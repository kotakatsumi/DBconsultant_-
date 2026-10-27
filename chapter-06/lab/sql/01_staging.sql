-- =====================================================================
-- 第6章 ハンズオン ステップ2：raw → staging（単元6-3）
-- BigQuery のクエリエディタに貼り付けて、まとめて実行する。
-- ※ BigQuery サンドボックスでは、パーティションに60日の有効期限が自動で付き、
--   古い日付のパーティションが消えてしまうため、ここでは PARTITION BY を付けていない。
--   課金を有効にした本番環境では PARTITION BY ordered_on を付けること（単元6-2）。
-- =====================================================================

-- 受注：raw には同じ注文の複数の版が入っている → 各注文の最新版だけを残す
CREATE OR REPLACE TABLE staging.orders AS
SELECT
  CAST(id AS INT64)              AS order_id,
  CAST(order_no AS STRING)       AS order_no,
  CAST(ordered_on AS DATE)       AS ordered_on,
  CAST(customer_id AS INT64)     AS customer_id,
  CAST(staff_id AS INT64)        AS staff_id,
  CAST(status AS STRING)         AS status,
  CAST(updated_at AS TIMESTAMP)  AS updated_at
FROM raw.orders
WHERE TRUE
QUALIFY ROW_NUMBER() OVER (
  PARTITION BY id
  ORDER BY CAST(updated_at AS TIMESTAMP) DESC, CAST(_loaded_at AS TIMESTAMP) DESC
) = 1;

CREATE OR REPLACE TABLE staging.order_items AS
SELECT
  CAST(order_id AS INT64)      AS order_id,
  CAST(product_id AS INT64)    AS product_id,
  CAST(qty AS INT64)           AS qty,
  CAST(unit_price AS NUMERIC)  AS unit_price
FROM raw.order_items;

CREATE OR REPLACE TABLE staging.customers AS
SELECT
  CAST(id AS INT64)  AS customer_id,
  name, prefecture, industry, rank,
  CAST(staff_id AS INT64) AS staff_id
FROM raw.customers;

CREATE OR REPLACE TABLE staging.products AS
SELECT CAST(id AS INT64) AS product_id, name, category, CAST(list_price AS NUMERIC) AS list_price
FROM raw.products;

CREATE OR REPLACE TABLE staging.staff AS
SELECT CAST(id AS INT64) AS staff_id, name, department
FROM raw.staff;
