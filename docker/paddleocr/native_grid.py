"""Grilla de tablas desde PDF nativo usando coordenadas (pdfplumber)."""

from __future__ import annotations

import re
from typing import Any

Y_TOL = 4.0
GAP_CELDA = 18.0


def extraer_grilla_nativa_pdf(
    pdf_path: str,
    first_page: int = 1,
    last_page: int = 15,
) -> list[dict[str, Any]]:
    """Devuelve [{pagina, filas: [[celda, ...], ...]}]. Vacío si no hay texto nativo."""
    try:
        import pdfplumber
    except ImportError:
        return []

    first_page = max(1, int(first_page))
    last_page = max(first_page, int(last_page))
    paginas: list[dict[str, Any]] = []

    with pdfplumber.open(pdf_path) as pdf:
        total = len(pdf.pages)
        for pagina_num in range(first_page, min(last_page, total) + 1):
            page = pdf.pages[pagina_num - 1]
            filas = _filas_desde_tablas_pdfplumber(page)
            if not _grilla_util(filas):
                filas = _filas_desde_palabras(page.extract_words(use_text_flow=True) or [])
            if _grilla_util(filas):
                paginas.append({"pagina": pagina_num, "filas": filas})

    return paginas


def _filas_desde_tablas_pdfplumber(page: Any) -> list[list[str]]:
    try:
        tables = page.extract_tables() or []
    except Exception:
        return []

    filas: list[list[str]] = []
    for table in tables:
        for row in table or []:
            celdas = [_limpiar_celda(c) for c in (row or [])]
            if any(c != "" for c in celdas):
                filas.append(celdas)
    return filas


def _filas_desde_palabras(words: list[dict[str, Any]]) -> list[list[str]]:
    """Agrupa palabras por Y (fila) y parte celdas por huecos en X."""
    if not words:
        return []

    ordenadas = sorted(
        words,
        key=lambda w: (round(float(w.get("top", 0)), 1), float(w.get("x0", 0))),
    )
    grupos: list[list[dict[str, Any]]] = []
    fila_actual: list[dict[str, Any]] = []
    y_ref: float | None = None

    for word in ordenadas:
        top = float(word.get("top", 0))
        if y_ref is None or abs(top - y_ref) <= Y_TOL:
            fila_actual.append(word)
            if y_ref is None:
                y_ref = top
            else:
                y_ref = (y_ref * (len(fila_actual) - 1) + top) / len(fila_actual)
        else:
            if fila_actual:
                grupos.append(fila_actual)
            fila_actual = [word]
            y_ref = top
    if fila_actual:
        grupos.append(fila_actual)

    return [_celdas_desde_fila_palabras(grupo) for grupo in grupos if grupo]


def _celdas_desde_fila_palabras(grupo: list[dict[str, Any]]) -> list[str]:
    grupo = sorted(grupo, key=lambda w: float(w.get("x0", 0)))
    celdas: list[list[str]] = []
    actual: list[str] = []
    x1_prev: float | None = None

    for word in grupo:
        texto = str(word.get("text") or "").strip()
        if texto == "":
            continue
        x0 = float(word.get("x0", 0))
        x1 = float(word.get("x1", x0))
        if x1_prev is not None and (x0 - x1_prev) >= GAP_CELDA:
            celdas.append(actual)
            actual = [texto]
        else:
            actual.append(texto)
        x1_prev = x1

    if actual:
        celdas.append(actual)

    return [" ".join(p).strip() for p in celdas if p]


def _limpiar_celda(valor: Any) -> str:
    texto = "" if valor is None else str(valor)
    texto = texto.replace("\r", "\n")
    texto = re.sub(r"[ \t]+", " ", texto)
    texto = re.sub(r"\s*\n\s*", " ", texto)
    return texto.strip()


def _grilla_util(filas: list[list[str]]) -> bool:
    """Exige mayoría de filas con ≥2 celdas. Un encabezado y el resto en 1 columna no cuenta (escaneo)."""
    if len(filas) < 2:
        return False
    con_celdas = sum(1 for f in filas if sum(1 for c in f if str(c).strip() != "") >= 2)
    return con_celdas >= 2 and con_celdas >= (len(filas) / 2)


def grilla_nativa_util(paginas: list[dict[str, Any]]) -> bool:
    filas: list[list[str]] = []
    for pagina in paginas:
        for fila in pagina.get("filas") or []:
            if isinstance(fila, list):
                filas.append(fila)
    return _grilla_util(filas)
