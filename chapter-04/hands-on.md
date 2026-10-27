# 第4章 ハンズオン：模擬 kintone API から PostgreSQL へ同期する

> **目的**：第4章で学んだ「ページネーション・再試行・差分取得・正規化・冪等な保存・削除の検知」を、動くコードで確かめる。
> **所要時間**：2〜3時間
> **必要なもの**：Docker Desktop
> **使うファイル**：[`lab/`](./lab/) フォルダ一式

```text
lab/
├── docker-compose.yml   PostgreSQL ＋ 模擬 kintone API ＋ 同期処理
├── Dockerfile           PHP 8.3（pdo_pgsql 入り、一般ユーザーで実行）
├── sql/01_schema.sql    取り込み先テーブル、しおり、エラー行、同期専用ロール
├── mock/generate.php    模擬 kintone のレコードを生成（表記ゆれ・不正値入り）
├── mock/kintone.php     模擬 kintone API（認証・500件上限・ランダムな 429）
└── sync/sync.php        同期処理本体（この章の主役）
```

> 本物の kintone を使わないのは、誰でも費用ゼロで、**わざと障害を起こして**挙動を確かめられるようにするためです。本物の kintone の開発者向け環境で試す場合も、`sync.php` の考え方はそのまま使えます（認証ヘッダー名・クエリの書き方は本物に合わせてあります）。

---

## 準備

```bash
cd chapter-04/lab
docker compose build
docker compose up -d db mock-api
docker compose run --rm sync php mock/generate.php     # 1,234 件の顧客レコードを生成
```

> Linux で `data/` への書き込みが Permission denied になる場合は、`chmod 777 data` ではなく `sudo chown 1000:1000 data` としてください（コンテナ内のユーザー ID が 1000 のため。単元1-3）。

---

## 演習1：API を手で叩いてみる（単元4-1）

```bash
# 正しいトークン：最初の3件
curl -s -H "X-Cybozu-API-Token: lab-token-readonly" \
  --get "http://127.0.0.1:8080/k/v1/records.json" \
  --data-urlencode 'app=12' \
  --data-urlencode 'query=$id > 0 order by $id asc limit 3'

# 間違ったトークン → 401
curl -s -i -H "X-Cybozu-API-Token: wrong" "http://127.0.0.1:8080/k/v1/records.json?app=12"

# 何度か実行すると、ときどき 429 と Retry-After ヘッダーが返ってくる
for i in $(seq 1 10); do
  curl -s -o /dev/null -w "%{http_code}\n" -H "X-Cybozu-API-Token: lab-token-readonly" \
    "http://127.0.0.1:8080/k/v1/records.json?app=12"
done
```

**観察ポイント**：JSON の各フィールドが `{"type": ..., "value": ...}` という kintone 特有の形になっていること。会社名に `(株)` `㈱` `株式会社` や半角カナが混在していること。

---

## 演習2：コードを読む

実行する前に [`lab/sync/sync.php`](./lab/sync/sync.php) を読み、次の問いに答えられるようにしてください（回答ファイルに書く）。

1. ページネーションはオフセット方式・シーク方式・カーソル方式のどれですか？ どの行でそれが分かりますか？
2. 再試行するのはどんな HTTP ステータスのときですか？ 401 のときはどうなりますか？
3. 「しおり（watermark）」は、いつ、どんな値で更新されますか？ なぜ処理の最初ではないのですか？
4. UPSERT の SQL で、`WHERE customers.source_updated_at < EXCLUDED.source_updated_at` が何を防いでいますか？

---

## 演習3：初回の同期（全件取り込み）

```bash
docker compose run --rm sync php sync/sync.php
```

出力例：

```text
{"msg":"差分同期を開始","since":"1970-01-01T00:00:00Z"}
{"msg":"再試行します","status":429,"attempt":1,"wait_sec":1.91}
{"msg":"ページを保存","count":500,"last_id":500}
{"msg":"ページを保存","count":500,"last_id":1000}
{"msg":"ページを保存","count":234,"last_id":1234}
{"msg":"差分同期が完了","fetched":1234,"changed":1222,"unchanged":0,"errors":12,...}
```

- 429 が返ってきても、待ってから再試行して最後まで取り込めていることを確認します。
- `errors: 12` は、電話番号が不正な行です。黙って捨てずに記録されています。

DB を確認します。

```bash
docker exec -it lab4-postgres psql -U postgres -d salesdb
```

```sql
SELECT count(*) FROM customers;
SELECT source_id, reason FROM sync_errors LIMIT 5;
SELECT * FROM sync_state;

-- 表記ゆれ：元の名前と、正規化した名前
SELECT name, name_normalized FROM customers WHERE name_normalized = '山田商店1';
```

---

## 演習4：冪等性を確かめる（単元4-3）

もう一度、同じ同期を実行します。

```bash
docker compose run --rm sync php sync/sync.php
docker compose run --rm sync php sync/sync.php
```

- 2回目以降は「しおり」の5分前からしか取らないので、取得件数がごくわずか（`fetched` が 0〜数件）になる。
- 取得したレコードも `unchanged`（変更なし）になり、**件数は増えない**。

