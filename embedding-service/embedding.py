"""
Provider profile / gig -> embedding, copied from the ranker repo so the web
app produces exactly the vectors the ranker was built and evaluated on.

    provider_text, hirer_text           ranker/pipeline/common/corpus.py
    BASE_MODEL_NAME, QUERY_PREFIX_GENERIC,
    _get_model, _truncate_and_normalize,
    encode_docs, encode_queries         ranker/pipeline/recall/retrieval_dense.py

Keep those copies in sync with the ranker -- any change to the field list,
field order, model, prefix or normalisation changes every vector, and existing
vectors would need re-embedding.

mxbai-embed-large-v1 is asymmetric: providers are the *documents* side and get
no instruction prefix; gigs are the *query* side and get QUERY_PREFIX_GENERIC
(the prefix the shipped ranker's dense leg used -- features.py calls
encode_queries with its default).

EMBED_DIM is the full 1024-dim output -- what the shipped ranker's dense leg
used (features.py --dense-dim default). The model is Matryoshka-trained, so a
smaller value (512/256/...) also works, but it must match
app/Config/Embedding.php::$dimensions and the vector DB column.

The model runs on EMBED_DEVICE (environment variable: mps, cuda or cpu). Unset,
sentence-transformers picks the best device it finds (on Apple Silicon, mps),
which can silently be the CPU; set, it is used or the service won't start.
"""
import os

import numpy as np
import torch
from sentence_transformers import SentenceTransformer

# From ranker retrieval_dense.py, except EMBED_DIM (the ranker passes truncate_dim to each call instead).
BASE_MODEL_NAME = "mixedbread-ai/mxbai-embed-large-v1"
EMBED_DIM = 1024
QUERY_PREFIX_GENERIC = "Represent this sentence for searching relevant passages: "

_model_cache: dict[str, SentenceTransformer] = {}

# Choosing the device; the ranker lets sentence-transformers choose it.
_DEVICE_AVAILABLE = {
    "mps": lambda: torch.backends.mps.is_available(),
    "cuda": lambda: torch.cuda.is_available(),
    "cpu": lambda: True,
}


def embed_device() -> str | None:
    """EMBED_DEVICE, checked; None when unset (sentence-transformers decides).

    Returns:
        "mps", "cuda", "cpu", or None.

    Raises:
        RuntimeError: EMBED_DEVICE names an unknown device, or one torch can't use here.
    """
    device = os.environ.get("EMBED_DEVICE", "").strip().lower() or None
    if device is None:
        return None
    if device not in _DEVICE_AVAILABLE:
        raise RuntimeError(f"EMBED_DEVICE={device!r}: use one of {', '.join(_DEVICE_AVAILABLE)}")
    if not _DEVICE_AVAILABLE[device]():
        raise RuntimeError(f"EMBED_DEVICE={device!r}, but torch {torch.__version__} can't use it on this machine")
    return device


# ranker retrieval_dense._get_model, plus device=embed_device()
# In: a Hugging Face model name. Out: the loaded SentenceTransformer, cached per process.
def _get_model(model_name: str = BASE_MODEL_NAME) -> SentenceTransformer:
    """Returns the embedding model. It is loaded the first time only; later calls reuse it."""
    if model_name not in _model_cache:
        _model_cache[model_name] = SentenceTransformer(model_name, device=embed_device())
    return _model_cache[model_name]


# From ranker corpus.py: the same code.
# In: a provider record (the five text fields). Out: the fields in order, empty ones
# skipped, joined by single spaces.
def provider_text(p: dict) -> str:
    """Provider showcase -> one text blob (the text that gets embedded).

    `credentials` is deliberately excluded: in the ranker's synthetic data it's
    derived from the same background sentence as `about_description`, so
    including both would double-count identical tokens/semantics.
    """
    parts = [
        p.get("about_title"),
        p.get("about_description"),
        p.get("services_offered_title"),
        p.get("services_offered_description"),
        p.get("relevant_experience"),
    ]
    return " ".join(x for x in parts if x)


# From ranker corpus.py: the same code.
# In: a gig record (hire_title, hire_description, hire_description_additional_notes).
# Out: those fields joined like provider_text.
def hirer_text(h: dict) -> str:
    """Hirer gig posting -> one query text blob (this is what would get
    embedded live at /match time per CLAUDE.md)."""
    parts = [
        h.get("hire_title"),
        h.get("hire_description"),
        h.get("hire_description_additional_notes"),
    ]
    return " ".join(x for x in parts if x)


# From ranker retrieval_dense.py: the same code.
# In: an (n, d) array and a dimension (or None to keep all). Out: an (n, dim) array of
# unit-length rows, so a dot product is the cosine similarity; all-zero rows stay zeros.
def _truncate_and_normalize(vecs: np.ndarray, truncate_dim: int | None) -> np.ndarray:
    """Cuts each vector down to its first truncate_dim numbers (this model is trained so that
    shorter vectors still work), then scales it to length 1. With every vector at length 1,
    the similarity of two vectors is just their dot product. All-zero vectors are left as they
    are."""
    if truncate_dim is not None:
        vecs = vecs[:, :truncate_dim]
    norms = np.linalg.norm(vecs, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    return vecs / norms


# From ranker retrieval_dense.py: the same code.
# In: texts. Out: the raw (n, 1024) model output, not normalised.
def encode_docs(texts: list[str], model_name: str = BASE_MODEL_NAME) -> np.ndarray:
    """Batch-encode passage/document texts (no query instruction prefix)."""
    model = _get_model(model_name)
    return model.encode(texts, convert_to_numpy=True, show_progress_bar=False)


# From ranker retrieval_dense.py: the same code.
# In: query texts. Out: the raw (n, 1024) output, each text prefixed with
# QUERY_PREFIX_GENERIC first.
def encode_queries(
    texts: list[str], model_name: str = BASE_MODEL_NAME, query_prefix: str = QUERY_PREFIX_GENERIC,
) -> np.ndarray:
    """Batch-encode query texts (with the asymmetric instruction prefix)."""
    model = _get_model(model_name)
    return model.encode([query_prefix + t for t in texts], convert_to_numpy=True, show_progress_bar=False)


# What app.py serves. Each is encode_* then _truncate_and_normalize to EMBED_DIM:
# the steps the ranker's rank_from_raw takes before scoring.
def embed_provider(p: dict) -> list[float]:
    """One provider profile -> unit-length EMBED_DIM vector (cosine-ready).

    Args:
        p: A provider record with the five text fields (any may be missing or None).

    Returns:
        EMBED_DIM floats.
    """
    raw = encode_docs([provider_text(p)])
    return _truncate_and_normalize(raw, EMBED_DIM)[0].tolist()


def embed_providers(ps: list[dict]) -> list[list[float]]:
    """Many provider profiles in one encode call -> unit-length vectors, in order.

    Args:
        ps: Provider records, as for embed_provider.

    Returns:
        One list of EMBED_DIM floats per record, in input order.
    """
    raw = encode_docs([provider_text(p) for p in ps])
    return _truncate_and_normalize(raw, EMBED_DIM).tolist()


def embed_gigs(gs: list[dict]) -> list[list[float]]:
    """Many gigs (query side, with the instruction prefix) in one encode call ->
    unit-length vectors, in order.

    Args:
        gs: Gig records (hire_title, hire_description, hire_description_additional_notes).

    Returns:
        One list of EMBED_DIM floats per gig, in input order.
    """
    raw = encode_queries([hirer_text(g) for g in gs])
    return _truncate_and_normalize(raw, EMBED_DIM).tolist()
