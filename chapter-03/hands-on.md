# 第3章 ハンズオン：PostgreSQL で「正規化・インデックス・トランザクション・権限」を体験する

> **目的**：Excel の生データを正規化したテーブルに取り込み、第3章の各単元の仕組みを実際に動かして確かめる。
> **所要時間**：2〜3時間
> **必要なもの**：MacBook ＋ Docker Desktop（[SETUP-MAC.md](../SETUP-MAC.md) の手順1〜5を済ませ、Docker Desktop を起動しておく）
> **使うファイル**：[`lab/`](./lab/) フォルダ一式

```text
lab/
├── docker-compose.yml      PostgreSQL 16 の起動設定
├── data/orders_raw.csv     Excel から書き出した想定の受注データ（表記ゆれあり）
├── sql/01_schema.sql       正規化したスキーマ ＋ CSV の読み込み（起動時に自動実行）
├── sql/02_roles.sql        ロール分離と行レベルセキュリティ（起動時に自動実行）
└── solutions/normalize.sql 演習2 の解答例
```

---

## 準備：起動と接続

```bash
cd ~/dev/DBconsultant_-/chapter-03/lab
docker compose up -d
docker compose logs -f db     # "database system is ready to accept connections" が出たら control + C で抜ける
```

管理者として接続します。

```bash
docker exec -it lab-postgres psql -U postgres -d salesdb
```

> `docker-compose.yml` で `ports` を `127.0.0.1:5432:5432` にしている点に注目してください。`0.0.0.0` にすると、カフェなど同じ Wi-Fi に接続している他人からも、あなたの Mac の DB に接続できてしまいます（単元2-1）。

