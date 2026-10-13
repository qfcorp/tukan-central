<?php
declare(strict_types=1);

use Tukan\{ErrorPrograma, Solicitudes};

/** Detalle de una solicitud: se pide al programa en vivo (con la copia guardada si no contesta). */
$instId = param('i');
$id = param('id');
$inst = instancias()->obtener($instId);
if (!$inst || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id)) {
    aviso('Esa solicitud no existe.', 'error');
    redirigir('solicitudes');
}
$problema = null;
try {
    $r = cliente_v2()->pedir($inst, 'solicitud', ['id' => $id], usuario_v2());
    if (($l = Solicitudes::limpiar($r['solicitud'] ?? null)) !== null) solicitudes()->guardarUna($inst['id'], $l, null, false);
} catch (ErrorPrograma $e) {
    $problema = $e;
    if ($e->codigo === 'no_encontrado') {
        db()->prepare("UPDATE solicitudes SET estado = 'cerrada', ultima_vez = ? WHERE instancia_id = ? AND solicitud_id = ? AND estado IN ('pendiente', 'en_proceso')")->execute([time(), $inst['id'], $id]);
    }
}
$fila = solicitudes()->obtener($inst['id'], $id);
if (!$fila) {
    aviso($problema ? $inst['nombre'] . ': ' . $problema->getMessage() : 'Esa solicitud no existe.', 'error');
    redirigir('solicitudes');
}
$st = db()->prepare('SELECT o.*, u.nombre AS quien FROM operaciones o LEFT JOIN usuarios u ON u.id = o.usuario_id WHERE o.instancia_id = ? AND o.solicitud_id = ? ORDER BY o.creada DESC LIMIT 20');
$st->execute([$inst['id'], $id]);
vista('solicitud', [
    's' => solicitudes()->completa($fila, ve_contacto()), 'inst' => $inst, 'problema' => $problema, 'historial' => $st->fetchAll(),
    'cliente' => $fila['cliente_id'] ? clientes()->obtener($inst['id'], $fila['cliente_id']) : null,
], Solicitudes::nombreTipo($fila['tipo']) . ' · Panel Tukán');
