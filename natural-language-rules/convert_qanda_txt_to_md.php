<?php
/**
 * 疑義解釈テキスト（pdftotext 出力） → C方式 Markdown 変換スクリプト
 *
 * 前提:
 *   - inputDir 配下に .txt ファイルを配置
 *   - 出力は outputDir 配下に YYYYMMDD_Q{番号}_{区分}.md 形式で生成
 *
 * 使い方（例）:
 *   php convert_qanda_txt_to_md.php
 */

mb_internal_encoding('UTF-8');

$baseDir   = __DIR__;
$inputDir  = $baseDir . '/input';   // pdftotext 出力を置く場所
$outputDir = $baseDir . '/output';  // Markdown を出力する場所

if (!is_dir($inputDir)) {
    fwrite(STDERR, "input ディレクトリがありません: {$inputDir}\n");
    exit(1);
}
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

/**
 * 全角数字やスペースを半角に寄せる
 */
function normalize_numbers_and_spaces(string $s): string {
    // 全角数字 → 半角数字
    $s = preg_replace_callback('/[０-９]/u', function ($m) {
        $code = uniord($m[0]) - uniord('０') + ord('0');
        return chr($code);
    }, $s);
    // 全角スペース → 半角スペース
    $s = str_replace(["　"], " ", $s);
    return $s;
}

/**
 * Unicode 1文字→コードポイント（簡易）
 */
function uniord($c) {
    $h = ord($c[0]);
    if ($h <= 0x7F) {
        return $h;
    } elseif ($h < 0xE0) {
        return ($h & 0x1F) << 6 | (ord($c[1]) & 0x3F);
    } elseif ($h < 0xF0) {
        return ($h & 0x0F) << 12 | (ord($c[1]) & 0x3F) << 6 | (ord($c[2]) & 0x3F);
    } else {
        return ($h & 0x07) << 18 | (ord($c[1]) & 0x3F) << 12
             | (ord($c[2]) & 0x3F) << 6 | (ord($c[3]) & 0x3F);
    }
}

/**
 * 令和◯年◯月◯日の文字列から YYYY-MM-DD を取り出す
 */
function extract_date_yyyy_mm_dd(string $text): ?string {
    // 改行少しだけ前方を見る
    $head = mb_substr($text, 0, 500);

    // 令和◯年◯月◯日 を探す
    if (!preg_match('/令和\s*([0-9０-９]+)\s*年\s*([0-9０-９]+)\s*月\s*([0-9０-９]+)\s*日/u', $head, $m)) {
        return null;
    }

    $reiwaYearRaw = normalize_numbers_and_spaces($m[1]);
    $monthRaw     = normalize_numbers_and_spaces($m[2]);
    $dayRaw       = normalize_numbers_and_spaces($m[3]);

    $reiwaYear = intval(preg_replace('/[^0-9]/', '', $reiwaYearRaw));
    $month     = intval(preg_replace('/[^0-9]/', '', $monthRaw));
    $day       = intval(preg_replace('/[^0-9]/', '', $dayRaw));

    if ($reiwaYear <= 0 || $month <= 0 || $day <= 0) {
        return null;
    }

    // 令和1年 = 2019年 → 西暦 = 2018 + 令和年
    $year = 2018 + $reiwaYear;

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * カテゴリ推定（非常に素朴なヒューリスティック）
 */
function detect_category(string $text): string {
    $head = mb_substr($text, 0, 300);

    if (mb_strpos($head, '医科') !== false) return '医科';
    if (mb_strpos($head, '歯科') !== false) return '歯科';
    if (mb_strpos($head, '調剤') !== false) return '調剤';
    if (mb_strpos($head, '訪問看護') !== false) return '訪問看護';
    if (mb_strpos($head, '共通') !== false) return '共通';

    // デフォルトは医科扱いにしておく
    return '医科';
}

/**
 * テキスト全体を「問ブロック」に分割する
 * 戻り値: array<int,array{number:int, text:string}>
 */
function split_into_questions(string $text): array {
    $lines = preg_split('/\R/u', $text);
    $blocks = [];
    $current = [
        'number' => null,
        'text_lines' => []
    ];

    $questionPattern = '/^問[ 　]*([0-9０-９]+)/u';

    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");

        if (preg_match($questionPattern, $line, $m)) {
            // 新しい問が始まる
            if ($current['number'] !== null) {
                $blocks[] = [
                    'number' => $current['number'],
                    'text'   => implode("\n", $current['text_lines'])
                ];
            }

            $numRaw = normalize_numbers_and_spaces($m[1]);
            $num = intval(preg_replace('/[^0-9]/', '', $numRaw));

            $current = [
                'number'     => $num,
                'text_lines' => [$line]
            ];
        } else {
            if ($current['number'] !== null) {
                $current['text_lines'][] = $line;
            }
        }
    }

    if ($current['number'] !== null) {
        $blocks[] = [
            'number' => $current['number'],
            'text'   => implode("\n", $current['text_lines'])
        ];
    }

    return $blocks;
}

