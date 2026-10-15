<?php

namespace App\Services\Reports;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Libro .xlsx escrito fila a fila en disco, para reportes de cientos de miles de lineas (libro auxiliar, diario)
 * que no caben en memoria con PhpSpreadsheet. Mismo estilo que ArrayExport: titulo, filtros, encabezado con
 * filtro automatico y panel fijo, formatos numericos y fila de totales.
 */
class XlsxStream
{
    /** Indices de cellXfs en styles.xml. */
    private const STYLES = ['text' => 0, 'header' => 1, 'money' => 2, 'qty' => 2, 'cost' => 2, 'int' => 3, 'count' => 3, 'date' => 4, 'title' => 5, 'pct' => 6];

    private const TOTAL_STYLES = ['money' => 7, 'qty' => 7, 'cost' => 7, 'int' => 8, 'count' => 8];

    private $handle;

    private string $sheetPath;

    private int $row = 0;

    private int $headerRow;

    private string $autoFilter;

    /** @param list<array{0:string,1:string}> $columns [titulo, formato] */
    public function __construct(private readonly string $title, private readonly array $columns, ?string $caption = null, ?string $filters = null)
    {
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'xlsx_sheet');
        $this->handle = fopen($this->sheetPath, 'w');
        $last = $this->col(count($columns) - 1);
        $this->headerRow = $caption ? ($filters ? 4 : 3) : 1;
        fwrite($this->handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$this->headerRow.'" topLeftCell="A'.($this->headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.implode('', array_map(fn ($i, $c) => '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$this->width($c).'" customWidth="1"/>', array_keys($columns), $columns)).'</cols><sheetData>');
        if ($caption) {
            $this->write([$caption], ['title']);
            if ($filters) {
                $this->write([$filters], ['text']);
            }
            $this->row++;
        }
        $this->write(array_column($columns, 0), array_fill(0, count($columns), 'header'));
        $this->autoFilter = "A{$this->headerRow}:{$last}{$this->headerRow}";
    }

    public function add(array $values): void
    {
        $this->write(array_values($values), array_column($this->columns, 1));
    }

    /** Fila de totales en negrilla: claves = indice de columna. */
    public function totals(array $values, string $label = 'Total general'): void
    {
        $row = array_fill(0, count($this->columns), null);
        $row[0] = $label;
        $styles = array_fill(0, count($this->columns), 'header');
        foreach ($values as $i => $v) {
            $row[$i] = $v;
            $styles[$i] = 'total:'.$this->columns[$i][1];
        }
        $this->write($row, $styles);
    }

    public function download(string $fileName): BinaryFileResponse
    {
        fwrite($this->handle, '</sheetData><autoFilter ref="'.$this->autoFilter.'"/><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>');
        fclose($this->handle);
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de Excel.');
        }
        $sheetName = htmlspecialchars(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $this->title), 0, 31), ENT_XML1);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.str_replace("'", "''", $sheetName).'\'!'.preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $this->autoFilter).'</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($this->sheetPath);

        return response()->download($path, $fileName, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private function write(array $values, array $formats): void
    {
        $this->row++;
        $cells = '';
        foreach ($values as $i => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $fmt = $formats[$i] ?? 'text';
            $total = str_starts_with($fmt, 'total:');
            $base = $total ? substr($fmt, 6) : $fmt;
            $ref = $this->col($i).$this->row;
            $style = $total ? (self::TOTAL_STYLES[$base] ?? 1) : (self::STYLES[$base] ?? 0);
            if ($base === 'date' && ($serial = self::serial($v)) !== null) {
                $cells .= "<c r=\"$ref\" s=\"$style\"><v>$serial</v></c>";
            } elseif (in_array($base, ['money', 'qty', 'cost', 'int', 'count', 'pct'], true) && is_numeric($v)) {
                $cells .= "<c r=\"$ref\" s=\"$style\"><v>".(0 + $v).'</v></c>';
            } else {
                $text = htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= "<c r=\"$ref\" s=\"$style\" t=\"inlineStr\"><is><t xml:space=\"preserve\">$text</t></is></c>";
            }
        }
        fwrite($this->handle, "<row r=\"{$this->row}\">$cells</row>");
    }

    /** Numero de serie de Excel para fechas AAAA-MM-DD o DD/MM/AAAA. */
    private static function serial(mixed $v): ?int
    {
        $s = (string) $v;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? intdiv(gmmktime(0, 0, 0, $mo, $d, $y), 86400) + 25569 : null;
    }

    private function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private function width(array $column): int
    {
        return match ($column[1]) {
            'money', 'qty', 'cost' => 17, 'int', 'count' => 11, 'date' => 12, 'pct' => 10,
            default => min(max(mb_strlen($column[0]) + 4, 14), 45),
        };
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="0.00&quot;%&quot;"/></numFmts>'
            .'<fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="13"/><color rgb="FF00579C"/><name val="Arial"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9F3FB"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="3" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
