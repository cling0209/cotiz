"""Convierte grilla/texto de tabla en ítems {cantidad, descripcion}."""

from __future__ import annotations

import re
import unicodedata
from typing import Any

ETIQUETAS_PRODUCTO = (
    "PRODUCTO",
    "DESCRIPCION",
    "DETALLE",
    "NOMBRE DEL PRODUCTO",
    "BIEN O SERVICIO",
    "REQUERIMIENTO",
)
ETIQUETAS_CANTIDAD = ("CANTIDAD", "UNIDADES", "QTY", "CANT")

_RUIDO = re.compile(
    r"ANTECEDENTES GENERALES|MERCADOPUBLICO\.CL|OBJETIVOS ESTRATEGICOS|"
    r"INCLUIR TANTAS LINEAS|CONVENIO MARCO|GESTION DE RECURSOS|"
    r"DESCRIPCION DE LA ACCION|\bDIMENSION\b|\bESTRATEGIA\b|"
    r"TRATANDOSE DE COMPRAS|PAGINA \d+ DE",
    re.IGNORECASE,
)
_CORTE_LINEA = re.compile(
    r"^(ANTECEDENTES GENERALES|DESCRIPCION DE LA ACCION|OBJETIVOS ESTRATEGICOS|"
    r"CONVENIO MARCO|GESTION DE RECURSOS|PAGINA \d+ DE|DIMENSION|ESTRATEGIA|"
    r"INCLUIR TANTAS|TRATANDOSE DE COMPRAS|HTTPS WWW MERCADOPUBLICO)\b",
    re.IGNORECASE,
)
_QTY_SOLA = re.compile(r"^\d{1,5}$")
_QTY_DESC = re.compile(
    r"^(\d{1,5})\s+(?!HOJAS\b|UNIDADES\b|UNIDAD\b|PLIEGOS\b)(?!x\s)([A-Za-zÁÉÍÓÚÑáéíóúñ¿¡(].+)$",
    re.UNICODE,
)
_BASURA_OCR = re.compile(r"[≡Ξ]{2,}")
_PEGADO = re.compile(
    r"(?<=\S)\s+(?=\d{1,5}\s+"
    r"(?!HOJAS\b|UNIDADES\b|UNIDAD\b|PLIEGOS\b|PLIEGO\b|PACKS?\b|CAJAS?\b|SETS?\b|SOBRES?\b)"
    r"[A-ZÁÉÍÓÚÑ(][A-Za-zÁ-ú]{2,})"
)


def extraer_items_desde_paginas(
    paginas: list[dict[str, Any]],
    columna_cantidad: str = "CANTIDAD",
    columna_producto: str = "PRODUCTO",
) -> list[dict[str, Any]]:
    filas: list[list[str]] = []
    for pagina in paginas:
        for fila in pagina.get("filas") or []:
            if isinstance(fila, list):
                filas.append([str(c).strip() if c is not None else "" for c in fila])

    por_celdas = _dedup_items(
        _expandir_pegoteados(_items_desde_celdas(filas, columna_cantidad, columna_producto))
    )

    lineas: list[str] = []
    for fila in filas:
        for celda in fila:
            texto = str(celda).strip()
            if texto:
                lineas.append(texto)

    por_lineas = _dedup_items(
        _expandir_pegoteados(_items_desde_lineas(lineas, columna_cantidad, columna_producto))
    )

    if len(por_lineas) > len(por_celdas):
        return por_lineas
    return por_celdas


def _norm(texto: str) -> str:
    texto = unicodedata.normalize("NFKD", texto)
    texto = "".join(c for c in texto if not unicodedata.combining(c))
    texto = re.sub(r"[^A-Z0-9 ]", " ", texto.upper())
    return re.sub(r"\s+", " ", texto).strip()


def _es_producto_header(celda: str, nombre: str) -> bool:
    n = _norm(celda)
    p = _norm(nombre)
    if not n or not p:
        return False
    if p in n or n in p:
        return True
    nombre_eq = any(_norm(e) in n or n in _norm(e) for e in ETIQUETAS_PRODUCTO)
    pedido_eq = any(_norm(e) in p or p in _norm(e) for e in ETIQUETAS_PRODUCTO)
    return nombre_eq and pedido_eq


def _es_cantidad_header(celda: str, nombre: str) -> bool:
    n = _norm(celda)
    p = _norm(nombre)
    if not n or not p:
        return False
    if p in n or n in p:
        return True
    return any(_norm(e) in n for e in ETIQUETAS_CANTIDAD) and any(
        _norm(e) in p for e in ETIQUETAS_CANTIDAD
    )


def _linea_termina_listado(texto: str) -> bool:
    n = _norm(texto)
    if n == "":
        return False
    if _CORTE_LINEA.match(n):
        return True
    recortada = _recortar(texto)
    if _RUIDO.search(n) and len(recortada) < 12:
        return True
    letras = len(re.findall(r"[A-Za-zÁ-ú]", recortada))
    return bool(_BASURA_OCR.search(texto) and letras < 8)


