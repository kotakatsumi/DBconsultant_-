<?php
// Webhook を「SaaS のふりをして」送るスクリプト
//   php send_webhook.php             正しく署名して送る
//   php send_webhook.php tamper      署名後に本文を改ざんして送る
//   php send_webhook.php old         10分前の時刻で署名して送る（リプレイの再現）
//   php send_webhook.php nosecret    間違った秘密鍵で署名して送る（攻撃者）
$mode   = $argv[1] ?? 'ok';
$secret = $mode === 'nosecret' ? 'attacker-guess' : 'lab-webhook-secret';
$ts     = (string)($mode === 'old' ? time() - 600 : time());
$body   = json_encode(['event' => 'deal.won', 'deal_id' => 501, 'amount' => 300000], JSON_UNESCAPED_UNICODE);
$sig    = hash_hmac('sha256', $ts . $body, $secret);
if ($mode === 'tamper') {
    $body = str_replace('300000', '30000000', $body);   // 署名した後で金額を書き換える
}
$ctx = stream_context_create(['http' => [
    'method' => 'POST', 'ignore_errors' => true, 'content' => $body,
    'header' => "Content-Type: application/json\r\nX-Timestamp: $ts\r\nX-Signature: $sig\r\n",
]]);
echo "送信: $body\n";
echo "応答: ", file_get_contents(getenv('TARGET') ?: 'http://127.0.0.1:8000/webhook', false, $ctx);
