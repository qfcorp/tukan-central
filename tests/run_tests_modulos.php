<?php
declare(strict_types=1);

/**
 * Pruebas del Anexo B (programas modulares) en el panel. Las incluye tests/run_tests.php (usa $db, $cif, $ins, $uid, $tmp).
 * Lógica (catálogo, ficha, vista previa del precio, permisos) y contra el PROGRAMA DE PRUEBA (tests/mock/) con "php -S".
 */

use Tukan\{Acceso, Capacidades, ClienteV2, Clientes, Formulario, Licencias, Modulos, Operaciones, Solicitudes, Sincronizador};

// ---------------------------------------------------------------------------
titulo('29. Módulos (Anexo B): catálogo, ficha del cliente y vista previa del precio');
$catR = ['base' => ['Fichas', '', 7], 'editable' => true,
    'modulos' => [
        ['id' => 'portal', 'nombre' => 'Portal', 'icono' => 'house', 'descripcion' => 'Los dueños entran', 'precio_mensual' => 10, 'depende_de' => [], 'grupo' => 'Dueños', 'orden' => 20],
        ['id' => 'anuncios', 'nombre' => 'Anuncios', 'descripcion' => 'Avisos', 'precio_mensual' => 5.0, 'depende_de' => ['portal', 'fantasma'], 'grupo' => 'Dueños', 'orden' => 30],
        ['id' => 'hotel', 'nombre' => 'Hotel', 'descripcion' => 'Cuartos', 'precio_mensual' => 10.0, 'depende_de' => [], 'grupo' => 'Servicios', 'orden' => 10],
        ['id' => 'MAL ID', 'nombre' => 'x'], 'no es módulo',
    ],
    'planes' => [
        ['codigo' => 'esencial', 'nombre' => 'Esencial', 'precio_mensual' => 25, 'modulos' => ['portal', 'otro']],
        ['codigo' => 'completo', 'nombre' => 'Completo', 'precio_mensual' => 39.0, 'modulos' => ['*']],
        ['codigo' => 5, 'nombre' => 'Numérico', 'precio_mensual' => 9.0, 'todos' => false, 'modulos' => []],
    ]];
$cat = Modulos::limpiarCatalogo($catR);
check('Catálogo: solo los módulos válidos, con precio en número', [['portal', 'anuncios', 'hotel'], 10.0], [array_keys($cat['modulos']), $cat['modulos']['portal']['precio_mensual']]);
check('Dependencias desconocidas se quitan', ['portal'], $cat['modulos']['anuncios']['depende_de']);
check('Planes: "*" = todos; códigos numéricos como texto; módulos desconocidos fuera', [true, ['portal', 'anuncios', 'hotel'], ['portal'], ['esencial', 'completo', '5']],
      [$cat['planes']['completo']['todos'], $cat['planes']['completo']['modulos'], $cat['planes']['esencial']['modulos'], array_column($cat['planes'], 'codigo')]);
check('Base: textos (sin vacíos ni números)', ['Fichas'], $cat['base']);
check('Agrupados por grupo y en orden', ['Servicios' => ['hotel'], 'Dueños' => ['portal', 'anuncios']], array_map(fn($l) => array_column($l, 'id'), Modulos::agrupados($cat)));
$mc = Modulos::limpiarCliente(['plan' => ['codigo' => 'esencial', 'nombre' => 'Esencial'], 'del_plan' => ['portal'], 'extra' => ['hotel', 'MAL'], 'quitados' => [], 'activos' => ['portal', 'hotel'],
                               'precio_plan' => 25, 'precio_extra' => 10.0, 'descuento' => 0, 'precio_acordado' => null, 'mensual' => 35.0]);
