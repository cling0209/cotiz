"""Extrae filas producto/cantidad de PDFs escaneados con tablas."""

from __future__ import annotations

import gc
import os
import re
import tempfile
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

# Recorte izquierdo: deja PRODUCTO + CANTIDAD y descarta IMAGEN REFERENCIA (ahorra mucha RAM).
CROP_LEFT_PERCENT = max(30, min(90, int(os.environ.get("PADDLEOCR_CROP_LEFT_PERCENT", "58"))))
MAX_IMAGE_WIDTH = max(800, min(2400, int(os.environ.get("PADDLEOCR_MAX_IMAGE_WIDTH", "1200"))))
# structure = PPStructure; paddle_ocr = PaddleOCR; tesseract/ocr/auto = Tesseract (cabe en ~2GB).
EXTRACT_MODE = (os.environ.get("PADDLEOCR_MODE", "auto") or "auto").strip().lower()
if EXTRACT_MODE in ("auto", "ocr", "light"):
    EXTRACT_MODE = "tesseract"

CANTIDAD_RE = re.compile(
    r"^(\d{1,5})\s*(?:unidades?|unidade|packs?|pack|paquetes?|cajas?|sobres?|sets?|ovillos?|bolsas?|pliegos?|rollos?)[:.]?\s*$",
    re.IGNORECASE,
)
CANTIDAD_INICIO_RE = re.compile(
    r"^(\d{1,5})\s*(?:unidades?|unidade|packs?|pack|paquetes?|cajas?|sobres?|sets?|ovillos?|bolsas?|pliegos?|rollos?)?\b",
    re.IGNORECASE,
)
CANTIDAD_PACK_DE_UNIDADES_RE = re.compile(
    r"^(\d{1,5})\s+pack\s+de\s+\d+\s+unidades?\s*$",
    re.IGNORECASE,
)
CANTIDAD_RUIDO_IMAGEN_RE = re.compile(
    r"^[a-záéíóú]{1,2}\.?\s*\d{1,5}\s*$",
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
ETIQUETAS_PRODUCTO = ("PRODUCTO", "DESCRIPCION", "DESCRIPCIÓN", "DETALLE", "NOMBRE", "REQUERIMIENTO", "BIEN O SERVICIO", "SERVICIO")
ETIQUETAS_CANTIDAD = ("CANTIDAD", "UNIDADES", "QTY", "CANT")
ETIQUETAS_UNIDAD_MEDIDA = ("UNIDAD DE MEDIDA", "UNIDAD MEDIDA", "U.M.", "UM")
ETIQUETAS_LINEA = ("LINEA", "LÍNEA", "LINEA N", "N°")
ETIQUETAS_MONTO = ("MONTO", "TOTAL", "PRECIO")


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
        elif tag == "br" and self._cell_parts is not None:
            self._cell_parts.append("\n")

    def handle_data(self, data: str) -> None:
        if self._cell_parts is not None:
            text = data.strip()
            if text:
                self._cell_parts.append(text)

    def handle_endtag(self, tag: str) -> None:
        if tag in ("td", "th") and self._current_row is not None:
            cell = " ".join(self._cell_parts).strip()
            cell = re.sub(r"\s*\n\s*", " ", cell)
            cell = re.sub(r"\s+", " ", cell)
            self._current_row.append(cell)
            self._cell_parts = []
        elif tag == "tr" and self._current_row is not None:
            if any(c.strip() for c in self._current_row):
                self.rows.append(self._current_row)
            self._current_row = None


def _normalizar_celdas_grilla(celdas: list[str]) -> list[str]:
    """Conserva celdas vacías para mantener índices de columnas."""
    return [re.sub(r"\s+", " ", (c or "").strip()) for c in celdas]


def _normalizar_celdas(celdas: list[str]) -> list[str]:
    return [re.sub(r"\s+", " ", c.strip()) for c in celdas if c and c.strip()]


def _parse_cantidad(raw: str) -> int | None:
    raw = raw.strip()
    if not raw or _CANTIDAD_RUIDO_IMAGEN(raw):
        return None
    m = CANTIDAD_PACK_DE_UNIDADES_RE.match(raw)
    if m:
        return max(1, int(m.group(1)))
    m = CANTIDAD_RE.match(raw)
    if m:
        return max(1, int(m.group(1)))
    if raw.isdigit():
        return max(1, int(raw))
    return None


def _CANTIDAD_RUIDO_IMAGEN(raw: str) -> bool:
    """Ruido típico de la columna imagen (ej. 'e. 3', 'Ne'), no cantidad pedido."""
    return CANTIDAD_RUIDO_IMAGEN_RE.match(raw.strip()) is not None


def _es_ruido(texto: str) -> bool:
    t = texto.strip()
    if not t or len(t) < 2:
        return True
    return RUido_RE.match(t) is not None


def _es_celda_imagen(celda: str) -> bool:
    return re.match(r"^IMAGEN\b", celda, re.I) is not None


def _es_celda_cantidad(celda: str) -> bool:
    return _parse_cantidad(celda) is not None and len(celda.strip()) <= 40


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
        "especificaciones": _indice_columna_por_etiquetas(
            header_row,
            ("ESPECIFICACIONES", "ESPECIFICACIONES TÉCNICAS", "ESPECIFICACIONES TECNICAS"),
        ),
        "unidad_medida": _indice_columna_por_etiquetas(header_row, ETIQUETAS_UNIDAD_MEDIDA),
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


def _es_fila_cabecera_bases(row: list[str]) -> bool:
    upper = " ".join(row).upper()
    tiene_desc = any(e in upper for e in ("DESCRIPCION", "DESCRIPCIÓN", "REQUERIMIENTO"))
    tiene_unidades = "UNIDADES" in upper
    tiene_linea = any(e in upper for e in ETIQUETAS_LINEA)
    tiene_monto = "MONTO" in upper or "TOTAL" in upper
    return tiene_desc and tiene_unidades and (tiene_linea or tiene_monto)


def _mapear_columnas_bases(header_row: list[str]) -> dict[str, int | None]:
    producto = _indice_columna_por_etiquetas(header_row, ETIQUETAS_PRODUCTO)
    cantidad = _indice_columna_por_etiquetas(header_row, ETIQUETAS_CANTIDAD)
    linea = _indice_columna_por_etiquetas(header_row, ETIQUETAS_LINEA)
    monto = _indice_columna_por_etiquetas(header_row, ETIQUETAS_MONTO)
    if producto is None:
        for indice, celda in enumerate(header_row):
            upper = celda.upper()
            if "DESCRIPCION" in upper or "REQUERIMIENTO" in upper:
                producto = indice
                break
    return {"producto": producto, "cantidad": cantidad, "linea": linea, "monto": monto}


def _es_celda_monto_bases(celda: str) -> bool:
    celda = celda.strip()
    return re.match(r"^\d{1,3}(?:\.\d{3})+$", celda) is not None


def _inferir_columnas_bases(rows: list[list[str]], max_filas: int = 12) -> dict[str, int | None]:
    if not rows:
        return {"producto": None, "cantidad": None}

    num_cols = max(len(r) for r in rows)
    if num_cols < 3:
        return _inferir_columnas_por_contenido(rows, max_filas)

    scores_linea = [0] * num_cols
    scores_monto = [0] * num_cols
    scores_qty = [0] * num_cols
    scores_prod = [0] * num_cols

    for row in rows[:max_filas]:
        for indice in range(num_cols):
            celda = row[indice].strip() if indice < len(row) else ""
            if not celda or _es_celda_imagen(celda):
                continue
            if re.match(r"^\d{1,3}$", celda):
                scores_linea[indice] += 1
            elif _es_celda_monto_bases(celda):
                scores_monto[indice] += 1
            elif _parse_cantidad(celda) is not None and len(celda) <= 8:
                scores_qty[indice] += 1
            elif _es_celda_producto(celda):
                scores_prod[indice] += 1

    idx_linea = scores_linea.index(max(scores_linea)) if max(scores_linea, default=0) > 0 else None
    idx_monto = scores_monto.index(max(scores_monto)) if max(scores_monto, default=0) > 0 else None

    excluir = {i for i in (idx_linea, idx_monto) if i is not None}

    idx_cantidad: int | None = None
    candidatos_qty = [(i, s) for i, s in enumerate(scores_qty) if i not in excluir and s > 0]
    if candidatos_qty:
        idx_cantidad = max(candidatos_qty, key=lambda par: par[1])[0]

    idx_producto: int | None = None
    candidatos_prod = [
        (i, s)
        for i, s in enumerate(scores_prod)
        if i not in excluir and i != idx_cantidad and s > 0
    ]
    if candidatos_prod:
        idx_producto = max(candidatos_prod, key=lambda par: par[1])[0]

    if idx_producto is None or idx_cantidad is None:
        inferido = _inferir_columnas_por_contenido(rows, max_filas)
        if idx_producto is None:
            idx_producto = inferido["producto"]
        if idx_cantidad is None:
            idx_cantidad = inferido["cantidad"]

    return {"producto": idx_producto, "cantidad": idx_cantidad}


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
        if re.match(r"^(?:Unidad(?:es)?|Caja|Display)$", celda.strip(), re.I):
            continue
        qty = _parse_cantidad(celda)
        if qty is not None and cantidad is None and len(celda.strip()) <= 40:
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
        idx_specs = mapeo.get("especificaciones")
        if (
            idx_specs is not None
            and idx_specs < len(celdas)
            and idx_specs != idx_producto
            and idx_specs != idx_cantidad
        ):
            specs = celdas[idx_specs].strip()
            if specs and specs not in descripcion:
                descripcion = f"{descripcion} {specs}".strip()
        cantidad = _parse_cantidad(celdas[idx_cantidad])
        if (
            cantidad is not None
            and len(descripcion) >= 3
            and not _es_ruido(descripcion)
            and not _es_celda_imagen(descripcion)
        ):
            return {"cantidad": cantidad, "descripcion": descripcion}

        # Con columnas mapeadas: no inferir cantidad desde el texto del producto.
        if len(descripcion) >= 3 and not _es_ruido(descripcion):
            return None

    return _parse_celdas_fila(celdas)


def _fusionar_filas_tabla_html_partidas(
    rows: list[list[str]],
    mapeo: dict[str, int | None],
) -> list[list[str]]:
    """Une filas HTML donde el producto multilínea quedó partido sin cantidad en la primera."""
    idx_producto = mapeo.get("producto")
    idx_cantidad = mapeo.get("cantidad")
    if (
        idx_producto is None
        or idx_cantidad is None
        or idx_producto == idx_cantidad
        or not rows
    ):
        return rows

    merged: list[list[str]] = []
    buffer: list[str] | None = None

    for row in rows:
        celdas = _normalizar_celdas(row)
        max_idx = max(idx_producto, idx_cantidad)
        while len(celdas) <= max_idx:
            celdas.append("")

        prod = celdas[idx_producto].strip()
        qty_cell = celdas[idx_cantidad].strip()
        qty = _parse_cantidad(qty_cell)

        if qty is None and prod and not _es_ruido(prod) and not _es_celda_imagen(prod):
            if buffer is None:
                buffer = celdas.copy()
            else:
                buffer[idx_producto] = f"{buffer[idx_producto]} {prod}".strip()
            continue

        if qty is not None and buffer is not None:
            if prod:
                buffer[idx_producto] = f"{buffer[idx_producto]} {prod}".strip()
            buffer[idx_cantidad] = qty_cell
            merged.append(buffer)
            buffer = None
            continue

        if buffer is not None:
            merged.append(buffer)
            buffer = None

        merged.append(celdas)

    if buffer is not None:
        merged.append(buffer)

    return merged


def _fusionar_continuaciones_posteriores(
    rows: list[list[str]],
    mapeo: dict[str, int | None],
) -> list[list[str]]:
    """Une filas siguientes sin cantidad cuando el producto multilínea quedó partido tras una fila con qty."""
    idx_producto = mapeo.get("producto")
    idx_cantidad = mapeo.get("cantidad")
    if (
        idx_producto is None
        or idx_cantidad is None
        or idx_producto == idx_cantidad
        or not rows
    ):
        return rows

    max_idx = max(idx_producto, idx_cantidad)
    merged: list[list[str]] = []
    i = 0
    while i < len(rows):
        row = rows[i]
        celdas = _normalizar_celdas(row)
        while len(celdas) <= max_idx:
            celdas.append("")

        if _parse_cantidad(celdas[idx_cantidad]) is not None:
            j = i + 1
            while j < len(rows):
                nxt = _normalizar_celdas(rows[j])
                while len(nxt) <= max_idx:
                    nxt.append("")
                prod = nxt[idx_producto].strip()
                qty = _parse_cantidad(nxt[idx_cantidad])
                if (
                    qty is None
                    and prod
                    and not _es_ruido(prod)
                    and not _es_celda_imagen(prod)
                ):
                    celdas[idx_producto] = f"{celdas[idx_producto]} {prod}".strip()
                    j += 1
                else:
                    break
            merged.append(celdas)
            i = j
        else:
            merged.append(celdas)
            i += 1

    return merged


def _filas_desde_html_tabla(html: str) -> list[dict[str, Any]]:
    parser = _TableHtmlParser()
    parser.feed(html)

    mapeo: dict[str, int | None] = {"producto": None, "cantidad": None}
    es_bases = False
    filas_datos: list[list[str]] = []

    for row in parser.rows:
        if _es_fila_cabecera_completa(row):
            mapeo = _mapear_columnas_desde_cabecera(row)
            es_bases = False
            continue

        if _es_fila_cabecera_bases(row):
            bases_map = _mapear_columnas_bases(row)
            mapeo = {
                "producto": bases_map.get("producto"),
                "cantidad": bases_map.get("cantidad"),
            }
            es_bases = True
            continue

        if _es_fila_cabecera_parcial(row) and not _parse_celdas_fila(row):
            mapeo = _actualizar_mapeo_desde_fila_parcial(row, mapeo)
            upper = " ".join(row).upper()
            if "UNIDADES" in upper and ("DESCRIPCION" in upper or "REQUERIMIENTO" in upper):
                es_bases = True
            continue

        filas_datos.append(row)

    if mapeo["producto"] is None or mapeo["cantidad"] is None:
        inferido = _inferir_columnas_bases(filas_datos) if es_bases else _inferir_columnas_por_contenido(filas_datos)
        if mapeo["producto"] is None:
            mapeo["producto"] = inferido["producto"]
        if mapeo["cantidad"] is None:
            mapeo["cantidad"] = inferido["cantidad"]

    filas_datos = _fusionar_continuaciones_posteriores(filas_datos, mapeo)
    filas_datos = _fusionar_filas_tabla_html_partidas(filas_datos, mapeo)

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


def _filtrar_filas_cabecera(filas: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for fila in filas:
        desc = fila["descripcion"].strip().upper()
        if re.match(
            r"^(?:PRODUCTO|CANTIDAD|IMAGEN(?:\s+REFERENCIA)?|PRODUCTO\s+CANTIDAD(?:\s+IMAGEN)?(?:\s+REFERENCIA)?|CANTIDAD\s+IMAGEN(?:\s+REFERENCIA)?|LINEA(?:\s+DESCRIPCION)?(?:\s+REQUERIMIENTO)?|DESCRIPCION(?:\s+REQUERIMIENTO)?|UNIDADES(?:\s+POR\s+AÑO)?|MONTO(?:\s+TOTAL)?)$",
            desc,
        ):
            continue
        out.append(fila)
    return out


def _deduplicar(filas: list[dict[str, Any]]) -> list[dict[str, Any]]:
    normalizadas: list[tuple[str, dict[str, Any]]] = []
    for fila in filas:
        clave = re.sub(r"[^\w\s]", "", re.sub(r"\s+", " ", fila["descripcion"].strip().lower()))
        clave = clave.strip()
        if not clave:
            continue
        normalizadas.append((clave, fila))

    out: list[dict[str, Any]] = []
    out_claves: list[str] = []
    for clave, fila in normalizadas:
        duplicada = False
        for ex_clave in out_claves:
            if clave == ex_clave:
                duplicada = True
                break
            min_len = min(len(clave), len(ex_clave))
            max_len = max(len(clave), len(ex_clave))
            if min_len >= 5 and max_len > 0:
                if (clave in ex_clave or ex_clave in clave) and (min_len / max_len) >= 0.55:
                    duplicada = True
                    break
        if duplicada:
            continue
        out_claves.append(clave)
        out.append(
            {"cantidad": int(fila["cantidad"]), "descripcion": fila["descripcion"].strip()}
        )

    return out


_engine: Any | None = None
_ocr_engine: Any | None = None


def get_ppstructure_engine() -> Any:
    """Inicializa PPStructure una sola vez (descarga modelos en runtime)."""
    global _engine
    if _engine is None:
        from paddleocr import PPStructure

        # layout=False reduce RAM; la tabla PRODUCTO|CANTIDAD|IMAGEN no lo necesita.
        _engine = PPStructure(
            show_log=False,
            lang="en",
            layout=False,
            table=True,
            ocr=True,
        )
    return _engine


def get_paddle_ocr_engine() -> Any:
    """OCR de líneas (más liviano que PPStructure; útil con Docker ~2GB)."""
    global _ocr_engine
    if _ocr_engine is None:
        from paddleocr import PaddleOCR

        _ocr_engine = PaddleOCR(
            use_angle_cls=False,
            lang="en",
            show_log=False,
            use_gpu=False,
        )
    return _ocr_engine


def _preparar_imagen_para_ocr(image: Any) -> Any:
    """Recorta columna de fotos, escala y pasa a RGB para bajar memoria del motor."""
    width, height = image.size
    crop_ratio = CROP_LEFT_PERCENT / 100.0
    cropped = image.crop((0, 0, max(1, int(width * crop_ratio)), height))
    if cropped.mode != "RGB":
        cropped = cropped.convert("RGB")
    if cropped.width > MAX_IMAGE_WIDTH:
        new_h = max(1, int(cropped.height * (MAX_IMAGE_WIDTH / cropped.width)))
        cropped = cropped.resize((MAX_IMAGE_WIDTH, new_h))
    return cropped


def _texto_desde_paddle_ocr(image_path: str) -> str:
    engine = get_paddle_ocr_engine()
    result = engine.ocr(image_path, cls=False)
    lineas: list[str] = []
    if not result:
        return ""
    for page in result:
        if not page:
            continue
        for item in page:
            if not item or len(item) < 2:
                continue
            payload = item[1]
            text = payload[0] if isinstance(payload, (list, tuple)) else str(payload)
            text = (text or "").strip()
            if text:
                lineas.append(text)
    return "\n".join(lineas)


def _texto_desde_tesseract(image_path: str, psm: int = 4) -> str:
    import subprocess

    out_base = image_path + ".tess"
    # psm 4 = columna de texto de altura variable (mejor para tablas PRODUCTO|CANTIDAD).
    cmd = [
        "tesseract",
        image_path,
        out_base,
        "-l",
        "spa+eng",
        "--oem",
        "1",
        "--psm",
        str(psm),
    ]
    env = os.environ.copy()
    env["OMP_THREAD_LIMIT"] = "1"
    subprocess.run(cmd, check=True, capture_output=True, timeout=180, env=env)
    txt_path = out_base + ".txt"
    try:
        return Path(txt_path).read_text(encoding="utf-8", errors="ignore").strip()
    finally:
        Path(txt_path).unlink(missing_ok=True)


def _texto_ocr_imagen(image_path: str) -> str:
    if EXTRACT_MODE == "paddle_ocr":
        return _texto_desde_paddle_ocr(image_path)
    return _texto_desde_tesseract(image_path)


def _tsv_desde_tesseract(image_path: str) -> str:
    import subprocess

    out_base = image_path + ".tsv"
    cmd = [
        "tesseract",
        image_path,
        out_base,
        "-l",
        "spa+eng",
        "--oem",
        "1",
        "--psm",
        "6",
        "tsv",
    ]
    env = os.environ.copy()
    env["OMP_THREAD_LIMIT"] = "1"
    subprocess.run(cmd, check=True, capture_output=True, timeout=180, env=env)
    tsv_path = out_base + ".tsv"
    try:
        return Path(tsv_path).read_text(encoding="utf-8", errors="ignore")
    finally:
        Path(tsv_path).unlink(missing_ok=True)


def _filas_desde_tsv_columnas(tsv: str, image_width: int) -> list[dict[str, Any]]:
    """Agrupa tokens Tesseract por fila (Y) y columna (X): producto | cantidad."""
    # Recorte = ~62% página (sin IMAGEN). CANTIDAD empieza ~48/62 ≈ 0.78 del recorte.
    split_x = int(image_width * 0.78)
    tokens: list[dict[str, Any]] = []
    for i, line in enumerate(tsv.splitlines()):
        if i == 0 or not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) < 12:
            continue
        try:
            left = int(parts[6])
            top = int(parts[7])
            width = int(parts[8])
            conf = float(parts[10])
        except ValueError:
            continue
        text = (parts[11] or "").strip()
        if not text or conf < 0:
            continue
        tokens.append(
            {
                "text": text,
                "left": left,
                "top": top,
                "cx": left + width / 2.0,
                "cy": top + int(parts[9]) / 2.0 if parts[9].isdigit() else top,
            }
        )

    if not tokens:
        return []

    tokens.sort(key=lambda t: (t["top"], t["left"]))
    # Agrupar en bandas horizontales (filas de tabla).
    row_tol = max(18, int(image_width * 0.02))
    bands: list[list[dict[str, Any]]] = []
    for tok in tokens:
        if not bands:
            bands.append([tok])
            continue
        prev = bands[-1]
        prev_cy = sum(t["cy"] for t in prev) / len(prev)
        if abs(tok["cy"] - prev_cy) <= row_tol:
            prev.append(tok)
        else:
            bands.append([tok])

    filas: list[dict[str, Any]] = []
    buffer_prod: str | None = None
    for band in bands:
        prod_parts = [t["text"] for t in band if t["cx"] < split_x]
        cant_parts = [t["text"] for t in band if t["cx"] >= split_x]
        prod = re.sub(r"\s+", " ", " ".join(prod_parts)).strip()
        cant_raw = re.sub(r"\s+", " ", " ".join(cant_parts)).strip()

        if re.search(r"\bPRODUCTO\b|\bCANTIDAD\b|\bIMAGEN\b", f"{prod} {cant_raw}", re.I):
            continue

        qty = _parse_cantidad(cant_raw) if cant_raw else None
        if qty is None and cant_raw:
            m = re.search(r"(\d{1,5})", cant_raw)
            if m and len(cant_raw) <= 20:
                qty = max(1, int(m.group(1)))

        if qty is not None:
            desc = prod
            if buffer_prod:
                desc = f"{buffer_prod} {desc}".strip()
                buffer_prod = None
            if len(desc) >= 3 and not _es_ruido(desc):
                filas.append({"cantidad": qty, "descripcion": desc})
            continue

        if prod and not _es_ruido(prod):
            buffer_prod = f"{buffer_prod} {prod}".strip() if buffer_prod else prod

    return filas


def _tokens_desde_tsv(tsv: str) -> list[dict[str, Any]]:
    """Tokens OCR con posición (left, top, cx, cy)."""
    tokens: list[dict[str, Any]] = []
    for i, line in enumerate(tsv.splitlines()):
        if i == 0 or not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) < 12:
            continue
        try:
            left = int(parts[6])
            top = int(parts[7])
            width = int(parts[8])
            height = int(parts[9]) if parts[9].isdigit() else 20
            conf = float(parts[10])
        except ValueError:
            continue
        text = (parts[11] or "").strip()
        if not text or conf < 0:
            continue
        tokens.append(
            {
                "text": text,
                "left": left,
                "top": top,
                "cx": left + width / 2.0,
                "cy": top + height / 2.0,
            }
        )
    return tokens


