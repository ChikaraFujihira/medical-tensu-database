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

$baseDir = __DIR__ . '/';
$rawDir  = $baseDir . 'raw/';

$tables = [
    'supplemental' => [
        'file'       => '01補助マスターテーブル.csv',
        'code_index' => 1  // "111000110"
    ],
    'inclusive' => [
        'file'       => '02包括テーブル.csv',
        'code_index' => 2  // "113000910" など
    ],
    'exclusive' => [
        'file'       => [
            '03-1背反テーブル1.csv',
            '03-2背反テーブル2.csv',
            '03-3背反テーブル3.csv',
            '03-4背反テーブル4.csv'
        ],
        'code_index' => 1  // "111000110" など
    ],
    'inpatient-base' => [
        'file'       => '04入院基本料テーブル.csv',
        'code_index' => 2  // "190076570" など
    ],
    'count-limits' => [
        'file'       => '05算定回数テーブル.csv',
        'code_index' => 1  // "111000110"
    ],
];

function ensure_dir($path) {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function csv_to_array_sjis($file) {
    $content = file_get_contents($file);
    $utf8 = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
    $lines = preg_split('/\r\n|\r|\n/', trim($utf8));
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

foreach ($tables as $category => $info) {

    $codeIndex = $info['code_index'];

    $schemaPath = $baseDir . $category . '/schema.json';
    if (!file_exists($schemaPath)) {
        echo "  [ERROR] schema missing: $schemaPath\n";
        continue;
    }
    $schema = json_decode(file_get_contents($schemaPath), true);

    $outputDir = $baseDir . $category . '/';
    ensure_dir($outputDir);

    // ★ file が配列ならそのままループ、文字列なら 1 要素配列にする
    $fileNames = is_array($info['file']) ? $info['file'] : [$info['file']];

    foreach ($fileNames as $fileName) {

        echo "\n[{$category}] processing {$fileName} (code index = {$codeIndex})...\n";

        $filePath = $rawDir . $fileName;

        if (!file_exists($filePath)) {
            echo "  [WARNING] raw file missing: $filePath\n";
            continue;
        }

        echo "  loading: $fileName\n";

        $rows = csv_to_array_sjis($filePath);
        if (count($rows) === 0) {
            echo "  [WARNING] no rows in $fileName\n";
            continue;
        }

        // ヘッダ行をスキップ
        $header = $rows[0];
        array_shift($rows);

        foreach ($rows as $cols) {

            // コード列を取得（SJIS→UTF8済み）
            $code = $cols[$codeIndex] ?? '';
            $code = trim($code);

            if ($code === '') {
                continue; // コードなし行は無視
            }

            $json = ["code" => $code];

            // --- カテゴリ別の JSON 組立（ここは仮実装のまま） ---

            if ($category === 'supplemental') {

                $json['attributes'] = [
                    "category"          => $cols[3] ?? "",
                    "sub_category"      => $cols[4] ?? "",
                    "drug_related"      => ($cols[5] ?? "") === "1",
                    "surgery_related"   => ($cols[6] ?? "") === "1",
                    "radiology_related" => ($cols[7] ?? "") === "1",
                    "requires_comment"  => ($cols[8] ?? "") === "1",
                    "requires_modifier" => ($cols[9] ?? "") === "1"
                ];

            } elseif ($category === 'inclusive') {

                // 包含関係の例：包含されるコードが複数列に並んでいる前提（仮）
                $includes = [];
                for ($i = $codeIndex + 1; $i < count($cols); $i++) {
                    $c = trim($cols[$i]);
                    if ($c !== '') $includes[] = $c;
                }
                $json['includes'] = $includes;

            } elseif ($category === 'exclusive') {

                $json['exclusive'] = [];
                for ($i = $codeIndex + 1; $i < count($cols); $i += 2) {
                    $target = trim($cols[$i] ?? '');
                    if ($target === '') continue;
                    $type = trim($cols[$i + 1] ?? 'same_day');
                    $json['exclusive'][] = [
                        "target" => $target,
                        "type"   => $type
                    ];
                }

            } elseif ($category === 'inpatient-base') {

                $json['ward_type'] = $cols[$codeIndex + 1] ?? "";

                $json['conditions'] = [
                    "required_staff" => [
                        "nurse_ratio"      => $cols[$codeIndex + 2] ?? "",
                        "doctor_presence"  => ($cols[$codeIndex + 3] ?? "") === "1"
                    ],
                    "patient_conditions" => [
                        "age"             => $cols[$codeIndex + 4] ?? "",
                        "severity_level"  => $cols[$codeIndex + 5] ?? ""
                    ]
                ];

            } elseif ($category === 'count-limits') {

                $json['count_limits'] = [];
                for ($i = $codeIndex + 1; $i < count($cols); $i += 2) {
                    $unit = trim($cols[$i] ?? '');
                    if ($unit === '') continue;
                    $max = intval($cols[$i + 1] ?? 0);
                    $json['count_limits'][] = [
                        "unit" => $unit,
                        "max"  => $max
                    ];
                }
            }

            // --- JSON 保存 ---
            $outFile = $outputDir . $code . '.json';
            save_json($outFile, $json);
        }

        echo "  -> OK: {$category} / {$fileName} complete.\n";
    }
}

echo "\n=== rule-tables R6 conversion done ===\n";
