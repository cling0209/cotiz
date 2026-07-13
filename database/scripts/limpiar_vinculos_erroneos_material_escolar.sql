-- Limpia vínculos erróneos en agilemaeprod (aprendizaje)
-- Casos PDF Material_escolar: cartón/cartulina/cinta → maestros incorrectos
-- PostgreSQL / DBeaver
--
-- 1) Preview → 2) DELETE → 3) Verificar
-- Tras borrar, re-analizar el PDF: deben salir Pendiente (no Vinculado).

-- =============================================================================
-- 1) Preview: filas de aprendizaje que disparan esos falsos positivos
-- =============================================================================
SELECT
    a.prod_item_agile,
    a.prod_codigo_categoria_mp AS id_agile_pdf,
    a.prod_descripcion_agile,
    a.prod_item AS codigo_maestro_malo,
    m.prod_nombre AS nombre_maestro
FROM agilemaeprod a
LEFT JOIN maeprod m ON m.prod_item = a.prod_item
WHERE
    -- por ID pdf de la pantalla
    a.prod_codigo_categoria_mp IN (
        'pdf:0bf67f43a55e467864cae1e665ea787b',
        'pdf:13bda426e2bb120742a82b75eb6d3fa4',
        'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
        'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
        'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
        'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
    )
    OR a.prod_item_agile IN (
        'pdf:0bf67f43a55e467864cae1e665ea787b',
        'pdf:13bda426e2bb120742a82b75eb6d3fa4',
        'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
        'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
        'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
        'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
    )
    -- por descripción exacta (o casi) de las 6 líneas
    OR upper(trim(a.prod_descripcion_agile)) IN (
        'JARDIN CALABACITAS PACK DE 10 PLIEGOS DE CARTON FORRADO EN COLORES SURTIDOS',
        'CARTON FORRADO PLIEGO BLANCO',
        'JARDIN MANZANITAS PACK DE 10 PLIEGOS DE CARTON CORRUGADO EN COLORES SURTIDOS',
        'PACK DE 10 PLIEGOS DE CARTULINA COLORES SURTIDOS',
        'JARDIN PULGARCITO PACK DE 12 PLIEGOS DE CARTULINA ESPAÑOLA COLORES SURTIDOS',
        'CINTA DOBLE CONTACTO BLANCO 40MTS. X 24MM',
        'CINTA DOBLE CONTACTO BLANCO 40MTS X 24MM'
    )
    -- patrones amplios: cartón/cartulina aprendidos como tinta/goma EVA
    OR (
        a.prod_item IN ('797271', '56841S')
        AND (
            a.prod_descripcion_agile ILIKE '%CARTON%'
            OR a.prod_descripcion_agile ILIKE '%CARTULINA%'
            OR a.prod_descripcion_agile ILIKE '%CORRUGADO%'
        )
    )
    -- cinta aprendida como palitos
    OR (
        a.prod_item = 'MERLI4325'
        AND a.prod_descripcion_agile ILIKE '%CINTA%'
    )
ORDER BY a.prod_item, a.prod_descripcion_agile;

-- =============================================================================
-- 2) Borrar aprendizaje malo (descomentar COMMIT al final)
-- =============================================================================
BEGIN;

DELETE FROM agilemaeprod a
WHERE
    a.prod_codigo_categoria_mp IN (
        'pdf:0bf67f43a55e467864cae1e665ea787b',
        'pdf:13bda426e2bb120742a82b75eb6d3fa4',
        'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
        'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
        'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
        'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
    )
    OR a.prod_item_agile IN (
        'pdf:0bf67f43a55e467864cae1e665ea787b',
        'pdf:13bda426e2bb120742a82b75eb6d3fa4',
        'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
        'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
        'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
        'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
    )
    OR upper(trim(a.prod_descripcion_agile)) IN (
        'JARDIN CALABACITAS PACK DE 10 PLIEGOS DE CARTON FORRADO EN COLORES SURTIDOS',
        'CARTON FORRADO PLIEGO BLANCO',
        'JARDIN MANZANITAS PACK DE 10 PLIEGOS DE CARTON CORRUGADO EN COLORES SURTIDOS',
        'PACK DE 10 PLIEGOS DE CARTULINA COLORES SURTIDOS',
        'JARDIN PULGARCITO PACK DE 12 PLIEGOS DE CARTULINA ESPAÑOLA COLORES SURTIDOS',
        'CINTA DOBLE CONTACTO BLANCO 40MTS. X 24MM',
        'CINTA DOBLE CONTACTO BLANCO 40MTS X 24MM'
    )
    OR (
        a.prod_item IN ('797271', '56841S')
        AND (
            a.prod_descripcion_agile ILIKE '%CARTON%'
            OR a.prod_descripcion_agile ILIKE '%CARTULINA%'
            OR a.prod_descripcion_agile ILIKE '%CORRUGADO%'
        )
    )
    OR (
        a.prod_item = 'MERLI4325'
        AND a.prod_descripcion_agile ILIKE '%CINTA%'
    );

-- Verificar que ya no quedan
SELECT count(*) AS restantes
FROM agilemaeprod a
WHERE
    a.prod_codigo_categoria_mp IN (
        'pdf:0bf67f43a55e467864cae1e665ea787b',
        'pdf:13bda426e2bb120742a82b75eb6d3fa4',
        'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
        'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
        'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
        'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
    )
    OR (
        a.prod_item IN ('797271', '56841S')
        AND (
            a.prod_descripcion_agile ILIKE '%CARTON%'
            OR a.prod_descripcion_agile ILIKE '%CARTULINA%'
            OR a.prod_descripcion_agile ILIKE '%CORRUGADO%'
        )
    )
    OR (
        a.prod_item = 'MERLI4325'
        AND a.prod_descripcion_agile ILIKE '%CINTA%'
    );

COMMIT;
-- ROLLBACK;

-- =============================================================================
-- 3) Opcional: líneas ya grabadas en cotización con esos pdf: y código malo
-- =============================================================================
-- SELECT nronota, orden, prod_item, prod_item_agile, prod_descripcion_agile
-- FROM notadetalle
-- WHERE prod_item_agile IN (
--     'pdf:0bf67f43a55e467864cae1e665ea787b',
--     'pdf:13bda426e2bb120742a82b75eb6d3fa4',
--     'pdf:9b82f1cb0cb74da7266e5a9a5dc2a8fa',
--     'pdf:75bcea7406a2f1f148dcac9946ddf0d4',
--     'pdf:89a695c06b7ac0e77f578ed14cc3a70f',
--     'pdf:86d73e1cccca6ab89ea5953c01b4ec9c'
-- )
--    OR (
--        prod_item IN ('797271', '56841S', 'MERLI4325')
--        AND (
--            prod_descripcion_agile ILIKE '%CARTON%'
--            OR prod_descripcion_agile ILIKE '%CARTULINA%'
--            OR prod_descripcion_agile ILIKE '%CINTA%'
--        )
--    );
