<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Programas modulares (PROTOCOLO_V2 Anexo B): cada programa tiene un catálogo de módulos con precio, planes
 * (precio + módulos incluidos) y, por cliente, los módulos encendidos. El panel solo pide y muestra:
 * - acción "modulos" (catálogo; se guarda la última copia en sincronizacion, que = 'modulos')
 * - "cliente".modulos (lo de cada cliente) y ejecutar cambiar_modulos / guardar_modulos.
 * Programas sin Anexo B no tienen nada de esto y siguen igual.
 *
 * Regla del precio (B.1): plan + Σ módulos extra − Σ módulos del plan que se quitaron (mínimo 0);
 * con precio acordado vale ese. El panel la usa solo para la VISTA PREVIA; lo que cuenta es lo que responde el programa.
 */
final class Modulos
{
    public function __construct(private PDO $db) {}

    /** ¿El programa tiene módulos (Anexo B)? */
    public static function tiene(array $cap): bool
    {
        return Capacidades::puede($cap, 'modulos');
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function dinero(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? round((float) $v, 2) : null;
    }

    private static function ids(mixed $v, ?array $conocidos = null): array
    {
        if (!is_array($v) || !array_is_list($v)) return [];
        $r = [];
        foreach (array_slice($v, 0, 100) as $x) {
            if (is_string($x) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $x) && ($conocidos === null || in_array($x, $conocidos, true))) $r[] = $x;
        }
        return array_values(array_unique($r));
    }