def _bandas_horizontales(tokens: list[dict[str, Any]], row_tol: float) -> list[list[dict[str, Any]]]:
    tokens = sorted(tokens, key=lambda t: (t["top"], t["left"]))
    bands: list[list[dict[str, Any]]] = []
    for tok in tokens:
        if not bands:
            bands.append([tok])
            continue
        prev = bands[-1]
        prev_cy = sum(t["cy"] for t in prev) / len(prev)
        if abs(tok["cy"] - prev_cy) <= row_tol:
            prev.append(tok)
        else:
            bands.append([tok])
    return bands


def _texto_fila_desde_banda(banda: list[dict[str, Any]]) -> str:
    return re.sub(
        r"\s+",
        " ",
        " ".join(t["text"] for t in sorted(banda, key=lambda t: t["left"])),
    ).strip()


def _detectar_split_x_desde_cabecera(bands: list[list[dict[str, Any]]], image_width: int) -> int | None:
    """Busca fila con PRODUCTO y CANTIDAD y estima límite X entre columnas."""
    for band in bands[:12]:
        texto = _texto_fila_desde_banda(band).upper()
        if "PRODUCTO" not in texto or "CANTIDAD" not in texto:
            continue
        prod_x: list[float] = []
        cant_x: list[float] = []
        for tok in band:
            upper = tok["text"].upper()
            if "PRODUCTO" in upper or upper == "PRODUCTO":
                prod_x.append(tok["cx"])
            if "CANTIDAD" in upper or upper == "CANTIDAD":
                cant_x.append(tok["cx"])
        if prod_x and cant_x:
            return int((max(prod_x) + min(cant_x)) / 2.0)
        xs = sorted(tok["cx"] for tok in band)
        if len(xs) >= 2:
            gaps = [(xs[i + 1] - xs[i], i) for i in range(len(xs) - 1)]
            gap, idx = max(gaps, key=lambda par: par[0])
            if gap >= image_width * 0.08:
                return int((xs[idx] + xs[idx + 1]) / 2.0)
    return None


