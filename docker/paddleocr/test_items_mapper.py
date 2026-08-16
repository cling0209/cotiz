"""Tests del mapeo sidecar cantidad/descripción (sin Paddle ni PDF)."""

from __future__ import annotations

import unittest

from items_mapper import extraer_items_desde_paginas


class TestItemsMapperCra(unittest.TestCase):
    def test_grilla_celdas_separadas(self) -> None:
        paginas = [
            {
                "filas": [
                    ["CANTIDAD", "DESCRIPCIÓN", "ID¹"],
                    ["2", "Mesón de préstamo cubierta simple. Medidas mesón: largo 200 x alto 72 x fondo 65 cm.", ""],
                    ["2", "Diario mural tipo vitrina", ""],
                    ["4", "Lector Inalámbrico", ""],
                    ["1", "Alfombra Rectangular, modelo circulo de colores", ""],
                    ["6", "Silla con respaldo con perforaciones y asientos tapiz", ""],
                    ["3", "Mesa Modular Masca para 3 personas.", ""],
                ]
            }
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertEqual(6, len(items))
        self.assertEqual(4, items[2]["cantidad"])
        self.assertEqual("Lector Inalámbrico", items[2]["descripcion"])
        self.assertEqual("Mesa Modular Masca para 3 personas.", items[5]["descripcion"])

    def test_columnas_sueltas_cra_escaneado(self) -> None:
        paginas = [
            {
                "filas": [
                    ["SLEP Calama I Ollagüe I San Pedro de Atacama"],
                    ["CANTIDAD DESCRIPCIÓN m1"],
                    ["2"],
                    ["2"],
                    ["4"],
                    ["1"],
                    ["6"],
                    ["3"],
                    ["2."],
                    ["Mesón de préstamo cubierta simple. Medidas mesón: largo"],
                    ["200 x alto 72 x fondo 65 cm."],
                    ["Diario mural tipo vitrina"],
                    ["Lector Inalámbrico"],
                    ["Alfombra Rectangular, modelo circulo de colores"],
                    ["Silla con respaldo con perforaciones y asientos tapiz"],
                    ["Mesa Modular Masca para 3 personas."],
                    ["ID1 : Tratándose de compras por Convenio Marco o Suministro."],
                    ["https://www.mercadopublico.cl/TiendaHome/"],
                    ["ANTECEDENTES GENERALES (Sólo debe ser llenado para requerimientos SEP)"],
                    ["DESCRIPCIÓN DE LA ACCIÓN Fortalecimiento de la biblioteca CRA"],
                    ["Página 3 de 5"],
                ]
            }
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertEqual(6, len(items))
        self.assertEqual(2, items[0]["cantidad"])
        self.assertIn("Mesón de préstamo cubierta simple", items[0]["descripcion"])
        self.assertIn("fondo 65 cm", items[0]["descripcion"])
        self.assertEqual(2, items[1]["cantidad"])
        self.assertEqual("Diario mural tipo vitrina", items[1]["descripcion"])
        self.assertEqual(4, items[2]["cantidad"])
        self.assertEqual("Lector Inalámbrico", items[2]["descripcion"])
        self.assertEqual(1, items[3]["cantidad"])
        self.assertEqual(6, items[4]["cantidad"])
        self.assertEqual(3, items[5]["cantidad"])
        self.assertEqual("Mesa Modular Masca para 3 personas.", items[5]["descripcion"])
        for item in items:
            self.assertNotIn("ANTECEDENTES", item["descripcion"])
            self.assertNotIn("Fortalecimiento", item["descripcion"])
            self.assertNotIn("≡", item["descripcion"])

    def test_fila_unica_pegoteada_se_parte(self) -> None:
        paginas = [
            {
                "filas": [
                    ["CANTIDAD", "DESCRIPCIÓN", "ID¹"],
                    [
                        "2",
                        "Mesón de préstamo cubierta simple. Medidas mesón: largo 200 x alto 72 x fondo 65 cm. "
                        "2 Diario mural tipo vitrina 4 Lector Inalámbrico 1 Alfombra Rectangular, modelo circulo de colores "
                        "6 Silla con respaldo con perforaciones y asientos tapiz 3 Mesa Modular Masca para 3 personas. "
                        "ANTECEDENTES GENERALES Gestión de Recursos https://www.mercadopublico.cl/TiendaHome OBJETIVOS ESTRATÉGICOS",
                        "",
                    ],
                ]
            }
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertEqual(6, len(items))
        self.assertEqual("Diario mural tipo vitrina", items[1]["descripcion"])
        self.assertEqual("Lector Inalámbrico", items[2]["descripcion"])
        self.assertNotIn("ANTECEDENTES", items[0]["descripcion"])
        self.assertNotIn("ESTRATÉGICOS", items[5]["descripcion"].upper())

    def test_mesa_no_conserva_estrategia_ni_basura_ocr(self) -> None:
        paginas = [
            {
                "filas": [
                    ["CANTIDAD DESCRIPCIÓN"],
                    ["6 Silla con respaldo"],
                    ["3 Mesa Modular Masca para 3 personas. ESTRATEGIA ≡≡ DIMENSIÓN"],
                ]
            }
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertGreaterEqual(len(items), 2)
        mesa = next(i for i in items if i["cantidad"] == 3)
        self.assertIn("Mesa Modular Masca", mesa["descripcion"])
        self.assertNotIn("ESTRATEGIA", mesa["descripcion"].upper())
        self.assertNotIn("≡", mesa["descripcion"])
        self.assertNotIn("DIMENSIÓN", mesa["descripcion"].upper())

    def test_producto_alias_descripcion(self) -> None:
        paginas = [
            {
                "filas": [
                    ["CANTIDAD", "DESCRIPCIÓN"],
                    ["10", "RESMA OFICIO"],
                    ["5", "GOMA EVA"],
                ]
            }
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "PRODUCTO")
        self.assertEqual(2, len(items))
        self.assertEqual("RESMA OFICIO", items[0]["descripcion"])

    def test_no_corta_en_silla_mesa_si_hay_cantidades_sueltas(self) -> None:
        paginas = [
            {
                "filas": [
                    ["CANTIDAD DESCRIPCIÓN m1"],
                    ["2"],
                    ["2"],
                    ["4"],
                    ["1"],
                    ["6"],
                    ["3"],
                    ["Mesón de préstamo cubierta simple."],
                    ["Diario mural tipo vitrina"],
                    ["Lector Inalámbrico"],
                    ["Alfombra Rectangular"],
                    ["Silla con respaldo con perforaciones y asientos tapiz"],
                    ["Mesa Modular Masca para 3 personas."],
                    ["6 Silla con respaldo con perforaciones y asientos tapiz"],
                    ["3 Mesa Modular Masca para 3 personas."],
                ]
            },
            {
                "filas": [
                    ["6 Silla con respaldo con perforaciones y asientos tapiz"],
                    ["3 Mesa Modular Masca para 3 personas."],
                ]
            },
        ]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertEqual(6, len(items))
        self.assertEqual("Lector Inalámbrico", items[2]["descripcion"])
        cantidades = [i["cantidad"] for i in items]
        self.assertEqual([2, 2, 4, 1, 6, 3], cantidades)

    def test_dedup_misma_tabla_en_dos_paginas(self) -> None:
        fila = [
            ["CANTIDAD", "DESCRIPCIÓN"],
            ["6", "Silla con respaldo"],
            ["3", "Mesa Modular Masca para 3 personas."],
        ]
        paginas = [{"filas": fila}, {"filas": fila}]
        items = extraer_items_desde_paginas(paginas, "CANTIDAD", "DESCRIPCIÓN")
        self.assertEqual(2, len(items))


if __name__ == "__main__":
    unittest.main()
