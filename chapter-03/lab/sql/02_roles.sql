-- =====================================================================
-- 第3章 ハンズオン：ロールの分離と行レベルセキュリティ（単元3-5）
-- パスワードは学習用。本番では Secret Manager で管理し、ファイルに書かない。
-- =====================================================================
SET client_min_messages = warning;

CREATE ROLE app_rw     LOGIN PASSWORD 'lab_app_rw_password';
CREATE ROLE etl_reader LOGIN PASSWORD 'lab_etl_password';
CREATE ROLE analyst_ro LOGIN PASSWORD 'lab_analyst_password';

-- 既定で全員（PUBLIC）に与えられている権限を取り上げる
REVOKE ALL ON DATABASE salesdb FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;

GRANT CONNECT ON DATABASE salesdb TO app_rw, etl_reader, analyst_ro;
GRANT USAGE   ON SCHEMA public    TO app_rw, etl_reader, analyst_ro;

-- アプリ：読み書き。ただし DELETE は与えない（論理削除で運用）
GRANT SELECT, INSERT, UPDATE
    ON departments, staff, customers, products, orders, order_items, inventory
    TO app_rw;

-- BigQuery 同期：読み取りのみ
GRANT SELECT
    ON departments, staff, customers, products, orders, order_items
    TO etl_reader;

-- 分析担当：個人情報を落としたビューだけ
CREATE VIEW v_customers_masked AS
SELECT id,
       name,
       substring(address from '^(北海道|東京都|京都府|大阪府|.{2,3}?県)') AS prefecture,
       department_id,
       created_at
FROM customers;
GRANT SELECT ON v_customers_masked TO analyst_ro;

-- ---------------------------------------------------------------------
-- 行レベルセキュリティ：app_rw は「自分の部署の顧客」だけ見える
--   アプリは接続後に  SET app.department_id = '1';  を実行する想定
-- ---------------------------------------------------------------------
ALTER TABLE customers ENABLE ROW LEVEL SECURITY;

CREATE POLICY dept_isolation ON customers
    FOR ALL
    TO app_rw
    USING      (department_id = current_setting('app.department_id', true)::bigint)
    WITH CHECK (department_id = current_setting('app.department_id', true)::bigint);

-- BigQuery 同期は全件読む必要があるので、全行を許可するポリシーを別に作る
CREATE POLICY etl_read_all ON customers
    FOR SELECT
    TO etl_reader
    USING (true);
