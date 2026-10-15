<?php
declare(strict_types=1);

/**
 * Pruebas de lógica del Anexo A (pausar y borrar clientes, con papelera). Las incluye tests/run_tests.php
 * (usa $db, $cif, $ins, $uid). La parte de punta a punta corre contra el PROGRAMA DE PRUEBA (tests/mock/) con "php -S".
 */

use Tukan\{Acceso, Capacidades, ClienteV2, Clientes, Formulario, Historial, Operaciones, Solicitudes};

// ---------------------------------------------------------------------------
titulo('25. Pausar y borrar clientes (Anexo A): permisos, estilos y campos');
check('Permisos: pausar (soporte también), borrar y restaurar solo el dueño, reactivar es cobros', ['pausar', 'borrar_clientes', 'borrar_clientes', 'cobros'],
      array_map([Operaciones::class, 'permiso'], ['pausar', 'borrar_cliente', 'restaurar_cliente', 'reactivar']));
check('Soporte: pausa, pero no reactiva ni borra ni restaura', [true, false, false],
      [Acceso::puede('soporte', 'pausar'), Acceso::puede('soporte', Operaciones::permiso('reactivar')), Acceso::puede('soporte', 'borrar_clientes')]);
check('Dueño: pausa y borra', [true, true], [Acceso::puede('dueno', 'pausar'), Acceso::puede('dueno', 'borrar_clientes')]);
check('borrar_cliente pide el código de 2 pasos', true, in_array('borrar_cliente', Operaciones::PIDEN_2FA, true));
check('Nombres en español sencillo', ['Pausar', 'Borrar cliente', 'Restaurar', 'Reactivar'],
      array_map([Operaciones::class, 'nombre'], ['pausar', 'borrar_cliente', 'restaurar_cliente', 'reactivar']));
check('Pausar y borrar salen en rojo aunque el programa diga "normal"', ['peligro', 'peligro', 'principal'],
      array_map(fn($a) => Formulario::limpiarAccion(['accion' => $a, 'estilo' => $a === 'restaurar_cliente' ? 'principal' : 'normal'])['estilo'], ['pausar', 'borrar_cliente', 'restaurar_cliente']));
$aP = Operaciones::completarCampos(Formulario::limpiarAccion(['accion' => 'pausar', 'datos' => ['cliente_id' => '3']]));
check('Pausar sin campos del programa: el panel pone tipo y motivo', ['tipo', 'motivo'], array_column($aP['campos'], 'campo'));
check('...tipo con las 5 opciones del protocolo', ['falta_pago', 'pedido_cliente', 'revision', 'abuso', 'otro'], array_column($aP['campos'][0]['opciones'], 'valor'));
check('...motivo obligatorio de 3 a 300', [true, 3, 300], [$aP['campos'][1]['requerido'], $aP['campos'][1]['min'], $aP['campos'][1]['max']]);
$aP2 = Operaciones::completarCampos(Formulario::limpiarAccion(['accion' => 'pausar', 'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo (lo ve el cliente)', 'tipo' => 'texto']]]));
check('Si el programa manda el motivo: se respeta su etiqueta, queda obligatorio y se agrega el tipo', ['Motivo (lo ve el cliente)', true, ['motivo', 'tipo']],
      [$aP2['campos'][0]['etiqueta'], $aP2['campos'][0]['requerido'], array_column($aP2['campos'], 'campo')]);
$aB = Operaciones::completarCampos(Formulario::limpiarAccion(['accion' => 'borrar_cliente', 'campos' => [['campo' => 'confirmar', 'tipo' => 'texto', 'valor' => 'Liga Norte']]]), 'Liga Norte');
check('Borrar: confirmar (sin valor lleno) + motivo, los dos obligatorios', [['confirmar', 'motivo'], null, true, true],
      [array_column($aB['campos'], 'campo'), $aB['campos'][0]['valor'], $aB['campos'][0]['requerido'], $aB['campos'][1]['requerido']]);
$aB2 = Operaciones::completarCampos(Formulario::limpiarAccion(['accion' => 'borrar_cliente']), 'Liga Norte');
check('Borrar sin campos: la etiqueta dice el nombre exacto', 'Escribe el nombre exacto: Liga Norte', $aB2['campos'][0]['etiqueta']);
check('Nombre exacto (o el id); no vale con otras mayúsculas', [true, true, true, false, false],
      [Operaciones::nombreCoincide('Liga Norte', 'Liga Norte', '7'), Operaciones::nombreCoincide('  Liga Norte ', 'Liga Norte', '7'), Operaciones::nombreCoincide('7', 'Liga Norte', '7'),
       Operaciones::nombreCoincide('liga norte', 'Liga Norte', '7'), Operaciones::nombreCoincide('', 'Liga Norte', '7')]);
