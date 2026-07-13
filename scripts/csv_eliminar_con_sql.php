<?php

$csvIn = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\datos2_eliminar_vinculos.csv';
$base = pathinfo($csvIn, PATHINFO_FILENAME);
$dir = dirname($csvIn);
$csvOut = $argv[2] ?? ($dir.DIRECTORY_SEPARATOR.$base.'_con_sql.csv');

$toUtf8 = static function (?string $value): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (! mb_check_encoding($value, 'UTF-8')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        $value = $converted !== false ? $converted : $value;
    }
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

    return trim($clean !== false ? $clean : $value);
};

$sqlLiteral = static function (string $value): string {
    return "'".str_replace("'", "''", $value)."'";
};

$raw = file_get_contents($csvIn);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer $csvIn\n");
    exit(1);
}
if (! mb_check_encoding($raw, 'UTF-8')) {
    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
    $raw = $converted !== false ? $converted : $raw;
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'csv_con_sql_in.csv';
file_put_contents($tmp, $raw);

$fh = fopen($tmp, 'rb');
$header = fgetcsv($fh, 0, ';');
if ($header === false) {
    fwrite(STDERR, "CSV vacío\n");
    exit(1);
}
$header = array_map($toUtf8, $header);
$map = array_flip($header);

$out = fopen($csvOut, 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, array_merge($header, ['sql_delete']), ';');

$n = 0;
while (($data = fgetcsv($fh, 0, ';')) !== false) {
    if (! is_array($data) || $data === []) {
        continue;
    }
    // normalizar ancho
    while (count($data) < count($header)) {
        $data[] = '';
    }
    $data = array_map($toUtf8, array_slice($data, 0, count($header)));

    $id = (string) ($data[$map['prod_item_agile'] ?? 0] ?? '');
    $desc = (string) ($data[$map['prod_descripcion_agile'] ?? 1] ?? '');
    $prod = (string) ($data[$map['prod_item'] ?? 2] ?? '');

    $parts = [];
    if ($id !== '') {
        $parts[] = 'prod_item_agile = '.$sqlLiteral($id);
    }
    if ($desc !== '' && $prod !== '' && $prod !== '0') {
        $parts[] = '(prod_item = '.$sqlLiteral($prod)
            .' AND upper(trim(coalesce(prod_descripcion_agile, \'\'))) = upper(trim('.$sqlLiteral($desc).')))';
    }

    if ($parts === []) {
        $sql = '';
    } else {
        $sql = 'DELETE FROM agilemaeprod WHERE '.implode(' OR ', $parts).';';
    }

    fputcsv($out, array_merge($data, [$sql]), ';');
    $n++;
}

fclose($fh);
fclose($out);
@unlink($tmp);

echo "Filas: $n\n";
echo "CSV: $csvOut\n";
