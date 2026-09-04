#!/usr/bin/env bash
# ============================================================
# PMD 本地一键部署脚本（macOS/Linux + 本机 MySQL）
# 用法：bash tools/local_deploy.sh [新PIN] [端口]
#   - 端口默认 5019，可用第 2 个参数或环境变量 PMD_PORT 覆盖
#   - 自动创建数据库与账号（使用本机 MySQL root，brew 默认无密码）
#   - 通过 PHP/PDO（charset=utf8mb4）执行 sql/init.sql，杜绝中文乱码
#   - 生成 app/config.php
#   - 设置登录 PIN（默认 123456，可传参覆盖，如 bash tools/local_deploy.sh 888888）
# 说明：适用于本机开发/演示环境；服务器部署请使用网页安装向导（/install.php）
# ============================================================
set -e
cd "$(dirname "$0")/.."

PIN="${1:-123456}"
PORT="${2:-${PMD_PORT:-5019}}"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="pmd"
DB_USER="pmd"
DB_PASS="pmd_local_$(date +%s | tail -c 5)"   # 随机本地密码

echo "==> 1/4 检查 MySQL 服务…"
if ! mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" --silent 2>/dev/null; then
  echo "    MySQL 未运行，尝试启动（brew services / mysqld_safe）…"
  (brew services start mysql >/dev/null 2>&1 || true)
  for i in $(seq 1 30); do
    if mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" --silent 2>/dev/null; then break; fi
    sleep 2
  done
fi
mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" --silent || { echo "✘ MySQL 无法连接，请手动启动后重试"; exit 1; }
echo "    MySQL 运行中 ✔"

echo "==> 2/4 创建数据库与账号…"
mysql --default-character-set=utf8mb4 -h "$DB_HOST" -P "$DB_PORT" -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> 3/4 写入配置并初始化表结构与默认数据（PDO/utf8mb4，防乱码）…"
php -r '
$cfg = [
  "db" => ["host" => $argv[1], "port" => (int)$argv[2], "name" => $argv[3], "user" => $argv[4], "pass" => $argv[5], "charset" => "utf8mb4"],
  "session" => ["name" => "PMDSESSID", "lifetime" => 28800],
  "upload_max_bytes" => 10 * 1024 * 1024,
];
file_put_contents("app/config.php", "<?php\n// PMD 配置文件（由 local_deploy.sh 生成）\nreturn " . var_export($cfg, true) . ";\n");
echo "    config.php 已生成\n";
' "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS"

php tools/seed.php "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS"

echo "==> 4/4 设置登录 PIN 与网站标题…"
php -r '
$cfg = require "app/config.php";
$pdo = new PDO(
  "mysql:host={$cfg["db"]["host"]};port={$cfg["db"]["port"]};dbname={$cfg["db"]["name"]};charset=utf8mb4",
  $cfg["db"]["user"], $cfg["db"]["pass"],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$st = $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
$st->execute(["pin_hash", password_hash($argv[1], PASSWORD_DEFAULT)]);
$st->execute(["site_title", "个人物品管理数据库"]);
echo "    PIN 与标题已写入\n";
' "$PIN"

echo ""
echo "======================================================"
echo " 部署完成！"
echo "  访问地址 : http://127.0.0.1:$PORT"
echo "  登录 PIN : $PIN   （登录后请在 设置→常规 中修改）"
echo "  数据库   : $DB_NAME（账号 $DB_USER，密码已写入 app/config.php）"
echo "  启动命令 : php -S 127.0.0.1:$PORT -t public public/index.php"
echo "======================================================"