check('Respuesta de borrar: fecha y copia arriba o en resultado', [['borrar_definitivo_el' => '2026-11-14', 'copia' => '/r/1.json.gz'], ['borrar_definitivo_el' => '2026-11-14', 'copia' => '/r/2.json.gz'], ['borrar_definitivo_el' => null, 'copia' => null]],
      [Operaciones::datosBorrado(['borrar_definitivo_el' => '2026-11-14', 'copia' => '/r/1.json.gz']), Operaciones::datosBorrado(['resultado' => ['borrar_definitivo_el' => '2026-11-14T00:00:00-05:00', 'copia' => '/r/2.json.gz']]),
       Operaciones::datosBorrado(['borrar_definitivo_el' => 'mañana', 'copia' => "a\nb"])]);
check('Resumen de pausar para la auditoría (con el tipo)', 'Pausar · Liga 3 · Falta de pago', Operaciones::resumen('pausar', 'Liga 3', ['tipo' => 'falta_pago', 'motivo' => 'x']));
check('Capacidades: "Pausar clientes" y "Borrar clientes (papelera)" en la ficha del programa', [true, false],
      [Capacidades::resumen(['acciones' => ['ejecutar'], 'ejecutar' => ['pausar']])['Pausar clientes'], Capacidades::resumen(['acciones' => ['ejecutar'], 'ejecutar' => ['pausar']])['Borrar clientes (papelera)']]);

// ---------------------------------------------------------------------------
titulo('26. Copia local: pausa y papelera');
$lp = Clientes::limpiar(['id' => '1', 'nombre' => 'X', 'estado' => 'suspendido', 'pausa' => ['tipo' => 'abuso', 'motivo' => 'Reventa', 'desde' => '2026-10-15T09:00:00-05:00', 'quien' => 'Dimitry <d@x.ec>'],
                         'papelera' => null]);
check('Pausa: tipo, nombre del tipo y motivo', ['abuso', 'Uso indebido', 'Reventa', null], [$lp['pausa']['tipo'], $lp['pausa']['tipo_nombre'], $lp['pausa']['motivo'], $lp['papelera']]);
check('Pausa con tipo desconocido -> otro', 'otro', Clientes::limpiar(['id' => '1', 'nombre' => 'X', 'pausa' => ['tipo' => 'raro']])['pausa']['tipo']);
$lpp = Clientes::limpiar(['id' => '1', 'nombre' => 'X', 'papelera' => ['desde' => '2026-10-15', 'borrar_definitivo_el' => '2026-11-14', 'motivo' => "Pidió\nla baja"]]);
check('Papelera: desde, hasta (también como borrar_definitivo_el) y motivo en una línea', ['2026-10-15', '2026-11-14', 'Pidió la baja'], [$lpp['papelera']['desde'], $lpp['papelera']['hasta'], $lpp['papelera']['motivo']]);
check('Sin pausa ni papelera: null', [null, null], array_values(array_intersect_key(Clientes::limpiar(['id' => '1', 'nombre' => 'X']), ['pausa' => 1, 'papelera' => 1])));
$ins->crear(['id' => 'pap_logica', 'producto' => 'tuevento', 'url' => 'https://pap.ejemplo.ec', 'nombre' => 'Papelera lógica']);
$clP = new Clientes($db, $cif);
foreach ([['1', 'activo', null], ['2', 'suspendido', ['tipo' => 'falta_pago', 'motivo' => 'Debe octubre']], ['3', 'activo', null]] as [$i, $e, $pz]) {
    $clP->guardar('pap_logica', Clientes::limpiar(['id' => $i, 'nombre' => "Quinta $i", 'estado' => $e, 'pausa' => $pz, 'papelera' => $i === '3' ? ['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d', strtotime('+30 days')), 'motivo' => 'Cerró'] : null]));
}
$lt = $clP->lista(['inst' => 'pap_logica'], true);
check('Lista normal sin los de la papelera', ['1', '2'], array_column($lt['filas'], 'cliente_id'));
check('Contadores sin los de la papelera, y cuántos hay en ella', [1, 1, 1], [$lt['contadores']['activo'], $lt['contadores']['suspendido'], $lt['papelera']]);
$ltp = $clP->lista(['inst' => 'pap_logica', 'papelera' => true, 'estados' => ['activo']], true);
check('Filtro Papelera: solo esos (aunque venga otro estado)', ['3'], array_column($ltp['filas'], 'cliente_id'));
check('todas() (Inicio, Vencimientos, Embudo) no cuenta la papelera', ['1', '2'], array_column($clP->todas('pap_logica'), 'cliente_id'));
check('...con conPapelera sí', 3, count($clP->todas('pap_logica', true)));
$clP->aPapelera('pap_logica', '1', ['hasta' => '2026-11-14', 'motivo' => 'Prueba', 'copia' => '/r/1.json.gz']);
check('aPapelera: pasa a la papelera de la copia (con desde de hoy y la copia)', [['2'], date('Y-m-d'), '/r/1.json.gz'],
      [array_column($clP->lista(['inst' => 'pap_logica'], true)['filas'], 'cliente_id'), $clP->obtener('pap_logica', '1')['datos']['papelera']['desde'], $clP->obtener('pap_logica', '1')['datos']['papelera']['copia']]);
