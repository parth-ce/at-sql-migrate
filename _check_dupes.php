<?php
require __DIR__ . '/functions.php';

$csv = __DIR__ . '/ankensaki_joho AT.csv';
$h = fopen($csv, 'r');
$headers = readAirtableCsvHeaders($h);
fclose($h);

echo "=== CSV header analysis ===\n";
echo 'Total headers: ' . count($headers) . "\n";

$counts = array_count_values($headers);
$dups = array_filter($counts, fn($c) => $c > 1);
echo 'Exact duplicate headers: ' . count($dups) . "\n";
foreach ($dups as $k => $v) {
    echo "  [{$v}x] {$k}\n";
}

$norm = [];
foreach ($headers as $h) {
    $k = normalizeHeaderKey($h);
    if (!isset($norm[$k])) {
        $norm[$k] = [];
    }
    $norm[$k][] = $h;
}
$normDups = array_filter($norm, fn($a) => count(array_unique($a)) > 1);
echo 'Normalized duplicate header groups: ' . count($normDups) . "\n";
foreach ($normDups as $k => $vals) {
    echo "  norm=[{$k}] => " . implode(' | ', array_unique($vals)) . "\n";
}

if ($argc > 1 && file_exists($argv[1])) {
    echo "\n=== Schema Excel analysis: {$argv[1]} ===\n";
    $schema = parseSchema($argv[1], basename($argv[1]), $argc > 2 ? $argv[2] : null);
    echo 'Schema rows (by JP key): ' . count($schema) . "\n";

    $enMap = [];
    foreach ($schema as $jp => $info) {
        $en = trim($info['en_name']);
        $enKey = normalizeHeaderKey($en);
        if (!isset($enMap[$enKey])) {
            $enMap[$enKey] = [];
        }
        $enMap[$enKey][] = ['jp' => $jp, 'en' => $en, 'type' => $info['type']];
    }
    $enDups = array_filter($enMap, fn($a) => count($a) > 1);
    echo 'Duplicate EN column names (case/spacing normalized): ' . count($enDups) . "\n";
    foreach ($enDups as $enKey => $rows) {
        echo "  SQL column norm=[{$enKey}]:\n";
        foreach ($rows as $r) {
            echo "    JP: {$r['jp']} | EN: {$r['en']} | type: {$r['type']}\n";
        }
    }
} else {
    echo "\n(No schema xlsx passed — run: php _check_dupes.php schema.xlsx [table_filter])\n";
}
