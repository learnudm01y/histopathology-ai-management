<?php

namespace App\Support;

use ZipArchive;

/**
 * Minimal streaming .xlsx writer (no external dependency).
 *
 * Writes a single worksheet using inline strings — rows are streamed to a
 * temporary file as they are produced, so exporting tens of thousands of
 * rows stays flat in memory.
 */
class SimpleXlsxWriter
{
    /** Excel's hard limit for the character count of a single cell. */
    private const MAX_CELL_LENGTH = 32000;

    /**
     * Build an .xlsx file at $path.
     *
     * @param  string    $path       Destination file (overwritten if present).
     * @param  string[]  $headers    Header labels for the first row.
     * @param  iterable  $rows       Iterable of arrays, values positional and aligned with $headers.
     * @param  string    $sheetName  Worksheet tab name.
     */
    public static function write(string $path, array $headers, iterable $rows, string $sheetName = 'Sheet1'): void
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'xlsxsheet');
        $handle    = fopen($sheetPath, 'w');

        $lastCol = self::columnLetter(max(count($headers), 1));

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<sheetData>');

        // Header row — style index 1 (bold).
        fwrite($handle, self::rowXml(1, $headers, true));

        $rowNum = 1;
        foreach ($rows as $row) {
            $rowNum++;
            fwrite($handle, self::rowXml($rowNum, array_values($row), false));
        }

        fwrite($handle, '</sheetData>');
        fwrite($handle, '<autoFilter ref="A1:' . $lastCol . $rowNum . '"/>');
        fwrite($handle, '</worksheet>');
        fclose($handle);

        @unlink($path);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sheetPath);
            throw new \RuntimeException("Unable to create xlsx archive at {$path}");
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::relsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($sheetPath);
    }

    /** Render a single <row> element. */
    private static function rowXml(int $rowNum, array $values, bool $bold): string
    {
        $xml   = '<row r="' . $rowNum . '">';
        $style = $bold ? ' s="1"' : '';

        foreach (array_values($values) as $i => $value) {
            if ($value === null || $value === '') {
                continue; // empty cell — omit entirely
            }

            $ref = self::columnLetter($i + 1) . $rowNum;

            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }

            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                continue;
            }

            $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                 . self::sanitize((string) $value) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /** 1 => A, 27 => AA, … */
    public static function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $rem    = ($index - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $index  = intdiv($index - 1 - $rem, 26);
        }

        return $letter;
    }

    /** Drop characters XML cannot carry, truncate to Excel's cell limit, then escape. */
    private static function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_CELL_LENGTH - 1) . '…';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        // Excel sheet names: 31 chars max, and none of  : \ / ? * [ ]
        $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $sheetName);
        $name = htmlspecialchars(mb_substr($name, 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Two cell formats: 0 = default, 1 = bold (header row). */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
