<?php
/**
 * PMD 文件上传处理
 */
declare(strict_types=1);

class Upload
{
    /**
     * 保存上传文件到存储目录，返回 [ok, path, orig_name, ext]
     */
    public static function save(string $field = 'file'): array
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException(t('val.noFile'));
        }
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('上传失败（错误码 ' . $f['error'] . '）');
        }
        $maxBytes = (int)($GLOBALS['pmd_config']['upload_max_bytes'] ?? 10 * 1024 * 1024);
        if ($f['size'] > $maxBytes) {
            throw new RuntimeException(t('val.fileTooBig'));
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'sql'], true)) {
            throw new RuntimeException(t('val.fileType'));
        }

        self::cleanupOld();
        $dir = PMD_STORAGE . '/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $path)) {
            throw new RuntimeException('文件保存失败');
        }
        return ['path' => $path, 'orig_name' => $f['name'], 'ext' => $ext];
    }

    /** 清理 24 小时前的临时文件 */
    public static function cleanupOld(): void
    {
        $dir = PMD_STORAGE . '/tmp';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && time() - filemtime($file) > 86400) {
                @unlink($file);
            }
        }
    }

    /** 读取上传文件内容为二维表（xlsx/csv） */
    public static function parseTable(string $path, string $ext): array
    {
        if ($ext === 'xlsx') {
            return XlsxReader::read($path);
        }
        if ($ext === 'csv') {
            return CsvIO::read($path);
        }
        throw new RuntimeException(t('val.fileType'));
    }
}
