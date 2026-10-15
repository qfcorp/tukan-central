<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Qué puede hacer cada programa desde el panel (acción "capacidades", PROTOCOLO_V2 §5.1).
 * protocolo = 1: el programa todavía es v1 (solo cifras y Entrar). Lo que el programa no declara, no se muestra.
 */
final class Capacidades
{
    public function __construct(private PDO $db) {}

    /** @return array{protocolo:int,datos:array,consultado:int}|null */
    public function obtener(string $inst): ?array
    {
        $st = $this->db->prepare('SELECT protocolo, datos, consultado FROM capacidades WHERE instancia_id = ?');
        $st->execute([$inst]);
        $f = $st->fetch();
        if (!$f) return null;
        return ['protocolo' => (int) $f['protocolo'], 'datos' => json_decode($f['datos'], true) ?: [], 'consultado' => (int) $f['consultado']];
    }

    /** @return array<string, array{protocolo:int,datos:array,consultado:int}> */
    public function todas(): array
    {
        $r = [];
        foreach ($this->db->query('SELECT instancia_id, protocolo, datos, consultado FROM capacidades')->fetchAll() as $f) {
            $r[$f['instancia_id']] = ['protocolo' => (int) $f['protocolo'], 'datos' => json_decode($f['datos'], true) ?: [], 'consultado' => (int) $f['consultado']];
        }
        return $r;
    }

    public function datos(string $inst): array
    {
        $c = $this->obtener($inst);
        return $c && $c['protocolo'] === 2 ? $c['datos'] : [];
    }

    public function esV2(string $inst): bool
    {
        return ($this->obtener($inst)['protocolo'] ?? 1) === 2;
    }

    /** soporta('tuvocalia', 'clientes') · soporta('tuvocalia', 'ejecutar', 'crear_demo') */
    public static function puede(array $datos, string $accion, ?string $operacion = null): bool
    {
        if (!in_array($accion, $datos['acciones'] ?? [], true)) return false;
        return $operacion === null || in_array($operacion, $datos['ejecutar'] ?? [], true);
    }

    public function soporta(string $inst, string $accion, ?string $operacion = null): bool
    {
        return self::puede($this->datos($inst), $accion, $operacion);
    }

