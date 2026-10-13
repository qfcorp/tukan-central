<?php
declare(strict_types=1);

/** Editar un programa (nombre, dirección, pausar, notas, comando). El ID no se cambia. */
$inst = instancias()->obtener(param('id'));
if (!$inst) {
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
$error = null;
$d = [
    'producto' => $inst['producto'], 'id' => $inst['id'], 'nombre' => $inst['nombre'], 'url' => $inst['url'],
    'carpeta' => (string) $inst['carpeta'], 'notas' => $inst['notas'], 'plantilla' => (string) $inst['plantilla'], 'activo' => (string) $inst['activo'],
];
if (es_post()) {
    $d = array_merge($d, array_map(fn($v) => is_string($v) ? trim($v) : '', array_intersect_key($_POST, $d)));
    $d['id'] = $inst['id'];
    $d['activo'] = isset($_POST['activo']) ? '1' : '';
    try {
        $cambios = instancias()->editar($inst['id'], $d);
        $nombres = ['nombre' => 'nombre', 'producto' => 'tipo', 'url' => 'dirección', 'plantilla' => 'comando', 'carpeta' => 'carpeta', 'notas' => 'notas', 'activo' => 'activo'];
        if ($cambios) {
            $txt = 'Cambió: ' . implode(', ', array_map(fn($c) => $nombres[$c] ?? $c, $cambios));
            if (in_array('url', $cambios, true)) $txt .= ' (antes ' . $inst['url'] . ')';
            if (in_array('activo', $cambios, true)) $txt .= $d['activo'] ? ' · ahora activo' : ' · ahora pausado';
            auditar('instancia_editada', $txt, $inst['id']);
            aviso('Cambios guardados.');
        } else {
            aviso('No había nada que cambiar.', 'info');
        }
        redirigir('programa', ['id' => $inst['id']]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
vista('programa_form', ['d' => $d, 'error' => $error, 'nuevo' => false, 'inst' => $inst], 'Editar ' . $inst['nombre'] . ' · Panel Tukán');