$hi = new Historial($db);
$hi->anotar('pap_logica', '2', 'pausar', ['tipo' => 'falta_pago', 'motivo' => "Debe\noctubre"], ['id' => $uid, 'nombre' => 'Dimitry'], str_repeat('a', 32), [], time() - 10);
$hi->anotar('pap_logica', '2', 'pausar', ['tipo' => 'falta_pago', 'motivo' => 'otra vez'], ['id' => $uid, 'nombre' => 'Dimitry'], str_repeat('a', 32));
$hi->anotar('pap_logica', '2', 'reactivar', [], ['id' => $uid, 'nombre' => 'Dimitry'], str_repeat('b', 32));
$h = $hi->de('pap_logica', '2');
check('Historial: una línea por operación (la repetida no se duplica), lo nuevo primero', ['reactivar', 'pausar'], array_column($h, 'accion'));
check('Historial: quién (usuario del panel), tipo y motivo', ['Dimitry Fierro', 'falta_pago', 'Debe octubre'], [$h[1]['quien'], $h[1]['tipo'], $h[1]['motivo']]);
check('Historial: títulos en palabras simples', ['Reactivó', 'Pausó · Falta de pago', 'Borró (a la papelera)'],
      [Historial::titulo($h[0]), Historial::titulo($h[1]), Historial::titulo(['accion' => 'borrar_cliente', 'tipo' => null])]);
check('La tabla del historial se crea sola (user_version sigue en 2)', [true, 2],
      [(bool) $db->query("SELECT 1 FROM sqlite_master WHERE name = 'historial_clientes'")->fetchColumn(), (int) $db->query('PRAGMA user_version')->fetchColumn()]);

// ---------------------------------------------------------------------------
titulo('27. Contra el programa de prueba (tests/mock): pausar, borrar a la papelera, restaurar');
$mkDir = "$tmp/mock_pausa";
@mkdir($mkDir, 0700, true);
$MK_PUERTO = 8257;
$secMk = $ins->crear(['id' => 'quintas', 'producto' => 'tuevento', 'url' => "http://127.0.0.1:$MK_PUERTO", 'nombre' => 'TuEvento Quintas']);
$mkProc = null;
$arrancar = function (array $extra = []) use (&$mkProc, $mkDir, $MK_PUERTO, $secMk): void {
    if ($mkProc) { proc_terminate($mkProc); proc_close($mkProc); usleep(200000); }
    $mkProc = proc_open(['php', '-S', "127.0.0.1:$MK_PUERTO", dirname(__DIR__) . '/tests/mock/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$mkDir/servidor.log", 'a'], 2 => ['file', "$mkDir/servidor.log", 'a']],
        $t, null, ['PATH' => getenv('PATH'), 'TZ' => 'America/Guayaquil', 'TUKAN_CENTRAL_ID' => 'quintas', 'TUKAN_CENTRAL_SECRET' => $secMk, 'TUKAN_CENTRAL_URL' => 'http://127.0.0.1:1',
                         'MOCK_DIR' => $mkDir, 'MOCK_PRODUCTO' => 'tuevento', 'MOCK_LIMITE' => '1000'] + $extra);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $MK_PUERTO)) break; usleep(100000); }
};
$arrancar();
$iq = $ins->obtener('quintas');
$cvQ = new ClienteV2($ins);
$capQ = $cvQ->pedir($iq, 'capacidades');
(new Capacidades($db))->guardar('quintas', 2, $capQ);
check('El programa de prueba declara pausar, borrar_cliente y restaurar_cliente', [true, true, true],
      [Capacidades::puede($capQ, 'ejecutar', 'pausar'), Capacidades::puede($capQ, 'ejecutar', 'borrar_cliente'), Capacidades::puede($capQ, 'ejecutar', 'restaurar_cliente')]);