def _grilla_desde_tesseract_tsv(image: Any) -> list[list[str]]:
    """Grilla de celdas por posición X/Y (estándar para cualquier tabla PRODUCTO|CANTIDAD)."""
    width, height = image.size
    prepared = _preparar_imagen_para_ocr(image)
    if prepared.mode != "RGB":
        prepared = prepared.convert("RGB")

    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        tmp_path = tmp.name
        prepared.save(tmp_path, format="PNG", optimize=True)

    try:
        tsv = _tsv_desde_tesseract(tmp_path)
    finally:
        Path(tmp_path).unlink(missing_ok=True)

    tokens = _tokens_desde_tsv(tsv)
    if not tokens:
        return []

    row_tol = max(14, int(height * 0.012))
    bands = _bandas_horizontales(tokens, row_tol)
    split_x = _detectar_split_x_desde_cabecera(bands, width)
    if split_x is None:
        split_x = int(width * 0.78)

    filas: list[list[str]] = []
    for band in bands:
        prod_parts = [t["text"] for t in sorted(band, key=lambda t: t["left"]) if t["cx"] < split_x]
        cant_parts = [t["text"] for t in sorted(band, key=lambda t: t["left"]) if t["cx"] >= split_x]
        prod = re.sub(r"\s+", " ", " ".join(prod_parts)).strip()
        cant = re.sub(r"\s+", " ", " ".join(cant_parts)).strip()
        if not prod and not cant:
            continue
        if re.search(r"\bPRODUCTO\b|\bCANTIDAD\b|\bIMAGEN\b", f"{prod} {cant}", re.I):
            filas.append([prod or "PRODUCTO", cant or "CANTIDAD"])
            continue
        filas.append([prod, cant])

    return [f for f in filas if any(c.strip() for c in f)]


