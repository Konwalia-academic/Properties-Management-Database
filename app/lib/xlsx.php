<?php
/**
 * PMD 轻量 XLSX 读写（纯 PHP，无第三方依赖）
 *
 * 写入：支持多工作表、表头加粗底色、列宽自适应、首行冻结。
 * 读取：支持 sharedStrings / inlineStr / 数值 / 布尔，取第一个工作表。
 * 注意：仅适用于扁平表格数据，不含公式/图表/合并单元格等复杂特性。
 */
declare(strict_types=1);

class XlsxWriter
{
    private array $sheets = [];

    /** 添加工作表：$name 表名，$rows 二维数组（首行为表头） */
    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = ['name' => self::sanitizeSheetName($name), 'rows' => $rows];
    }

    public static function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\[\]:*?\/\\\\]/', '', $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet';
        }
        if (mb_strlen($name) > 31) {
            $name = mb_substr($name, 0, 31);
        }
        return $name;
    }

    /** 生成 xlsx 二进制内容 */
    public function build(): string
    {
        if (!$this->sheets) {
            $this->addSheet('Sheet1', []);
        }

        $sheetXmls = [];
        $sheetRels = [];
        $colWidths = [];
        $sheetIndex = 1;
        $usedNames = [];
        foreach ($this->sheets as $s) {
            $name = $s['name'];
            $i = 2;
            $base = $name;
            while (in_array($name, $usedNames, true)) {
                $name = mb_substr($base, 0, 28) . '_' . $i++;
            }
            $usedNames[] = $name;
            $sheetXmls[] = $this->sheetXml($s['rows']);
            $sheetRels[] = ['name' => $name, 'file' => 'worksheets/sheet' . $sheetIndex . '.xml', 'sheetId' => $sheetIndex];
            $sheetIndex++;
        }

        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'pmd_xlsx_');
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建临时 XLSX 文件（请确认已启用 php-zip 扩展）');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheetRels)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->appXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetRels));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($sheetRels));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($sheetRels as $i => $sr) {
            $zip->addFromString('xl/' . $sr['file'], $sheetXmls[$i]);
        }
        $zip->close();

        $content = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }

    /** 保存到文件 */
    public function save(string $path): void
    {
        file_put_contents($path, $this->build());
    }

    /** 直接输出下载 */
    public function output(string $filename): void
    {
        download_headers($filename);
        echo $this->build();
        exit;
    }

    // ---------------- XML 片段 ----------------

    private static function xmlEscape($v): string
    {
        return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function cellRef(int $col, int $row): string
    {
        $c = '';
        $n = $col + 1;
        while ($n > 0) {
            $n--;
            $c = chr(65 + ($n % 26)) . $c;
            $n = intdiv($n, 26);
        }
        return $c . $row;
    }

    private function sheetXml(array $rows): string
    {
        $maxCols = 0;
        foreach ($rows as $r) {
            $maxCols = max($maxCols, count($r));
        }
        $maxCols = max(1, $maxCols);

        // 列宽估算
        $widths = array_fill(0, $maxCols, 10);
        foreach ($rows as $rIdx => $r) {
            foreach ($r as $cIdx => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $len = mb_strwidth((string)$v);
                $w = min(60, max(8, $len + 3));
                if ($rIdx === 0) {
                    $w = min(40, max(10, $len + 3));
                }
                $widths[$cIdx] = max($widths[$cIdx], $w);
            }
        }
        $colsXml = '<cols>';
        foreach ($widths as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';

        $sheetData = '';
        foreach ($rows as $rIdx => $r) {
            $rowNum = $rIdx + 1;
            $cells = '';
            $isHeader = ($rIdx === 0);
            foreach ($r as $cIdx => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::cellRef($cIdx, $rowNum);
                if (is_int($v) || (is_float($v) && is_finite($v))) {
                    $num = sprintf('%.10g', $v);
                    $cells .= '<c r="' . $ref . '"' . ($isHeader ? ' s="1"' : '') . '><v>' . $num . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"' . ($isHeader ? ' s="1"' : '') . '><is><t xml:space="preserve">'
                        . self::xmlEscape($v) . '</t></is></c>';
                }
            }
            if ($cells !== '') {
                $sheetData .= '<row r="' . $rowNum . '">' . $cells . '</row>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . $colsXml
            . '<sheetData>' . $sheetData . '</sheetData>'
            . '</worksheet>';
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(array $sheets): string
    {
        $s = '';
        foreach ($sheets as $i => $sh) {
            $s .= '<sheet name="' . self::xmlEscape($sh['name']) . '" sheetId="' . $sh['sheetId'] . '" r:id="rId' . $sh['sheetId'] . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $s . '</sheets></workbook>';
    }

    private function workbookRelsXml(array $sheets): string
    {
        $s = '';
        foreach ($sheets as $sh) {
            $s .= '<Relationship Id="rId' . $sh['sheetId'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $sh['file'] . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $s . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF305496"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border>'
            . '<left style="thin"><color rgb="FFB0B0B0"/></left>'
            . '<right style="thin"><color rgb="FFB0B0B0"/></right>'
            . '<top style="thin"><color rgb="FFB0B0B0"/></top>'
            . '<bottom style="thin"><color rgb="FFB0B0B0"/></bottom>'
            . '</border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function appXml(): string
    {
        $parts = '';
        foreach ($this->sheets as $s) {
            $parts .= '<vt:lpstr>' . self::xmlEscape($s['name']) . '</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>PMD</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant">'
            . '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>' . count($this->sheets) . '</vt:i4></vt:variant>'
            . '</vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($this->sheets) . '" baseType="lpstr">'
            . $parts
            . '</vt:vector></TitlesOfParts></Properties>';
    }

    private function coreXml(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>PMD</dc:creator><dc:title>PMD Export</dc:title>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }
}

class XlsxReader
{
    /**
     * 读取 xlsx 第一个工作表，返回二维数组（含表头行）
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('文件不存在');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法打开 XLSX 文件（可能已损坏或不是有效的 xlsx）');
        }

        // 共享字符串
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ssXml, $m)) {
                foreach ($m[1] as $si) {
                    $shared[] = self::extractText($si);
                }
            }
        }

        // 第一个工作表文件
        $sheetFile = self::firstSheetFile($zip);

        $sheetXml = $zip->getFromName($sheetFile);
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('XLSX 中未找到工作表内容');
        }

        $rows = [];
        if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheetXml, $rm)) {
            foreach ($rm[1] as $rowXml) {
                $cells = [];
                $maxCol = -1;
                if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cm)) {
                    foreach ($cm[1] as $idx => $attrs) {
                        $col = self::colIndex($attrs);
                        $val = self::cellValue($cm[2][$idx], $attrs, $shared);
                        $cells[$col] = $val;
                        $maxCol = max($maxCol, $col);
                    }
                }
                if ($maxCol >= 0) {
                    $row = [];
                    for ($i = 0; $i <= $maxCol; $i++) {
                        $row[] = $cells[$i] ?? '';
                    }
                    // 跳过完全空行
                    if (implode('', $row) !== '') {
                        $rows[] = $row;
                    }
                }
            }
        }
        return $rows;
    }

    private static function firstSheetFile(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $rid = null;
            if (preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $wb, $m)) {
                $rid = $m[1];
            } elseif (preg_match('/<sheet\b[^>]*r:Id="([^"]+)"/', $wb, $m)) {
                $rid = $m[1];
            }
            if ($rid !== null && preg_match('/Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/', $rels, $m2)) {
                $target = $m2[1];
                if (str_starts_with($target, '/')) {
                    return 'xl' . $target;
                }
                return 'xl/' . $target;
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function colIndex(string $attrs): int
    {
        if (preg_match('/r="([A-Z]+)\d+"/', $attrs, $m)) {
            $col = 0;
            foreach (str_split($m[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            return $col - 1;
        }
        return 0;
    }

    private static function cellValue(string $inner, string $attrs, array $shared): string
    {
        $t = '';
        if (preg_match('/t="([^"]+)"/', $attrs, $m)) {
            $t = $m[1];
        }
        if ($t === 'inlineStr') {
            return self::extractText($inner);
        }
        if (preg_match('/<v>(.*?)<\/v>/s', $inner, $m)) {
            $v = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($t === 's') {
                $idx = (int)$v;
                return $shared[$idx] ?? '';
            }
            if ($t === 'b') {
                return $v === '1' ? '是' : '否';
            }
            return trim($v);
        }
        return '';
    }

    private static function extractText(string $xml): string
    {
        if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $xml, $m)) {
            $s = '';
            foreach ($m[1] as $t) {
                $s .= $t;
            }
            return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return '';
    }
}
