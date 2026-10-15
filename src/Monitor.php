<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Consulta el "estado" de todos los programas a la vez (curl_multi, 5 s como máximo),
 * guarda la última respuesta (la pantalla de Inicio se dibuja con lo guardado, aunque un programa esté caído),
 * lleva el historial de disponibilidad y manda los avisos de Telegram.
 */
final class Monitor
{
    public const TIEMPO_MAX = 5;          // segundos por consulta
    public const VIGENCIA = 120;          // la cifra guardada sirve 2 minutos
    public const FALLOS_PARA_AVISO = 2;   // avisa "caído" después de 2 fallos seguidos

    public function __construct(
        private PDO $db,
        private Instancias $instancias,
        private ?Telegram $telegram = null,
    ) {}

    /**
     * Consulta varias instancias en paralelo. No guarda nada.
     * @return array<string, array{en_linea:bool,http:int,ms:int,error:?string,error_detalle:?string,datos:?array,aviso:?string}>
     */
    public function consultar(array $insts, string $email, string $nombre, int $tiempo = self::TIEMPO_MAX): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $res = [];
        $cabeceras = [];
        $cuerpos = [];
        foreach ($insts as $i) {
            $id = $i['id'];
            try {
                $secreto = $this->instancias->secreto($i);
            } catch (\Throwable) {
                $res[$id] = self::resultadoError(0, 0, 'secreto_ilegible', Estado::ERRORES['secreto_ilegible']);
                continue;
            }
            $token = Token::crear('estado', $id, $email, $nombre, $secreto);
            $url = $i['url'] . '/tukan_central.php?accion=estado&t=' . $token;
            $ch = curl_init($url);
            $cabeceras[$id] = [];
            $cuerpos[$id] = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(4, $tiempo),
                CURLOPT_TIMEOUT => $tiempo,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'TukanCentral/1',
                CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1',
                CURLOPT_HEADERFUNCTION => function ($ch, string $h) use ($id, &$cabeceras): int {
                    $p = explode(':', $h, 2);
                    if (count($p) === 2) $cabeceras[$id][strtolower(trim($p[0]))] = trim($p[1]);
                    return strlen($h);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $trozo) use ($id, &$cuerpos): int {
                    if (strlen($cuerpos[$id]) + strlen($trozo) > 512 * 1024) return 0;   // corta respuestas gigantes
                    $cuerpos[$id] .= $trozo;
                    return strlen($trozo);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        do {
            $estado = curl_multi_exec($mh, $activos);
            if ($activos) curl_multi_select($mh, 0.5);
        } while ($activos && $estado === CURLM_OK);

        $errnos = [];
        while ($info = curl_multi_info_read($mh)) {
            foreach ($handles as $id => $ch) {
                if ($ch === $info['handle']) $errnos[$id] = (int) $info['result'];
            }
        }
        $porId = [];
        foreach ($insts as $i) $porId[$i['id']] = $i;
        foreach ($handles as $id => $ch) {
            $errno = $errnos[$id] ?? curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $error = $errno ? (curl_strerror($errno) ?: '') : '';
            $desfase = null;
            if (!empty($cabeceras[$id]['date']) && ($t = strtotime($cabeceras[$id]['date'])) !== false) $desfase = $t - time();
            $res[$id] = self::interpretar($porId[$id], $http, $cuerpos[$id], $errno, $error, $desfase, $ms);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $res;
    }

    private static function resultadoError(int $http, int $ms, string $codigo, string $texto): array
    {
        return ['en_linea' => false, 'http' => $http, 'ms' => $ms, 'error' => $codigo, 'error_detalle' => $texto, 'datos' => null, 'aviso' => null];
    }

    /** Convierte la respuesta HTTP en el resultado (sirve también para las pruebas sin red). */
    public static function interpretar(array $inst, int $http, string $cuerpo, int $errno, string $error, ?int $desfase, int $ms): array
    {
        if ($errno === 0 && $http === 200) {
            try {
                $datos = Estado::validar($cuerpo);
                $aviso = null;
                if ($inst['producto'] !== 'otro' && $datos['producto'] !== $inst['producto']) {
                    $aviso = 'El programa dice ser "' . $datos['producto'] . '" pero aquí está guardado como "' . $inst['producto'] . '".';
                }
                return ['en_linea' => true, 'http' => 200, 'ms' => $ms, 'error' => null, 'error_detalle' => null, 'datos' => $datos, 'aviso' => $aviso];
            } catch (EstadoInvalido $e) {
                return self::resultadoError(200, $ms, 'formato', Estado::ERRORES['formato'] . ' (' . $e->getMessage() . ')');
            }
        }
        [$codigo, $texto] = Estado::clasificar($http, $cuerpo, $errno, $error, $desfase);
        return self::resultadoError($http, $ms, $codigo, $texto);
    }

    /**
     * Consulta y guarda. $ids = null -> todos los programas activos.
     * Devuelve los resultados por ID.
     */
    public function refrescar(?array $ids, string $email, string $nombre, ?int $ahora = null): array
    {
        $insts = array_filter($this->instancias->lista(true), fn($i) => $ids === null || in_array($i['id'], $ids, true));
        if (!$insts) return [];
        $res = $this->consultar(array_values($insts), $email, $nombre);
        foreach ($insts as $i) {
            if (isset($res[$i['id']])) $this->guardar($i, $res[$i['id']], $ahora);
        }
        return $res;
    }

    /** Guarda un resultado, el historial y avisa por Telegram si algo cambió. */
    public function guardar(array $inst, array $r, ?int $ahora = null): void
    {
        $ahora ??= time();
        $id = $inst['id'];
        $st = $this->db->prepare('SELECT * FROM estados WHERE instancia_id = ?');
        $st->execute([$id]);
        $antes = $st->fetch() ?: null;
        $avisos = [];
        $avisoCaido = (int) ($antes['aviso_caido'] ?? 0);
        $fallos = $r['en_linea'] ? 0 : (int) ($antes['fallos_seguidos'] ?? 0) + 1;
        $nombre = $inst['nombre'];

        if ($r['en_linea']) {
            if ($avisoCaido) {
                $avisos[] = "✅ $nombre volvió a estar en línea.";
                $avisoCaido = 0;
            }
            $previos = $antes && $antes['datos'] ? json_decode($antes['datos'], true) : null;
            $totalAntes = $previos['negocios']['total'] ?? null;
            $totalAhora = $r['datos']['negocios']['total'] ?? null;
            if (is_int($totalAntes) && is_int($totalAhora) && $totalAhora > $totalAntes) {
                $n = $totalAhora - $totalAntes;
                $avisos[] = '🎉 ' . ($n === 1 ? 'Un negocio nuevo' : "$n negocios nuevos") . " en $nombre. Ahora son $totalAhora en total.";
            }
            $this->db->prepare('INSERT INTO estados (instancia_id, consultado, en_linea, http, ms, error, error_detalle, datos, datos_momento, fallos_seguidos, aviso_caido)
                                VALUES (?,?,1,?,?,NULL,?,?,?,0,?)
                                ON CONFLICT (instancia_id) DO UPDATE SET consultado = excluded.consultado, en_linea = 1, http = excluded.http, ms = excluded.ms,
                                  error = NULL, error_detalle = excluded.error_detalle, datos = excluded.datos, datos_momento = excluded.datos_momento,
                                  fallos_seguidos = 0, aviso_caido = excluded.aviso_caido')
                ->execute([$id, $ahora, $r['http'], $r['ms'], $r['aviso'], json_encode($r['datos'], JSON_UNESCAPED_UNICODE), $ahora, $avisoCaido]);
        } else {
            if ($fallos >= self::FALLOS_PARA_AVISO && !$avisoCaido && !Bajas::apagando($this->db, $id)) {
                $avisos[] = "🔴 $nombre está sin conexión. " . $r['error_detalle'];
                $avisoCaido = 1;
            }
            $this->db->prepare('INSERT INTO estados (instancia_id, consultado, en_linea, http, ms, error, error_detalle, fallos_seguidos, aviso_caido)
                                VALUES (?,?,0,?,?,?,?,?,?)
                                ON CONFLICT (instancia_id) DO UPDATE SET consultado = excluded.consultado, en_linea = 0, http = excluded.http, ms = excluded.ms,
                                  error = excluded.error, error_detalle = excluded.error_detalle, fallos_seguidos = excluded.fallos_seguidos, aviso_caido = excluded.aviso_caido')
                ->execute([$id, $ahora, $r['http'], $r['ms'], $r['error'], $r['error_detalle'], $fallos, $avisoCaido]);
        }

        // Historial: una marca por minuto como máximo, salvo que cambie el estado (caída o vuelta siempre se anotan)
        $st = $this->db->prepare('SELECT momento, en_linea FROM historial WHERE instancia_id = ? ORDER BY momento DESC LIMIT 1');
        $st->execute([$id]);
        $ultimo = $st->fetch() ?: ['momento' => 0, 'en_linea' => -1];
        if ($ahora - (int) $ultimo['momento'] >= 60 || (int) $ultimo['en_linea'] !== (int) $r['en_linea']) {
            $this->db->prepare('INSERT INTO historial (instancia_id, momento, en_linea, ms) VALUES (?,?,?,?)')
                ->execute([$id, $ahora, (int) $r['en_linea'], $r['ms']]);
            $this->db->prepare('DELETE FROM historial WHERE momento < ?')->execute([$ahora - 35 * 86400]);
        }

        if ($avisos && $this->telegram && Telegram::configurado()) {
            $ajustes = new Ajustes($this->db);
            foreach ($avisos as $a) {
                $esNegocio = str_starts_with($a, '🎉');
                if ($esNegocio ? $ajustes->si('avisar_negocios') : $ajustes->si('avisar_caidas')) {
                    $this->telegram->enviar("Panel Tukán · $a");
                }
            }
        }
    }

    /** Programas con su último estado guardado (para la pantalla de Inicio). */
    public function tablero(bool $soloActivas = false): array
    {
        $filas = $this->db->query('SELECT i.*, e.consultado, e.en_linea, e.http, e.ms, e.error, e.error_detalle, e.datos, e.datos_momento, e.fallos_seguidos
                                     FROM instancias i LEFT JOIN estados e ON e.instancia_id = i.id'
                                  . ($soloActivas ? ' WHERE i.activo = 1' : '') . ' ORDER BY i.activo DESC, i.orden, i.nombre')->fetchAll();
        foreach ($filas as &$f) {
            $f['datos'] = $f['datos'] ? json_decode($f['datos'], true) : null;
        }
        return $filas;
    }

    /** ¿Hace falta consultar otra vez? (alguna instancia activa sin dato o con dato de más de 2 minutos) */
    public static function hayViejos(array $tablero, ?int $ahora = null): bool
    {
        $ahora ??= time();
        foreach ($tablero as $f) {
            if ((int) $f['activo'] && ($f['consultado'] === null || $ahora - (int) $f['consultado'] > self::VIGENCIA)) return true;
        }
        return false;
    }

    /** Totales de todos los programas activos (con el último dato conocido). */
    public static function totales(array $tablero): array
    {
        $t = array_fill_keys(Estado::CLAVES_NEGOCIOS, 0);
        $t += ['nuevos_30d' => 0, 'ingresos_mes' => 0.0, 'ingresos_mes_anterior' => 0.0, 'con_negocios' => 0, 'con_ingresos' => 0,
               'en_linea' => 0, 'sin_conexion' => 0, 'activas' => 0];
        foreach ($tablero as $f) {
            if (!(int) $f['activo']) continue;
            $t['activas']++;
            $f['consultado'] !== null && (int) $f['en_linea'] ? $t['en_linea']++ : $t['sin_conexion']++;
            $d = $f['datos'];
            if (!$d) continue;
            if (is_array($d['negocios'])) {
                $t['con_negocios']++;
                foreach (Estado::CLAVES_NEGOCIOS as $k) $t[$k] += (int) ($d['negocios'][$k] ?? 0);
            }
            $t['nuevos_30d'] += (int) ($d['nuevos_30d'] ?? 0);
            if ($d['ingresos_mes'] !== null || $d['ingresos_mes_anterior'] !== null) {
                $t['con_ingresos']++;
                $t['ingresos_mes'] += (float) ($d['ingresos_mes'] ?? 0);
                $t['ingresos_mes_anterior'] += (float) ($d['ingresos_mes_anterior'] ?? 0);
            }
        }
        $t['ingresos_mes'] = round($t['ingresos_mes'], 2);
        $t['ingresos_mes_anterior'] = round($t['ingresos_mes_anterior'], 2);
        $t['variacion'] = $t['ingresos_mes_anterior'] > 0
            ? round(($t['ingresos_mes'] - $t['ingresos_mes_anterior']) * 100 / $t['ingresos_mes_anterior'], 1) : null;
        return $t;
    }

    /** "Para atender hoy": pruebas que vencen y pagos vencidos de todos los programas. */
    public static function pendientes(array $tablero): array
    {
        $p = ['pruebas' => [], 'pagos' => []];
        foreach ($tablero as $f) {
            if (!(int) $f['activo'] || !$f['datos']) continue;
            foreach ($f['datos']['pruebas_vencen_7d'] as $x) {
                $p['pruebas'][] = $x + ['instancia' => $f['id'], 'programa' => $f['nombre'], 'producto' => $f['producto']];
            }
            foreach ($f['datos']['pagos_vencidos'] as $x) {
                $p['pagos'][] = $x + ['instancia' => $f['id'], 'programa' => $f['nombre'], 'producto' => $f['producto']];
            }
        }
        usort($p['pruebas'], fn($a, $b) => strcmp((string) $a['vence'], (string) $b['vence']));
        usort($p['pagos'], fn($a, $b) => strcmp((string) $a['desde'], (string) $b['desde']));
        return $p;
    }

    /**
     * Disponibilidad de los últimos 7 días en 28 tramos de 6 horas (el más antiguo primero).
     * @return array{tramos: list<array{clase:string,desde:int,pct:?float}>, pct: ?float}
     */
    public function disponibilidad(string $id, ?int $ahora = null, int $dias = 7, int $horasTramo = 6): array
    {
        $ahora ??= time();
        $tramo = $horasTramo * 3600;
        $n = intdiv($dias * 24, $horasTramo);
        $inicio = $ahora - $n * $tramo;
        $st = $this->db->prepare('SELECT momento, en_linea FROM historial WHERE instancia_id = ? AND momento > ? ORDER BY momento');
        $st->execute([$id, $inicio]);
        $ok = array_fill(0, $n, 0);
        $tot = array_fill(0, $n, 0);
        $sOk = 0;
        $sTot = 0;
        foreach ($st->fetchAll() as $h) {
            $k = min($n - 1, intdiv((int) $h['momento'] - $inicio, $tramo));
            $tot[$k]++;
            $sTot++;
            if ((int) $h['en_linea']) {
                $ok[$k]++;
                $sOk++;
            }
        }
        $tramos = [];
        for ($k = 0; $k < $n; $k++) {
            $pct = $tot[$k] ? $ok[$k] * 100 / $tot[$k] : null;
            $tramos[] = [
                'clase' => $pct === null ? 'nada' : ($pct >= 99.99 ? 'ok' : ($pct > 0 ? 'medio' : 'mal')),
                'desde' => $inicio + $k * $tramo,
                'pct' => $pct === null ? null : round($pct, 1),
            ];
        }
        return ['tramos' => $tramos, 'pct' => $sTot ? round($sOk * 100 / $sTot, 1) : null];
    }
}
