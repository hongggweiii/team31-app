"""
Checks that the web app still matches the way the ranker does. Run it after
copying a new ranker version into pipeline/ (and bumping RANKER_VERSION), or
after changing embedding-service/.

  1. embeddings  embedding-service/ (must be running) vs the ranker's own recipe,
                 for showcases (document side) and gigs (query side, with prefix).
                 Expect bit-identical vectors.
  2. matching    app.py's rank() over the ranker's own corpus vs the ranker's
                 feature tables and shipped ranked lists. Expect the same top-50
                 pools, equal features, and the same top-10s. (The ranker computed
                 its dense scores in float16 on other hardware, so a few
                 near-tied candidates can swap places.)
  3. contract    the ranker's example_io.json request, through the same code /match
                 runs, vs its response: identical JSON structure, the same providers
                 in the same order, scores and margins within float16 noise.

Usage (from matcher/, with a checkout of harvestingmoon/ranker):
    .venv/bin/python check_parity.py --ranker ../../ranker [--n 25] [--service http://localhost:8001]
The first run embeds the ranker's 3,024 providers (a few minutes) into cache/.
"""
import argparse
import csv
import json
import sys
import urllib.request
from collections import defaultdict
from pathlib import Path

import numpy as np

import app
from corpus import hirer_text, provider_text
from retrieval_dense import _truncate_and_normalize, encode_docs, encode_queries
from tag_features import TAG_FEATURES, tag_axes

PROVIDER_FIELDS = ["about_title", "about_description", "services_offered_title",
                   "services_offered_description", "relevant_experience"]
GIG_FIELDS = ["hire_title", "hire_description", "hire_description_additional_notes"]
CACHE = app.HERE / "cache"


