<?php
/**
 * 支払基金 電子点数表（rule-tables/R6/raw/*.csv）
 * → AI 用 JSON に自動変換
 *
 * JSON は以下に出力される：
 *   rule-tables/R6/supplemental/<code>.json
 *   rule-tables/R6/inclusive/<code>.json
 *   rule-tables/R6/exclusive/<code>.json
 *   rule-tables/R6/inpatient-base/<code>.json
 *   rule-tables/R6/count-limits/<code>.json
 */

$baseDir = __DIR__ . '/R6/';
$rawDir  = $baseDir . 'raw/';

$tables = [
    'supplemental'   => '01補助マスターテーブル.csv',
    'inclusive'      => '02包括テーブル.csv',
    'exclusive'      => ['03-1背反テーブル1.csv', '03-2背反テーブル2.csv', '03-3背反テーブル3.csv', '03-4背反テーブル4.csv'],
    'inpatient-base' => '04入院基本料テーブル.csv',
    'count-limits'   => '05算定回数テーブル.csv'
];

function ensure_dir($path) {
    if (!is_dir($path)) mkdir($path, 0777, true);
}

function csv_to_array($file) {
    $content = file_get_contents($file);
    $utf8 = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
    $lines = explode("\n", trim($utf8));
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

function save_json($path, $data) {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

echo "=== rule-tables R6 conversion start ===\n";

foreach ($tables as $category => $files) {

    echo "\n[{$category}] processing...\n";

    $schemaPath = $baseDir . $category . '/schema.json';
    if (!file_exists($schemaPath)) {
        echo "  [ERROR] schema missing: $schemaPath\n";
        continue;
    }
    $schema = json_decode(file_get_contents($schemaPath), true);

    $outputDir = $baseDir . $category . '/';
    ensure_dir($outputDir);

    $fileList = is_array($files) ? $files : [$files];

    foreach ($fileList as $fileName) {
        $filePath = $rawDir . $fileName;

        if (!file_exists($filePath)) {
            echo "  [WARNING] raw file missing: $filePath\n";
            continue;
        }

        echo "  loading: $fileName\n";

        $rows = csv_to_array($filePath);

        // ヘッダ行を取得
        $header = $rows[0];
        array_shift($rows);

        foreach ($rows as $cols) {

            // 行為コード（最初の列と仮定、実データに合わせて必要なら調整可）
            $code = $cols[0];
            if ($code === '' || $code === null) continue;

            $json = ["code" => $code];

            /////// カテゴリ別処理 ///////

            if ($category === 'supplemental') {

                $json['attributes'] = [
                    "category"         => $cols[1] ?? "",
                    "sub_category"     => $cols[2] ?? "",
                    "drug_related"     => ($cols[3] ?? "") === "1",
                    "surgery_related"  => ($cols[4] ?? "") === "1",
                    "radiology_related"=> ($cols[5] ?? "") === "1",
                    "requires_comment" => ($cols[6] ?? "") === "1",
                    "requires_modifier"=> ($cols[7] ?? "") === "1"
                ];

            } elseif ($category === 'inclusive') {

                $json['includes'] = array_filter(explode(',', $cols[1] ?? ""));
                if (!empty($cols[2])) {
                    $json['included_in'] = $cols[2];
                }

            } elseif ($category === 'exclusive') {

                $json['exclusive'] = [];

                for ($i = 1; $i < count($cols); $i += 2) {
                    if (empty($cols[$i])) continue;

                    $target = $cols[$i];
                    $type   = $cols[$i + 1] ?? "same_day";

                    $json['exclusive'][] = [
                        "target" => $target,
                        "type"   => $type
                    ];
                }

            } elseif ($category === 'inpatient-base') {

                $json['ward_type'] = $cols[1] ?? "";

                $json['conditions'] = [
                    "required_staff" => [
                        "nurse_ratio" => $cols[2] ?? "",
                        "doctor_presence" => ($cols[3] ?? "") === "1"
                    ],
                    "patient_conditions" => [
                        "age" => $cols[4] ?? "",
                        "severity_level" => $cols[5] ?? ""
                    ]
                ];

                $json['exclusions'] = array_filter(explode(',', $cols[6] ?? ""));

            } elseif ($category === 'count-limits') {

                $json['count_limits'] = [];

                for ($i = 1; $i < count($cols); $i += 2) {
                    if (empty($cols[$i])) continue;

                    $unit = $cols[$i];
                    $max  = intval($cols[$i + 1] ?? 0);

                    $json['count_limits'][] = [
                        "unit" => $unit,
                        "max"  => $max
                    ];
                }
            }

            ////// 保存 //////

            $outFile = $outputDir . $code . '.json';
            save_json($outFile, $json);
        }
    }

    echo "  -> OK: {$category} complete.\n";
}

echo "\n=== rule-tables R6 conversion done ===\n";