/**
 * 問ブロックから 質問/回答 を分離
 * 戻り値: array{question:string, answer:string}
 */
function split_question_answer(string $blockText): array {
    // 一旦ブロック全体の中で「（答）」を探す
    $pos = mb_strpos($blockText, '（答）');

    if ($pos === false) {
        // 予備：行頭の「答」単独行を探す
        $lines = preg_split('/\R/u', $blockText);
        $idxAnswer = null;
        foreach ($lines as $i => $line) {
            $trim = preg_replace('/[ 　]/u', '', $line);
            if ($trim === '答' || $trim === '（答）') {
                $idxAnswer = $i;
                break;
            }
        }
        if ($idxAnswer === null) {
            // 分割できない場合、全部質問側に置いておく
            return [
                'question' => trim($blockText),
                'answer'   => ''
            ];
        }

        $qLines = array_slice($lines, 0, $idxAnswer);
        $aLines = array_slice($lines, $idxAnswer + 1);

        return [
            'question' => trim(implode("\n", $qLines)),
            'answer'   => trim(implode("\n", $aLines))
        ];
    }

    $question = mb_substr($blockText, 0, $pos);
    $answer   = mb_substr($blockText, $pos);

    // answer から「（答）」を削る
    $answer = preg_replace('/^.*?（答）/us', '', $answer);

    return [
        'question' => trim($question),
        'answer'   => trim($answer)
    ];
}

/**
 * Markdown を生成
 */
function build_markdown(array $meta): string {
    $qNum     = $meta['number'];
    $dateYmd  = $meta['date'];        // YYYY-MM-DD
    $srcFile  = $meta['source_file']; // 元の txt or pdf 名
    $category = $meta['category'];
    $question = $meta['question'];
    $answer   = $meta['answer'];

    // ファイル名には使わないが、一応 title フィールド用意可能
    $title = "Q{$qNum}";

    $codesJson = '[]'; // ここは後で手作業 or 別処理で埋める前提

    $lines = [];

    $lines[] = "# Q{$qNum}";
    $lines[] = "date: {$dateYmd}";
    $lines[] = "source_file: {$srcFile}";
    $lines[] = "category: {$category}";
    $lines[] = "codes: {$codesJson}";
    $lines[] = "tags: [\"疑義解釈\"]";
    $lines[] = "";
    $lines[] = "## 質問";
    $lines[] = $question !== '' ? $question : '(未抽出)';
    $lines[] = "";
    $lines[] = "## 回答";
    $lines[] = $answer !== '' ? $answer : '(未抽出)';
    $lines[] = "";
    $lines[] = "## Notes";
    $lines[] = "- 自動変換により生成。必要に応じて人手で補正してください。";

    return implode("\n", $lines) . "\n";
}

/**
 * メイン処理
 */

$txtFiles = glob($inputDir . '/*.txt');
if (!$txtFiles) {
    fwrite(STDERR, "input ディレクトリに .txt ファイルがありません。\n");
    exit(1);
}

foreach ($txtFiles as $txtPath) {
    $baseName = basename($txtPath);
    echo "Processing: {$baseName}\n";

    $text = file_get_contents($txtPath);
    if ($text === false || $text === '') {
        echo "  -> 空 or 読み込み不可、スキップ\n";
        continue;
    }

    // 改行正規化
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $dateYmd = extract_date_yyyy_mm_dd($text);
    if ($dateYmd === null) {
        echo "  -> 日付が抽出できませんでした（令和表記を探索できず）。YYYYMMDD を 00000000 として処理します。\n";
        $dateYmd = '0000-00-00';
        $dateForName = '00000000';
    } else {
        $dateForName = str_replace('-', '', $dateYmd);
    }

    $category = detect_category($text);

    $questions = split_into_questions($text);
    if (empty($questions)) {
        echo "  -> 問◯ パターンが見つからなかったためスキップ\n";
        continue;
    }

    foreach ($questions as $q) {
        $qNum = $q['number'];
        $blockText = $q['text'];

        $qa = split_question_answer($blockText);

        $md = build_markdown([
            'number'      => $qNum,
            'date'        => $dateYmd,
            'source_file' => $baseName,
            'category'    => $category,
            'question'    => $qa['question'],
            'answer'      => $qa['answer'],
        ]);

        $fileName = sprintf('%s_Q%d_%s.md', $dateForName, $qNum, $category);
        $outPath  = $outputDir . '/' . $fileName;

        file_put_contents($outPath, $md);
        echo "  -> wrote {$fileName}\n";
    }

    echo "Done: {$baseName}\n\n";
}

echo "All done.\n";