check('Ficha: modulos limpio (ids válidos, dinero en número)', [['hotel'], 25.0, 35.0, null, 'esencial'], [$mc['extra'], $mc['precio_plan'], $mc['mensual'], $mc['precio_acordado'], $mc['plan']['codigo']]);
check('Sin modulos (programa sin Anexo B) → null', [null, null], [Modulos::limpiarCliente(null), Modulos::limpiarCliente(['plan' => 'x'])]);
$s = Modulos::simular($cat, $mc, 'esencial', ['portal', 'hotel', 'anuncios'], null);
check('Vista previa: encender Anuncios (+5) → $40', [['anuncios'], [], 40.0, ['anuncios', 'hotel']], [$s['encender'], $s['apagar'], $s['mensual'], $s['extra']]);
$s = Modulos::simular($cat, $mc, 'esencial', ['hotel', 'anuncios'], null);
check('Encender uno sin su dependencia → se enciende sola (Portal queda)', [['portal', 'anuncios', 'hotel'], ['anuncios'], []], [$s['activos'], $s['encender'], $s['problemas']]);
$s = Modulos::simular($cat, ['activos' => ['portal', 'anuncios']], 'completo', ['anuncios'], null);
check('Apagar Portal dejando Anuncios encendido → aviso de problema', true, $s['problemas'] !== [] && str_contains($s['problemas'][0], 'necesita «Portal»'));
$s = Modulos::simular($cat, $mc, 'completo', ['portal'], null);
check('Plan Completo y apagar Hotel y Anuncios: $39 − 15 = $24 (quitados con descuento)', [24.0, ['anuncios', 'hotel'], ['hotel']], [$s['mensual'], $s['quitados'], $s['apagar']]);
check('Precio acordado manda (y el calculado se ve aparte)', [30.0, 24.0], [Modulos::simular($cat, $mc, 'completo', ['portal'], 30.0)['mensual'], Modulos::simular($cat, $mc, 'completo', ['portal'], 30.0)['calculado']]);
check('Plan con código numérico ($9) + Hotel extra = $19', 19.0, Modulos::simular($cat, ['activos' => []], '5', ['hotel'], null)['mensual']);

$capM = Capacidades::limpiar(['acciones' => ['capacidades', 'cliente', 'ejecutar', 'modulos'], 'ejecutar' => ['cambiar_modulos', 'guardar_modulos', 'crear_demo'], 'modulos' => ['crear_demo' => true, 'precio_acordado' => false]]);
$capSin = Capacidades::limpiar(['acciones' => ['capacidades', 'cliente', 'ejecutar'], 'ejecutar' => ['nueva_clave_dueno'], 'modulos' => ['crear_demo' => true]]);
check('Capacidades: modulos solo si la acción "modulos" existe', [true, false, true, false, null], [Modulos::tiene($capM), Modulos::tiene($capSin), $capM['modulos']['crear_demo'], $capM['modulos']['precio_acordado'], $capSin['modulos']]);
$capB = Capacidades::limpiar(['acciones' => ['capacidades', 'clientes', 'ejecutar', 'modulos'], 'ejecutar' => ['crear_demo', 'cambiar_modulos'], 'modulos' => true, 'finanzas_por_modulo' => true]);
check('Capacidades con modulos: true (revisión 3 de otros programas): todo se puede, demo con módulos', [true, true, true, true, true],
      [Modulos::tiene($capB), $capB['modulos']['editable'], $capB['modulos']['precio_acordado'], $capB['modulos']['crear_demo'], $capB['finanzas_por_modulo']]);
check('...sin crear_demo no se ofrecen módulos en la demo', false, Capacidades::limpiar(['acciones' => ['modulos', 'ejecutar'], 'ejecutar' => ['cambiar_modulos'], 'modulos' => true])['modulos']['crear_demo']);
check('Resumen del programa: "Módulos (encender y apagar)"', [true, false], [Capacidades::resumen($capM)['Módulos (encender y apagar)'], Capacidades::resumen($capSin)['Módulos (encender y apagar)']]);
check('Permisos: cambiar_modulos y guardar_modulos solo el dueño; guardar_modulos pide 2 pasos', ['cobros', 'cobros', false, true, true],
      [Operaciones::permiso('cambiar_modulos'), Operaciones::permiso('guardar_modulos'), Acceso::puede('soporte', 'cobros'), Acceso::puede('dueno', 'cobros'), in_array('guardar_modulos', Operaciones::PIDEN_2FA, true)]);
