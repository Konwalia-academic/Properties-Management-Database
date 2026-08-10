<?php
/**
 * PMD API 路由（JSON）
 * 入口：public/index.php 检测到 /api 前缀后引入本文件
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!PMD_INSTALLED) {
    json_fail('系统尚未安装', 500);
}

// 同源校验（防止跨站请求）：兼容带端口的 Host/Origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $hostName = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostName = preg_replace('/:\d+$/', '', $hostName); // 去掉端口
    // 本机地址别名：localhost 与 127.0.0.1 等价
    $loopback = ['localhost', '127.0.0.1', '::1'];
    if (in_array($originHost, $loopback, true)) {
        $originHost = '127.0.0.1';
    }
    if (in_array($hostName, $loopback, true)) {
        $hostName = '127.0.0.1';
    }
    if ($originHost !== '' && $hostName !== '' && $originHost !== $hostName) {
        json_fail('跨域请求被拒绝', 403);
    }
}

$action = (string)($_GET['action'] ?? '');
$body = json_body();

try {
    switch ($action) {
        // ---------------- 认证 / 状态 ----------------
        case 'status':
            Auth::requireLogin();
            json_ok(public_status());
            break;

        case 'public_status':
            json_ok(public_status(false));
            break;

        case 'login': {
            $pin = (string)($body['pin'] ?? '');
            if (Auth::attempt($pin)) {
                json_ok(public_status());
            }
            $lockUntil = (int)($_SESSION['pmd_login_lock'] ?? 0);
            if (time() < $lockUntil) {
                json_fail(t('common.locked'), 429);
            }
            json_fail(t('common.wrongPin'), 401);
            break;
        }

        case 'logout':
            Auth::logout();
            json_ok();
            break;

        case 'change_pin': {
            Auth::requireLogin();
            [$ok, $msg] = Auth::changePin((string)($body['old_pin'] ?? ''), (string)($body['new_pin'] ?? ''));
            if (!$ok) {
                json_fail($msg);
            }
            json_ok();
            break;
        }

        // ---------------- 物品 ----------------
        case 'items.list':
            Auth::requireLogin();
            json_ok(Items::search($_GET));
            break;

        case 'items.get': {
            Auth::requireLogin();
            $item = Items::find((string)($body['serial_no'] ?? get('serial_no', '')));
            if ($item === null) {
                json_fail(tf('val.serialMissing', ['serial' => $body['serial_no'] ?? '']), 404);
            }
            json_ok($item);
            break;
        }

        case 'items.create':
            Auth::requireLogin();
            json_ok(Items::create($body));
            break;

        case 'items.update':
            Auth::requireLogin();
            json_ok(Items::update((string)($body['serial_no'] ?? ''), $body));
            break;

        case 'items.delete':
            Auth::requireLogin();
            Items::delete((string)($body['serial_no'] ?? ''));
            json_ok();
            break;

        case 'items.batch_update':
            Auth::requireLogin();
            json_ok(Items::batchUpdate(
                (array)($body['serials'] ?? []),
                (string)($body['field'] ?? ''),
                $body['value'] ?? ''
            ));
            break;

        case 'serial.next':
            Auth::requireLogin();
            json_ok(['serial_no' => Serial::next((string)($body['main_category'] ?? ''), (string)($body['sub_category'] ?? ''))]);
            break;

        // ---------------- 导入 ----------------
        case 'import.preview': {
            Auth::requireLogin();
            $up = Upload::save('file');
            $table = Upload::parseTable($up['path'], $up['ext']);
            $rows = Templates::mapRows($table, Templates::ITEMS_HEADERS);
            $preview = Importer::previewItems($rows);
            $token = Workflows::draftSave('import_items', $preview['rows']);
            json_ok([
                'token' => $token,
                'file' => $up['orig_name'],
                'inserted' => $preview['inserted'],
                'duplicates' => $preview['duplicates'],
                'invalid' => $preview['invalid'],
                'auto_categories' => $preview['auto_categories'],
                'rows' => array_slice($preview['rows'], 0, 500),
                'has_duplicates' => $preview['duplicates'] > 0,
            ]);
            break;
        }

        case 'import.sql_preview': {
            Auth::requireLogin();
            $up = Upload::save('file');
            if ($up['ext'] !== 'sql') {
                json_fail(t('val.fileType'));
            }
            $parsed = Importer::parseSql($up['path']);
            $rows = Importer::sqlToRows($parsed['stmts']);
            $preview = Importer::previewItems($rows);
            $token = Workflows::draftSave('import_items', $preview['rows']);
            json_ok([
                'token' => $token,
                'file' => $up['orig_name'],
                'statements' => count($parsed['stmts']),
                'ignored' => $parsed['ignored'],
                'inserted' => $preview['inserted'],
                'duplicates' => $preview['duplicates'],
                'invalid' => $preview['invalid'],
                'auto_categories' => $preview['auto_categories'],
                'rows' => array_slice($preview['rows'], 0, 500),
                'has_duplicates' => $preview['duplicates'] > 0,
            ]);
            break;
        }

        case 'import.apply': {
            Auth::requireLogin();
            $token = (string)($body['token'] ?? '');
            $dupMode = (string)($body['dup_mode'] ?? '');
            if (!in_array($dupMode, ['update', 'skip'], true)) {
                json_fail(t('val.dupMode'));
            }
            $draft = Workflows::draftGet($token);
            if ($draft === null || ($draft['type'] ?? '') !== 'import_items') {
                json_fail('导入数据已过期，请重新上传文件');
            }
            $result = Importer::applyItems($draft['rows'], $dupMode);
            Workflows::draftClear($token);
            json_ok($result);
            break;
        }

        case 'import.exchange_preview': {
            Auth::requireLogin();
            $up = Upload::save('file');
            $table = Upload::parseTable($up['path'], $up['ext']);
            $rows = Templates::mapRows($table, Templates::EXCHANGE_HEADERS);
            $preview = Importer::previewExchange($rows);
            $token = Workflows::draftSave('import_exchange', $preview['rows']);
            json_ok(['token' => $token, 'file' => $up['orig_name'], 'valid' => $preview['valid'], 'rows' => $preview['rows']]);
            break;
        }

        case 'import.exchange_apply': {
            Auth::requireLogin();
            $draft = Workflows::draftGet((string)($body['token'] ?? ''));
            if ($draft === null || ($draft['type'] ?? '') !== 'import_exchange') {
                json_fail('作业单数据已过期，请重新上传文件');
            }
            $result = Importer::applyExchange($draft['rows']);
            Workflows::draftClear((string)($body['token'] ?? ''));
            json_ok($result);
            break;
        }

        case 'import.purchase_preview': {
            Auth::requireLogin();
            $up = Upload::save('file');
            $table = Upload::parseTable($up['path'], $up['ext']);
            $rows = Templates::mapRows($table, Templates::PURCHASE_HEADERS);
            $preview = Importer::previewPurchase($rows);
            $token = Workflows::draftSave('import_purchase', $preview['rows']);
            json_ok(['token' => $token, 'file' => $up['orig_name'], 'valid' => $preview['valid'], 'auto_categories' => $preview['auto_categories'], 'rows' => $preview['rows']]);
            break;
        }

        case 'import.purchase_apply': {
            Auth::requireLogin();
            $draft = Workflows::draftGet((string)($body['token'] ?? ''));
            if ($draft === null || ($draft['type'] ?? '') !== 'import_purchase') {
                json_fail('欲购清单数据已过期，请重新上传文件');
            }
            $result = Importer::applyPurchase($draft['rows']);
            Workflows::draftClear((string)($body['token'] ?? ''));
            json_ok($result);
            break;
        }

        // ---------------- 导出 ----------------
        case 'export.items': {
            Auth::requireLogin();
            $format = get('format', 'xlsx');
            $f = $_GET;
            $f['page'] = 1;
            $f['all'] = 1;
            $res = Items::search($f);
            $rows = [Templates::itemsHeaderRow()];
            foreach ($res['rows'] as $item) {
                $rows[] = Templates::itemToRow($item);
            }
            export_table($rows, $format, 'PMD物品导出_' . date('Ymd'));
            break;
        }

        case 'export.template': {
            Auth::requireLogin();
            $type = (string)get('type', 'items');
            export_table(build_template($type), 'xlsx', 'PMD_' . $type . '_模板');
            break;
        }

        case 'exchange.worksheet': {
            Auth::requireLogin();
            $draft = Workflows::draftGet((string)get('token', ''));
            if ($draft === null || ($draft['type'] ?? '') !== 'exchange') {
                json_fail('作业单草稿不存在或已过期，请重新生成');
            }
            $rows = [Templates::exchangeHeaderRow()];
            foreach ($draft['rows'] as $r) {
                $rows[] = [
                    $r['serial_no'], $r['name'], $r['location_code'],
                    $r['new_location_code'], $r['notes'] ?? '',
                ];
            }
            export_table($rows, 'xlsx', 'PMD物资交换作业单_' . date('Ymd_His'));
            break;
        }

        case 'purchase.worksheet': {
            Auth::requireLogin();
            $draft = Workflows::draftGet((string)get('token', ''));
            if ($draft === null || ($draft['type'] ?? '') !== 'purchase') {
                json_fail('欲购清单草稿不存在或已过期，请重新生成');
            }
            $rows = [Templates::purchaseHeaderRow()];
            foreach ($draft['rows'] as $r) {
                $rows[] = [
                    $r['serial_no'], $r['name'], $r['brand'],
                    $r['main_category'], $r['sub_category'], $r['location_code'],
                    $r['unit'], $r['quantity'], $r['purchase_qty'],
                    $r['purchase_price'] ?? '', $r['notes'] ?? '',
                ];
            }
            export_table($rows, 'xlsx', 'PMD物资采购欲购清单_' . date('Ymd_His'));
            break;
        }

        // ---------------- 物资交换 ----------------
        case 'exchange.prepare':
            Auth::requireLogin();
            json_ok(Workflows::exchangePrepare((array)($body['serials'] ?? []), (string)($body['new_location'] ?? '')));
            break;

        case 'exchange.pending':
            Auth::requireLogin();
            json_ok(Workflows::exchangePending());
            break;

        case 'exchange.generate':
            Auth::requireLogin();
            json_ok(Workflows::exchangeGenerate());
            break;

        case 'exchange.apply':
            Auth::requireLogin();
            json_ok(Workflows::exchangeApply((string)($body['token'] ?? '')));
            break;

        // ---------------- 物资采购 ----------------
        case 'purchase.scan':
            Auth::requireLogin();
            json_ok(Workflows::purchaseScan((string)($body['location'] ?? '')));
            break;

        case 'purchase.generate':
            Auth::requireLogin();
            json_ok(Workflows::purchaseGenerate((array)($body['rows'] ?? [])));
            break;

        case 'purchase.apply':
            Auth::requireLogin();
            json_ok(Workflows::purchaseApply((string)($body['token'] ?? '')));
            break;

        // ---------------- 物资借还 ----------------
        case 'borrow.out':
            Auth::requireLogin();
            json_ok(Workflows::borrowOut((array)($body['serials'] ?? []), (string)($body['borrower'] ?? '')));
            break;

        case 'borrow.list':
            Auth::requireLogin();
            json_ok(Workflows::borrowList());
            break;

        case 'borrow.in':
            Auth::requireLogin();
            json_ok(Workflows::borrowIn(
                (string)($body['serial_no'] ?? ''),
                (float)($body['quantity'] ?? 0),
                (int)($body['depreciation'] ?? 100),
                (string)($body['location'] ?? ''),
                (string)($body['note'] ?? '')
            ));
            break;

        case 'borrow.history':
            Auth::requireLogin();
            json_ok(Workflows::borrowHistory());
            break;

        // ---------------- 类别 / 位置 ----------------
        case 'categories.list':
            Auth::requireLogin();
            json_ok(DB::all('SELECT main_code, sub_code, main_name, sub_name FROM categories ORDER BY main_code, sub_code'));
            break;

        case 'categories.create': {
            Auth::requireLogin();
            $main = strtoupper(clean_str($body['main_code'] ?? ''));
            $sub = strtoupper(clean_str($body['sub_code'] ?? ''));
            $subName = clean_str($body['sub_name'] ?? '');
            if (!isset(Serial::MAIN[$main])) {
                json_fail(t('val.mainInvalid'));
            }
            if (!preg_match('/^[A-Z]{2}$/', $sub)) {
                json_fail(t('val.subInvalid'));
            }
            if ($subName === '') {
                json_fail('子类别名称不能为空');
            }
            if (DB::one('SELECT 1 FROM categories WHERE main_code=? AND sub_code=?', [$main, $sub])) {
                json_fail(t('settings.duplicate'));
            }
            DB::q('INSERT INTO categories (main_code, sub_code, main_name, sub_name) VALUES (?,?,?,?)', [$main, $sub, Serial::MAIN[$main], $subName]);
            json_ok();
            break;
        }

        case 'categories.update': {
            Auth::requireLogin();
            $main = strtoupper(clean_str($body['main_code'] ?? ''));
            $sub = strtoupper(clean_str($body['sub_code'] ?? ''));
            $subName = clean_str($body['sub_name'] ?? '');
            if ($subName === '') {
                json_fail('子类别名称不能为空');
            }
            DB::q('UPDATE categories SET sub_name = ? WHERE main_code = ? AND sub_code = ?', [$subName, $main, $sub]);
            json_ok();
            break;
        }

        case 'categories.delete': {
            Auth::requireLogin();
            $main = strtoupper(clean_str($body['main_code'] ?? ''));
            $sub = strtoupper(clean_str($body['sub_code'] ?? ''));
            if (DB::one('SELECT 1 FROM items WHERE main_category=? AND sub_category=? LIMIT 1', [$main, $sub])) {
                json_fail(t('settings.inUse'));
            }
            DB::exec('DELETE FROM categories WHERE main_code=? AND sub_code=?', [$main, $sub]);
            json_ok();
            break;
        }

        case 'locations.list':
            Auth::requireLogin();
            json_ok(DB::all('SELECT code, name, sort_order FROM locations ORDER BY sort_order, code'));
            break;

        case 'locations.create': {
            Auth::requireLogin();
            $code = strtoupper(clean_str($body['code'] ?? ''));
            $name = clean_str($body['name'] ?? '');
            if (preg_match(Items::LOCATION_RE, $code) !== 1) {
                json_fail(t('val.locationInvalid'));
            }
            if (DB::one('SELECT 1 FROM locations WHERE code=?', [$code])) {
                json_fail(t('settings.duplicate'));
            }
            DB::q('INSERT INTO locations (code, name, sort_order) VALUES (?,?,?)', [$code, $name, (int)($body['sort_order'] ?? 0)]);
            json_ok();
            break;
        }

        case 'locations.update': {
            Auth::requireLogin();
            $code = strtoupper(clean_str($body['code'] ?? ''));
            if ($code === 'LTO') {
                json_fail(t('settings.ltoProtected'));
            }
            DB::q('UPDATE locations SET name = ?, sort_order = ? WHERE code = ?', [clean_str($body['name'] ?? ''), (int)($body['sort_order'] ?? 0), $code]);
            json_ok();
            break;
        }

        case 'locations.delete': {
            Auth::requireLogin();
            $code = strtoupper(clean_str($body['code'] ?? ''));
            if ($code === 'LTO') {
                json_fail(t('settings.ltoProtected'));
            }
            if (DB::one('SELECT 1 FROM items WHERE location_code=? OR new_location_code=? LIMIT 1', [$code, $code])) {
                json_fail(t('settings.inUse'));
            }
            DB::exec('DELETE FROM locations WHERE code=?', [$code]);
            json_ok();
            break;
        }

        // ---------------- 设置 ----------------
        case 'settings.get':
            Auth::requireLogin();
            json_ok([
                'site_title' => (string)Settings::get('site_title', t('app.name')),
                'logo' => (string)Settings::get('logo', ''),
                'theme_accent' => (string)Settings::get('theme_accent', '#2563eb'),
                'language' => (string)Settings::get('language', 'zh-CN'),
                'rows_per_page' => (int)Settings::get('rows_per_page', 30),
            ]);
            break;

        case 'settings.update': {
            Auth::requireLogin();
            if (isset($body['site_title'])) {
                $title = clean_str($body['site_title']);
                if ($title === '') {
                    json_fail('网站标题不能为空');
                }
                Settings::set('site_title', $title);
            }
            if (isset($body['theme_accent'])) {
                $c = clean_str($body['theme_accent']);
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $c) !== 1) {
                    json_fail('主题色格式错误');
                }
                Settings::set('theme_accent', strtoupper($c));
            }
            if (isset($body['language'])) {
                $l = clean_str($body['language']);
                if (isset(I18n::LANGUAGES[$l])) {
                    Settings::set('language', $l);
                }
            }
            if (isset($body['rows_per_page'])) {
                Settings::set('rows_per_page', (string)min(500, max(10, (int)$body['rows_per_page'])));
            }
            json_ok();
            break;
        }

        case 'settings.logo_upload': {
            Auth::requireLogin();
            if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? 0) !== UPLOAD_ERR_OK) {
                json_fail(t('val.noFile'));
            }
            $f = $_FILES['logo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                json_fail('LOGO 仅支持 png/jpg/jpeg/gif/webp/svg');
            }
            if ($f['size'] > 2 * 1024 * 1024) {
                json_fail('LOGO 文件不能超过 2MB');
            }
            $dir = PMD_PUBLIC . '/uploads/logo';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // 删除旧 LOGO
            $old = (string)Settings::get('logo', '');
            if ($old !== '') {
                @unlink($dir . '/' . basename($old));
            }
            $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                json_fail('LOGO 保存失败');
            }
            Settings::set('logo', $name);
            json_ok(['logo' => $name]);
            break;
        }

        case 'settings.logo_remove': {
            Auth::requireLogin();
            $old = (string)Settings::get('logo', '');
            if ($old !== '') {
                @unlink(PMD_PUBLIC . '/uploads/logo/' . basename($old));
            }
            Settings::set('logo', '');
            json_ok();
            break;
        }

        default:
            json_fail('未知操作：' . $action, 404);
    }
} catch (RuntimeException $e) {
    json_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[PMD] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('服务器内部错误：' . $e->getMessage(), 500);
}

// ============================================================
// 内部函数
// ============================================================

function public_status(bool $requireLogin = true): array
{
    if ($requireLogin) {
        Auth::requireLogin();
    }
    $cats = DB::all('SELECT main_code, sub_code, main_name, sub_name FROM categories ORDER BY main_code, sub_code');
    $locs = DB::all('SELECT code, name, sort_order FROM locations ORDER BY sort_order, code');
    return [
        'logged_in' => Auth::logged(),
        'site_title' => (string)Settings::get('site_title', t('app.name')),
        'logo' => (string)Settings::get('logo', ''),
        'theme_accent' => (string)Settings::get('theme_accent', '#2563eb'),
        'language' => lang(),
        'languages' => I18n::LANGUAGES,
        'strings' => I18n::all(),
        'main_categories' => Serial::MAIN,
        'categories' => $cats,
        'locations' => $locs,
        'version' => PMD_VERSION,
        'has_pin' => Auth::hasPin(),
    ];
}

function build_template(string $type): array
{
    switch ($type) {
        case 'exchange':
            return [
                Templates::exchangeHeaderRow(),
                ['NDZ001', '蓝牙键盘（示例）', 'HOME', 'OFFC', '示例行，请替换或删除'],
            ];
        case 'purchase':
            return [
                Templates::purchaseHeaderRow(),
                ['NDZ001', '蓝牙键盘（示例）', '罗技', 'N', 'DZ', 'HOME', '个', 1, 1, 199, '示例行，请替换或删除'],
            ];
        case 'items':
        default:
            return [
                Templates::itemsHeaderRow(),
                ['HBG001', 'A4复印纸（示例）', '得力', 'HOME', '', '', 25, 10, 5, '包', 80, '示例行，请替换或删除', 'H', 'BG', '6901234567890'],
            ];
    }
}

function export_table(array $rows, string $format, string $basename): void
{
    if ($format === 'csv') {
        $path = tempnam(sys_get_temp_dir(), 'pmd_csv_');
        CsvIO::write($path, $rows);
        download_headers($basename . '.csv');
        header('Content-Type: text/csv; charset=utf-8');
        readfile($path);
        @unlink($path);
        exit;
    }
    $w = new XlsxWriter();
    $w->addSheet('Sheet1', $rows);
    $w->output($basename . '.xlsx');
}
