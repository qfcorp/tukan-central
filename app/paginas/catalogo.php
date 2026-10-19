<?php
declare(strict_types=1);

use Tukan\{Capacidades, Formulario, Modulos, Operaciones};

/**
 * Catálogo de módulos y planes de un programa (PROTOCOLO_V2 Anexo B, ejecutar guardar_modulos):
 * precio, nombre, descripción y orden de cada módulo, y qué módulos trae cada plan.
 * Todos lo ven; guardar es solo del dueño y pide el código de 2 pasos (cambia lo que se cobra).
 */
$u = usuario();
$inst = instancias()->obtener(es_post() ? campo('i') : param('i'));
if (!$inst) {
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
$cap = capacidades()->datos($inst['id']);
if (!Modulos::tiene($cap)) {
    aviso($inst['nombre'] . ' todavía no tiene módulos (actualízalo al Anexo B).', 'info');
    redirigir('programa', ['id' => $inst['id']]);
}
$puede = puede_operacion($inst['id'], 'guardar_modulos') && ($cap['modulos']['editable'] ?? true);
$m = new Modulos(db());
$cat = $m->catalogo($inst, cliente_v2(), usuario_v2());
if (!$cat) {
    aviso($inst['nombre'] . ' no mandó su catálogo de módulos. Prueba la conexión.', 'error');
    redirigir('programa', ['id' => $inst['id']]);
}
$errores = [];
$valores = [];
if (es_post()) {
    if (!$puede) {
        auditar('permiso_negado', 'guardar_modulos', $inst['id']);
        http_response_code(403);
        vista('error', ['mensaje' => 'Solo el dueño del panel puede cambiar precios y planes.'], 'Sin permiso');
        exit;
    }
    $op = campo('op');
    if (!Operaciones::idValida($op)) $op = Operaciones::nuevaId();
    $pm = is_array($_POST['m'] ?? null) ? $_POST['m'] : [];
    $pp = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
    $mods = [];
    foreach ($cat['modulos'] as $id => $x) {
        $f = is_array($pm[$id] ?? null) ? $pm[$id] : null;
        if ($f === null) continue;
        $cambio = ['id' => $id];
        $nombre = trim((string) ($f['nombre'] ?? ''));
        $desc = trim(preg_replace('/\s+/u', ' ', (string) ($f['descripcion'] ?? '')) ?? '');
        $precio = Formulario::numero((string) ($f['precio'] ?? ''));
        $orden = trim((string) ($f['orden'] ?? ''));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) $errores["m.$id.nombre"] = 'El nombre debe tener de 2 a 80 letras.';
        if (mb_strlen($desc) < 5 || mb_strlen($desc) > 400) $errores["m.$id.descripcion"] = 'La descripción debe tener de 5 a 400 letras.';
        if ($precio === null || $precio < 0 || $precio > 1000) $errores["m.$id.precio"] = 'Escribe el precio con números (de 0 a 1000).';
        if (!preg_match('/^\d{1,4}$/', $orden)) $errores["m.$id.orden"] = 'Número de 0 a 9999.';
        if ($nombre !== $x['nombre']) $cambio['nombre'] = $nombre;
        if ($desc !== $x['descripcion']) $cambio['descripcion'] = $desc;
        if ($precio !== null && round($precio, 2) !== round($x['precio_mensual'], 2)) $cambio['precio_mensual'] = round($precio, 2);
        if (preg_match('/^\d{1,4}$/', $orden) && (int) $orden !== $x['orden']) $cambio['orden'] = (int) $orden;
        if (count($cambio) > 1) $mods[] = $cambio;
    }
    $planes = [];
    foreach (array_merge(array_keys($cat['planes']), ['_nuevo']) as $cod) {
        $f = is_array($pp[$cod] ?? null) ? $pp[$cod] : null;
        if ($f === null) continue;
        $nuevo = $cod === '_nuevo';
        if ($nuevo) {
            $cod = trim((string) ($f['codigo'] ?? ''));
            if ($cod === '' && trim((string) ($f['nombre'] ?? '')) === '') continue;
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $cod)) { $errores['p._nuevo.codigo'] = 'Código en minúsculas y sin espacios (ej. basico).'; continue; }
            if (isset($cat['planes'][$cod])) { $errores['p._nuevo.codigo'] = 'Ya hay un plan con ese código.'; continue; }
        }
        $x = $cat['planes'][$cod] ?? ['nombre' => '', 'precio_mensual' => -1.0, 'todos' => false, 'modulos' => []];
        $k = $nuevo ? '_nuevo' : $cod;
        if (!$nuevo && !empty($f['borrar'])) { $planes[] = ['codigo' => $cod, 'borrar' => true]; continue; }
        $nombre = trim((string) ($f['nombre'] ?? ''));
        $precio = Formulario::numero((string) ($f['precio'] ?? ''));
        $todos = !empty($f['todos']);
        $lista = array_values(array_intersect(array_keys($cat['modulos']), is_array($f['mods'] ?? null) ? $f['mods'] : []));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) $errores["p.$k.nombre"] = 'El nombre debe tener de 2 a 80 letras.';
        if ($precio === null || $precio < 0 || $precio > 10000) $errores["p.$k.precio"] = 'Escribe el precio con números.';
        if (!$todos) {
            foreach ($lista as $id) foreach ($cat['modulos'][$id]['depende_de'] as $d) {
                if (!in_array($d, $lista, true)) $errores["p.$k.mods"] = 'Trae «' . $cat['modulos'][$id]['nombre'] . '» pero no «' . ($cat['modulos'][$d]['nombre'] ?? $d) . '», que lo necesita.';
            }
        }
        $cambio = ['codigo' => $cod];
        if ($nuevo || $nombre !== $x['nombre']) $cambio['nombre'] = $nombre;
        if ($precio !== null && ($nuevo || round($precio, 2) !== round($x['precio_mensual'], 2))) $cambio['precio_mensual'] = round($precio, 2);
        if ($nuevo || $todos !== $x['todos'] || (!$todos && $lista !== $x['modulos'])) $cambio['modulos'] = $todos ? '*' : $lista;
        if (count($cambio) > 1) $planes[] = $cambio;
    }
    if (!acceso()->verificarCodigo($u, campo('codigo'), ip_visitante())) {
        auditar('2fa_fallido', 'Al guardar el catálogo de módulos de ' . $inst['nombre'], $inst['id']);
        $errores['_codigo'] = 'El código de 2 pasos no es correcto. Escribe el que aparece ahora en tu app.';
    }
    if (!$errores && !$mods && !$planes) {
        aviso('No cambiaste nada.', 'info');
        redirigir('catalogo', ['i' => $inst['id']]);
    }
    if (!$errores) {
        $datos = array_filter(['modulos' => $mods, 'planes' => $planes]);
        $res = operaciones()->ejecutar($inst, 'guardar_modulos', $op, null, $datos, usuario_v2(), $inst['nombre']);
        $corto = substr($op, 0, 8);
        if ($res['ok']) {
            $r = $res['respuesta'];
            auditar('ejecutar', $res['resumen'] . ' → listo' . (($r['sin_cambios'] ?? false) ? ' (sin cambios)' : '') . " (op $corto)", $inst['id']);
            aviso(is_string($r['mensaje'] ?? null) ? $r['mensaje'] : 'Catálogo guardado.', 'ok');
            redirigir('catalogo', ['i' => $inst['id']]);
        }
        $e = $res['error'];
        auditar('ejecutar', $res['resumen'] . ($res['sin_respuesta'] ? ' → el programa no respondió' : ' → no se hizo: ' . $e->codigo) . " (op $corto)", $inst['id']);
        $errores['_programa'] = $inst['nombre'] . ': ' . $e->getMessage();
        $op = $res['sin_respuesta'] ? $op : Operaciones::nuevaId();
    }
    http_response_code(422);
    $valores = ['m' => $pm, 'p' => $pp];
}
vista('catalogo', ['inst' => $inst, 'cat' => $cat, 'puede' => $puede, 'errores' => $errores, 'valores' => $valores, 'op' => $op ?? Operaciones::nuevaId()],
      'Catálogo de módulos · ' . $inst['nombre'] . ' · Panel Tukán');