> **`port is already allocated` と表示されたら**：Mac に別の PostgreSQL（Postgres.app や Homebrew の postgresql）が起動していて、5432 番ポートを使っています。[SETUP-MAC.md の「よくあるトラブル」](../SETUP-MAC.md#よくあるトラブル)を参照してください。

> **Homebrew で `psql` を入れている場合**（[SETUP-MAC.md](../SETUP-MAC.md) の手順8）は、`docker exec` を使わずに Mac から直接接続することもできます：`psql -h 127.0.0.1 -p 5432 -U postgres -d salesdb`（パスワードは `docker-compose.yml` に書かれた学習用のもの）。

**やり直したいとき**は、データごと消して再作成します。

```bash
docker compose down -v && docker compose up -d
```

---

## 演習1：Excel 由来の生データを観察する（単元3-3）

```sql
\d staging_orders_raw          -- 列の型を確認（全部 text）
SELECT * FROM staging_orders_raw;
```

**観察ポイント**

- 同じ電話番号 `03-1111-2222` に、`山田商店` / `(株)山田商店` / `ヤマダ商店` の3つの表記がある
- `ボルトA` の単価が 120 円の行と 110 円の行がある（値引き？）
- 日付が `2026/04/01` という文字列

```sql
-- 表記ゆれのせいで、顧客名で集計すると同じ会社が分かれる
SELECT customer_name, sum(unit_price::numeric * qty::int) AS sales
FROM staging_orders_raw
GROUP BY customer_name
ORDER BY sales DESC;
```

---

## 演習2：正規化したテーブルに取り込む（単元3-3・3-4）

`\d customers` などで、正規化済みの空のテーブル（`departments`、`staff`、`customers`、`products`、`orders`、`order_items`）の構造を確認したうえで、**`staging_orders_raw` から各テーブルへデータを移す SQL を自分で書いてください。**

**ヒント**

- `INSERT INTO テーブル (列...) SELECT ... FROM staging_orders_raw` の形で書ける
- 親テーブル（部署 → 担当者・顧客・商品 → 受注 → 明細）の順に入れる
- 顧客の表記ゆれはどう扱う？（何を「同じ顧客」の判定に使うか）
- 文字列の日付は `to_date(ordered_on, 'YYYY/MM/DD')` で変換できる
- 全体を `BEGIN; ... COMMIT;` で囲むと、途中で失敗しても中途半端な状態が残らない

書けたら（または詰まったら）、解答例を実行して答え合わせをします。**後の演習は、この解答例で作ったデータを前提にしています。**

```sql
\i /solutions/normalize.sql
```

最後に表示される JOIN の結果が、元の Excel と同じ見た目になっていることを確認してください。顧客は3社にまとまっています。

```sql
-- 正規化後は、表記ゆれに関係なく正しく集計できる
SELECT c.name, sum(i.unit_price * i.qty) AS sales
FROM orders o
JOIN customers c   ON c.id = o.customer_id
JOIN order_items i ON i.order_id = o.id
GROUP BY c.name
ORDER BY sales DESC;
```

**制約が効いていることも確かめます。**

```sql
INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (1, 3, -5, 30);
-- → ERROR: violates check constraint "order_items_qty_check"

INSERT INTO orders (order_no, ordered_on, customer_id, staff_id) VALUES ('9999', '2026-05-01', 99999, 1);
-- → ERROR: violates foreign key constraint（存在しない顧客）

DELETE FROM customers WHERE name = '山田商店';
-- → ERROR: 注文が残っている顧客は削除できない（ON DELETE RESTRICT）
```

---

## 演習3：インデックスの効果を測る（単元3-1・3-4）

100万行のテーブルを作ります（数秒かかります）。

```sql
CREATE TABLE big_orders AS
SELECT g AS id,
       'A-' || lpad(g::text, 8, '0') AS order_no,
       (random() * 5000)::int + 1    AS customer_id,
       date '2020-01-01' + (random() * 2400)::int AS ordered_on,
       (random() * 100000)::int      AS amount
FROM generate_series(1, 1000000) AS g;
ANALYZE big_orders;

SELECT pg_size_pretty(pg_relation_size('big_orders')) AS size,
       pg_relation_size('big_orders') / 8192         AS pages;
```

**インデックスなしで検索：**

```sql
EXPLAIN ANALYZE SELECT * FROM big_orders WHERE order_no = 'A-00123456';
```

**インデックスを作って再検索：**

```sql
CREATE INDEX idx_big_orders_order_no ON big_orders (order_no);
EXPLAIN ANALYZE SELECT * FROM big_orders WHERE order_no = 'A-00123456';
SELECT pg_size_pretty(pg_relation_size('idx_big_orders_order_no')) AS index_size;
```

**参考（筆者の環境での結果）**

| | 実行計画 | 実行時間 |
|---|---|---|
| インデックスなし | `Parallel Seq Scan`（約7,000ページを全部読む） | 約 42 ms |
| インデックスあり | `Index Scan` | 約 0.05 ms |

約800倍の差です。テーブルが 100 倍になれば、Seq Scan はさらに約 100 倍遅くなりますが、Index Scan はほとんど変わりません。一方で、インデックス自体が 30MB ほどの容量を使っていることも確認してください。

**考察課題**：`WHERE amount > 50000` で検索した場合、`amount` にインデックスを作ると速くなるでしょうか？ 実際に試し、結果の理由を考えてください。（ヒント：何割の行が条件に当てはまるか）

---

## 演習4：更新の消失を再現し、防ぐ（単元3-2）

**ターミナルを2つ開き**（`command + N` で新しいウィンドウ、`command + T` で新しいタブ）、両方で `docker exec -it lab-postgres psql -U postgres -d salesdb` を実行します。

```sql
-- 準備（どちらか一方で）
UPDATE inventory SET stock = 10, version = 1 WHERE product_id = 1;
```

### 4-1. 更新の消失

アプリが「読んで → 計算して → 書く」場合を、手で再現します。上から順に、A と B を交互に実行してください。

| 順 | ターミナルA（3個出荷） | ターミナルB（2個出荷） |
|---|---|---|
| 1 | `BEGIN;` | |
| 2 | `SELECT stock FROM inventory WHERE product_id = 1;` → 10 | |
| 3 | | `BEGIN;` |
| 4 | | `SELECT stock FROM inventory WHERE product_id = 1;` → 10 |
| 5 | `UPDATE inventory SET stock = 7 WHERE product_id = 1;` | |
| 6 | `COMMIT;` | |
| 7 | | `UPDATE inventory SET stock = 8 WHERE product_id = 1;` |
| 8 | | `COMMIT;` |

```sql
SELECT stock FROM inventory WHERE product_id = 1;   -- 8（正しくは 5）
```

### 4-2. 対策①：DB に計算させる

在庫を 10 に戻し、同じ手順で、5・7 の UPDATE を次のように変えて試します。

```sql
-- A
UPDATE inventory SET stock = stock - 3 WHERE product_id = 1 AND stock >= 3;
-- B
UPDATE inventory SET stock = stock - 2 WHERE product_id = 1 AND stock >= 2;
```

B の UPDATE は、A が COMMIT するまで**待たされる**ことを観察してください。結果は正しく 5 になります。

### 4-3. 対策②：悲観的ロック（SELECT ... FOR UPDATE）

在庫を 10 に戻し、手順2・4 の SELECT の末尾に `FOR UPDATE` をつけて試します。B の SELECT の時点で待たされ、A の COMMIT 後に **7** が返ってくることを確認します。

### 4-4. 対策③：楽観的ロック

```sql
-- 読むときに version も取得し、更新時に「読んだときから変わっていないこと」を条件にする
UPDATE inventory SET stock = 7, version = version + 1 WHERE product_id = 1 AND version = 1;
```

A と B の両方が `version = 1` を読んだ状態で、後から UPDATE した方が `UPDATE 0`（0件更新）になることを確認します。アプリは 0件なら「他の人が先に更新しました。やり直してください」と扱います。

---

## 演習5：デッドロックを起こす（単元3-2）

| 順 | ターミナルA | ターミナルB |
|---|---|---|
| 1 | `BEGIN;` | `BEGIN;` |
| 2 | `UPDATE inventory SET stock = stock - 1 WHERE product_id = 1;` | |
| 3 | | `UPDATE inventory SET stock = stock - 1 WHERE product_id = 2;` |
| 4 | `UPDATE inventory SET stock = stock - 1 WHERE product_id = 2;` → 待ち | |
| 5 | | `UPDATE inventory SET stock = stock - 1 WHERE product_id = 1;` |

約1秒後、どちらかに次のエラーが出ます。

```text
ERROR:  deadlock detected
DETAIL:  Process 123 waits for ShareLock on transaction 456; blocked by process 789.
```

両方のターミナルで `ROLLBACK;` してください。**同じ処理を「常に product_id の小さい順に更新する」ルールにすると、デッドロックが起きないことも確かめてみましょう。**

---

## 演習6：WAL が書かれていることを見る（単元3-1）

```sql
SELECT pg_current_wal_lsn();                         -- 現在の WAL の位置
UPDATE big_orders SET amount = amount + 1 WHERE id <= 100000;
SELECT pg_current_wal_lsn();                         -- 位置が進んでいる
SELECT pg_size_pretty(pg_wal_lsn_diff('<2回目の値>', '<1回目の値>'));  -- 書かれた WAL の量
```

10万行の更新で、数十MB の WAL が書かれたはずです。**更新のたびに「何をしたか」の日記が先に書かれている**ことの確認です。

```sql
-- 更新で古い行が「デッドタプル」として残っている
SELECT n_live_tup, n_dead_tup FROM pg_stat_user_tables WHERE relname = 'big_orders';
VACUUM big_orders;
SELECT n_live_tup, n_dead_tup FROM pg_stat_user_tables WHERE relname = 'big_orders';
```

---

## 演習7：ロール分離と行レベルセキュリティ（単元3-5）

`\q` で抜けて、**アプリ用ロール `app_rw`** で接続し直します。

```bash
docker exec -it lab-postgres psql -U app_rw -d salesdb
```

```sql
SELECT * FROM customers;           -- 0件！（自分の部署が設定されていない）

SELECT * FROM departments;         -- 営業1課の id を確認（通常は 1）
SET app.department_id = '1';
SELECT id, name FROM customers;    -- 営業1課の顧客だけが見える

DELETE FROM orders WHERE id = 1;   -- → permission denied（DELETE 権限なし）
DROP TABLE orders;                 -- → must be owner（構造変更できない）

-- 他部署の顧客を作ろうとする（department_id は営業2課の id）
INSERT INTO customers (name, phone, department_id, source, source_id)
VALUES ('テスト商事', '03-0000-0000', 2, 'manual', 't1');
-- → new row violates row-level security policy
```

次に **分析用ロール `analyst_ro`** で確認します。

```bash
docker exec -it lab-postgres psql -U analyst_ro -d salesdb
```

```sql
SELECT * FROM v_customers_masked;  -- 住所は都道府県だけ、電話番号はない
SELECT * FROM customers;           -- → permission denied
```

---

## 後片付け

```bash
docker compose down -v
```

---

## 考察課題（回答ファイルに書く）

1. 演習2で、顧客の表記ゆれをどう判定しましたか？ 電話番号を判定キーにする方法の弱点（同じ電話番号の別会社、電話番号の変更など）と、実案件での対処法を考えてください。
2. 演習3の考察課題（`amount > 50000` のインデックス）の結果と理由。
3. 演習4の3つの対策を、「kintone から1日3回データを同期するバッチ」と「複数人が画面から在庫を更新する業務アプリ」のそれぞれで、どれを選ぶか理由とともに答えてください。
4. 演習7で `app_rw` が `SET app.department_id = '2'` と自分で書き換えれば、他部署のデータも見えてしまいます。この弱点は実際のシステムでどう防ぐべきでしょうか？（ヒント：その SET 文を実行するのは誰か）

---

回答は [`answers/chapter-03/hands-on.md`](../answers/chapter-03/hands-on.md) に記入してください。
