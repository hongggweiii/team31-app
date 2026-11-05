"""
Integration acceptance checks for the SenseiGigs recommendation engine.

Black-box: talks to embedding-service and the matcher over HTTP and to MariaDB
with SQL, so it doesn't matter how the host app is written. Run it against a
staging copy of your database, never production.

    python check.py --env-file ../../engine.env services    # both services against openapi/*.yaml
    python check.py --env-file ../../engine.env database    # your tables and the rows your app wrote
    python check.py --env-file ../../engine.env lifecycle --showcase-id 123
                                             # you edit, unpublish and republish it in your app; it watches
    python check.py fixtures my_output.json  # your code's output for fixtures/*.json

Settings, from environment variables or --env-file (the engine's engine.env works as it is:
its database.default.* and embedding.baseURL keys are read as DB_* and EMBEDDING_URL):
    EMBEDDING_URL   default http://localhost:8001
    MATCHER_URL     default http://localhost:8002
    DB_HOST, DB_PORT (3306), DB_USER, DB_PASSWORD, DB_NAME   for `database` and `lifecycle`
    CHECK_GIG_ID    an existing gig id: `services` then also checks its vector is saved after /match
Environment variables win over the file.

Exit code 0 when nothing FAILs (WARNs are allowed). Needs: pip install -r requirements.txt
"""
from __future__ import annotations  # Python 3.8+

import argparse
import concurrent.futures
import hashlib
import json
import math
import os
import random
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
FIELDS = ["about_title", "about_description", "services_offered_title", "services_offered_description", "relevant_experience"]
REFRESH_WAIT = 45  # seconds: the matcher reloads its catalogue every 30 s
counts = {"PASS": 0, "FAIL": 0, "WARN": 0}


def report(status: str, name: str, detail: str = "") -> bool:
    counts[status] += 1
    print(f"{status:4} {name}" + (f"\n       {detail}" if detail and status != "PASS" else ""))
    return status == "PASS"


def check(name: str, ok: bool, detail: str = "") -> bool:
    return report("PASS" if ok else "FAIL", name, detail)


def warn(name: str, ok: bool, detail: str = "") -> bool:
    return report("PASS" if ok else "WARN", name, detail)