    /** Revisa la respuesta de "modulos" (B.2). */
    public static function limpiarCatalogo(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        $mods = [];
        foreach (is_array($d['modulos'] ?? null) ? array_slice($d['modulos'], 0, 100) : [] as $m) {
            if (!is_array($m) || !is_string($m['id'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $m['id'])) continue;
            $mods[$m['id']] = [
                'id' => $m['id'],
                'nombre' => self::texto($m['nombre'] ?? null, 80) ?? $m['id'],
                'icono' => self::texto($m['icono'] ?? null, 40),
                'descripcion' => self::texto($m['descripcion'] ?? null, 400) ?? '',
                'precio_mensual' => self::dinero($m['precio_mensual'] ?? null) ?? 0.0,
                'depende_de' => self::ids($m['depende_de'] ?? []),
                'grupo' => self::texto($m['grupo'] ?? null, 60) ?? 'Módulos',
                'orden' => is_int($m['orden'] ?? null) ? $m['orden'] : 0,
            ];
        }
        $conocidos = array_keys($mods);
        foreach ($mods as &$m) $m['depende_de'] = array_values(array_intersect($m['depende_de'], $conocidos));
        unset($m);
        $planes = [];
        foreach (is_array($d['planes'] ?? null) ? array_slice($d['planes'], 0, 30) : [] as $p) {
            $cod = is_int($p['codigo'] ?? null) ? (string) $p['codigo'] : ($p['codigo'] ?? null);
            if (!is_array($p) || !is_string($cod) || !preg_match('/^[A-Za-z0-9_.:-]{1,40}$/', $cod)) continue;
            $todos = ($p['todos'] ?? false) === true || ($p['modulos'] ?? null) === '*' || ($p['modulos'] ?? null) === ['*'];
            $planes[$cod] = ['codigo' => $cod, 'nombre' => self::texto($p['nombre'] ?? null, 80) ?? $cod, 'precio_mensual' => self::dinero($p['precio_mensual'] ?? null) ?? 0.0,
                             'todos' => $todos, 'modulos' => $todos ? $conocidos : self::ids($p['modulos'] ?? [], $conocidos)];
        }
        $base = [];
        foreach (is_array($d['base'] ?? null) ? array_slice($d['base'], 0, 30) : [] as $b) if (($t = self::texto($b, 200)) !== null) $base[] = $t;
        return ['base' => $base, 'modulos' => $mods, 'planes' => $planes, 'editable' => ($d['editable'] ?? true) !== false];
    }

    /** Revisa el objeto "modulos" de un cliente (B.2). null si no viene. */
    public static function limpiarCliente(mixed $m): ?array
    {
        if (!is_array($m) || !is_array($m['activos'] ?? null)) return null;
        $plan = $m['plan'] ?? null;
        $plan = is_array($plan) ? ['codigo' => self::texto(is_int($plan['codigo'] ?? null) ? (string) $plan['codigo'] : ($plan['codigo'] ?? null), 40), 'nombre' => self::texto($plan['nombre'] ?? null, 80)]
              : (is_string($plan) ? ['codigo' => mb_substr($plan, 0, 40), 'nombre' => null] : null);
        $mensual = self::dinero($m['mensual'] ?? null);
        return [
            'plan' => $plan,
            'del_plan' => self::ids($m['del_plan'] ?? []),
            'extra' => self::ids($m['extra'] ?? []),
            'quitados' => self::ids($m['quitados'] ?? []),
            'activos' => self::ids($m['activos'] ?? []),
            'precio_plan' => self::dinero($m['precio_plan'] ?? null) ?? 0.0,
            'precio_extra' => self::dinero($m['precio_extra'] ?? null) ?? 0.0,
            'descuento' => self::dinero($m['descuento'] ?? null) ?? 0.0,
            'precio_acordado' => self::dinero($m['precio_acordado'] ?? null),
            'mensual' => $mensual ?? 0.0,
        ];
    }

    /** Catálogo de un programa: en vivo (y se guarda) o la última copia si no contesta. */
    public function catalogo(array $inst, ClienteV2 $cv, ?array $usuario = null, bool $enVivo = true): ?array
    {
        $sync = new Sincronizacion($this->db);
        if ($enVivo) {
            try {
                $r = $cv->pedir($inst, 'modulos', [], $usuario);
                $c = self::limpiarCatalogo($r);
                $sync->guardar($inst['id'], 'modulos', time(), null, $c);
                return $c + ['de_copia' => null];
            } catch (ErrorPrograma) {
            }
        }
        $s = $sync->obtener($inst['id'], 'modulos');
        return $s && is_array($s['datos']) ? self::limpiarCatalogo($s['datos']) + ['de_copia' => $s['momento']] : null;
    }

    public function guardarCatalogo(string $inst, array $catalogo): void
    {
        (new Sincronizacion($this->db))->guardar($inst, 'modulos', time(), null, self::limpiarCatalogo(['base' => $catalogo['base'] ?? [],
            'modulos' => array_values($catalogo['modulos'] ?? []), 'planes' => array_values($catalogo['planes'] ?? [])]));
    }

    public function copiaCatalogo(string $inst): ?array
    {
        $s = (new Sincronizacion($this->db))->obtener($inst, 'modulos');
        return $s && is_array($s['datos']) ? self::limpiarCatalogo($s['datos']) : null;
    }

    /**
     * Lo que pasaría con una combinación (vista previa): plan y módulos que deben quedar encendidos.
     * Enciende las dependencias de lo que se enciende; si apaga algo que otro encendido necesita, lo dice en "problemas".
     * @return array{plan:string,activos:array,encender:array,apagar:array,por_dependencia:array,extra:array,quitados:array,
     *               precio_plan:float,precio_extra:float,descuento:float,calculado:float,mensual:float,problemas:array}
     */
    public static function simular(array $cat, array $actual, string $plan, array $quiero, ?float $acordado): array
    {
        $mods = $cat['modulos'];
        $quiero = array_values(array_intersect(array_keys($mods), $quiero));
        $antes = $actual['activos'] ?? [];
        $porDep = [];
        $cola = array_values(array_diff($quiero, $antes));
        while ($cola) {
            $id = array_shift($cola);
            foreach ($mods[$id]['depende_de'] ?? [] as $d) {
                if (!in_array($d, $quiero, true)) { $quiero[] = $d; $cola[] = $d; $porDep[] = $d; }
            }
        }
        $problemas = [];
        foreach ($quiero as $id) {
            foreach ($mods[$id]['depende_de'] ?? [] as $d) {
                if (!in_array($d, $quiero, true)) $problemas[] = '«' . $mods[$id]['nombre'] . '» necesita «' . ($mods[$d]['nombre'] ?? $d) . '».';
            }
        }
        $quiero = array_values(array_intersect(array_keys($mods), $quiero));
        $del = $cat['planes'][$plan]['modulos'] ?? [];
        $extra = array_values(array_diff($quiero, $del));
        $quitados = array_values(array_diff($del, $quiero));
        $pp = (float) ($cat['planes'][$plan]['precio_mensual'] ?? 0);
        $pe = round(array_sum(array_map(fn($x) => $mods[$x]['precio_mensual'], $extra)), 2);
        $de = round(array_sum(array_map(fn($x) => $mods[$x]['precio_mensual'], $quitados)), 2);
        $calc = round(max(0, $pp + $pe - $de), 2);
        return ['plan' => $plan, 'activos' => $quiero, 'encender' => array_values(array_diff($quiero, $antes)), 'apagar' => array_values(array_diff($antes, $quiero)),
                'por_dependencia' => array_values(array_unique($porDep)), 'extra' => $extra, 'quitados' => $quitados,
                'precio_plan' => $pp, 'precio_extra' => $pe, 'descuento' => $de, 'calculado' => $calc, 'mensual' => $acordado ?? $calc, 'problemas' => array_values(array_unique($problemas))];
    }

    public static function nombres(array $cat, array $ids): string
    {
        return implode(', ', array_map(fn($x) => $cat['modulos'][$x]['nombre'] ?? $x, $ids));
    }

    /** Módulos agrupados para pantalla: grupo => lista (en orden). */
    public static function agrupados(array $cat): array
    {
        $m = array_values($cat['modulos']);
        usort($m, fn($a, $b) => [$a['orden'], $a['nombre']] <=> [$b['orden'], $b['nombre']]);
        $g = [];
        foreach ($m as $x) $g[$x['grupo']][] = $x;
        return $g;
    }

    /**
     * Ingreso estimado mensual por módulo EXTRA en todos los programas (Finanzas, B.4):
     * para cada cliente con módulos, suma el precio del catálogo de cada módulo extra encendido.
     * @return list<array{instancia_id:string,programa:string,modulo:string,nombre:string,clientes:int,monto:float}>
     */
    public function ingresoPorModulo(Clientes $clientes): array
    {
        $cats = [];
        $r = [];
        foreach ($clientes->todas() as $f) {
            $m = $f['datos']['modulos'] ?? null;
            if (!is_array($m) || !($m['extra'] ?? []) || $f['estado'] === 'suspendido') continue;
            $inst = $f['instancia_id'];
            $cats[$inst] ??= $this->copiaCatalogo($inst) ?? ['modulos' => []];
            foreach ($m['extra'] as $id) {
                if (!in_array($id, $m['activos'] ?? [], true)) continue;
                $k = "$inst|$id";
                $r[$k] ??= ['instancia_id' => $inst, 'programa' => (string) $f['programa'], 'modulo' => $id,
                            'nombre' => $cats[$inst]['modulos'][$id]['nombre'] ?? $id, 'clientes' => 0, 'monto' => 0.0];
                $r[$k]['clientes']++;
                $r[$k]['monto'] = round($r[$k]['monto'] + (float) ($cats[$inst]['modulos'][$id]['precio_mensual'] ?? 0), 2);
            }
        }
        $r = array_values($r);
        usort($r, fn($a, $b) => [$b['monto'], $a['nombre']] <=> [$a['monto'], $b['nombre']]);
        return $r;
    }
}
