<?php
// 模擬 kintone REST API（学習用の簡易版）
//   GET /k/v1/records.json?app=12&query=...
//   - X-Cybozu-API-Token ヘッダーで認証
//   - query は次の書き方だけをサポート：
//       [更新日時 >= "2026-01-01T00:00:00Z" and] $id > 100 order by $id asc limit 500
//   - 1回の取得は最大 500 件（本物の kintone と同じ）
//   - FAIL_RATE の確率で 429 Too Many Requests を返す（レート制限の再現）

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
error_log(sprintf('%s %s', $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']));

if ($path !== '/k/v1/records.json' || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(404, ['code' => 'NOT_FOUND', 'message' => '指定したAPIは存在しません。']);
}

// --- 認証 ---
$token = $_SERVER['HTTP_X_CYBOZU_API_TOKEN'] ?? '';
if (!hash_equals((string)getenv('API_TOKEN'), $token)) {
    respond(401, ['code' => 'GAIA_IA02', 'message' => 'APIトークンが不正です。']);
}

// --- レート制限の再現 ---
$failRate = (float)(getenv('FAIL_RATE') ?: 0);
if (mt_rand() / mt_getrandmax() < $failRate) {
    respond(429, ['code' => 'TOO_MANY_REQUESTS', 'message' => 'リクエストが多すぎます。'], ['Retry-After' => '1']);
}

// --- クエリの解釈 ---
$query = $_GET['query'] ?? '';
$limit = 100;
if (preg_match('/limit\s+(\d+)/', $query, $m)) {
    $limit = (int)$m[1];
}
if ($limit > 500) {
    respond(400, ['code' => 'CB_VA01', 'message' => 'limit は 500 以下にしてください。']);
}
$afterId = 0;
if (preg_match('/\$id\s*>\s*(\d+)/', $query, $m)) {
    $afterId = (int)$m[1];
}
$updatedSince = null;
if (preg_match('/更新日時\s*>=\s*"([^"]+)"/u', $query, $m)) {
    $updatedSince = strtotime($m[1]);
}

// --- データの読み込みと絞り込み ---
$file = __DIR__ . '/../data/records.json';
if (!file_exists($file)) {
    respond(500, ['code' => 'NO_DATA', 'message' => 'data/records.json がありません。generate.php を先に実行してください。']);
}
$records = json_decode(file_get_contents($file), true);

$filtered = array_filter($records, function ($r) use ($afterId, $updatedSince) {
    if ((int)$r['$id']['value'] <= $afterId) {
        return false;
    }
    if ($updatedSince !== null && strtotime($r['更新日時']['value']) < $updatedSince) {
        return false;
    }
    return true;
});
usort($filtered, fn($a, $b) => (int)$a['$id']['value'] <=> (int)$b['$id']['value']);
$page = array_slice($filtered, 0, $limit);

// fields[] が指定されていれば、そのフィールドだけ返す（削除の突き合わせで $id だけ取るのに使う）
if (!empty($_GET['fields'])) {
    $fields = (array)$_GET['fields'];
    $page = array_map(fn($r) => array_intersect_key($r, array_flip($fields)), $page);
}

respond(200, ['records' => array_values($page), 'totalCount' => null]);
