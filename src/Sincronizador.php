<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Revisión de los programas (PROTOCOLO_V2 §9.4): la usa el cron (bin/revisar.php, cada 15 minutos) y el botón
 * "Actualizar" del panel.
 *  1. "estado" de todos (como en v1).
 *  2. "capacidades" si cambió la versión, si pasó un día o si el programa dice que ya habla v2.
 *  3. "solicitudes" si cambió su huella (o si el programa no la manda).
 *  4. "clientes" cada hora (todas las páginas) o si las cifras de "estado" no coinciden con las guardadas.
 *  5. "finanzas" una vez al día (03:10) y los gastos/ingresos fijos del mes.
 *  6. Avisos de Telegram de las solicitudes nuevas (fuera del horario de silencio) y el resumen de las 07:30.
 */
final class Sincronizador
{
    public const TIPOS_AVISO_PREDETERMINADOS = ['registro_nuevo', 'demo_pedida', 'comprobante', 'cambio_plan', 'soporte', 'derechos_datos'];

    /** Lo que pasó (para la salida del cron). */
    public array $registro = [];

    private Capacidades $cap;
    private Sincronizacion $sync;
    private Clientes $clientes;
    private Solicitudes $solicitudes;
    private Finanzas $finanzas;
    private Ajustes $ajustes;

    public function __construct(private PDO $db, private Instancias $instancias, private Monitor $monitor, private ClienteV2 $cv,
                                private ?Telegram $telegram = null, ?Cifrado $cifrado = null)
    {
        $this->cap = new Capacidades($db);
        $this->sync = new Sincronizacion($db);
        $this->clientes = new Clientes($db, $cifrado);
        $this->solicitudes = new Solicitudes($db, $cifrado);
        $this->finanzas = new Finanzas($db);
        $this->ajustes = new Ajustes($db);
    }

    private function anotar(string $id, string $texto): void
    {
        $this->registro[] = "$id: $texto";
    }

    /**
     * Revisa todos los programas activos (o $ids). $cron = true: reintenta los caídos y usa los tiempos del cron.
     * @return array<string, array> resultado del "estado" de cada uno
     */
    public function revisar(?array $ids, string $email, string $nombre, bool $cron, ?int $ahora = null): array
    {
        $res = $this->monitor->refrescar($ids, $email, $nombre, $ahora);
        $fallaron = array_keys(array_filter($res, fn($r) => !$r['en_linea']));
        if ($cron && $fallaron) {
            $espera = getenv('TUKAN_REVISAR_ESPERA');
            sleep($espera !== false ? (int) $espera : 10);
            foreach ($this->monitor->refrescar($fallaron, $email, $nombre) as $id => $r) $res[$id] = $r;
        }
        $usuario = ['email' => $email, 'nombre' => $nombre, 'rol' => 'dueno'];
        foreach ($this->instancias->lista(true) as $inst) {
            if (!isset($res[$inst['id']]) || !$res[$inst['id']]['en_linea']) continue;
            $this->programa($inst, $res[$inst['id']]['datos'], $cron, [], $usuario, $ahora);
        }
        return $res;
    }

