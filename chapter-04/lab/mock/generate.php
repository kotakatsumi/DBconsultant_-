<?php
// 模擬 kintone の「顧客管理アプリ」のレコードを生成して data/records.json に保存する。
// 表記ゆれ・不正な値をわざと混ぜている（単元4-2）。
// 使い方: php mock/generate.php [件数]

$count = (int)($argv[1] ?? 1234);
mt_srand(42); // 毎回同じデータになるように固定

$names  = ['山田商店', '田中工業', '高橋製作所', '佐藤商事', '伊藤電機', 'ミドリ物産', '中村建設', 'サクラ食品'];
$styles = [
    fn($n) => $n,
    fn($n) => '株式会社' . $n,
    fn($n) => '(株)' . $n,
    fn($n) => '㈱' . $n,
    fn($n) => ' ' . $n . ' ',                      // 前後の空白
    fn($n) => mb_convert_kana($n, 'k'),            // 全角カナ→半角カナ（漢字はそのまま）
];
$prefs = ['東京都港区', '大阪府大阪市北区', '愛知県名古屋市中区', '神奈川県横浜市西区', '福岡県福岡市博多区'];

$base = strtotime('2026-01-01T00:00:00Z');
$records = [];
for ($i = 1; $i <= $count; $i++) {
    $name  = $styles[mt_rand(0, count($styles) - 1)]($names[mt_rand(0, count($names) - 1)] . ($i % 50));
    $phone = sprintf('0%d-%04d-%04d', mt_rand(3, 9), mt_rand(0, 9999), mt_rand(0, 9999));
    if ($i % 97 === 0) {
        $phone = '電話番号不明';                   // 不正な値（エラー行になる）
    }
    $updated = $base + mt_rand(0, 270 * 86400);
    $records[] = [
        '$id'      => ['type' => 'RECORD_NUMBER', 'value' => (string)$i],
        '会社名'   => ['type' => 'SINGLE_LINE_TEXT', 'value' => $name],
        '電話番号' => ['type' => 'SINGLE_LINE_TEXT', 'value' => $phone],
        '住所'     => ['type' => 'SINGLE_LINE_TEXT', 'value' => $prefs[mt_rand(0, count($prefs) - 1)] . mt_rand(1, 9) . '-' . mt_rand(1, 30)],
        '更新日時' => ['type' => 'UPDATED_TIME', 'value' => gmdate('Y-m-d\TH:i:s\Z', $updated)],
    ];
}

$path = __DIR__ . '/../data/records.json';
file_put_contents($path, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "{$count} 件のレコードを {$path} に保存しました\n";
