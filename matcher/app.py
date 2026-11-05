"""
POST /match: one gig in, ranked showcases out, in the ranker's API contract
(ranker API_IO_SPEC.md; example: ranker example_io.json).

    request   {"hire_id", "hire_title", "hire_description", "industry", "taxonomy", "top_k"}
    response  {"results": [{rank, provider_id, score, raw_margin, provider, signals}], "meta": {...}}

Inside the request (the 5 s budget) the gig is embedded LIVE by embedding-service/
(the ranker's query recipe; check_parity.py confirms bit-identical vectors), then
ranked by the ranker's own pipeline (pipeline/, verbatim copies at RANKER_VERSION)
over the whole showcase catalogue, as the ranker's serve.py does:

    refined BM25 || cosine (1024-d) -> RRF k=60 -> top-50 -> 17 features, z-scored per query
        -> v5tax_tags LambdaMART -> ranked showcases

Only after the response is sent is the gig's vector saved, to gig_embeddings.
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
    uses the same database and embedding-service as the app."""
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


# ---- matching: the ranker's per-gig steps over an in-memory catalogue ---------

class Catalogue:
    """What the ranker builds once before scoring any gig (features.py, serve.py):
    the providers, the BM25 index, the document vectors and the tag matcher.

    `unit_vectors` are the providers' unit-length embeddings -- what embedding-service
    returns and MariaDB stores: retrieval_dense._truncate_and_normalize's float16
    output, exactly. They are kept in float16, the precision the ranker scores in."""

    def __init__(self, providers: list[dict], unit_vectors: np.ndarray, skill_vectors: dict | None = None):
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
    would only re-round them in float16); the result is bit-identical to the ranker's."""
    scores = cat.vectors @ np.asarray(gig_unit, dtype=np.float16)
    return sorted(zip(cat.ids, scores.tolist()), key=lambda x: x[1], reverse=True)


def candidates(cat: Catalogue, gig: dict, gig_unit: np.ndarray) -> list[dict]:
    """features.py's per-gig loop body plus tag_features.py: the RRF top-50 over
    the whole catalogue, each with the ranker's raw feature row."""
    if not cat.providers:
        return []
    h = gig
    bm25_ranked = cat.bm25.rank(hirer_text(h), query_title=h["hire_title"])
    dense_ranked = dense_rank(cat, gig_unit)
    fused = rrf_fuse(bm25_ranked, dense_ranked, k=60)

    bm25_score = dict(bm25_ranked)
    dense_score = dict(dense_ranked)
    bm25_pos = {pid: r + 1 for r, (pid, _) in enumerate(bm25_ranked)}
    dense_pos = {pid: r + 1 for r, (pid, _) in enumerate(dense_ranked)}
    cat.tags.gig[GIG_KEY] = tag_axes(h)
    missing = [k for k in cat.tags.gig[GIG_KEY][2] if k not in cat.tags.vec] if cat.tags.vec else []
    if missing:  # a gig skill no corpus record has: embed its name like embed_skills does
        cat.tags.vec.update(embed_skill_names(missing))

    rows = []
    for rrf_pos, (pid, rrf_score) in enumerate(fused[:POOL_SIZE], start=1):
        p_tax = cat.by_id[pid]
        rows.append({
            "hire_id": 0,  # normalize_by_query groups rows by it
            "provider_id": pid,
            # ---- features.py:244-269, as-is, except the dead features (see DEAD_FEATURES) ----
            "bm25_score": round(float(bm25_score.get(pid, 0.0)), 6),
            "bm25_rank": bm25_pos.get(pid, 0),
            "dense_cosine": round(float(dense_score.get(pid, 0.0)), 6),
            "dense_rank": dense_pos.get(pid, 0),
            "rrf_score": round(float(rrf_score), 8),
            "rrf_rank": rrf_pos,
            "exp_years": round(float(p_tax.get("years_experience") or 0), 1),
            **dict.fromkeys(DEAD_FEATURES, 0.0),
            # ---- tag_features.py (tag_features.csv is rounded to 6 places) ----
            **{k: round(v, 6) for k, v in cat.tags.features(GIG_KEY, pid).items()},
        })
    return rows