    public function guardar(string $inst, int $protocolo, array $datos, ?int $ahora = null): void
    {
        $this->db->prepare('INSERT INTO capacidades (instancia_id, protocolo, datos, consultado) VALUES (?,?,?,?)
                            ON CONFLICT (instancia_id) DO UPDATE SET protocolo = excluded.protocolo, datos = excluded.datos, consultado = excluded.consultado')
            ->execute([$inst, $protocolo, json_encode($datos, JSON_UNESCAPED_UNICODE), $ahora ?? time()]);
    }

    /**
     * Pide "capacidades". Si el programa contesta como v1 (400 sin código, o 404 sin código mientras su "estado"
     * funciona) queda como protocolo 1. Si no se pudo saber (caído), deja lo que había y lanza el error.
     */
    public function refrescar(array $inst, ClienteV2 $cliente, ?array $usuario = null, bool $estadoEnLinea = true): array
    {
        try {
            $r = $cliente->pedir($inst, 'capacidades', [], $usuario);
            $d = self::limpiar($r);
            $this->guardar($inst['id'], 2, $d);
            return ['protocolo' => 2, 'datos' => $d];
        } catch (ErrorPrograma $e) {
            if ($e->esV1() || ($e->codigo === 'no_conectado' && $estadoEnLinea)) {
                $this->guardar($inst['id'], 1, []);
                return ['protocolo' => 1, 'datos' => []];
            }
            throw $e;
        }
    }

    private static function lista(mixed $v, string $patron = '/^[a-z][a-z0-9_]{1,39}$/', int $max = 40): array
    {
        if (!is_array($v) || !array_is_list($v)) return [];
        return array_values(array_unique(array_filter(array_slice($v, 0, $max), fn($x) => is_string($x) && preg_match($patron, $x))));
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Revisa la respuesta de "capacidades" y deja solo lo que se entiende. */
    public static function limpiar(array $d): array
    {
        $estados = [];
        if (is_array($d['estados_detalle'] ?? null)) {
            foreach ($d['estados_detalle'] as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z0-9_]{1,30}$/', $k) && ($t = self::texto($v, 40)) !== null) $estados[$k] = $t;
            }
        }
        $periodos = [];
        foreach (is_array($d['periodos'] ?? null) ? $d['periodos'] : [] as $p) {
            if (is_array($p) && is_string($p['periodo'] ?? null) && preg_match('/^[a-z0-9_]{1,30}$/', $p['periodo']) && is_int($p['meses'] ?? null)) {
                $periodos[] = ['periodo' => $p['periodo'], 'meses' => $p['meses']];
            }
        }
        $metodos = [];
        foreach (is_array($d['metodos_pago'] ?? null) ? $d['metodos_pago'] : [] as $m) {
            if (is_array($m) && is_string($m['metodo'] ?? null) && preg_match('/^[a-z0-9_]{1,30}$/', $m['metodo'])) {
                $metodos[] = ['metodo' => $m['metodo'], 'nombre' => self::texto($m['nombre'] ?? null, 60) ?? $m['metodo']];
            }
        }
        $rutas = [];
        foreach (is_array($d['rutas_sso'] ?? null) ? array_slice($d['rutas_sso'], 0, 10) : [] as $r) {
            if (Formulario::rutaValida($r)) $rutas[] = $r;
        }
        $lim = is_array($d['limites'] ?? null) ? $d['limites'] : [];
        $ent = fn($v, int $def, int $max) => is_int($v) && $v >= 1 ? min($v, $max) : $def;
        $acciones = self::lista($d['acciones'] ?? []);
        return [
            'producto'       => is_string($d['producto'] ?? null) && preg_match('/^[a-z0-9_-]{2,40}$/', $d['producto']) ? $d['producto'] : null,
            'nombre'         => self::texto($d['nombre'] ?? null, 60),
            'version'        => self::texto($d['version'] ?? null, 40),
            'revision'       => is_int($d['revision'] ?? null) ? $d['revision'] : 1,
            'unico'          => ($d['unico'] ?? false) === true,
            'url_publica'    => is_string($d['url_publica'] ?? null) && preg_match('#^https?://#', $d['url_publica']) ? mb_substr($d['url_publica'], 0, 200) : null,
            'acciones'       => $acciones,
            'sso_ir'         => ($d['sso_ir'] ?? false) === true && $rutas !== [],
            'rutas_sso'      => $rutas,
            'ejecutar'       => in_array('ejecutar', $acciones, true) ? self::lista($d['ejecutar'] ?? []) : [],
            'tipos_solicitud' => self::lista($d['tipos_solicitud'] ?? []),
            'estados_detalle' => $estados,
            'periodos'       => $periodos,
            'meses_libres'   => ($d['meses_libres'] ?? false) === true,
            'metodos_pago'   => $metodos,
            'planes_editar'  => ($d['planes_editar'] ?? false) === true,
            'filtros_clientes' => self::lista($d['filtros_clientes'] ?? []),
            'formularios'    => ['crear_demo' => Formulario::limpiarCampos($d['formularios']['crear_demo'] ?? [])],
            'limites'        => [
                'por_pagina_max' => $ent($lim['por_pagina_max'] ?? null, 50, 200),
                'meses_finanzas_max' => $ent($lim['meses_finanzas_max'] ?? null, 12, 24),
                'dias_prueba_max' => $ent($lim['dias_prueba_max'] ?? null, 90, 365),
            ],
        ];
    }

    /** ¿Se puede mandar este "ir" en el SSO de esta instancia? (sso_ir y empieza con una de sus rutas) */
    public static function irPermitido(array $datos, ?string $ir): bool
    {
        if ($ir === null || $ir === '' || !($datos['sso_ir'] ?? false) || !Formulario::rutaValida($ir)) return false;
        foreach ($datos['rutas_sso'] ?? [] as $r) {
            if (str_starts_with($ir, $r)) return true;
        }
        return false;
    }

    /** Lista "Qué puede hacer desde el panel" para la ficha del programa. */
    public static function resumen(array $datos): array
    {
        $p = fn(string $a, ?string $o = null) => self::puede($datos, $a, $o);
        return [
            'Ver las solicitudes (bandeja)'       => $p('solicitudes'),
            'Ver los clientes y sus vencimientos' => $p('clientes') || ($datos['unico'] ?? false) && $p('cliente'),
            'Crear demos'                         => $p('ejecutar', 'crear_demo'),
            'Alargar pruebas'                     => $p('ejecutar', 'extender_prueba'),
            'Registrar pagos'                     => $p('ejecutar', 'registrar_pago'),
            'Aprobar comprobantes'                => $p('ejecutar', 'aprobar_comprobante'),
            'Suspender y reactivar'               => $p('ejecutar', 'suspender'),
            'Pausar clientes'                     => $p('ejecutar', 'pausar'),
            'Borrar clientes (papelera)'          => $p('ejecutar', 'borrar_cliente'),
            'Nueva clave del dueño'               => $p('ejecutar', 'nueva_clave_dueno'),
            'Finanzas (ingresos por mes)'         => $p('finanzas'),
            'Entrar directo a la ficha'           => (bool) ($datos['sso_ir'] ?? false),
        ];
    }
}
