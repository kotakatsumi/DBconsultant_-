-- =====================================================================
-- 第6章 ハンズオン ステップ3：staging → mart（単元6-4）
-- =====================================================================

-- 日付ディメンション（4月始まりの会計年度つき）
CREATE OR REPLACE TABLE mart.dim_date AS
SELECT
  d                                                         AS date,
  EXTRACT(YEAR  FROM d)                                     AS year,
  EXTRACT(MONTH FROM d)                                     AS month,
  FORMAT_DATE('%Y-%m', d)                                   AS year_month,
  IF(EXTRACT(MONTH FROM d) >= 4, EXTRACT(YEAR FROM d), EXTRACT(YEAR FROM d) - 1) AS fiscal_year,
  DIV(MOD(EXTRACT(MONTH FROM d) + 8, 12), 3) + 1            AS fiscal_quarter,   -- 4〜6月=1, … 1〜3月=4
  ['日', '月', '火', '水', '木', '金', '土'][OFFSET(EXTRACT(DAYOFWEEK FROM d) - 1)] AS weekday_ja,
  EXTRACT(DAYOFWEEK FROM d) IN (1, 7)                       AS is_weekend
FROM UNNEST(GENERATE_DATE_ARRAY('2024-04-01', '2027-03-31')) AS d;

CREATE OR REPLACE TABLE mart.dim_customer AS
SELECT customer_id, name, prefecture, industry, rank
FROM staging.customers;

CREATE OR REPLACE TABLE mart.dim_product AS
SELECT product_id, name, category
FROM staging.products;

CREATE OR REPLACE TABLE mart.dim_staff AS
SELECT staff_id, name, department
FROM staging.staff;

-- ファクト：粒度は「受注明細」。売上の定義はここで1回だけ決める
CREATE OR REPLACE TABLE mart.fct_sales AS
SELECT
  i.order_id,
  o.ordered_on                  AS order_date,
  o.customer_id,
  i.product_id,
  o.staff_id,
  i.qty,
  i.unit_price,
  i.qty * i.unit_price          AS amount,            -- 税抜・販売時単価ベース
  o.status,
  o.status != 'cancelled'       AS is_valid_sale      -- キャンセルは売上に数えない
FROM staging.order_items AS i
JOIN staging.orders      AS o ON o.order_id = i.order_id;

-- ダッシュボード用の横長の表（必要な列だけ）
CREATE OR REPLACE TABLE mart.sales_wide AS
SELECT
  f.order_id,
  f.order_date,
  d.year_month,
  d.fiscal_year,
  d.fiscal_quarter,
  d.weekday_ja,
  c.name        AS customer_name,
  c.prefecture  AS customer_prefecture,
  c.industry    AS customer_industry,
  c.rank        AS customer_rank,
  p.name        AS product_name,
  p.category    AS product_category,
  s.name        AS staff_name,
  s.department,
  f.qty,
  f.amount,
  f.is_valid_sale
FROM mart.fct_sales    AS f
JOIN mart.dim_date     AS d ON d.date        = f.order_date
JOIN mart.dim_customer AS c ON c.customer_id = f.customer_id
JOIN mart.dim_product  AS p ON p.product_id  = f.product_id
JOIN mart.dim_staff    AS s ON s.staff_id    = f.staff_id;