def post(url: str, body: dict) -> dict:
    req = urllib.request.Request(url, json.dumps(body).encode(), {"Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(req))


def check_embeddings(service: str, providers: list, hirers: list, n: int) -> bool:
    ok = True
    for side, path, key, fields, records, reference in (
        ("showcases", "embed/providers", "providers", PROVIDER_FIELDS, providers[:n],
         lambda rs: encode_docs([provider_text(r) for r in rs])),
        ("gigs", "embed/gigs", "gigs", GIG_FIELDS, hirers[:n],
         lambda rs: encode_queries([hirer_text(r) for r in rs])),
    ):
        got = np.array(post(f"{service}/{path}", {key: [{f: r.get(f) for f in fields} for r in records]})["embeddings"],
                       dtype=np.float32)
        want = _truncate_and_normalize(reference(records), app.DENSE_DIM).astype(np.float32)
        same = got.shape == want.shape and np.array_equal(got, want)
        ok &= same
        print(f"  {side:9s} n={len(records)}  {'bit-identical' if same else 'DIFFERENT'}  "
              f"max |diff| {np.abs(got - want).max() if got.shape == want.shape else 'shape mismatch'}")
    return ok


def cached(name: str, build):
    CACHE.mkdir(exist_ok=True)
    path = CACHE / name
    if not path.exists():
        np.save(path, build())
    return np.load(path)


def catalogue(providers: list, hirers: list) -> app.Catalogue:
    skills = sorted({k for r in hirers + providers for k in tag_axes(r)[2]})
    raw = cached("v5tax_providers.npy", lambda: encode_docs([provider_text(p) for p in providers]))
    return app.Catalogue(providers, _truncate_and_normalize(raw, app.DENSE_DIM),
                         app.build_skill_vectors(skills, CACHE / "skill_vectors.json"))


def gig_unit(gigs: list) -> np.ndarray:
    """What embedding-service returns for these gigs (check 1 proves it): unit-length float16."""
    return _truncate_and_normalize(encode_queries([hirer_text(h) for h in gigs]), app.DENSE_DIM)


def check_matching(ranker: Path, cat: app.Catalogue, hirers: list, n: int) -> bool:
    feats = ranker / "pipeline" / "features_data" / "data_set_v5_tax"

    ref = defaultdict(dict)
    for name in ("candidates_top50.csv", "tag_features.csv"):
        for r in csv.DictReader((feats / name).open()):
            ref[(int(r["hire_id"]), int(r["provider_id"]))].update(r)
    pools = defaultdict(list)
    for r in csv.DictReader((feats / "candidates_top50.csv").open()):
        pools[int(r["hire_id"])].append(int(r["provider_id"]))
    shipped = json.loads((ranker / "pipeline" / "results_data" / "data_set_v5_tax" / "v5tax_tags.json").read_text())

    queries = hirers[:n]
    gig_vectors = gig_unit(queries)
    cols = [c for c in app.FEATURES if c not in app.DEAD_FEATURES]
    max_diff, pool_overlap, top10_same, top10_oof = defaultdict(float), [], [], []
    for h, v in zip(queries, gig_vectors):
        rows = app.candidates(cat, h, v)
        ours = {row["provider_id"]: row for row in rows}
        hid = h["hire_id"]
        pool_overlap.append(len(set(ours) & set(pools[hid])) / len(pools[hid]))
        for pid in set(ours) & set(pools[hid]):
            for c in cols:
                max_diff[c] = max(max_diff[c], abs(float(ours[pid][c]) - float(ref[(hid, pid)][c])))
        # Same model, the ranker's own feature rows vs ours: isolates this service's pipeline.
        theirs = [{"hire_id": 0, "provider_id": pid, **{f: float(ref[(hid, pid)][f]) for f in app.FEATURES},
                   **dict.fromkeys(app.DEAD_FEATURES, 0.0)}  # served as 0.0, as the ranker does
                  for pid in pools[hid]]
        top10 = [c["provider_id"] for c in app.score(rows)[:10]]
        top10_same.append(len(set(top10) & {c["provider_id"] for c in app.score(theirs)[:10]}) / 10)
        top10_oof.append(len(set(top10) & set(shipped[str(hid)][:10])) / 10)

    print(f"  gigs={len(queries)}  top-50 pool overlap: mean {np.mean(pool_overlap):.3f}, min {min(pool_overlap):.2f}")
    print("  max |feature diff| on shared candidates: "
          + ", ".join(f"{c} {max_diff[c]:.1e}" for c in cols))
    print(f"  top-10 overlap, same model on the ranker's features vs ours: mean {np.mean(top10_same):.3f}, "
          f"min {min(top10_same):.1f}")
    print(f"  (for reference) top-10 overlap with the ranker's out-of-fold lists, which come from 5 fold "
          f"models rather than the shipped one: mean {np.mean(top10_oof):.3f}")
    # Features are written rounded to 6 places, so one unit in the last place is equal.
    tags_equal = all(max_diff[c] <= 1.5e-6 for c in TAG_FEATURES)
    bm25_equal = all(max_diff[c] == 0 for c in ("bm25_score", "bm25_rank"))
    return np.mean(pool_overlap) >= 0.98 and tags_equal and bm25_equal and np.mean(top10_same) >= 0.95


def shape(x):
    """A JSON value's structure: dict keys in order, the first element of lists, leaf types."""
    if isinstance(x, dict):
        return {k: shape(v) for k, v in x.items()}
    if isinstance(x, list):
        return [shape(x[0])] if x else []
    return "number" if isinstance(x, (int, float)) and not isinstance(x, bool) else type(x).__name__


def check_contract(ranker: Path, cat: app.Catalogue) -> bool:
    example = json.loads((ranker / "example_io.json").read_text())
    req, want = app.MatchRequest(**example["request"]), example["response"]
    gig = {"hire_id": req.hire_id, "hire_title": req.hire_title, "hire_description": req.hire_description,
           "industry": req.industry, "tags": {"categories": req.taxonomy}}
    got = app.response(app.rank(cat, gig, gig_unit([gig])[0]), cat, req.top_k)

    same_shape = shape(got) == shape(want)
    same_meta = got["meta"] == want["meta"]
    ids = lambda res: [r["provider_id"] for r in res["results"]]  # noqa: E731
    same_order = ids(got) == ids(want)
    d_score = max(abs(a["score"] - b["score"]) for a, b in zip(got["results"], want["results"]))
    d_margin = max(abs(a["raw_margin"] - b["raw_margin"]) for a, b in zip(got["results"], want["results"]))
    print(f"  request fields accepted: {list(example['request'])}")
    print(f"  same JSON structure: {same_shape}   identical meta: {same_meta}   "
          f"same {len(ids(want))} providers in the same order: {same_order}")
    print(f"  largest |score| diff {d_score:.2f}, |raw_margin| diff {d_margin:.6f} "
          f"(example_io.json was scored from the ranker's float16 feature tables)")
    return same_shape and same_meta and same_order and d_score <= 1.0 and d_margin <= 0.1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--ranker", required=True, help="path to a harvestingmoon/ranker checkout")
    ap.add_argument("--n", type=int, default=25, help="gigs (and showcases) to check")
    ap.add_argument("--service", default="http://localhost:8001", help="embedding-service/ base URL")
    ap.add_argument("--skip-embeddings", action="store_true", help="only check matching")
    args = ap.parse_args()

    ranker = Path(args.ranker).resolve()
    data = ranker / "pipeline" / "data" / "data_set_v5_tax"
    providers = json.loads((data / "providers.json").read_text())
    hirers = json.loads((data / "hirers.json").read_text())

    ok = True
    if not args.skip_embeddings:
        print("1. embeddings: embedding-service vs the ranker's encode_docs / encode_queries")
        ok &= check_embeddings(args.service, providers, hirers, args.n)
    cat = catalogue(providers, hirers)
    print("2. matching: app.rank() vs the ranker's feature tables and shipped lists")
    ok &= check_matching(ranker, cat, hirers, args.n)
    print("3. contract: the ranker's example_io.json request through /match's code vs its response")
    ok &= check_contract(ranker, cat)
    print("PASS" if ok else "FAIL")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
