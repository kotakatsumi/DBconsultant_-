"""
第6章 ハンズオン用のサンプルデータを生成する（標準ライブラリのみ）。
PostgreSQL から BigQuery の raw 層に取り込んだ「想定」の CSV を作る。

  python3 generate_data.py

- raw_orders.csv には、同じ注文の「更新前」と「更新後」が両方入っている（単元6-3の重複除去の練習用）
- 期間は 2024年4月〜2026年9月（2.5 会計年度）
"""
import csv
import random
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

random.seed(2026)
OUT = Path(__file__).parent / "data"
OUT.mkdir(exist_ok=True)
JST = timezone(timedelta(hours=9))


def write(name, header, rows):
    with open(OUT / name, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)
    print(f"{name}: {len(rows)} 行")


# --- 担当者 ---
staff = [
    (1, "佐藤", "営業1課"), (2, "鈴木", "営業1課"), (3, "高橋", "営業2課"),
    (4, "田中", "営業2課"), (5, "伊藤", "営業3課"), (6, "渡辺", "営業3課"),
]
write("staff.csv", ["id", "name", "department"], staff)

# --- 商品 ---
products = [
    (1, "ボルトA", "締結部品", 120), (2, "ナットB", "締結部品", 80), (3, "ワッシャC", "締結部品", 30),
    (4, "ベアリングD", "回転部品", 1800), (5, "ギアE", "回転部品", 4500), (6, "シャフトF", "回転部品", 3200),
    (7, "パッキンG", "シール部品", 250), (8, "オイルシールH", "シール部品", 900),
    (9, "工具セットI", "工具", 12000), (10, "測定器J", "工具", 38000),
]
write("products.csv", ["id", "name", "category", "list_price"], products)

# --- 顧客 ---
prefs = ["東京都"] * 5 + ["神奈川県"] * 3 + ["埼玉県"] * 2 + ["愛知県"] * 3 + ["大阪府"] * 3 + ["福岡県"] * 2 + ["北海道", "宮城県", "広島県"]
industries = ["製造業", "建設業", "卸売業", "小売業", "サービス業"]
prefixes = ["山田", "田中", "高橋", "中村", "小林", "加藤", "吉田", "山本", "松本", "井上", "木村", "林", "清水", "森", "池田"]
suffixes = ["商店", "工業", "製作所", "商事", "産業", "電機", "建設", "物産"]
customers = []
for i in range(1, 121):
    name = f"{random.choice(prefixes)}{random.choice(suffixes)}"
    if any(c[1] == name for c in customers):
        name += f"{i}"
    customers.append((i, name, random.choice(prefs), random.choice(industries),
                      random.choices(["A", "B", "C"], weights=[2, 5, 8])[0], random.choice(staff)[0]))
write("customers.csv", ["id", "name", "prefecture", "industry", "rank", "staff_id"], customers)

# --- 受注・明細 ---
start, end = date(2024, 4, 1), date(2026, 9, 30)
orders, items, raw_orders = [], [], []
oid = 0
d = start
while d <= end:
    # 平日に多く、年々少しずつ増え、3月（年度末）と12月に山がある
    base = 3.2 if d.weekday() < 5 else 0.4
    growth = 1 + (d - start).days / 365 * 0.12
    season = 1.4 if d.month in (3, 12) else (0.8 if d.month == 8 else 1.0)
    for _ in range(int(random.gauss(base * growth * season, 1.2)) if base > 1 else random.randint(0, 1)):
        oid += 1
        c = random.choice(customers)
        weight = {"A": 3, "B": 2, "C": 1}[c[4]]
        created = datetime(d.year, d.month, d.day, random.randint(9, 18), random.randint(0, 59), tzinfo=JST)
        status = random.choices(["shipped", "cancelled", "received"], weights=[90, 5, 5])[0]
        if (end - d).days > 14 and status == "received":
            status = "shipped"
        updated = created + timedelta(days=random.randint(1, 10)) if status != "received" else created
        order = [oid, f"A-{oid:06d}", d.isoformat(), c[0], c[5], status, updated.isoformat()]
        orders.append(order)
        # raw には「受付時点」の版も入っている（後で shipped / cancelled に更新された）
        loaded_first = created + timedelta(hours=1)
        raw_orders.append([oid, f"A-{oid:06d}", d.isoformat(), c[0], c[5], "received", created.isoformat(), loaded_first.isoformat()])
        if status != "received":
            raw_orders.append(order + [(updated + timedelta(hours=1)).isoformat()])
        for p in random.sample(products, random.randint(1, 3)):
            qty = random.randint(1, 20) * (10 if p[3] < 1000 else 1) * weight
            price = round(p[3] * random.choice([1, 1, 1, 0.95, 0.9]))
            items.append((oid, p[0], qty, price))
    d += timedelta(days=1)

write("raw_orders.csv", ["id", "order_no", "ordered_on", "customer_id", "staff_id", "status", "updated_at", "_loaded_at"], raw_orders)
write("order_items.csv", ["order_id", "product_id", "qty", "unit_price"], items)
print(f"（参考）注文の実数: {len(orders)} 件")