    /**
     * Pasos 2 a 5 para un programa. $forzar: lista de cosas a pedir sí o sí (capacidades, solicitudes, clientes, finanzas).
     */
    public function programa(array $inst, ?array $estado, bool $cron, array $forzar = [], ?array $usuario = null, ?int $ahora = null): void
    {
        $ahora ??= time();
        $id = $inst['id'];
        // 2) capacidades
        $c = $this->cap->obtener($id);
        $verCap = $this->sync->obtener($id, 'capacidades');
        $version = $estado['version'] ?? null;
        $pedir = in_array('capacidades', $forzar, true) || $c === null || $ahora - $c['consultado'] > 86400
              || ($version !== null && ($verCap['huella'] ?? null) !== null && $verCap['huella'] !== $version)
              || ($c['protocolo'] === 1 && ($estado['protocolo_max'] ?? 1) >= 2);
        if ($pedir) {
            try {
                $c = $this->cap->refrescar($inst, $this->cv, $usuario, true) + ['consultado' => $ahora];
                $this->sync->guardar($id, 'capacidades', $ahora, $version);
                $this->anotar($id, 'capacidades v' . $c['protocolo']);
            } catch (ErrorPrograma $e) {
                $this->anotar($id, 'capacidades: ' . $e->codigo);
                if ($c === null) return;
            }
        }
        if (($c['protocolo'] ?? 1) !== 2) return;
        $d = $c['datos'];

        // 3) solicitudes
        if (Capacidades::puede($d, 'solicitudes')) {
            $s = $this->sync->obtener($id, 'solicitudes');
            $huella = $estado['solicitudes_huella'] ?? null;
            $pedir = in_array('solicitudes', $forzar, true) || $s === null
                  || ($huella !== null && $huella !== $s['huella'])
                  || ($huella === null && ($cron || $ahora - $s['momento'] > 120));
            if ($pedir) {
                try {
                    $r = $this->solicitudes->refrescar($inst, $this->cv, $usuario, $huella, $ahora);
                    $this->anotar($id, "solicitudes {$r['total']} abiertas" . ($r['nuevas'] ? ', ' . count($r['nuevas']) . ' nuevas' : ''));
                } catch (ErrorPrograma $e) {
                    $this->anotar($id, 'solicitudes: ' . $e->codigo);
                }
            }
        }

        // 4) clientes
        $unico = ($d['unico'] ?? false) || Productos::tipo($inst['producto'])['unico'];
        if (!$unico && Capacidades::puede($d, 'clientes')) {
            $s = $this->sync->obtener($id, 'clientes');
            $pedir = in_array('clientes', $forzar, true) || $s === null || ($cron && $ahora - $s['momento'] >= 3600 - 120)
                  || (!$cron && $ahora - $s['momento'] > 3 * 3600)
                  || self::noCoinciden($estado['negocios'] ?? null, $s['datos'] ?? null);
            if ($pedir) {
                try {
                    $r = $this->clientes->refrescar($inst, $this->cv, $d, $usuario, null, $ahora);
                    $this->anotar($id, "clientes {$r['total']}");
                    $this->avisarDemosQuePagaron($this->clientes->vincularDemos($id), $inst);
                } catch (ErrorPrograma $e) {
                    $this->anotar($id, 'clientes: ' . $e->codigo);
                }
            }
        } elseif ($unico && Capacidades::puede($d, 'cliente')) {
            $s = $this->sync->obtener($id, 'clientes');
            if (in_array('clientes', $forzar, true) || $s === null || $ahora - $s['momento'] >= 3600 - 120) {
                try {
                    $this->clientes->refrescarUnico($inst, $this->cv, $usuario, $ahora);
                    $this->anotar($id, 'cliente unico');
                } catch (ErrorPrograma $e) {
                    $this->anotar($id, 'cliente: ' . $e->codigo);
                }
            }
        }

        // 5) finanzas (una vez al día, desde las 03:10; la primera vez enseguida)
        if (Capacidades::puede($d, 'finanzas')) {
            $s = $this->sync->obtener($id, 'finanzas');
            $hora = (int) date('Gi', $ahora);
            $pedir = in_array('finanzas', $forzar, true) || $s === null
                  || ($cron && $hora >= 310 && date('Y-m-d', $s['momento']) !== date('Y-m-d', $ahora))
                  || $ahora - $s['momento'] > 36 * 3600;
            if ($pedir) {
                try {
                    $n = $this->finanzas->refrescar($inst, $this->cv, $d, $usuario, $ahora);
                    $this->anotar($id, "finanzas $n meses");
                } catch (ErrorPrograma $e) {
                    $this->anotar($id, 'finanzas: ' . $e->codigo);
                }
            }
        }
    }

    /** ¿Las cifras de "estado" (v1) no son las mismas que los contadores de la última lista de clientes? */
    public static function noCoinciden(?array $negocios, ?array $contadores): bool
    {
        if (!is_array($negocios) || !is_array($contadores) || $contadores === []) return false;
        $m = ['total' => 'total', 'prueba' => 'prueba', 'activos' => 'activo', 'por_vencer' => 'por_vencer', 'vencidos' => 'vencido', 'suspendidos' => 'suspendido'];
        foreach ($m as $v1 => $v2) {
            if (isset($negocios[$v1], $contadores[$v2]) && (int) $negocios[$v1] !== (int) $contadores[$v2]) return true;
        }
        return false;
    }

    // ------------------------------------------------------------- avisos

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    private function enviar(string $texto): bool
    {
        return $this->telegram !== null && Telegram::configurado() && $this->telegram->enviar($texto);
    }

    private function avisarDemosQuePagaron(array $pagaron, array $inst): void
    {
        foreach ($pagaron as $p) {
            $this->enviar('🎉 ¡La demo de ' . $p['nombre'] . ' pagó!' . ($p['monto'] !== null ? ' ' . self::dinero((float) $p['monto']) : '') . ' (' . $inst['nombre'] . ')');
        }
    }

