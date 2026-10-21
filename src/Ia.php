<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Pantalla «IA» (Más → IA): lo que gasta la inteligencia artificial de cada programa en el mes, con la acción v2 de solo
 * lectura «ia_uso» (docs/PROTOCOLO_V2.md, Anexo C). La última respuesta de cada mes se guarda en sincronizacion
 * (que = 'ia_uso:2026-10') para mostrarla si el programa no contesta. Programas sin «ia_uso»: «aún no reporta IA».
 * El panel NO puede pausar la IA (no escribe en /etc): muestra el comando de deploy/configurar_ia.sh.
 */
final class Ia
{
    public function __construct(private PDO $db) {}

    public static function tiene(array $cap): bool
    {
        return Capacidades::puede($cap, 'ia_uso');
    }

    public static function mesValido(string $mes): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes);
    }

    private static function num(mixed $v): float
    {
        return is_int($v) || is_float($v) ? max(0.0, (float) $v) : 0.0;
    }

    private static function ent(mixed $v): int
    {
        return is_int($v) ? max(0, $v) : (is_float($v) ? max(0, (int) $v) : 0);
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_int($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Revisa la respuesta de «ia_uso» (lo que no viene o viene mal queda en 0 / null). */
    public static function limpiar(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        $t = is_array($d['totales'] ?? null) ? $d['totales'] : [];
        $tot = ['costo' => round(self::num($t['costo'] ?? 0), 6)];
        foreach (['llamadas', 'fallidas', 'ahorradas', 'ayudas_nuevas', 'tokens_entrada', 'tokens_salida'] as $k) $tot[$k] = self::ent($t[$k] ?? 0);
        $neg = [];
        foreach (is_array($d['negocios'] ?? null) ? array_slice($d['negocios'], 0, 500) : [] as $n) {
            if (!is_array($n) || ($id = self::texto($n['cliente_id'] ?? null, 64)) === null) continue;
            $neg[] = ['cliente_id' => $id, 'nombre' => self::texto($n['nombre'] ?? null, 120) ?? $id, 'costo' => round(self::num($n['costo'] ?? 0), 6),
                      'llamadas' => self::ent($n['llamadas'] ?? 0), 'fallidas' => self::ent($n['fallidas'] ?? 0), 'ahorradas' => self::ent($n['ahorradas'] ?? 0),
                      'ayudas_nuevas' => self::ent($n['ayudas_nuevas'] ?? 0),
                      'tope' => is_int($n['tope'] ?? null) || is_float($n['tope'] ?? null) ? round((float) $n['tope'], 4) : null,
                      'modulo' => ($n['modulo'] ?? false) === true];
        }
        usort($neg, fn($a, $b) => $b['costo'] <=> $a['costo'] ?: $b['llamadas'] <=> $a['llamadas']);
        $f = is_array($d['faq'] ?? null) ? $d['faq'] : null;
        return [
            'mes' => is_string($d['mes'] ?? null) && self::mesValido($d['mes']) ? $d['mes'] : null,
            'configurada' => is_bool($d['configurada'] ?? null) ? $d['configurada'] : null,
            'pausada' => ($d['pausada'] ?? false) === true,
            'modelo_rapido' => self::texto($d['modelo_rapido'] ?? null, 60),
            'modelo_fuerte' => self::texto($d['modelo_fuerte'] ?? null, 60),
            'tope_programa' => is_int($d['tope_programa'] ?? null) || is_float($d['tope_programa'] ?? null) ? round((float) $d['tope_programa'], 2) : null,
            'totales' => $tot,
            'faq' => $f === null ? null : ['total' => self::ent($f['total'] ?? 0), 'automaticas' => self::ent($f['automaticas'] ?? 0),
                                           'manuales' => self::ent($f['manuales'] ?? 0), 'sin_aprobar' => self::ent($f['sin_aprobar'] ?? 0)],
            'negocios' => $neg,
            'version_libreria' => self::texto($d['version_libreria'] ?? null, 20),
        ];
    }

    /**
     * Uso de IA de un programa en un mes: en vivo (y se guarda la copia) o, si no contesta, la última copia.
     * Devuelve ['datos' => …|null, 'de_copia' => momento|null, 'error' => texto|null].
     */
    public function uso(array $inst, ClienteV2 $cv, string $mes, ?array $usuario = null, bool $enVivo = true): array
    {
        $sync = new Sincronizacion($this->db);
        $que = "ia_uso:$mes";
        $error = null;
        if ($enVivo) {
            try {
                $r = self::limpiar($cv->pedir($inst, 'ia_uso', ['mes' => $mes], $usuario));
                $sync->guardar($inst['id'], $que, time(), null, $r);
                return ['datos' => $r, 'de_copia' => null, 'error' => null];
            } catch (ErrorPrograma $e) {
                $error = $e->getMessage();
            }
        }
        $s = $sync->obtener($inst['id'], $que);
        return ['datos' => $s && is_array($s['datos']) ? self::limpiar($s['datos']) : null, 'de_copia' => $s['momento'] ?? null, 'error' => $error];
    }

    /** Suma de todos los programas que reportan. */
    public static function totales(array $filas): array
    {
        $t = ['costo' => 0.0, 'llamadas' => 0, 'ahorradas' => 0, 'fallidas' => 0, 'programas' => 0, 'pausados' => 0];
        foreach ($filas as $f) {
            if (empty($f['uso']['datos'])) continue;
            $d = $f['uso']['datos'];
            $t['programas']++;
            if ($d['pausada']) $t['pausados']++;
            $t['costo'] += $d['totales']['costo'];
            foreach (['llamadas', 'ahorradas', 'fallidas'] as $k) $t[$k] += $d['totales'][$k];
        }
        $t['costo'] = round($t['costo'], 6);
        $t['pct_ahorro'] = $t['llamadas'] + $t['ahorradas'] > 0 ? (int) round($t['ahorradas'] / ($t['llamadas'] + $t['ahorradas']) * 100) : null;
        return $t;
    }

    /** "$0,0042" para centavos de IA; "$12,30" desde 1 dólar. */
    public static function dinero(float $v): string
    {
        if ($v === 0.0) return '$0,00';
        if ($v < 0.01) return '$' . number_format($v, 4, ',', '.');
        if ($v < 1) return '$' . number_format($v, 3, ',', '.');
        return '$' . number_format($v, 2, ',', '.');
    }

    /**
     * Estado de /etc/tukan-ia.env leído por este mismo servidor (el panel corre en qfcorp como www-data, que puede leerlo).
     * Sin la clave. null si la librería no está.
     */
    public static function estadoServidor(): ?array
    {
        $lib = dirname(__DIR__) . '/compartido/tukan_ia.php';
        if (!is_file($lib)) return null;
        require_once $lib;
        return \tukan_ia_estado();
    }
}
