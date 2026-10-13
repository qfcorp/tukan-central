<?php
declare(strict_types=1);

namespace Tukan;

use PDO;
use RuntimeException;

/**
 * Finanzas de Tukán (PROTOCOLO_V2 §5.11, §9.5 G y §9.6): ingresos que informa cada programa, gastos propios,
 * ingresos extra, gastos e ingresos fijos de cada mes, y la ganancia por mes y por programa.
 *
 * Ganancia de un programa en un mes = ingresos + otros_ingresos (del programa) + ingresos extra asignados
 *   − costos informados − gastos asignados (− su parte de los gastos generales, si se reparten).
 * Ganancia total = todos los ingresos − todos los costos y gastos (repartir no cambia el total).
 */
final class Finanzas
{
    public const CATEGORIAS = [
        'servidores' => 'Servidores/VPS', 'dominios' => 'Dominios', 'tiendas_apps' => 'Google Play/Apple', 'publicidad' => 'Publicidad',
        'comisiones_pasarela' => 'Comisiones de pasarela', 'sueldos_servicios' => 'Sueldos/servicios', 'otros' => 'Otros',
    ];
    public const METODOS = ['transferencia' => 'Transferencia', 'tarjeta' => 'Tarjeta', 'efectivo' => 'Efectivo', 'deposito' => 'Depósito', 'debito' => 'Débito automático', 'otro' => 'Otro'];

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------- meses

    public static function mesValido(mixed $m): bool
    {
        return is_string($m) && (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);
    }

    /** Meses de un período: "12" (últimos 12, con este), "24", "anio" (enero a este mes). */
    public static function meses(string $periodo, ?string $hoy = null): array
    {
        $hoy = new \DateTimeImmutable(($hoy ?? date('Y-m-d')) . ' 00:00');
        $fin = $hoy->modify('first day of this month');
        $n = match ($periodo) {
            '24' => 24,
            'anio' => (int) $fin->format('n'),
            default => 12,
        };
        $r = [];
        for ($i = $n - 1; $i >= 0; $i--) $r[] = $fin->modify("-$i months")->format('Y-m');
        return $r;
    }

    public static function nombreMes(string $mes, bool $corto = false): string
    {
        $n = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $c = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        [$a, $m] = explode('-', $mes);
        return $corto ? $c[(int) $m] . ' ' . substr($a, 2) : $n[(int) $m] . ' ' . $a;
    }

    // ------------------------------------------------------------- lo que informan los programas

    private static function num(mixed $v): float
    {
        return is_int($v) || is_float($v) ? round((float) $v, 2) : 0.0;
    }