def score(rows: list[dict]) -> list[dict]:
    """The shipped ranker over one gig's candidates, best first (serve.py /
    api_io_example.py): z-score SCORE_FEATURES within the query, predict the
    margin, min-max it to 0-100."""
    if not rows:
        return []
    inputs = [dict(r) for r in rows]
    normalize_by_query(inputs, list(SCORE_FEATURES), "zscore")
    margins = model.predict(xgb.DMatrix(np.array([[r[f] for f in FEATURES] for r in inputs], dtype=np.float32)))
    lo = float(margins.min())
    span = (float(margins.max()) - lo) or 1.0
    scored = [{"provider_id": r["provider_id"], "inputs": x, "margin": float(m),
               "score": round((float(m) - lo) / span * 100, 2)}
              for r, x, m in zip(rows, inputs, margins)]
    return sorted(scored, key=lambda c: -c["margin"])


def rank(cat: Catalogue, gig: dict, gig_unit: np.ndarray) -> list[dict]:
    return score(candidates(cat, gig, gig_unit))


def response(ranked: list[dict], cat: Catalogue, top_k: int) -> dict:
    """The ranker's response block (example_io.json): signals are the model's
    actual inputs, after z-scoring, in declared order."""
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

def post_json(path: str, body: dict, timeout: float) -> dict:
    req = urllib.request.Request(EMBEDDING_URL + path, json.dumps(body).encode(), {"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.load(resp)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as e:
        raise HTTPException(status_code=503, detail=f"embedding-service unavailable: {e}") from e


def embed_gig(gig: dict) -> tuple[np.ndarray, str]:
    """The gig's unit-length query vector, embedded now (nothing is read from or written to MariaDB here)."""
    data = post_json("embed/gigs", {"gigs": [{"hire_title": gig["hire_title"], "hire_description": gig["hire_description"],
                                              "hire_description_additional_notes": None}]}, EMBED_TIMEOUT)
    return np.asarray(data["embeddings"][0], dtype=np.float32), data.get("model", "")


def embed_skill_names(names: list[str]) -> dict:
    """Skill-name vectors as tag_features.embed_skills makes them (document side,
    unit length, rounded to 6 places), via embedding-service."""
    data = post_json("embed/providers", {"providers": [{"about_title": n} for n in names]}, EMBED_TIMEOUT)
    return {n: np.asarray(v, dtype=np.float32).round(6) for n, v in zip(names, data["embeddings"])}


# ---- MariaDB: the showcase catalogue (read) and gig vectors (written after the response)

_conn = None
_db_lock = threading.Lock()  # one connection, shared by requests, the refresh thread and saves


def db_query(sql: str, args=None) -> list[dict]:
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
    for each. Mirrors Taxonomy::tags() in app/Libraries/Taxonomy.php."""
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
    """Every published showcase that has been embedded: the ranker-format record it
    was embedded from (fields_json), its tags, and its stored vector."""
    rows = db_query("SELECT e.showcase_id AS id, e.fields_json, VEC_ToText(e.embedding) AS embedding "
                    "FROM showcase_embeddings e JOIN showcases s ON s.id = e.showcase_id "
                    "WHERE s.status = 'published' AND e.fields_json IS NOT NULL ORDER BY e.showcase_id")
    tags = load_tags("showcase")
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
    """Changes whenever a showcase or its vector is added, edited, unpublished or removed."""
    return tuple(db_query(
        "SELECT (SELECT COUNT(*) FROM showcase_embeddings) AS n_vectors, "
        "(SELECT MAX(updated_at) FROM showcase_embeddings) AS vectors_at, "
        "(SELECT COUNT(*) FROM showcases WHERE status = 'published') AS n_published, "
        "(SELECT MAX(updated_at) FROM showcases) AS showcases_at")[0].values())


def save_gig_embedding(gig: dict, vector: np.ndarray, model_name: str) -> None:
    """Runs after the response has been sent: stores the gig's vector and the
    ranker-format record it was embedded from. A failure is logged, never raised."""
    fields = {"hire_title": gig["hire_title"], "hire_description": gig["hire_description"],
              "hire_description_additional_notes": ""}
    try:
        db_query("INSERT INTO gig_embeddings (gig_id, model, text_hash, fields_json, embedding, created_at, updated_at) "
                 "VALUES (%s, %s, %s, %s, VEC_FromText(%s), NOW(), NOW()) "
                 "ON DUPLICATE KEY UPDATE model = VALUES(model), text_hash = VALUES(text_hash), "
                 "fields_json = VALUES(fields_json), embedding = VALUES(embedding), updated_at = VALUES(updated_at)",
                 (gig["hire_id"], model_name, hashlib.sha256(hirer_text(fields).encode()).hexdigest(),
                  json.dumps(fields, ensure_ascii=False), json.dumps(vector.tolist())))
    except Exception:
        log.exception("saving the embedding of gig %s failed", gig["hire_id"])


state = {"catalogue": None, "version": None}


def warm_embedding_service() -> None:
    """Embeds a dummy gig, outside any request. An embedding-service that has sat idle
    for a long while (its model paged out) can take ~4 s on its next call, more than
    EMBED_TIMEOUT, so the next real gig would get a 503. Called at startup and then
    every KEEP_WARM_SECONDS. Failures are logged: matching reports them anyway."""
    try:
        post_json("embed/gigs", {"gigs": [{"hire_title": "warm-up", "hire_description": "warm-up"}]}, 30)
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
            version = catalogue_version()
            if version != state["version"]:
                state["catalogue"], state["version"] = load_catalogue(), version
                log.info("catalogue reloaded: %d showcases", len(state["catalogue"].providers))
        except Exception:
            log.exception("catalogue refresh failed; still serving the previous one")


# ---- the ranker's own corpus (MATCHER_CORPUS), to replay example_io.json -------

def build_skill_vectors(skills: list, cache: Path) -> dict:
    """tag_features.embed_skills, cached. Built in a child process: it runs torch on
    the CPU, and torch + xgboost (loaded here) in one process hangs on macOS -- the
    ranker runs tag_features.py on its own for the same reason."""
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

@asynccontextmanager
async def lifespan(_app: FastAPI):
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
    name: Name
    search_tag: str | None = None  # "<specialisation> (<category>)"; display only
    skills: list[Name] = Field(default_factory=list)


class TaxonomyCategory(BaseModel):
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
        if not (self.hire_title.strip() or self.hire_description.strip()):
            raise ValueError("hire_title and hire_description are both empty: there is no text to match")
        return self


@app.get("/health")
async def health():
    return {"status": "ok", "corpus": RANKER or "mariadb", "providers": len(state["catalogue"].providers),
            "ranker": MODEL_PATH.name, "ranker_version": (HERE / "RANKER_VERSION").read_text().strip()}


# async def: requests run one at a time on the event loop, so the catalogue and
# xgboost are never used concurrently.
@app.post("/match")
async def match(req: MatchRequest, background: BackgroundTasks):
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
    if not RANKER:  # after the response is sent, outside the 5 s
        background.add_task(save_gig_embedding, gig, vector, model_name)
    log.info("[timing] matched gig %s in %.0f ms: embed (embedding-service) %.0f ms | candidates (BM25 + cosine + RRF + tags, "
             "%d showcases) %.0f ms | score (xgboost) %.0f ms | response %.0f ms", req.hire_id, (t4 - t0) * 1000,
             (t1 - t0) * 1000, len(cat.providers), (t2 - t1) * 1000, (t3 - t2) * 1000, (t4 - t3) * 1000)
    return out
