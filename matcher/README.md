# matcher

`POST /match`: one gig in, ranked showcases out. It runs the Data team's ranker (`harvestingmoon/ranker`) over the app's showcases in MariaDB. Where `app.py` differs from the ranker's `serve.py` is noted in comments next to each section. `check_parity.py` explains how to check it against a ranker checkout.

## New in this folder

These files have no counterpart in the ranker.

| File | What it is |
|---|---|
| `app.py` | The service (FastAPI, port 8002). It rebuilds the ranker's `serve.py` from the `pipeline/` functions. It reads showcases, vectors and tags from MariaDB, embeds the gig through embedding-service, and saves the gig's vector after responding |
| `check_parity.py` | Checks the matcher against a ranker checkout: embeddings, features and rankings, and `example_io.json` |
| `RANKER_VERSION` | The ranker commit `pipeline/` was copied from. `/health` reports it |
| `requirements.txt` | Package versions for this service |
| `cache/` (not in git) | Generated skill-name and corpus vectors |

## Copied from the ranker: `pipeline/`

Each file starts with a header comment: where the file is in the ranker, and what the matcher uses from it. Don't change anything below the header.

| File | Used by the matcher for |
|---|---|
| `corpus.py` | `hirer_text` (the gig's text) and BM25 tokenising |
| `retrieval_bm25.py`, `data/synonyms.json` | `BM25Retriever` with its synonym expansion |
| `retrieval_dense.py` | `_truncate_and_normalize` only. Embedding is done by embedding-service, so the model is never loaded here |
| `retrieval_rrf.py` | `rrf_fuse` |
| `tag_features.py` | The 8 SkillsFuture tag features (`TagMatcher`, `tag_axes`, `embed_skills`) |
| `rerank_ltr.py` | `DEFAULT_FEATURES`, `SCORE_FEATURES`, `normalize_by_query`. Its training code is unused |
| `evaluate.py`, `taxonomy.py` | Nothing directly: `rerank_ltr.py` imports them |
| `features.py`, `make_api_sample.py` | Nothing: not imported. `features.py` is the reference for the feature rows in `app.py` |
| `models_data/data_set_v5_tax/v5tax_tags_xgb.json` | The shipped 17-feature LambdaMART model |

JSON files can't hold comments, so `data/synonyms.json` and the model are byte-identical to the ranker's. A comment key in `synonyms.json` would also be read as a synonym.

In the ranker, these files are in stage folders (`common/`, `recall/`, `features/`, `lightweight/`, `serving/`) with package imports (`from pipeline.common.corpus import ...`). `taxonomy.py` and `make_api_sample.py` are no longer there. The model and the feature logic are the same. `app.py` imports the flat file names, so the ranker's files can't be copied in as they are. Adjust the imports first.

## Upgrading to a newer ranker

1. Copy the files above from the new ranker commit, adjusting imports as needed.
2. Put the header back on each file, and write the new commit into `RANKER_VERSION`.
3. Run `check_parity.py`.
