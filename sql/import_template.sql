-- ============================================================
-- PMD SQL 数据导入模板
--
-- 使用说明：
--   1. 本文件用于"新增/合并物品"的 SQL 导入（设置 → 数据导入 → SQL 文件）。
--   2. 系统只会执行 INSERT INTO items 语句，其余语句将被忽略并报告。
--   3. 每条 INSERT 后必须有分号（;）结尾。
--   4. 序列号若与库中已有数据重复，系统按导入预览中你选择的方式处理（更新或放弃）。
--   5. 字段 last_modified 可留空，系统自动填为导入当天。
--   6. 请勿在导入文件中使用 DELETE/UPDATE/DROP/触发器/存储过程。
-- ============================================================

-- 示例一：新增一件消耗品（办公用品，序列号 HBG 前缀自动递增可留空序列号）
-- 注意：序列号留空时，必须填写 物品母类别(main_category) 和 物品子类别(sub_category)，
--       系统将自动生成序列号。
INSERT INTO items
  (serial_no, name, brand, location_code, new_location_code, container_serial,
   purchase_price, quantity, quarterly_consumption, unit, depreciation,
   notes, main_category, sub_category, barcode, last_modified)
VALUES
  ('HBG001', 'A4复印纸', '得力', 'OFFC', '', '',
   25.00, 10, 5, '包', 80,
   '行政采购', 'H', 'BG', '6901234567890', '2026-08-07');

-- 示例二：新增一件耐用品（电子设备，序列号自动生成示例：留空 serial_no）
INSERT INTO items
  (serial_no, name, brand, location_code, new_location_code, container_serial,
   purchase_price, quantity, quarterly_consumption, unit, depreciation,
   notes, main_category, sub_category, barcode, last_modified)
VALUES
  ('', '蓝牙键盘', '罗技', 'HOME', '', 'RSN001',
   199.00, 1, 0, '个', 100,
   '', 'N', 'DZ', '6901234567891', '2026-08-07');

-- 示例三：新增一个容器（收纳用品，R 前缀）
INSERT INTO items
  (serial_no, name, brand, location_code, new_location_code, container_serial,
   purchase_price, quantity, quarterly_consumption, unit, depreciation,
   notes, main_category, sub_category, barcode, last_modified)
VALUES
  ('RSN001', '透明收纳箱 60L', '禧天龙', 'HOME', '', '',
   59.90, 3, 0, '个', 90,
   '客厅储物', 'R', 'SN', '', '2026-08-07');

-- ------------------------------------------------------------
-- 复制以下区域作为你的导入模板（删除上面的示例后填写）
-- ------------------------------------------------------------
-- INSERT INTO items
--   (serial_no, name, brand, location_code, new_location_code, container_serial,
--    purchase_price, quantity, quarterly_consumption, unit, depreciation,
--    notes, main_category, sub_category, barcode, last_modified)
-- VALUES
--   ('', '', '', '', '', '',
--    0, 0, 0, '', 100,
--    '', '', '', '', '');
