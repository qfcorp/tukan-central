<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * "ejecutar" (PROTOCOLO_V2 §6 y §7): cada botón que cambia algo en un programa.
 * - operacion_id se crea al abrir el formulario (dos toques = la misma operación) y se reusa al reintentar
 *   ("Revisar" cuando el programa no contestó). Si el programa rechaza los datos, el formulario corregido usa una nueva.
 * - Permisos por rol (§9.3). Las claves de un solo uso nunca se guardan ni se anotan.
 */
final class Operaciones
{
    /** Permiso que pide cada operación. Las que no están aquí (nuevas de un programa) son solo del dueño. */
    public const PERMISOS = [
        'atender_solicitud' => 'atender', 'responder_ticket' => 'atender',
        'extender_prueba' => 'demos', 'crear_demo' => 'demos', 'nueva_clave_dueno' => 'demos', 'nota_interna' => 'demos',
        'registrar_pago' => 'cobros', 'aprobar_comprobante' => 'cobros', 'rechazar_comprobante' => 'cobros', 'anular_pago' => 'cobros',
        'suspender' => 'cobros', 'reactivar' => 'cobros', 'cambiar_plan' => 'cobros', 'guardar_plan' => 'cobros', 'cancelar' => 'cobros',
        // Borrar la instalación: solo el dueño, y solo desde la ficha del programa (zona de peligro, con el ID y el código de 2 pasos)
        'dar_de_baja' => 'programas', 'cancelar_baja' => 'programas',
    ];
    /** Se hacen desde la ficha del programa (página "baja"), nunca con el formulario genérico. */
    public const DE_LA_FICHA = ['dar_de_baja', 'cancelar_baja'];
    /** Raras y delicadas: piden otra vez el código de 2 pasos. */
    public const PIDEN_2FA = ['anular_pago', 'guardar_plan'];

    public const NOMBRES = [
        'crear_demo' => 'Crear demo', 'extender_prueba' => 'Alargar prueba', 'registrar_pago' => 'Registrar pago', 'anular_pago' => 'Anular pago',
        'aprobar_comprobante' => 'Aprobar comprobante', 'rechazar_comprobante' => 'Rechazar comprobante', 'suspender' => 'Suspender', 'reactivar' => 'Reactivar',
        'cambiar_plan' => 'Cambiar plan', 'nueva_clave_dueno' => 'Nueva clave del dueño', 'nota_interna' => 'Nota interna',
        'atender_solicitud' => 'Atender solicitud', 'guardar_plan' => 'Guardar plan', 'responder_ticket' => 'Responder ticket', 'cancelar' => 'Cancelar cuenta',
        'dar_de_baja' => 'Borrar la instalación', 'cancelar_baja' => 'Cancelar el borrado',
    ];

    /** Segundos de espera cuando el programa dice que la operación sigue "en curso" (§7.1). */
    public int $espera = 3;

    public function __construct(private PDO $db, private ClienteV2 $cliente, private ?Cifrado $cifrado = null) {}

    public static function permiso(string $accion): string
    {
        return self::PERMISOS[$accion] ?? 'cobros';
    }

    public static function nombre(string $accion): string
    {
        return self::NOMBRES[$accion] ?? ucfirst(str_replace('_', ' ', $accion));
    }

    public static function nuevaId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function idValida(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }

    public function obtener(string $op): ?array
    {
        $st = $this->db->prepare('SELECT * FROM operaciones WHERE operacion_id = ?');
        $st->execute([$op]);
        return $st->fetch() ?: null;
    }

    /** Resumen sin datos personales ni claves (para la auditoría y la tabla operaciones). */
    public static function resumen(string $accion, ?string $nombre, array $datos): string
    {
        $p = [self::nombre($accion)];
        if ($nombre) $p[] = $nombre;
        foreach (['plan', 'periodo', 'meses', 'dias', 'resultado', 'estado', 'pago_id'] as $k) {
            if (isset($datos[$k]) && is_scalar($datos[$k]) && $datos[$k] !== '') $p[] = "$k " . mb_substr((string) $datos[$k], 0, 30);
        }
        if (isset($datos['monto']) && (is_int($datos['monto']) || is_float($datos['monto']))) $p[] = '$' . number_format((float) $datos['monto'], 2, ',', '.');
        return mb_substr(implode(' · ', $p), 0, 300);
    }