def _lineas_con_y_desde_tsv(tsv: str) -> list[tuple[float, str]]:
    """Devuelve [(cy, texto)] por línea Tesseract."""
    rows: dict[tuple[int, int, int], dict[str, Any]] = {}
    for i, line in enumerate(tsv.splitlines()):
        if i == 0 or not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) < 12:
            continue
        try:
            left = int(parts[6])
            top = int(parts[7])
            height = int(parts[9]) if parts[9].isdigit() else 20
            conf = float(parts[10])
            key = (int(parts[2]), int(parts[3]), int(parts[4]))
        except ValueError:
            continue
        text = (parts[11] or "").strip()
        if not text or conf < 0:
            continue
        bucket = rows.setdefault(key, {"words": [], "tops": [], "heights": []})
        bucket["words"].append((left, text))
        bucket["tops"].append(top)
        bucket["heights"].append(height)

    out: list[tuple[float, str]] = []
    for bucket in rows.values():
        words = sorted(bucket["words"], key=lambda w: w[0])
        texto = re.sub(r"\s+", " ", " ".join(w[1] for w in words)).strip()
        if not texto:
            continue
        cy = (sum(bucket["tops"]) / len(bucket["tops"])) + (
            sum(bucket["heights"]) / len(bucket["heights"]) / 2.0
        )
        out.append((cy, texto))
    out.sort(key=lambda item: item[0])
    return out


