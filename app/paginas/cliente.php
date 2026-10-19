<?php
declare(strict_types=1);

use Tukan\{Capacidades, Clientes, ErrorPrograma, Formulario, Licencias, Modulos};

/** Ficha de un cliente (PROTOCOLO_V2 §5.5 y §9.5 D): se pide al programa en vivo; si no contesta, la copia guardada. */
$inst = instancias()->obtener(param('i'));
$id = param('id');
if (!$inst || !Clientes::idValido($id)) {
    aviso('Ese cliente no existe.', 'error');
    redirigir('clientes');
}
$problema = null;
$det = null;
try {
    $r = cliente_v2()->pedir($inst, 'cliente', ['id' => $id], usuario_v2());
    $det = is_array($r['cliente'] ?? null) ? $r['cliente'] : null;
    if (($l = Clientes::limpiar($det)) !== null) {
        if ($l['id'] === 'unico') $l = licencias()->aplicar($inst['id'], $l);
        clientes()->guardar($inst['id'], $l);
    }
} catch (ErrorPrograma $e) {
    $problema = $e;
}
$fila = clientes()->obtener($inst['id'], $id);
if (!$fila && !$det) {
    aviso($problema ? $inst['nombre'] . ': ' . $problema->getMessage() : 'Ese cliente no existe.', 'error');
    redirigir('clientes');
}
$c = $fila ? $fila['datos'] + ['estado' => $fila['estado']] : Clientes::limpiar($det);
$contacto = ve_contacto() ? ($fila ? clientes()->contacto($fila) : ($c['dueno'] ?? null)) : null;
$acciones = $det ? Formulario::limpiarAcciones($det['acciones_posibles'] ?? []) : [];
$pagos = $det && is_array($det['pagos_detalle'] ?? null) ? array_slice($det['pagos_detalle'], 0, 200) : [];
$registro = $det && is_array($det['registro'] ?? null) ? array_slice($det['registro'], 0, 20) : [];
$mensajes = $det && is_array($det['mensajes_sugeridos'] ?? null) ? array_filter($det['mensajes_sugeridos'], fn($v) => is_string($v) && $v !== '') : [];
// Anexo B: pestaña Módulos (solo si el programa tiene módulos y mandó los de este cliente)
$capD = capacidades()->datos($inst['id']);
$mCli = Modulos::limpiarCliente($det['modulos'] ?? null) ?? (is_array($c['modulos'] ?? null) ? $c['modulos'] : null);
$conModulos = Modulos::tiene($capD) && $mCli !== null;
$tab = $conModulos && param('tab') === 'modulos' ? 'modulos' : 'resumen';
$mCat = $tab === 'modulos' ? (new Modulos(db()))->catalogo($inst, cliente_v2(), usuario_v2(), $det !== null) : null;
$st = db()->prepare('SELECT o.*, u.nombre AS quien FROM operaciones o LEFT JOIN usuarios u ON u.id = o.usuario_id WHERE o.instancia_id = ? AND o.cliente_id = ? ORDER BY o.creada DESC LIMIT 10');
$st->execute([$inst['id'], $id]);
vista('cliente', [
    'inst' => $inst, 'c' => $c, 'det' => $det, 'fila' => $fila, 'contacto' => $contacto, 'acciones' => $acciones, 'pagos' => $pagos, 'registro' => $registro,
    'mensajes' => $mensajes, 'problema' => $problema, 'operaciones' => $st->fetchAll(), 'cap' => $capD,
    'conModulos' => $conModulos, 'tab' => $tab, 'mCli' => $mCli, 'mCat' => $mCat,
    'solicitudes' => solicitudes()->deCliente($inst['id'], $id, ve_contacto()), 'historial' => historial()->de($inst['id'], $id), 'recordado' => clientes()->ultimosRecordatorios(60)[$inst['id'] . '|' . $id] ?? null,
], ($c['nombre'] ?? 'Cliente') . ' · Panel Tukán');
