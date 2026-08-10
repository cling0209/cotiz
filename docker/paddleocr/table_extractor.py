"""Extrae filas producto/cantidad de PDFs escaneados con tablas."""

from __future__ import annotations

import re
import tempfile
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

CANTIDAD_RE = re.compile(
    r"^(\d{1,5})\s*(?:unidades?|packs?|pack|cajas?|sobres?|sets?)\.?\s*$",
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
            cell = re.sub(r"\s*\n\s*", " / ", cell)
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


def get_ppstructure_engine() -> Any:
    """Inicializa PPStructure una sola vez (descarga modelos en runtime)."""
    global _engine
    if _engine is None:
        from paddleocr import PPStructure

        _engine = PPStructure(show_log=False, lang="en", layout=True, table=True, ocr=True)
    return _engine


def _filas_crudas_desde_html_tabla(html: str) -> list[list[str]]:
    parser = _TableHtmlParser()
    parser.feed(html)
    filas: list[list[str]] = []
    for row in parser.rows:
        celdas = _normalizar_celdas(row)
        if celdas:
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

    engine = get_ppstructure_engine()
    first_page = max(1, int(first_page))
    last_page = max(first_page, int(last_page))

    images = convert_from_path(
        pdf_path,
        dpi=dpi,
        first_page=first_page,
        last_page=last_page,
        fmt="png",
    )

    paginas: list[dict[str, Any]] = []

    for offset, image in enumerate(images):
        pagina_num = first_page + offset
        filas_pagina: list[list[str]] = []

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
                    filas_pagina.extend(_filas_crudas_desde_html_tabla(html))
        finally:
            Path(tmp_path).unlink(missing_ok=True)

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

    engine = get_ppstructure_engine()
    first_page = max(1, int(first_page))
    last_page = max(first_page, int(last_page))

    images = convert_from_path(
        pdf_path,
        dpi=dpi,
        first_page=first_page,
        last_page=last_page,
        fmt="png",
    )

    filas: list[dict[str, Any]] = []

    for offset, image in enumerate(images):
        pagina_num = first_page + offset
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
                    pagina_filas = _filas_desde_html_tabla(html)
                    for fila in _filtrar_filas_cabecera(pagina_filas):
                        fila["pagina"] = pagina_num
                        filas.append(fila)

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
