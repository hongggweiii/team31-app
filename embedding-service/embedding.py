"""
Provider profile -> embedding, copied from the ranker repo so the web app
produces exactly the vectors the ranker was built and evaluated on.

    provider_text                       ranker/pipeline/corpus.py
    BASE_MODEL_NAME, _get_model,
    _truncate_and_normalize, encode_docs ranker/pipeline/retrieval_dense.py

Keep those copies in sync with the ranker -- any change to the field list,
field order, model or normalisation changes every vector, and existing
vectors would need re-embedding.

Providers are the *documents* side of mxbai-embed-large-v1's asymmetric
setup, so they get no instruction prefix (only gig queries do).

EMBED_DIM is the full 1024-dim output -- what the shipped ranker's dense leg
used (features.py --dense-dim default). The model is Matryoshka-trained, so a
smaller value (512/256/...) also works, but it must match
app/Config/Embedding.php::$dimensions and the vector DB column.
"""
import numpy as np
from sentence_transformers import SentenceTransformer

BASE_MODEL_NAME = "mixedbread-ai/mxbai-embed-large-v1"
EMBED_DIM = 1024

_model_cache: dict[str, SentenceTransformer] = {}


def _get_model(model_name: str = BASE_MODEL_NAME) -> SentenceTransformer:
    if model_name not in _model_cache:
        _model_cache[model_name] = SentenceTransformer(model_name)
    return _model_cache[model_name]


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


def _truncate_and_normalize(vecs: np.ndarray, truncate_dim: int | None) -> np.ndarray:
    if truncate_dim is not None:
        vecs = vecs[:, :truncate_dim]
    norms = np.linalg.norm(vecs, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    return vecs / norms


def encode_docs(texts: list[str], model_name: str = BASE_MODEL_NAME) -> np.ndarray:
    """Batch-encode passage/document texts (no query instruction prefix)."""
    model = _get_model(model_name)
    return model.encode(texts, convert_to_numpy=True, show_progress_bar=False)


def embed_provider(p: dict) -> list[float]:
    """One provider profile -> unit-length EMBED_DIM vector (cosine-ready)."""
    raw = encode_docs([provider_text(p)])
    return _truncate_and_normalize(raw, EMBED_DIM)[0].tolist()
