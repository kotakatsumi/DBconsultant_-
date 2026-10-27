# 第1章 ハンズオン：Linux コンテナの中でプロセス・権限・メモリを観察する

> **目的**：単元1-1〜1-4で学んだことを、自分の手で確かめる。
> **所要時間**：60〜90分
> **必要なもの**：MacBook ＋ Docker Desktop（未導入なら先に [SETUP-MAC.md](../SETUP-MAC.md) の手順1〜4を実施）

---

## 準備

Docker Desktop を起動し（メニューバーに 🐳 が出て「running」になるまで待つ）、ターミナルで動作を確認します。

```bash
docker --version
docker run --rm hello-world
```

### Mac で Docker を使うときに知っておくこと

コンテナは **Linux カーネルの機能**（namespaces・cgroups、単元1-4）で動く仕組みです。macOS のカーネルは Linux ではないので、Mac の Docker Desktop は、裏側で**小さな Linux の仮想マシン（VM）を1台起動し、その中でコンテナを動かしています**。

```text
あなたの Mac（macOS のカーネル）
└── Docker Desktop が起動する Linux VM（Linux のカーネル）   ← 単元1-4 の「仮想マシン」
      ├── コンテナ A（ubuntu）   ← 単元1-4 の「コンテナ」。VM の Linux カーネルを共有
      └── コンテナ B（postgres）
```

つまり Mac の上では、**単元1-4で学んだ「仮想マシン」と「コンテナ」の両方が、同時に動いている**ことになります。演習5で、これを自分の目で確かめます。

---

## 演習1：コンテナの中は「別の世界」に見える（namespaces）

Ubuntu のコンテナを起動して、中に入ります。

```bash
docker run --rm -it --name lab1 ubuntu:24.04 bash
```

コンテナの中で、次を実行します。

```bash
ps aux          # 動いているプロセスの一覧
hostname        # マシン名
ls /            # ディレクトリ構成
whoami          # 自分は誰か
```

**観察ポイント**

- `ps aux` に出てくるプロセスはごくわずか（`bash` と `ps` だけ）。Mac や、他のコンテナで動いている大量のプロセスは見えない。→ **namespaces による隔離**（単元1-4）
- `whoami` が `root` になっている。→ コンテナ内の既定ユーザーは root。**これは本番では避けるべき状態**（演習3で扱います）

`exit` でコンテナを抜けます（`--rm` を付けたので自動で削除されます）。

---

## 演習2：パーミッションを体験する（単元1-3）

```bash
docker run --rm -it ubuntu:24.04 bash
```

コンテナの中で：

```bash
# 一般ユーザー alice を作る
useradd -m alice

# root として秘密ファイルを作り、本人だけ読める 600 にする
echo "DB_PASSWORD=super-secret" > /root/secret.env
chmod 600 /root/secret.env
ls -l /root/secret.env

# 共有の作業場所に、alice も読めるファイルを作る
mkdir -p /srv/app
echo "APP_NAME=demo" > /srv/app/config.env
chmod 644 /srv/app/config.env

# alice になって読んでみる
su alice -c "cat /srv/app/config.env"    # → 読める
su alice -c "cat /root/secret.env"       # → Permission denied
```

**続けて、よくある事故を再現します。**

```bash
chmod 777 /srv/app/config.env
su alice -c "echo 'APP_NAME=hacked' > /srv/app/config.env"
cat /srv/app/config.env    # → alice に書き換えられている
```

**考察課題（回答ファイルに書く）**
- alice が `/root/secret.env` を読めなかったのは、誰が・何を確認した結果ですか？
- `777` にしたことで何が起きましたか？ Web アプリのコードが置かれたディレクトリで同じことをしたら、どんな被害につながりますか？

`exit` で抜けます。

---

## 演習3：root で動くアプリと一般ユーザーで動くアプリ（単元1-2）

```bash
# root で起動（既定）
docker run --rm ubuntu:24.04 bash -c "whoami; cat /etc/shadow | head -3"

# 一般ユーザー（UID 1000）で起動
docker run --rm --user 1000:1000 ubuntu:24.04 bash -c "whoami; cat /etc/shadow | head -3"
```

**観察ポイント**