    /** Busca las claves de un solo uso en el resultado (para no guardarlas nunca). */
    private static function claves(mixed $r): array
    {
        $c = [];
        if (is_array($r)) {
            foreach ($r as $k => $v) {
                if (in_array($k, ['clave', 'contrasena', 'password'], true) && is_string($v) && $v !== '') $c[] = $v;
                elseif (is_array($v)) array_push($c, ...self::claves($v));
            }
        }
        return $c;
    }

    public static function sinClaves(string $texto, array $resultado): string
    {
        foreach (self::claves($resultado) as $clave) $texto = str_replace($clave, '••••••', $texto);
        return $texto;
    }

    /**
     * Manda la operación al programa.
     * @return array{ok:bool, respuesta:?array, error:?ErrorPrograma, sin_respuesta:bool, resumen:string}
     */
    public function ejecutar(array $inst, string $accion, string $op, ?string $clienteId, array $datos, array $usuario, ?string $nombreCliente = null): array
    {
        if (!self::idValida($op)) throw new \InvalidArgumentException('operacion_id no valido');
        $resumen = self::resumen($accion, $nombreCliente ?? $clienteId, $datos);
        $ahora = time();
        $this->db->prepare('INSERT INTO operaciones (operacion_id, instancia_id, accion, cliente_id, solicitud_id, usuario_id, estado, resumen, creada)
                            VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT (operacion_id) DO UPDATE SET estado = \'enviada\'')
            ->execute([$op, $inst['id'], $accion, $clienteId, is_string($datos['solicitud_id'] ?? null) ? $datos['solicitud_id'] : null,
                       $usuario['id'] ?? null, 'enviada', $resumen, $ahora]);
        $cuerpo = ['operacion_id' => $op, 'accion' => $accion];
        if ($clienteId !== null && $clienteId !== '') $cuerpo['cliente_id'] = $clienteId;
        $cuerpo['datos'] = $datos === [] ? new \stdClass() : $datos;
        // Reintento ("Revisar"): se manda el MISMO cuerpo, byte por byte (el programa compara el hash del cuerpo)
        $cif = $this->cifrado ??= new Cifrado();
        $guardado = $this->obtener($op);
        $json = null;
        if ($guardado && !empty($guardado['cuerpo_cif'])) {
            try {
                $json = $cif->descifrar($guardado['cuerpo_cif'], "operacion:$op");
            } catch (\Throwable) {
                $json = null;
            }
        }
        $json ??= ClienteV2::json($cuerpo);
        $this->db->prepare('UPDATE operaciones SET cuerpo_cif = ? WHERE operacion_id = ?')->execute([$cif->cifrar($json, "operacion:$op"), $op]);
        $intentos = 0;
        while (true) {
            try {
                $r = $this->cliente->pedirCrudo($inst, 'ejecutar', $json, $usuario);
                break;
            } catch (ErrorPrograma $e) {
                if ($e->codigo === 'conflicto' && ($e->detalle['motivo'] ?? '') === 'en_curso' && $intentos < 2) {
                    $intentos++;
                    if ($this->espera > 0) sleep($this->espera);
                    continue;
                }
                $sinRespuesta = $e->sinRespuesta();
                $this->db->prepare('UPDATE operaciones SET estado = ?, http = ?, codigo = ?, mensaje = ?, respondida = ?' . ($sinRespuesta ? '' : ', cuerpo_cif = NULL') . ' WHERE operacion_id = ?')
                    ->execute([$sinRespuesta ? 'sin_respuesta' : 'error', $e->http ?: null, $e->codigo, mb_substr($e->getMessage(), 0, 500), time(), $op]);
                return ['ok' => false, 'respuesta' => null, 'error' => $e, 'sin_respuesta' => $sinRespuesta, 'resumen' => $resumen];
            }
        }
        $resultado = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
        $mensaje = is_string($r['mensaje'] ?? null) ? mb_substr(self::sinClaves($r['mensaje'], $resultado), 0, 500) : 'Listo.';
        $this->db->prepare('UPDATE operaciones SET estado = \'ok\', http = 200, codigo = NULL, mensaje = ?, respondida = ?, cuerpo_cif = NULL WHERE operacion_id = ?')
            ->execute([$mensaje, time(), $op]);
        $this->aplicar($inst, $accion, $clienteId, $datos, $r, $usuario);
        return ['ok' => true, 'respuesta' => $r, 'error' => null, 'sin_respuesta' => false, 'resumen' => $resumen];
    }

