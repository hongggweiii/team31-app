"""
POST /match: one gig in, ranked showcases out, in the ranker's API contract
(ranker API_IO_SPEC.md; example: ranker example_io.json).

    request   {"hire_id", "hire_title", "hire_description", "industry", "taxonomy", "top_k"}
    response  {"results": [{rank, provider_id, score, raw_margin, provider, signals}], "meta": {...}}

Inside the request (the 5 s budget) the gig is embedded LIVE by embedding-service/
(the ranker's query recipe; check_parity.py confirms bit-identical vectors), then
ranked by the ranker's own pipeline (pipeline/; each file's header names its ranker file)
over the whole showcase catalogue, as the ranker's serve.py does:

    refined BM25 || cosine (1024-d) -> RRF k=60 -> top-50 -> 17 features, z-scored per query
        -> v5tax_tags LambdaMART -> ranked showcases

Only after the response is sent is the gig's vector saved, to gig_embeddings. A later
request for the same gig (a revisit or refresh) reuses that vector while the gig's text
and the embedding model are unchanged, and skips embedding-service.
Showcase vectors are never computed here: `php spark embed:batch showcases` puts
them in MariaDB with the records they were embedded from (fields_json), and the
catalogue is reloaded in the background when they change.

Run (from matcher/; database and embedding-service settings come from the app's ../.env):
    python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
    .venv/bin/uvicorn app:app --port 8002

MATCHER_CORPUS=<path to a ranker checkout> serves the ranker's own corpus
(data_set_v5_tax) instead of the app's showcases, to replay its example_io.json.
"""
import hashlib
import json
import logging
import os
import platform
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated

import numpy as np
import pymysql
import xgboost as xgb
from fastapi import BackgroundTasks, FastAPI, HTTPException
from pydantic import BaseModel, Field, StringConstraints, model_validator

HERE = Path(__file__).resolve().parent
PIPELINE = HERE / "pipeline"
CACHE = HERE / "cache"
sys.path.insert(0, str(PIPELINE))

from corpus import hirer_text  # noqa: E402
from rerank_ltr import DEFAULT_FEATURES, SCORE_FEATURES, normalize_by_query  # noqa: E402
from retrieval_bm25 import BM25Retriever  # noqa: E402
from retrieval_dense import _truncate_and_normalize  # noqa: E402
from retrieval_rrf import rrf_fuse  # noqa: E402
from tag_features import TAG_FEATURES, TagMatcher, embed_skills, tag_axes  # noqa: E402

MODEL_PATH = PIPELINE / "models_data" / "data_set_v5_tax" / "v5tax_tags_xgb.json"
FEATURES = list(DEFAULT_FEATURES) + list(TAG_FEATURES)  # 17, positional: the model stores no names
# POOL_SIZE and DENSE_DIM: serve.py reads them from the ranker's configs/lightweight.json
POOL_SIZE = 50         # features.py --top-k: the candidate pool the ranker was trained on
DENSE_DIM = 1024       # features.py --dense-dim default
REFRESH_SECONDS = 30   # how often the background thread checks MariaDB for changed showcases
EMBED_TIMEOUT = 3      # seconds for embedding-service, inside the caller's 5 s
KEEP_WARM_SECONDS = 120  # how often an idle embedding-service gets a tiny request (see warm_embedding_service)
GIG_KEY = "gig"        # TagMatcher keys gigs by id; one gig is scored at a time
# API_IO_SPEC.md "Known-dead fields": the shipped model was trained with these zeroed,
# so they are always sent as 0.0 (as serve.py does) -- live values, even where the data
# has them, would be out-of-distribution for it.
DEAD_FEATURES = ("budget_fit", "seniority_fit", "avail_immediacy")

# meta strings, exactly as the ranker's example_io.json
META = {
    "pipeline": "refined BM25 || mxbai-embed-large-v1 (1024-d) -> RRF(k=60) -> LambdaMART",
    "normalization": "per-query zscore on SCORE_FEATURES (required at serve time)",
    "n_features": len(FEATURES),
    "features": FEATURES,
}
SCORE_SCALE = "0-100, higher is better; ranker score, NOT a calibrated probability"
RANKING_IS = "serving model (all-data); committed artifact is out-of-fold"

model = xgb.Booster(model_file=str(MODEL_PATH))
log = logging.getLogger("uvicorn.error")


