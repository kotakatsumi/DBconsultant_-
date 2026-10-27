-- =====================================================================
-- 演習2 の解答例：staging_orders_raw → 正規化テーブル
-- まず自分で書いてから、答え合わせに使ってください。
-- =====================================================================
BEGIN;  -- 全部成功するか、全部なかったことにする（単元3-2）

-- 1) 部署
INSERT INTO departments (name)
SELECT DISTINCT trim(department) FROM staging_orders_raw;

-- 2) 担当者（担当者 → 部署 は推移的従属なので staff 側に持つ）
INSERT INTO staff (name, department_id)
SELECT DISTINCT trim(s.staff_name), d.id
FROM staging_orders_raw s
JOIN departments d ON d.name = trim(s.department);

-- 3) 顧客：表記ゆれがあるので「電話番号」を同一顧客の判定キーにする
--    名前は最も多く使われている表記、担当部署も最も多いものを採用
INSERT INTO customers (name, address, phone, department_id, source, source_id)
SELECT mode() WITHIN GROUP (ORDER BY trim(s.customer_name)),
       mode() WITHIN GROUP (ORDER BY trim(s.customer_address)),
       trim(s.customer_phone),
       mode() WITHIN GROUP (ORDER BY d.id),
       'excel',
       trim(s.customer_phone)          -- Excel には ID がないので電話番号を取り込み元IDとする
FROM staging_orders_raw s
JOIN departments d ON d.name = trim(s.department)
GROUP BY trim(s.customer_phone);

-- 4) 商品：標準単価は最も多く使われている単価
INSERT INTO products (name, list_price)
SELECT trim(product_name),
       mode() WITHIN GROUP (ORDER BY unit_price::numeric)
FROM staging_orders_raw
GROUP BY trim(product_name);

-- 5) 受注（文字列の日付を date 型に変換）
INSERT INTO orders (order_no, ordered_on, customer_id, staff_id)
SELECT DISTINCT
       trim(s.order_no),
       to_date(s.ordered_on, 'YYYY/MM/DD'),
       c.id,
       st.id
FROM staging_orders_raw s
JOIN customers c  ON c.source = 'excel' AND c.source_id = trim(s.customer_phone)
JOIN staff     st ON st.name = trim(s.staff_name);

-- 6) 受注明細（販売時の単価は明細に残す）
INSERT INTO order_items (order_id, product_id, qty, unit_price)
SELECT o.id, p.id, s.qty::integer, s.unit_price::numeric
FROM staging_orders_raw s
JOIN orders   o ON o.order_no = trim(s.order_no)
JOIN products p ON p.name     = trim(s.product_name);

-- 7) 在庫の初期値（演習4 で使う）
INSERT INTO inventory (product_id, stock)
SELECT id, 1000 FROM products;

COMMIT;

-- 確認：元の Excel と同じ見た目の表を JOIN で再現する
SELECT o.order_no, o.ordered_on, c.name AS customer, st.name AS staff,
       d.name AS department, p.name AS product, i.unit_price, i.qty,
       i.unit_price * i.qty AS amount
FROM orders o
JOIN customers   c  ON c.id  = o.customer_id
JOIN staff       st ON st.id = o.staff_id
JOIN departments d  ON d.id  = st.department_id
JOIN order_items i  ON i.order_id = o.id
JOIN products    p  ON p.id  = i.product_id
ORDER BY o.order_no, p.name;
