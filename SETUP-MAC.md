# 環境構築ガイド（MacBook）

> このカリキュラムのハンズオンは、**MacBook（macOS）** を前提にしています。最初に一度だけ、このガイドの手順で環境を整えてください。
> **所要時間**：30〜60分（ダウンロード時間を含む）

| 対象 | 確認した前提 |
|---|---|
| Mac | Apple シリコン（M1〜M4 など）、Intel のどちらでも可 |
| macOS | 最新、またはその1つ前のバージョンを推奨 |
| シェル | macOS 標準の **zsh**（「ターミナル」アプリ） |
| 空きディスク | 20GB 以上を推奨（Docker のイメージ用） |
| メモリ | 8GB でも動くが、16GB 以上あると快適 |

---

## 0. 自分の Mac のチップを確認する

画面左上の  メニュー →「この Mac について」を開き、「チップ」に **Apple M○** と書かれていれば Apple シリコン、「プロセッサ」に **Intel** と書かれていれば Intel Mac です。Docker Desktop のダウンロード時に使います。

ターミナルでも確認できます。

```bash
uname -m    # arm64 → Apple シリコン / x86_64 → Intel
```

---

## 1. ターミナルを開く

`command + space` で Spotlight を開き、「ターミナル」と入力して起動します。以降の `$` から始まらないコードブロックは、すべてターミナルに貼り付けて `return` で実行します。

---

## 2. Xcode コマンドラインツール（git などの基本ツール）

```bash
xcode-select --install
```

ダイアログが出たら「インストール」を押します。すでに入っている場合は「already installed」と表示されるので、次に進みます。

```bash
git --version     # バージョンが表示されれば OK
python3 --version # 第6章のデータ生成スクリプトで使う（任意）
```

---

## 3. Homebrew（Mac 用のパッケージ管理ツール）

