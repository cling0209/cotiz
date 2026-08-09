<?php

$goldenPath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json';
$pagPath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json';

$golden = json_decode((string) file_get_contents($goldenPath), true, 512, JSON_THROW_ON_ERROR);
$pag = json_decode((string) file_get_contents($pagPath), true, 512, JSON_THROW_ON_ERROR);

$hoja = 1;
$idx = 0;

foreach ($golden['lineas'] as &$fila) {
    if ($idx >= $pag['filas_por_hoja'][$hoja - 1]) {
        $hoja++;
        $idx = 0;
    }
    $fila['pagina'] = $hoja;
    $idx++;
}
unset($fila);

file_put_contents(
    $goldenPath,
    json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL,
);

echo 'pagina asignada a '.count($golden['lineas'])." productos\n";
