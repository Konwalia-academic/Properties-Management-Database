-- ============================================================
-- PMD 个人物品管理数据库 初始化脚本
-- 适用于 MySQL 5.7+ / 8.0+，字符集 utf8mb4
-- 可通过网页安装向导自动执行，也可手动执行本文件
-- ============================================================

CREATE DATABASE IF NOT EXISTS pmd DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pmd;

-- ------------------------------------------------------------
-- 类别表（母类别 × 子类别 组合）
-- 母类别：R=容器 N=耐用品 H=消耗品 B=已报废
-- 子类别：BG/QJ/RH/SN/WJ/DZ/FS/YP/SP/CJ/YS/GH/ZS/YD/AQ/FZ 等，可在设置中扩展
-- 说明：R（容器）与其他母类别一样，预置覆盖全部 16 种子类别；
--      导入数据遇到不存在的组合时系统也会自动创建（见 app/lib/categories.php）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  main_code CHAR(1) NOT NULL COMMENT '母类别代码 R/N/H/B',
  sub_code  CHAR(2) NOT NULL COMMENT '子类别代码（2位字母）',
  main_name VARCHAR(50) NOT NULL COMMENT '母类别名称',
  sub_name  VARCHAR(50) NOT NULL COMMENT '子类别名称',
  PRIMARY KEY (main_code, sub_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='物品类别';

-- ------------------------------------------------------------
-- 位置代码表
-- LTO 为系统保留代码，表示"借出及在途"，不得删除
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
  code       VARCHAR(4)  NOT NULL COMMENT '位置代码（2-4位字母）',
  name       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '位置名称',
  sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='位置代码';

-- ------------------------------------------------------------
-- 物品主表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no           CHAR(6)      NOT NULL COMMENT '序列号（3字母+3数字，唯一）',
  name                VARCHAR(200) NOT NULL COMMENT '物品名称',
  brand               VARCHAR(100) NOT NULL DEFAULT '' COMMENT '品牌',
  location_code       VARCHAR(4)   NOT NULL DEFAULT '' COMMENT '目前所在位置代码',
  new_location_code   VARCHAR(4)   NOT NULL DEFAULT '' COMMENT '新所在位置代码（交换作业用）',
  container_serial    CHAR(6)      NOT NULL DEFAULT '' COMMENT '所在容器序列号',
  purchase_price      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '购入价格',
  quantity            DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '余量（支持小数）',
  quarterly_consumption DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '季度消耗量（支持小数）',
  unit                VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '单位',
  depreciation        TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '仓储/折旧情况(%)，数字越低越旧',
  notes               TEXT COMMENT '备注',
  main_category       CHAR(1)      NOT NULL COMMENT '物品母类别',
  sub_category        CHAR(2)      NOT NULL COMMENT '物品子类别',
  barcode             VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '商品条形码',
  hygiene_level       CHAR(1)      NOT NULL DEFAULT '' COMMENT '卫生等级（A/B/C/D 或自定义，可空）',
  last_modified       DATE         NOT NULL COMMENT '最新修改日期（自动）',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY uk_serial (serial_no),
  KEY idx_location (location_code),
  KEY idx_new_location (new_location_code),
  KEY idx_name (name),
  KEY idx_main (main_category),
  KEY idx_sub (sub_category),
  KEY idx_dep (depreciation),
  KEY idx_container (container_serial),
  KEY idx_hygiene (hygiene_level),
  KEY idx_modified (last_modified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='物品';

-- ------------------------------------------------------------
-- 借还记录表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS borrow_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no   CHAR(6)      NOT NULL COMMENT '物品序列号',
  borrower    VARCHAR(100) NOT NULL COMMENT '借用人',
  borrowed_at DATE         NOT NULL COMMENT '借出日期',
  returned_at DATE         NULL COMMENT '归还日期',
  note        TEXT COMMENT '记录说明',
  PRIMARY KEY (id),
  KEY idx_serial (serial_no),
  KEY idx_borrower (borrower)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='借还记录';

-- ------------------------------------------------------------
-- 系统设置表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64) NOT NULL COMMENT '设置键',
  svalue TEXT COMMENT '设置值',
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- ------------------------------------------------------------
-- 卫生等级表
-- 预置：A 食品接触 / B 母婴与敏感部位接触 / C 皮肤接触 / D 地面与脏污材料接触
-- 可在 设置→卫生等级管理 中新增/改名/删除；导入遇到未登记等级时自动创建
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hygiene_levels (
  code       CHAR(1)     NOT NULL COMMENT '等级代码（1位字母）',
  name       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '等级名称',
  sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卫生等级';

-- ------------------------------------------------------------
-- 默认类别数据（母类别×子类别组合）
-- R/N/H/B 均覆盖全部 16 种子类别；导入遇到新组合时自动创建
-- ------------------------------------------------------------
INSERT IGNORE INTO categories (main_code, sub_code, main_name, sub_name) VALUES
('R','BG','容器','办公用品'),
('R','QJ','容器','卫生清洁用品'),
('R','RH','容器','日化用品'),
('R','SN','容器','收纳用品'),
('R','WJ','容器','五金工具'),
('R','DZ','容器','电子设备'),
('R','FS','容器','服饰品'),
('R','YP','容器','药品'),
('R','SP','容器','食品饮品'),
('R','CJ','容器','餐厨用具'),
('R','YS','容器','印刷品'),
('R','GH','容器','个人护理用品'),
('R','ZS','容器','装饰品'),
('R','YD','容器','运动器械'),
('R','AQ','容器','安全设施'),
('R','FZ','容器','其他纺织品'),
('N','BG','耐用品','办公用品'),
('N','QJ','耐用品','卫生清洁用品'),
('N','RH','耐用品','日化用品'),
('N','SN','耐用品','收纳用品'),
('N','WJ','耐用品','五金工具'),
('N','DZ','耐用品','电子设备'),
('N','FS','耐用品','服饰品'),
('N','YP','耐用品','药品'),
('N','SP','耐用品','食品饮品'),
('N','CJ','耐用品','餐厨用具'),
('N','YS','耐用品','印刷品'),
('N','GH','耐用品','个人护理用品'),
('N','ZS','耐用品','装饰品'),
('N','YD','耐用品','运动器械'),
('N','AQ','耐用品','安全设施'),
('N','FZ','耐用品','其他纺织品'),
('H','BG','消耗品','办公用品'),
('H','QJ','消耗品','卫生清洁用品'),
('H','RH','消耗品','日化用品'),
('H','SN','消耗品','收纳用品'),
('H','WJ','消耗品','五金工具'),
('H','DZ','消耗品','电子设备'),
('H','FS','消耗品','服饰品'),
('H','YP','消耗品','药品'),
('H','SP','消耗品','食品饮品'),
('H','CJ','消耗品','餐厨用具'),
('H','YS','消耗品','印刷品'),
('H','GH','消耗品','个人护理用品'),
('H','ZS','消耗品','装饰品'),
('H','YD','消耗品','运动器械'),
('H','AQ','消耗品','安全设施'),
('H','FZ','消耗品','其他纺织品'),
('B','BG','已报废','办公用品'),
('B','QJ','已报废','卫生清洁用品'),
('B','RH','已报废','日化用品'),
('B','SN','已报废','收纳用品'),
('B','WJ','已报废','五金工具'),
('B','DZ','已报废','电子设备'),
('B','FS','已报废','服饰品'),
('B','YP','已报废','药品'),
('B','SP','已报废','食品饮品'),
('B','CJ','已报废','餐厨用具'),
('B','YS','已报废','印刷品'),
('B','GH','已报废','个人护理用品'),
('B','ZS','已报废','装饰品'),
('B','YD','已报废','运动器械'),
('B','AQ','已报废','安全设施'),
('B','FZ','已报废','其他纺织品');

-- ------------------------------------------------------------
-- 默认位置代码（预设，可在设置中新增/修改/删除；LTO 为系统保留，不得删除）
-- ------------------------------------------------------------
INSERT IGNORE INTO locations (code, name, sort_order) VALUES
('LTO','借出及在途',0),
('FA','通辽住所办公桌',10),
('FB','通辽住所床',11),
('FC','通辽住所衣柜',12),
('FD','通辽住所储物架',13),
('FE','通辽住所其他',14),
('TL','通辽市其他',20),
('CZT','长株潭区域其他',30),
('NJ','南京市其他',40),
('SZ','苏州市其他',50),
('SH','上海市其他',60),
('SY','沈阳市其他',70),
('WH','武汉市其他',80);

-- ------------------------------------------------------------
-- 默认卫生等级（可在 设置→卫生等级管理 中修改）
-- ------------------------------------------------------------
INSERT IGNORE INTO hygiene_levels (code, name, sort_order) VALUES
('A','食品接触',10),
('B','母婴与敏感部位接触',20),
('C','皮肤接触',30),
('D','地面与脏污材料接触',40);

-- ------------------------------------------------------------
-- 默认设置
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (skey, svalue) VALUES
('site_title','个人物品管理数据库'),
('logo',''),
('theme_accent','#2563eb'),
('language','zh-CN'),
('rows_per_page','30');
