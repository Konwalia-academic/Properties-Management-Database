<?php
/**
 * ============================================================
 *  PMD v3.0.1 升级工具（卫生等级功能）— 单文件网页版
 * ============================================================
 *  用法：
 *    1. 把本文件上传到服务器（建议放网站根目录 public/ 下），
 *       例如：https://你的域名/upgrade_301.php
 *    2. 浏览器打开该地址，点击「执行升级」即可。
 *
 *  本文件会自动完成以下数据库变更（幂等，可重复执行，不影响现有数据）：
 *    1. items 表新增 hygiene_level 列（旧数据留空）+ idx_hygiene 索引
 *    2. 新建 hygiene_levels 表（卫生等级方案）
 *    3. 写入预置等级 A/B/C/D（食品接触 / 母婴与敏感部位接触 / 皮肤接触 / 地面与脏污材料接触）
 *    4. 在 settings 表记录 db_version = 3.0.1
 *
 *  说明：
 *    - 若能在同级/上级目录找到 app/config.php，则直接使用现有数据库配置（不覆盖）。
 *    - 若找不到 config.php，会在网页中请你填写数据库连接信息；
 *      升级成功后（若目录可写）自动生成 app/config.php，方便后续访问系统。
 *    - 升级完成后请删除本文件。
 *
 *  命令行也可运行：php upgrade_301.php [db_host] [db_port] [db_name] [db_user] [db_pass]
 * ============================================================
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$IS_CLI = (PHP_SAPI === 'cli');

// ---------------- 工具函数 ----------------

/** 查找 app/config.php */
function pmd_find_config(): ?string
{
    $candidates = [
        __DIR__ . '/app/config.php',
        __DIR__ . '/../app/config.php',
        __DIR__ . '/../../app/config.php',
        dirname(__DIR__) . '/app/config.php',
        dirname(__DIR__) . '/../app/config.php',
    ];
    foreach (array_unique($candidates) as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/** 从 POST / 默认值读取数据库参数 */
function pmd_db_input(string $k, string $default = ''): string
{
    return trim((string)($_POST[$k] ?? $default));
}

/** 建立连接 */
function pmd_connect(array $db): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? '127.0.0.1',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? 'pmd',
        $db['charset'] ?? 'utf8mb4'
    );
    return new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** 执行升级，返回日志数组 */
function pmd_upgrade(PDO $pdo): array
{
    $log = [];

    // 1. items 表是否存在
    $t = $pdo->query("SHOW TABLES LIKE 'items'")->fetch();
    if (!$t) {
        throw new RuntimeException('未找到 items 表，请确认数据库名正确（升级前系统应已正常部署）。');
    }

    // 2. items.hygiene_level 列
    $col = $pdo->query("SHOW COLUMNS FROM items LIKE 'hygiene_level'")->fetch();
    if ($col) {
        $log[] = 'items.hygiene_level 列已存在，跳过';
    } else {
        $pdo->exec("ALTER TABLE items ADD COLUMN hygiene_level CHAR(1) NOT NULL DEFAULT '' COMMENT '卫生等级（A/B/C/D 或自定义，可空）' AFTER barcode");
        $log[] = '已为 items 表新增 hygiene_level 列';
    }

    // 3. idx_hygiene 索引
    $idx = $pdo->query("SHOW INDEX FROM items WHERE Key_name = 'idx_hygiene'")->fetch();
    if ($idx) {
        $log[] = 'items.idx_hygiene 索引已存在，跳过';
    } else {
        $pdo->exec('ALTER TABLE items ADD KEY idx_hygiene (hygiene_level)');
        $log[] = '已为 items 表新增 idx_hygiene 索引';
    }

    // 4. hygiene_levels 表
    $pdo->exec("CREATE TABLE IF NOT EXISTS hygiene_levels (
        code       CHAR(1)      NOT NULL COMMENT '等级代码（1位字母）',
        name       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '等级名称',
        sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
        PRIMARY KEY (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='卫生等级'");
    $log[] = 'hygiene_levels 表已就绪';

    // 5. 预置等级（INSERT IGNORE，不覆盖已存在的自定义记录）
    $st = $pdo->prepare('INSERT IGNORE INTO hygiene_levels (code, name, sort_order) VALUES (?,?,?)');
    $defaults = [
        ['A', '食品接触', 10],
        ['B', '母婴与敏感部位接触', 20],
        ['C', '皮肤接触', 30],
        ['D', '地面与脏污材料接触', 40],
    ];
    $inserted = 0;
    foreach ($defaults as $row) {
        $st->execute($row);
        $inserted += $st->rowCount();
    }
    $log[] = '预置卫生等级：新增 ' . $inserted . ' 个（A/B/C/D）';

    // 6. 记录版本
    try {
        $pdo->exec("INSERT INTO settings (skey, svalue) VALUES ('db_version', '3.0.1')
                    ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
        $log[] = '已记录数据库版本 db_version = 3.0.1';
    } catch (Throwable $e) {
        $log[] = '（提示）settings 表写入 db_version 失败：' . $e->getMessage();
    }

    // 7. 汇总校验
    $levels = $pdo->query('SELECT code, name FROM hygiene_levels ORDER BY sort_order, code')->fetchAll();
    $log[] = '当前卫生等级方案：' . (count($levels) ? implode('、', array_map(fn($l) => $l['code'] . '=' . $l['name'], $levels)) : '(空)');
    $total = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $log[] = '物品总数：' . $total . '（旧数据卫生等级留空，可在编辑时补充）';

    return $log;
}

/** 尝试生成 app/config.php（仅当不存在时），返回 [ok, path|err] */
function pmd_try_write_config(array $db): array
{
    $dirs = array_unique([
        __DIR__ . '/app',
        __DIR__ . '/../app',
    ]);
    $target = '';
    foreach ($dirs as $d) {
        if (is_dir($d)) {
            $target = $d . '/config.php';
            break;
        }
    }
    if ($target === '') {
        if (@mkdir(__DIR__ . '/app', 0775, true)) {
            $target = __DIR__ . '/app/config.php';
        }
    }
    if ($target === '') {
        return [false, '未找到可写的 app 目录，请手动创建 app/config.php'];
    }
    if (is_file($target)) {
        return [false, 'app/config.php 已存在，未覆盖'];
    }
    $config = [
        'db' => [
            'host'    => $db['host'],
            'port'    => (int)$db['port'],
            'name'    => $db['name'],
            'user'    => $db['user'],
            'pass'    => $db['pass'],
            'charset' => 'utf8mb4',
        ],
        'session' => ['name' => 'PMDSESSID', 'lifetime' => 28800],
        'upload_max_bytes' => 10 * 1024 * 1024,
    ];
    $php = "<?php\n// PMD 配置文件（由 upgrade_301.php 生成）\nreturn " . var_export($config, true) . ";\n";
    if (@file_put_contents($target, $php) === false) {
        return [false, '写入 ' . $target . ' 失败，请检查目录写权限'];
    }
    return [true, '已生成 ' . $target];
}

// ---------------- 命令行模式 ----------------
if ($IS_CLI) {
    if (count($argv) >= 6) {
        $db = [
            'host' => $argv[1],
            'port' => (int)$argv[2],
            'name' => $argv[3],
            'user' => $argv[4],
            'pass' => $argv[5],
            'charset' => 'utf8mb4',
        ];
    } else {
        $cfgFile = pmd_find_config();
        if (!$cfgFile) {
            fwrite(STDERR, "未找到 app/config.php。\n用法: php upgrade_301.php [db_host] [db_port] [db_name] [db_user] [db_pass]\n");
            exit(1);
        }
        $cfg = require $cfgFile;
        $db = ($cfg['db'] ?? null);
        if (!is_array($db)) {
            fwrite(STDERR, "config.php 缺少 db 配置。\n");
            exit(1);
        }
    }
    try {
        $pdo = pmd_connect($db);
        echo "PMD v3.0.1 升级结果：\n";
        foreach (pmd_upgrade($pdo) as $line) {
            echo "  - $line\n";
        }
        echo "升级完成。\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '升级失败：' . $e->getMessage() . "\n");
        exit(1);
    }
}

// ============================================================
// 网页模式
// ============================================================

$configFile = pmd_find_config();
$dbFromConfig = null;
if ($configFile) {
    try {
        $cfg = require $configFile;
        $dbFromConfig = is_array($cfg['db'] ?? null) ? $cfg['db'] : null;
    } catch (Throwable $e) {
        $dbFromConfig = null;
    }
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$runRequested = $isPost && (($_POST['run'] ?? '') === '1');

// 决定使用的数据库配置
$db = $dbFromConfig;
if ($db === null) {
    $db = [
        'host' => pmd_db_input('db_host', '127.0.0.1'),
        'port' => pmd_db_input('db_port', '3306'),
        'name' => pmd_db_input('db_name', 'pmd'),
        'user' => pmd_db_input('db_user'),
        'pass' => (string)($_POST['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];
}

$result = null;   // ['ok' => bool, 'log' => [], 'config_written' => ...]
$error = '';

if ($runRequested) {
    // 若无 config，校验必填项
    if ($dbFromConfig === null) {
        if ($db['user'] === '' || $db['name'] === '') {
            $error = '请填写数据库名称与用户名。';
            $runRequested = false;
        }
    }
}

if ($runRequested) {
    try {
        $pdo = pmd_connect($db);
        $log = pmd_upgrade($pdo);
        $configWritten = null;
        if ($dbFromConfig === null) {
            $configWritten = pmd_try_write_config($db);
        }
        $result = ['ok' => true, 'log' => $log, 'config_written' => $configWritten];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'log' => ['升级失败：' . $e->getMessage()], 'config_written' => null];
    }
}

// ---------------- 页面输出 ----------------
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
echo '<title>PMD v3.0.1 升级</title></head>';
echo '<body style="margin:0;font-family:-apple-system,\'PingFang SC\',\'Microsoft YaHei\',sans-serif;background:#f1f5f9;color:#1e293b;padding:30px 16px">';
echo '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.08)">';
echo '<h1 style="font-size:22px;margin:0 0 6px">PMD v3.0.1 升级</h1>';
echo '<div style="color:#64748b;font-size:13px;margin-bottom:18px">新增「卫生等级」功能 · 幂等可重复执行 · 不改动现有设置与数据</div>';

if ($result !== null) {
    $ok = $result['ok'];
    echo '<div style="padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:16px;background:' . ($ok ? '#f0fdf4' : '#fef2f2') . ';color:' . ($ok ? '#15803d' : '#b91c1c') . ';border:1px solid ' . ($ok ? '#bbf7d0' : '#fecaca') . '">'
        . ($ok ? '✔ 升级完成' : '✘ 升级失败') . '</div>';
    echo '<ul style="line-height:1.9;font-size:14px;margin:0 0 16px;padding-left:20px">';
    foreach ($result['log'] as $line) {
        echo '<li>' . htmlspecialchars($line) . '</li>';
    }
    echo '</ul>';
    if ($result['config_written'] !== null) {
        echo '<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe">'
            . ($result['config_written'][0] ? '✔ ' : '⚠ ') . htmlspecialchars($result['config_written'][1]) . '</div>';
    }
    if ($ok) {
        echo '<p style="font-size:13px;color:#94a3b8">请同步部署本次 v3.0.1 的全部代码文件；升级完成后删除本文件。</p>';
        echo '<p><a href="/" style="color:#2563eb">← 返回系统首页</a></p>';
    }
    echo '</div></body></html>';
    exit;
}

if ($error !== '') {
    echo '<div style="padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:16px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca">' . htmlspecialchars($error) . '</div>';
}

// 配置来源提示
if ($dbFromConfig !== null) {
    echo '<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0">';
    echo '✔ 已检测到 ' . htmlspecialchars(basename(dirname($configFile)) . '/config.php') . '，将使用现有数据库配置执行升级。</div>';
} else {
    echo '<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;background:#fefce8;color:#a16207;border:1px solid #fde68a">';
    echo '⚠ 未找到 app/config.php，请在下表填写数据库连接信息。升级成功后将尝试自动生成 app/config.php。</div>';
}

echo '<h2 style="font-size:16px;margin:0 0 8px">升级内容</h2>';
echo '<ul style="line-height:1.9;font-size:14px;margin:0 0 18px;padding-left:20px">';
echo '<li>items 表新增 <b>hygiene_level</b> 列（旧数据留空，不受影响）+ 索引</li>';
echo '<li>新建 <b>hygiene_levels</b> 表并写入预置等级 A/B/C/D</li>';
echo '<li>在 settings 记录版本号 db_version = 3.0.1</li>';
echo '</ul>';

echo '<form method="post">';
if ($dbFromConfig === null) {
    echo '<h2 style="font-size:16px;margin:0 0 8px">数据库连接</h2>';
    echo '<div style="display:flex;gap:12px">';
    echo '<div style="flex:2"><label style="display:block;font-size:13px;color:#475569;margin:8px 0 4px">主机</label><input type="text" name="db_host" value="' . htmlspecialchars($db['host']) . '" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px"></div>';
    echo '<div style="flex:1"><label style="display:block;font-size:13px;color:#475569;margin:8px 0 4px">端口</label><input type="number" name="db_port" value="' . htmlspecialchars($db['port']) . '" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px"></div>';
    echo '</div>';
    echo '<label style="display:block;font-size:13px;color:#475569;margin:8px 0 4px">数据库名称</label><input type="text" name="db_name" value="' . htmlspecialchars($db['name']) . '" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px">';
    echo '<label style="display:block;font-size:13px;color:#475569;margin:8px 0 4px">用户名</label><input type="text" name="db_user" value="' . htmlspecialchars($db['user']) . '" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px">';
    echo '<label style="display:block;font-size:13px;color:#475569;margin:8px 0 4px">密码</label><input type="password" name="db_pass" autocomplete="off" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px">';
    echo '<div style="font-size:12px;color:#94a3b8;margin-top:6px">数据库必须已存在（升级前系统应已部署）。</div>';
} else {
    echo '<input type="hidden" name="use_config" value="1">';
}
echo '<button type="submit" name="run" value="1" style="margin-top:18px;background:#2563eb;color:#fff;border:0;padding:11px 26px;border-radius:8px;font-size:15px;cursor:pointer">执行升级</button>';
echo '<div style="font-size:12px;color:#94a3b8;margin-top:10px">操作幂等、可重复执行；升级完成后请删除本文件。</div>';
echo '</form>';

echo '</div></body></html>';
