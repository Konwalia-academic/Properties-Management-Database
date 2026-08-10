<?php
/**
 * PMD CSV 读写（UTF-8 + BOM，兼容 Excel）
 */
declare(strict_types=1);

class CsvIO
{
    /** 读取 CSV：自动识别分隔符（, ; TAB）与编码（UTF-8/GB18030），返回二维数组 */
    public static function read(string $path): array
    {
        $raw = (string)file_get_contents($path);
        if ($raw === '') {
            return [];
        }
        // 去 BOM
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        // 编码检测
        if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'GB18030');
        } elseif (!function_exists('mb_check_encoding') && !self::looksUtf8($raw)) {
            $raw = @iconv('GB18030', 'UTF-8//IGNORE', $raw) ?: $raw;
        }
        // 分隔符检测
        $firstLine = strtok($raw, "\n");
        $delim = ',';
        $scores = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($scores);
        $delim = (string)array_key_first($scores);
        if ($scores[$delim] === 0) {
            $delim = ',';
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $row = array_map(fn($v) => trim((string)$v), $row);
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /** 写出 CSV（UTF-8 BOM） */
    public static function write(string $path, array $rows): void
    {
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $v) {
                $s = (string)$v;
                if (strpbrk($s, ",\"\n\r") !== false) {
                    $s = '"' . str_replace('"', '""', $s) . '"';
                }
                $cells[] = $s;
            }
            $out .= implode(',', $cells) . "\r\n";
        }
        file_put_contents($path, $out);
    }

    private static function looksUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }
}
