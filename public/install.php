<?php
/**
 * PMD 网页安装向导
 * 完成：环境检查 → 数据库连接 → 执行 init.sql → 写入 config.php → 设置管理员 PIN
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');
$configFile = PMD_APP . '/config.php';
$installed = file_exists($configFile);

function env_check(): array
{
    $checks = [];
    $checks[] = ['PHP 版本 >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION];
    $checks[] = ['pdo_mysql 扩展', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已启用' : '未启用'];
    $checks[] = ['zip 扩展（xlsx 读写）', extension_loaded('zip'), extension_loaded('zip') ? '已启用' : '未启用'];
    $checks[] = ['mbstring 扩展（推荐）', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已启用' : '未启用'];
    return $checks;
}

function split_sql(string $sql): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $stmt)) {
            continue;
        }
        $out[] = $stmt;
    }
    return $out;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'install';

    if ($action === 'install') {
        $host = trim($_POST['db_host'] ?? '127.0.0.1');
        $port = (int)($_POST['db_port'] ?? 3306);
        $dbname = trim($_POST['db_name'] ?? 'pmd');
        $dbuser = trim($_POST['db_user'] ?? '');
        $dbpass = (string)($_POST['db_pass'] ?? '');
        $pin = (string)($_POST['pin'] ?? '');
        $pin2 = (string)($_POST['pin2'] ?? '');
        $title = trim($_POST['site_title'] ?? '个人物品管理数据库');

        if ($dbname === '' || $dbuser === '') {
            $error = '数据库名称与用户名不能为空';
        } elseif (strlen($pin) < 4 || strlen($pin) > 32) {
            $error = '登录 PIN 长度需为 4-32 位';
        } elseif ($pin !== $pin2) {
            $error = '两次输入的 PIN 不一致';
        } else {
            // 1. 连接 MySQL 服务器（不指定库）
            try {
                $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbuser, $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                // 2. 尝试创建数据库（无权限时忽略，使用已存在的库）
                try {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (Throwable $e) {
                    // 无建库权限，忽略（库必须已存在）
                }
                // 3. 连接目标库并执行 init.sql
                $pdo2 = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $sql = (string)file_get_contents(PMD_ROOT . '/sql/init.sql');
                foreach (split_sql($sql) as $stmt) {
                    $pdo2->exec($stmt);
                }
                // 4. 写入 config.php
                $config = [
                    'db' => ['host' => $host, 'port' => $port, 'name' => $dbname, 'user' => $dbuser, 'pass' => $dbpass, 'charset' => 'utf8mb4'],
                    'session' => ['name' => 'PMDSESSID', 'lifetime' => 28800],
                    'upload_max_bytes' => 10 * 1024 * 1024,
                ];
                $php = "<?php\n// PMD 配置文件（由安装向导生成）\nreturn " . var_export($config, true) . ";\n";
                if (file_put_contents($configFile, $php) === false) {
                    throw new RuntimeException('无法写入 ' . $configFile . '，请检查目录写权限');
                }
                // 5. 设置 PIN 与标题
                $st = $pdo2->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                $st->execute(['pin_hash', password_hash($pin, PASSWORD_DEFAULT)]);
                $st->execute(['site_title', $title]);
                $success = '安装完成！即将跳转到登录页…';
                echo '<script>setTimeout(function(){location.href="/";},1200);</script>';
            } catch (Throwable $e) {
                $error = '安装失败：' . $e->getMessage();
            }
        }
    } elseif ($action === 'reinstall' && $installed) {
        // 重新安装：仅覆盖 config.php（数据库数据保留）
        $dbname = trim($_POST['db_name'] ?? 'pmd');
        $pin = (string)($_POST['pin'] ?? '');
        if (strlen($pin) < 4) {
            $error = 'PIN 长度需至少 4 位';
        } else {
            try {
                $cfg = require $configFile;
                $pdo = new PDO(
                    "mysql:host={$cfg['db']['host']};port={$cfg['db']['port']};dbname={$cfg['db']['name']};charset=utf8mb4",
                    $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                $st->execute(['pin_hash', password_hash($pin, PASSWORD_DEFAULT)]);
                $success = '已重置登录 PIN。';
            } catch (Throwable $e) {
                $error = '重置失败：' . $e->getMessage();
            }
        }
    }
}

function check_ok(bool $ok): string
{
    return $ok ? '<span style="color:#16a34a">✔</span>' : '<span style="color:#dc2626">✘</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PMD 安装向导</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f1f5f9;color:#1e293b;padding:40px 16px}
.card{max-width:640px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:32px}
h1{font-size:22px;margin-bottom:4px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
table{width:100%;border-collapse:collapse;margin-bottom:24px;font-size:14px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0}
label{display:block;font-size:13px;color:#475569;margin:14px 0 4px}
input[type=text],input[type=password],input[type=number]{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px}
input:focus{outline:2px solid #2563eb;border-color:transparent}
.btn{display:inline-block;margin-top:20px;background:#2563eb;color:#fff;border:0;padding:10px 22px;border-radius:8px;font-size:15px;cursor:pointer}
.btn:hover{background:#1d4ed8}
.msg{padding:10px 14px;border-radius:8px;margin-top:14px;font-size:14px}
.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.hint{font-size:12px;color:#94a3b8;margin-top:4px}
hr{margin:24px 0;border:0;border-top:1px solid #e2e8f0}
</style>
</head>
<body>
<div class="card">
  <h1>PMD 个人物品管理数据库 · 安装向导</h1>
  <div class="sub">版本 1.2.1 · 安装前请确认 MySQL 已就绪，且已创建数据库（或当前账号具备建库权限）</div>

  <h2 style="font-size:16px;margin-bottom:8px">环境检查</h2>
  <table>
    <?php foreach (env_check() as $c): ?>
    <tr><td><?= htmlspecialchars($c[0]) ?></td><td style="text-align:right"><?= check_ok($c[1]) ?> <?= htmlspecialchars((string)$c[2]) ?></td></tr>
    <?php endforeach; ?>
  </table>

  <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if ($installed): ?>
    <div class="msg ok">系统已安装（检测到 app/config.php）。如需重置登录 PIN，可在下方填写新 PIN 后点击"重置 PIN"。</div>
    <form method="post">
      <input type="hidden" name="action" value="reinstall">
      <label>新登录 PIN（4-32 位）</label>
      <input type="password" name="pin" required autocomplete="new-password">
      <button class="btn" type="submit">重置 PIN</button>
    </form>
    <hr>
  <?php endif; ?>

  <h2 style="font-size:16px;margin-bottom:8px"><?= $installed ? '重新安装（保留数据）' : '开始安装' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="install">
    <div style="display:flex;gap:12px">
      <div style="flex:2">
        <label>数据库主机</label>
        <input type="text" name="db_host" value="127.0.0.1">
      </div>
      <div style="flex:1">
        <label>端口</label>
        <input type="number" name="db_port" value="3306">
      </div>
    </div>
    <label>数据库名称</label>
    <input type="text" name="db_name" value="pmd">
    <label>数据库用户名</label>
    <input type="text" name="db_user" required>
    <label>数据库密码</label>
    <input type="password" name="db_pass" autocomplete="off">
    <label>网站标题</label>
    <input type="text" name="site_title" value="个人物品管理数据库">
    <label>管理员登录 PIN（4-32 位）</label>
    <input type="password" name="pin" required autocomplete="new-password">
    <label>确认 PIN</label>
    <input type="password" name="pin2" required autocomplete="new-password">
    <button class="btn" type="submit"><?= $installed ? '重新安装（保留数据）' : '安装' ?></button>
  </form>
  <div class="hint">安装向导仅在本项目首次部署时使用；部署完成后建议删除 public/install.php。</div>
</div>
</body>
</html>