check('Se hacen desde sus pantallas, no con el formulario genérico', ['cambiar_modulos', 'guardar_modulos'], Operaciones::DE_MODULOS);
check('Nombres en español', ['Cambiar módulos', 'Guardar catálogo de módulos'], [Operaciones::nombre('cambiar_modulos'), Operaciones::nombre('guardar_modulos')]);
check('Solicitudes: cambio_modulos y los pedidos de demo ("demo", "alta") con nombre', ['Cambio de módulos', 'Pedido de demo', 'Pedido de demo', 'Pidió una demo (registro)'],
      [Solicitudes::nombreTipo('cambio_modulos'), Solicitudes::nombreTipo('demo'), Solicitudes::nombreTipo('alta'), Solicitudes::nombreTipo('registro_nuevo')]);
check('Chip "Pedidos de demo" junta demo_pedida, demo, alta y registro_nuevo; chip "Módulos"', [['demo_pedida', 'demo', 'alta', 'registro_nuevo'], ['cambio_modulos']],
      [Solicitudes::FILTROS['demos'][1], Solicitudes::FILTROS['modulos'][1]]);
check('Telegram avisa los pedidos de demo y de módulos', [true, true, true], array_map(fn($t) => in_array($t, Sincronizador::TIPOS_AVISO_PREDETERMINADOS, true), ['demo', 'alta', 'cambio_modulos']));

// Copia local: modulos en el cliente, filtro "con módulos extra" e ingreso por módulo
$ins->crear(['id' => 'mod_logica', 'producto' => 'tuevento', 'url' => 'https://mod.ejemplo.ec', 'nombre' => 'Módulos lógica']);
$clM = new Clientes($db, $cif);
$mm = new Modulos($db);
$mm->guardarCatalogo('mod_logica', $cat);
foreach ([['1', ['hotel'], 'activo'], ['2', [], 'activo'], ['3', ['hotel', 'anuncios'], 'activo'], ['4', ['hotel'], 'suspendido']] as [$i, $ext, $e]) {
    $act = array_merge(['portal'], $ext);
    $clM->guardar('mod_logica', Clientes::limpiar(['id' => $i, 'nombre' => "Negocio $i", 'estado' => $e, 'mensual_estimado' => 25 + 10 * count($ext),
        'modulos' => ['plan' => ['codigo' => 'esencial'], 'del_plan' => ['portal'], 'extra' => $ext, 'quitados' => [], 'activos' => $act, 'precio_plan' => 25, 'mensual' => 25.0]]));
}
check('La copia guarda los módulos del cliente', ['hotel', 'anuncios'], $clM->obtener('mod_logica', '3')['datos']['modulos']['extra']);
check('Filtro "con módulos extra" (los que tienen al menos uno)', ['1', '3', '4'], array_column($clM->lista(['inst' => 'mod_logica', 'con_extra' => true], true)['filas'], 'cliente_id'));
$pm = array_column($mm->ingresoPorModulo($clM), null, 'modulo');
check('Ingreso por módulo: Hotel 2 clientes $20 (el suspendido no cuenta), Anuncios 1 cliente $5', [2, 20.0, 1, 5.0, 'Hotel'],
      [$pm['hotel']['clientes'], $pm['hotel']['monto'], $pm['anuncios']['clientes'], $pm['anuncios']['monto'], $pm['hotel']['nombre']]);
$ins->eliminar('mod_logica');

