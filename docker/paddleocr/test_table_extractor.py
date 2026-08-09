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

    def test_celda_producto_multilinea_cantidad_solo_en_columna(self) -> None:
        """Cantidad pedido solo desde columna CANTIDAD, no desde texto del producto."""
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>CUADERNO CUARTA 150 HOJAS</td><td></td><td></td></tr>
        <tr><td>7MM PACK 6 UNIDADES</td><td>2</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(1, len(filas))
        self.assertEqual(2, filas[0]["cantidad"])
        self.assertIn("CUADERNO CUARTA", filas[0]["descripcion"])
        self.assertIn("7MM PACK 6 UNIDADES", filas[0]["descripcion"])

    def test_celda_cantidad_ignora_ruido_columna_imagen(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>7MM PACK 6 UNIDADES</td><td>e. 3</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(0, len(filas))

    def test_celda_cantidad_pack_y_cajas(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td></tr>
        <tr><td>PACK MARCATEXTOS PUNTA BISELADA</td><td>2 PACK</td></tr>
        <tr><td>COLA FRÍA 120ML CAJA 12 UNIDADES</td><td>2 CAJAS</td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(2, filas[0]["cantidad"])
        self.assertEqual(2, filas[1]["cantidad"])

    def test_celda_producto_partido_block_dibujo(self) -> None:
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>BLOCK DE DIBUJO MEDIUM N°99</td><td></td><td></td></tr>
        <tr><td>1/8 20 HOJAS</td><td>5</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(1, len(filas))
        self.assertEqual(5, filas[0]["cantidad"])
        self.assertIn("1/8 20 HOJAS", filas[0]["descripcion"])

    def test_celda_termolaminadora_plastificadora_una_fila_multilinea(self) -> None:
        """PDF hoja 7: descripcion multilinea en una celda, qty en la primera sub-fila HTML."""
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>TERMOLAMINADORA</td><td>1</td><td></td></tr>
        <tr><td>PLASTIFICADORA +CORTADOR DE PAPEL +300 MICAS</td><td></td><td></td></tr>
        <tr><td>SACA CORCHETES</td><td>4</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(2, len(filas))
        self.assertEqual(1, filas[0]["cantidad"])
        self.assertIn("TERMOLAMINADORA", filas[0]["descripcion"])
        self.assertIn("PLASTIFICADORA", filas[0]["descripcion"])
        self.assertEqual(4, filas[1]["cantidad"])
        self.assertIn("SACA CORCHETES", filas[1]["descripcion"])

    def test_hoja_3_pdf_doce_filas_cantidad_desde_columna(self) -> None:
        """12 productos hoja 3 del PDF solicitud 83965 — solo celdas, sin regex por nombre."""
        html = """
        <table>
        <tr><td>PRODUCTO</td><td>CANTIDAD</td><td>IMAGEN REFERENCIA</td></tr>
        <tr><td>MARCADORES 20 COLORES</td><td>10 sobres</td><td></td></tr>
        <tr><td>TEMPERA 12 COLORES</td><td>10 cajas</td><td></td></tr>
        <tr><td>TEMPERA 6 COLOR PASTEL</td><td>10 cajas</td><td></td></tr>
        <tr><td>PLASTICINA 12 COLORES NEON</td><td>10 cajas</td><td></td></tr>
        <tr><td>PLASTICINA TRIANGULAR 12 COLORES PASTELES</td><td>10 cajas</td><td></td></tr>
        <tr><td>CORRECTOR LÁPIZ 7ML CAJA 12 UNIDADES</td><td>1 caja</td><td></td></tr>
        <tr><td>CUADERNO COLLEGE 7MM 80 HOJAS PACK 10 UNI</td><td>4 pack de 10 unidades</td><td></td></tr>
        <tr><td>PORCELANA EN FRIO</td><td>6</td><td></td></tr>
        <tr><td>PINTURA ACRÍLICA DECORATIVA 6 COLORES</td><td>10</td><td></td></tr>
        <tr><td>MARCADORES NEGRO PUNTA FINA PERMANENTE CAJA 14 UNI</td><td>1 caja</td><td></td></tr>
        <tr><td>FINELINER 12 COLORES DELI</td><td>5 set</td><td></td></tr>
        <tr><td>ACUARELA SET 12 COLORES CON PINCEL</td><td>20 set</td><td></td></tr>
        </table>
        """
        filas = _filas_desde_html_tabla(html)
        self.assertEqual(12, len(filas))
        self.assertEqual(10, filas[0]["cantidad"])
        self.assertIn("MARCADORES 20 COLORES", filas[0]["descripcion"])
        self.assertEqual(4, filas[6]["cantidad"])
        self.assertIn("CUADERNO COLLEGE", filas[6]["descripcion"])
        self.assertEqual(1, filas[9]["cantidad"])
        self.assertIn("MARCADORES NEGRO", filas[9]["descripcion"])
        self.assertEqual(20, filas[11]["cantidad"])
        self.assertIn("ACUARELA SET", filas[11]["descripcion"])


if __name__ == "__main__":
    unittest.main()
