<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Descargas de Finanzas (PROTOCOLO_V2 §9.5 G):
 * - Excel (.xlsx) con las hojas Resumen, Por programa, Ingresos, Gastos e Ingresos extra.
 * - CSV para Excel en español: UTF-8 con BOM, separado por ";" y decimales con coma (una fila por movimiento).
 */
final class Exportar
{
    public function __construct(private PDO $db, private Finanzas $finanzas) {}

    /** Filas de cada hoja (también las usa el CSV). */
    public function hojas(array $meses, bool $repartir): array
    {
        $r = $this->finanzas->resumen($meses, $repartir);
        $resumen = [['Mes', 'Ingresos', 'Costos de los programas', 'Gastos propios', 'Gastos (total)', 'Ganancia']];
        foreach ($meses as $m) {
            $t = $r['total']['meses'][$m];
            $resumen[] = [$m, $t['ingresos'], $t['costos'], $t['gastos_propios'], $t['gastos'], $t['ganancia']];
        }
        $tt = $r['total']['total'];
        $resumen[] = ['Total', $tt['ingresos'], $tt['costos'], $tt['gastos_propios'], $tt['gastos'], $tt['ganancia']];
        $resumen[] = ['Ingreso mensual recurrente (hoy)', $r['mrr'], null, null, null, null];

        $porPrograma = [['Programa', 'Mes', 'Ingresos', 'Otros ingresos', 'Ingresos extra', 'Costos informados', 'Gastos asignados', 'Parte de gastos generales', 'Ganancia']];
        foreach ($r['programas'] as $p) {
            foreach ($meses as $m) {
                $x = $p['meses'][$m];
                $porPrograma[] = [$p['nombre'], $m, $x['ingresos'], $x['otros'], $x['extra'], $x['costos'], $x['gastos'], $x['parte_general'], $x['ganancia']];
            }
        }
        foreach ($meses as $m) {
            $g = $r['general']['meses'][$m];
            $porPrograma[] = ['General (sin programa)', $m, 0.0, 0.0, $g['extra'], 0.0, $g['gastos'], -$g['repartido'], $g['ganancia']];
        }

        $ingresos = [['Programa', 'Mes', 'Grupo', 'Detalle', 'Monto', 'Pagos']];
        $prog = array_column($this->db->query('SELECT id, nombre FROM instancias')->fetchAll(), 'nombre', 'id');
        foreach ($this->finanzas->deProgramas($meses) as $inst => $porMes) {
            ksort($porMes);
            foreach ($porMes as $mes => $d) {
                $ingresos[] = [$prog[$inst] ?? $inst, $mes, 'Total', 'Suscripciones', $d['ingresos'], $d['pagos']];
                foreach (['por_plan' => 'Plan', 'por_periodo' => 'Tiempo', 'por_metodo' => 'Forma de pago'] as $g => $t) {
                    foreach ($d[$g] as $x) $ingresos[] = [$prog[$inst] ?? $inst, $mes, $t, $x['nombre'], $x['monto'], $x['pagos']];
                }
                foreach ($d['otros_ingresos'] as $x) $ingresos[] = [$prog[$inst] ?? $inst, $mes, 'Otros ingresos', $x['nombre'], $x['monto'], null];
                foreach ($d['costos'] as $x) $ingresos[] = [$prog[$inst] ?? $inst, $mes, 'Costo informado', $x['nombre'] . ($x['estimado'] ? ' (estimado)' : ''), -$x['monto'], null];
            }
        }

        $gastos = [['Fecha', 'Mes', 'Categoría', 'Descripción', 'Programa', 'Monto', 'Forma de pago', 'Referencia', 'Fijo']];
        foreach ($this->finanzas->movimientos('gastos', $meses) as $g) {
            $gastos[] = [$g['fecha'], $g['mes'], Finanzas::CATEGORIAS[$g['categoria']] ?? $g['categoria'], $g['descripcion'], $g['programa'] ?? 'General',
                         (float) $g['monto'], $g['metodo'], $g['referencia'], $g['recurrente_id'] ? 'Sí' : 'No'];
        }
        $extra = [['Fecha', 'Mes', 'Concepto', 'Programa', 'Monto', 'Forma de pago', 'Fijo']];
        foreach ($this->finanzas->movimientos('ingresos_extra', $meses) as $x) {
            $extra[] = [$x['fecha'], $x['mes'], $x['concepto'], $x['programa'] ?? 'General', (float) $x['monto'], $x['metodo'], $x['recurrente_id'] ? 'Sí' : 'No'];
        }
        return ['Resumen' => [$resumen, [1, 2, 3, 4, 5]], 'Por programa' => [$porPrograma, [2, 3, 4, 5, 6, 7, 8]], 'Ingresos' => [$ingresos, [4]],
                'Gastos' => [$gastos, [5]], 'Ingresos extra' => [$extra, [4]]];
    }

    public function xlsx(array $meses, bool $repartir): string
    {
        $x = new Xlsx();
        foreach ($this->hojas($meses, $repartir) as $nombre => [$filas, $dinero]) $x->hoja($nombre, $filas, $dinero);
        return $x->generar();
    }

    /** Número para el CSV en español: 1234,5 (sin separador de miles, para que Excel lo lea como número). */
    public static function numeroCsv(float|int $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
    }

    public static function lineaCsv(array $fila): string
    {
        return implode(';', array_map(function ($v) {
            if ($v === null) return '';
            if (is_int($v) || is_float($v)) return self::numeroCsv($v);
            $v = (string) $v;
            // Evita fórmulas al abrir en Excel (inyección CSV)
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) $v = "'" . $v;
            return preg_match('/[;"\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $fila)) . "\r\n";
    }

    /** CSV: una fila por movimiento (ingresos de cada programa por mes, otros ingresos, costos, gastos e ingresos extra). */
    public function csv(array $meses, bool $repartir): string
    {
        $h = $this->hojas($meses, $repartir);
        $s = "\xEF\xBB\xBF" . self::lineaCsv(['Tipo', 'Mes', 'Fecha', 'Programa', 'Categoría o concepto', 'Descripción', 'Monto']);
        foreach (array_slice($h['Ingresos'][0], 1) as $f) {
            if ($f[2] === 'Total') $s .= self::lineaCsv(['Ingreso del programa', $f[1], '', $f[0], 'Suscripciones', '', $f[4]]);
            elseif ($f[2] === 'Otros ingresos') $s .= self::lineaCsv(['Otro ingreso del programa', $f[1], '', $f[0], 'Otros ingresos', $f[3], $f[4]]);
            elseif ($f[2] === 'Costo informado') $s .= self::lineaCsv(['Costo del programa', $f[1], '', $f[0], 'Costo informado', $f[3], $f[4]]);
        }
        foreach (array_slice($h['Gastos'][0], 1) as $g) $s .= self::lineaCsv(['Gasto', $g[1], $g[0], $g[4], $g[2], $g[3], -$g[5]]);
        foreach (array_slice($h['Ingresos extra'][0], 1) as $x) $s .= self::lineaCsv(['Ingreso extra', $x[1], $x[0], $x[3], 'Ingreso extra', $x[2], $x[4]]);
        return $s;
    }
}
