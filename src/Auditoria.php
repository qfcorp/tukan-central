<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/** Registro de todo lo que pasa en el panel (no se puede borrar desde la pantalla). */
final class Auditoria
{
    public const ACCIONES = [
        'login_ok'            => 'Entró al panel',
        'login_fallido'       => 'Contraseña equivocada',
        'login_bloqueado'     => 'Intento bloqueado (demasiados errores)',
        '2fa_fallido'         => 'Código de 2 pasos equivocado',
        '2fa_activado'        => 'Activó la verificación en 2 pasos',
        '2fa_respaldo'        => 'Entró con un código de respaldo',
        '2fa_quitado'         => 'Se quitó la verificación en 2 pasos',
        'codigos_nuevos'      => 'Generó códigos de respaldo nuevos',
        'clave_cambiada'      => 'Cambió su contraseña',
        'salir'               => 'Salió del panel',
        'sesion_vencida'      => 'Sesión cerrada por tiempo',
        'sesiones_cerradas'   => 'Cerró sus otras sesiones',
        'entrar'              => 'Entró a un programa',
        'probar'              => 'Probó la conexión',
        'actualizar'          => 'Actualizó las cifras',
        'instancia_agregada'  => 'Agregó un programa',
        'instancia_editada'   => 'Editó un programa',
        'instancia_eliminada' => 'Eliminó un programa',
        'secreto_cambiado'    => 'Cambió el secreto de un programa',
        'usuario_creado'      => 'Creó un usuario del panel',
        'usuario_editado'     => 'Cambió un usuario del panel',
        'ajustes'             => 'Cambió los ajustes',
        'csrf_rechazado'      => 'Formulario rechazado (página caducada o falsa)',
        'permiso_negado'      => 'Intentó abrir algo sin permiso',
        'cli'                 => 'Cambio desde la consola del servidor',
        // Protocolo v2
        'ejecutar'            => 'Hizo un cambio en un programa',
        'recordatorio'        => 'Recordó a un cliente por WhatsApp',
        'correo_demo'         => 'Envió una demo por correo',
        'gasto'               => 'Gastos',
        'ingreso_extra'       => 'Ingresos extra',
        'licencia'            => 'Licencia de un programa',
        'exportar'            => 'Descargó las finanzas',
        'actualizar_datos'    => 'Trajo solicitudes, clientes o finanzas',
        // Borrar una instalación desde el panel (TuGuardería)
        'baja_programada'     => 'Programó el borrado de una instalación',
        'baja_cancelada'      => 'Canceló el borrado de una instalación',
        'baja_estado'         => 'Borrado de una instalación: cambió el estado',
        'baja_rechazada'      => 'Borrado de una instalación: no se hizo',
        'cloudflare'          => 'Cloudflare (túnel y DNS)',
        'solicitud_atendida'  => 'Solicitud atendida (al borrar el cliente)',
        // Actualizar los programas de este servidor (Más → Actualizaciones)
        'actualizacion_pedida'    => 'Pidió actualizar programas',
        'actualizacion_resultado' => 'Resultado de una actualización',
        'actualizacion_rechazada' => 'Actualización no pedida (código o datos)',
        'actualizacion_revisar'   => 'Buscó versiones nuevas en GitHub',
    ];

    public function __construct(private PDO $db) {}

    public function registrar(string $accion, string $detalle = '', ?string $instancia = null, ?array $usuario = null, ?string $ip = null, ?string $agente = null, ?int $momento = null): void
    {
        $this->db->prepare('INSERT INTO auditoria (momento, usuario_id, email, accion, detalle, instancia_id, ip, agente) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $momento ?? time(), $usuario['id'] ?? null, isset($usuario['email']) ? mb_substr((string) $usuario['email'], 0, 200) : null,
                $accion, mb_substr($detalle, 0, 500), $instancia, $ip !== null ? substr($ip, 0, 45) : null,
                $agente !== null ? mb_substr($agente, 0, 200) : null,
            ]);
    }

    public static function texto(string $accion): string
    {
        return self::ACCIONES[$accion] ?? $accion;
    }

    /**
     * Búsqueda con filtros: accion, usuario (email), instancia, desde/hasta (Y-m-d), q (texto libre).
     * @return array{filas: array, total: int}
     */
    public function buscar(array $f, int $pagina = 1, int $porPagina = 100): array
    {
        $donde = [];
        $p = [];
        if (!empty($f['accion'])) {
            $donde[] = 'accion = ?';
            $p[] = $f['accion'];
        }
        if (!empty($f['grupo'])) {
            $grupos = [
                'acceso'    => ['login_ok', 'login_fallido', 'login_bloqueado', '2fa_fallido', '2fa_activado', '2fa_respaldo', '2fa_quitado', 'salir', 'sesion_vencida', 'sesiones_cerradas', 'codigos_nuevos', 'clave_cambiada'],
                'fallos'    => ['login_fallido', 'login_bloqueado', '2fa_fallido', 'csrf_rechazado', 'permiso_negado'],
                'programas' => ['entrar', 'probar', 'instancia_agregada', 'instancia_editada', 'instancia_eliminada', 'secreto_cambiado', 'ejecutar',
                                'baja_programada', 'baja_cancelada', 'baja_estado', 'baja_rechazada', 'cloudflare',
                                'actualizacion_pedida', 'actualizacion_resultado', 'actualizacion_rechazada', 'actualizacion_revisar'],
                'clientes'  => ['ejecutar', 'recordatorio', 'solicitud_atendida'],
                'plata'     => ['gasto', 'ingreso_extra', 'licencia', 'exportar'],
                'cambios'   => ['instancia_agregada', 'instancia_editada', 'instancia_eliminada', 'secreto_cambiado', 'usuario_creado', 'usuario_editado', 'ajustes', 'clave_cambiada', '2fa_quitado', 'cli',
                                'actualizacion_pedida', 'actualizacion_resultado'],
            ];
            if (isset($grupos[$f['grupo']])) {
                $donde[] = 'accion IN (' . implode(',', array_fill(0, count($grupos[$f['grupo']]), '?')) . ')';
                array_push($p, ...$grupos[$f['grupo']]);
            }
        }
        if (!empty($f['usuario'])) {
            $donde[] = 'email = ?';
            $p[] = $f['usuario'];
        }
        if (!empty($f['instancia'])) {
            $donde[] = 'instancia_id = ?';
            $p[] = $f['instancia'];
        }
        if (!empty($f['desde']) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['desde']))) {
            $donde[] = 'momento >= ?';
            $p[] = $d->getTimestamp();
        }
        if (!empty($f['hasta']) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['hasta']))) {
            $donde[] = 'momento < ?';
            $p[] = $d->modify('+1 day')->getTimestamp();
        }
        if (!empty($f['q'])) {
            $donde[] = "(detalle LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\')";
            $q = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($p, $q, $q, $q);
        }
        $sql = $donde ? ' WHERE ' . implode(' AND ', $donde) : '';
        $st = $this->db->prepare("SELECT count(*) FROM auditoria$sql");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $st = $this->db->prepare("SELECT * FROM auditoria$sql ORDER BY id DESC LIMIT " . (int) $porPagina . ' OFFSET ' . (int) (max(1, $pagina) - 1) * $porPagina);
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }
}