    private static function txt(mixed $v, int $max = 80): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? ''), 0, $max) : null;
    }

    /** Revisa un mes de "finanzas". */
    public static function limpiarMes(mixed $m): ?array
    {
        if (!is_array($m) || !self::mesValido($m['mes'] ?? null)) return null;
        $grupo = function (mixed $lista, string $clave) {
            $r = [];
            foreach (is_array($lista) ? array_slice($lista, 0, 50) : [] as $x) {
                if (!is_array($x)) continue;
                $r[] = [$clave => self::txt(is_int($x[$clave] ?? null) ? (string) $x[$clave] : ($x[$clave] ?? null), 40) ?? '?',
                        'nombre' => self::txt($x['nombre'] ?? null) ?? self::txt(is_int($x[$clave] ?? null) ? (string) $x[$clave] : ($x[$clave] ?? null)) ?? '?',
                        'monto' => self::num($x['monto'] ?? 0), 'pagos' => is_int($x['pagos'] ?? null) ? $x['pagos'] : 0];
            }
            return $r;
        };
        $otros = [];
        foreach (is_array($m['otros_ingresos'] ?? null) ? $m['otros_ingresos'] : [] as $o) {
            if (is_array($o)) $otros[] = ['concepto' => self::txt($o['concepto'] ?? null, 40) ?? 'otro', 'nombre' => self::txt($o['nombre'] ?? null, 100) ?? 'Otros ingresos', 'monto' => self::num($o['monto'] ?? 0)];
        }
        $costos = [];
        foreach (is_array($m['costos'] ?? null) ? $m['costos'] : [] as $c) {
            if (!is_array($c)) continue;
            $costos[] = ['concepto' => self::txt($c['concepto'] ?? null, 40) ?? 'costo',
                         'categoria' => isset(self::CATEGORIAS[$c['categoria'] ?? '']) ? $c['categoria'] : 'otros',
                         'nombre' => self::txt($c['nombre'] ?? null, 100) ?? 'Costo', 'monto' => self::num($c['monto'] ?? 0), 'estimado' => ($c['estimado'] ?? false) === true];
        }
        return [
            'mes' => $m['mes'],
            'ingresos' => self::num($m['ingresos'] ?? 0),
            'pagos' => is_int($m['pagos'] ?? null) ? $m['pagos'] : 0,
            'clientes_que_pagaron' => is_int($m['clientes_que_pagaron'] ?? null) ? $m['clientes_que_pagaron'] : null,
            'nuevos_pagadores' => is_int($m['nuevos_pagadores'] ?? null) ? $m['nuevos_pagadores'] : null,
            'devengado' => isset($m['devengado']) && (is_int($m['devengado']) || is_float($m['devengado'])) ? round((float) $m['devengado'], 2) : null,
            'por_plan' => $grupo($m['por_plan'] ?? [], 'plan'),
            'por_periodo' => $grupo($m['por_periodo'] ?? [], 'periodo'),
            'por_metodo' => $grupo($m['por_metodo'] ?? [], 'metodo'),
            'otros_ingresos' => $otros,
            'costos' => $costos,
        ];
    }

    /** Pide "finanzas" de los últimos 24 meses (o lo que permita el programa) y guarda la copia. */
    public function refrescar(array $inst, ClienteV2 $cv, array $cap, ?array $usuario = null, ?int $ahora = null): int
    {
        $ahora ??= time();
        $n = max(1, min(24, (int) ($cap['limites']['meses_finanzas_max'] ?? 12)));
        $meses = array_slice(self::meses('24', date('Y-m-d', $ahora)), -$n);
        $r = $cv->pedir($inst, 'finanzas', ['desde' => $meses[0], 'hasta' => end($meses)], $usuario);
        $st = $this->db->prepare('INSERT INTO finanzas_mes (instancia_id, mes, datos, consultado) VALUES (?,?,?,?)
                                  ON CONFLICT (instancia_id, mes) DO UPDATE SET datos = excluded.datos, consultado = excluded.consultado');
        $guardados = 0;
        $this->db->beginTransaction();
        foreach (is_array($r['meses'] ?? null) ? $r['meses'] : [] as $m) {
            if (($l = self::limpiarMes($m)) === null) continue;
            $st->execute([$inst['id'], $l['mes'], json_encode($l, JSON_UNESCAPED_UNICODE), $ahora]);
            $guardados++;
        }
        $this->db->commit();
        (new Sincronizacion($this->db))->guardar($inst['id'], 'finanzas', $ahora, null, [
            'mrr' => self::num($r['mrr'] ?? 0), 'criterio' => self::txt($r['criterio'] ?? null, 300),
        ]);
        return $guardados;
    }

    /** @return array<string, array<string, array>> inst => mes => datos */
    public function deProgramas(array $meses): array
    {
        if (!$meses) return [];
        $in = implode(',', array_fill(0, count($meses), '?'));
        $st = $this->db->prepare("SELECT f.instancia_id, f.mes, f.datos FROM finanzas_mes f JOIN instancias i ON i.id = f.instancia_id AND i.activo = 1 WHERE f.mes IN ($in)");
        $st->execute($meses);
        $r = [];
        foreach ($st->fetchAll() as $f) $r[$f['instancia_id']][$f['mes']] = json_decode($f['datos'], true);
        return $r;
    }

    // ------------------------------------------------------------- gastos e ingresos extra

    private function instanciaValida(?string $inst): ?string
    {
        if ($inst === null || $inst === '') return null;
        $st = $this->db->prepare('SELECT id FROM instancias WHERE id = ?');
        $st->execute([$inst]);
        if (!$st->fetchColumn()) throw new RuntimeException('Ese programa no existe.');
        return $inst;
    }

    private function producto(?string $inst): ?string
    {
        if ($inst === null) return null;
        $st = $this->db->prepare('SELECT producto FROM instancias WHERE id = ?');
        $st->execute([$inst]);
        return $st->fetchColumn() ?: null;
    }

    private static function revisar(array $d, bool $esGasto): array
    {
        $fecha = (string) ($d['fecha'] ?? '');
        if (!\DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)) throw new RuntimeException('Elige la fecha.');
        $monto = is_float($d['monto'] ?? null) || is_int($d['monto'] ?? null) ? (float) $d['monto'] : Formulario::numero((string) ($d['monto'] ?? ''));
        if ($monto === null || $monto < 0 || $monto > 10000000) throw new RuntimeException('Escribe el valor con números, por ejemplo 12,50.');
        $texto = trim((string) ($d[$esGasto ? 'descripcion' : 'concepto'] ?? ''));
        if (mb_strlen($texto) < 2 || mb_strlen($texto) > 200) throw new RuntimeException($esGasto ? 'Escribe en qué se gastó (2 a 200 letras).' : 'Escribe de qué es el ingreso (2 a 200 letras).');
        $metodo = trim((string) ($d['metodo'] ?? ''));
        $dia = (int) ($d['dia'] ?? (int) substr($fecha, 8, 2));
        return ['fecha' => $fecha, 'mes' => substr($fecha, 0, 7), 'monto' => round($monto, 2), 'texto' => $texto,
                'metodo' => $metodo === '' ? null : mb_substr($metodo, 0, 40), 'dia' => max(1, min(28, $dia))];
    }

    /** Agrega un gasto. Con "recurrente" = true también queda como gasto fijo de cada mes (desde el mes de la fecha). */
    public function guardarGasto(array $d, ?int $uid, ?int $ahora = null): int
    {
        $ahora ??= time();
        $r = self::revisar($d, true);
        $cat = (string) ($d['categoria'] ?? '');
        if (!isset(self::CATEGORIAS[$cat])) throw new RuntimeException('Elige la categoría.');
        $inst = $this->instanciaValida(($d['instancia_id'] ?? '') ?: null);
        $ref = mb_substr(trim((string) ($d['referencia'] ?? '')), 0, 100);
        $rec = null;
        if (!empty($d['recurrente'])) {
            $this->db->prepare('INSERT INTO recurrentes (tipo, categoria, descripcion, monto, dia, producto, instancia_id, desde_mes, creado) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute(['gasto', $cat, $r['texto'], $r['monto'], $r['dia'], $this->producto($inst), $inst, $r['mes'], $ahora]);
            $rec = (int) $this->db->lastInsertId();
        }
        $this->db->prepare('INSERT INTO gastos (fecha, mes, categoria, descripcion, monto, producto, instancia_id, recurrente_id, metodo, referencia, creado_por, creado)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$r['fecha'], $r['mes'], $cat, $r['texto'], $r['monto'], $this->producto($inst), $inst, $rec, $r['metodo'], $ref === '' ? null : $ref, $uid, $ahora]);
        return (int) $this->db->lastInsertId();
    }

    public function guardarIngresoExtra(array $d, ?int $uid, ?int $ahora = null): int
    {
        $ahora ??= time();
        $r = self::revisar($d, false);
        $inst = $this->instanciaValida(($d['instancia_id'] ?? '') ?: null);
        $rec = null;
        if (!empty($d['recurrente'])) {
            $this->db->prepare('INSERT INTO recurrentes (tipo, categoria, descripcion, monto, dia, producto, instancia_id, desde_mes, creado) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute(['ingreso', null, $r['texto'], $r['monto'], $r['dia'], $this->producto($inst), $inst, $r['mes'], $ahora]);
            $rec = (int) $this->db->lastInsertId();
        }
        $this->db->prepare('INSERT INTO ingresos_extra (fecha, mes, concepto, monto, producto, instancia_id, recurrente_id, metodo, creado_por, creado)
                            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$r['fecha'], $r['mes'], $r['texto'], $r['monto'], $this->producto($inst), $inst, $rec, $r['metodo'], $uid, $ahora]);
        return (int) $this->db->lastInsertId();
    }

    /** Anula (no borra) un gasto o un ingreso extra. */
    public function anular(string $tabla, int $id): array
    {
        if (!in_array($tabla, ['gastos', 'ingresos_extra'], true)) throw new RuntimeException('Tabla no válida.');
        $st = $this->db->prepare("SELECT * FROM $tabla WHERE id = ? AND anulado = 0");
        $st->execute([$id]);
        $f = $st->fetch() ?: throw new RuntimeException('Ese registro no existe o ya está anulado.');
        $this->db->prepare("UPDATE $tabla SET anulado = 1 WHERE id = ?")->execute([$id]);
        return $f;
    }

    public function gastos(string $mes): array
    {
        $st = $this->db->prepare('SELECT g.*, i.nombre AS programa, r.activo AS fijo_activo FROM gastos g LEFT JOIN instancias i ON i.id = g.instancia_id
                                  LEFT JOIN recurrentes r ON r.id = g.recurrente_id WHERE g.mes = ? ORDER BY g.anulado, g.fecha DESC, g.id DESC');
        $st->execute([$mes]);
        return $st->fetchAll();
    }

    public function ingresosExtra(string $mes): array
    {
        $st = $this->db->prepare('SELECT x.*, i.nombre AS programa FROM ingresos_extra x LEFT JOIN instancias i ON i.id = x.instancia_id WHERE x.mes = ? ORDER BY x.anulado, x.fecha DESC, x.id DESC');
        $st->execute([$mes]);
        return $st->fetchAll();
    }

    /** Todos (para exportar). */
    public function movimientos(string $tabla, array $meses): array
    {
        if (!in_array($tabla, ['gastos', 'ingresos_extra'], true) || !$meses) return [];
        $in = implode(',', array_fill(0, count($meses), '?'));
        $st = $this->db->prepare("SELECT x.*, i.nombre AS programa FROM $tabla x LEFT JOIN instancias i ON i.id = x.instancia_id WHERE x.anulado = 0 AND x.mes IN ($in) ORDER BY x.fecha, x.id");
        $st->execute($meses);
        return $st->fetchAll();
    }

    /** Costos que informan los programas en un mes (salen aparte: "lo informa TuVocalía"). */
    public function costosInformados(string $mes): array
    {
        $r = [];
        foreach ($this->deProgramas([$mes]) as $inst => $porMes) {
            $nombre = $this->db->query('SELECT nombre FROM instancias WHERE id = ' . $this->db->quote($inst))->fetchColumn();
            foreach ($porMes[$mes]['costos'] ?? [] as $c) $r[] = $c + ['instancia_id' => $inst, 'programa' => $nombre];
        }
        return $r;
    }

    // ------------------------------------------------------------- gastos e ingresos fijos

    public function recurrentes(?string $tipo = null): array
    {
        $sql = 'SELECT r.*, i.nombre AS programa FROM recurrentes r LEFT JOIN instancias i ON i.id = r.instancia_id';
        if ($tipo !== null) $sql .= ' WHERE r.tipo = ' . $this->db->quote($tipo);
        return $this->db->query($sql . ' ORDER BY r.hasta_mes IS NOT NULL, r.activo DESC, r.descripcion')->fetchAll();
    }

    public function recurrente(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM recurrentes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** pausar | reanudar | terminar (desde el mes que viene ya no se crea) | monto (cambia el valor desde este mes). */
    public function cambiarRecurrente(int $id, string $que, ?float $monto = null, ?string $hoy = null): void
    {
        $r = $this->recurrente($id) ?? throw new RuntimeException('Ese gasto fijo no existe.');
        $mes = substr($hoy ?? date('Y-m-d'), 0, 7);
        switch ($que) {
            case 'pausar':
                $this->db->prepare('UPDATE recurrentes SET activo = 0 WHERE id = ?')->execute([$id]);
                break;
            case 'reanudar':
                $this->db->prepare('UPDATE recurrentes SET activo = 1, hasta_mes = NULL WHERE id = ?')->execute([$id]);
                break;
            case 'terminar':
                $this->db->prepare('UPDATE recurrentes SET hasta_mes = ?, activo = 0 WHERE id = ?')->execute([$mes, $id]);
                break;
            case 'monto':
                if ($monto === null || $monto < 0 || $monto > 10000000) throw new RuntimeException('Escribe el valor con números.');
                $this->db->prepare('UPDATE recurrentes SET monto = ? WHERE id = ?')->execute([round($monto, 2), $id]);
                $tabla = $r['tipo'] === 'gasto' ? 'gastos' : 'ingresos_extra';
                $this->db->prepare("UPDATE $tabla SET monto = ? WHERE recurrente_id = ? AND mes = ? AND anulado = 0")->execute([round($monto, 2), $id, $mes]);
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    }

    /**
     * Crea los gastos e ingresos fijos de un mes (una sola vez por mes gracias al índice único).
     * Solo el mes pedido (no rellena meses pausados hacia atrás). Devuelve cuántos creó.
     */
    public function generarFijos(string $mes, ?int $ahora = null): int
    {
        $ahora ??= time();
        $n = 0;
        $st = $this->db->prepare("SELECT * FROM recurrentes WHERE activo = 1 AND desde_mes <= ? AND (hasta_mes IS NULL OR hasta_mes >= ?)");
        $st->execute([$mes, $mes]);
        foreach ($st->fetchAll() as $r) {
            $fecha = $mes . '-' . str_pad((string) $r['dia'], 2, '0', STR_PAD_LEFT);
            if ($r['tipo'] === 'gasto') {
                $ins = $this->db->prepare('INSERT OR IGNORE INTO gastos (fecha, mes, categoria, descripcion, monto, producto, instancia_id, recurrente_id, metodo, creado_por, creado)
                                           VALUES (?,?,?,?,?,?,?,?,NULL,NULL,?)');
                $ins->execute([$fecha, $mes, $r['categoria'] ?: 'otros', $r['descripcion'], $r['monto'], $r['producto'], $r['instancia_id'], $r['id'], $ahora]);
            } else {
                $ins = $this->db->prepare('INSERT OR IGNORE INTO ingresos_extra (fecha, mes, concepto, monto, producto, instancia_id, recurrente_id, metodo, creado_por, creado)
                                           VALUES (?,?,?,?,?,?,?,NULL,NULL,?)');
                $ins->execute([$fecha, $mes, $r['descripcion'], $r['monto'], $r['producto'], $r['instancia_id'], $r['id'], $ahora]);
            }
            $n += $ins->rowCount();
        }
        return $n;
    }

    // ------------------------------------------------------------- cálculo

    private function sumas(string $tabla, array $meses): array
    {
        $r = [];
        if (!$meses) return $r;
        $in = implode(',', array_fill(0, count($meses), '?'));
        $st = $this->db->prepare("SELECT coalesce(instancia_id, '') AS inst, mes, sum(monto) AS total FROM $tabla WHERE anulado = 0 AND mes IN ($in) GROUP BY inst, mes");
        $st->execute($meses);
        foreach ($st->fetchAll() as $f) $r[$f['inst']][$f['mes']] = round((float) $f['total'], 2);
        return $r;
    }

    /** MRR de hoy: lo que informa cada programa + las licencias vigentes. */
    public function mrr(?string $hoy = null): float
    {
        $hoy ??= date('Y-m-d');
        $t = 0.0;
        foreach ($this->db->query("SELECT s.datos FROM sincronizacion s JOIN instancias i ON i.id = s.instancia_id AND i.activo = 1 WHERE s.que = 'finanzas'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $t += (float) (json_decode((string) $d, true)['mrr'] ?? 0);
        }
        $st = $this->db->prepare('SELECT coalesce(sum(l.valor_mensual), 0) FROM licencias l JOIN instancias i ON i.id = l.instancia_id AND i.activo = 1 WHERE l.activo_hasta >= ?');
        $st->execute([$hoy]);
        return round($t + (float) $st->fetchColumn(), 2);
    }

    /**
     * Resumen por mes y por programa.
     * @return array{meses: list<string>, programas: array, general: array, total: array, mrr: float, con_datos: list<string>}
     */
    public function resumen(array $meses, bool $repartir, ?array $programas = null, ?string $hoy = null): array
    {
        $programas ??= $this->db->query('SELECT id, nombre, producto FROM instancias WHERE activo = 1 ORDER BY orden, nombre')->fetchAll();
        $fp = $this->deProgramas($meses);
        $gastos = $this->sumas('gastos', $meses);
        $extra = $this->sumas('ingresos_extra', $meses);
        $cero = ['ingresos' => 0.0, 'otros' => 0.0, 'extra' => 0.0, 'costos' => 0.0, 'gastos' => 0.0, 'parte_general' => 0.0, 'ganancia' => 0.0];
        $prog = [];
        foreach ($programas as $p) {
            $id = $p['id'];
            $fila = ['id' => $id, 'nombre' => $p['nombre'], 'producto' => $p['producto'], 'meses' => [], 'total' => $cero, 'informa' => isset($fp[$id])];
            foreach ($meses as $m) {
                $d = $fp[$id][$m] ?? null;
                $x = $cero;
                if ($d) {
                    $x['ingresos'] = (float) $d['ingresos'];
                    $x['otros'] = round(array_sum(array_column($d['otros_ingresos'], 'monto')), 2);
                    $x['costos'] = round(array_sum(array_column($d['costos'], 'monto')), 2);
                }
                $x['extra'] = $extra[$id][$m] ?? 0.0;
                $x['gastos'] = $gastos[$id][$m] ?? 0.0;
                $fila['meses'][$m] = $x;
            }
            $prog[$id] = $fila;
        }
        // Gastos generales (sin programa): se reparten según lo que gana cada programa en el mes, si se pidió
        $general = ['meses' => [], 'total' => ['extra' => 0.0, 'gastos' => 0.0, 'repartido' => 0.0, 'ganancia' => 0.0]];
        foreach ($meses as $m) {
            $g = $gastos[''][$m] ?? 0.0;
            $e = $extra[''][$m] ?? 0.0;
            $repartido = 0.0;
            if ($repartir && $g > 0) {
                $base = 0.0;
                foreach ($prog as $p) $base += max(0, $p['meses'][$m]['ingresos'] + $p['meses'][$m]['otros'] + $p['meses'][$m]['extra']);
                if ($base > 0) {
                    $resto = $g;
                    $ids = array_keys($prog);
                    foreach ($ids as $k => $id) {
                        $x = &$prog[$id]['meses'][$m];
                        $peso = max(0, $x['ingresos'] + $x['otros'] + $x['extra']) / $base;
                        $parte = $k === count($ids) - 1 ? $resto : round($g * $peso, 2);
                        $parte = min($parte, $resto);
                        $x['parte_general'] = round($parte, 2);
                        $resto = round($resto - $parte, 2);
                        unset($x);
                    }
                    $repartido = $g;
                }
            }
            $general['meses'][$m] = ['extra' => $e, 'gastos' => $g, 'repartido' => $repartido, 'ganancia' => round($e - ($g - $repartido), 2)];
        }
        // Ganancias y totales
        $total = ['meses' => [], 'total' => ['ingresos' => 0.0, 'gastos' => 0.0, 'ganancia' => 0.0, 'costos' => 0.0, 'gastos_propios' => 0.0]];
        foreach ($meses as $m) $total['meses'][$m] = ['ingresos' => 0.0, 'gastos' => 0.0, 'ganancia' => 0.0, 'costos' => 0.0, 'gastos_propios' => 0.0];
        foreach ($prog as $id => &$p) {
            foreach ($meses as $m) {
                $x = &$p['meses'][$m];
                $x['ganancia'] = round($x['ingresos'] + $x['otros'] + $x['extra'] - $x['costos'] - $x['gastos'] - $x['parte_general'], 2);
                foreach ($cero as $k => $_) $p['total'][$k] = round($p['total'][$k] + $x[$k], 2);
                $total['meses'][$m]['ingresos'] += $x['ingresos'] + $x['otros'] + $x['extra'];
                $total['meses'][$m]['costos'] += $x['costos'];
                $total['meses'][$m]['gastos_propios'] += $x['gastos'];
                unset($x);
            }
        }
        unset($p);
        foreach ($meses as $m) {
            $t = &$total['meses'][$m];
            $t['ingresos'] = round($t['ingresos'] + $general['meses'][$m]['extra'], 2);
            $t['gastos_propios'] = round($t['gastos_propios'] + $general['meses'][$m]['gastos'], 2);
            $t['costos'] = round($t['costos'], 2);
            $t['gastos'] = round($t['costos'] + $t['gastos_propios'], 2);
            $t['ganancia'] = round($t['ingresos'] - $t['gastos'], 2);
            foreach (['ingresos', 'gastos', 'ganancia', 'costos', 'gastos_propios'] as $k) $total['total'][$k] = round($total['total'][$k] + $t[$k], 2);
            foreach (['extra', 'gastos', 'repartido', 'ganancia'] as $k) $general['total'][$k] = round($general['total'][$k] + $general['meses'][$m][$k], 2);
            unset($t);
        }
        // Solo se muestran los programas que tienen algo en el período
        $prog = array_filter($prog, fn($p) => $p['informa'] || array_sum(array_map('abs', $p['total'])) > 0);
        return ['meses' => $meses, 'programas' => $prog, 'general' => $general, 'total' => $total, 'mrr' => $this->mrr($hoy), 'repartir' => $repartir];
    }

    /** Detalle de ingresos de un programa en el período: por plan, por tiempo y por forma de pago. */
    public function detallePrograma(string $inst, array $meses): array
    {
        $r = ['por_plan' => [], 'por_periodo' => [], 'por_metodo' => [], 'otros_ingresos' => [], 'costos' => [], 'meses' => []];
        foreach ($this->deProgramas($meses)[$inst] ?? [] as $mes => $d) {
            $r['meses'][$mes] = $d;
            foreach (['por_plan' => 'plan', 'por_periodo' => 'periodo', 'por_metodo' => 'metodo'] as $g => $k) {
                foreach ($d[$g] as $x) {
                    $c = $x[$k];
                    $r[$g][$c] ??= ['nombre' => $x['nombre'], 'monto' => 0.0, 'pagos' => 0];
                    $r[$g][$c]['monto'] = round($r[$g][$c]['monto'] + $x['monto'], 2);
                    $r[$g][$c]['pagos'] += $x['pagos'];
                }
            }
            foreach (['otros_ingresos', 'costos'] as $g) {
                foreach ($d[$g] as $x) {
                    $r[$g][$x['concepto']] ??= ['nombre' => $x['nombre'], 'monto' => 0.0];
                    $r[$g][$x['concepto']]['monto'] = round($r[$g][$x['concepto']]['monto'] + $x['monto'], 2);
                }
            }
        }
        foreach (['por_plan', 'por_periodo', 'por_metodo', 'otros_ingresos', 'costos'] as $g) uasort($r[$g], fn($a, $b) => $b['monto'] <=> $a['monto']);
        ksort($r['meses']);
        return $r;
    }
}
