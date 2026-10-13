<?php
declare(strict_types=1);

use Tukan\{Formulario, Operaciones, Solicitudes};

/**
 * Manda una operación al programa (PROTOCOLO_V2 §6) desde un formulario de acciones_posibles.
 * - Permisos por rol (§9.3) y código de 2 pasos para anular_pago / guardar_plan.
 * - Si el programa no contesta: pantalla "Revisar" que reenvía la MISMA operación (no se duplica).
 * - Si el programa rechaza los datos: el formulario vuelve con el error junto al campo y una operación nueva.
 * - Las claves de un solo uso se muestran aquí mismo (sin redirigir) y no se guardan en ningún lado.
 */
$u = usuario();
$inst = instancias()->obtener(campo('i'));
$accion = campo('accion');
$volver = volver_valido(campo('volver'), '/?r=solicitudes');
$irA = function () use ($volver): never {
    header('Location: ' . $volver, true, 303);
    exit;
};
if (!$inst || !(int) $inst['activo']) {
    aviso('Ese programa no existe o está pausado.', 'error');
    $irA();
}
if ($accion === 'crear_demo') {
    aviso('Las demos se crean desde la pantalla Demos.', 'info');
    redirigir('demo_nueva', ['i' => $inst['id']]);
}
$op = campo('op');
$cid = campo('cliente_id');
$fijosJ = campo('fijos', '{}');
$defJ = campo('def', '[]');
$metaJ = campo('meta', '');
$firmaOk = hash_equals(firma_form($inst['id'], $accion, $cid, $fijosJ, $defJ, ...($metaJ !== '' ? [$metaJ] : [])), campo('firma'));
if (!$firmaOk || !Operaciones::idValida($op)) {
    auditar('csrf_rechazado', "ejecutar $accion: formulario alterado", $inst['id']);
    aviso('El formulario no es válido. Vuelve a abrirlo e intenta otra vez.', 'error');
    $irA();
}
if (!puede_operacion($inst['id'], $accion)) {
    auditar('permiso_negado', "ejecutar $accion", $inst['id']);
    http_response_code(403);
    vista('error', ['mensaje' => 'Tu usuario no tiene permiso para esto. Pídeselo al dueño del panel.'], 'Sin permiso');
    exit;
}
$fijos = json_decode($fijosJ, true);
$fijos = is_array($fijos) ? $fijos : [];
$campos = Formulario::limpiarCampos(json_decode($defJ, true));
$meta = json_decode($metaJ, true) ?: [];
$a = Formulario::limpiarAccion(['accion' => $accion, 'etiqueta' => $meta['etiqueta'] ?? Operaciones::nombre($accion), 'estilo' => $meta['estilo'] ?? 'normal',
                                'confirmar' => $meta['confirmar'] ?? null, 'datos' => $fijos, 'campos' => json_decode($defJ, true) ?: []]);
$ctx = ['volver' => $volver, 'cliente_id' => $cid ?: null, 'nombre' => mb_substr(campo('nombre'), 0, 120)];
[$valores, $errores] = Formulario::leer($campos, campos_post());

// Formulario otra vez (con el error junto al campo)
$deNuevo = function (array $errores, ?string $error, string $op, int $http = 422) use ($inst, $a, $ctx): never {
    http_response_code($http);
    vista('accion', ['inst' => $inst, 'a' => $a, 'ctx' => $ctx, 'valores' => campos_post(), 'errores' => $errores, 'error' => $error, 'op' => $op], $a['etiqueta'] . ' · Panel Tukán');
    exit;
};
if ($a['estilo'] === 'peligro' && campo('seguro') !== '1') $errores['_seguro'] = 'Marca "Sí, estoy seguro" para continuar.';
if (in_array($accion, Operaciones::PIDEN_2FA, true)) {
    if (!acceso()->verificarCodigo($u, campo('codigo'), ip_visitante())) {
        auditar('2fa_fallido', "Al hacer $accion en " . $inst['nombre'], $inst['id']);
        $errores['_codigo'] = 'El código de 2 pasos no es correcto. Escribe el que aparece ahora en tu app.';
    }
}
if ($errores) $deNuevo($errores, $errores['_seguro'] ?? 'Revisa los campos marcados.', $op);

$datos = $fijos + $valores;
$res = operaciones()->ejecutar($inst, $accion, $op, $cid !== '' ? $cid : null, $datos, usuario_v2(), $ctx['nombre'] ?: null);
$corto = substr($op, 0, 8);
if ($res['ok']) {
    $r = $res['respuesta'];
    $resultado = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
    $mensaje = Operaciones::sinClaves(is_string($r['mensaje'] ?? null) ? $r['mensaje'] : 'Listo.', $resultado);
    auditar('ejecutar', $res['resumen'] . ' → listo' . (($r['repetida'] ?? false) ? ' (ya estaba hecho)' : '') . ($r['sin_cambios'] ?? false ? ' (sin cambios)' : '') . " (op $corto)", $inst['id']);
    if (array_key_exists('clave', $resultado) || isset($resultado['acceso'])) {
        vista('resultado', ['inst' => $inst, 'tipo' => 'clave', 'mensaje' => $mensaje, 'resultado' => $resultado, 'respuesta' => $r, 'volver' => $volver], 'Listo · Panel Tukán');
        exit;
    }
    aviso(($r['repetida'] ?? false) ? 'Ya estaba hecho: ' . $mensaje : $mensaje, 'ok');
    // Desde una solicitud que ya quedó atendida, se vuelve a la bandeja
    $irA();
}
$e = $res['error'];
auditar('ejecutar', $res['resumen'] . ($res['sin_respuesta'] ? ' → el programa no respondió' : ' → no se hizo: ' . $e->codigo) . " (op $corto)", $inst['id']);
if ($res['sin_respuesta']) {
    http_response_code(504);
    vista('resultado', ['inst' => $inst, 'tipo' => 'sin_respuesta', 'mensaje' => $e->getMessage(), 'accion' => $accion, 'volver' => $volver, 'post' => $_POST, 'nombre' => $ctx['nombre']],
          'Sin respuesta · Panel Tukán');
    exit;
}
if ($e->codigo === 'invalido' && $e->campo !== null) {
    $campo = $e->campoFormulario();
    $deNuevo(in_array($campo, array_column($campos, 'campo'), true) ? [$campo => $e->getMessage()] : [], $e->getMessage(), Operaciones::nuevaId());
}
if (in_array($e->codigo, ['invalido', 'no_permitido'], true)) $deNuevo([], $e->getMessage(), Operaciones::nuevaId(), $e->http ?: 422);
// conflicto / no_encontrado: ya lo atendió otro o ya no existe → se recarga la solicitud o el cliente
if (in_array($e->codigo, ['conflicto', 'no_encontrado'], true)) {
    try {
        sincronizador()->programa($inst, null, false, ['solicitudes'], usuario_v2());
    } catch (\Throwable) {
    }
}
aviso($inst['nombre'] . ': ' . $e->getMessage(), 'error');
$irA();
