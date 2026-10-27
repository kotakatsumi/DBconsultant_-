# 第5章 ハンズオン：脆弱なコードを攻撃し、対策版と比べる

> **目的**：単元5-1・5-3の攻撃を、自分の手で「成功させて」から「防ぐ」。攻撃が成立する仕組みを体で理解する。
> **所要時間**：60〜90分
> **必要なもの**：Docker Desktop（または PHP 8.x）
> **使うファイル**：[`lab/app.php`](./lab/app.php)、[`lab/send_webhook.php`](./lab/send_webhook.php)

> ⚠️ **このアプリは学習用に、わざと脆弱に作ってあります。インターネットに公開しないでください。** また、ここで学ぶ手口を、許可のない他人のシステムに試すことは法律（不正アクセス禁止法など）で禁止されています。自分の環境でのみ行ってください。

---

## 準備

```bash
cd chapter-05/lab
docker run --rm -it -p 127.0.0.1:8000:8000 -v "$PWD":/app -w /app php:8.3-cli \
  php -S 0.0.0.0:8000 app.php
```

別のターミナルで操作します。データを初期状態に戻したいときは：

```bash
curl -X POST http://127.0.0.1:8000/reset
```

---

## 演習1：SQL インジェクション（単元5-1）

**普通の検索**

```bash
curl -s --get http://127.0.0.1:8000/vuln/search --data-urlencode "name=山田商店"
```

**攻撃**

```bash
curl -s --get http://127.0.0.1:8000/vuln/search --data-urlencode "name=' OR '1'='1"
```

- レスポンスの `sql` に、実際に実行された SQL が表示されます。入力値が SQL の一部として解釈され、**全顧客が返ってくる**ことを確認してください。

**対策版で同じ攻撃**

```bash
curl -s --get http://127.0.0.1:8000/safe/search --data-urlencode "name=' OR '1'='1"
```

- 結果は空。入力値は「`' OR '1'='1` という名前の顧客」として扱われ、命令にはならない。

**考察**：`app.php` の `/vuln/search` と `/safe/search` のコードを見比べ、違いを1行で説明してください。

---

## 演習2：BOLA / IDOR（単元5-1）

`alice`（営業1課）としてログインしている想定です。営業1課の顧客は 1001 と 1004、営業2課は 1002 と 1003 です。

```bash
# 自分の部署の顧客 → 見られて当然
curl -s -H "X-User: alice" "http://127.0.0.1:8000/vuln/customer?id=1001"

# ID を書き換えて、他部署の顧客を見る → 見えてしまう
curl -s -H "X-User: alice" "http://127.0.0.1:8000/vuln/customer?id=1002"

# 対策版
curl -s -H "X-User: alice" "http://127.0.0.1:8000/safe/customer?id=1002"   # → not found
curl -s -H "X-User: alice" "http://127.0.0.1:8000/safe/customer?id=1001"   # → 見える
```

**考察**：対策版は、他部署の顧客に対して「権限がありません（403）」ではなく「見つかりません（404）」を返しています。なぜでしょうか？

---

## 演習3：マスアサインメント（単元5-1）

画面には「電話番号の変更」しかない想定です。攻撃者は、画面にない項目を勝手に追加して送ります。

```bash
curl -s -X POST http://127.0.0.1:8000/reset
curl -s -H "X-User: alice" -X POST http://127.0.0.1:8000/vuln/profile \
  -d '{"phone": "03-9999-9999", "role": "admin", "department_id": 2}'
```

- `role` が `admin` に、`department_id` が 2 に書き換わってしまいます。この状態で演習2の `/safe/customer?id=1002` を試すと、**認可チェックをしている対策版でさえ、営業2課の顧客が見えてしまいます**（部署そのものを書き換えられたため）。

```bash
curl -s -H "X-User: alice" "http://127.0.0.1:8000/safe/customer?id=1002"
```

**対策版で同じ攻撃**

```bash
curl -s -X POST http://127.0.0.1:8000/reset
curl -s -H "X-User: alice" -X POST http://127.0.0.1:8000/safe/profile \
  -d '{"phone": "03-9999-9999", "role": "admin", "department_id": 2}'
```

- 電話番号だけが変わり、`role` と `department_id` は変わらない。

**考察**：1つの脆弱性（マスアサインメント）が、別の対策（認可チェック）を無効化してしまいました。この経験から、「対策を1つだけに頼ってはいけない」理由を説明してください。

---

## 演習4：Webhook の署名検証（単元5-3）

`send_webhook.php` は、SaaS のふりをして Webhook を送るスクリプトです。別のターミナルで実行します。

```bash
cd chapter-05/lab
docker run --rm --network host -v "$PWD":/app -w /app php:8.3-cli php send_webhook.php
```

> Docker Desktop（Mac / Windows）で `--network host` が使えない場合は、`--network host` を外し、`-e TARGET=http://host.docker.internal:8000/webhook` を付けてください。

| 実行するコマンドの末尾 | 再現する状況 | 期待する応答 |
|---|---|---|
| （なし） | 本物の SaaS からの正しい通知 | `accepted` |
| `tamper` | 経路上で金額を書き換えられた | `署名が一致しません` |
| `old` | 10分前の通知を盗み見て再送した（リプレイ） | `時刻が不正、または古すぎます` |
| `nosecret` | 秘密鍵を知らない攻撃者が偽の通知を作った | `署名が一致しません` |

**考察**：

1. `app.php` の署名検証部分で、`hash_equals` を `===` に変えても、この演習の結果は変わりません。それでも `hash_equals` を使うべき理由は何ですか？
2. `old` の攻撃を防いでいるのは、コードのどの部分ですか？ その部分を消すと、どんな攻撃が可能になりますか？

---

## 後片付け

1つ目のターミナルで `Ctrl+C` を押してサーバーを止めます。

---

## 考察課題（回答ファイルに書く）

各演習の「考察」への回答に加えて：

1. この4つの攻撃のうち、**WAF（Cloud Armor）で防げる可能性があるもの**と、**WAF では防げないもの**を分類し、理由を説明してください（単元5-2）。
2. 演習1の `/vuln/search` が、PostgreSQL の `app_rw` ロール（第3章：DELETE 権限なし）で接続していたとしたら、攻撃者にできること・できないことはどう変わりますか？
3. 自分がこれまでに書いた（または教えている）Laravel のコードで、この4つの観点で危ない箇所がないか、1つ見直した結果を書いてください。