- root で起動した場合、パスワードのハッシュが格納された `/etc/shadow` が読めてしまう。
- 一般ユーザーで起動した場合は `Permission denied`。

→ アプリに脆弱性があって任意のコマンドを実行されたとき、**被害の範囲は「アプリの実行ユーザーの権限」で決まる**。本番のコンテナは `--user`（または Dockerfile の `USER` 命令）で一般ユーザーにするのが鉄則です。

---

## 演習4：メモリ上限をかける（cgroups / 単元1-1・1-4）

メモリを 50MB に制限したコンテナで、200MB のメモリを確保しようとしてみます。

```bash
docker run --rm --memory=50m python:3.12-slim \
  python -c "x = bytearray(200 * 1024 * 1024); print('確保できた')"
echo "終了コード: $?"
```

**観察ポイント**

- 「確保できた」は表示されず、プロセスが強制終了される（終了コード `137` ＝ OOM Killer による強制終了）。
- 上限を `--memory=300m` に変えると成功する。

→ **cgroups によって、1つのコンテナの暴走が他に波及しないようにできる**。単元1-2の「1つのバッチが暴走して全システムが止まった」事故への対策です。Cloud Run でもメモリ上限を設定し、超えると同じように強制終了されます。

---

## 演習5：コンテナの正体を確かめる（単元1-4）

ターミナルを2つ開きます（ターミナルで `command + N`）。

**ターミナルA：** コンテナを1つ起動したままにする

```bash
docker run --rm --name lab5 ubuntu:24.04 sleep 300
```

**ターミナルB：** 次のコマンドを順に実行する

```bash
# ① Mac 自身のカーネルと、コンテナの中のカーネルを比べる
uname -sr                                     # Mac：Darwin ...（macOS のカーネル）
docker run --rm ubuntu:24.04 uname -sr        # コンテナ：Linux ...（Linux のカーネル）

# ② Mac のプロセス一覧から、コンテナの sleep を探す
ps aux | grep "sleep 300" | grep -v grep      # → 見つからない（docker コマンドだけが見える）

# ③ Docker から見た、コンテナのプロセス
docker top lab5

# ④ Linux VM 全体のプロセス一覧から探す（--pid=host で VM のプロセスを見る特別なコンテナを起動）
docker run --rm --pid=host ubuntu:24.04 ps -e -o pid,user,cmd | grep "sleep 300" | grep -v grep
```

**観察ポイント**

- ① Mac は `Darwin`、コンテナの中は `Linux`。コンテナは **Mac のカーネルではなく、Docker Desktop の Linux VM のカーネル**の上で動いている。
- ② Mac のプロセス一覧には、コンテナの `sleep` は出てこない。Mac から見ると、コンテナは「VM という別のマシンの中」にある。
- ④ Linux VM の側から見ると、`sleep 300` は**普通のプロセスの1つとして並んでいる**。コンテナの中では PID 1 だったのに、VM から見ると別の PID になっている。
- → **コンテナは「VM のような別マシン」ではなく、「同じ Linux カーネルの上で、隔離された普通のプロセス」**。一方で Mac と Linux の間には**VM の壁**がある。単元1-4の「VM はカーネルごと分かれる／コンテナはカーネルを共有する」の違いを、1台の Mac の上で両方確認できた。

終わったら `docker stop lab5`。

> 本番の Cloud Run も、Google のデータセンターで同じように「隔離された実行環境（サンドボックス）の中のコンテナ」として動いています。

---

## まとめ：このハンズオンで確かめたこと

| 演習 | 確かめたこと | 関連単元 |
|---|---|---|
| 1 | コンテナ内からは外のプロセスが見えない | 1-4 namespaces |
| 2 | カーネルがパーミッションを毎回チェックしている / 777 の危険 | 1-2, 1-3 |
| 3 | 実行ユーザーが被害範囲を決める | 1-2 最小権限 |
| 4 | メモリ上限で暴走を封じ込められる | 1-1, 1-4 cgroups |
| 5 | コンテナの正体は隔離されたプロセス / Mac の Docker は Linux VM の上で動く | 1-4 VM とコンテナ |

---

回答は [`answers/chapter-01/hands-on.md`](../answers/chapter-01/hands-on.md) に記入してください。
