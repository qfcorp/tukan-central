<?php
declare(strict_types=1);

use Tukan\{Clientes, ErrorPrograma, Formulario, Modulos, Operaciones, Solicitudes};

/**
 * Encender y apagar módulos de un cliente (PROTOCOLO_V2 Anexo B, ejecutar cambiar_modulos).
 *   POST paso=revisar  (desde la pestaña Módulos): resumen de lo que se enciende, se apaga y el precio nuevo.
 *   GET  sol=…         (Aprobar un pedido cambio_modulos): el mismo resumen con lo que pidió el cliente.
 *   POST paso=guardar  (Confirmar): manda la operación. En TuGuardería el total pasa a ser el valor de la licencia.
 * Solo el dueño guarda (permiso de cambiar_modulos); el resumen está firmado (no se puede cambiar en el navegador).
 */
$inst = instancias()->obtener(es_post() ? campo('i') : param('i'));
$volverDef = '/?r=clientes';
$falla = function (string $texto, ?string $volver = null) use (&$volverDef): never {
    aviso($texto, 'error');
    header('Location: ' . ($volver ?? $volverDef), true, 303);
    exit;
};
if (!$inst || !(int) $inst['activo']) $falla('Ese programa no existe o está pausado.');
$cap = capacidades()->datos($inst['id']);
if (!Modulos::tiene($cap) || !\Tukan\Capacidades::puede($cap, 'ejecutar', 'cambiar_modulos')) $falla($inst['nombre'] . ' todavía no tiene módulos (actualízalo al Anexo B).');
if (!puede_operacion($inst['id'], 'cambiar_modulos')) {
    auditar('permiso_negado', 'cambiar_modulos', $inst['id']);
    http_response_code(403);
    vista('error', ['mensaje' => 'Solo el dueño del panel puede encender o apagar módulos.'], 'Sin permiso');
    exit;
}
$paso = es_post() ? campo('paso') : 'revisar';
$solId = es_post() ? campo('sol') : param('sol');
$sol = null;
if ($solId !== '') {
    $fs = solicitudes()->obtener($inst['id'], $solId);
    if (!$fs || $fs['tipo'] !== 'cambio_modulos') $falla('Ese pedido de módulos ya no existe. Actualiza la bandeja.', '/?r=solicitudes');
    if (!in_array($fs['estado'], Solicitudes::ABIERTAS, true)) $falla('Ese pedido ya fue atendido.', '/?r=solicitudes');
    $sol = solicitudes()->completa($fs, ve_contacto());
}
$cid = $sol ? (string) ($sol['cliente_id'] ?? '') : (es_post() ? campo('cliente') : param('cliente'));
if (!Clientes::idValido($cid)) $falla('Falta el cliente.');
$volverDef = $sol ? '/?r=solicitudes' : (($cid === 'unico' && param('desde') === 'programa') ? '/?r=programa&id=' . rawurlencode($inst['id']) : '/?' . http_build_query(['r' => 'cliente', 'i' => $inst['id'], 'id' => $cid, 'tab' => 'modulos']));
$volver = volver_valido(es_post() ? campo('volver') : param('volver'), $volverDef);

// ---------------------------------------------------------------- Guardar (confirmado)
if ($paso === 'guardar') {
    $op = campo('op');
    $carga = campo('carga');
    if (!Operaciones::idValida($op) || !hash_equals(firma_form($inst['id'], 'cambiar_modulos', $cid, $carga, $solId), campo('firma'))) {
        auditar('csrf_rechazado', 'cambiar_modulos: resumen alterado', $inst['id']);
        $falla('El resumen no es válido. Vuelve a abrirlo.', $volver);
    }
    $datos = json_decode($carga, true);
    if (!is_array($datos)) $falla('El resumen no es válido.', $volver);
    $motivo = mb_substr(campo('motivo'), 0, 300);
    if ($motivo !== '') $datos['motivo'] = $motivo;
    if ($sol) $datos['solicitud_id'] = $solId;
    $nombre = campo('nombre') ?: null;
    $res = operaciones()->ejecutar($inst, 'cambiar_modulos', $op, $cid, $datos, usuario_v2(), $nombre);
    $corto = substr($op, 0, 8);
    if ($res['ok']) {
        $r = $res['respuesta'];
        auditar('ejecutar', $res['resumen'] . ' → listo' . (($r['repetida'] ?? false) ? ' (ya estaba hecho)' : '') . (($r['sin_cambios'] ?? false) ? ' (sin cambios)' : '') . " (op $corto)", $inst['id']);
        $msg = is_string($r['mensaje'] ?? null) ? $r['mensaje'] : 'Módulos guardados.';
        $m = Modulos::limpiarCliente($r['modulos'] ?? $r['resultado']['modulos'] ?? $r['cliente']['modulos'] ?? null);
        if ($cid === 'unico' && $m !== null) $msg .= ' La licencia queda en ' . dinero($m['mensual']) . ' al mes.';
        aviso(($r['repetida'] ?? false) ? 'Ya estaba hecho: ' . $msg : $msg, 'ok');
        header('Location: ' . $volver, true, 303);
        exit;
    }
    $e = $res['error'];
    auditar('ejecutar', $res['resumen'] . ($res['sin_respuesta'] ? ' → el programa no respondió' : ' → no se hizo: ' . $e->codigo) . " (op $corto)", $inst['id']);
    if ($res['sin_respuesta']) {
        http_response_code(504);
        vista('resultado', ['inst' => $inst, 'tipo' => 'sin_respuesta', 'mensaje' => $e->getMessage(), 'accion' => 'cambiar_modulos', 'volver' => $volver, 'post' => $_POST,
                            'accionUrl' => url('modulos')], 'Sin respuesta · Panel Tukán');
        exit;
    }
    if (in_array($e->codigo, ['conflicto', 'no_encontrado'], true)) {
        try { sincronizador()->programa($inst, null, false, ['solicitudes'], usuario_v2()); } catch (\Throwable) {}
    }
    $falla($inst['nombre'] . ': ' . $e->getMessage(), $volver);
}