def _filas_desde_columnas_tesseract(image: Any) -> list[dict[str, Any]]:
    """OCR en franjas separadas PRODUCTO / CANTIDAD y empareje por Y."""
    width, height = image.size
    top = int(height * 0.10)
    # Medido en ESPECIFICACIONES TECNICAS2 (solicitud 83965): CANTIDAD ~38–50%.
    prod_right = int(width * 0.38)
    cant_left = int(width * 0.38)
    cant_right = int(width * 0.50)

    prod_img = image.crop((0, top, max(1, prod_right), height))
    cant_img = image.crop((max(0, cant_left), top, max(cant_left + 1, cant_right), height))
    if prod_img.mode != "RGB":
        prod_img = prod_img.convert("RGB")
    if cant_img.mode != "RGB":
        cant_img = cant_img.convert("RGB")

    with tempfile.TemporaryDirectory(prefix="cotiz-tess-dual-") as tmpdir:
        prod_path = str(Path(tmpdir) / "prod.png")
        cant_path = str(Path(tmpdir) / "cant.png")
        prod_img.save(prod_path, format="PNG", optimize=True)
        cant_img.save(cant_path, format="PNG", optimize=True)
        prod_lines = _lineas_con_y_desde_tsv(_tsv_desde_tesseract(prod_path))
        cant_lines = _lineas_con_y_desde_tsv(_tsv_desde_tesseract(cant_path))

    cantidades: list[tuple[float, int]] = []
    skip_continuacion = False
    for cy, texto in cant_lines:
        if re.search(r"\bCANTIDAD\b", texto, re.I):
            continue
        # Continuación de celda multilínea ("5 paquetes de" + "cada espesor").
        if skip_continuacion and not re.match(r"^\d", texto.strip()):
            skip_continuacion = False
            continue
        skip_continuacion = False
        qty = _parse_cantidad(texto)
        if qty is None:
            m = re.fullmatch(r"(\d{1,5})", texto.strip())
            if m:
                qty = max(1, int(m.group(1)))
        if qty is None:
            m = CANTIDAD_INICIO_RE.match(texto.strip())
            if m and len(texto.strip()) <= 40:
                qty = max(1, int(m.group(1)))
                # Si la cantidad continúa en la siguiente línea, saltarla.
                if re.search(r"\bde\b|\bcada\b", texto, re.I):
                    skip_continuacion = True
        if qty is not None:
            cantidades.append((cy, qty))

    productos: list[tuple[float, str]] = []
    for cy, texto in prod_lines:
        if re.search(r"\bPRODUCTO\b", texto, re.I) or _es_ruido(texto):
            continue
        productos.append((cy, texto))

    if not cantidades:
        return _filas_desde_ocr_lineas("\n".join(t for _, t in productos))

    filas: list[dict[str, Any]] = []
    # Para cada cantidad, juntar líneas de producto cuya Y cae en la banda de la fila.
    prod_used = [False] * len(productos)
    for idx, (cy_q, qty) in enumerate(cantidades):
        prev_y = cantidades[idx - 1][0] if idx > 0 else cy_q - 80
        next_y = cantidades[idx + 1][0] if idx + 1 < len(cantidades) else cy_q + 80
        y_min = (prev_y + cy_q) / 2.0
        y_max = (cy_q + next_y) / 2.0
        parts: list[str] = []
        for p_i, (cy_p, texto) in enumerate(productos):
            if prod_used[p_i]:
                continue
            if y_min <= cy_p <= y_max or abs(cy_p - cy_q) <= 35:
                parts.append(texto)
                prod_used[p_i] = True
        desc = re.sub(r"\s+", " ", " ".join(parts)).strip()
        if len(desc) >= 3:
            filas.append({"cantidad": qty, "descripcion": desc})

    return filas