$clQ = new Clientes($db, $cif);
$clQ->refrescar($iq, $cvQ, $capQ);
check('Copia: 9 clientes, ninguno en la papelera', [9, 0], [count($clQ->todas('quintas')), $clQ->lista(['inst' => 'quintas'], true)['papelera']]);
check('Al refrescar también pide la papelera (estado y filtros.estado = papelera)', ['normal', 'papelera'], array_slice(file("$mkDir/clientes.log", FILE_IGNORE_NEW_LINES) ?: [], -2));
$opsQ = new Operaciones($db, $cvQ, $cif);
$opsQ->espera = 0;
$yo = ['id' => $uid, 'email' => 'dimitry@tukan.ec', 'nombre' => 'Dimitry', 'rol' => 'dueno'];
$mockdb = fn() => json_decode((string) file_get_contents("$mkDir/db.json"), true);
$fichaQ = fn(string $id) => $cvQ->pedir($iq, 'cliente', ['id' => $id], $yo)['cliente'];
$acc = fn(string $id) => array_column($fichaQ($id)['acciones_posibles'], 'accion');
check('Ficha de un cliente activo: Suspender, Pausar y Borrar', [true, true, true, false], [in_array('suspender', $acc('3'), true), in_array('pausar', $acc('3'), true), in_array('borrar_cliente', $acc('3'), true), in_array('restaurar_cliente', $acc('3'), true)]);
$r = $opsQ->ejecutar($iq, 'pausar', Operaciones::nuevaId(), '3', ['tipo' => 'falta_pago', 'motivo' => 'ab'], $yo, 'Salón Imperial');
check('Pausar con motivo corto: el programa lo rechaza junto al campo', [false, 'invalido', 'motivo'], [$r['ok'], $r['error']->codigo, $r['error']->campoFormulario()]);
$opP = Operaciones::nuevaId();
$r = $opsQ->ejecutar($iq, 'pausar', $opP, '3', ['tipo' => 'falta_pago', 'motivo' => 'Debe el pago de octubre'], $yo, 'Salón Imperial');
check('Pausar: listo en el programa (tipo y motivo guardados)', [true, 'falta_pago', 'Debe el pago de octubre'], [$r['ok'], $mockdb()['clientes']['3']['pausa']['tipo'] ?? null, $mockdb()['clientes']['3']['pausa']['motivo'] ?? null]);
$f3 = $clQ->obtener('quintas', '3');
check('Copia local: suspendido con su pausa', ['suspendido', 'Falta de pago', 'Debe el pago de octubre'], [$f3['estado'], $f3['datos']['pausa']['tipo_nombre'] ?? null, $f3['datos']['pausa']['motivo'] ?? null]);
check('Ficha pausada: Reactivar (y ya no Pausar)', [true, false], [in_array('reactivar', $acc('3'), true), in_array('pausar', $acc('3'), true)]);
$r = $opsQ->ejecutar($iq, 'pausar', $opP, '3', ['tipo' => 'falta_pago', 'motivo' => 'Debe el pago de octubre'], $yo, 'Salón Imperial');
check('Repetir la misma operación: "repetida" y una sola línea en el historial', [true, true, 1],
      [$r['ok'], $r['respuesta']['repetida'] ?? null, count(array_filter((new Historial($db))->de('quintas', '3'), fn($x) => $x['accion'] === 'pausar'))]);
$r = $opsQ->ejecutar($iq, 'pausar', Operaciones::nuevaId(), '3', ['tipo' => 'falta_pago', 'motivo' => 'Debe el pago de octubre'], $yo, 'Salón Imperial');
check('Pausar otra vez con lo mismo: sin cambios (no se anota en el historial)', [true, true, 1],
      [$r['ok'], $r['respuesta']['sin_cambios'] ?? null, count(array_filter((new Historial($db))->de('quintas', '3'), fn($x) => $x['accion'] === 'pausar'))]);
