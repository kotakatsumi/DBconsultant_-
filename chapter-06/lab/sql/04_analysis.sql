-- =====================================================================
-- 第6章 ハンズオン ステップ5：分析とコストの観察（単元6-2・6-4）
-- 1つずつ実行し、エディタ右上の「このクエリを実行すると ○○ が処理されます」を毎回メモする
-- =====================================================================

-- (1) 会計年度別の売上（答え合わせ用）
SELECT fiscal_year, SUM(amount) AS sales
FROM mart.sales_wide
WHERE is_valid_sale
GROUP BY fiscal_year
ORDER BY fiscal_year;

-- (2) 月別売上の推移（2025年度と2024年度の比較）
SELECT year_month, fiscal_year, SUM(amount) AS sales
FROM mart.sales_wide
WHERE is_valid_sale AND fiscal_year IN (2024, 2025)
GROUP BY year_month, fiscal_year
ORDER BY year_month;

-- (3) 顧客別売上トップ10（2025年度）
SELECT customer_name, customer_rank, SUM(amount) AS sales, COUNT(DISTINCT order_id) AS orders
FROM mart.sales_wide
WHERE is_valid_sale AND fiscal_year = 2025
GROUP BY customer_name, customer_rank
ORDER BY sales DESC
LIMIT 10;

-- (4) コストの観察：次の3つの「処理されるバイト数」を比べる
SELECT * FROM mart.sales_wide;
SELECT * FROM mart.sales_wide LIMIT 10;
SELECT order_date, amount FROM mart.sales_wide;

-- (5) 粒度の事故を再現する：受注単位の件数を、明細単位の表で数えてしまう
SELECT COUNT(*)                 AS wrong_order_count,   -- 明細の行数（多すぎる）
       COUNT(DISTINCT order_id) AS right_order_count    -- 正しい受注件数
FROM mart.sales_wide;
