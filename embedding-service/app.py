"""
HTTP wrapper around embedding.py, called by app/Libraries/ProviderEmbedder.php.

    GET  /health            -> {"status", "model", "dimensions"}
    POST /embed/provider    body: the five provider text fields (any may be null)
                            -> {"model", "dimensions", "embedding": [float x dimensions]}
    POST /embed/providers   body: {"providers": [up to MAX_BATCH of the above]}
                            -> {"model", "dimensions", "embeddings": [[float x dimensions], ...]}, in input order

Run (from embedding-service/):
    uvicorn app:app --port 8001
"""
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from embedding import BASE_MODEL_NAME, EMBED_DIM, _get_model, embed_provider, embed_providers, provider_text


@asynccontextmanager
async def lifespan(_app: FastAPI):
    _get_model()  # load once at startup so the first request doesn't pay for it
    yield


# Bounds one request's memory and latency; the PHP batch command pages below this.
MAX_BATCH = 128


app = FastAPI(title="SenseiGigs embedding service", lifespan=lifespan)


class Provider(BaseModel):
    about_title: str | None = None
    about_description: str | None = None
    services_offered_title: str | None = None
    services_offered_description: str | None = None
    relevant_experience: str | None = None


@app.get("/health")
def health():
    return {"status": "ok", "model": BASE_MODEL_NAME, "dimensions": EMBED_DIM}


# Plain `def` so FastAPI runs the CPU-bound encode in its threadpool instead of
# blocking the event loop.
@app.post("/embed/provider")
def embed_provider_route(provider: Provider):
    fields = provider.model_dump()
    if not provider_text(fields).strip():
        raise HTTPException(status_code=422, detail="All provider text fields are empty")
    return {"model": BASE_MODEL_NAME, "dimensions": EMBED_DIM, "embedding": embed_provider(fields)}


class ProviderBatch(BaseModel):
    providers: list[Provider]


@app.post("/embed/providers")
def embed_providers_route(batch: ProviderBatch):
    if not 1 <= len(batch.providers) <= MAX_BATCH:
        raise HTTPException(status_code=422, detail=f"Send between 1 and {MAX_BATCH} providers")
    fields = [p.model_dump() for p in batch.providers]
    empty = [i for i, f in enumerate(fields) if not provider_text(f).strip()]
    if empty:
        raise HTTPException(status_code=422, detail=f"All provider text fields are empty at index {empty}")
    return {"model": BASE_MODEL_NAME, "dimensions": EMBED_DIM, "embeddings": embed_providers(fields)}