$r = $opsQ->ejecutar($iq, 'reactivar', Operaciones::nuevaId(), '3', [], $yo, 'Salón Imperial');
check('Reactivar: quita la pausa en el programa y en la copia', [true, null, 'por_vencer'], [$r['ok'], $mockdb()['clientes']['3']['pausa'] ?? null, $clQ->obtener('quintas', '3')['estado']]);
$r = $opsQ->ejecutar($iq, 'borrar_cliente', Operaciones::nuevaId(), '4', ['confirmar' => 'Quinta El Eden', 'motivo' => 'Cerró el local'], $yo, 'Quinta El Edén');
check('Borrar con el nombre mal escrito: no se borra nada (campo confirmar)', [false, 'confirmar', null], [$r['ok'], $r['error']->campoFormulario(), $mockdb()['clientes']['4']['papelera'] ?? null]);
$opB = Operaciones::nuevaId();
$r = $opsQ->ejecutar($iq, 'borrar_cliente', $opB, '4', ['confirmar' => 'Quinta El Edén', 'motivo' => 'Cerró el local'], $yo, 'Quinta El Edén');
$bd = Operaciones::datosBorrado($r['respuesta'] ?? []);
check('Borrar: listo, se borra para siempre en 30 días y dice dónde quedó la copia', [true, date('Y-m-d', strtotime('+30 days')), true],
      [$r['ok'], $bd['borrar_definitivo_el'], str_contains((string) $bd['copia'], '/respaldos/borrados/4_')]);
$f4 = $clQ->obtener('quintas', '4');
check('Copia local: en la papelera con fecha, copia y motivo', [date('Y-m-d', strtotime('+30 days')), $bd['copia'], 'Cerró el local'],
      [$f4['datos']['papelera']['hasta'] ?? null, $f4['datos']['papelera']['copia'] ?? null, $f4['datos']['papelera']['motivo'] ?? null]);
check('Lista normal: 8 y la papelera 1', [8, 1], [count($clQ->lista(['inst' => 'quintas'], true)['filas']), $clQ->lista(['inst' => 'quintas'], true)['papelera']]);
$hb = (new Historial($db))->de('quintas', '4')[0];
check('Historial: quién borró, cuándo, por qué y hasta cuándo', ['borrar_cliente', 'Dimitry Fierro', 'Cerró el local', date('Y-m-d', strtotime('+30 days'))],
      [$hb['accion'], $hb['quien'], $hb['motivo'], $hb['datos']['borrar_definitivo_el'] ?? null]);
check('El programa ya no lo cuenta (estado.negocios.total = 8)', 8, json_decode((string) file_get_contents("http://127.0.0.1:$MK_PUERTO/tukan_central.php?accion=estado&t=" . \Tukan\Token::crear('estado', 'quintas', 'd@x.ec', 'D', $secMk)), true)['negocios']['total'] ?? null);
$clQ->refrescar($iq, $cvQ, $capQ);
check('Después de la revisión de cada hora sigue en la papelera (la trae aparte)', [8, 1, true],
      [count($clQ->todas('quintas')), $clQ->lista(['inst' => 'quintas'], true)['papelera'], !empty($clQ->obtener('quintas', '4')['datos']['papelera'])]);
check('Ficha en la papelera: solo Restaurar', ['restaurar_cliente'], $acc('4'));
$r = $opsQ->ejecutar($iq, 'registrar_pago', Operaciones::nuevaId(), '4', ['monto' => 19.0, 'metodo' => 'efectivo'], $yo, 'Quinta El Edén');
check('Otra operación sobre uno de la papelera: no_encontrado', 'no_encontrado', $r['error']->codigo ?? null);
$r = $opsQ->ejecutar($iq, 'restaurar_cliente', Operaciones::nuevaId(), '4', [], $yo, 'Quinta El Edén');
check('Restaurar: vuelve a la lista normal tal como estaba', [true, 9, 0, null], [$r['ok'], count($clQ->todas('quintas')), $clQ->lista(['inst' => 'quintas'], true)['papelera'], $clQ->obtener('quintas', '4')['datos']['papelera']]);
check('Historial del 4: borró y restauró', ['restaurar_cliente', 'borrar_cliente'], array_column((new Historial($db))->de('quintas', '4'), 'accion'));
$arrancar(['MOCK_SIN_PAUSA' => '1']);
$capQ2 = $cvQ->pedir($iq, 'capacidades');
(new Capacidades($db))->guardar('quintas', 2, $capQ2);
$antes = count(file("$mkDir/clientes.log") ?: []);
$clQ->refrescar($iq, $cvQ, $capQ2);
check('Programa sin el Anexo A: no declara las operaciones y no se le pide la papelera', [false, false, 1],
      [Capacidades::puede($capQ2, 'ejecutar', 'pausar'), Capacidades::puede($capQ2, 'ejecutar', 'borrar_cliente'), count(file("$mkDir/clientes.log") ?: []) - $antes]);
