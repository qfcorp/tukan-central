<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Cálculos de Demos, Embudo y Vencimientos (PROTOCOLO_V2 §9.5 E, F y H) con la copia local de clientes y solicitudes.
 */
final class Analisis
{
    public const ORIGENES_DEMO = ['central', 'demo_pedida', 'registro'];

    public function __construct(private PDO $db, private Clientes $clientes) {}

    private static function dias(string $a, string $b): int
    {
        return (int) (new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->format('%r%a');
    }

    /**
     * Historial de demos: las creadas desde el Central y las de los programas con origen demo o registro.
     * @return array{filas: list<array>, mes: array, por_programa: array, promedio_dias: ?float}
     */
    public function demos(?string $inst, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $mes = substr($hoy, 0, 7);
        $central = [];
        $st = $this->db->query('SELECT d.*, u.nombre AS creada_por_nombre FROM demos d LEFT JOIN usuarios u ON u.id = d.creada_por');
        foreach ($st->fetchAll() as $d) $central[$d['instancia_id'] . '|' . $d['cliente_id']] = $d;
        $filas = [];
        foreach ($this->clientes->todas($inst ?: null) as $c) {
            $k = $c['instancia_id'] . '|' . $c['cliente_id'];
            if (!in_array($c['origen'], self::ORIGENES_DEMO, true) && !isset($central[$k])) continue;
            $d = $central[$k] ?? null;
            $primer = $c['primer_pago'] ?? $d['primer_pago'] ?? null;
            $estadoDemo = $primer !== null ? 'pago' : ($c['estado'] === 'prueba' ? 'prueba' : ($c['estado'] === 'vencido' ? 'sin_pagar' : 'otro'));
            $filas[] = [
                'instancia_id' => $c['instancia_id'], 'programa' => $c['programa'], 'producto' => $c['producto'], 'cliente_id' => $c['cliente_id'],
                'nombre' => $c['nombre'], 'alta' => $c['alta'] ?? ($d ? date('Y-m-d', (int) $d['creada']) : null), 'estado' => $c['estado'], 'vence' => $c['vence'],
                'dias' => $c['dias_para_vencer'] !== null ? (int) $c['dias_para_vencer'] : null, 'primer_pago' => $primer,
                'monto_primer_pago' => $d['monto_primer_pago'] ?? ($primer !== null ? ($c['datos']['pagos']['ultimo_monto'] ?? null) : null),
                'origen' => $d ? 'central' : $c['origen'], 'creada_por' => $d['creada_por_nombre'] ?? null, 'estado_demo' => $estadoDemo,
            ];
            unset($central[$k]);
        }
        // Creadas desde el Central que ya no vienen en la copia (borradas en el programa)
        foreach ($central as $d) {
            if ($inst && $d['instancia_id'] !== $inst) continue;
            $filas[] = ['instancia_id' => $d['instancia_id'], 'programa' => null, 'producto' => null, 'cliente_id' => $d['cliente_id'], 'nombre' => $d['nombre'],
                        'alta' => date('Y-m-d', (int) $d['creada']), 'estado' => null, 'vence' => $d['prueba_hasta'], 'dias' => null, 'primer_pago' => $d['primer_pago'],
                        'monto_primer_pago' => $d['monto_primer_pago'], 'origen' => 'central', 'creada_por' => $d['creada_por_nombre'], 'estado_demo' => $d['primer_pago'] ? 'pago' : 'otro'];
        }
        usort($filas, fn($a, $b) => strcmp((string) $b['alta'], (string) $a['alta']));
        $resumen = function (array $l) use ($mes) {
            $delMes = array_filter($l, fn($f) => substr((string) $f['alta'], 0, 7) === $mes);
            $pagaron = count(array_filter($delMes, fn($f) => $f['primer_pago'] !== null));
            return ['demos' => count($delMes), 'pagaron' => $pagaron, 'porcentaje' => count($delMes) ? (int) round($pagaron * 100 / count($delMes)) : null];
        };
        $tiempos = [];
        foreach ($filas as $f) if ($f['primer_pago'] && $f['alta'] && $f['primer_pago'] >= $f['alta']) $tiempos[] = self::dias($f['alta'], $f['primer_pago']);
        $porPrograma = [];
        foreach ($filas as $f) if ($f['programa']) $porPrograma[$f['instancia_id']]['filas'][] = $f;
        foreach ($porPrograma as $i => $p) $porPrograma[$i] = $resumen($p['filas']) + ['programa' => $p['filas'][0]['programa'], 'producto' => $p['filas'][0]['producto'], 'total' => count($p['filas'])];
        return ['filas' => $filas, 'mes' => $resumen($filas), 'por_programa' => $porPrograma,
                'promedio_dias' => $tiempos ? round(array_sum($tiempos) / count($tiempos), 1) : null];
    }

    /**
     * Embudo por mes: interesados → demos/pruebas creadas → empezaron a pagar → siguen pagando hoy.
     * Interesados = cuentas nuevas del mes + pedidos de demo del mes que no se volvieron cuenta.
     */
    public function embudo(?string $inst, array $meses, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $base = array_fill_keys($meses, ['interesados' => 0, 'creadas' => 0, 'pagaron' => 0, 'siguen' => 0]);
        $clientes = $this->clientes->todas($inst ?: null);
        foreach ($clientes as $c) {
            $m = substr((string) $c['alta'], 0, 7);
            if (!isset($base[$m])) continue;
            $base[$m]['creadas']++;
            $base[$m]['interesados']++;
            if ($c['primer_pago'] !== null) $base[$m]['pagaron']++;
            if ($c['primer_pago'] !== null && in_array($c['estado'], ['activo', 'por_vencer'], true)) $base[$m]['siguen']++;
        }
        $sql = "SELECT fecha FROM solicitudes WHERE tipo = 'demo_pedida' AND estado IN ('pendiente', 'en_proceso', 'descartada')" . ($inst ? ' AND instancia_id = ?' : '');
        $st = $this->db->prepare($sql);
        $st->execute($inst ? [$inst] : []);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $m = date('Y-m', (int) strtotime($f));
            if (isset($base[$m])) $base[$m]['interesados']++;
        }
        $pct = fn(int $a, int $b) => $b > 0 ? (int) round($a * 100 / $b) : null;
        foreach ($base as $m => &$x) {
            $x['pct_creadas'] = $pct($x['creadas'], $x['interesados']);
            $x['pct_pagaron'] = $pct($x['pagaron'], $x['creadas']);
            $x['pct_siguen'] = $pct($x['siguen'], $x['pagaron']);
        }
        unset($x);
        $sin = [];
        $riesgo = [];
        $limite60 = date('Y-m-d', strtotime("$hoy -60 days"));
        $limite15 = strtotime("$hoy -15 days");
        foreach ($clientes as $c) {
            if ($c['estado'] === 'vencido' && (float) $c['total_pagado'] == 0.0 && $c['vence'] !== null && $c['vence'] >= $limite60) $sin[] = $c;
            $ua = $c['ultima_actividad'] ? strtotime($c['ultima_actividad']) : false;
            if ((float) $c['total_pagado'] > 0 && in_array($c['estado'], ['activo', 'por_vencer'], true) && ($ua === false || $ua < $limite15)) $riesgo[] = $c;
        }
        usort($sin, fn($a, $b) => strcmp((string) $b['vence'], (string) $a['vence']));
        usort($riesgo, fn($a, $b) => strcmp((string) $a['ultima_actividad'], (string) $b['ultima_actividad']));
        $total = ['interesados' => 0, 'creadas' => 0, 'pagaron' => 0, 'siguen' => 0];
        foreach ($base as $x) foreach ($total as $k => $_) $total[$k] += $x[$k];
        $total['pct_creadas'] = $pct($total['creadas'], $total['interesados']);
        $total['pct_pagaron'] = $pct($total['pagaron'], $total['creadas']);
        $total['pct_siguen'] = $pct($total['siguen'], $total['pagaron']);
        return ['meses' => $base, 'total' => $total, 'sin_pagar' => $sin, 'en_riesgo' => $riesgo];
    }