def _extraer_filas_de_imagen(image: Any, pagina_num: int) -> list[dict[str, Any]]:
    """Extrae filas de una página ya renderizada (imagen PIL)."""
    filas: list[dict[str, Any]] = []

    if EXTRACT_MODE in ("tesseract", "auto", "ocr", "light"):
        for fila in _filtrar_filas_cabecera(_filas_desde_columnas_tesseract(image)):
            fila["pagina"] = pagina_num
            filas.append(fila)
        if filas:
            return filas

    prepared = _preparar_imagen_para_ocr(image)

    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        tmp_path = tmp.name
        prepared.save(tmp_path, format="PNG", optimize=True)

    try:
        if EXTRACT_MODE == "structure":
            engine = get_ppstructure_engine()
            resultados = engine(tmp_path)
            for bloque in resultados or []:
                if bloque.get("type") != "table":
                    continue
                res = bloque.get("res") or {}
                html = res.get("html") if isinstance(res, dict) else None
                if html:
                    pagina_filas = _filas_desde_html_tabla(html)
                    for fila in _filtrar_filas_cabecera(pagina_filas):
                        fila["pagina"] = pagina_num
                        filas.append(fila)

            if not filas:
                textos: list[str] = []
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
                    for fila in _filas_desde_ocr_lineas("\n".join(textos)):
                        fila["pagina"] = pagina_num
                        filas.append(fila)

        if not filas:
            texto = _texto_ocr_imagen(tmp_path)
            if texto:
                for fila in _filas_desde_ocr_lineas(texto):
                    fila["pagina"] = pagina_num
                    filas.append(fila)
    finally:
        Path(tmp_path).unlink(missing_ok=True)
        del prepared
        gc.collect()

    return filas


