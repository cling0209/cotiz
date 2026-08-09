"""Extrae filas producto/cantidad de PDFs escaneados con tablas."""

from __future__ import annotations

import re
import tempfile
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

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
ETIQUETAS_PRODUCTO = ("PRODUCTO", "DESCRIPCION", "DESCRIPCIÓN", "DETALLE", "NOMBRE")
ETIQUETAS_CANTIDAD = ("CANTIDAD", "UNIDADES", "QTY", "CANT")


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


def _normalizar_celdas(celdas: list[str]) -> list[str]:
    return [re.sub(r"\s+", " ", c.strip()) for c in celdas if c and c.strip()]


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


def _es_celda_imagen(celda: str) -> bool:
    return re.match(r"^IMAGEN\b", celda, re.I) is not None


def _es_celda_cantidad(celda: str) -> bool:
    return _parse_cantidad(celda) is not None and len(celda.strip()) <= 16


def _es_celda_producto(celda: str) -> bool:
    if not celda or _es_ruido(celda) or _es_celda_imagen(celda):
        return False
    if _es_celda_cantidad(celda):
        return False
    return len(celda.strip()) >= 3


def _es_fila_cabecera_completa(row: list[str]) -> bool:
    upper = " ".join(row).upper()
    return "PRODUCTO" in upper and "CANTIDAD" in upper


def _es_fila_cabecera_parcial(row: list[str]) -> bool:
    if len(row) > 2:
        return False
    upper = " ".join(row).upper()
    return any(
        etiqueta in upper
        for etiqueta in (*ETIQUETAS_PRODUCTO, *ETIQUETAS_CANTIDAD, "IMAGEN", "REFERENCIA")
    )


def _indice_columna_por_etiquetas(row: list[str], etiquetas: tuple[str, ...]) -> int | None:
    for indice, celda in enumerate(row):
        upper = celda.upper().strip()
        for etiqueta in etiquetas:
            if etiqueta in upper or upper == etiqueta:
                return indice
    return None


def _mapear_columnas_desde_cabecera(header_row: list[str]) -> dict[str, int | None]:
    return {
        "producto": _indice_columna_por_etiquetas(header_row, ETIQUETAS_PRODUCTO),
        "cantidad": _indice_columna_por_etiquetas(header_row, ETIQUETAS_CANTIDAD),
    }


def _actualizar_mapeo_desde_fila_parcial(
    row: list[str],
    mapeo: dict[str, int | None],
) -> dict[str, int | None]:
    for indice, celda in enumerate(row):
        upper = celda.upper().strip()
        if mapeo["producto"] is None and any(e in upper for e in ETIQUETAS_PRODUCTO):
            mapeo["producto"] = indice
        if mapeo["cantidad"] is None and any(e in upper for e in ETIQUETAS_CANTIDAD):
            mapeo["cantidad"] = indice
    return mapeo


def _inferir_columnas_por_contenido(
    rows: list[list[str]],
    max_filas: int = 8,
) -> dict[str, int | None]:
    if not rows:
        return {"producto": None, "cantidad": None}

    num_cols = max(len(r) for r in rows)
    if num_cols < 2:
        return {"producto": None, "cantidad": None}

    scores_qty = [0] * num_cols
    scores_prod = [0] * num_cols

    for row in rows[:max_filas]:
        for indice in range(num_cols):
            celda = row[indice].strip() if indice < len(row) else ""
            if not celda or _es_celda_imagen(celda):
                continue
            if _es_celda_cantidad(celda):
                scores_qty[indice] += 1
            elif _es_celda_producto(celda):
                scores_prod[indice] += 1

    idx_cantidad: int | None = None
    idx_producto: int | None = None

    if max(scores_qty, default=0) > 0:
        idx_cantidad = scores_qty.index(max(scores_qty))

    candidatos_prod = [
        (indice, puntaje)
        for indice, puntaje in enumerate(scores_prod)
        if indice != idx_cantidad and puntaje > 0
    ]
    if candidatos_prod:
        idx_producto = max(candidatos_prod, key=lambda par: par[1])[0]

    return {"producto": idx_producto, "cantidad": idx_cantidad}


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
    celdas = _normalizar_celdas(celdas)
    if not celdas:
        return None

    if len(celdas) == 1:
        return _parse_texto_linea(celdas[0])

    cantidad: int | None = None
    partes_desc: list[str] = []

    for celda in celdas:
        if _es_celda_imagen(celda):
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


def _parse_fila_con_mapeo(row: list[str], mapeo: dict[str, int | None]) -> dict[str, Any] | None:
    celdas = _normalizar_celdas(row)
    if not celdas:
        return None

    idx_producto = mapeo.get("producto")
    idx_cantidad = mapeo.get("cantidad")

    if (
        idx_producto is not None
        and idx_cantidad is not None
        and idx_producto < len(celdas)
        and idx_cantidad < len(celdas)
        and idx_producto != idx_cantidad
    ):
        descripcion = celdas[idx_producto].strip()
        cantidad = _parse_cantidad(celdas[idx_cantidad])
        if (
            cantidad is not None
            and len(descripcion) >= 3
            and not _es_ruido(descripcion)
            and not _es_celda_imagen(descripcion)
        ):
            return {"cantidad": cantidad, "descripcion": descripcion}

    return _parse_celdas_fila(celdas)


def _filas_desde_html_tabla(html: str) -> list[dict[str, Any]]:
    parser = _TableHtmlParser()
    parser.feed(html)

    mapeo: dict[str, int | None] = {"producto": None, "cantidad": None}
    filas_datos: list[list[str]] = []

    for row in parser.rows:
        if _es_fila_cabecera_completa(row):
            mapeo = _mapear_columnas_desde_cabecera(row)
            continue

        if _es_fila_cabecera_parcial(row) and not _parse_celdas_fila(row):
            mapeo = _actualizar_mapeo_desde_fila_parcial(row, mapeo)
            continue

        filas_datos.append(row)

    if mapeo["producto"] is None or mapeo["cantidad"] is None:
        inferido = _inferir_columnas_por_contenido(filas_datos)
        if mapeo["producto"] is None:
            mapeo["producto"] = inferido["producto"]
        if mapeo["cantidad"] is None:
            mapeo["cantidad"] = inferido["cantidad"]

    filas: list[dict[str, Any]] = []
    for row in filas_datos:
        parsed = _parse_fila_con_mapeo(row, mapeo)
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
    for fila in filas:
        clave = re.sub(r"\s+", " ", fila["descripcion"].strip().lower())
        if not clave or clave in vistas:
            continue
        vistas.add(clave)
        out.append({"cantidad": int(fila["cantidad"]), "descripcion": fila["descripcion"].strip()})
    return out


_engine: Any | None = None


def get_ppstructure_engine() -> Any:
    """Inicializa PPStructure una sola vez (descarga modelos en runtime)."""
    global _engine
    if _engine is None:
        from paddleocr import PPStructure

        _engine = PPStructure(show_log=False, lang="en", layout=True, table=True, ocr=True)
    return _engine


def extraer_lineas_pdf(pdf_path: str, dpi: int = 200, max_pages: int = 15) -> list[dict[str, Any]]:
    """Convierte PDF a imágenes y extrae filas producto/cantidad."""
    from pdf2image import convert_from_path

    engine = get_ppstructure_engine()

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