def env(key: str, default: str | None = None) -> str | None:
    """A setting from the app's .env (environment variables win), so the matcher
    uses the same database and embedding-service as the app.

    Args:
        key: The setting's name, e.g. "database.default.hostname".
        default: Returned when it is set nowhere.

    Returns:
        The value (surrounding quotes removed), or default.
    """
    if key in os.environ:
        return os.environ[key]
    path = HERE.parent / ".env"
    for line in path.read_text().splitlines() if path.exists() else []:
        k, sep, v = line.partition("=")
        if sep and k.strip() == key:
            return v.strip().strip("'\"")
    return default


EMBEDDING_URL = env("embedding.baseURL", "http://localhost:8001/").rstrip("/") + "/"
RANKER = os.environ.get("MATCHER_CORPUS")  # unset: the app's showcases in MariaDB


def machine() -> str:
    """What the matcher runs on, reported in meta.machine (the app's analytics_outputs):
    MATCHER_MACHINE if set (e.g. a cloud instance type), otherwise this machine's CPU."""
    if os.environ.get("MATCHER_MACHINE"):
        return os.environ["MATCHER_MACHINE"]
    name = None
    try:
        if sys.platform == "win32":
            import winreg
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"HARDWARE\DESCRIPTION\System\CentralProcessor\0") as key:
                name = winreg.QueryValueEx(key, "ProcessorNameString")[0]
        elif sys.platform == "darwin":
            name = subprocess.run(["sysctl", "-n", "machdep.cpu.brand_string"], capture_output=True, text=True).stdout
        else:
            name = next((line.split(":", 1)[1] for line in Path("/proc/cpuinfo").read_text().splitlines()
                         if line.startswith("model name")), None)
    except OSError:
        pass
    return f"CPU: {(name or platform.processor() or 'unknown').strip()}"


MACHINE = machine()


# ---- matching: the ranker's per-gig steps over an in-memory catalogue ---------
# serve.py's serve(), split into steps; each calls the pipeline/ functions serve.py calls.

class Catalogue:
    """What the ranker builds once before scoring any gig (features.py, serve.py):
    the providers, the BM25 index, the document vectors and the tag matcher.

    `unit_vectors` are the providers' unit-length embeddings -- what embedding-service
    returns and MariaDB stores: retrieval_dense._truncate_and_normalize's float16
    output, exactly. They are kept in float16, the precision the ranker scores in."""

    def __init__(self, providers: list[dict], unit_vectors: np.ndarray, skill_vectors: dict | None = None):
        """Prepares everything needed to match gigs against these showcases: their ids, their
        vectors, a keyword search index (BM25) and the tag matcher. This happens once per
        catalogue, so a match request only has to work on the new gig.

        Args:
            providers: Ranker-format records, each with provider_id, the five text fields,
                years_experience and tags.
            unit_vectors: One unit-length vector per provider, in the same order.
            skill_vectors: Skill name -> vector, for the skill_soft feature; None turns it off.
        """
        self.providers = providers
        self.ids = [p["provider_id"] for p in providers]
        self.by_id = {p["provider_id"]: p for p in providers}
        self.vectors = np.asarray(unit_vectors, dtype=np.float16)
        self.bm25 = BM25Retriever(providers, refined=True) if providers else None
        # skill_soft compares skill-name vectors; without them the feature is 0.
        # TagMatcher takes log(len(providers)), so an empty catalogue (nothing
        # published or embedded yet) gets none: candidates() returns [] first.
        self.tags = TagMatcher([], providers, skill_vectors) if providers else None


def dense_rank(cat: Catalogue, gig_unit: np.ndarray) -> list[tuple[int, float]]:
    """retrieval_dense.rank_from_raw's last step. Both sides are already the unit-length
    float16 vectors rank_from_raw would compute, so they are not normalised again (that
    would only re-round them in float16); the result is bit-identical to the ranker's.

    Returns:
        (provider_id, cosine similarity) for every provider, most similar first.
    """
    scores = cat.vectors @ np.asarray(gig_unit, dtype=np.float16)
    return sorted(zip(cat.ids, scores.tolist()), key=lambda x: x[1], reverse=True)