def _filas_crudas_desde_html_tabla(html: str) -> list[list[str]]:
    parser = _TableHtmlParser()
    parser.feed(html)
    filas: list[list[str]] = []
    for row in parser.rows:
        celdas = _normalizar_celdas_grilla(row)
        if any(c.strip() for c in celdas):
            filas.append(celdas)
    return filas


def extraer_grilla_pdf(
    pdf_path: str,
    dpi: int = 200,
    first_page: int = 1,
    last_page: int = 15,
) -> list[dict[str, Any]]:
    """Devuelve filas crudas de tabla por página (celdas, multilínea unida en cada celda)."""
    from pdf2image import convert_from_path

    first_page = max(1, int(first_page))
    last_page = max(first_page, int(last_page))

    # Una página a la vez: menos RAM pico en Docker Desktop (~2GB).
    paginas: list[dict[str, Any]] = []
    for pagina_num in range(first_page, last_page + 1):
        images = convert_from_path(
            pdf_path,
            dpi=dpi,
            first_page=pagina_num,
            last_page=pagina_num,
            fmt="png",
            thread_count=1,
        )
        if not images:
            continue
        image = images[0]
        prepared = _preparar_imagen_para_ocr(image)
        filas_pagina: list[list[str]] = []

        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
            tmp_path = tmp.name
            prepared.save(tmp_path, format="PNG", optimize=True)

        try:
            if EXTRACT_MODE == "structure":
                engine = get_ppstructure_engine()
                resultados = engine(tmp_path)
                for bloque in resultados or []:
                    if bloque.get("type") != "table":
                        continue
                    res = bloque.get("res") or {}
                    html = res.get("html") if isinstance(res, dict) else None
                    if html:
                        filas_pagina.extend(_filas_crudas_desde_html_tabla(html))
            if not filas_pagina:
                filas_pagina = _grilla_desde_tesseract_tsv(image)
            if not filas_pagina:
                for fila in _filas_desde_ocr_lineas(_texto_ocr_imagen(tmp_path)):
                    filas_pagina.append([fila["descripcion"], str(fila["cantidad"])])
        finally:
            Path(tmp_path).unlink(missing_ok=True)
            del prepared, image, images
            gc.collect()

        if filas_pagina:
            paginas.append({"pagina": pagina_num, "filas": filas_pagina})

    return paginas


def extraer_lineas_pdf(
    pdf_path: str,
    dpi: int = 200,
    first_page: int = 1,
    last_page: int = 15,
) -> list[dict[str, Any]]:
    """Convierte PDF a imágenes y extrae filas producto/cantidad."""
    from pdf2image import convert_from_path

    first_page = max(1, int(first_page))
    last_page = max(first_page, int(last_page))

    filas: list[dict[str, Any]] = []

    for pagina_num in range(first_page, last_page + 1):
        images = convert_from_path(
            pdf_path,
            dpi=dpi,
            first_page=pagina_num,
            last_page=pagina_num,
            fmt="png",
            thread_count=1,
        )
        if not images:
            continue
        try:
            filas.extend(_extraer_filas_de_imagen(images[0], pagina_num))
        finally:
            del images
            gc.collect()

    return _deduplicar(filas)