// ---------------------------------------------------------------------------
titulo('30. Módulos contra el programa de prueba (TuGuardería y TuEvento)');
$mdDir = "$tmp/mock_mod";
@mkdir($mdDir, 0700, true);
$MD_PUERTO = 8267;
$secMd = $ins->crear(['id' => 'perritos', 'producto' => 'tuguarderia', 'url' => "http://127.0.0.1:$MD_PUERTO", 'nombre' => 'TuGuardería Perritos']);
$mdProc = null;
$arrancarM = function (string $id, string $sec, string $prod, array $extra = []) use (&$mdProc, $mdDir, $MD_PUERTO): void {
    if ($mdProc) { proc_terminate($mdProc); proc_close($mdProc); usleep(200000); }
    foreach (glob("$mdDir/*") ?: [] as $f) @unlink($f);
    $mdProc = proc_open(['php', '-S', "127.0.0.1:$MD_PUERTO", dirname(__DIR__) . '/tests/mock/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$mdDir/servidor.log", 'a'], 2 => ['file', "$mdDir/servidor.log", 'a']],
        $t, null, ['PATH' => getenv('PATH'), 'TZ' => 'America/Guayaquil', 'TUKAN_CENTRAL_ID' => $id, 'TUKAN_CENTRAL_SECRET' => $sec, 'TUKAN_CENTRAL_URL' => 'http://127.0.0.1:1',
                         'MOCK_DIR' => $mdDir, 'MOCK_PRODUCTO' => $prod, 'MOCK_LIMITE' => '1000'] + $extra);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $MD_PUERTO)) break; usleep(100000); }
};
$arrancarM('perritos', $secMd, 'tuguarderia');
$ip = $ins->obtener('perritos');
$cvM = new ClienteV2($ins);
$capP = Capacidades::limpiar($cvM->pedir($ip, 'capacidades'));
(new Capacidades($db))->guardar('perritos', 2, $capP);
$yo = ['id' => $uid, 'email' => 'dimitry@tukan.ec', 'nombre' => 'Dimitry', 'rol' => 'dueno'];
check('TuGuardería de prueba: modulos, solicitudes y cambiar_modulos', [true, true, true, true], [Modulos::tiene($capP), Capacidades::puede($capP, 'solicitudes'),
      Capacidades::puede($capP, 'ejecutar', 'cambiar_modulos'), $capP['modulos']['por_instalacion']]);
$catP = $mm->catalogo($ip, $cvM, $yo);
check('Catálogo en vivo (7 módulos, 2 planes) y se guarda la copia', [7, 2, null, 7], [count($catP['modulos']), count($catP['planes']), $catP['de_copia'], count($mm->copiaCatalogo('perritos')['modulos'])]);
$clP = new Clientes($db, $cif);
$fu = $clP->refrescarUnico($ip, $cvM, $yo);
check('Cliente único: Completo sin Hotel ($59 − $10 = $49)', [49.0, false], [$fu['modulos']['mensual'], in_array('hotel', $fu['modulos']['activos'], true)]);
$licP = new Licencias($db, $cif);
$licP->guardar('perritos', 30.0, '2026-12-31', ['nombre' => 'Ana'], 'nota');
$opsM = new Operaciones($db, $cvM, $cif);
$opsM->espera = 0;
$r = $opsM->ejecutar($ip, 'cambiar_modulos', Operaciones::nuevaId(), 'unico', ['encender' => ['hotel'], 'apagar' => ['fotos']], $yo, 'Perritos');
check('cambiar_modulos: Hotel encendido, Fotos apagado → $51', [true, 51.0], [$r['ok'], $r['respuesta']['modulos']['mensual'] ?? null]);
$l = $licP->obtener('perritos');
check('La licencia toma el total de los módulos (y conserva fecha, contacto y notas)', [51.0, '2026-12-31', 'Ana', 'nota'], [(float) $l['valor_mensual'], $l['activo_hasta'], $l['contacto']['nombre'] ?? null, $l['notas']]);
check('La copia del cliente vale $51 al mes (la licencia)', 51.0, (float) $clP->obtener('perritos', 'unico')['mensual_estimado']);
$sol = new Solicitudes($db, $cif);
$sol->refrescar($ip, $cvM, $yo, null);
$fs = $sol->obtener('perritos', 'cambio_modulos:1');
check('El pedido de Mi plan llega a la bandeja (cambio_modulos, abierto)', ['cambio_modulos', 'pendiente'], [$fs['tipo'] ?? null, $fs['estado'] ?? null]);
$r = $opsM->ejecutar($ip, 'cambiar_modulos', Operaciones::nuevaId(), 'unico', ['encender' => ['hotel'], 'apagar' => [], 'solicitud_id' => 'cambio_modulos:1'], $yo, 'Perritos');
check('Aprobar: el pedido queda atendido en la copia', [true, 'atendida'], [$r['ok'], $sol->obtener('perritos', 'cambio_modulos:1')['estado']]);
$r = $opsM->ejecutar($ip, 'cambiar_modulos', Operaciones::nuevaId(), 'unico', ['encender' => [], 'apagar' => [], 'solicitud_id' => 'cambio_modulos:1'], $yo, 'Perritos');
check('Aprobar otra vez → conflicto (ya atendido)', [false, 'conflicto'], [$r['ok'], $r['error']->codigo ?? null]);
$r = $opsM->ejecutar($ip, 'guardar_modulos', Operaciones::nuevaId(), null, ['modulos' => [['id' => 'hotel', 'precio_mensual' => 12.0]]], $yo, 'Perritos');
check('guardar_modulos: la copia del catálogo queda con el precio nuevo', [true, 12.0], [$r['ok'], $mm->copiaCatalogo('perritos')['modulos']['hotel']['precio_mensual'] ?? null]);

