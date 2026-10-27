<?php
// =====================================================================
// kintone → PostgreSQL 同期処理（第4章 ハンズオン）
//
//   php sync/sync.php          差分同期（前回以降に更新されたレコードだけ）
//   php sync/sync.php --full   全件の ID を突き合わせて、削除されたレコードを論理削除
//
// 学ぶポイント
//   - シーク方式のページネーション（単元4-1）
//   - 429 / 5xx の再試行と指数バックオフ（単元4-1）
//   - 差分取得の「しおり」と重なり（単元4-1）
//   - 表記の正規化とエラー行の記録（単元4-2）
//   - UPSERT による冪等な保存と、古いデータで上書きしない工夫（単元4-3）
// =====================================================================

declare(strict_types=1);

const PAGE_SIZE      = 500;     // kintone の1回の上限
const OVERLAP_SEC    = 300;     // 差分取得の重なり（5分）
const MAX_RETRIES    = 5;
const SOURCE         = 'kintone';

// --- 設定は環境変数から読む（本番では Secret Manager の値を環境変数として渡す） ---
function env(string $key): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        fwrite(STDERR, "環境変数 {$key} が設定されていません\n");
        exit(2);
    }
    return $v;
}

function logInfo(string $msg, array $ctx = []): void
{
    // トークンなどの秘密情報はログに出さない
    echo json_encode(['time' => date('c'), 'msg' => $msg] + $ctx, JSON_UNESCAPED_UNICODE), "\n";
}

// ---------------------------------------------------------------------
// HTTP：再試行つきで kintone API を呼ぶ
// ---------------------------------------------------------------------
function kintoneGet(array $params): array
{
    $url = rtrim(env('KINTONE_BASE_URL'), '/') . '/k/v1/records.json?' . http_build_query($params);

    for ($attempt = 1; ; $attempt++) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => 'X-Cybozu-API-Token: ' . env('KINTONE_API_TOKEN'),  // URL ではなくヘッダーで送る
            'timeout'       => 30,
            'ignore_errors' => true,   // 4xx/5xx でも本文を読む
        ]]);
        $body    = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header ?? [];
        $status  = isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m) ? (int)$m[1] : 0;

        if ($status === 200) {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        // 再試行してよいエラー：429（送りすぎ）、5xx（相手の一時的な障害）、0（通信失敗）
        $retryable = $status === 429 || $status >= 500 || $status === 0;
        if (!$retryable || $attempt >= MAX_RETRIES) {
            // 401 や 400 は何度やっても同じなので、すぐに止めて人間に知らせる
            throw new RuntimeException("kintone API エラー status={$status} body={$body}");
        }

        $wait = retryAfter($headers) ?? (2 ** ($attempt - 1));   // 1, 2, 4, 8 秒…
        $wait += mt_rand(0, 1000) / 1000;                        // ジッター
        logInfo('再試行します', ['status' => $status, 'attempt' => $attempt, 'wait_sec' => round($wait, 2)]);
        usleep((int)($wait * 1_000_000));
    }
}

function retryAfter(array $headers): ?float
{
    foreach ($headers as $h) {
        if (stripos($h, 'Retry-After:') === 0) {
            return (float)trim(substr($h, 12));
        }
    }
    return null;
}

// ---------------------------------------------------------------------
// 変換：kintone のレコード → customers テーブルの1行
// ---------------------------------------------------------------------
function normalizeName(string $s): string
{
    $s = mb_convert_kana(trim($s), 'KVas');                    // 半角カナ→全角、全角英数→半角、全角空白→半角
    $s = str_replace(['㈱', '（株）', '(株)', '株式会社'], '', $s); // 比較用に法人格を除く
    return preg_replace('/\s+/u', '', $s);
}

/** @return array{0: ?array, 1: ?string}  [変換後の行, エラー理由] */
function mapRecord(array $r): array
{
    foreach (['$id', '会社名', '電話番号', '住所', '更新日時'] as $f) {
        if (!array_key_exists($f, $r)) {
            // フィールド名が変わった可能性：黙って空欄にせず、処理全体を止める（単元4-1）
            throw new RuntimeException("想定外のフィールド構成です（{$f} がありません）。kintone アプリの設定変更を確認してください。");
        }
    }
    $name  = trim(mb_convert_kana($r['会社名']['value'], 'KVs'));
    $phone = trim(mb_convert_kana($r['電話番号']['value'], 'as'));

    if ($name === '') {
        return [null, '会社名が空です'];
    }
    if ($phone !== '' && !preg_match('/^[0-9\-]+$/', $phone)) {
        return [null, "電話番号の形式が不正です（{$phone}）"];
    }

    return [[
        'source_id'         => $r['$id']['value'],
        'name'              => $name,
        'name_normalized'   => normalizeName($name),
        'phone'             => $phone ?: null,
        'phone_digits'      => $phone ? preg_replace('/\D/', '', $phone) : null,
        'address'           => trim($r['住所']['value']) ?: null,
        'source_updated_at' => $r['更新日時']['value'],
    ], null];
}

// ---------------------------------------------------------------------
// 保存：UPSERT（何回実行しても同じ結果になる）
// ---------------------------------------------------------------------
function upsert(PDO $db, array $row): int
{
    static $stmt = null;
    $stmt ??= $db->prepare(<<<SQL
        INSERT INTO customers
            (source, source_id, name, name_normalized, phone, phone_digits, address, source_updated_at)
        VALUES
            ('kintone', :source_id, :name, :name_normalized, :phone, :phone_digits, :address, :source_updated_at)
        ON CONFLICT (source, source_id) DO UPDATE
        SET name              = EXCLUDED.name,
            name_normalized   = EXCLUDED.name_normalized,
            phone             = EXCLUDED.phone,
            phone_digits      = EXCLUDED.phone_digits,
            address           = EXCLUDED.address,
            source_updated_at = EXCLUDED.source_updated_at,
            deleted_at        = NULL,
            updated_at        = now()
        WHERE customers.source_updated_at < EXCLUDED.source_updated_at   -- 古いデータで上書きしない
        SQL);
    $stmt->execute($row);
    return $stmt->rowCount();   // 0 = 変更なし（同じ or 古いデータ）
}

