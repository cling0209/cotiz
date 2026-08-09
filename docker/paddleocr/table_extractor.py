"""Extrae filas producto/cantidad de PDFs escaneados con tablas."""

from __future__ import annotations

import re
import tempfile
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

from pdf2image import convert_from_path

CANTIDAD_RE = re.compile(
    r"^(?:(\d{1,5})\s*(?:unidades?|pack)\.?|\s*(\d{1,5}))$",
    re.IGNORECASE,
)
CANTIDAD_EN_TEXTO_FIN_RE = re.compile(
    r"^(.+?)\s+(\d{1,5})\s*(?:unidades?|pack)?\.?\s*$",
    re.IGNORECASE,
)
CANTIDAD_EN_TEXTO_INICIO_RE = re.compile(
    r"^(\d{1,5})\s+(.{3,})$",
    re.IGNORECASE,
)
RUido_RE = re.compile(
    r"^(PRODUCTO|CANTIDAD|IMAGEN|P[AÁ]GINA|ESPECIFICACIONES|SOLICITUD|REFERENCIA)",
    re.IGNORECASE,
)


class _TableHtmlParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.rows: list[list[str]] = []
        self._current_row: list[str] | None = None
        self._cell_parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag == "tr":
            self._current_row = []
        elif tag in ("td", "th"):
            self._cell_parts = []

    def handle_data(self, data: str) -> None:
        if self._cell_parts is not None:
            text = data.strip()
            if text:
                self._cell_parts.append(text)

    def handle_endtag(self, tag: str) -> None:
        if tag in ("td", "th") and self._current_row is not None:
            cell = " ".join(self._cell_parts).strip()
            self._current_row.append(cell)
            self._cell_parts = []
        elif tag == "tr" and self._current_row is not None:
            if any(c.strip() for c in self._current_row):
                self.rows.append(self._current_row)
            self._current_row = None


def _parse_cantidad(raw: str) -> int | None:
    raw = raw.strip()
    if not raw:
        return None
    m = CANTIDAD_RE.match(raw)
    if m:
        val = m.group(1) or m.group(2)
        return max(1, int(val))
    if raw.isdigit():
        return max(1, int(raw))
    return None


def _es_ruido(texto: str) -> bool:
    t = texto.strip()
    if not t or len(t) < 2:
        return True
    return RUido_RE.match(t) is not None


def _parse_texto_linea(linea: str) -> dict[str, Any] | None:
    linea = re.sub(r"\s+", " ", linea.strip())
    if _es_ruido(linea):
        return None

    m = CANTIDAD_EN_TEXTO_INICIO_RE.match(linea)
    if m and not re.match(r"^(unidades?|pack)", m.group(2), re.I):
        return {"cantidad": max(1, int(m.group(1))), "descripcion": m.group(2).strip()}

    m = CANTIDAD_EN_TEXTO_FIN_RE.match(linea)
    if m:
        desc = m.group(1).strip()
        if len(desc) >= 3:
            return {"cantidad": max(1, int(m.group(2))), "descripcion": desc}

    return None


def _parse_celdas_fila(celdas: list[str]) -> dict[str, Any] | None:
    celdas = [re.sub(r"\s+", " ", c.strip()) for c in celdas if c and c.strip()]
    if not celdas:
        return None

    if len(celdas) == 1:
        return _parse_texto_linea(celdas[0])

    cantidad: int | None = None
    partes_desc: list[str] = []

    for celda in celdas:
        if re.match(r"^IMAGEN\b", celda, re.I):
            continue
        qty = _parse_cantidad(celda)
        if qty is not None and cantidad is None and len(celda) <= 16:
            cantidad = qty
            continue
        if _es_ruido(celda):
            continue
        partes_desc.append(celda)

    if not partes_desc:
        return None

    descripcion = " ".join(partes_desc).strip()
    if len(descripcion) < 3:
        return None

    if cantidad is None:
        parsed = _parse_texto_linea(descripcion)
        if parsed:
            return parsed
        return None

    return {"cantidad": cantidad, "descripcion": descripcion}