// TuEvento de prueba: muchos clientes, módulos en la lista, ficha y demo con módulos elegidos
$secTq = $ins->crear(['id' => 'quintas_m', 'producto' => 'tuevento', 'url' => "http://127.0.0.1:$MD_PUERTO", 'nombre' => 'TuEvento módulos']);
$arrancarM('quintas_m', $secTq, 'tuevento');
$iq = $ins->obtener('quintas_m');
$capT = Capacidades::limpiar($cvM->pedir($iq, 'capacidades'));
(new Capacidades($db))->guardar('quintas_m', 2, $capT);
$clP->refrescar($iq, $cvM, $capT, $yo);
check('Lista: el cliente 3 trae +Reportes; filtro con extra = solo él', [['reportes'], ['3']],
      [$clP->obtener('quintas_m', '3')['datos']['modulos']['extra'], array_column($clP->lista(['inst' => 'quintas_m', 'con_extra' => true], true)['filas'], 'cliente_id')]);
$mm->catalogo($iq, $cvM, $yo);
$pmT = array_column(array_filter($mm->ingresoPorModulo($clP), fn($x) => $x['instancia_id'] === 'quintas_m'), null, 'modulo');
check('Ingreso por módulo de TuEvento: Reportes $4 (1 cliente)', [4.0, 1], [$pmT['reportes']['monto'] ?? null, $pmT['reportes']['clientes'] ?? null]);
check('crear_demo acepta módulos (capacidades.modulos.crear_demo)', true, $capT['modulos']['crear_demo']);
$r = $opsM->ejecutar($iq, 'crear_demo', Operaciones::nuevaId(), null, ['nombre' => 'Quinta Demo', 'contacto_nombre' => 'Rosa', 'email' => 'rosa@demo.ec', 'telefono' => '0991112233',
                     'plan' => '1', 'dias' => 7, 'enviar_correo' => false, 'extra' => new stdClass(), 'modulos' => ['agenda', 'whatsapp']], $yo, 'Quinta Demo');
$ld = array_slice(file("$mdDir/demos.log", FILE_IGNORE_NEW_LINES) ?: [], -1)[0] ?? '';
check('Demo con los módulos elegidos (el programa los recibe)', [true, true], [$r['ok'], str_ends_with($ld, '|1|agenda,whatsapp')]);
$arrancarM('quintas_m', $secTq, 'tuevento', ['MOCK_SIN_MODULOS' => '1']);
$capT2 = Capacidades::limpiar($cvM->pedir($iq, 'capacidades'));
check('Programa sin Anexo B: nada de módulos (y todo lo demás igual)', [false, null, false, true],
      [Modulos::tiene($capT2), $capT2['modulos'], Capacidades::puede($capT2, 'ejecutar', 'cambiar_modulos'), Capacidades::puede($capT2, 'ejecutar', 'registrar_pago')]);
proc_terminate($mdProc);
proc_close($mdProc);
$ins->eliminar('perritos');
$ins->eliminar('quintas_m');
foreach (glob("$mdDir/*") ?: [] as $f) @unlink($f);
@rmdir($mdDir);
