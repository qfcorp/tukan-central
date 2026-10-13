<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Libro de Excel (.xlsx) mínimo, en PHP puro: el ZIP se arma aquí mismo (deflate + crc32), así no depende de
 * ZipArchive. Hojas con textos (inlineStr) y números; la primera fila en negrita; columnas de dinero con formato.
 */
final class Xlsx
{
    /** @var list<array{nombre:string, filas:list<list<mixed>>, dinero:list<int>}> */
    private array $hojas = [];

    /** $dinero: índices (desde 0) de las columnas con formato de dinero. */
    public function hoja(string $nombre, array $filas, array $dinero = []): self
    {
        $nombre = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $nombre), 0, 31);
        $this->hojas[] = ['nombre' => $nombre, 'filas' => $filas, 'dinero' => $dinero];
        return $this;
    }

    private static function x(string $t): string
    {
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t);
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function columna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private function hojaXml(array $h): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0">'
           . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach ($h['filas'] as $r => $fila) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($fila) as $c => $v) {
                $ref = self::columna($c) . ($r + 1);
                $estilo = $r === 0 ? ' s="1"' : (in_array($c, $h['dinero'], true) ? ' s="2"' : '');
                if ($v === null || $v === '') {
                    $x .= "<c r=\"$ref\"$estilo/>";
                } elseif ((is_int($v) || is_float($v)) && $r > 0) {
                    $x .= "<c r=\"$ref\"$estilo><v>" . (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v) . '</v></c>';
                } else {
                    $x .= "<c r=\"$ref\" t=\"inlineStr\"$estilo><is><t xml:space=\"preserve\">" . self::x((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    }

    /** Contenido del archivo .xlsx */
    public function generar(): string
    {
        if (!$this->hojas) $this->hoja('Hoja1', [['']]);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
               . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $archivos = [];
        foreach ($this->hojas as $i => $h) {
            $n = $i + 1;
            $ct .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $libro .= '<sheet name="' . self::x($h['nombre']) . "\" sheetId=\"$n\" r:id=\"rId$n\"/>";
            $rels .= "<Relationship Id=\"rId$n\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$n.xml\"/>";
            $archivos["xl/worksheets/sheet$n.xml"] = $this->hojaXml($h);
        }
        $k = count($this->hojas) + 1;
        $rels .= "<Relationship Id=\"rId$k\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>";
        $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                 . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                 . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/></numFmts>'
                 . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                 . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                 . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                 . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                 . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                 . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                 . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                 . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $todo = [
            '[Content_Types].xml' => $ct . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $libro . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $rels,
            'xl/styles.xml' => $estilos,
        ] + $archivos;
        return self::zip($todo);
    }

    /** ZIP (método deflate) en PHP puro. @param array<string,string> $archivos */
    public static function zip(array $archivos): string
    {
        $datos = '';
        $central = '';
        $n = 0;
        [$hora, $fecha] = self::fechaDos(time());
        foreach ($archivos as $nombre => $contenido) {
            $comprimido = (string) gzdeflate($contenido, 6);
            $crc = crc32($contenido);
            $offset = strlen($datos);
            $cab = pack('vvvvvVVVvv', 20, 0x0800, 8, $hora, $fecha, $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0);
            $datos .= "PK\x03\x04" . $cab . $nombre . $comprimido;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $hora, $fecha, $crc, strlen($comprimido), strlen($contenido),
                                               strlen($nombre), 0, 0, 0, 0, 0, $offset) . $nombre;
            $n++;
        }
        return $datos . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), strlen($datos), 0);
    }

    private static function fechaDos(int $t): array
    {
        $d = getdate($t);
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
