<?php
declare(strict_types=1);

use Tukan\{Auditoria, Bajas, Cloudflare, Operaciones};

/** Ficha de un programa: estado, cifras, historial y acciones. */
$id = param('id');
$fila = null;
foreach (monitor()->tablero() as $f) {
    if ($f['id'] === $id) $fila = $f;
}
if (!$fila) {
    http_response_code(404);
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
$registro = puede('auditoria') ? (new Auditoria(db()))->buscar(['instancia' => $id], 1, 12)['filas'] : [];
$cap = capacidades()->obtener($id);
$unico = \Tukan\Productos::tipo($fila['producto'])['unico'] || ($cap['datos']['unico'] ?? false);
// Borrar la instalación (solo el dueño): si hay un borrado en marcha, se pregunta al programa cómo va
$baja = null;
$bajaDisp = false;
if (puede('programas')) {
    $bajaDisp = Bajas::disponible($cap && $cap['protocolo'] === 2 ? $cap['datos'] : []);
    $baja = bajas()->obtener($id);
    if ($baja && in_array($baja['estado'], Bajas::ACTIVAS, true) && time() - (int) $baja['revisada'] >= 5) {
        $inst = instancias()->obtener($id);
        if ($inst) $baja = bajas()->revisar($inst, cliente_v2(), usuario_v2());
    }
}
vista('programa', [
    'baja' => $baja, 'bajaDisp' => $bajaDisp, 'urlPublica' => is_string($cap['datos']['url_publica'] ?? null) ? $cap['datos']['url_publica'] : null, 'cfListo' => Cloudflare::configurado(ajustes()), 'opBaja' => Operaciones::nuevaId(),
    'f' => $fila, 'cap' => $cap, 'unico' => $unico, 'licencia' => $unico && puede('finanzas') ? licencias()->obtener($id) : null,
    'disp' => monitor()->disponibilidad($id),
    'registro' => $registro,
], $fila['nombre'] . ' · Panel Tukán');