[Homebrew 公式サイト](https://brew.sh/ja/) に表示されているインストールコマンドをコピーして、ターミナルで実行します。

インストールの最後に「Next steps」として表示される `echo ... >> ~/.zprofile` と `eval ...` の2行を、**そのままコピーして実行**してください（Apple シリコンの Mac では、これをしないと `brew` コマンドが見つかりません）。

```bash
brew --version    # バージョンが表示されれば OK
```

---

## 4. Docker Desktop（第1・3・4・5章で使用）

1. [Docker Desktop 公式ページ](https://www.docker.com/products/docker-desktop/) から、**自分のチップに合ったもの**（Apple Silicon / Intel）をダウンロード
2. `.dmg` を開き、Docker のアイコンを「アプリケーション」フォルダにドラッグ
3. アプリケーションから Docker を起動し、利用規約に同意する（アカウントの作成は任意。「Skip」で可）
4. メニューバーに 🐳 のアイコンが出て、「Docker Desktop is running」になれば起動完了

（`brew install --cask docker` でもインストールできます）

**動作確認**

```bash
docker --version
docker compose version
docker run --rm hello-world     # "Hello from Docker!" と表示されれば OK
```

**推奨設定**（Docker Desktop の歯車アイコン → Resources）

| 項目 | 推奨値 |
|---|---|
| Memory | 4GB 以上 |
| Disk usage limit | 20GB 以上 |

> **Docker は、使うときだけ起動しておけば十分です。** 起動中は Mac のメモリを消費するので、ハンズオンが終わったらメニューバーの 🐳 →「Quit Docker Desktop」で終了して構いません。

---

## 5. Git と GitHub

### 5-1. 名前とメールアドレスの設定（初回のみ）

```bash
git config --global user.name "あなたの名前"
git config --global user.email "GitHubに登録したメールアドレス"
```

### 5-2. GitHub CLI でログイン

```bash
brew install gh
gh auth login
```

質問には次のように答えます：`GitHub.com` → `HTTPS` → `Y`（Git の認証に使う）→ `Login with a web browser`。表示されたコードをブラウザに入力すればログイン完了です。

### 5-3. このリポジトリを手元に取得する

```bash
mkdir -p ~/dev && cd ~/dev
gh repo clone kotakatsumi/DBconsultant_-
cd DBconsultant_-
```

以降、各ハンズオンの `cd chapter-03/lab` のようなコマンドは、**この `~/dev/DBconsultant_-` フォルダの中で**実行します。

### 5-4. 回答を GitHub に保存する流れ

```bash
cd ~/dev/DBconsultant_-
git pull                                  # 最新の教材を取得
# … answers/ のファイルに回答を書く …
git add answers/
git commit -m "単元1-1 回答"
git push
```

---

## 6. エディタ（VS Code）

```bash
brew install --cask visual-studio-code
```

起動後、`command + shift + P` →「Shell Command: Install 'code' command in PATH」を実行しておくと、ターミナルから `code .` で今いるフォルダを開けます。

**入れておくと便利な拡張機能**

| 拡張機能 | 用途 |
|---|---|
| Japanese Language Pack | 日本語化 |
| Markdown Preview Mermaid Support | 教材の図（Mermaid）を VS Code のプレビューで表示 |
| PHP Intelephense | 第4・5章の PHP コードを読む |

---

## 7. 第2章で使うネットワークコマンド

第2章のコマンド（`dig`、`curl`、`openssl`、`nc`、`traceroute`、`ifconfig`、`netstat`）は、**すべて macOS に最初から入っています**。追加のインストールは不要です。

```bash
dig -v; curl --version | head -1; openssl version; which nc traceroute
```

> macOS の `openssl` は LibreSSL という互換版ですが、このカリキュラムで使う範囲では同じように動きます。

---

## 8. 任意：Mac に直接入れておくと便利なもの

Docker の中で完結するので必須ではありませんが、慣れてきたら入れておくと作業が楽になります。

```bash
brew install libpq && brew link --force libpq   # psql（PostgreSQL クライアント）を Mac から直接使う
brew install php                                # 第5章を Docker なしで動かす
brew install jq                                 # 第4章の API の JSON を見やすく整形する
```

---

## 9. 第6章で使うもの

ブラウザ（Chrome 推奨）と Google アカウントだけで完結します。Mac への追加のインストールはありません。

---

## よくあるトラブル

| 症状 | 原因と対処 |
|---|---|
| `zsh: command not found: brew` | Homebrew インストール後の「Next steps」の2行を実行していない。ターミナルを開き直してから再実行 |
| `Cannot connect to the Docker daemon` | Docker Desktop が起動していない。アプリケーションから Docker を起動し、🐳 が「running」になるまで待つ |
| `Bind for 127.0.0.1:5432 failed: port is already allocated` | Mac に別の PostgreSQL（Postgres.app、`brew install postgresql` など）が起動していて、5432 番ポートを使っている。そちらを停止する（`brew services stop postgresql@16` など）か、`docker-compose.yml` の `"127.0.0.1:5432:5432"` を `"127.0.0.1:55432:5432"` のように変える（単元2-2：ポート番号） |
| ポート 8000 / 8080 が使用中 | `lsof -i :8000` で使っているプロセスを確認し、停止する |
| `docker compose` で `no configuration file provided` | `docker-compose.yml` があるフォルダ（各章の `lab/`）に `cd` していない |
| Apple シリコンで `platform (linux/amd64) does not match` という警告 | このカリキュラムのイメージ（ubuntu、postgres、php、python）はすべて Apple シリコンに対応しているので、通常は出ない。出た場合も動作には影響しないことが多い |
| ダウンロードした `.dmg` が「開発元を確認できない」と言われる | 公式サイトから入手したものであれば、「システム設定」→「プライバシーとセキュリティ」で「このまま開く」を選ぶ |
| ディスクが逼迫した | `docker system df` で使用量を確認し、`docker system prune` で不要なイメージ・コンテナを削除（実行中でないものは消えるので注意） |
