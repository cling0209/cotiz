"""Tests de grilla nativa por coordenadas (sin Paddle ni PDF real)."""

from __future__ import annotations

import unittest

from native_grid import (
    _filas_desde_palabras,
    _grilla_util,
    grilla_nativa_util,
)


def _w(text: str, x0: float, x1: float, top: float) -> dict:
    return {"text": text, "x0": x0, "x1": x1, "top": top, "bottom": top + 10}


class TestNativeGridPalabras(unittest.TestCase):
    def test_detalle_cra_cantidad_descripcion_id(self) -> None:
        words = [
            _w("CANTIDAD", 50, 110, 100),
            _w("DESCRIPCIÓN", 140, 230, 100),
            _w("ID¹", 500, 530, 101),
            _w("2", 55, 65, 130),
            _w("Mesón", 140, 175, 130),
            _w("de", 178, 190, 130),
            _w("préstamo", 193, 245, 130),
            _w("cubierta", 248, 300, 130),
            _w("simple.", 303, 345, 130),
            _w("2", 55, 65, 160),
            _w("Diario", 140, 180, 160),
            _w("mural", 183, 220, 160),
            _w("tipo", 223, 250, 160),
            _w("vitrina", 253, 300, 160),
            _w("4", 55, 65, 190),
            _w("Lector", 140, 180, 190),
            _w("Inalámbrico", 183, 260, 190),
            _w("1", 55, 65, 220),
            _w("Alfombra", 140, 195, 220),
            _w("Rectangular", 198, 270, 220),
            _w("6", 55, 65, 250),
            _w("Silla", 140, 175, 250),
            _w("con", 178, 198, 250),
            _w("respaldo", 201, 255, 250),
            _w("3", 55, 65, 280),
            _w("Mesa", 140, 170, 280),
            _w("Modular", 173, 225, 280),
            _w("Masca", 228, 265, 280),
        ]

        filas = _filas_desde_palabras(words)
        self.assertGreaterEqual(len(filas), 7)
        self.assertEqual(["CANTIDAD", "DESCRIPCIÓN", "ID¹"], filas[0])
        self.assertEqual("2", filas[1][0])
        self.assertIn("Mesón de préstamo cubierta simple.", filas[1][1])
        self.assertEqual("2", filas[2][0])
        self.assertEqual("Diario mural tipo vitrina", filas[2][1])
        self.assertEqual("4", filas[3][0])
        self.assertEqual("Lector Inalámbrico", filas[3][1])
        self.assertTrue(_grilla_util(filas))
        self.assertTrue(grilla_nativa_util([{"pagina": 1, "filas": filas}]))

    def test_no_parte_palabras_cercanas_de_la_descripcion(self) -> None:
        words = [
            _w("10", 40, 55, 80),
            _w("RESMA", 90, 130, 80),
            _w("OFICIO", 133, 175, 80),
            _w("500", 178, 200, 80),
            _w("HOJAS", 203, 245, 80),
        ]
        filas = _filas_desde_palabras(words)
        self.assertEqual(1, len(filas))
        self.assertEqual("10", filas[0][0])
        self.assertEqual("RESMA OFICIO 500 HOJAS", filas[0][1])

    def test_grilla_vacia_no_es_util(self) -> None:
        self.assertFalse(_grilla_util([]))
        self.assertFalse(_grilla_util([["solo una celda"]]))
        self.assertFalse(grilla_nativa_util([]))


if __name__ == "__main__":
    unittest.main()
