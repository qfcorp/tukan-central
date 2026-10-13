<?php
declare(strict_types=1);

use Tukan\Auditoria;

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
vista('programa', [
    'f' => $fila,
    'disp' => monitor()->disponibilidad($id),
    'registro' => $registro,
], $fila['nombre'] . ' · Panel Tukán');