check('...y la ficha no trae esos botones', [], array_values(array_intersect(['pausar', 'borrar_cliente', 'restaurar_cliente'], $acc('5'))));
proc_terminate($mkProc);
proc_close($mkProc);
$ins->eliminar('quintas');
$ins->eliminar('pap_logica');
foreach (glob("$mkDir/*") ?: [] as $f) @unlink($f);
@rmdir($mkDir);

// ---------------------------------------------------------------------------
titulo('28. Solicitudes: pausar / borrar al cliente que no pagó');
check('Demo terminó sin pagar (etapa terminada): motivo sugerido', 'No pagó después de la demo', Solicitudes::motivoSinPagar(['tipo' => 'seguimiento_prueba', 'detalle' => ['etapa' => 'terminada']]));
check('Plan vencido sin renovar (etapa vencida)', 'No pagó la renovación del plan', Solicitudes::motivoSinPagar(['tipo' => 'renovacion', 'detalle' => ['etapa' => 'vencida']]));
check('Avisos antes de vencer: no (faltan_2, vence_hoy, vence_pronto)', [null, null, null],
      [Solicitudes::motivoSinPagar(['tipo' => 'seguimiento_prueba', 'detalle' => ['etapa' => 'faltan_2']]), Solicitudes::motivoSinPagar(['tipo' => 'seguimiento_prueba', 'detalle' => ['etapa' => 'vence_hoy']]),
       Solicitudes::motivoSinPagar(['tipo' => 'renovacion', 'detalle' => ['etapa' => 'vence_pronto']])]);
check('Sin etapa: por el título (TuVocalía «La demo terminó sin pagar: X», «No renovó: X»)', ['No pagó después de la demo', 'No pagó la renovación del plan'],
      [Solicitudes::motivoSinPagar(['tipo' => 'seguimiento_prueba', 'titulo' => 'La demo terminó sin pagar: Liga Norte', 'detalle' => []]),
       Solicitudes::motivoSinPagar(['tipo' => 'renovacion', 'titulo' => 'No renovó: Liga Norte', 'detalle' => []])]);
check('Otros tipos: no (comprobante, soporte, registro nuevo)', [null, null, null],
      [Solicitudes::motivoSinPagar(['tipo' => 'comprobante', 'categoria' => 'pedido', 'titulo' => 'Comprobante', 'detalle' => []]),
       Solicitudes::motivoSinPagar(['tipo' => 'soporte', 'categoria' => 'pedido', 'titulo' => 'Ticket: la demo terminó sin pagar', 'detalle' => []]),
       Solicitudes::motivoSinPagar(['tipo' => 'registro_nuevo', 'categoria' => 'pedido', 'titulo' => 'Se registró solo', 'detalle' => []])]);
check('Recordatorio de un programa nuevo con ese título: sí', 'No pagó después de la demo',
      Solicitudes::motivoSinPagar(['tipo' => 'aviso_demo', 'categoria' => 'recordatorio', 'titulo' => 'Prueba terminada sin pagar', 'detalle' => []]));
$ins->crear(['id' => 'sol_borrar', 'producto' => 'tuevento', 'url' => 'https://solb.ejemplo.ec', 'nombre' => 'Sol borrar']);
$solB = new Solicitudes($db, $cif);
$solB->guardarUna('sol_borrar', Solicitudes::limpiar(['id' => 'seguimiento_prueba:5:terminada', 'tipo' => 'seguimiento_prueba', 'categoria' => 'recordatorio', 'titulo' => 'Prueba terminada sin pagar',
    'fecha' => date('c'), 'cliente_id' => '5', 'estado' => 'pendiente', 'detalle' => ['etapa' => 'terminada'], 'acciones_posibles' => []]), null, false);
check('Marcar atendida en la copia local (el programa ya no la tiene)', [true, 'atendida', false],
      [$solB->marcarAtendida('sol_borrar', 'seguimiento_prueba:5:terminada'), $solB->obtener('sol_borrar', 'seguimiento_prueba:5:terminada')['estado'], $solB->marcarAtendida('sol_borrar', 'no-existe')]);
check('...ya no cuenta por atender', 0, (int) ($solB->porPrograma()['sol_borrar'] ?? 0));
$ins->eliminar('sol_borrar');