// ---------------------------------------------------------------- Resumen (revisar)
try {
    $r = cliente_v2()->pedir($inst, 'cliente', ['id' => $cid], usuario_v2());
} catch (ErrorPrograma $e) {
    $falla($inst['nombre'] . ' no respondió: ' . $e->getMessage() . ' Intenta en un momento.', $volver);
}
$actual = Modulos::limpiarCliente($r['cliente']['modulos'] ?? null);
$nombreCliente = (string) ($r['cliente']['nombre'] ?? $cid);
$cat = (new Modulos(db()))->catalogo($inst, cliente_v2(), usuario_v2());
if (!$actual || !$cat || !$cat['modulos']) $falla($inst['nombre'] . ' no mandó sus módulos. Prueba la conexión del programa.', $volver);

$plan = (string) ($actual['plan']['codigo'] ?? '');
$acordado = $actual['precio_acordado'];
$cambiaAcordado = false;
if ($sol) {
    $ids = fn($l) => array_values(array_filter(array_map(fn($x) => is_array($x) ? ($x['id'] ?? null) : $x, is_array($l) ? $l : []), 'is_string'));
    $enc = $ids($sol['detalle']['encender'] ?? []);
    $apa = $ids($sol['detalle']['apagar'] ?? []);
    $quiero = array_values(array_diff(array_unique(array_merge($actual['activos'], $enc)), $apa));
} else {
    $quiero = array_values(array_filter(is_array($_POST['mod'] ?? null) ? $_POST['mod'] : [], 'is_string'));
    $pp = campo('plan');
    if ($pp !== '' && isset($cat['planes'][$pp]) && ($cap['modulos']['cambia_plan'] ?? true)) $plan = $pp;
    if (array_key_exists('precio_acordado', $_POST) && ($cap['modulos']['precio_acordado'] ?? true)) {
        $t = campo('precio_acordado');
        $n = $t === '' ? null : Formulario::numero($t);
        if ($t !== '' && ($n === null || $n < 0 || $n > 100000)) $falla('El precio acordado no es válido. Escríbelo con números, por ejemplo 45,00 (o déjalo vacío).', $volver);
        $n = $n === null ? null : round($n, 2);
        if ($n !== $acordado) { $acordado = $n; $cambiaAcordado = true; }
    }
}
$sim = Modulos::simular($cat, $actual, $plan, $quiero, $acordado);
$carga = ['encender' => $sim['encender'], 'apagar' => $sim['apagar']];
if ($plan !== (string) ($actual['plan']['codigo'] ?? '')) $carga['plan'] = $plan;
if ($cambiaAcordado) $carga['precio_acordado'] = $acordado;
$cargaJ = json_encode($carga, JSON_UNESCAPED_UNICODE);
vista('modulos_confirmar', [
    'inst' => $inst, 'cid' => $cid, 'nombreCliente' => $nombreCliente, 'cat' => $cat, 'actual' => $actual, 'sim' => $sim, 'plan' => $plan,
    'cambiaPlan' => isset($carga['plan']), 'cambiaAcordado' => $cambiaAcordado, 'acordado' => $acordado, 'sol' => $sol, 'solId' => $sol ? $solId : '',
    'carga' => $cargaJ, 'firma' => firma_form($inst['id'], 'cambiar_modulos', $cid, $cargaJ, $sol ? $solId : ''), 'op' => Operaciones::nuevaId(),
    'volver' => $volver, 'motivo' => es_post() ? mb_substr(campo('motivo'), 0, 300) : '',
], ($sol ? 'Aprobar módulos' : 'Revisar módulos') . ' · Panel Tukán');