    /** ¿Ahora es horario de silencio? (22:00 a 07:00 por defecto; cruza la medianoche) */
    public static function enSilencio(string $desde, string $hasta, int $ahora): bool
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $desde) || !preg_match('/^\d{2}:\d{2}$/', $hasta) || $desde === $hasta) return false;
        $h = date('H:i', $ahora);
        return $desde < $hasta ? ($h >= $desde && $h < $hasta) : ($h >= $desde || $h < $hasta);
    }

    public function tiposAviso(): array
    {
        $v = $this->ajustes->valor('avisar_tipos');
        return $v === '' ? self::TIPOS_AVISO_PREDETERMINADOS : array_values(array_filter(explode(',', $v)));
    }

    /** Aviso de las solicitudes nuevas. Más de 5 a la vez: un solo mensaje con el resumen. Devuelve cuántas avisó. */
    public function avisarSolicitudes(string $urlCentral, ?int $ahora = null): int
    {
        $ahora ??= time();
        $tipos = $this->tiposAviso();
        $this->solicitudes->marcarNoAvisables($tipos);
        if (!$this->ajustes->si('avisar_solicitudes') || !Telegram::configurado() || $this->telegram === null) {
            // Sin avisos: no se acumulan para después
            $this->solicitudes->marcarAvisadas($this->solicitudes->porAvisar(array_keys(Solicitudes::TIPOS)));
            return 0;
        }
        if (self::enSilencio($this->ajustes->valor('silencio_desde'), $this->ajustes->valor('silencio_hasta'), $ahora)) return 0;
        $filas = $this->solicitudes->porAvisar($tipos);
        if (!$filas) return 0;
        if (count($filas) > 5) {
            $porTipo = [];
            foreach ($filas as $f) $porTipo[Solicitudes::nombreTipo($f['tipo'])] = ($porTipo[Solicitudes::nombreTipo($f['tipo'])] ?? 0) + 1;
            arsort($porTipo);
            $partes = [];
            foreach ($porTipo as $t => $n) $partes[] = "$n × $t";
            $ok = $this->enviar('📬 ' . count($filas) . ' solicitudes nuevas: ' . implode(', ', $partes) . '. Abrir: ' . rtrim($urlCentral, '/') . '/?r=solicitudes');
        } else {
            $ok = true;
            foreach ($filas as $f) $ok = $this->enviar($this->solicitudes->textoAviso($f, $urlCentral)) && $ok;
        }
        if ($ok) $this->solicitudes->marcarAvisadas($filas);
        return $ok ? count($filas) : 0;
    }

    /** Resumen de cada mañana (07:30), una vez por día. */
    public function resumenDiario(?int $ahora = null): bool
    {
        $ahora ??= time();
        $hoy = date('Y-m-d', $ahora);
        $hora = (int) date('Gi', $ahora);
        if (!$this->ajustes->si('resumen_diario') || $hora < 730 || $hora >= 1200 || $this->ajustes->valor('ultimo_resumen') === $hoy) return false;
        $porAtender = $this->solicitudes->porAtender();
        $pruebas = 0;
        $planes = 0;
        foreach ($this->clientes->todas() as $c) {
            if ($c['vence'] !== $hoy) continue;
            $c['estado'] === 'prueba' ? $pruebas++ : ($c['estado'] !== 'suspendido' ? $planes++ : null);
        }
        $mes = date('Y-m', $ahora);
        $r = $this->finanzas->resumen([$mes], $this->ajustes->si('repartir_gastos_generales'), null, $hoy);
        $nombreMes = explode(' ', Finanzas::nombreMes($mes))[0];
        $txt = '☀️ Buenos días. Hoy: ' . $porAtender . ($porAtender === 1 ? ' solicitud' : ' solicitudes') . ' por atender, '
             . $pruebas . ($pruebas === 1 ? ' prueba termina' : ' pruebas terminan') . ', ' . $planes . ($planes === 1 ? ' plan vence' : ' planes vencen') . '. '
             . "Cobrado en $nombreMes: " . self::dinero($r['total']['meses'][$mes]['ingresos']) . ' · Gastos: ' . self::dinero($r['total']['meses'][$mes]['gastos']) . '.';
        $this->ajustes->guardar('ultimo_resumen', $hoy);
        return $this->enviar($txt);
    }

    /** Gastos e ingresos fijos del mes (una vez por mes, gracias al índice único). */
    public function fijosDelMes(?int $ahora = null): int
    {
        return $this->finanzas->generarFijos(date('Y-m', $ahora ?? time()), $ahora);
    }
}
