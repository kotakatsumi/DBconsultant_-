# 単元 3-4：物理設計（データ型、制約、インデックス設計、パフォーマンス）

> **この単元のゴール**
> 単元3-3で分解したテーブルを、実際の PostgreSQL の `CREATE TABLE` 文に落とし込めるようになる。「おかしなデータを DB が自動で拒否する」設計と、「データが増えても遅くならない」設計ができるようになる。

---

## 【概念の解説】

### 1. 論理設計と物理設計

| | 論理設計（単元3-3） | 物理設計（この単元） |
|---|---|---|
| 問い | どんなテーブルに分けるか | 各列をどの型で、どんなルールで、どう速く引けるようにするか |
| 成果物 | ER図 | `CREATE TABLE` / `CREATE INDEX` 文 |

### 2. データ型の選び方：型は「最初の防御線」

データ型は、単なる保存形式ではなく、**入ってはいけない値を DB の入口で弾く**ためのものです。

| 用途 | 推奨する型 | 避けるべき型と理由 |
|---|---|---|
| 金額 | `numeric(12, 0)` や `numeric(14, 2)` | `real` / `double precision`：**2進数の浮動小数点は 0.1 を正確に表せない**ため、合計に誤差が出る |
| 数量・件数 | `integer` / `bigint` | `text`：「1O0」（オーとゼロの混在）のような値を受け入れてしまう |
| 日付 | `date` | `text`：「2026/4/1」「4月1日」「R8.4.1」が混在し、並び替えも比較もできない |
| 日時 | `timestamptz`（タイムゾーン付き） | `timestamp`（タイムゾーンなし）：サーバーや SaaS のタイムゾーンが違うと**9時間ずれる** |
| 真偽 | `boolean` | `text` の「済」「〇」「1」 |
| 文字列 | `text`（必要に応じて長さの CHECK 制約） | — |
| 構造が変わりうる付帯情報 | `jsonb` | 何でも jsonb に入れるのは避ける（検索・制約がかけにくい） |

> **Excel から来たデータは、ほぼ「全部文字列」です。** 取り込み時に正しい型に変換し、変換できない値をエラーとして検出することが、データ品質の第一歩になります（第4章）。

**ID（主キー）の型**

| 方式 | 例 | 特徴 |
|---|---|---|
| 連番 | `bigint GENERATED ALWAYS AS IDENTITY` | 小さく速い。ただし**番号から件数や他のデータの ID が推測できる** |
| UUID | `uuid DEFAULT gen_random_uuid()` | 推測できない。外部に公開する ID に向く。少し大きい |

URL に `/customers/1234` のような連番を出すと、`1235` に書き換えて他人のデータを見ようとする攻撃（**IDOR**、第5章）の的になります。連番を使う場合でも、**アクセス権のチェックは必須**です。

### 3. 制約：DB に「業務ルール」を守らせる

アプリのバリデーションだけでは不十分です。アプリにはバグがあり、取り込みスクリプトや手作業の SQL など、**アプリを経由しない書き込み経路**が必ず生まれるからです。最後の砦として、DB 自身にルールを持たせます。

| 制約 | 意味 | 防げる事故 |
|---|---|---|
| `PRIMARY KEY` | 行を一意に特定する | 同じ行の重複 |
| `NOT NULL` | 空欄を許さない | 「顧客のない注文」 |
| `UNIQUE` | 重複を許さない | 同じ受注No の二重登録、**同じ kintone レコードの二重取り込み** |
| `FOREIGN KEY` | 参照先が存在することを保証 | 存在しない顧客 ID を持つ注文、顧客を消したら注文が宙に浮く |
| `CHECK` | 任意の条件 | 数量がマイナス、ステータスに想定外の値 |
| `DEFAULT` | 省略時の値 | 作成日時の入れ忘れ |

### 4. 実際の DDL

単元3-3の設計を、物理設計に落とし込んだ例です（一部抜粋）。

```sql
CREATE TABLE customers (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    phone        text        CHECK (phone ~ '^[0-9-]+$'),
    address      text,
    -- 取り込み元の情報（第4章で使う）
    source       text        NOT NULL CHECK (source IN ('kintone', 'hubspot', 'excel', 'manual')),
    source_id    text        NOT NULL,          -- 取り込み元でのレコードID
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source, source_id)                  -- 同じレコードを二重に取り込まない
);

CREATE TABLE orders (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_no     text        NOT NULL UNIQUE,
    ordered_on   date        NOT NULL,
    customer_id  bigint      NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    staff_id     bigint      NOT NULL REFERENCES staff (id),
    status       text        NOT NULL DEFAULT 'received'
                 CHECK (status IN ('received', 'shipped', 'cancelled')),
    created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE order_items (
    order_id     bigint        NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    product_id   bigint        NOT NULL REFERENCES products (id),
    qty          integer       NOT NULL CHECK (qty > 0),
    unit_price   numeric(12,0) NOT NULL CHECK (unit_price >= 0),
    PRIMARY KEY (order_id, product_id)
);
```

ポイント：

- `ON DELETE RESTRICT`：注文が残っている顧客は削除できない（誤削除の防止）
- `ON DELETE CASCADE`：注文を消したら明細も一緒に消える（明細だけ残ることはない）
- `UNIQUE (source, source_id)`：**取り込み処理を何度実行しても重複しない**ための鍵（第4章の冪等性）
- `created_at` / `updated_at`：BigQuery への差分同期で「前回以降に変わった行」を探すのに使う（第6章）

### 5. インデックス設計

単元3-1で、インデックスは「検索を速くするが、書き込みを遅くする」と学びました。張るべき場所の目安：