    /**
     * Calendario de vencimientos de un mes: por día, las pruebas que terminan (amarillo), los planes que vencen (verde)
     * y los que ya vencieron (rojo). Más: vencidos sin renovar y lo que está en juego esta semana.
     */
    public function vencimientos(?string $inst, string $mes, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $semana = date('Y-m-d', strtotime("$hoy +7 days"));
        $dias = [];
        $vencidos = [];
        $estaSemana = ['n' => 0, 'monto' => 0.0, 'filas' => []];
        foreach ($this->clientes->todas($inst ?: null) as $c) {
            if ($c['estado'] === 'suspendido' || $c['vence'] === null) continue;
            $color = $c['estado'] === 'vencido' ? 'rojo' : ($c['estado'] === 'prueba' ? 'amarillo' : 'verde');
            if ($c['estado'] === 'vencido') $vencidos[] = $c;
            if (substr($c['vence'], 0, 7) === $mes) $dias[$c['vence']][] = $c + ['color' => $color];
            if ($c['vence'] >= $hoy && $c['vence'] <= $semana) {
                $estaSemana['n']++;
                $estaSemana['monto'] += (float) $c['mensual_estimado'];
                $estaSemana['filas'][] = $c;
            }
        }
        ksort($dias);
        foreach ($dias as &$l) usort($l, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));
        unset($l);
        usort($estaSemana['filas'], fn($a, $b) => strcmp($a['vence'], $b['vence']));
        $estaSemana['monto'] = round($estaSemana['monto'], 2);
        return ['dias' => $dias, 'vencidos' => $vencidos, 'semana' => $estaSemana];
    }
}
