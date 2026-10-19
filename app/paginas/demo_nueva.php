<?php
declare(strict_types=1);

use Tukan\{Capacidades, ErrorPrograma, Formulario, Operaciones};

/**
 * Crear demo en cualquier programa (PROTOCOLO_V2 §6.1, §6.3 y §9.5 F):
 *   1) elegir el programa (solo los que pueden crear demos); 2) llenar los datos comunes + los propios del programa;
 *   3) resultado con los accesos (la clave se ve solo ahí), WhatsApp, Copiar y Entrar a la demo.
 * Desde un "Pedido de demo" el formulario viene lleno.
 */
$pueden = array_values(array_filter(instancias()->lista(true), fn($i) => Capacidades::puede(capacidades()->datos($i['id']), 'ejecutar', 'crear_demo')));
$inst = (es_post() ? instancias()->obtener(campo('i')) : null) ?? (param('i') !== '' ? instancias()->obtener(param('i')) : null);
if (!$inst || !in_array($inst['id'], array_column($pueden, 'id'), true)) {
    if ($inst || es_post()) aviso('Ese programa no puede crear demos desde el panel (actualízalo al protocolo v2).', 'error');
    vista('demo_nueva', ['paso' => 1, 'pueden' => $pueden], 'Crear demo · Panel Tukán');
    exit;
}
$cap = capacidades()->datos($inst['id']);
$solId = es_post() ? campo('sol') : param('sol');

