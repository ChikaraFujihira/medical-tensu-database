<?php

// ------------------------------------------------------------
//  医科中央マスター raw → normalized 変換スクリプト
//  medical-tensu-database / scripts/convert_master.php
// ------------------------------------------------------------

/**
 * 設定
 */
$baseDir = __DIR__ . '/../central-master/R6/';
$schemaDir = $baseDir . 'schema/';
$rawDir    = $baseDir . 'raw/';
$normalDir = $baseDir . 'normalized/';

$masters = [
    's-medical-acts'        => 's-medical-acts.csv',
    'y-drugs'               => 'y-drugs.csv',
    't-specific-materials'  => 't-specific-materials.csv',
    'b-diseases'            => 'b-diseases.csv',
    'z-modifiers'           => 'z-modifiers.csv',
    'c-comments'            => 'c-comments.csv',
    'k-byotou'              => 'k-byotou.csv'   // CSV 仕様のため注意
];

$mapping = json_decode(file_get_contents($schemaDir . 'mapping.json'), true);

echo "=== Master Conversion Start ===\n";

foreach ($masters as $key => $srcFile) {

    $schemaPath = $schemaDir . $key . '.schema.json';
    $rawPath    = $rawDir . $srcFile;
    $outPath    = $normalDir . $key . '.csv';

    echo "\n[{$key}] converting...\n";

    if (!file_exists($schemaPath)) {
        echo "  [ERROR] schema missing: {$schemaPath}\n";
        continue;
    }
    if (!file_exists($rawPath)) {
        echo "  [ERROR] raw file missing: {$rawPath}\n";
        continue;
    }

    $schema = json_decode(file_get_contents($schemaPath), true);
    $headers = array_keys($schema['properties']);

    $fp = fopen($outPath, 'w');

    // ---- ヘッダをダブルクォート囲みで書き込む ----
    fwrite($fp, '"' . implode('","', $headers) . '"' . "\n");

    // RAW 読み込み（k.csv のみ CSV）
    $isCsv = (substr($srcFile, -4) === '.csv');
    $lines = file($rawPath);

    foreach ($lines as $line) {

        $utf8 = mb_convert_encoding(rtrim($line), 'UTF-8', 'SJIS-win');

        if ($isCsv) {
            $cols = str_getcsv($utf8);
        } else {
            $cols = explode("\t", $utf8);
        }

        $rowValues = [];

        foreach ($schema['properties'] as $colName => $info) {

            if ($colName === 'raw_record') {
                // Base64 により「行崩れゼロ」
                $rowValues[] = base64_encode($utf8);
                continue;
            }

            if (!isset($mapping[$key][$colName])) {
                $rowValues[] = "";
                continue;
            }

            $index = $mapping[$key][$colName];
            $rowValues[] = $cols[$index] ?? "";
        }

        // ---- ここで全フィールドを必ず "..." で囲む ----
        $escaped = array_map(
            fn($v) => '"' . str_replace('"', '""', $v) . '"',
            $rowValues
        );

        fwrite($fp, implode(",", $escaped) . "\n");
    }

    fclose($fp);

    echo "  -> OK: {$outPath}\n";
}

echo "\n=== Conversion Completed ===\n";
