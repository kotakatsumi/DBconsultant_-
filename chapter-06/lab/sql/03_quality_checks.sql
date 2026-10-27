-- =====================================================================
-- 第6章 ハンズオン ステップ4：品質チェック（単元6-3）
-- すべての行で ok = true になっていれば合格
-- =====================================================================
SELECT '受注IDの重複がない' AS check_name,
       COUNT(*) = COUNT(DISTINCT order_id) AS ok,
       FORMAT('%d 行 / %d 件', COUNT(*), COUNT(DISTINCT order_id)) AS detail
FROM staging.orders

UNION ALL
SELECT 'raw の注文数と staging の件数が一致',
       (SELECT COUNT(DISTINCT id) FROM raw.orders) = (SELECT COUNT(*) FROM staging.orders),
       FORMAT('raw=%d, staging=%d', (SELECT COUNT(DISTINCT id) FROM raw.orders), (SELECT COUNT(*) FROM staging.orders))

UNION ALL
SELECT '明細の注文が必ず存在する（孤児がない）',
       COUNT(*) = 0,
       FORMAT('孤児の明細 %d 行', COUNT(*))
FROM staging.order_items AS i
LEFT JOIN staging.orders AS o ON o.order_id = i.order_id
WHERE o.order_id IS NULL

UNION ALL
SELECT '金額に NULL やマイナスがない',
       COUNTIF(amount IS NULL OR amount < 0) = 0,
       FORMAT('問題のある行 %d', COUNTIF(amount IS NULL OR amount < 0))
FROM mart.fct_sales

UNION ALL
SELECT 'fct_sales と sales_wide の行数が一致（JOIN で行が増減していない）',
       (SELECT COUNT(*) FROM mart.fct_sales) = (SELECT COUNT(*) FROM mart.sales_wide),
       FORMAT('fct=%d, wide=%d', (SELECT COUNT(*) FROM mart.fct_sales), (SELECT COUNT(*) FROM mart.sales_wide))

UNION ALL
SELECT 'データが新しい（最新の受注日が直近）',
       MAX(order_date) >= DATE '2026-09-01',     -- 本番では CURRENT_DATE() との比較にする
       FORMAT('最新の受注日 %t', MAX(order_date))
FROM mart.fct_sales;
