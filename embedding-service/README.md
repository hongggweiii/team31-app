# embedding-service

Turns showcase and gig text into the vectors the ranker was trained on: `mixedbread-ai/mxbai-embed-large-v1`, 1024 dimensions, unit length. It runs on port 8001. How to run it is at the top of `app.py`.

## Files

| File | Where it comes from |
|---|---|
| `embedding.py` | Partly the ranker's code. `provider_text` and `hirer_text` are in the ranker's `pipeline/common/corpus.py`. The model name, query prefix, `_get_model`, `_truncate_and_normalize`, `encode_docs` and `encode_queries` are in `pipeline/recall/retrieval_dense.py`. The code is the same, except that `_get_model` also picks the device. `EMBED_DIM`, the device handling and the `embed_*` functions are new. Comments in the file mark which is which |
| `app.py` | New. The ranker has no service: it embeds in the same process that ranks. This serves the vectors over HTTP |
| `requirements.txt` | New. Only what these two files need |

## Who calls it

| Caller | Endpoints | For |
|---|---|---|
| PHP app (`app/Libraries/ProviderEmbedder.php`) | `/embed/provider`, `/embed/providers` | Showcase vectors, stored in `showcase_embeddings` |
| `matcher/app.py` | `/embed/gigs` | The gig, embedded live inside each match request |
| `matcher/app.py` | `/embed/providers` | Skill names, for the `skill_soft` tag feature |
| `matcher/check_parity.py` | `/embed/providers`, `/embed/gigs` | Checking the vectors are bit-identical to the ranker's own |

## Differences from the ranker

- **The vectors are the same.** Showcases are the document side, with no prefix. Gigs are the query side, with the ranker's `QUERY_PREFIX_GENERIC`.
- **The size is fixed.** The ranker passes `truncate_dim` to each call. Here it is fixed at `EMBED_DIM = 1024`, the shipped ranker's setting.
- **The device can be pinned.** The ranker lets sentence-transformers choose the device. Here `EMBED_DEVICE` can pin it (`mps`, `cuda` or `cpu`).
- **Requests are checked.** Text that is all empty gets a 422, and a batch holds at most 128 records. The ranker does neither.

## Keeping it in sync

If the ranker changes any of the following, every stored vector is out of date:
- the model;
- the text fields or their order;
- the query prefix;
- the normalisation.

To update:
1. Update `embedding.py`.
2. Run `matcher/check_parity.py`.
3. Re-embed every showcase.