def candidates(cat: Catalogue, gig: dict, gig_unit: np.ndarray) -> list[dict]:
    """Finds the 50 showcases most likely to fit the gig, and works out the ranker's features for
    each.

    Two searches rank every showcase: BM25 (how many words they share) and cosine similarity
    of their vectors (how close they are in meaning). RRF (reciprocal rank fusion) merges the
    two rankings into one, and the top 50 are kept. Each gets a row of features: its scores
    and places in both searches, the features that are always 0 (DEAD_FEATURES), and the 8 tag
    features comparing the gig's tags with the showcase's.

    This is the per-gig step of the ranker's features.py and tag_features.py, with values
    rounded as features.py rounds them. The ranker's serve.py rebuilds the tag matcher for
    every gig; this reuses the catalogue's one, which gives the same results.

    Args:
        cat: The catalogue.
        gig: The gig as a dict: hire_title, hire_description and tags.categories.
        gig_unit: Its unit-length query vector.

    Returns:
        Up to POOL_SIZE rows in RRF order: provider_id and the 17 raw features
        (the three DEAD_FEATURES always 0.0). [] for an empty catalogue.

    Raises:
        HTTPException: 503 if a gig skill no showcase has must be embedded and embedding-service fails.
    """
    if not cat.providers:
        return []
    h = gig
    # Two independent rankings of the whole catalogue (keywords and meaning), fused by RRF.
    bm25_ranked = cat.bm25.rank(hirer_text(h), query_title=h["hire_title"])
    dense_ranked = dense_rank(cat, gig_unit)
    fused = rrf_fuse(bm25_ranked, dense_ranked, k=60)

    # Each candidate's score and position in both rankings: these are model features.
    bm25_score = dict(bm25_ranked)
    dense_score = dict(dense_ranked)
    bm25_pos = {pid: r + 1 for r, (pid, _) in enumerate(bm25_ranked)}
    dense_pos = {pid: r + 1 for r, (pid, _) in enumerate(dense_ranked)}
    # Register this gig's tags with the tag matcher (it compares one gig at a time).
    cat.tags.gig[GIG_KEY] = tag_axes(h)
    missing = [k for k in cat.tags.gig[GIG_KEY][2] if k not in cat.tags.vec] if cat.tags.vec else []
    if missing:  # a gig skill no corpus record has: embed its name like embed_skills does
        cat.tags.vec.update(embed_skill_names(missing))

    # The model scores only the RRF top POOL_SIZE: one feature row each.
    rows = []
    for rrf_pos, (pid, rrf_score) in enumerate(fused[:POOL_SIZE], start=1):
        p_tax = cat.by_id[pid]
        rows.append({
            "hire_id": 0,  # normalize_by_query groups rows by it
            "provider_id": pid,
            # ---- features.py:247-272, as-is, except the dead features (see DEAD_FEATURES) ----
            "bm25_score": round(float(bm25_score.get(pid, 0.0)), 6),
            "bm25_rank": bm25_pos.get(pid, 0),
            "dense_cosine": round(float(dense_score.get(pid, 0.0)), 6),
            "dense_rank": dense_pos.get(pid, 0),
            "rrf_score": round(float(rrf_score), 8),
            "rrf_rank": rrf_pos,
            "exp_years": round(float(p_tax.get("years_experience") or 0), 1),  # not one of FEATURES: unused by the model
            **dict.fromkeys(DEAD_FEATURES, 0.0),
            # ---- tag_features.py (tag_features.csv is rounded to 6 places) ----
            **{k: round(v, 6) for k, v in cat.tags.features(GIG_KEY, pid).items()},
        })
    return rows


def score(rows: list[dict]) -> list[dict]:
    """Has the ranker model put the candidates in order, best first.

    First, each score feature is standardised within this gig (a z-score: how far above or
    below this gig's average it is), because the model was trained that way. Then the model
    gives each candidate a raw score (its margin). The margins are rescaled to 0-100 for
    display: 100 for the best candidate and 0 for the worst. These are the same steps as the
    ranker's serve.py.

    Args:
        rows: candidates()'s feature rows.

    Returns:
        One dict per row, best first: provider_id, inputs (the z-scored features),
        margin (the model's raw output) and score (0-100 within this gig).
    """
    if not rows:
        return []
    # z-score the drifting features within this gig, as the model was trained.
    inputs = [dict(r) for r in rows]
    normalize_by_query(inputs, list(SCORE_FEATURES), "zscore")
    # Features in FEATURES order: the model stores no names, only positions.
    margins = model.predict(xgb.DMatrix(np.array([[r[f] for f in FEATURES] for r in inputs], dtype=np.float32)))
    lo = float(margins.min())
    span = (float(margins.max()) - lo) or 1.0
    scored = [{"provider_id": r["provider_id"], "inputs": x, "margin": float(m),
               "score": round((float(m) - lo) / span * 100, 2)}
              for r, x, m in zip(rows, inputs, margins)]
    return sorted(scored, key=lambda c: -c["margin"])