function recordError(PDO $db, array $record, string $reason): void
{
    $db->prepare('INSERT INTO sync_errors (source, source_id, reason, payload) VALUES (:s, :id, :reason, :payload)')
       ->execute([
           's'       => SOURCE,
           'id'      => $record['$id']['value'] ?? null,
           'reason'  => $reason,
           'payload' => json_encode($record, JSON_UNESCAPED_UNICODE),
       ]);
}

// ---------------------------------------------------------------------
// 差分同期
// ---------------------------------------------------------------------
function incrementalSync(PDO $db): void
{
    $watermark = $db->query("SELECT watermark FROM sync_state WHERE source = 'kintone'")->fetchColumn();
    $since     = $watermark ? strtotime($watermark) - OVERLAP_SEC : 0;
    $sinceIso  = gmdate('Y-m-d\TH:i:s\Z', $since);
    logInfo('差分同期を開始', ['since' => $sinceIso]);

    $lastId = 0;
    $maxUpdated = $watermark ?: null;
    $stats = ['fetched' => 0, 'changed' => 0, 'unchanged' => 0, 'errors' => 0];

    do {
        // シーク方式：「前のページの最後の $id より大きいもの」を $id 順に取る
        $res = kintoneGet([
            'app'   => env('KINTONE_APP_ID'),
            'query' => sprintf('更新日時 >= "%s" and $id > %d order by $id asc limit %d', $sinceIso, $lastId, PAGE_SIZE),
        ]);
        $records = $res['records'];

        $db->beginTransaction();       // ページ単位でトランザクション
        foreach ($records as $r) {
            [$row, $error] = mapRecord($r);
            if ($error !== null) {
                recordError($db, $r, $error);
                $stats['errors']++;
                continue;
            }
            upsert($db, $row) > 0 ? $stats['changed']++ : $stats['unchanged']++;
            if ($maxUpdated === null || strtotime($row['source_updated_at']) > strtotime($maxUpdated)) {
                $maxUpdated = $row['source_updated_at'];
            }
        }
        $db->commit();

        $stats['fetched'] += count($records);
        if ($records) {
            $lastId = (int)end($records)['$id']['value'];
        }
        logInfo('ページを保存', ['count' => count($records), 'last_id' => $lastId]);
    } while (count($records) === PAGE_SIZE);   // 500件未満なら最後のページ

    // 保存に成功した後で「しおり」を進める（途中で失敗したら、次回は同じ位置からやり直す）
    if ($maxUpdated !== null) {
        $db->prepare(<<<SQL
            INSERT INTO sync_state (source, watermark, last_run_at) VALUES ('kintone', :w, now())
            ON CONFLICT (source) DO UPDATE SET watermark = EXCLUDED.watermark, last_run_at = now()
            SQL)->execute(['w' => $maxUpdated]);
    }
    logInfo('差分同期が完了', $stats + ['watermark' => $maxUpdated]);
}

// ---------------------------------------------------------------------
// 全件突き合わせ：kintone から消えたレコードを論理削除する
// ---------------------------------------------------------------------
function reconcileDeletes(PDO $db): void
{
    logInfo('削除の突き合わせを開始');
    $ids = [];
    $lastId = 0;
    do {
        $res = kintoneGet([
            'app'    => env('KINTONE_APP_ID'),
            'query'  => sprintf('$id > %d order by $id asc limit %d', $lastId, PAGE_SIZE),
            'fields' => ['$id'],
        ]);
        foreach ($res['records'] as $r) {
            $ids[] = $r['$id']['value'];
        }
        if ($res['records']) {
            $lastId = (int)end($res['records'])['$id']['value'];
        }
    } while (count($res['records']) === PAGE_SIZE);

    // 安全装置：API の不具合で空が返ってきたときに、全件を削除扱いにしない
    $active = (int)$db->query("SELECT count(*) FROM customers WHERE source = 'kintone' AND deleted_at IS NULL")->fetchColumn();
    if ($active > 0 && count($ids) < $active * 0.5) {
        throw new RuntimeException(sprintf('kintone 側の件数（%d）が DB 側（%d）の半分未満です。誤削除を防ぐため中止します。', count($ids), $active));
    }

    $stmt = $db->prepare(<<<SQL
        UPDATE customers SET deleted_at = now(), updated_at = now()
        WHERE source = 'kintone' AND deleted_at IS NULL
          AND NOT (source_id = ANY (CAST(:ids AS text[])))
        SQL);
    $stmt->execute(['ids' => '{' . implode(',', $ids) . '}']);
    logInfo('削除の突き合わせが完了', ['kintone_count' => count($ids), 'marked_deleted' => $stmt->rowCount()]);
}

// ---------------------------------------------------------------------
// メイン
// ---------------------------------------------------------------------
try {
    $db = new PDO(env('DB_DSN'), env('DB_USER'), env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    in_array('--full', $argv, true) ? reconcileDeletes($db) : incrementalSync($db);
    exit(0);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logInfo('同期に失敗しました', ['error' => $e->getMessage()]);
    exit(1);   // 終了コード 1 → Cloud Run jobs が失敗として扱い、アラートの対象になる
}
