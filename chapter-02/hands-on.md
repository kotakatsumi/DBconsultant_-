# 第2章 ハンズオン：通信を「見える化」する

> **目的**：DNS → TCP → TLS → HTTP の流れを、実際のコマンド出力で確認する。
> **所要時間**：60〜90分
> **環境**：MacBook の「ターミナル」。使うコマンドはすべて macOS に最初から入っているので、**インストールも Docker も不要**です（[SETUP-MAC.md](../SETUP-MAC.md) の手順7）。
> 会社のネットワークなど、ファイアウォールで一部の通信が制限されている環境では結果が変わることがあります。自宅の Wi-Fi やスマートフォンのテザリングで試すと確実です。

---

## 演習1：自分のネットワーク情報を見る（単元2-1）

MacBook の Wi-Fi は、通常 `en0` という名前のネットワーク機器（インターフェース）です。

```bash
ipconfig getifaddr en0              # Mac に割り当てられた IP アドレス
ifconfig en0 | grep "inet "         # IP アドレスとサブネットマスク
route -n get default | grep gateway # デフォルトゲートウェイ（家や会社のルーター）
netstat -rn -f inet | head -15      # ルーティングテーブル
curl -s https://ifconfig.me; echo   # インターネット側から見た自分の IP アドレス
```

> 有線 LAN のアダプタを使っている場合など、`en0` で何も表示されないときは、`ifconfig | grep "inet "` で一覧を見て、`127.0.0.1` 以外の行を探してください。

**確認すること**

- `ifconfig` の `netmask 0xffffff00` は16進数で書かれたサブネットマスク。`ff` = 255 なので `255.255.255.0`、つまり **`/24`** という意味。
- Mac の IP アドレス（例：`192.168.1.25`）は**プライベートIP**の範囲に入っているか？
- `netstat -rn` の `default` の行が、**`0.0.0.0/0` 宛のルール**（どれにも当てはまらない宛先はルーターに渡す）。
- 最後の `ifconfig.me` で表示される IP アドレスは、Mac の IP アドレスと**違う**はず。これが **NAT**（ルーターがグローバルIPに書き換えて代理で通信している）の証拠。

---

## 演習2：DNS の名前解決をたどる（単元2-3）

```bash
dig www.google.com            # A レコード
dig google.com MX             # メールの受け取り先
dig google.com TXT            # SPF などの設定
dig +trace www.example.com    # ルートサーバーから順にたどる
```

**確認すること**

- `ANSWER SECTION` の数字（例：`300`）が **TTL**。2回続けて実行すると、キャッシュDNSサーバーのTTLが減っていくのが見えることがある。
- `+trace` の出力で、`.` → `com.` → `example.com.` と権威サーバーへたどり着く流れ。
- 自分の会社（または勤務先）のドメインで `dig ドメイン TXT` を実行し、`v=spf1` や `_dmarc` の設定があるか確認する（`dig _dmarc.ドメイン TXT`）。

---

## 演習3：ポートが開いているか確かめる（単元2-2）

```bash
nc -zv -G 3 www.google.com 443    # HTTPS → 成功するはず
nc -zv -G 3 www.google.com 5432   # PostgreSQL → 3秒でタイムアウトするはず（-G は接続の待ち時間）
```

**確認すること**

- 443 は `Connection to www.google.com port 443 [tcp/https] succeeded!`。5432 は `Operation timed out`。
- **タイムアウトはファイアウォールがパケットを黙って捨てている**ことを示す。「拒否（refused）」と返事をするより、攻撃者に情報を与えない。

---

## 演習4：HTTP の中身を読む（単元2-4）

```bash
curl -v https://www.example.com/ -o /dev/null
```

**出力から次の部分を探す**

```text
* Connected to www.example.com (93.184.215.14) port 443   ← DNS → TCP 接続
* SSL connection using TLSv1.3 / ...                      ← TLS のバージョンと暗号方式
* Server certificate:
*  subject: CN=www.example.org                             ← 証明書の持ち主
*  issuer: C=US; O=DigiCert Inc; ...                       ← 発行した認証局
> GET / HTTP/2                                             ← リクエスト（> がこちらから送った行）
> Host: www.example.com
< HTTP/2 200                                               ← レスポンス（< が返ってきた行）
< content-type: text/html
```

（IP アドレスや証明書の内容は時期によって変わります。macOS 標準の `curl` は表示が少し異なる場合がありますが、同じ情報が含まれています）

**確認すること**：DNS → TCP → TLS → HTTP の順に処理が進んでいること。

---

## 演習5：証明書を直接確認する（単元2-4）

```bash
echo | openssl s_client -connect www.google.com:443 -servername www.google.com 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates
```

**確認すること**

- `notBefore` / `notAfter`：証明書の有効期間。期限切れになるとブラウザが警告を出す。
- `issuer`：発行した認証局。

**わざと失敗させる**：ホスト名が一致しない証明書でどうなるか見てみましょう。

```bash
curl -v https://wrong.host.badssl.com/ 2>&1 | grep -i "ssl\|certificate"
```

`curl` がエラーで止まるはずです。これが「**偽サーバーへの接続を、証明書の検証で防いでいる**」動きです。

---

## 演習6：経路をたどる（単元2-1）

```bash
traceroute -n -w 2 -m 20 www.google.com    # -w：各応答の待ち時間（秒）、-m：最大ホップ数
```

**確認すること**

- 何台のルーターを経由しているか（各行が1つのルーター）。
- 途中で `* * *` になる行は、そのルーターが応答を返さない設定になっているだけで、通信が止まっているわけではない。

---

## 考察課題（回答ファイルに書く）

1. 演習3で、5432 番ポートへの接続が「拒否」ではなく「タイムアウト」になったのはなぜだと考えられますか？
2. 演習4の `curl -v` の出力で、TLS の暗号化が始まる前に平文でやり取りされている情報は何ですか？（ヒント：DNS の問い合わせと、TLS の ClientHello に含まれる `servername`）
3. 自社または勤務先のドメインについて、演習2で SPF / DMARC の設定状況を調べた結果を書いてください。

---

## まとめ

| 演習 | 確かめたこと | 関連単元 |
|---|---|---|
| 1 | 自分の IP・CIDR・デフォルトゲートウェイ | 2-1 |
| 2 | DNS の階層構造、TTL、メール認証の設定 | 2-3 |
| 3 | 開いているポートと、ファイアウォールの挙動 | 2-2, 2-5 |
| 4 | DNS → TCP → TLS → HTTP の順序 | 2-4 |
| 5 | 証明書の中身と、検証失敗時の挙動 | 2-4 |
| 6 | ルーターのバケツリレー | 2-1 |

---

回答は [`answers/chapter-02/hands-on.md`](../answers/chapter-02/hands-on.md) に記入してください。