// Planes y días de prueba del programa (si los informa)
$planes = [];
$diasDef = 7;
if (Capacidades::puede($cap, 'planes')) {
    try {
        $p = cliente_v2()->pedir($inst, 'planes', [], usuario_v2());
        foreach (is_array($p['planes'] ?? null) ? $p['planes'] : [] as $pl) {
            if (is_array($pl) && (is_string($pl['codigo'] ?? null) || is_int($pl['codigo'] ?? null)) && ($pl['activo'] ?? true) !== false) {
                $planes[] = ['valor' => (string) $pl['codigo'], 'etiqueta' => (string) ($pl['nombre'] ?? $pl['codigo'])];
            }
        }
        $dd = $p['ajustes']['dias_demo'] ?? $p['ajustes']['dias_prueba'] ?? null;
        if (is_int($dd) && $dd >= 1) $diasDef = $dd;
    } catch (ErrorPrograma) {
    }
}
// Anexo B: si el programa lo acepta (capacidades.modulos.crear_demo), se elige el plan y qué módulos encender
$demoCat = null;
if (\Tukan\Modulos::tiene($cap) && ($cap['modulos']['crear_demo'] ?? false)) {
    $demoCat = (new \Tukan\Modulos(db()))->catalogo($inst, cliente_v2(), usuario_v2());
    if ($demoCat && !$demoCat['modulos']) $demoCat = null;
    if ($demoCat && !$planes) foreach ($demoCat['planes'] as $pl) $planes[] = ['valor' => $pl['codigo'], 'etiqueta' => $pl['nombre'] . ' · ' . dinero($pl['precio_mensual']) . ' al mes'];
}
$maxDias = (int) ($cap['limites']['dias_prueba_max'] ?? 90);
$comunes = Formulario::limpiarCampos([
    ['campo' => 'nombre', 'etiqueta' => 'Nombre del negocio', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 120],
    ['campo' => 'contacto_nombre', 'etiqueta' => 'Nombre del contacto', 'tipo' => 'texto', 'requerido' => true, 'min' => 2, 'max' => 80],
    ['campo' => 'telefono', 'etiqueta' => 'Celular', 'tipo' => 'telefono', 'requerido' => true, 'max' => 30, 'ayuda' => 'Para mandarle los accesos por WhatsApp.'],
    ['campo' => 'email', 'etiqueta' => 'Correo (será su usuario)', 'tipo' => 'email', 'requerido' => true, 'max' => 150],
]);
if ($planes) $comunes = array_merge($comunes, Formulario::limpiarCampos([['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $planes[0]['valor'], 'opciones' => $planes]]));
$comunes = array_merge($comunes, Formulario::limpiarCampos([
    ['campo' => 'dias', 'etiqueta' => 'Días de demo', 'tipo' => 'dias', 'requerido' => true, 'valor' => min($diasDef, $maxDias), 'min' => 1, 'max' => $maxDias],
]));
$extra = $cap['formularios']['crear_demo'] ?? [];

// Valores iniciales: del pedido de demo (si viene de una solicitud)
$valores = [];
$valoresExtra = [];
$sol = null;
if ($solId !== '' && ($fila = solicitudes()->obtener($inst['id'], $solId))) {
    $sol = solicitudes()->completa($fila, true);
    $q = $sol['quien'];
    $valores = array_filter(['nombre' => $q['organizacion'] ?? null, 'contacto_nombre' => $q['nombre'] ?? null, 'telefono' => $q['telefono'] ?? null, 'email' => $q['email'] ?? null]);
    foreach ($sol['acciones'] as $a) {
        if ($a['accion'] !== 'crear_demo') continue;
        foreach ($a['campos'] as $c) if ($c['valor'] !== null && $c['valor'] !== '') {
            if (in_array($c['campo'], array_column($comunes, 'campo'), true)) $valores[$c['campo']] = $c['valor'];
            else $valoresExtra[$c['campo']] = $c['valor'];
        }
    }
    foreach ($extra as $c) if (!isset($valoresExtra[$c['campo']]) && is_scalar($sol['detalle'][$c['campo']] ?? null)) $valoresExtra[$c['campo']] = (string) $sol['detalle'][$c['campo']];
}
$demoMods = $demoCat ? array_keys($demoCat['modulos']) : [];
if ($demoCat && es_post()) $demoMods = array_values(array_intersect(array_keys($demoCat['modulos']), is_array($_POST['demo_mods'] ?? null) ? $_POST['demo_mods'] : []));
$base = ['demoCat' => $demoCat, 'demoMods' => $demoMods, 'paso' => 2, 'inst' => $inst, 'pueden' => $pueden, 'comunes' => $comunes, 'extra' => $extra, 'sol' => $sol, 'solId' => $sol ? $solId : '',
         'valores' => $valores, 'valoresExtra' => $valoresExtra, 'errores' => [], 'error' => null, 'op' => Operaciones::nuevaId(), 'enviarCorreo' => true];
if (!es_post()) {
    vista('demo_nueva', $base, 'Crear demo · Panel Tukán');
    exit;
}

// Crear
$op = campo('op');
if (!Operaciones::idValida($op)) $op = Operaciones::nuevaId();
$post = campos_post();
[$v, $err] = Formulario::leer($comunes, $post);
[$vx, $errx] = Formulario::leer($extra, is_array($_POST['extra'] ?? null) ? $_POST['extra'] : []);
if (isset($v['telefono']) && strlen(preg_replace('/\D+/', '', $v['telefono'])) < 9) $err['telefono'] = 'Escribe un celular con al menos 9 números.';
$enviar = campo('enviar_correo') === '1';
$deNuevo = fn(array $errores, ?string $error, string $op) => vista('demo_nueva', ['valores' => $post, 'valoresExtra' => $_POST['extra'] ?? [], 'errores' => $errores, 'error' => $error, 'op' => $op, 'enviarCorreo' => $enviar] + $base, 'Crear demo · Panel Tukán');
if ($err || $errx) {
    http_response_code(422);
    $deNuevo($err + $errx, 'Revisa los campos marcados.', $op);
    exit;
}
$datos = ['nombre' => $v['nombre'], 'contacto_nombre' => $v['contacto_nombre'], 'email' => $v['email'], 'telefono' => $v['telefono'],
          'plan' => $v['plan'] ?? null, 'dias' => $v['dias'], 'enviar_correo' => $enviar, 'extra' => $vx === [] ? new stdClass() : $vx];
if ($sol) $datos['solicitud_id'] = $solId;
// Módulos de la demo: sin elegir = todos encendidos (no se manda nada); si se quitaron algunos, la lista de los que quedan
if ($demoCat && count($demoMods) < count($demoCat['modulos'])) {
    $sim = \Tukan\Modulos::simular($demoCat, ['activos' => []], (string) ($v['plan'] ?? ''), $demoMods, null);
    // Ninguno marcado = solo la base ("ninguno"); una lista vacía los programas la leen como «todos»
    $datos['modulos'] = $sim['activos'] ?: 'ninguno';
}
$res = operaciones()->ejecutar($inst, 'crear_demo', $op, null, $datos, usuario_v2(), $v['nombre']);
$corto = substr($op, 0, 8);
if ($res['ok']) {
    $r = $res['respuesta'];
    $resultado = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
    auditar('ejecutar', $res['resumen'] . ' → listo' . (($r['repetida'] ?? false) ? ' (ya estaba hecho)' : '') . " (op $corto)", $inst['id']);
    vista('resultado', ['inst' => $inst, 'tipo' => 'clave', 'mensaje' => Operaciones::sinClaves((string) ($r['mensaje'] ?? 'Demo creada.'), $resultado),
                        'resultado' => $resultado, 'respuesta' => $r, 'volver' => '/?r=demos'], 'Demo lista · Panel Tukán');
    exit;
}
$e = $res['error'];
auditar('ejecutar', $res['resumen'] . ($res['sin_respuesta'] ? ' → el programa no respondió' : ' → no se hizo: ' . $e->codigo) . " (op $corto)", $inst['id']);
if ($res['sin_respuesta']) {
    http_response_code(504);
    vista('resultado', ['inst' => $inst, 'tipo' => 'sin_respuesta', 'mensaje' => $e->getMessage(), 'accion' => 'crear_demo', 'volver' => '/?r=demos', 'post' => $_POST,
                        'accionUrl' => url('demo_nueva')], 'Sin respuesta · Panel Tukán');
    exit;
}
http_response_code($e->http >= 400 && $e->http < 500 ? $e->http : 422);
$campo = $e->campoFormulario();
$deNuevo($campo !== null ? [$campo => $e->getMessage()] : [], $inst['nombre'] . ': ' . $e->getMessage(), Operaciones::nuevaId());
