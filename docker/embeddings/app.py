import os

# Limitar hilos ANTES de cargar ONNX/FastEmbed. En un CX33 compartido, usar todas
# las vCPU deja el host al 400% (sidecar + Postgres HNSW) y Hetzner recorta el fair-share.
_THREADS = max(1, min(8, int(os.environ.get("EMBEDDING_THREADS", "1"))))
for _key in (
    "OMP_NUM_THREADS",
    "MKL_NUM_THREADS",
    "OPENBLAS_NUM_THREADS",
    "NUMEXPR_NUM_THREADS",
    "ORT_INTRA_OP_NUM_THREADS",
    "ORT_INTER_OP_NUM_THREADS",
):
    os.environ[_key] = str(_THREADS)

from typing import Literal

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="Cotiz embeddings", version="1.0")

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "intfloat/multilingual-e5-small")
_model = None


def get_model():
    global _model
    if _model is None:
        from fastembed import TextEmbedding
        from fastembed.common.model_description import ModelSource, PoolingType

        if MODEL_NAME == "intfloat/multilingual-e5-small":
            try:
                TextEmbedding(model_name=MODEL_NAME, threads=_THREADS)
            except ValueError:
                TextEmbedding.add_custom_model(
                    model=MODEL_NAME,
                    pooling=PoolingType.MEAN,
                    normalization=True,
                    sources=ModelSource(hf=MODEL_NAME),
                    dim=384,
                    model_file="onnx/model.onnx",
                )

        _model = TextEmbedding(model_name=MODEL_NAME, threads=_THREADS)
    return _model


def prefix_text(text: str, task: str) -> str:
    t = (task or "document").lower()
    if t in ("query", "retrieval_query"):
        return f"query: {text}"
    return f"passage: {text}"


class EmbedRequest(BaseModel):
    text: str = Field(min_length=1, max_length=8000)
    task: Literal["document", "query", "retrieval_document", "retrieval_query"] = "document"


class EmbedBatchRequest(BaseModel):
    texts: list[str] = Field(min_length=1, max_length=64)
    task: Literal["document", "query", "retrieval_document", "retrieval_query"] = "document"


@app.get("/health")
def health():
    return {"ok": True, "model": MODEL_NAME, "threads": _THREADS}


@app.post("/embed")
def embed_one(body: EmbedRequest):
    text = body.text.strip()
    if not text:
        raise HTTPException(status_code=400, detail="text vacío")
    prefixed = prefix_text(text, body.task)
    model = get_model()
    vectors = list(model.embed([prefixed], parallel=None))
    if not vectors:
        raise HTTPException(status_code=500, detail="sin vector")
    vec = vectors[0]
    return {
        "model": MODEL_NAME,
        "dimension": len(vec),
        "values": [float(x) for x in vec],
    }


@app.post("/embed/batch")
def embed_batch(body: EmbedBatchRequest):
    cleaned = [t.strip() for t in body.texts if t and t.strip()]
    if not cleaned:
        raise HTTPException(status_code=400, detail="texts vacíos")
    prefixed = [prefix_text(t, body.task) for t in cleaned]
    model = get_model()
    vectors = list(model.embed(prefixed, parallel=None))
    out = []
    for vec in vectors:
        out.append([float(x) for x in vec])
    return {"model": MODEL_NAME, "dimension": len(out[0]) if out else 0, "vectors": out}


@app.on_event("startup")
def warmup():
    if os.environ.get("EMBEDDING_WARMUP", "true").lower() in ("1", "true", "yes"):
        try:
            get_model().embed(["passage: warmup"], parallel=None)
        except Exception as exc:
            import logging

            logging.warning("Warmup falló (se reintentará en /embed): %s", exc)