def call(base: str, path: str, body=None, timeout: float = 60):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(base.rstrip("/") + path, data, {"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw or b"null")
        except json.JSONDecodeError:
            return e.code, raw.decode(errors="replace")


def provider_text(fields: dict) -> str:
    """The exact text embedding-service embeds: the five fields in order, empty ones skipped, joined by one space."""
    return " ".join(str(fields.get(f) or "") for f in FIELDS if fields.get(f))


def cosine(a, b) -> float:
    return sum(x * y for x, y in zip(a, b)) / (math.sqrt(sum(x * x for x in a)) * math.sqrt(sum(y * y for y in b)))


def schemas(spec_file: str):
    """A validator per component schema of an OpenAPI file, or None when jsonschema / pyyaml are missing."""
    try:
        import yaml
        from jsonschema import Draft202012Validator
        from referencing import Registry, Resource
    except ImportError:
        return None
    spec = yaml.safe_load((HERE.parent / "openapi" / spec_file).read_text())
    registry = Registry().with_resource("spec", Resource.from_contents(
        {"$schema": "https://json-schema.org/draft/2020-12/schema", **spec}))

    def validate(name: str, data) -> str:
        errors = list(Draft202012Validator({"$ref": f"spec#/components/schemas/{name}"}, registry=registry).iter_errors(data))
        return "; ".join(e.message[:150] for e in errors[:3])
    return validate


ENGINE_KEYS = {"database.default.hostname": "DB_HOST", "database.default.port": "DB_PORT",
               "database.default.username": "DB_USER", "database.default.password": "DB_PASSWORD",
               "database.default.database": "DB_NAME", "embedding.baseURL": "EMBEDDING_URL"}


def load_env_file(path: Path) -> None:
    """KEY=VALUE lines (comments, blank lines and quotes as in a .env file); never overrides the environment."""
    if not path.is_file():
        sys.exit(f"--env-file: {path} not found")
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = (x.strip() for x in line.split("=", 1))
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "'\"":
            value = value[1:-1]
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()
        os.environ.setdefault(ENGINE_KEYS.get(key, key), value)


def db():
    import pymysql
    missing = [k for k in ("DB_HOST", "DB_USER", "DB_NAME") if not os.environ.get(k)]
    if missing:
        sys.exit(f"Set {', '.join(missing)} (and DB_PASSWORD, DB_PORT) for this check.")
    try:
        return pymysql.connect(host=os.environ["DB_HOST"], port=int(os.environ.get("DB_PORT", "3306")),
                               user=os.environ["DB_USER"], password=os.environ.get("DB_PASSWORD", ""),
                               database=os.environ["DB_NAME"], charset="utf8mb4", autocommit=True,
                               cursorclass=pymysql.cursors.DictCursor)
    except pymysql.err.MySQLError as e:
        sys.exit(f"Can't connect to the database {os.environ['DB_USER']}@{os.environ['DB_HOST']}:{os.environ.get('DB_PORT', '3306')}"
                 f"/{os.environ['DB_NAME']}: {e}")


def query(conn, sql: str, args=None) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute(sql, args)
        return list(cur.fetchall())


# ---- services ---------------------------------------------------------------------

def check_services(emb: str, mat: str) -> None:
    ev, mv = schemas("embedding-service.yaml"), schemas("matcher.yaml")
    if ev is None:
        report("WARN", "schema validation skipped", "pip install pyyaml jsonschema to validate responses against openapi/*.yaml")
        ev = mv = lambda name, data: ""

    print("\n== embedding-service", emb)
    s, h = call(emb, "/health")
    check("E1 GET /health is 200 and matches the spec", s == 200 and not ev("Health", h), ev("Health", h) or f"HTTP {s}: {h}")
    if s != 200:
        return
    a, b = {"about_title": "Tax advisor for SMEs"}, {"about_title": "Kubernetes platform engineer"}
    s, one = call(emb, "/embed/provider", a)
    check("E2 POST /embed/provider is 200 and matches the spec", s == 200 and not ev("SingleEmbedding", one), ev("SingleEmbedding", one) or f"HTTP {s}")
    vec = one.get("embedding", []) if s == 200 else []
    check("E3 vectors are 1024-d and unit length", len(vec) == 1024 and abs(math.sqrt(sum(x * x for x in vec)) - 1) < 1e-3)
    check("E4 the same input gives the same vector", call(emb, "/embed/provider", a)[1].get("embedding") == vec)
    _, ab = call(emb, "/embed/providers", {"providers": [a, b]})
    _, ba = call(emb, "/embed/providers", {"providers": [b, a]})
    check("E5 /embed/providers keeps input order", ab["embeddings"][0] == ba["embeddings"][1] and ab["embeddings"][1] == ba["embeddings"][0])
    check("E6 a batch of 128 is accepted", call(emb, "/embed/providers", {"providers": [a] * 128})[0] == 200)
    s, body = call(emb, "/embed/providers", {"providers": [a] * 129})
    check("E7 a batch of 129 is refused with 422", s == 422, f"HTTP {s}")
    s, body = call(emb, "/embed/provider", {"about_title": "   "})
    check("E8 a showcase with no text is refused with 422", s == 422, f"HTTP {s}")
    s, g = call(emb, "/embed/gigs", {"gigs": [{"hire_title": a["about_title"]}]})
    check("E9 POST /embed/gigs is 200 and differs from the provider vector (query prefix)",
          s == 200 and g["embeddings"][0] != vec, f"HTTP {s}")
    model = h.get("model")

    print("\n== matcher", mat)
    s, mh = call(mat, "/health")
    check("M1 GET /health is 200 and matches the spec", s == 200 and not mv("Health", mh), mv("Health", mh) or f"HTTP {s}: {mh}")
    if s != 200:
        return
    warn("M2 the catalogue is not empty", mh["providers"] > 0,
         "0 showcases: nothing is published and embedded yet, so ranking checks below are skipped")
    gig_id = int(os.environ.get("CHECK_GIG_ID") or 0)
    req = {"hire_id": gig_id or 2_000_000_000, "hire_title": "Interim CFO for a fintech",
           "hire_description": "Prepare our Series B data room, rebuild the forecast and manage the audit.",
           "industry": None, "taxonomy": [], "top_k": 10}
    t0 = time.perf_counter()
    s, r = call(mat, "/match", req)
    ms = (time.perf_counter() - t0) * 1000
    check("M3 POST /match is 200 and matches the spec", s == 200 and not mv("MatchResponse", r), mv("MatchResponse", r) or f"HTTP {s}: {str(r)[:200]}")
    if s != 200:
        return
    res, meta = r["results"], r["meta"]
    check("M4 meta.catalogue_size equals /health providers", meta.get("catalogue_size") == mh["providers"],
          f"{meta.get('catalogue_size')} vs {mh['providers']}")
    check("M5 results = min(top_k, catalogue_size)", len(res) == min(10, meta.get("catalogue_size", 0)), f"{len(res)} results")
    if res:
        check("M6 ranks run 1..n and scores are 0-100, descending, rank 1 = 100",
              [x["rank"] for x in res] == list(range(1, len(res) + 1))
              and [x["score"] for x in res] == sorted((x["score"] for x in res), reverse=True)
              and res[0]["score"] == 100 and all(0 <= x["score"] <= 100 for x in res))
        check("M7 the same request gives the same order", [x["provider_id"] for x in call(mat, "/match", req)[1]["results"]]
              == [x["provider_id"] for x in res])
    warn(f"M8 one match well inside the 5 s budget ({ms:.0f} ms)", ms < 2000, "over 2 s: check DB latency and embedding-service hardware")
    for k, want in ((1, 200), (50, 200), (0, 422), (51, 422)):
        check(f"M9 top_k={k} gives HTTP {want}", call(mat, "/match", {**req, "top_k": k})[0] == want)
    check("M10 a gig with no text is refused with 422", call(mat, "/match", {**req, "hire_title": " ", "hire_description": ""})[0] == 422)
    check("M11 a taxonomy entry without a name is refused with 422",
          call(mat, "/match", {**req, "taxonomy": [{"specialisations": []}]})[0] == 422)
    tagged = {**req, "taxonomy": [{"name": "Accountancy", "group": None, "specialisations": [
        {"name": "Financial Accounting", "search_tag": "Financial Accounting (Accountancy)", "skills": ["Capital Raising"]}]}]}
    check("M12 a tagged gig is 200", call(mat, "/match", tagged)[0] == 200)
    with concurrent.futures.ThreadPoolExecutor(8) as pool:
        codes = list(pool.map(lambda _: call(mat, "/match", req)[0], range(8)))
    check("M13 8 parallel matches all succeed (they queue)", codes == [200] * 8, str(codes))
    if gig_id and not res:
        report("WARN", "M14 skipped: the catalogue is empty, so the matcher has nothing to rank and doesn't embed the gig")
    elif gig_id:
        time.sleep(2)
        conn = db()
        rows = query(conn, "SELECT model FROM gig_embeddings WHERE gig_id = %s", (gig_id,))
        check(f"M14 gig {gig_id}'s vector is saved to gig_embeddings after /match", len(rows) == 1 and rows[0]["model"] == model, str(rows))
    else:
        report("WARN", "M14 skipped: set CHECK_GIG_ID to an existing gig id to check gig vectors are saved")


# ---- database ----------------------------------------------------------------------

TABLES = ["sf_categories", "sf_specialisations", "sf_skills", "sf_specialisation_skills", "showcase_specialisations",
          "showcase_skills", "gig_specialisations", "gig_skills", "showcase_embeddings", "gig_embeddings"]


def check_database(emb: str, mat: str, sample: int) -> None:
    conn = db()
    print("\n== database", os.environ["DB_NAME"])
    version = query(conn, "SELECT VERSION() AS v")[0]["v"]
    major, minor = (int(x) for x in version.split(".")[:2])
    check(f"D1 MariaDB 11.7 or later ({version})", "MariaDB" in version and (major, minor) >= (11, 7))
    have = {r["t"] for r in query(conn, "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()")}
    missing = [t for t in TABLES + ["showcases", "gigs"] if t not in have]
    check("D2 every table exists", not missing, f"missing: {missing}")
    if missing:
        return
    cols = {(r["t"], r["c"]): r["ty"] for r in query(conn, "SELECT table_name AS t, column_name AS c, column_type AS ty "
                                                    "FROM information_schema.columns WHERE table_schema = DATABASE()")}
    need = [("showcases", "id"), ("showcases", "status"), ("showcases", "updated_at"), ("gigs", "id")]
    check("D3 showcases has id, status, updated_at; gigs has id", all(k in cols for k in need), f"missing: {[k for k in need if k not in cols]}")
    base = lambda ty: ty.replace("int(10)", "int").replace("int(11)", "int").replace("bigint(20)", "bigint")
    check("D4 the link and embedding tables' id columns match your ids' type",
          all(base(cols[(t, f"{o}_id")]) == base(cols[(o + "s", "id")])
              for o in ("showcase", "gig") for t in (f"{o}_specialisations", f"{o}_skills", f"{o}_embeddings")),
          "see sql/README.md, Step 1")

    n = query(conn, "SELECT (SELECT COUNT(*) FROM sf_categories) c, (SELECT COUNT(*) FROM sf_specialisations) s, "
                    "(SELECT COUNT(*) FROM sf_skills) k, (SELECT COUNT(*) FROM sf_specialisation_skills) l")[0]
    check("D5 the taxonomy is loaded", all(n[k] > 0 for k in "cskl"), str(n))

    over = []
    for o in ("showcase", "gig"):
        link = f"FROM {o}_specialisations l JOIN sf_specialisations s ON s.id = l.specialisation_id"
        over += [f"{o} {r['id']}: {r['n']} categories" for r in query(
            conn, f"SELECT l.{o}_id id, COUNT(DISTINCT s.category_id) n {link} GROUP BY l.{o}_id HAVING n > 2 LIMIT 5")]
        over += [f"{o} {r['id']}: {r['n']} specialisations in one category" for r in query(
            conn, f"SELECT l.{o}_id id, COUNT(*) n {link} GROUP BY l.{o}_id, s.category_id HAVING n > 3 LIMIT 5")]
        over += [f"{o} {r['id']}: {r['n']} skills in one specialisation" for r in query(
            conn, f"SELECT {o}_id id, COUNT(*) n FROM {o}_skills GROUP BY {o}_id, specialisation_id HAVING n > 8 LIMIT 5")]
    warn("D6 tags within the caps (2 categories, 3 specialisations each, 8 skills each)", not over,
         f"the model was trained within these caps: {over[:10]}")

    rows = query(conn, "SELECT e.showcase_id, e.model, e.text_hash, e.fields_json, s.status FROM showcase_embeddings e "
                       "JOIN showcases s ON s.id = e.showcase_id")
    published = query(conn, "SELECT COUNT(*) n FROM showcases WHERE status = 'published'")[0]["n"]
    check("D7 showcases have embeddings", len(rows) > 0, "none yet: embed your showcases first (data-setup.md)")
    if not rows:
        return
    s, h = call(emb, "/health")
    models = {r["model"] for r in rows}
    check("D8 every vector comes from one model, the one embedding-service runs", models == {h.get("model")},
          f"stored: {sorted(models)}; service: {h.get('model')}. Re-embed rows from other models")
    bad_json, bad_keys, bad_hash = [], [], []
    for r in rows:
        try:
            f = json.loads(r["fields_json"]) if r["fields_json"] else None
        except json.JSONDecodeError:
            f = None
        if not isinstance(f, dict):
            bad_json.append(r["showcase_id"])
            continue
        if not set(FIELDS + ["years_experience"]) <= set(f):
            bad_keys.append(r["showcase_id"])
        if hashlib.sha256(provider_text(f).encode()).hexdigest() != r["text_hash"]:
            bad_hash.append(r["showcase_id"])
    check("D9 every fields_json is a JSON object (rows without one are never ranked)", not bad_json, f"showcase ids: {bad_json[:10]}")
    check("D10 every fields_json has the 5 text fields and years_experience", not bad_keys, f"showcase ids: {bad_keys[:10]}")
    check("D11 every text_hash is sha256 of the fields' text, built as embedding-service builds it", not bad_hash,
          f"showcase ids: {bad_hash[:10]} -- see integration-guide.md, 'The text'")
    embedded_published = sum(1 for r in rows if r["status"] == "published" and r["fields_json"])
    warn("D12 every published showcase is embedded", embedded_published == published,
         f"{published - embedded_published} published showcase(s) have no usable embedding yet, so they are never matched")
    stale = query(conn, "SELECT s.id, s.updated_at AS showcase_at, e.updated_at AS embedding_at FROM showcases s "
                        "JOIN showcase_embeddings e ON e.showcase_id = s.id "
                        "WHERE s.status = 'published' AND s.updated_at > e.updated_at ORDER BY s.updated_at LIMIT 10")
    warn("D15 no published showcase is waiting for re-embedding (edited after its vector)", not stale,
         f"{len(stale)}+ waiting, oldest first: {[(r['id'], str(r['showcase_at'])) for r in stale[:5]]}. "
         "Expected for a minute after edits; if they stay, your embedding job isn't running")
    tz = query(conn, "SELECT @@session.time_zone AS tz, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_min")[0]
    future = query(conn, "SELECT (SELECT COUNT(*) FROM showcases WHERE updated_at > NOW() + INTERVAL 10 MINUTE) s, "
                         "(SELECT COUNT(*) FROM showcase_embeddings WHERE updated_at > NOW() + INTERVAL 10 MINUTE) e")[0]
    warn("D16 timestamps are on one clock (showcases.updated_at vs showcase_embeddings.updated_at)",
         tz["offset_min"] == 0 and future["s"] == 0 and future["e"] == 0,
         f"database clock: time_zone {tz['tz']}, UTC{tz['offset_min'] / 60:+g} h; rows dated in the future: "
         f"{future['s']} showcases, {future['e']} embeddings. If your app writes updated_at in another time zone than "
         "the database's NOW(), edits can go unnoticed for hours: write both columns with the same clock "
         "(integration-guide.md, G2), then confirm with `check.py lifecycle`")

    picked = random.Random(0).sample([r for r in rows if r["showcase_id"] not in bad_json], min(sample, len(rows)))
    low = []
    for r in picked:
        stored = json.loads(query(conn, "SELECT VEC_ToText(embedding) v FROM showcase_embeddings WHERE showcase_id = %s",
                                  (r["showcase_id"],))[0]["v"])
        f = json.loads(r["fields_json"])
        s, fresh = call(emb, "/embed/provider", {k: f.get(k) or None for k in FIELDS})
        if s != 200 or cosine(stored, fresh["embedding"]) < 0.998:
            low.append((r["showcase_id"], round(cosine(stored, fresh["embedding"]), 4) if s == 200 else f"HTTP {s}"))
    check(f"D13 stored vectors match a fresh embedding of their fields_json ({len(picked)} sampled, cosine >= 0.998)", not low,
          f"(showcase id, cosine): {low[:10]} -- stored with the wrong side, model or text?")
    s, mh = call(mat, "/health")
    if s == 200:
        warn("D14 the matcher sees every published, embedded showcase", mh["providers"] == embedded_published,
             f"matcher {mh['providers']} vs database {embedded_published}; if you just changed data, it reloads within 30 s")


# ---- lifecycle ---------------------------------------------------------------------

def check_lifecycle(mat: str, showcase_id: int, auto: bool, edit_wait: int) -> None:
    """Interactive (default): you edit, unpublish and republish the showcase in your app, so your own
    code (G2, G3) is what gets tested. --auto writes `status` and `updated_at` itself instead."""
    conn = db()
    print(f"\n== lifecycle, showcase {showcase_id}" + (" (--auto)" if auto else ""))
    row = query(conn, "SELECT s.status, e.text_hash FROM showcases s LEFT JOIN showcase_embeddings e ON e.showcase_id = s.id "
                      "WHERE s.id = %s", (showcase_id,))
    if not row or row[0]["status"] != "published" or row[0]["text_hash"] is None:
        sys.exit(f"Showcase {showcase_id} must exist, be published and be embedded.")
    if not auto and not sys.stdin.isatty():
        sys.exit("The interactive lifecycle check needs a terminal. Use --auto only if `showcases.status` is a real column.")

    def wait_for(cond, seconds: int = REFRESH_WAIT) -> float | None:
        start = time.time()
        while time.time() - start < seconds:
            if cond():
                return time.time() - start
            time.sleep(3)
        return None

    def providers() -> int:
        return call(mat, "/health")[1]["providers"]

    def act(instruction: str) -> None:
        input(f"\n  -> {instruction}\n     Press Enter when done. ")

    before = providers()
    if auto:
        try:
            query(conn, "UPDATE showcases SET status = 'lifecycle_check', updated_at = NOW() WHERE id = %s", (showcase_id,))
        except Exception as e:  # e.g. `showcases` is a view over your own table
            sys.exit(f"--auto can't change showcases.status ({e}). Run the interactive check instead (without --auto).")
        try:
            t = wait_for(lambda: providers() == before - 1)
            check("L2 an unpublished showcase leaves the catalogue within 30 s", t is not None, f"after {REFRESH_WAIT} s still {before}")
        finally:
            query(conn, "UPDATE showcases SET status = 'published', updated_at = NOW() + INTERVAL 1 SECOND WHERE id = %s", (showcase_id,))
        check("L3 republishing brings it back", wait_for(lambda: providers() == before) is not None)
        print("       (status restored to 'published'; updated_at was bumped)")
        return

    old_hash = row[0]["text_hash"]
    act(f"In your app, edit showcase {showcase_id}'s bio (add a sentence) and save it. Then run your embedding job, "
        "or let its schedule run it.")
    new = lambda: query(conn, "SELECT text_hash FROM showcase_embeddings WHERE showcase_id = %s", (showcase_id,))[0]["text_hash"]
    t = wait_for(lambda: new() != old_hash, edit_wait)
    check("L1 an edit is re-embedded (new text_hash)", t is not None,
          f"after {edit_wait} s the stored text_hash is unchanged: check the job ran, that the edit bumped updated_at, "
          "and that both timestamps use the same clock (D16)")
    act(f"Unpublish showcase {showcase_id} in your app.")
    t = wait_for(lambda: providers() == before - 1)
    check("L2 an unpublished showcase leaves the catalogue within 30 s", t is not None,
          f"the matcher still has {providers()} showcases: check that unpublishing changes what `showcases.status` shows and bumps updated_at")
    act(f"Publish showcase {showcase_id} again in your app (and revert the bio edit if you like).")
    check("L3 republishing brings it back", wait_for(lambda: providers() == before) is not None)


# ---- fixtures ----------------------------------------------------------------------

def ranked_tags(taxonomy) -> list:
    """What the matcher uses from a taxonomy array: (category, specialisation, skills) names. Order, `group`
    and `search_tag` don't affect ranking, so they aren't compared."""
    if not isinstance(taxonomy, list):
        return [repr(taxonomy)]
    return sorted((c.get("name"), s.get("name"), sorted(s.get("skills") or []))
                  for c in taxonomy if isinstance(c, dict) for s in (c.get("specialisations") or []) if isinstance(s, dict))


def check_fixtures(output: Path) -> None:
    """Compares your code's output for the fixture inputs with ours, field by field."""
    theirs = json.loads(output.read_text())
    print("\n== fixtures vs", output)
    for kind, key in (("showcases", ("fields_json", "provider_text", "text_hash")), ("gigs", ("match_request",))):
        ours = json.loads((HERE / "fixtures" / f"{kind}.json").read_text())
        got = {str(x["id"]): x for x in theirs.get(kind, [])}
        for case in ours:
            mine = got.get(str(case["id"]))
            if mine is None:
                report("FAIL", f"{kind} {case['id']} ({case['case']})", "missing from your output")
                continue
            diffs = []
            for k in key:
                want, have = case["expected"][k], mine.get(k)
                if k == "match_request" and isinstance(have, dict):
                    want = {**want, "taxonomy": ranked_tags(want["taxonomy"])}
                    have = {**have, "taxonomy": ranked_tags(have.get("taxonomy"))}
                    want.pop("top_k"), have.pop("top_k", None)  # how many results you show is your choice
                if isinstance(want, dict):
                    have = have if isinstance(have, dict) else {}
                    diffs += [f"{k}.{f}: missing (expected {json.dumps(want[f], ensure_ascii=False)})" for f in want if f not in have]
                    diffs += [f"{k}.{f}: expected {json.dumps(want[f], ensure_ascii=False)}, got {json.dumps(have[f], ensure_ascii=False)}"
                              for f in want if f in have and have[f] != want[f]]
                    diffs += [f"{k}.{f}: unexpected key" for f in have if f not in want]
                elif have != want:
                    diffs.append(f"{k}: expected {json.dumps(want, ensure_ascii=False)[:200]}, got {json.dumps(have, ensure_ascii=False)[:200]}")
            check(f"{kind} {case['id']} ({case['case']})", not diffs, "\n       ".join(diffs[:6]))


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--env-file", type=Path, help="read settings from this file (e.g. the engine's engine.env)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("services")
    d = sub.add_parser("database")
    d.add_argument("--sample", type=int, default=20, help="stored vectors to re-embed and compare (default 20)")
    l = sub.add_parser("lifecycle")
    l.add_argument("--showcase-id", type=int, required=True, help="a published, embedded showcase on staging")
    l.add_argument("--auto", action="store_true",
                   help="change status/updated_at directly instead of asking you to (only if showcases.status is a real column)")
    l.add_argument("--edit-wait", type=int, default=300,
                   help="seconds to wait for your embedding job to re-embed the edit (default 300; raise it if the job runs less often)")
    f = sub.add_parser("fixtures")
    f.add_argument("output", type=Path, help="your output: {\"showcases\": [{id, fields_json, provider_text, text_hash}], \"gigs\": [{id, match_request}]}")
    args = ap.parse_args()
    if args.env_file:
        load_env_file(args.env_file)

    emb = os.environ.get("EMBEDDING_URL", "http://localhost:8001")
    mat = os.environ.get("MATCHER_URL", "http://localhost:8002")
    if args.cmd == "services":
        check_services(emb, mat)
    elif args.cmd == "database":
        check_database(emb, mat, args.sample)
    elif args.cmd == "lifecycle":
        check_lifecycle(mat, args.showcase_id, args.auto, args.edit_wait)
    else:
        check_fixtures(args.output)
    print(f"\n{counts['PASS']} passed, {counts['WARN']} warnings, {counts['FAIL']} failed")
    sys.exit(1 if counts["FAIL"] else 0)


if __name__ == "__main__":
    main()