def _filas_desde_html_tabla(html: str) -> list[dict[str, Any]]:
    parser = _TableHtmlParser()
    parser.feed(html)
    filas: list[dict[str, Any]] = []
    cabecera_vista = False

    for row in parser.rows:
        upper = " ".join(row).upper()
        if not cabecera_vista and "PRODUCTO" in upper and "CANTIDAD" in upper:
            cabecera_vista = True
            continue
        parsed = _parse_celdas_fila(row)
        if parsed:
            filas.append(parsed)

    return filas


def _filas_desde_ocr_lineas(texto: str) -> list[dict[str, Any]]:
    filas: list[dict[str, Any]] = []
    cantidad_pendiente: int | None = None
    buffer: str | None = None

    for linea_cruda in texto.splitlines():
        linea = re.sub(r"\s+", " ", linea_cruda.strip())
        if not linea or _es_ruido(linea):
            continue

        if linea.isdigit() and buffer is None:
            cantidad_pendiente = max(1, int(linea))
            continue

        parsed = _parse_texto_linea(linea)
        if parsed:
            if buffer:
                parsed["descripcion"] = f"{buffer} {parsed['descripcion']}".strip()
                buffer = None
            cantidad_pendiente = None
            filas.append(parsed)
            continue

        qty_sola = _parse_cantidad(linea)
        if qty_sola is not None and len(linea) <= 16 and buffer is None:
            cantidad_pendiente = qty_sola
            continue

        if cantidad_pendiente is not None and buffer is None:
            filas.append({"cantidad": cantidad_pendiente, "descripcion": linea})
            cantidad_pendiente = None
            continue

        if buffer is None:
            buffer = linea
        else:
            buffer = f"{buffer} {linea}"

        if cantidad_pendiente is not None and buffer and len(buffer) >= 5:
            filas.append({"cantidad": cantidad_pendiente, "descripcion": buffer})
            buffer = None
            cantidad_pendiente = None

    if buffer and cantidad_pendiente is not None:
        filas.append({"cantidad": cantidad_pendiente, "descripcion": buffer})

    return filas


def _deduplicar(filas: list[dict[str, Any]]) -> list[dict[str, Any]]:
    vistas: set[str] = set()
    out: list[dict[str, Any]] = []
    for f in filas:
        clave = re.sub(r"\s+", " ", f["descripcion"].strip().lower())
        if not clave or clave in vistas:
            continue
        vistas.add(clave)
        out.append({"cantidad": int(f["cantidad"]), "descripcion": f["descripcion"].strip()})
    return out


def extraer_lineas_pdf(pdf_path: str, dpi: int = 200, max_pages: int = 15) -> list[dict[str, Any]]:
    """Convierte PDF a imágenes y extrae filas producto/cantidad."""
    from paddleocr import PPStructure

    engine = PPStructure(show_log=False, lang="en", layout=True, table=True, ocr=True)

    images = convert_from_path(
        pdf_path,
        dpi=dpi,
        first_page=1,
        last_page=max_pages,
        fmt="png",
    )

    filas: list[dict[str, Any]] = []

    for image in images:
        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
            tmp_path = tmp.name
            image.save(tmp_path, format="PNG")

        try:
            resultados = engine(tmp_path)
            for bloque in resultados or []:
                if bloque.get("type") != "table":
                    continue
                res = bloque.get("res") or {}
                html = res.get("html") if isinstance(res, dict) else None
                if html:
                    filas.extend(_filas_desde_html_tabla(html))

            # Fallback: OCR de líneas en bloques de texto si la tabla no se detectó
            if not any(b.get("type") == "table" for b in (resultados or [])):
                textos = []
                for bloque in resultados or []:
                    if bloque.get("type") == "text":
                        res = bloque.get("res")
                        if isinstance(res, list):
                            for item in res:
                                if isinstance(item, dict) and item.get("text"):
                                    textos.append(str(item["text"]))
                        elif isinstance(res, str):
                            textos.append(res)
                if textos:
                    filas.extend(_filas_desde_ocr_lineas("\n".join(textos)))
        finally:
            Path(tmp_path).unlink(missing_ok=True)

    return _deduplicar(filas)
