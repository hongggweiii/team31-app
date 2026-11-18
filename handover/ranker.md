# The ranker repo

## What it is

The **ranker** repo (`harvestingmoon/ranker`) is where the Data team built and trained the matching model. It holds:

| Part | What it is |
|---|---|
| `pipeline/` | The matching pipeline in Python: text building (`corpus.py`), BM25 and dense retrieval, RRF fusion, the 17 features (`features.py`, `tag_features.py`) and the LambdaMART training and scoring (`rerank_ltr.py`) |
| `pipeline/models_data/data_set_v5_tax/v5tax_tags_xgb.json` | The shipped model: an XGBoost LambdaMART ranker over 17 features |
| `API_IO_SPEC.md`, `example_io.json` | The request/response contract that `/match` follows, with a worked example |
| `serve.py` | The Data team's reference server |
| `pipeline/data/data_set_v5_tax/` | The training data: 3,024 providers (`providers.json`) and the hirers (`hirers.json`) the model was trained and evaluated on, tagged with SkillsFuture `sf_v1` |
| `pipeline/features_data/`, `pipeline/results_data/` | The feature tables and ranked lists the model produced, which the parity check compares against |

**You do not deploy the ranker repo.** The matcher service (`matcher/` in team31-app) already contains everything it needs from it:

- `matcher/pipeline/` holds **verbatim copies** of the ranker files used at serving time (`corpus.py`, `retrieval_bm25.py`, `retrieval_dense.py`, `retrieval_rrf.py`, `rerank_ltr.py`, `tag_features.py`, `taxonomy.py`, `features.py`, `evaluate.py`, `make_api_sample.py`, `data/synonyms.json`) and the model file.
- `matcher/RANKER_VERSION` records the ranker commit they were copied from.
- The embedding service copies the ranker's text building and encoding (`provider_text`, `hirer_text`, the query prefix, normalisation). This keeps the vectors identical to the ones the model was trained on.

You need the ranker repo for only three things:

1. **Parity checks:** confirming the services still reproduce the ranker exactly (`matcher/check_parity.py`).
2. **Upgrades:** taking a newer model or pipeline from the Data team.
3. **The taxonomy and reference data:** the `sf_v1`-tagged records the taxonomy seed comes from (the seed for your database is `seed/sf_taxonomy.sql`: see `data-setup.md`). The records themselves, and the full SkillsFuture framework they were tagged from, were generated in the data generation repo (`data-generation.md`).

## Where to put it

Clone it **next to** the folder that holds `matcher/` and `embedding-service/`, never inside your web app's code:

```
projects/
├── your-app/              your PHP website
├── team31-app/           your clone of the team31-app repository
│   ├── matcher/
│   ├── embedding-service/
│   └── handover/
└── ranker/                git clone of harvestingmoon/ranker
```

The tools expect this layout: `check_parity.py --ranker ../../ranker` and `MATCHER_CORPUS=../../ranker` are relative to `matcher/`. Another location works if you pass its path to those options.

Keep it as a separate git repository. Don't copy it into your app or add it as a submodule of your app: your production deployment never needs it, and it contains training data that doesn't belong on a web server.

## Checking parity

Run this after any change to `matcher/pipeline/`, the embedding service or their Python packages. It needs the embedding service running.

```bash
cd matcher
.venv/bin/python check_parity.py --ranker ../../ranker
```

It checks three things:

| Check | Compares | Passes when |
|---|---|---|
| Embeddings | Vectors from the embedding service vs the ranker's own recipe, for providers and gigs | They are bit-identical |
| Matching | The matcher's ranking over the ranker's corpus vs the ranker's feature tables and ranked lists | The top-50 pools are the same, features are equal and the top-10s are the same (a few near-tied candidates may swap) |
| Contract | `example_io.json`'s request through `/match`'s code vs its response | The JSON structure is identical, the providers are in the same order, and scores are within float16 noise |

The first run embeds the ranker's 3,024 providers into `matcher/cache/`, which takes a few minutes.

In `example_io.json`, rank 10's score differs slightly from a live run. That file was scored from the ranker's precomputed feature tables; the live run equals what the ranker's own `serve.py` returns.

To replay the ranker's example by hand against the ranker's own corpus instead of your database:

```bash
cd matcher
MATCHER_CORPUS=../../ranker .venv/bin/uvicorn app:app --port 8002
curl -X POST localhost:8002/match -H 'Content-Type: application/json' \
     -d "$(python3 -c "import json; print(json.dumps(json.load(open('../../ranker/example_io.json'))['request']))")"
```

## Upgrading to a newer ranker

1. Pull the ranker repo and note the new commit (`git rev-parse HEAD`).
2. Copy the same files from `ranker/pipeline/` into `matcher/pipeline/`, including the model under `models_data/`. Copy them unchanged.
3. Write the new commit into `matcher/RANKER_VERSION`.
4. If the model file name changed, update `MODEL_PATH` in `matcher/app.py`. If the feature list changed, check `FEATURES` and `DEAD_FEATURES` against the ranker's `API_IO_SPEC.md`.
5. Run `check_parity.py` (above). It must pass before you deploy.
6. Restart the matcher. `GET /health` reports the new `ranker_version`.

If the Data team **changes the embedding model, the text recipe or the query prefix**, every stored vector is out of date:

1. Update `embedding-service/embedding.py` to match.
2. Run the parity check.
3. Re-embed every showcase. The matcher must never rank vectors from two different models; see `showcase_embeddings.model`.

If they release a **new taxonomy version** (after `sf_v1`), the model was trained on it. Load it into the `sf_*` tables and re-tag gigs and showcases; see `data-setup.md`.