def rank(cat: Catalogue, gig: dict, gig_unit: np.ndarray) -> list[dict]:
    """Ranks the showcases for one gig, best first, by calling candidates() and then score().
    These are the same steps as the ranker's serve.py. check_parity.py uses this function;
    /match calls the two steps separately so it can time each one.

    Args:
        cat: The catalogue to rank.
        gig: The gig as a dict, with its tags.
        gig_unit: Its unit-length query vector.

    Returns:
        score()'s output.
    """
    return score(candidates(cat, gig, gig_unit))


def response(ranked: list[dict], cat: Catalogue, top_k: int) -> dict:
    """Builds the reply in the ranker's format (its example_io.json): the top_k showcases, each
    with its rank, score, a short profile and the feature values the model saw, plus a `meta`
    block describing the pipeline. The ranker's newer serve.py also adds the gig's hire_id and
    tier details; this reply keeps example_io.json's format, which the PHP app reads.

    Args:
        ranked: score()'s output (may be empty).
        cat: The catalogue the gig was ranked against.
        top_k: How many results to return.

    Returns:
        {"results": [...], "meta": {..., "catalogue_size", "candidate_pool", "returned"}}.
    """
    results = []
    for rank_, c in enumerate(ranked[:top_k], start=1):
        p, x = cat.by_id[c["provider_id"]], c["inputs"]
        results.append({
            "rank": rank_,
            "provider_id": c["provider_id"],
            "score": c["score"],
            "raw_margin": round(c["margin"], 6),
            "provider": {
                "display_title": p.get("about_title"),
                "industry": p.get("industry"),
                "bio_excerpt": (p.get("about_description") or "")[:200],
            },
            "signals": {
                "retrieval": {f: round(float(x[f]), 6) for f in DEFAULT_FEATURES[:6]},
                "business_fit": {f: round(float(x[f]), 6) for f in DEFAULT_FEATURES[6:]},
                "taxonomy": {f: round(float(x[f]), 4) for f in TAG_FEATURES},
            },
        })
    # catalogue_size tells "nothing published yet" (0) apart from "nothing matched" (an
    # empty list from a non-empty catalogue), so callers never infer it from `results`.
    return {"results": results, "meta": {**META, "catalogue_size": len(cat.providers), "candidate_pool": len(ranked),
                                         "returned": len(results), "score_scale": SCORE_SCALE, "ranking_is": RANKING_IS}}


# ---- embedding-service: the gig (query side), and skill names -----------------
# serve.py embeds in-process (retrieval_dense.encode_queries, tag_features.embed_skills).
# Here embedding-service does, with the same model and text recipe; check_parity.py
# check 1 shows its vectors are bit-identical to the ranker's.