**さらに「しおり」を消して、全件を取り直してみます。**

```sql
-- psql で（postgres ユーザーで接続中）
DELETE FROM sync_state;
```

```bash
docker compose run --rm sync php sync/sync.php
```

1,234 件を取り直しても `changed: 0`、`customers` の件数も変わらないことを確認してください。**何度やり直しても同じ結果になる＝冪等**です。

---

## 演習5：更新を差分で取り込む（単元4-1）

`lab/data/records.json` をエディタで開き、`"$id": {"value": "5"}` のレコードを次のように書き換えます。

- `電話番号` の `value` を `03-9999-0000` に
- `更新日時` の `value` を**現在の UTC 時刻**に（例：`2026-10-27T07:40:00Z`。日本時間から9時間引く）

```bash
docker compose run --rm sync php sync/sync.php
```

- `fetched` が数件、`changed: 1` になることを確認します。
- `SELECT phone, source_updated_at FROM customers WHERE source_id = '5';` で反映を確認します。

---

## 演習6：古いデータが後から届いた場合（単元4-3）

Webhook の再送などで、**古い内容が後から届いた**状況を、psql で手動で再現します。

```sql
-- source_id = 5 の、1年前の古い内容で UPSERT を試みる
INSERT INTO customers (source, source_id, name, name_normalized, phone, phone_digits, address, source_updated_at)
VALUES ('kintone', '5', '古い会社名', '古い会社名', '03-0000-0000', '0300000000', NULL, '2025-10-01T00:00:00Z')
ON CONFLICT (source, source_id) DO UPDATE
SET name = EXCLUDED.name, phone = EXCLUDED.phone, source_updated_at = EXCLUDED.source_updated_at
WHERE customers.source_updated_at < EXCLUDED.source_updated_at;
-- → INSERT 0 0（何も変わらない）

SELECT name, phone FROM customers WHERE source_id = '5';   -- 新しい内容のまま
```

`WHERE` 句を外して同じ SQL を実行すると、古い内容で上書きされてしまうことも確かめてみましょう（確認後、`DELETE FROM sync_state;` してから同期を再実行すると元に戻ります）。

---

## 演習7：削除を検知する（単元4-1・4-3）

`lab/data/records.json` から、`$id` が `10` と `11` のレコードを丸ごと削除して保存します。

```bash
# 差分同期では、削除は検知できない
docker compose run --rm sync php sync/sync.php
```

```sql
SELECT source_id, deleted_at FROM customers WHERE source_id IN ('10', '11');   -- まだ deleted_at は空
```

```bash
# 全件突き合わせ
docker compose run --rm sync php sync/sync.php --full
```

```sql
SELECT source_id, deleted_at FROM customers WHERE source_id IN ('10', '11');   -- deleted_at に日時が入った
```

行は物理的に消えず、**論理削除**されていることを確認してください。

**安全装置を試す**：`records.json` の中身を `[]`（空の配列）にして `--full` を実行すると、「件数が半分未満なので中止します」というエラーで止まります。API の不具合やアプリの誤設定で、**全顧客が削除扱いになる事故を防ぐ**ための仕組みです。確認後は `generate.php` を再実行してデータを戻してください。

---

## 演習8：失敗のさせ方を変えてみる

**認証エラー（再試行しない）**

```bash
docker compose run --rm -e KINTONE_API_TOKEN=wrong sync php sync/sync.php
echo "終了コード: $?"
```

再試行せずにすぐ止まり、終了コード `1` で終わることを確認します。Cloud Run jobs では、終了コードが 0 以外だと「失敗」として扱われ、アラートの対象になります（第7章）。

**レート制限が厳しい場合（再試行の上限）**

`docker-compose.yml` の `FAIL_RATE` を `"0.9"` に変えて `docker compose up -d mock-api` で再起動し、`DELETE FROM sync_state;` の後で同期を実行します。

- 再試行の待ち時間が 1 → 2 → 4 → 8 秒と伸びていくこと（指数バックオフ）
- 5回失敗すると諦めて終了コード 1 で止まること
- **途中のページまでは保存されているが、しおりは進んでいない**ので、`FAIL_RATE` を戻して再実行すれば正しく追いつくこと

を確認してください。終わったら `FAIL_RATE` を `"0.2"` に戻します。

---

## 後片付け

```bash
docker compose down -v
```

---

## 考察課題（回答ファイルに書く）

1. 演習2の4つの問いへの回答。
2. 演習8で、途中まで保存されたページがあるのに、再実行しても重複が起きないのはなぜですか？
3. 本物の kintone で、このアプリの他に、同じドメインで5つの連携サービスが動いているとします。この同期処理を「10分おき」に動かす設計にする前に、確認すべきことを挙げてください。
4. `sync.php` は会社名の正規化（`name_normalized`）までは行っていますが、名寄せ（同じ会社の統合）は行っていません。`name_normalized` と `phone_digits` を使って「同じ会社の可能性があるレコード」を探す SQL を書いてください。