def _recortar(texto: str) -> str:
    partes = _RUIDO.split(texto, maxsplit=1)
    texto = partes[0].strip()
    texto = _BASURA_OCR.split(texto, maxsplit=1)[0].strip()
    return texto


def _parse_qty(texto: str) -> int | None:
    texto = texto.strip()
    if _QTY_SOLA.match(texto):
        valor = int(texto)
        return valor if 1 <= valor <= 99999 else None
    return None


def _dedup_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[tuple[int, str]] = set()
    out: list[dict[str, Any]] = []
    for item in items:
        qty = int(item.get("cantidad") or 0)
        desc = str(item.get("descripcion") or "").strip()
        clave = (qty, _norm(desc)[:80])
        if qty < 1 or len(desc) < 2 or clave in seen:
            continue
        seen.add(clave)
        out.append({"cantidad": qty, "descripcion": desc})
    return out


def _item(qty: int, desc: str) -> dict[str, Any] | None:
    desc = _recortar(desc)
    if qty >= 1 and len(desc) >= 2 and not _linea_termina_listado(desc):
        return {"cantidad": qty, "descripcion": desc}
    return None


def _expandir_pegoteados(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for item in items:
        texto = _recortar(str(item.get("descripcion") or ""))
        qty0 = int(item.get("cantidad") or 0)
        partes = _PEGADO.split(texto)
        if len(partes) < 2:
            normal = _item(qty0, texto)
            if normal is not None:
                out.append(normal)
            continue
        for i, parte in enumerate(partes):
            parte = parte.strip()
            if parte == "":
                continue
            if i == 0:
                normal = _item(qty0, parte)
            else:
                partida = _QTY_DESC.match(parte)
                if partida:
                    normal = _item(int(partida.group(1)), partida.group(2))
                else:
                    normal = None
                    if out:
                        out[-1]["descripcion"] = _recortar(out[-1]["descripcion"] + " " + parte)
            if normal is not None:
                out.append(normal)
    return out


def _items_desde_celdas(
    filas: list[list[str]],
    col_cant: str,
    col_prod: str,
) -> list[dict[str, Any]]:
    idx_c = idx_p = None
    items: list[dict[str, Any]] = []

    for fila in filas:
        celdas = [c.strip() for c in fila]
        if not any(celdas):
            continue
        found_c = found_p = None
        for i, celda in enumerate(celdas):
            if _es_cantidad_header(celda, col_cant):
                found_c = i
            if _es_producto_header(celda, col_prod):
                found_p = i
        if found_c is not None and found_p is not None and found_c != found_p:
            idx_c, idx_p = found_c, found_p
            continue
        if idx_c is None or idx_p is None:
            continue
        while len(celdas) <= max(idx_c, idx_p):
            celdas.append("")
        unida = " ".join(c for c in celdas if c)
        if _linea_termina_listado(unida):
            break
        qty = _parse_qty(celdas[idx_c])
        desc = _recortar(celdas[idx_p])
        if qty is None:
            partida = _QTY_DESC.match(unida)
            if partida:
                qty = int(partida.group(1))
                desc = _recortar(partida.group(2))
        normal = _item(qty or 0, desc)
        if normal is not None:
            items.append(normal)

    return items


def _items_desde_lineas(
    lineas: list[str],
    col_cant: str,
    col_prod: str,
) -> list[dict[str, Any]]:
    start = 0
    for i, cruda in enumerate(lineas):
        linea = cruda.strip()
        n = _norm(linea)
        if len(n) > 48:
            continue
        if _es_cantidad_header(linea, col_cant) or _es_producto_header(linea, col_prod):
            start = i + 1

    pending: list[int] = []
    items: list[dict[str, Any]] = []

    def push(qty: int, desc: str) -> None:
        normal = _item(qty, desc)
        if normal is not None:
            items.append(normal)

    for cruda in lineas[start:]:
        linea = cruda.strip()
        if linea == "" or linea.upper() in {"ID", "ID1", "ID¹"} or re.match(r"^ID\d*\b", linea, re.I):
            if linea != "" and (_RUIDO.search(_norm(linea)) or len(_recortar(linea)) < 12):
                break
            continue
        if _linea_termina_listado(linea):
            break
        qty_sola = _parse_qty(linea)
        if qty_sola is not None:
            pending.append(qty_sola)
            continue
        partida = _QTY_DESC.match(linea)
        if partida:
            qty = int(partida.group(1))
            desc = partida.group(2)
            if pending and pending[0] == qty:
                pending.pop(0)
            elif pending:
                continue
            push(qty, desc)
            continue
        if not re.match(r"^[A-Za-zÁÉÍÓÚÑáéíóúñ¿¡(]", linea):
            if items and re.match(r"^\d{1,4}\s+x\s", linea, re.I):
                items[-1]["descripcion"] = _recortar(items[-1]["descripcion"] + " " + linea)
            continue
        if pending:
            push(pending.pop(0), linea)
        elif items:
            items[-1]["descripcion"] = _recortar(items[-1]["descripcion"] + " " + linea)

    return items
