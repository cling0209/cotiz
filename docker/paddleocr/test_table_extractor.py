"""Tests unitarios para inferencia de columnas producto/cantidad (sin Paddle)."""

from __future__ import annotations

import unittest

from table_extractor import (
    _filas_desde_html_tabla,
    _inferir_columnas_por_contenido,
    _parse_fila_con_mapeo,
)


class TestInferenciaColumnas(unittest.TestCase):
    def test_cabecera_producto_cantidad_orden_clasico(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>ACUARELAS DE 12 COLORES</td><td>40</td><td></td></tr>
        <tr><td>GOMA EVA HOJA 50X70</td><td>5</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(40, filas[0]["cantidad"])
        self.assertIn("ACUARELAS", filas[0]["descripcion"])
        self.assertEqual(5, filas[1]["cantidad"])
        self.assertIn("GOMA EVA", filas[1]["descripcion"])

    def test_cabecera_cantidad_producto_orden_invertido(self) -> None:
        html = """
        <table>
        <tr><td>CANTIDAD</td><td>PRODUCTO</td></tr>
        <tr><td>10</td><td>RESMA OFICIO 500 HOJAS</td></tr>
        <tr><td>5</td><td>GOMA EVA HOJA 50X70 CM</td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(10, filas[0]["cantidad"])
        self.assertIn("RESMA OFICIO", filas[0]["descripcion"])
        self.assertEqual(5, filas[1]["cantidad"])
        self.assertIn("GOMA EVA", filas[1]["descripcion"])

    def test_sin_cabecera_infiere_por_contenido(self) -> None:
        html = """
        <table>
        <tr><td>10</td><td>RESMA OFICIO 500 HOJAS</td></tr>
        <tr><td>5</td><td>GOMA EVA HOJA 50X70 CM</td></tr>
        <tr><td>8</td><td>LAPICES DE CERA JUMBO</td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(3, len(filas))
        self.assertEqual(10, filas[0]["cantidad"])
        self.assertIn("RESMA", filas[0]["descripcion"])

    def test_sin_cabecera_producto_antes_cantidad(self) -> None:
        html = """
        <table>
        <tr><td>ACUARELAS DE 12 COLORES</td><td>40</td></tr>
        <tr><td>SCOTCH TRANSPARENTE</td><td>100</td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(40, filas[0]["cantidad"])
        self.assertIn("ACUARELAS", filas[0]["descripcion"])

    def test_cabecera_en_filas_separadas(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td></tr>
        <tr><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>CLIP METALICO 28 MM</td><td>2</td></tr>
        <tr><td>PERFORADORA GRANDE</td><td>3</td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(2, filas[0]["cantidad"])
        self.assertIn("CLIP", filas[0]["descripcion"])

    def test_inferir_columnas_por_contenido_directo(self) -> None:
        rows = [
            ["40", "ACUARELAS DE 12 COLORES"],
            ["5", "GOMA EVA"],
        ]
        mapeo = _inferir_columnas_por_contenido(rows)
        self.assertEqual(1, mapeo["producto"])
        self.assertEqual(0, mapeo["cantidad"])

    def test_parse_fila_con_mapeo_invertido(self) -> None:
        mapeo = {"producto": 1, "cantidad": 0}
        fila = _parse_fila_con_mapeo(["10", "RESMA OFICIO 500 HOJAS"], mapeo)
        self.assertIsNotNone(fila)
        assert fila is not None
        self.assertEqual(10, fila["cantidad"])
        self.assertIn("RESMA", fila["descripcion"])

    def test_celda_multilinea_sin_cantidad_en_primera_fila(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>LAPICES DE CERA JUMBO 12</td><td></td><td></td></tr>
        <tr><td>UNIDADES IMAGIA TRIANGULAR</td><td>8 unidades</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(1, len(filas))
        self.assertEqual(8, filas[0]["cantidad"])
        self.assertIn("LAPICES DE CERA JUMBO", filas[0]["descripcion"])
        self.assertIn("UNIDADES IMAGIA", filas[0]["descripcion"])


if __name__ == "__main__":
    unittest.main()
