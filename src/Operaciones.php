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
        // Anexo A: pausar lo puede hacer soporte; reactivar (devolver el servicio) y borrar / restaurar solo el dueño
        'pausar' => 'pausar', 'borrar_cliente' => 'borrar_clientes', 'restaurar_cliente' => 'borrar_clientes',
        // Borrar la instalación: solo el dueño, y solo desde la ficha del programa (zona de peligro, con el ID y el código de 2 pasos)
        'dar_de_baja' => 'programas', 'cancelar_baja' => 'programas',
        // Anexo B: encender / apagar módulos y el catálogo de precios: solo el dueño
        'cambiar_modulos' => 'cobros', 'guardar_modulos' => 'cobros',
    ];
    /** Anexo B: se hacen desde sus pantallas (pestaña Módulos y Catálogo), nunca con el formulario genérico. */
    public const DE_MODULOS = ['cambiar_modulos', 'guardar_modulos'];
    /** Se hacen desde la ficha del programa (página "baja"), nunca con el formulario genérico. */
    public const DE_LA_FICHA = ['dar_de_baja', 'cancelar_baja'];
    /** Raras y delicadas: piden otra vez el código de 2 pasos. */
    public const PIDEN_2FA = ['anular_pago', 'guardar_plan', 'borrar_cliente', 'guardar_modulos'];
    /** Estilo que pone el panel aunque el programa mande otro (botón rojo que pide confirmar dos veces). */
    public const ESTILOS = ['pausar' => 'peligro', 'suspender' => 'peligro', 'borrar_cliente' => 'peligro', 'dar_de_baja' => 'peligro', 'anular_pago' => 'peligro'];
    /** Tipos de pausa (Anexo A.1). Con revision y abuso el cliente ve un texto general, no el motivo. */
    public const TIPOS_PAUSA = ['falta_pago' => 'Falta de pago', 'pedido_cliente' => 'A pedido del cliente', 'revision' => 'En revisión', 'abuso' => 'Uso indebido', 'otro' => 'Otro motivo'];
    /** Las que quedan en el historial de la ficha ("quién pausó o borró, cuándo y por qué"). */
    public const DE_HISTORIAL = ['pausar', 'reactivar', 'suspender', 'cancelar', 'borrar_cliente', 'restaurar_cliente'];

    public const NOMBRES = [
        'crear_demo' => 'Crear demo', 'extender_prueba' => 'Alargar demo', 'registrar_pago' => 'Registrar pago', 'anular_pago' => 'Anular pago',
        'aprobar_comprobante' => 'Aprobar comprobante', 'rechazar_comprobante' => 'Rechazar comprobante', 'suspender' => 'Suspender', 'reactivar' => 'Reactivar',
        'cambiar_plan' => 'Cambiar plan', 'nueva_clave_dueno' => 'Nueva clave del dueño', 'nota_interna' => 'Nota interna',
        'atender_solicitud' => 'Atender solicitud', 'guardar_plan' => 'Guardar plan', 'responder_ticket' => 'Responder ticket', 'cancelar' => 'Cancelar cuenta',
        'dar_de_baja' => 'Borrar la instalación', 'cancelar_baja' => 'Cancelar el borrado',
        'pausar' => 'Pausar', 'borrar_cliente' => 'Borrar cliente', 'restaurar_cliente' => 'Restaurar',
        'cambiar_modulos' => 'Cambiar módulos', 'guardar_modulos' => 'Guardar catálogo de módulos',
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

    /**
     * Campos que el panel exige en las operaciones del Anexo A, aunque el programa no los mande:
     * pausar → tipo + motivo; borrar_cliente → confirmar (nombre exacto) + motivo; suspender → motivo.
     * Los campos que el programa sí manda se respetan (etiquetas, ayudas), pero quedan obligatorios.
     */
    public static function completarCampos(array $a, string $nombre = ''): array
    {
        $tiene = fn(string $c) => in_array($c, array_column($a['campos'], 'campo'), true);
        $obligar = function (string $c, array $def) use (&$a, $tiene): void {
            if (!$tiene($c)) {
                $a['campos'][] = $def;
                return;
            }
            foreach ($a['campos'] as &$f) {
                if ($f['campo'] !== $c) continue;
                $f['requerido'] = true;
                if ($def['tipo'] === 'opcion' && $f['tipo'] !== 'opcion') { $f['tipo'] = 'opcion'; $f['opciones'] = $def['opciones']; }
                foreach (['min', 'max'] as $k) if (isset($def[$k]) && $f[$k] === null) $f[$k] = $def[$k];
            }
            unset($f);
        };
        $motivo = ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto_largo', 'requerido' => true, 'valor' => null, 'min' => 3, 'max' => 300,
                   'opciones' => [], 'ayuda' => null, 'cotizar' => false];
        if ($a['accion'] === 'pausar') {
            $obligar('tipo', ['campo' => 'tipo', 'etiqueta' => 'Por qué se pausa', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'falta_pago', 'min' => null, 'max' => null,
                              'opciones' => array_map(fn($k, $v) => ['valor' => $k, 'etiqueta' => $v], array_keys(self::TIPOS_PAUSA), self::TIPOS_PAUSA),
                              'ayuda' => 'Con «En revisión» o «Uso indebido» el cliente ve un texto general, sin el motivo.', 'cotizar' => false]);
            $obligar('motivo', ['ayuda' => 'El cliente lo ve al entrar (salvo «En revisión» y «Uso indebido»).'] + $motivo);
        } elseif ($a['accion'] === 'borrar_cliente') {
            $obligar('confirmar', ['campo' => 'confirmar', 'etiqueta' => 'Escribe el nombre exacto' . ($nombre !== '' ? ': ' . $nombre : ''), 'tipo' => 'texto', 'requerido' => true,
                                   'valor' => null, 'min' => null, 'max' => 120, 'opciones' => [], 'ayuda' => 'Así se evita borrar otro por error.', 'cotizar' => false]);
            $obligar('motivo', ['ayuda' => 'Queda en el historial de la ficha y en el programa.'] + $motivo);
            // El valor nunca viene lleno: hay que escribirlo
            foreach ($a['campos'] as &$f) if ($f['campo'] === 'confirmar') $f['valor'] = null;
            unset($f);
        } elseif ($a['accion'] === 'suspender') {
            $obligar('motivo', ['min' => 5, 'max' => 200] + $motivo);
        }
        return $a;
    }

    /** ¿Lo que escribió es el nombre exacto del cliente (o su id)? Solo se quitan los espacios de los extremos. */
    public static function nombreCoincide(string $escrito, string $nombre, string $id): bool
    {
        $e = trim($escrito);
        return $e !== '' && ($e === trim($nombre) || $e === $id);
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
        if ($accion === 'pausar' && isset(self::TIPOS_PAUSA[$datos['tipo'] ?? ''])) $p[] = self::TIPOS_PAUSA[$datos['tipo']];
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
        if (in_array($accion, self::DE_HISTORIAL, true) && $clienteId !== null && $clienteId !== '' && ($r['sin_cambios'] ?? false) !== true) {
            (new Historial($this->db))->anotar($inst['id'], $clienteId, $accion, $datos, $usuario, $op, array_filter(self::datosBorrado($r), fn($v) => $v !== null));
        }
        return ['ok' => true, 'respuesta' => $r, 'error' => null, 'sin_respuesta' => false, 'resumen' => $resumen];
    }

    /**
     * Lo que devuelve borrar_cliente (Anexo A.1): {copia, borrar_definitivo_el}, arriba o dentro de "resultado".
     * @return array{borrar_definitivo_el:?string, copia:?string}
     */
    public static function datosBorrado(array $r): array
    {
        $res = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
        $fecha = $r['borrar_definitivo_el'] ?? $res['borrar_definitivo_el'] ?? null;
        $copia = $r['copia'] ?? $res['copia'] ?? null;
        return [
            'borrar_definitivo_el' => is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha) ? substr($fecha, 0, 10) : null,
            'copia' => is_string($copia) && $copia !== '' && !preg_match('/[\x00-\x1F\x7F]/', $copia) ? mb_substr($copia, 0, 300) : null,
        ];
    }

    /** Actualiza la copia local con lo que devolvió el programa (sin otro pedido). */
    private function aplicar(array $inst, string $accion, ?string $clienteId, array $datos, array $r, array $usuario): void
    {
        $clientes = new Clientes($this->db, $this->cifrado);
        $sol = new Solicitudes($this->db, $this->cifrado);
        // Anexo B: en los programas de una sola empresa el total mensual de los módulos es el valor de la licencia
        if ($accion === 'cambiar_modulos' && ($clienteId === 'unico' || ($r['cliente']['id'] ?? null) === 'unico')) {
            $m = Modulos::limpiarCliente($r['modulos'] ?? $r['resultado']['modulos'] ?? $r['cliente']['modulos'] ?? null);
            if ($m !== null) (new Licencias($this->db, $this->cifrado))->fijarValor($inst['id'], $m['mensual']);
        }
        if ($accion === 'guardar_modulos' && is_array($r['catalogo'] ?? $r['resultado']['catalogo'] ?? null)) {
            (new Modulos($this->db))->guardarCatalogo($inst['id'], Modulos::limpiarCatalogo($r['catalogo'] ?? $r['resultado']['catalogo']));
        }
        $c = Clientes::limpiar($r['cliente'] ?? null);
        if ($c !== null) {
            if ($c['id'] === 'unico') $c = (new Licencias($this->db, $this->cifrado))->aplicar($inst['id'], $c);
            if ($accion === 'restaurar_cliente') $c['papelera'] = null;
            $clientes->guardar($inst['id'], $c);
            $clienteId ??= $c['id'];
        }
        if ($accion === 'borrar_cliente' && $clienteId !== null) {
            // A la papelera de la copia local, con lo que dijo el programa (y lo que falte, de su respuesta y del formulario)
            $b = self::datosBorrado($r);
            $p = array_filter($c['papelera'] ?? [], fn($v) => $v !== null);
            $clientes->aPapelera($inst['id'], $clienteId, $p + ['desde' => date('Y-m-d'), 'hasta' => $b['borrar_definitivo_el'], 'copia' => $b['copia'],
                'motivo' => is_string($datos['motivo'] ?? null) ? mb_substr($datos['motivo'], 0, 300) : null, 'quien' => $usuario['nombre'] ?? null]);
            return;
        }
        $s = Solicitudes::limpiar($r['solicitud'] ?? null);
        $solId = is_string($datos['solicitud_id'] ?? null) ? $datos['solicitud_id'] : null;
        if ($s !== null) {
            $sol->guardarUna($inst['id'], $s, null, false);
        } elseif ($solId !== null) {
            $nuevo = match ($accion) {
                'aprobar_comprobante', 'rechazar_comprobante', 'crear_demo', 'cambiar_modulos' => 'atendida',
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