def post_json(path: str, body: dict, timeout: float) -> dict:
    """Sends a JSON request to the embedding service and returns its JSON reply. If the service
    can't be reached, takes too long, returns an error or sends back something unreadable, the
    caller gets HTTP 503 (service unavailable).

    Args:
        path: e.g. "embed/gigs", relative to EMBEDDING_URL.
        body: The JSON body.
        timeout: Seconds to wait.

    Returns:
        The decoded response.

    Raises:
        HTTPException: 503 on any failure.
    """
    req = urllib.request.Request(EMBEDDING_URL + path, json.dumps(body).encode(), {"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.load(resp)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as e:
        raise HTTPException(status_code=503, detail=f"embedding-service unavailable: {e}") from e


def embed_gig(gig: dict) -> tuple[np.ndarray, str]:
    """The gig's unit-length query vector, embedded now (nothing is read from or written to MariaDB here).

    Args:
        gig: The request as a dict (hire_title, hire_description, ...).

    Returns:
        (the vector as float32, the model name embedding-service reported).

    Raises:
        HTTPException: 503 when embedding-service fails or takes longer than EMBED_TIMEOUT.
    """
    data = post_json("embed/gigs", {"gigs": [{"hire_title": gig["hire_title"], "hire_description": gig["hire_description"],
                                              "hire_description_additional_notes": None}]}, EMBED_TIMEOUT)
    return np.asarray(data["embeddings"][0], dtype=np.float32), data.get("model", "")


def embed_skill_names(names: list[str]) -> dict:
    """Skill-name vectors as tag_features.embed_skills makes them (document side,
    unit length, rounded to 6 places), via embedding-service.

    Args:
        names: Skill names the cached skill vectors don't have.

    Returns:
        Skill name -> vector.
    """
    data = post_json("embed/providers", {"providers": [{"about_title": n} for n in names]}, EMBED_TIMEOUT)
    return {n: np.asarray(v, dtype=np.float32).round(6) for n, v in zip(names, data["embeddings"])}


# ---- MariaDB: the showcase catalogue (read) and gig vectors (written after the response)
# The ranker reads its corpus from providers.json and stores nothing. Here the
# showcases, their vectors and tags are read from the PHP app's database (its .env).

_conn = None
_db_lock = threading.Lock()  # one connection, shared by requests, the refresh thread and saves


def db_query(sql: str, args=None) -> list[dict]:
    """Runs one SQL query on the app's MariaDB database and returns the rows, each as a dict. The
    connection is opened the first time it is needed, with the database settings from the
    app's .env file, and reopened if it has dropped. Calls are serialised by a lock, because
    requests, the refresh thread and background saves share the connection.

    Args:
        sql: The query, with %s placeholders.
        args: Their values.

    Returns:
        The rows as dicts (empty for statements that return none).
    """
    global _conn
    with _db_lock:
        if _conn is None:
            _conn = pymysql.connect(host=env("database.default.hostname"), port=int(env("database.default.port", "3306")),
                                    user=env("database.default.username"), password=env("database.default.password"),
                                    database=env("database.default.database"), charset="utf8mb4", autocommit=True,
                                    cursorclass=pymysql.cursors.DictCursor)
        _conn.ping(reconnect=True)
        with _conn.cursor() as cur:
            cur.execute(sql, args)
            return list(cur.fetchall())


def load_tags(owner: str) -> dict[int, dict]:
    """The app's SkillsFuture tags (sf_v1) per gig / showcase, in the ranker's `tags`
    shape: the owner's specialisations under their categories, with the skills chosen
    for each. Mirrors Taxonomy::tags() in app/Libraries/Taxonomy.php.

    Args:
        owner: "showcase" or "gig" (part of the table names; never user input).

    Returns:
        Owner id -> {"taxonomy_version", "categories": [...]}; owners without tags are absent.
    """
    specs = db_query(f"SELECT l.{owner}_id AS id, s.id AS spec_id, s.name AS spec, c.name AS category, c.group_name "
                     f"FROM {owner}_specialisations l JOIN sf_specialisations s ON s.id = l.specialisation_id "
                     "JOIN sf_categories c ON c.id = s.category_id")
    skills: dict[tuple[int, int], list[str]] = {}
    for r in db_query(f"SELECT l.{owner}_id AS id, l.specialisation_id AS spec_id, k.name FROM {owner}_skills l "
                      "JOIN sf_skills k ON k.id = l.skill_id"):
        skills.setdefault((r["id"], r["spec_id"]), []).append(r["name"])
    tree: dict[int, dict[str, dict]] = {}
    for r in specs:
        cat = tree.setdefault(r["id"], {}).setdefault(r["category"], {"group": r["group_name"], "specs": {}})
        cat["specs"][r["spec"]] = sorted(skills.get((r["id"], r["spec_id"]), []))
    return {oid: {"taxonomy_version": "sf_v1", "categories": [
                {"name": c, "group": cat["group"], "specialisations": [
                    {"name": s, "search_tag": f"{s} ({c})", "skills": ks} for s, ks in sorted(cat["specs"].items())]}
                for c, cat in sorted(by_cat.items())]}
            for oid, by_cat in tree.items()}


def load_catalogue() -> Catalogue:
    """Loads every published showcase that has a vector from MariaDB: the text it was embedded
    from (fields_json), its tags and its vector.

    This replaces the ranker reading providers.json. fields_json uses the ranker's field names
    (the PHP app writes it in ProviderEmbedder::showcaseFields), so the ranker's code can read
    it unchanged. It holds only the text fields and years_experience, with no industry, so
    each result's provider.industry is null.

    Returns:
        The Catalogue; empty (no providers) when nothing is published and embedded.
    """
    rows = db_query("SELECT e.showcase_id AS id, e.fields_json, VEC_ToText(e.embedding) AS embedding "
                    "FROM showcase_embeddings e JOIN showcases s ON s.id = e.showcase_id "
                    "WHERE s.status = 'published' AND e.fields_json IS NOT NULL ORDER BY e.showcase_id")
    tags = load_tags("showcase")
    # Each provider: its id, the record it was embedded from, and its tags.
    providers = [{"provider_id": r["id"], **json.loads(r["fields_json"]), "tags": tags.get(r["id"], {})} for r in rows]
    vectors = (np.stack([np.asarray(json.loads(r["embedding"]), dtype=np.float32) for r in rows])
               if rows else np.zeros((0, DENSE_DIM), np.float32))
    # Every taxonomy skill, not just the showcases': gig skills then never need
    # embedding inside a match request. The cache is named after the skill list, so a
    # grown taxonomy gets a new file, which build_skill_vectors fills out of process.
    skills = [r["name"] for r in db_query("SELECT name FROM sf_skills ORDER BY name")]
    cache = CACHE / f"app_skill_vectors_{hashlib.sha256(json.dumps(skills).encode()).hexdigest()[:12]}.json"
    return Catalogue(providers, vectors, build_skill_vectors(skills, cache) if skills else None)


def catalogue_version() -> tuple:
    """Changes whenever a showcase or its vector is added, edited, unpublished or removed.

    Returns:
        (embedding count, latest embedding update, published count, latest showcase
        update): one cheap query, compared every REFRESH_SECONDS.
    """
    return tuple(db_query(
        "SELECT (SELECT COUNT(*) FROM showcase_embeddings) AS n_vectors, "
        "(SELECT MAX(updated_at) FROM showcase_embeddings) AS vectors_at, "
        "(SELECT COUNT(*) FROM showcases WHERE status = 'published') AS n_published, "
        "(SELECT MAX(updated_at) FROM showcases) AS showcases_at")[0].values())


def gig_fields(gig: dict) -> dict:
    """The ranker-format record a gig is embedded from."""
    return {"hire_title": gig["hire_title"], "hire_description": gig["hire_description"],
            "hire_description_additional_notes": ""}


def gig_text_hash(gig: dict) -> str:
    return hashlib.sha256(hirer_text(gig_fields(gig)).encode()).hexdigest()


def stored_gig_vector(gig: dict) -> np.ndarray | None:
    """The vector save_gig_embedding stored for this gig, if its text and the embedding
    model are unchanged since: a revisit or refresh of the matches page then skips
    embedding-service. Any failure just means embedding it live."""
    if not state["model"]:
        return None
    try:
        rows = db_query("SELECT VEC_ToText(embedding) AS embedding FROM gig_embeddings "
                        "WHERE gig_id = %s AND model = %s AND text_hash = %s",
                        (gig["hire_id"], state["model"], gig_text_hash(gig)))
    except Exception:
        log.exception("reading the stored embedding of gig %s failed", gig["hire_id"])
        return None
    return np.asarray(json.loads(rows[0]["embedding"]), dtype=np.float32) if rows else None


def save_gig_embedding(gig: dict, vector: np.ndarray, model_name: str) -> None:
    """Runs after the response has been sent: stores the gig's vector and the
    ranker-format record it was embedded from. A failure is logged, never raised."""
    fields = gig_fields(gig)
    try:
        db_query("INSERT INTO gig_embeddings (gig_id, model, text_hash, fields_json, embedding, created_at, updated_at) "
                 "VALUES (%s, %s, %s, %s, VEC_FromText(%s), NOW(), NOW()) "
                 "ON DUPLICATE KEY UPDATE model = VALUES(model), text_hash = VALUES(text_hash), "
                 "fields_json = VALUES(fields_json), embedding = VALUES(embedding), updated_at = VALUES(updated_at)",
                 (gig["hire_id"], model_name, gig_text_hash(gig),
                  json.dumps(fields, ensure_ascii=False), json.dumps(vector.tolist())))
    except Exception:
        log.exception("saving the embedding of gig %s failed", gig["hire_id"])


# model: embedding-service's model name, as it last reported it (stored gig vectors must match it)
state = {"catalogue": None, "version": None, "model": None}


def warm_embedding_service() -> None:
    """Embeds a dummy gig, outside any request. An embedding-service that has sat idle
    for a long while (its model paged out) can take ~4 s on its next call, more than
    EMBED_TIMEOUT, so the next real gig would get a 503. Called at startup and then
    every KEEP_WARM_SECONDS. Failures are logged: matching reports them anyway."""
    try:
        data = post_json("embed/gigs", {"gigs": [{"hire_title": "warm-up", "hire_description": "warm-up"}]}, 30)
        state["model"] = data.get("model") or state["model"]
    except HTTPException as e:
        log.warning("embedding-service warm-up failed: %s", e.detail)


def refresh_forever() -> None:
    """Background thread: reload the catalogue when MariaDB changed, so no request waits on
    it, and keep embedding-service warm."""
    warmed = time.monotonic()
    while True:
        time.sleep(REFRESH_SECONDS)
        if time.monotonic() - warmed >= KEEP_WARM_SECONDS:
            warm_embedding_service()
            warmed = time.monotonic()
        try:
            # One cheap query; the full reload only runs when something changed.
            version = catalogue_version()
            if version != state["version"]:
                state["catalogue"], state["version"] = load_catalogue(), version
                log.info("catalogue reloaded: %d showcases", len(state["catalogue"].providers))
        except Exception:  # e.g. the database is unreachable: keep serving, retry next time
            log.exception("catalogue refresh failed; still serving the previous one")


# ---- the ranker's own corpus (MATCHER_CORPUS), to replay example_io.json -------
# load_ranker_corpus is serve.py's _load() plus its _tag_matcher's skill list.
# build_skill_vectors is used in both modes.

def build_skill_vectors(skills: list, cache: Path) -> dict:
    """tag_features.embed_skills, cached. Built in a child process: it runs torch on
    the CPU, and torch + xgboost (loaded here) in one process hangs on macOS -- the
    ranker runs tag_features.py on its own for the same reason.

    Args:
        skills: Every skill name to have a vector for.
        cache: The cache file; its name is derived from the skill list, so a changed
            taxonomy gets a new file.

    Returns:
        Skill name -> unit-length vector.
    """
    if not cache.exists():
        CACHE.mkdir(exist_ok=True)
        wanted = CACHE / "skills.json"
        wanted.write_text(json.dumps(skills))
        subprocess.run([sys.executable, "-c",
                        "import json, sys; from pathlib import Path; sys.path.insert(0, 'pipeline'); "
                        "from tag_features import embed_skills; "
                        f"embed_skills(json.loads(Path({str(wanted)!r}).read_text()), Path({str(cache)!r}))"],
                       cwd=HERE, check=True)
    return embed_skills(skills, cache)  # all cached now: reads the file, loads no model


def load_ranker_corpus(ranker: Path) -> Catalogue:
    """Loads the ranker's own test data (its 3,024 providers) instead of the app's showcases.
    This is only used when MATCHER_CORPUS is set, to check that the matcher gives the same
    results as the ranker. The provider vectors are read from cache/ if check_parity.py saved
    them there; otherwise they are requested from the embedding service and saved in cache/.

    Args:
        ranker: A checkout of the ranker repo.

    Returns:
        The Catalogue of the ranker's providers.
    """
    data = ranker / "pipeline" / "data" / "data_set_v5_tax"
    providers = json.loads((data / "providers.json").read_text(encoding="utf-8"))
    hirers = json.loads((data / "hirers.json").read_text(encoding="utf-8"))
    raw = CACHE / "v5tax_providers.npy"         # encode_docs output, written by check_parity.py
    unit = CACHE / "v5tax_providers_unit.npy"   # embedding-service output
    if raw.exists():
        vectors = _truncate_and_normalize(np.load(raw), DENSE_DIM)
    else:
        if not unit.exists():
            CACHE.mkdir(exist_ok=True)
            vecs = []
            for i in range(0, len(providers), 128):
                body = {"providers": [{k: p.get(k) for k in ("about_title", "about_description", "services_offered_title",
                                                             "services_offered_description", "relevant_experience")}
                                      for p in providers[i:i + 128]]}
                vecs += post_json("embed/providers", body, 600)["embeddings"]
            np.save(unit, np.asarray(vecs, dtype=np.float16))
        vectors = np.load(unit)
    skills = sorted({k for r in hirers + providers for k in tag_axes(r)[2]})
    return Catalogue(providers, vectors, build_skill_vectors(skills, CACHE / "skill_vectors.json"))


# ---- HTTP -----------------------------------------------------------------------
# The ranker's serve.py takes one gig per run (--hire-id / --gig-json); this service keeps running.

@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Runs once when the matcher starts, before it accepts requests. It loads the showcases to
    match against: from MariaDB, or the ranker's data when MATCHER_CORPUS is set. For MariaDB
    it also starts the background thread that reloads them when they change. Finally it sends
    the embedding service a first request so it is ready. An empty catalogue is fine: matching
    then answers an empty list."""
    if RANKER:
        state["catalogue"] = load_ranker_corpus(Path(RANKER))
    else:
        state["catalogue"], state["version"] = load_catalogue(), catalogue_version()
        threading.Thread(target=refresh_forever, daemon=True).start()
    warm_embedding_service()
    log.info("matching against %d providers (%s)", len(state["catalogue"].providers), RANKER or "MariaDB showcases")
    yield


app = FastAPI(title="SenseiGigs matcher", lifespan=lifespan)


Name = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]


class TaxonomySpecialisation(BaseModel):
    """A specialisation the gig is tagged with, and the skills picked under it."""
    name: Name
    search_tag: str | None = None  # "<specialisation> (<category>)"; display only
    skills: list[Name] = Field(default_factory=list)


class TaxonomyCategory(BaseModel):
    """A category the gig is tagged with, and its specialisations. The request's `taxonomy` is a
    list of these, in the same format as the tags in the ranker's data. Only the names are used
    for ranking."""
    name: Name
    group: str | None = None
    specialisations: list[TaxonomySpecialisation] = Field(default_factory=list)


class MatchRequest(BaseModel):
    """The ranker's request (API_IO_SPEC.md): field names as in its hirers.json.
    Malformed input is a 422 here, never a crash (500) or an embedding-service
    rejection reported as a 503 further in."""
    hire_id: int
    hire_title: str
    hire_description: str
    industry: str | None = None
    taxonomy: list[TaxonomyCategory] = Field(default_factory=list)
    top_k: int = Field(10, ge=1, le=POOL_SIZE)

    @model_validator(mode="after")
    def has_text(self):
        """Rejects the request (HTTP 422) if the gig has neither a title nor a description,
        because there would be no text to match on.

        Raises:
            ValueError: Turned into a 422 by FastAPI.
        """
        if not (self.hire_title.strip() or self.hire_description.strip()):
            raise ValueError("hire_title and hire_description are both empty: there is no text to match")
        return self


@app.get("/health")
async def health():
    """Reports that the matcher is running, which showcases it matches against and how many, and
    which ranker model and version it uses.

    Returns:
        {"status": "ok", "corpus", "providers", "ranker", "ranker_version"}.
    """
    return {"status": "ok", "corpus": RANKER or "mariadb", "providers": len(state["catalogue"].providers),
            "ranker": MODEL_PATH.name, "ranker_version": (HERE / "RANKER_VERSION").read_text().strip()}


# async def: requests run one at a time on the event loop, so the catalogue and
# xgboost are never used concurrently.
@app.post("/match")
async def match(req: MatchRequest, background: BackgroundTasks):
    """Matches one gig against all published showcases and returns the best ones.

    Steps: turn the gig's text into a vector (through the embedding service); find the 50
    showcases most likely to fit, by keywords and by meaning; work out the 17 numbers
    (features) the ranker model looks at for each; let the model put them in order. The gig's
    vector is saved to the database only after the reply has been sent, so saving never slows
    the reply down. If no showcases are published yet, it returns an empty list straight away.

    Args:
        req: The validated request (invalid ones never get here: FastAPI answers 422).
        background: Runs save_gig_embedding after the response is sent.

    Returns:
        response()'s {"results", "meta"}.

    Raises:
        HTTPException: 503 when the gig can't be embedded.
    """
    t0 = time.perf_counter()
    cat = state["catalogue"]
    if not cat.providers:  # nothing published and embedded yet: no candidates, so no need to embed the gig
        return response([], cat, req.top_k)
    gig = {"hire_id": req.hire_id, "hire_title": req.hire_title, "hire_description": req.hire_description,
           "industry": req.industry, "tags": {"categories": [c.model_dump() for c in req.taxonomy]}}
    vector, model_name = embed_gig(gig)
    t1 = time.perf_counter()
    rows = candidates(cat, gig, vector)
    t2 = time.perf_counter()
    ranked = score(rows)
    t3 = time.perf_counter()
    out = response(ranked, cat, req.top_k)
    t4 = time.perf_counter()
    if not RANKER and not stored:  # after the response is sent, outside the 5 s
        background.add_task(save_gig_embedding, gig, vector, model_name)
    log.info("[timing] matched gig %s in %.0f ms: read stored vector (%s) %.0f ms | embed (embedding-service) %s | "
             "candidates (BM25 + cosine + RRF + tags, %d showcases) %.0f ms | score (xgboost) %.0f ms | response %.0f ms",
             req.hire_id, (t4 - t0) * 1000, "hit" if stored else "miss", (t_read - t0) * 1000,
             "skipped" if stored else f"{(t1 - t_read) * 1000:.0f} ms", len(cat.providers),
             (t2 - t1) * 1000, (t3 - t2) * 1000, (t4 - t3) * 1000)
    # for the app's analytics_outputs (App\Libraries\MatchRecorder). vector_read: looking
    # for the stored vector, found or not; embed: the live call, None when it was reused.
    out["meta"]["timing_ms"] = {"vector_read": None if RANKER else round((t_read - t0) * 1000, 1),
                                "embed": None if stored else round((t1 - t_read) * 1000, 1),
                                "candidates": round((t2 - t1) * 1000, 1),
                                "score": round((t3 - t2) * 1000, 1), "response": round((t4 - t3) * 1000, 1),
                                "total": round((t4 - t0) * 1000, 1)}
    out["meta"]["machine"] = MACHINE
    out["meta"]["gig_vector"] = "stored" if stored else "embedded"
    return out
