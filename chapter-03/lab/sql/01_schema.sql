-- =====================================================================
-- 第3章 ハンズオン：正規化したスキーマ（単元3-3 / 3-4）
-- =====================================================================
SET client_min_messages = warning;

-- 取り込み前の生データを置く「ステージング」テーブル（Excel/CSV そのまま、全部 text）
CREATE TABLE staging_orders_raw (
    order_no         text,
    ordered_on       text,
    customer_name    text,
    customer_address text,
    customer_phone   text,
    staff_name       text,
    department       text,
    product_name     text,
    unit_price       text,
    qty              text
);

-- ---------------------------------------------------------------------
-- 正規化したテーブル
-- ---------------------------------------------------------------------
CREATE TABLE departments (
    id    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name  text NOT NULL UNIQUE
);

CREATE TABLE staff (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text   NOT NULL UNIQUE,
    department_id  bigint NOT NULL REFERENCES departments (id)
);

CREATE TABLE customers (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    address        text,
    phone          text        NOT NULL CHECK (phone ~ '^[0-9-]+$'),
    department_id  bigint      NOT NULL REFERENCES departments (id),  -- 担当部署（RLS で使う）
    source         text        NOT NULL DEFAULT 'excel'
                   CHECK (source IN ('kintone', 'hubspot', 'excel', 'manual')),
    source_id      text        NOT NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source, source_id)
);

CREATE TABLE products (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text          NOT NULL UNIQUE,
    list_price  numeric(12,0) NOT NULL CHECK (list_price >= 0)
);

CREATE TABLE orders (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_no     text        NOT NULL UNIQUE,
    ordered_on   date        NOT NULL,
    customer_id  bigint      NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    staff_id     bigint      NOT NULL REFERENCES staff (id),
    status       text        NOT NULL DEFAULT 'received'
                 CHECK (status IN ('received', 'shipped', 'cancelled')),
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE order_items (
    order_id    bigint        NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    product_id  bigint        NOT NULL REFERENCES products (id),
    qty         integer       NOT NULL CHECK (qty > 0),
    unit_price  numeric(12,0) NOT NULL CHECK (unit_price >= 0),
    PRIMARY KEY (order_id, product_id)
);

-- 外部キー列のインデックス（PostgreSQL は自動で作らない）
CREATE INDEX idx_staff_department_id    ON staff (department_id);
CREATE INDEX idx_customers_department   ON customers (department_id);
CREATE INDEX idx_orders_customer_date   ON orders (customer_id, ordered_on DESC);
CREATE INDEX idx_orders_staff_id        ON orders (staff_id);
CREATE INDEX idx_order_items_product_id ON order_items (product_id);

-- 在庫（単元3-2 のトランザクション演習用）
CREATE TABLE inventory (
    product_id  bigint  PRIMARY KEY REFERENCES products (id),
    stock       integer NOT NULL CHECK (stock >= 0),
    version     integer NOT NULL DEFAULT 1
);

-- CSV を読み込む
COPY staging_orders_raw FROM '/data/orders_raw.csv' WITH (FORMAT csv, HEADER true);
