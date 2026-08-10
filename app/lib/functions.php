<?php
/**
 * PMD 公共函数
 */
declare(strict_types=1);

/** HTML 转义 */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 获取 POST 参数 */
function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

/** 获取 GET 参数 */
function get(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/** 输出 JSON 并结束 */
function json_out(bool $ok, $data = null, int $http = 200, string $error = ''): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** 成功响应 */
function json_ok($data = null): void
{
    json_out(true, $data);
}

/** 失败响应 */
function json_fail(string $msg, int $http = 400): void
{
    json_out(false, null, $http, $msg);
}

/** 读取请求体 JSON（POST/PUT 等） */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $_POST;
}

/** 翻译（后端用） */
function t(string $key): string
{
    return I18n::t($key);
}

/** 翻译并替换 {token} 占位符，如 tf('val.serialExist', ['serial' => 'NDZ121']) */
function tf(string $key, array $params = []): string
{
    $s = t($key);
    foreach ($params as $k => $v) {
        $s = str_replace('{' . $k . '}', (string)$v, $s);
    }
    return $s;
}

/** 当前语言 */
function lang(): string
{
    return I18n::lang();
}

/** 下载响应 */
function download_headers(string $filename): void
{
    $fn = rawurlencode($filename);
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . $fn);
    header('Cache-Control: no-store');
}

/** 简单的字符串是否为自然数（>=0 整数） */
function is_natural($v): bool
{
    return preg_match('/^\d+$/', (string)$v) === 1;
}

/** 是否为非负数字（>=0，支持小数） */
function is_non_negative_num($v): bool
{
    $s = (string)$v;
    if ($s === '') return false;
    return is_numeric($s) && (float)$s >= 0;
}

/** 修剪并清理字符串（去掉首尾空白与不可见字符） */
function clean_str($v): string
{
    if ($v === null) {
        return '';
    }
    $s = (string)$v;
    // 去掉 UTF-8 BOM
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    return trim($s);
}
