<?php
// =====================================================================
// 第5章 ハンズオン用：わざと脆弱なエンドポイント（/vuln/...）と、
// 対策済みのエンドポイント（/safe/...）を並べた学習用アプリ。
// ★ 学習専用。絶対にインターネットに公開しないこと。
//
// 起動: php -S 127.0.0.1:8000 app.php
// 「ログイン中の利用者」は X-User ヘッダーで擬似的に指定する（alice / bob）
// =====================================================================
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

const WEBHOOK_SECRET = 'lab-webhook-secret';   // 本番では Secret Manager から読む

// ---------------- DB の準備（SQLite） ----------------
$dbFile = sys_get_temp_dir() . '/ch5-lab.sqlite';
$fresh  = !file_exists($dbFile);
$db = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($fresh) {
    $db->exec(<<<SQL
        CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, role TEXT, department_id INTEGER);
        INSERT INTO users VALUES (1, 'alice', '03-1111-1111', 'staff', 1), (2, 'bob', '06-2222-2222', 'staff', 2);
        CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, department_id INTEGER);
        INSERT INTO customers VALUES
          (1001, '山田商店',   '03-1000-0001', 1),
          (1002, '田中工業',   '06-1000-0002', 2),
          (1003, '高橋製作所', '052-100-0003', 2),
          (1004, '佐藤商事',   '03-1000-0004', 1);
        CREATE TABLE webhook_events (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, received_at TEXT);
        SQL);
}

function out(int $status, $data): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n"; exit; }

function currentUser(PDO $db): array {
    $stmt = $db->prepare('SELECT * FROM users WHERE name = ?');
    $stmt->execute([$_SERVER['HTTP_X_USER'] ?? '']);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: out(401, ['error' => 'ログインしていません（X-User ヘッダーに alice か bob を指定）']);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

switch ("$method $path") {

// ---------------- 演習1：SQL インジェクション ----------------
case 'GET /vuln/search':
    $name = $_GET['name'] ?? '';
    $sql  = "SELECT id, name, phone FROM customers WHERE name = '$name'";      // ✗ 文字列連結
    out(200, ['sql' => $sql, 'rows' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);

case 'GET /safe/search':
    $stmt = $db->prepare('SELECT id, name, phone FROM customers WHERE name = ?'); // ✓ プレースホルダ
    $stmt->execute([$_GET['name'] ?? '']);
    out(200, ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

// ---------------- 演習2：BOLA / IDOR ----------------
case 'GET /vuln/customer':
    currentUser($db);                                                          // ✗ ログインの確認だけ
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$_GET['id'] ?? 0]);
    out(200, $stmt->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'not found']);

case 'GET /safe/customer':
    $user = currentUser($db);
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$_GET['id'] ?? 0]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['department_id'] !== (int)$user['department_id']) {  // ✓ 認可の確認
        out(404, ['error' => 'not found']);   // 403 ではなく 404：存在するかどうかも教えない
    }
    out(200, $row);

// ---------------- 演習3：マスアサインメント ----------------
case 'POST /vuln/profile':
    $user  = currentUser($db);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $sets  = []; $vals = [];
    foreach ($input as $col => $val) {                                         // ✗ 送られた項目を全部更新
        if (!preg_match('/^[a-z_]+$/', $col)) continue;
        $sets[] = "$col = ?"; $vals[] = $val;
    }
    if ($sets) { $vals[] = $user['id']; $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals); }
    out(200, currentUser($db));

case 'POST /safe/profile':
    $user  = currentUser($db);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $allowed = array_intersect_key($input, array_flip(['name', 'phone']));    // ✓ 許可リスト
    $sets = []; $vals = [];
    foreach ($allowed as $col => $val) { $sets[] = "$col = ?"; $vals[] = $val; }
    if ($sets) { $vals[] = $user['id']; $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals); }
    out(200, currentUser($db));

// ---------------- 演習4：Webhook の署名検証 ----------------
case 'POST /webhook':
    $raw = file_get_contents('php://input');
    $ts  = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
        out(401, ['error' => '時刻が不正、または古すぎます（リプレイの疑い）']);
    }
    $expected = hash_hmac('sha256', $ts . $raw, WEBHOOK_SECRET);
    if (!hash_equals($expected, $sig)) {
        out(401, ['error' => '署名が一致しません']);
    }
    $db->prepare("INSERT INTO webhook_events (body, received_at) VALUES (?, datetime('now'))")->execute([$raw]);
    out(200, ['status' => 'accepted']);

case 'POST /reset':
    @unlink($dbFile);
    out(200, ['status' => 'reset']);

default:
    out(404, ['error' => 'not found', 'path' => $path]);
}
