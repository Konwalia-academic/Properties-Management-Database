#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PMD 模板生成（Python 备用版）
与 tools/generate_templates.php 输出一致；仅在无 PHP 环境时使用。
用法: python3 tools/generate_templates.py
"""
import os, zipfile, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'templates')

ITEMS_HEADERS = ['序列号','物品名称','品牌','目前所在位置代码','新所在位置代码','所在容器序列号',
                 '购入价格','余量','季度消耗量','单位','仓储/折旧情况(%)','备注','物品母类别','物品子类别','商品条形码','卫生等级']
EXCHANGE_HEADERS = ['序列号','物品名称','目前所在位置代码','新所在位置代码','备注']
PURCHASE_HEADERS = ['序列号','物品名称','品牌','物品母类别','物品子类别','目前所在位置代码','单位','当前余量','采购数量','购入价格','备注','卫生等级']

def xml_escape(s):
    return str(s).replace('&','&amp;').replace('<','&lt;').replace('>','&gt;').replace('"','&quot;')

def col_ref(col, row):
    n = col + 1; c = ''
    while n > 0:
        n -= 1
        c = chr(65 + (n % 26)) + c
        n //= 26
    return c + str(row)

def sheet_xml(rows):
    cols = []
    for r in rows:
        for i, v in enumerate(r):
            w = min(60, max(8, len(str(v).encode('utf-8')) + 3)) if i else min(40, max(10, len(str(v).encode('utf-8')) + 3))
            if len(cols) <= i: cols.append(10)
            cols[i] = max(cols[i], w)
    cols_xml = '<cols>' + ''.join(f'<col min="{i+1}" max="{i+1}" width="{w}" customWidth="1"/>' for i, w in enumerate(cols)) + '</cols>'
    data = ''
    for ri, r in enumerate(rows):
        cells = ''
        for ci, v in enumerate(r):
            if v is None or v == '': continue
            ref = col_ref(ci, ri + 1)
            style = ' s="1"' if ri == 0 else ''
            if isinstance(v, (int, float)):
                cells += f'<c r="{ref}"{style}><v>{v}</v></c>'
            else:
                cells += f'<c r="{ref}" t="inlineStr"{style}><is><t xml:space="preserve">{xml_escape(v)}</t></is></c>'
        if cells:
            data += f'<row r="{ri+1}">{cells}</row>'
    return ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        + cols_xml + '<sheetData>' + data + '</sheetData></worksheet>')

def build_xlsx(path, sheet_name, rows):
    names = {'[Content_Types].xml':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        '<Default Extension="xml" ContentType="application/xml"/>'
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        '</Types>',
        '_rels/.rels':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        '</Relationships>',
        'xl/workbook.xml':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        f'<sheets><sheet name="{xml_escape(sheet_name)}" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        '</Relationships>',
        'xl/worksheets/sheet1.xml': sheet_xml(rows),
        'xl/styles.xml':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        '<fill><patternFill patternType="solid"><fgColor rgb="FF305496"/><bgColor indexed="64"/></patternFill></fill></fills>'
        '<borders count="1"><border><left style="thin"><color rgb="FFB0B0B0"/></left>'
        '<right style="thin"><color rgb="FFB0B0B0"/></right><top style="thin"><color rgb="FFB0B0B0"/></top>'
        '<bottom style="thin"><color rgb="FFB0B0B0"/></bottom></border></borders>'
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        '</styleSheet>',
        'docProps/app.xml':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        '<Application>PMD</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
        '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
        f'<vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        f'<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>{xml_escape(sheet_name)}</vt:lpstr></vt:vector></TitlesOfParts></Properties>',
        'docProps/core.xml':
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        '<dc:creator>PMD</dc:creator><dc:title>PMD Export</dc:title>'
        '<dcterms:created xsi:type="dcterms:W3CDTF">2026-08-07T00:00:00Z</dcterms:created>'
        '<dcterms:modified xsi:type="dcterms:W3CDTF">2026-08-07T00:00:00Z</dcterms:modified></cp:coreProperties>',
    }
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        for name, content in names.items():
            z.writestr(name, content)
    print('  OK', os.path.basename(path))

def write_csv(path, rows):
    out = '\ufeff'
    for r in rows:
        cells = []
        for v in r:
            s = str(v)
            if any(ch in s for ch in ',"\n\r'):
                s = '"' + s.replace('"', '""') + '"'
            cells.append(s)
        out += ','.join(cells) + '\r\n'
    with open(path, 'w', encoding='utf-8') as f:
        f.write(out)
    print('  OK', os.path.basename(path))

def main():
    os.makedirs(OUT, exist_ok=True)
    print('生成 PMD 模板文件…')
    build_xlsx(os.path.join(OUT, '物品导入模板_items_import_template.xlsx'), '物品导入', [
        ITEMS_HEADERS,
        ['HBG001', 'A4复印纸（示例）', '得力', 'HOME', '', '', 25, 10, 5, '包', 80, '示例行，导入前请删除', 'H', 'BG', '6901234567890', 'A'],
    ])
    write_csv(os.path.join(OUT, '物品导入模板_items_import_template.csv'), [
        ITEMS_HEADERS,
        ['HBG001', 'A4复印纸（示例）', '得力', 'HOME', '', '', 25, 10, 5, '包', 80, '示例行，导入前请删除', 'H', 'BG', '6901234567890', 'A'],
    ])
    build_xlsx(os.path.join(OUT, '物资交换作业单模板_exchange_worksheet_template.xlsx'), '物资交换作业单', [
        EXCHANGE_HEADERS,
        ['NDZ001', '蓝牙键盘（示例）', 'HOME', 'OFFC', '示例行，请替换或删除'],
    ])
    build_xlsx(os.path.join(OUT, '物资采购欲购清单模板_purchase_list_template.xlsx'), '物资采购欲购清单', [
        PURCHASE_HEADERS,
        ['NDZ001', '蓝牙键盘（示例）', '罗技', 'N', 'DZ', 'HOME', '个', 1, 1, 199, '示例行，请替换或删除', 'A'],
    ])
    print('完成。模板目录：' + OUT)

if __name__ == '__main__':
    sys.exit(main())
