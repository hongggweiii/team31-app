"""
HTTP wrapper around embedding.py, called by app/Libraries/ProviderEmbedder.php.

    GET  /health            -> {"status", "model", "dimensions"}
    POST /embed/provider    body: the five provider text fields (any may be null)
                            -> {"model", "dimensions", "embedding": [float x dimensions]}

Run (from embedding-service/):
    uvicorn app:app --port 8001
"""
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from embedding import BASE_MODEL_NAME, EMBED_DIM, _get_model, embed_provider, provider_text


@asynccontextmanager
async def lifespan(_app: FastAPI):
    _get_model()  # load once at startup so the first request doesn't pay for it
    yield


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
