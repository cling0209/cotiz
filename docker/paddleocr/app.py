"""API HTTP para extracción de tablas producto/cantidad desde PDF."""

from __future__ import annotations

import os
import tempfile
from pathlib import Path

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from table_extractor import extraer_lineas_pdf

app = FastAPI(title="Cotiz PaddleOCR", version="1.0.0")

DPI = int(os.environ.get("PADDLEOCR_DPI", "200"))
MAX_PAGES = int(os.environ.get("PADDLEOCR_MAX_PAGES", "15"))


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/extract-tabla")
async def extract_tabla(
    pdf: UploadFile = File(...),
    first_page: int | None = Form(default=None),
    last_page: int | None = Form(default=None),
) -> JSONResponse:
    if not pdf.filename or not pdf.filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Se requiere un archivo PDF.")

    contenido = await pdf.read()
    if not contenido:
        raise HTTPException(status_code=400, detail="PDF vacío.")

    page_start = max(1, int(first_page)) if first_page is not None else 1
    page_end = max(page_start, int(last_page)) if last_page is not None else MAX_PAGES

    tmp = tempfile.NamedTemporaryFile(suffix=".pdf", delete=False)
    tmp_path = tmp.name
    try:
        tmp.write(contenido)
        tmp.close()
        lineas = extraer_lineas_pdf(
            tmp_path,
            dpi=DPI,
            first_page=page_start,
            last_page=page_end,
        )
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"Error procesando PDF: {exc}") from exc
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    return JSONResponse({"lineas": lineas, "total": len(lineas), "first_page": page_start, "last_page": page_end})
