-- =====================================================================
-- 第4章 ハンズオン：取り込み先のテーブル
-- =====================================================================
SET client_min_messages = warning;

CREATE TABLE customers (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source             text        NOT NULL CHECK (source IN ('kintone', 'hubspot', 'excel', 'manual')),
    source_id          text        NOT NULL,
    name               text        NOT NULL,
    name_normalized    text        NOT NULL,           -- 名寄せ・検索用（単元4-2）
    phone              text,
    phone_digits       text,                            -- 比較用：数字だけ
    address            text,
    source_updated_at  timestamptz NOT NULL,            -- 取り込み元での更新日時（単元4-3）
    deleted_at         timestamptz,                     -- 論理削除
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source, source_id)                          -- 冪等性の要
);
CREATE INDEX idx_customers_phone_digits ON customers (phone_digits);

-- どこまで同期したかを覚えておく（差分取得の「しおり」）
CREATE TABLE sync_state (
    source        text PRIMARY KEY,
    watermark     timestamptz NOT NULL,                 -- 取り込めたデータの最大の更新日時
    last_run_at   timestamptz NOT NULL DEFAULT now()
);

-- 取り込めなかったレコード（黙って捨てない）
CREATE TABLE sync_errors (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source       text        NOT NULL,
    source_id    text,
    reason       text        NOT NULL,
    payload      jsonb       NOT NULL,
    occurred_at  timestamptz NOT NULL DEFAULT now()
);

-- 同期処理専用のロール（最小権限：DELETE なし）
CREATE ROLE sync_writer LOGIN PASSWORD 'lab_sync_password';
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO sync_writer;
GRANT SELECT, INSERT, UPDATE ON customers, sync_state TO sync_writer;
GRANT INSERT ON sync_errors TO sync_writer;