    /** Actualiza la copia local con lo que devolvió el programa (sin otro pedido). */
    private function aplicar(array $inst, string $accion, ?string $clienteId, array $datos, array $r, array $usuario): void
    {
        $clientes = new Clientes($this->db, $this->cifrado);
        $sol = new Solicitudes($this->db, $this->cifrado);
        $c = Clientes::limpiar($r['cliente'] ?? null);
        if ($c !== null) {
            if ($c['id'] === 'unico') $c = (new Licencias($this->db, $this->cifrado))->aplicar($inst['id'], $c);
            $clientes->guardar($inst['id'], $c);
            $clienteId ??= $c['id'];
        }
        $s = Solicitudes::limpiar($r['solicitud'] ?? null);
        $solId = is_string($datos['solicitud_id'] ?? null) ? $datos['solicitud_id'] : null;
        if ($s !== null) {
            $sol->guardarUna($inst['id'], $s, null, false);
        } elseif ($solId !== null) {
            $nuevo = match ($accion) {
                'aprobar_comprobante', 'rechazar_comprobante', 'crear_demo' => 'atendida',
                'atender_solicitud' => in_array($datos['resultado'] ?? '', ['en_proceso', 'atendida', 'descartada'], true) ? $datos['resultado'] : null,
                default => null,
            };
            if ($nuevo !== null) {
                $this->db->prepare('UPDATE solicitudes SET estado = ?, ultima_vez = ?, avisada = 1 WHERE instancia_id = ? AND solicitud_id = ?')
                    ->execute([$nuevo, time(), $inst['id'], $solId]);
            }
        }
        if ($accion === 'crear_demo') {
            $res = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
            $cid = is_int($res['cliente_id'] ?? null) ? (string) $res['cliente_id'] : ($res['cliente_id'] ?? $c['id'] ?? null);
            if (Clientes::idValido($cid)) {
                $this->db->prepare('INSERT OR IGNORE INTO demos (instancia_id, cliente_id, nombre, contacto, plan, prueba_hasta, solicitud_id, creada_por, creada)
                                    VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$inst['id'], $cid, mb_substr((string) ($datos['nombre'] ?? $c['nombre'] ?? $cid), 0, 120), mb_substr((string) ($datos['contacto_nombre'] ?? ''), 0, 80),
                               is_scalar($datos['plan'] ?? null) ? (string) $datos['plan'] : null,
                               is_string($res['prueba_hasta'] ?? null) ? substr($res['prueba_hasta'], 0, 10) : null, $solId, $usuario['id'] ?? null, time()]);
                $clienteId ??= $cid;
            }
        }
        // Si el programa no devolvió cómo quedó el cliente, se pide solo ese (sin esperar a la revisión de cada hora)
        if ($c === null && $clienteId !== null && $clienteId !== 'unico') {
            $cap = (new Capacidades($this->db))->datos($inst['id']);
            if (Capacidades::puede($cap, 'clientes')) {
                try {
                    $clientes->refrescar($inst, $this->cliente, $cap, $usuario, [$clienteId]);
                } catch (\Throwable) {
                }
            }
        }
    }
}
