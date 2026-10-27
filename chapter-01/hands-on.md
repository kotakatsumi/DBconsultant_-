# 第1章 ハンズオン：Linux コンテナの中でプロセス・権限・メモリを観察する

> **目的**：単元1-1〜1-4で学んだことを、自分の手で確かめる。
> **所要時間**：60〜90分
> **必要なもの**：Docker Desktop（Mac / Windows）または Docker Engine（Linux）

---

## 準備

ターミナルで Docker が動くことを確認します。

```bash
docker --version
```

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

- `ps aux` に出てくるプロセスはごくわずか（`bash` と `ps` だけ）。ホスト（あなたのPC）で動いている大量のプロセスは見えない。→ **namespaces による隔離**（単元1-4）
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

## 演習5：コンテナのプロセスはホストからどう見えるか

ターミナルを2つ開きます。

**ターミナルA：**
```bash
docker run --rm --name lab5 ubuntu:24.04 sleep 300
```

**ターミナルB：**
```bash
docker top lab5      # コンテナ内のプロセスを、ホスト側の視点で表示
```

**観察ポイント**

- コンテナの中から見ると PID 1 の `sleep` が、ホスト側から見ると別の PID を持つ普通のプロセスとして存在している。
- → **コンテナは「VM のような別マシン」ではなく、「隔離された普通のプロセス」**。カーネルを共有している（単元1-4）ことの証拠です。

終わったら `docker stop lab5`。

---

## まとめ：このハンズオンで確かめたこと

| 演習 | 確かめたこと | 関連単元 |
|---|---|---|
| 1 | コンテナ内からはホストのプロセスが見えない | 1-4 namespaces |
| 2 | カーネルがパーミッションを毎回チェックしている / 777 の危険 | 1-2, 1-3 |
| 3 | 実行ユーザーが被害範囲を決める | 1-2 最小権限 |
| 4 | メモリ上限で暴走を封じ込められる | 1-1, 1-4 cgroups |
| 5 | コンテナの正体は隔離されたプロセス | 1-4 |