| 張るべき列 | 理由 |
|---|---|
| 主キー、UNIQUE 制約の列 | 自動で作られる |
| **外部キーの列**（`orders.customer_id` など） | PostgreSQL は自動で作らない。JOIN や「この顧客の注文一覧」が遅くなる。親を削除するときのチェックも遅くなる |
| よく `WHERE` で絞り込む列 | `ordered_on`（期間指定）など |
| よく `ORDER BY` で並べる列 | 一覧画面の並び順 |

```sql
CREATE INDEX idx_orders_customer_id ON orders (customer_id);
CREATE INDEX idx_orders_ordered_on  ON orders (ordered_on);
-- 複合インデックス：「この顧客の注文を日付順に」を1つのインデックスで
CREATE INDEX idx_orders_customer_date ON orders (customer_id, ordered_on DESC);
```

**複合インデックスの列の順番**は重要です。`(customer_id, ordered_on)` のインデックスは、電話帳の「市区町村 → 氏名」の順の索引のようなもので、

- `WHERE customer_id = 101` → 使える
- `WHERE customer_id = 101 AND ordered_on >= '2026-04-01'` → 使える
- `WHERE ordered_on >= '2026-04-01'` だけ → **効率よく使えない**（市区町村を指定せずに氏名で引くのと同じ。PostgreSQL 18 以降は「スキップスキャン」で使われる場合もあるが、先頭列で絞れるときほど速くはならない）

### 6. パフォーマンスの確認：EXPLAIN ANALYZE

推測ではなく、実測します。

```sql
EXPLAIN ANALYZE
SELECT * FROM orders WHERE customer_id = 101 ORDER BY ordered_on DESC LIMIT 20;
```

```text
Limit  (actual time=0.031..0.045 rows=20 loops=1)
  ->  Index Scan using idx_orders_customer_date on orders
        (actual time=0.030..0.041 rows=20 loops=1)
        Index Cond: (customer_id = 101)
Execution Time: 0.068 ms
```

`Seq Scan` が出ていて遅ければ、インデックスの不足を疑います。

**アプリ側の典型的な問題：N+1 クエリ**

Laravel で次のように書くと、注文100件に対して SQL が101回発行されます。

```php
$orders = Order::all();               // 1回
foreach ($orders as $order) {
    echo $order->customer->name;       // 注文ごとに1回 × 100
}
```

単元2-2で学んだとおり、SQL 1回ごとにネットワークの往復が発生します。`Order::with('customer')->get()` とすれば2回で済みます。**DB の設計が正しくても、使い方で遅くなる**ことを覚えておいてください。

---

## 【実務・ビジネスとの結びつき】

### これが理解できていないと起きること

| 現場の症状 | 根本原因 |
|---|---|
| 「月次売上の合計が1円ずれる」 | 金額を浮動小数点型で保存していた |
| 「SaaS から取り込んだ日時が9時間ずれている」 | タイムゾーンなしの型を使い、UTC と日本時間が混在した |
| 「同じ顧客が取り込みのたびに増えていく」 | 取り込み元の ID に UNIQUE 制約がなかった |
| 「顧客を削除したら、売上レポートの一部が空欄になった」 | 外部キー制約がなく、参照先のない注文が残った |
| 「顧客詳細画面が年々遅くなる」 | 外部キー列にインデックスがない |

### 顧客のどの課題を解決するか

- 「**入力ミスや矛盾したデータが、そもそも入らない仕組み**」は、Excel / kintone 運用にはない強力なメリット。データの品質が上がれば、その上の分析（BigQuery）の信頼性も上がる。
- 「取り込みを何度やり直しても重複しない」設計は、**運用の手間とミスを減らす**。保守の工数削減としてROIにも効いてくる。

---

## 【理解度チェック課題】

**Q1.**
金額を `double precision`（浮動小数点）型で保存してはいけない理由を説明してください。代わりに何を使いますか。

**Q2.**
アプリ側でバリデーションをしているのに、DB 側にも `NOT NULL` / `CHECK` / `FOREIGN KEY` などの制約を設定すべき理由を、「アプリを経由しない書き込み経路」の例を挙げて説明してください。

**Q3.**
`orders` テーブルに `(customer_id, ordered_on)` の複合インデックスがあります。次の3つの SQL のうち、このインデックスが効率よく使えるのはどれですか。理由も答えてください。
- (a) `WHERE customer_id = 101`
- (b) `WHERE ordered_on = '2026-04-01'`
- (c) `WHERE customer_id = 101 AND ordered_on >= '2026-04-01'`

---

## 【顧客向け言語化トレーニング】

専門用語（データ型、制約、インデックス等）を使わずに答えてください。

**演習1**
> 担当者「今は入力ミスがあっても、月末に私が目で見て直しています。データベースにしたら、その作業はなくなりますか？」

**演習2**
> 社長「前に作ってもらったシステム、最初は速かったのに、最近すごく遅いんだよね。古くなったから？」

<details>
<summary>💡 回答のヒント（考えてから開く）</summary>

- 演習1：「マイナスの数量」「存在しないお客様」「日付になっていない日付」は、入力した瞬間に受け付けない仕組みにできる。ただし「山田と山口の打ち間違い」のような**意味の間違い**は機械では防げないので、ゼロにはならないと正直に伝える。「目視チェックの対象が大幅に減る」が誠実な答え。
- 演習2：システムは古くなっても遅くならない。「データが増えたのに、探し方が"全部の書類を1枚ずつめくる"ままになっている」可能性が高い。「原因を測って特定してから直す」ことを伝える。

</details>

---

回答は [`answers/chapter-03/04.md`](../answers/chapter-03/04.md) に記入してください。
