<?php
declare(strict_types=1);

/**
 * Pruebas de lógica del Protocolo Tukán Central v2 (las incluye tests/run_tests.php; usan su base temporal $db,
 * su cifrado $cif, sus instancias $ins y las funciones check / check_error / titulo).
 */

use Tukan\{Acceso, Ajustes, Analisis, Capacidades, ClienteV2, Clientes, Contacto, Db, ErrorPrograma, Estado, Exportar, Finanzas, Formulario,
           Instancias, Licencias, Operaciones, Sincronizacion, Sincronizador, Solicitudes, Token, Xlsx};

// ---------------------------------------------------------------------------
titulo('11. Token v2: hash del cuerpo byte por byte (§2.2 y §13.1)');
function tc2_token_ref(string $tipo, string $inst, string $email, string $nombre, string $rol, string $cuerpo, string $secreto, int $ahora, string $nonce): string
{
    $p = ['v' => 2, 'tipo' => $tipo, 'inst' => $inst, 'email' => $email, 'nombre' => $nombre, 'rol' => $rol,
          'iat' => $ahora, 'exp' => $ahora + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => $nonce, 'bh' => tc_b64(hash('sha256', $cuerpo, true))];
    return tc_firmar($p, $secreto);
}
$sec = str_repeat('0123456789abcdef', 4);
$cuerpo = ClienteV2::json(['pagina' => 1, 'por_pagina' => 50, 'buscar' => 'Señor / Ñandú']);
check('Cuerpo JSON exacto (UTF-8 crudo, "/" sin escapar)', '{"pagina":1,"por_pagina":50,"buscar":"Señor / Ñandú"}', $cuerpo);
check('Cuerpo vacío = {} (nunca [])', '{}', ClienteV2::json([]));
check('Objeto vacío anidado = {}', '{"datos":{}}', ClienteV2::json(['datos' => new stdClass()]));
check('Dinero con decimales se conserva (95.0)', '{"monto":95.0}', ClienteV2::json(['monto' => 95.0]));
$nonce = str_repeat('9f', 16);
$p2 = Token::payloadV2('clientes', 'tuvocalia', 'dimitryfierro@gmail.com', 'Dimitry', 'dueno', $cuerpo, null, 1760371200, $nonce);
check('Payload v2 en el orden del protocolo', 'v,tipo,inst,email,nombre,rol,iat,exp,nonce,bh', implode(',', array_keys($p2)));
check('Firma idéntica al código de referencia (§13.1)', tc2_token_ref('clientes', 'tuvocalia', 'dimitryfierro@gmail.com', 'Dimitry', 'dueno', $cuerpo, $sec, 1760371200, $nonce), Token::firmar($p2, $sec));
check('bh = b64url(sha256(cuerpo)), 43 caracteres', [tc_b64(hash('sha256', $cuerpo, true)), 43], [$p2['bh'], strlen($p2['bh'])]);
check('Lecturas: exp - iat = 60', 60, $p2['exp'] - $p2['iat']);
$pe = Token::payloadV2('ejecutar', 'tuvocalia', 'd@x.ec', 'D', 'dueno', '{}', null, 1760371200);
check('ejecutar: exp - iat = 30', 30, $pe['exp'] - $pe['iat']);
check('ejecutar: aunque se pida más, máximo 30', 30, Token::payloadV2('ejecutar', 'a1', 'd@x.ec', 'D', 'dueno', '{}', 300, 1760371200)['exp'] - 1760371200);
check('Rol desconocido se manda como dueno', 'dueno', Token::payloadV2('clientes', 'a1', 'd@x.ec', 'D', 'hacker', '{}')['rol']);
check('Rol soporte', 'soporte', Token::payloadV2('clientes', 'a1', 'd@x.ec', 'D', 'soporte', '{}')['rol']);
check_error('Tipo v1 no sirve como v2', 'no valido', fn() => Token::payloadV2('sso', 'a1', 'd@x.ec', 'D', 'dueno', '{}'));
$tok = Token::crearV2('clientes', 'tuvocalia', 'd@x.ec', 'D', 'dueno', $cuerpo, $sec, null, 1760371200);
check('Valida bien (como el programa)', 'tuvocalia', Token::validarV2($tok, $sec, 'clientes', 'tuvocalia', $cuerpo, 1760371210)['inst']);
check_error('Cuerpo cambiado UN byte -> firma', 'firma', fn() => Token::validarV2($tok, $sec, 'clientes', 'tuvocalia', substr($cuerpo, 0, -2) . 'X}', 1760371210));
check_error('Cuerpo con un espacio de más -> firma', 'firma', fn() => Token::validarV2($tok, $sec, 'clientes', 'tuvocalia', $cuerpo . ' ', 1760371210));
check_error('Otro secreto -> firma', 'firma', fn() => Token::validarV2($tok, str_repeat('f', 64), 'clientes', 'tuvocalia', $cuerpo, 1760371210));
check_error('Token v1 en una acción v2 -> version', 'version', fn() => Token::validarV2(Token::crear('estado', 'tuvocalia', 'd@x.ec', 'D', $sec, 1760371200), $sec, 'clientes', 'tuvocalia', '{}', 1760371210));
check_error('tipo distinto de la acción -> tipo', 'tipo', fn() => Token::validarV2($tok, $sec, 'finanzas', 'tuvocalia', $cuerpo, 1760371210));
check_error('Otra instancia -> inst', 'inst', fn() => Token::validarV2($tok, $sec, 'clientes', 'tusalon', $cuerpo, 1760371210));
check_error('Vencido -> vencido', 'vencido', fn() => Token::validarV2($tok, $sec, 'clientes', 'tuvocalia', $cuerpo, 1760371200 + 91));
check_error('Del futuro -> futuro', 'futuro', fn() => Token::validarV2($tok, $sec, 'clientes', 'tuvocalia', $cuerpo, 1760371200 - 31));
$largo = Token::firmar(['exp' => 1760371231] + Token::payloadV2('ejecutar', 'a1', 'd@x.ec', 'D', 'dueno', '{}', null, 1760371200), $sec);
check_error('ejecutar con exp - iat = 31 -> invalido', 'invalido', fn() => Token::validarV2($largo, $sec, 'ejecutar', 'a1', '{}', 1760371210));
check('SSO con ir (va al final, v:1)', ['v' => 1, 'ir' => '/admin.php?p=ligas&editar=123'], array_intersect_key(Token::payload('sso', 'a1', 'd@x.ec', 'D', 1760371200, null, '/admin.php?p=ligas&editar=123'), ['v' => 1, 'ir' => 1]));
check('SSO sin ir: igual que v1', 'v,tipo,inst,email,nombre,iat,exp,nonce', implode(',', array_keys(Token::payload('sso', 'a1', 'd@x.ec', 'D'))));
check('estado no lleva ir', false, array_key_exists('ir', Token::payload('estado', 'a1', 'd@x.ec', 'D', null, null, '/x')));

// ---------------------------------------------------------------------------
titulo('12. Errores con "codigo" (§3.2) y compatibilidad con los textos de v1');
$cods = ['firma' => 'secreto', 'inst' => 'id', 'tipo' => 'tipo', 'version' => 'version', 'vencido' => 'reloj', 'futuro' => 'reloj', 'nonce' => 'repetido',
         'sin_configurar' => 'sin_configurar', 'limite' => 'limite', 'no_encontrado' => 'no_encontrado', 'invalido' => 'invalido', 'no_permitido' => 'no_permitido',
         'conflicto' => 'conflicto', 'interno' => 'error_programa'];
foreach ($cods as $c => $esperado) {
    check("clasificar: codigo $c -> $esperado", $esperado, Estado::clasificar(in_array($c, ['sin_configurar', 'no_encontrado'], true) ? 404 : 401, json_encode(['ok' => false, 'codigo' => $c, 'error' => 'xyz']))[0]);
}
check('El código manda aunque el texto diga otra cosa', 'repetido', Estado::clasificar(401, '{"ok":false,"codigo":"nonce","error":"firma incorrecta"}')[0]);
check('Código desconocido: se adivina por el texto (v1)', 'secreto', Estado::clasificar(401, '{"ok":false,"codigo":"raro","error":"firma incorrecta"}')[0]);
check('v1 sin código sigue igual', 'id', Estado::clasificar(401, '{"ok":false,"error":"El enlace es de otra instalación."}')[0]);
check('404 sin JSON = sin conectar (como hoy)', 'no_conectado', Estado::clasificar(404, 'No encontrado')[0]);
check('404 con sin_configurar', 'sin_configurar', Estado::clasificar(404, '{"ok":false,"protocolo":2,"codigo":"sin_configurar","error":"x"}')[0]);
check('vencido con reloj del programa corrido: lo dice', true, str_contains(Estado::clasificar(401, '{"ok":false,"codigo":"vencido","error":"x"}', 0, '', 600)[1], '10 minutos'));
check('invalido: muestra el texto del programa', true, str_contains(Estado::clasificar(422, '{"ok":false,"codigo":"invalido","error":"Escriba el monto con números."}')[1], 'Escriba el monto'));
$e = ErrorPrograma::desdeRespuesta(422, '{"ok":false,"protocolo":2,"codigo":"invalido","error":"Escriba el monto con números, por ejemplo 39,00.","campo":"datos.monto"}');
check('ErrorPrograma: codigo, http, campo', ['invalido', 422, 'datos.monto', 'monto'], [$e->codigo, $e->http, $e->campo, $e->campoFormulario()]);
check('ErrorPrograma: el texto del programa tal cual', 'Escriba el monto con números, por ejemplo 39,00.', $e->getMessage());
check('Campo de extra (datos.extra.tipo -> tipo)', 'tipo', ErrorPrograma::desdeRespuesta(422, '{"ok":false,"codigo":"invalido","error":"x","campo":"datos.extra.tipo"}')->campoFormulario());
check('Campo raro se descarta', null, ErrorPrograma::desdeRespuesta(422, '{"ok":false,"codigo":"invalido","error":"x","campo":"<script>"}')->campo);
check('firma: texto fijo del panel (no el del programa)', ErrorPrograma::TEXTOS['firma'], ErrorPrograma::desdeRespuesta(401, '{"ok":false,"codigo":"firma","error":"lo que sea"}')->getMessage());
check('conflicto con detalle', 'en_curso', ErrorPrograma::desdeRespuesta(409, '{"ok":false,"codigo":"conflicto","error":"x","detalle":{"motivo":"en_curso"}}')->detalle['motivo']);
check('400 sin código (TuEvento v1: "Acción no válida.") = programa v1', true, ErrorPrograma::desdeRespuesta(400, '{"ok":false,"error":"Acción no válida."}')->esV1());
check('405 sin código = programa v1', 'v1', ErrorPrograma::desdeRespuesta(405, '')->codigo);
check('404 sin código (TuVocalía v1 o apagado) = no_conectado', 'no_conectado', ErrorPrograma::desdeRespuesta(404, 'No encontrado')->codigo);
check('Sin respuesta (tiempo) se puede Revisar', true, ErrorPrograma::desdeRespuesta(0, '', 28)->sinRespuesta());
check('Sin conexión se puede Revisar', true, ErrorPrograma::desdeRespuesta(0, '', 7)->sinRespuesta());
check('interno (el programa borró la operación) se puede Revisar', true, ErrorPrograma::desdeRespuesta(500, '{"ok":false,"codigo":"interno","error":"x"}')->sinRespuesta());
check('invalido NO es "sin respuesta"', false, $e->sinRespuesta());
check('500 sin JSON = interno', 'interno', ErrorPrograma::desdeRespuesta(502, '<html>Bad gateway</html>')->codigo);
check('Textos de v2 sin detalles técnicos ("Exception", "stack")', false, (bool) preg_grep('/exception|stack|sql/i', ErrorPrograma::TEXTOS));

// ---------------------------------------------------------------------------
titulo('13. ClienteV2: POST, cabecera X-Tukan-Token, tiempos y respuestas (sin red)');
$pedidos = [];
$respuestas = [];
$falso = function (string $url, array $cab, string $cuerpo, int $tiempo, int $max) use (&$pedidos, &$respuestas): array {
    $pedidos[] = compact('url', 'cab', 'cuerpo', 'tiempo', 'max');
    $r = array_shift($respuestas) ?? [200, '{"ok":true,"protocolo":2}'];
    if (is_callable($r)) $r = $r($cuerpo);
    return ['http' => $r[0], 'cuerpo' => $r[1], 'errno' => $r[2] ?? 0, 'error' => '', 'cabeceras' => [], 'ms' => 5];
};
$secV = $ins->crear(['id' => 'tuvocalia', 'producto' => 'tuvocalia', 'url' => 'https://tuvocalia.com', 'nombre' => 'TuVocalía']);
$iv = $ins->obtener('tuvocalia');
$cv = new ClienteV2($ins, $falso);
$respuestas[] = [200, '{"ok":true,"protocolo":2,"accion":"clientes","total":0,"clientes":[]}'];
$r = $cv->pedir($iv, 'clientes', ['pagina' => 1], ['email' => 'd@x.ec', 'nombre' => 'Dimitry', 'rol' => 'soporte']);
$pd = end($pedidos);
check('URL con accion', 'https://tuvocalia.com/tukan_central.php?accion=clientes', $pd['url']);
check('Token en la cabecera X-Tukan-Token (no en la URL)', [1, false], [count(preg_grep('/^X-Tukan-Token: [A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $pd['cab'])), str_contains($pd['url'], 't=')]);
check('Content-Type JSON', true, in_array('Content-Type: application/json; charset=utf-8', $pd['cab'], true));
$tk = substr((string) current(preg_grep('/^X-Tukan-Token: /', $pd['cab'])), 15);
$pl = Token::validarV2($tk, $secV, 'clientes', 'tuvocalia', $pd['cuerpo']);
check('El programa valida el token con el cuerpo exacto que llegó', ['d@x.ec', 'soporte'], [$pl['email'], $pl['rol']]);
check('Tiempos: lecturas 5 s, ejecutar 15 s, finanzas/adjunto 20 s', [5, 15, 20, 20], [ClienteV2::tiempo('clientes'), ClienteV2::tiempo('ejecutar'), ClienteV2::tiempo('finanzas'), ClienteV2::tiempo('adjunto')]);
check('Tamaño: 2 MiB, adjunto 8 MiB', [2097152, 8388608], [ClienteV2::maximo('clientes'), ClienteV2::maximo('adjunto')]);
check('Sin usuario: el correo de la revisión automática', 'revision@panel.tukanec.com', (function () use ($cv, $iv, &$pedidos, $secV) { $cv->pedir($iv, 'capacidades'); $p = end($pedidos); return Token::validarV2(substr((string) current(preg_grep('/^X-Tukan-Token: /', $p['cab'])), 15), $secV, 'capacidades', 'tuvocalia', $p['cuerpo'])['email']; })());
$respuestas[] = [401, '{"ok":false,"protocolo":2,"codigo":"firma","error":"x","detalle":{"parte":"cuerpo"}}'];
check_error('401 firma -> ErrorPrograma con texto simple', 'Cambiar secreto', fn() => $cv->pedir($iv, 'clientes'));
$respuestas[] = [200, '{"ok":true,"protocolo":1}'];
check_error('200 con protocolo 1 -> formato', 'formato', fn() => $cv->pedir($iv, 'clientes'));
$respuestas[] = [200, '{"ok":'];
check_error('200 con JSON roto -> formato', 'formato', fn() => $cv->pedir($iv, 'clientes'));
$respuestas[] = [0, '', 28];
check('Sin respuesta (curl 28) -> tiempo', 'tiempo', (function () use ($cv, $iv) { try { $cv->pedir($iv, 'clientes'); } catch (ErrorPrograma $e) { return $e->codigo; } return null; })());
check_error('Pedido de más de 64 KiB no se manda', '64 KiB', fn() => $cv->pedir($iv, 'clientes', ['buscar' => str_repeat('a', 70000)]));

// ---------------------------------------------------------------------------
titulo('14. Formularios genéricos (acciones_posibles y campos, §4.4)');
$campos = Formulario::limpiarCampos([
    ['campo' => 'monto', 'etiqueta' => 'Valor', 'tipo' => 'dinero', 'requerido' => true, 'min' => 0, 'cotizar' => true],
    ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'opciones' => [['valor' => '5', 'etiqueta' => 'Pro'], ['valor' => 6, 'etiqueta' => 'Torneo']]],
    ['campo' => 'dias', 'etiqueta' => 'Días', 'tipo' => 'dias', 'requerido' => true, 'min' => 1, 'max' => 90],
    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'max' => 10],
    ['campo' => 'correo', 'tipo' => 'email'],
    ['campo' => 'si', 'tipo' => 'si_no'],
    ['campo' => 'Mal Nombre', 'tipo' => 'texto'],
    ['campo' => 'raro', 'tipo' => 'html'],
]);
check('Campos con nombre inválido se descartan', ['monto', 'plan', 'dias', 'nota', 'correo', 'si', 'raro'], array_column($campos, 'campo'));
check('Tipo desconocido -> texto', 'texto', $campos[6]['tipo']);
check('Opción numérica se vuelve texto', '6', $campos[1]['opciones'][1]['valor']);
[$v, $err] = Formulario::leer($campos, ['monto' => '1.234,50', 'plan' => '6', 'dias' => '7', 'nota' => 'ok', 'si' => '1', 'correo' => 'A@B.EC']);
check('Leer: dinero "1.234,50" -> 1234.5', 1234.5, $v['monto']);
check('Leer: opción, días, sí/no, correo en minúsculas', ['6', 7, true, 'a@b.ec'], [$v['plan'], $v['dias'], $v['si'], $v['correo']]);
check('Leer: sin errores', [], $err);
check('Número: "39,50" / "39.50" / "$ 1.000" / "abc"', [39.5, 39.5, 1000.0, null], [Formulario::numero('39,50'), Formulario::numero('39.50'), Formulario::numero('$ 1.000'), Formulario::numero('abc')]);
[, $err] = Formulario::leer($campos, ['monto' => 'mucho', 'plan' => '9', 'dias' => '120', 'nota' => 'demasiado largo', 'correo' => 'no']);
check('Errores junto a cada campo', ['monto', 'plan', 'dias', 'nota', 'correo'], array_keys($err));
check('Error del dinero en palabras simples', 'Escribe el valor con números, por ejemplo 39,00.', $err['monto']);
[, $err] = Formulario::leer($campos, []);
check('Requeridos vacíos', ['monto', 'plan', 'dias'], array_keys($err));
check('Acción válida', 'registrar_pago', Formulario::limpiarAccion(['accion' => 'registrar_pago', 'etiqueta' => 'Registrar', 'estilo' => 'principal', 'datos' => ['cliente_id' => '123', 'x y' => 1]])['accion']);
check('Datos fijos con clave inválida se descartan', ['cliente_id' => '123'], Formulario::limpiarAccion(['accion' => 'x_y', 'datos' => ['cliente_id' => '123', 'x y' => 1, 'z' => ['a']]])['datos']);
check('Acción con nombre raro: no', null, Formulario::limpiarAccion(['accion' => 'rm -rf']));
check('WhatsApp sin número: no se muestra', null, Formulario::limpiarAccion(['accion' => 'whatsapp', 'whatsapp' => '12']));
check('WhatsApp: solo dígitos', '593991234567', Formulario::limpiarAccion(['accion' => 'whatsapp', 'whatsapp' => '+593 99 123 4567', 'texto' => 'Hola'])['whatsapp']);
check('Estilo desconocido -> normal', 'normal', Formulario::limpiarAccion(['accion' => 'x_y', 'estilo' => 'rosado'])['estilo']);
foreach (['/admin.php?p=ligas&editar=123' => true, '/?r=admin_negocio&id=5' => true, '//malo.com' => false, 'https://malo.com' => false, '/a\\b' => false, "/a\nb" => false, 'admin' => false, '/' . str_repeat('a', 300) => false] as $ir => $sirve) {
    check("ir " . json_encode($ir) . ($sirve ? " sirve" : " no sirve"), $sirve, Formulario::rutaValida($ir));
}
check('Abrir con ir malo: no se muestra', null, Formulario::limpiarAccion(['accion' => 'abrir', 'ir' => '//malo.com']));

// ---------------------------------------------------------------------------
titulo('15. Capacidades (§5.1)');
$capa = Capacidades::limpiar(['producto' => 'tuvocalia', 'acciones' => ['capacidades', 'clientes', 'ejecutar', 'mal nombre'], 'ejecutar' => ['crear_demo', 'registrar_pago'],
    'sso_ir' => true, 'rutas_sso' => ['/admin.php', '//malo'], 'limites' => ['por_pagina_max' => 5000], 'formularios' => ['crear_demo' => [['campo' => 'tipo', 'tipo' => 'texto']]]]);
check('Acciones revisadas', ['capacidades', 'clientes', 'ejecutar'], $capa['acciones']);
check('Rutas SSO: solo las válidas', ['/admin.php'], $capa['rutas_sso']);
check('Límite por página máximo 200', 200, $capa['limites']['por_pagina_max']);
check('Formulario propio de crear demo', 'tipo', $capa['formularios']['crear_demo'][0]['campo']);
check('puede: clientes / crear_demo / finanzas', [true, true, false], [Capacidades::puede($capa, 'clientes'), Capacidades::puede($capa, 'ejecutar', 'crear_demo'), Capacidades::puede($capa, 'finanzas')]);
check('Sin "ejecutar" en acciones no hay operaciones', [], Capacidades::limpiar(['acciones' => ['clientes'], 'ejecutar' => ['crear_demo']])['ejecutar']);
check('ir permitido solo dentro de rutas_sso', [true, false, false], [Capacidades::irPermitido($capa, '/admin.php?p=x'), Capacidades::irPermitido($capa, '/otra'), Capacidades::irPermitido(['sso_ir' => false] + $capa, '/admin.php')]);
check('sso_ir sin rutas = no', false, Capacidades::limpiar(['sso_ir' => true, 'rutas_sso' => []])['sso_ir']);
$capS = new Capacidades($db);
$capS->guardar('tuvocalia', 2, $capa);
check('Guardar y leer', [true, true], [$capS->esV2('tuvocalia'), $capS->soporta('tuvocalia', 'ejecutar', 'registrar_pago')]);
$respuestas[] = [400, '{"ok":false,"error":"Acción no válida."}'];
check('Programa v1 (400) queda como protocolo 1', 1, $capS->refrescar($ins->obtener('tuevento'), new ClienteV2($ins, $falso))['protocolo']);
$respuestas[] = [404, 'No encontrado'];
check('404 sin código con estado en línea = v1', 1, $capS->refrescar($ins->obtener('dogssy'), new ClienteV2($ins, $falso), null, true)['protocolo']);
$respuestas[] = [0, '', 7];
check_error('Caído: deja lo que había y avisa', 'Sin conexión', fn() => $capS->refrescar($iv, new ClienteV2($ins, $falso)));
check('Caído: siguió como v2', true, $capS->esV2('tuvocalia'));

// ---------------------------------------------------------------------------
titulo('16. Clientes: copia local, contacto cifrado, filtros y datos personales');
$hoy = date('Y-m-d');
$mk = fn(string $id, string $estado, ?int $vence, array $extra = []) => array_replace_recursive([
    'id' => $id, 'nombre' => "Liga $id", 'estado' => $estado, 'plan' => ['codigo' => '5', 'nombre' => 'Pro'],
    'fechas' => ['alta' => date('Y-m-d', strtotime('-20 days')), 'vence' => $vence === null ? null : date('Y-m-d', strtotime("$vence days"))],
    'dueno' => ['nombre' => "Dueño $id", 'telefono' => '09912345' . str_pad($id, 2, '0', STR_PAD_LEFT), 'whatsapp' => '5939912345' . str_pad($id, 2, '0', STR_PAD_LEFT), 'email' => "d$id@ejemplo.com"],
    'ultima_actividad' => date('c'), 'pagos' => ['total' => 0, 'cantidad' => 0], 'origen' => 'registro', 'ir' => "/admin.php?p=ligas&editar=$id",
], $extra);
$cl = new Clientes($db, $cif);
$l = Clientes::limpiar($mk('1', 'prueba', 3));
check('Limpiar: días para vencer calculados', 3, $l['fechas']['dias_para_vencer']);
check('Limpiar: id numérico se vuelve texto', '7', Clientes::limpiar(['id' => 7, 'nombre' => 'X'])['id']);
check('Limpiar: estado desconocido -> activo; origen raro -> desconocido', ['activo', 'desconocido'], array_values(array_intersect_key(Clientes::limpiar(['id' => 'a', 'nombre' => 'X', 'estado' => 'raro', 'origen' => 'x']), ['estado' => 1, 'origen' => 1])));
check('Limpiar: sin nombre no sirve', null, Clientes::limpiar(['id' => 'a']));
check('Limpiar: ir malo se quita', null, Clientes::limpiar(['id' => 'a', 'nombre' => 'X', 'ir' => 'https://malo'])['ir']);
$filasC = [$mk('1', 'prueba', 3), $mk('2', 'por_vencer', 6, ['pagos' => ['total' => 19.0, 'cantidad' => 1, 'primer_pago' => $hoy]]), $mk('3', 'activo', 40, ['ultima_actividad' => date('c', strtotime('-20 days')), 'pagos' => ['total' => 100.0]]),
           $mk('4', 'vencido', -2), $mk('5', 'suspendido', 10), $mk('6', 'activo', null)];
foreach ($filasC as $c) $cl->guardar('tuvocalia', Clientes::limpiar($c));
$fila = $cl->obtener('tuvocalia', '1');
check('Contacto guardado cifrado (ni teléfono ni correo en claro)', [false, false], [str_contains((string) $fila['contacto_cif'] . json_encode($fila['datos']), '0991234501'), str_contains((string) $fila['contacto_cif'] . json_encode($fila['datos']), 'd1@ejemplo.com')]);
check('El resto sin el contacto', false, array_key_exists('dueno', $fila['datos']));
check('Se descifra para el dueño', 'd1@ejemplo.com', $cl->contacto($fila)['email']);
check('Contexto atado al cliente (no se puede copiar de fila)', null, $cl->contacto(['instancia_id' => 'tuvocalia', 'cliente_id' => '2', 'contacto_cif' => $fila['contacto_cif']]));
$t = $cl->lista([], true);
check('Lista: los que vencen primero (sin fecha al final)', ['4', '1', '2', '5', '3', '6'], array_column($t['filas'], 'cliente_id'));
check('Contadores por estado', ['prueba' => 1, 'activo' => 2, 'por_vencer' => 1, 'vencido' => 1, 'suspendido' => 1], $t['contadores']);
check('Filtro estado', ['1'], array_column($cl->lista(['estados' => ['prueba']], true)['filas'], 'cliente_id'));
check('Se vence en 7 días (sin suspendidos ni vencidos)', ['1', '2'], array_column($cl->lista(['vence_en' => 7], true)['filas'], 'cliente_id'));
check('Sin usar hace 15 días', ['3'], array_column($cl->lista(['sin_uso' => 15], true)['filas'], 'cliente_id'));
check('Buscar por teléfono (dueño)', ['3'], array_column($cl->lista(['buscar' => '0991234503'], true)['filas'], 'cliente_id'));
check('Buscar por teléfono: soporte no puede', [], array_column($cl->lista(['buscar' => '0991234503'], false)['filas'], 'cliente_id'));
check('Buscar por nombre sin tildes ni mayúsculas', ['2'], array_column($cl->lista(['buscar' => 'LIGA 2'], false)['filas'], 'cliente_id'));
check('Soporte: sin contacto en la lista', null, $cl->lista([], false)['filas'][0]['contacto']);
(new Sincronizacion($db))->guardar('tuvocalia', 'clientes', time() + 5);
check('Los que no vinieron en la última lista completa no se muestran', 0, count($cl->todas('tuvocalia')));
(new Sincronizacion($db))->guardar('tuvocalia', 'clientes', time() - 60);
$cl->recordar('tuvocalia', '2', 'wa_recordar_renovacion', $uid);
check('Recordado: quién y cuándo', 'Dimitry Fierro', $cl->ultimosRecordatorios()['tuvocalia|2']['quien']);
check('Enmascarar teléfono (pantalla)', '09•••••67', Contacto::telefono('0991234567'));
check('Enmascarar teléfono (Telegram)', '099•••4567', Contacto::telefonoAviso('593991234567'));
check('Enmascarar correo', 'j•••@ejemplo.com', Contacto::email('juan@ejemplo.com'));
check('Enmascarar dentro de un texto', 'Juan P <j•••@ejemplo.com> 09•••••12', Contacto::enmascararTexto('Juan P <juan@ejemplo.com> 0998765412'));
check('Número de WhatsApp desde 09…', '593991234567', Contacto::numeroWhatsapp('099 123 4567'));
check('Plantilla', 'Hola Juan, Liga 1 vence el 20/10/2026.', Contacto::plantilla('Hola {nombre}, {negocio} vence el {vence}.', ['nombre' => 'Juan', 'negocio' => 'Liga 1', 'vence' => '20/10/2026']));
check('Plantilla según estado', ['wa_recordar_prueba', 'wa_recordar_renovacion', 'wa_vencido', 'wa_recordar_prueba', null],
      [Contacto::plantillaPara(['estado' => 'prueba']), Contacto::plantillaPara(['estado' => 'por_vencer']), Contacto::plantillaPara(['estado' => 'vencido', 'total_pagado' => 10]),
       Contacto::plantillaPara(['estado' => 'vencido', 'total_pagado' => 0]), Contacto::plantillaPara(['estado' => 'suspendido'])]);
check('Enlace de WhatsApp aceptado / rechazado', [true, false], [Contacto::esUrlWhatsapp('https://wa.me/593991234567?text=Hola%20Juan'), Contacto::esUrlWhatsapp('https://malo.com/wa.me')]);

// ---------------------------------------------------------------------------
titulo('17. Solicitudes: bandeja, nuevas y cerradas, datos personales y avisos');
$so = new Solicitudes($db, $cif);
$solic = fn(string $id, string $tipo, string $cat = 'pedido', array $x = []) => array_replace([
    'id' => $id, 'tipo' => $tipo, 'categoria' => $cat, 'titulo' => 'T ' . $id, 'fecha' => date('c'), 'cliente_id' => '1', 'estado' => 'pendiente', 'prioridad' => 'normal',
    'quien' => ['nombre' => 'Juan Pérez', 'organizacion' => 'Liga Norte', 'telefono' => '0991234567', 'whatsapp' => '593991234567', 'email' => 'juan@ejemplo.com'],
    'resumen' => 'Plan Pro · 6 meses · $95,00', 'detalle' => ['enviado_por' => 'Juan Pérez <juan@ejemplo.com>', 'ciudad' => 'Quito'],
    'acciones_posibles' => [['accion' => 'aprobar_comprobante', 'etiqueta' => 'Aprobar', 'estilo' => 'principal', 'datos' => ['solicitud_id' => $id]],
                            ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'whatsapp' => '593991234567', 'texto' => 'Hola Juan']],
], $x);
$r = $so->guardarAbiertas('tuvocalia', [$solic('comprobante:45', 'comprobante'), $solic('demo_pedida:12', 'demo_pedida'), $solic('renovacion:3:vence_pronto', 'renovacion', 'recordatorio')], null, false);
check('Primera vez: 3 guardadas', 3, count($r['nuevas']));
check('Primera vez: no se avisa lo viejo', 0, count($so->porAvisar(Sincronizador::TIPOS_AVISO_PREDETERMINADOS)));
$fs = $so->obtener('tuvocalia', 'comprobante:45');
check('Quién, detalle y WhatsApp cifrados (no en claro)', [false, false, false], [str_contains($fs['datos'], 'juan@ejemplo.com'), str_contains($fs['datos'], '0991234567'), str_contains($fs['datos'], 'Hola Juan')]);
$c1 = $so->completa($fs, true);
check('Dueño: ve teléfono y el botón de WhatsApp', ['0991234567', ['aprobar_comprobante', 'whatsapp']], [$c1['quien']['telefono'], array_column($c1['acciones'], 'accion')]);
$c2 = $so->completa($fs, false);
check('Soporte: teléfono escondido y sin WhatsApp', ['09•••••67', 'j•••@ejemplo.com', ['aprobar_comprobante']], [$c2['quien']['telefono'], $c2['quien']['email'], array_column($c2['acciones'], 'accion')]);
check('Soporte: correos escondidos también en el detalle', 'Juan Pérez <j•••@ejemplo.com>', $c2['detalle']['enviado_por']);
$r = $so->guardarAbiertas('tuvocalia', [$solic('comprobante:45', 'comprobante'), $solic('soporte:9', 'soporte', 'pedido', ['prioridad' => 'alta']), $solic('renovacion:3:vencida', 'renovacion', 'recordatorio')]);
check('Segunda vez: nuevas', ['soporte:9', 'renovacion:3:vencida'], $r['nuevas']);
check('Las que ya no vienen se cierran', [2, 'cerrada'], [$r['cerradas'], $so->obtener('tuvocalia', 'demo_pedida:12')['estado']]);
$bandeja = $so->lista(['pestana' => 'pendiente'], true);
check('Bandeja: urgentes primero, comprobantes antes que soporte', ['soporte:9', 'comprobante:45', 'renovacion:3:vencida'], array_column($bandeja['filas'], 'id'));
check('Pestañas con número', ['pendiente' => 3, 'en_proceso' => 0, 'atendidas' => 2], $bandeja['pestanas']);
check('Chip Pagos por aprobar', ['comprobante:45'], array_column($so->lista(['chip' => 'pagos'], true)['filas'], 'id'));
check('Chip Recordatorios', ['renovacion:3:vencida'], array_column($so->lista(['chip' => 'recordatorios'], true)['filas'], 'id'));
check('Buscar por teléfono (dueño sí, soporte no)', [3, 0], [count($so->lista(['buscar' => '0991234567'], true)['filas']), count($so->lista(['buscar' => '0991234567'], false)['filas'])]);
check('Número rojo: por atender', 3, $so->porAtender());
$tipos = Sincronizador::TIPOS_AVISO_PREDETERMINADOS;
check('Por avisar: el pedido nuevo (no el recordatorio)', ['soporte:9'], array_column($so->porAvisar($tipos), 'solicitud_id'));
$so->marcarNoAvisables($tipos);
check('Recordatorio no avisable queda marcado', '1', (string) $so->obtener('tuvocalia', 'renovacion:3:vencida')['avisada']);
$txt = $so->textoAviso($so->porAvisar($tipos)[0], 'https://panel.tukanec.com');
check('Aviso de Telegram: teléfono escondido, sin correo', [true, false, false], [str_contains($txt, '099•••4567'), str_contains($txt, '0991234567'), str_contains($txt, 'juan@')]);
check('Aviso de Telegram: enlace a la solicitud', true, str_contains($txt, 'Abrir: https://panel.tukanec.com/?r=solicitud&i=tuvocalia&id=soporte%3A9'));
check('Aviso: ticket urgente', true, str_contains($txt, 'Ticket urgente'));
check('Tipo "pedido" que el panel no conoce: se avisa igual', true, Solicitudes::avisable(['tipo' => 'nuevo_tipo', 'categoria' => 'pedido'], $tipos));
check('Horario de silencio 22:00-07:00 (cruza la medianoche)', [true, true, false, false], [
    Sincronizador::enSilencio('22:00', '07:00', strtotime('today 23:30')), Sincronizador::enSilencio('22:00', '07:00', strtotime('today 06:59')),
    Sincronizador::enSilencio('22:00', '07:00', strtotime('today 07:00')), Sincronizador::enSilencio('22:00', '07:00', strtotime('today 12:00'))]);
check('Cifras que no coinciden con la lista -> pedir clientes', [true, false, false], [
    Sincronizador::noCoinciden(['total' => 12, 'prueba' => 4], ['total' => 11, 'prueba' => 4]), Sincronizador::noCoinciden(['total' => 12, 'activos' => 6], ['total' => 12, 'activo' => 6]),
    Sincronizador::noCoinciden(null, ['total' => 1])]);

// ---------------------------------------------------------------------------
titulo('18. Operaciones: misma clave al reintentar, "en curso", claves de un solo uso');
$ops = new Operaciones($db, new ClienteV2($ins, $falso), $cif);
$ops->espera = 0;
$vistos = [];
$respuestas = [
    [409, '{"ok":false,"protocolo":2,"codigo":"conflicto","error":"en curso","detalle":{"motivo":"en_curso"}}'],
    function (string $cuerpo) use (&$vistos) { $vistos[] = $cuerpo; return [200, json_encode(['ok' => true, 'protocolo' => 2, 'operacion' => 'registrar_pago', 'repetida' => false, 'mensaje' => 'Pago registrado. Liga 2 queda activa hasta el 2027-04-13.',
        'resultado' => ['pago_id' => '89', 'activo_hasta' => '2027-04-13'], 'cliente' => $GLOBALS['mk']('2', 'activo', 180)])]; },
];
$GLOBALS['mk'] = $mk;
$op = Operaciones::nuevaId();
$res = $ops->ejecutar($iv, 'registrar_pago', $op, '2', ['plan' => '5', 'periodo' => 'semestral', 'monto' => 95.0], ['id' => $uid, 'email' => 'd@x.ec', 'nombre' => 'D', 'rol' => 'dueno'], 'Liga 2');
check('En curso: espera y reintenta solo (misma operación)', true, $res['ok']);
$ultimos = array_slice($pedidos, -2);
check('Los dos intentos con el MISMO cuerpo (misma operacion_id) y tokens distintos', [true, true],
      [$ultimos[0]['cuerpo'] === $ultimos[1]['cuerpo'], $ultimos[0]['cab'] !== $ultimos[1]['cab']]);
check('El cuerpo lleva operacion_id, accion, cliente_id y datos', ['operacion_id' => $op, 'accion' => 'registrar_pago', 'cliente_id' => '2'], array_intersect_key(json_decode($vistos[0], true), ['operacion_id' => 1, 'accion' => 1, 'cliente_id' => 1]));
check('Operación guardada como ok', 'ok', $ops->obtener($op)['estado']);
check('Copia del cliente actualizada con lo que devolvió', 'activo', $cl->obtener('tuvocalia', '2')['estado']);
check('Resumen para la auditoría (sin datos personales)', 'Registrar pago · Liga 2 · plan 5 · periodo semestral · $95,00', $res['resumen']);
$respuestas[] = [0, '', 28];
$op2 = Operaciones::nuevaId();
$res = $ops->ejecutar($iv, 'registrar_pago', $op2, '2', ['monto' => 19.0], ['id' => $uid, 'email' => 'd@x.ec', 'nombre' => 'D'], 'Liga 2');
check('No contestó: sin_respuesta (se puede Revisar)', [false, true, 'sin_respuesta'], [$res['ok'], $res['sin_respuesta'], $ops->obtener($op2)['estado']]);
$respuestas[] = [200, json_encode(['ok' => true, 'protocolo' => 2, 'repetida' => true, 'mensaje' => 'Pago registrado.', 'resultado' => []])];
$res = $ops->ejecutar($iv, 'registrar_pago', $op2, '2', ['monto' => 19.0], ['id' => $uid, 'email' => 'd@x.ec', 'nombre' => 'D'], 'Liga 2');
check('Revisar = la misma operación: el programa dice "repetida"', [true, true, 'ok'], [$res['ok'], $res['respuesta']['repetida'], $ops->obtener($op2)['estado']]);
$ej = array_values(array_filter($pedidos, fn($p) => str_ends_with($p['url'], 'accion=ejecutar')));
check('Revisar manda el mismo cuerpo', true, $ej[count($ej) - 1]['cuerpo'] === $ej[count($ej) - 2]['cuerpo']);
check('Sin "cliente" en la respuesta: pide solo ese cliente (ids)', ['2'], json_decode(end($pedidos)['cuerpo'], true)['ids'] ?? null);
$respuestas[] = [200, json_encode(['ok' => true, 'protocolo' => 2, 'mensaje' => 'Demo creada. Contraseña k8Ff2pQx7m', 'resultado' => ['cliente_id' => '124', 'prueba_hasta' => '2026-10-20',
    'acceso' => ['usuario' => 'n@x.ec', 'clave' => 'k8Ff2pQx7m'], 'mensaje_whatsapp' => 'Su clave: k8Ff2pQx7m']])];
$op3 = Operaciones::nuevaId();
$res = $ops->ejecutar($iv, 'crear_demo', $op3, null, ['nombre' => 'Liga Nueva', 'contacto_nombre' => 'Ana', 'plan' => '5'], ['id' => $uid, 'email' => 'd@x.ec', 'nombre' => 'D']);
check('Crear demo: la clave llega a la pantalla', 'k8Ff2pQx7m', $res['respuesta']['resultado']['acceso']['clave']);
check('Crear demo: la clave NO se guarda en la base', 0, (int) $db->query("SELECT count(*) FROM operaciones WHERE mensaje LIKE '%k8Ff2pQx7m%' OR resumen LIKE '%k8Ff2pQx7m%'")->fetchColumn());
check('Crear demo: queda en el historial de demos', ['Liga Nueva', '2026-10-20'], array_values($db->query("SELECT nombre, prueba_hasta FROM demos WHERE cliente_id = '124'")->fetch(PDO::FETCH_NUM)));
check('Quitar claves de un texto', 'Su clave: ••••••', Operaciones::sinClaves('Su clave: abc123XYZ', ['acceso' => ['clave' => 'abc123XYZ']]));
$respuestas[] = [422, '{"ok":false,"protocolo":2,"codigo":"invalido","error":"Ese correo ya tiene una cuenta.","campo":"datos.email"}'];
$res = $ops->ejecutar($iv, 'crear_demo', Operaciones::nuevaId(), null, ['nombre' => 'X'], ['id' => $uid, 'email' => 'd@x.ec', 'nombre' => 'D']);
check('Error de datos: no es "sin respuesta" y trae el campo', [false, 'email'], [$res['sin_respuesta'], $res['error']->campoFormulario()]);
check('Permisos de cada operación (§9.3)', ['atender', 'atender', 'demos', 'demos', 'cobros', 'cobros', 'cobros'],
      array_map([Operaciones::class, 'permiso'], ['atender_solicitud', 'responder_ticket', 'crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'operacion_nueva_rara']));
check('anular_pago y guardar_plan piden el código de 2 pasos', ['anular_pago', 'guardar_plan'], Operaciones::PIDEN_2FA);
check('Soporte: atiende pero no cobra ni ve contactos ni plata', [true, false, false, false, false],
      [Acceso::puede('soporte', 'atender'), Acceso::puede('soporte', 'cobros'), Acceso::puede('soporte', 'contacto'), Acceso::puede('soporte', 'finanzas'), Acceso::puede('soporte', 'demos')]);
check('Dueño: todo', true, Acceso::puede('dueno', 'cobros') && Acceso::puede('dueno', 'contacto') && Acceso::puede('dueno', 'finanzas'));

// ---------------------------------------------------------------------------
titulo('19. Finanzas: ingresos − gastos = ganancia, por mes y por programa; gastos fijos');
$fin = new Finanzas($db);
$mesA = date('Y-m');
$mesB = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
$ins->crear(['id' => 'tusalon', 'producto' => 'tusalon', 'url' => 'https://tusalon.tukanec.com', 'nombre' => 'TuSalón']);
$guardaMes = function (string $inst, string $mes, float $ing, float $otros, float $costos) use ($db) {
    $d = Finanzas::limpiarMes(['mes' => $mes, 'ingresos' => $ing, 'pagos' => 3, 'otros_ingresos' => $otros ? [['concepto' => 'gestion_cobros', 'nombre' => 'Gestión', 'monto' => $otros]] : [],
        'costos' => $costos ? [['concepto' => 'comision_pasarela', 'categoria' => 'comisiones_pasarela', 'nombre' => 'Plux', 'monto' => $costos]] : [],
        'por_plan' => [['plan' => '5', 'nombre' => 'Pro', 'monto' => $ing, 'pagos' => 3]], 'por_metodo' => [['metodo' => 'transferencia', 'nombre' => 'Transferencia', 'monto' => $ing, 'pagos' => 3]]]);
    $db->prepare('INSERT OR REPLACE INTO finanzas_mes (instancia_id, mes, datos, consultado) VALUES (?,?,?,?)')->execute([$inst, $mes, json_encode($d), time()]);
};
$guardaMes('tuvocalia', $mesA, 300.0, 4.1, 2.0);
$guardaMes('tuvocalia', $mesB, 100.0, 0, 0);
$guardaMes('tusalon', $mesA, 100.0, 0, 0);
check('limpiarMes: categoría desconocida -> otros', 'otros', Finanzas::limpiarMes(['mes' => '2026-10', 'costos' => [['categoria' => 'cosas', 'monto' => 1]]])['costos'][0]['categoria']);
check('limpiarMes: mes malo no sirve', null, Finanzas::limpiarMes(['mes' => '2026-13']));
$fin->guardarGasto(['fecha' => "$mesA-05", 'categoria' => 'servidores', 'descripcion' => 'VPS de TuVocalía', 'monto' => '20,00', 'instancia_id' => 'tuvocalia'], $uid);
$fin->guardarGasto(['fecha' => "$mesA-10", 'categoria' => 'publicidad', 'descripcion' => 'Facebook', 'monto' => '40'], $uid);
$idAnular = $fin->guardarGasto(['fecha' => "$mesA-11", 'categoria' => 'otros', 'descripcion' => 'Error', 'monto' => '999'], $uid);
$fin->anular('gastos', $idAnular);
$fin->guardarIngresoExtra(['fecha' => "$mesA-02", 'concepto' => 'Instalación', 'monto' => '50'], $uid);
check_error('Gasto sin categoría', 'categoría', fn() => $fin->guardarGasto(['fecha' => "$mesA-01", 'descripcion' => 'x y', 'monto' => '1'], $uid));
check_error('Gasto con valor negativo', 'números', fn() => $fin->guardarGasto(['fecha' => "$mesA-01", 'categoria' => 'otros', 'descripcion' => 'x y', 'monto' => '-5'], $uid));
check_error('Gasto de un programa que no existe', 'no existe', fn() => $fin->guardarGasto(['fecha' => "$mesA-01", 'categoria' => 'otros', 'descripcion' => 'x y', 'monto' => '5', 'instancia_id' => 'nada'], $uid));
$r = $fin->resumen([$mesB, $mesA], false);
$pv = $r['programas']['tuvocalia']['meses'][$mesA];
check('TuVocalía este mes: 300 + 4,10 − 2 − 20 = 282,10', 282.1, $pv['ganancia']);
check('TuSalón este mes: 100', 100.0, $r['programas']['tusalon']['meses'][$mesA]['ganancia']);
check('Total ingresos del mes: 300 + 4,10 + 100 + 50 (extra) = 454,10', 454.1, $r['total']['meses'][$mesA]['ingresos']);
check('Total gastos del mes: 2 (programa) + 20 + 40 = 62 (el anulado no cuenta)', 62.0, $r['total']['meses'][$mesA]['gastos']);
check('Ganancia del mes = ingresos − gastos = 392,10', 392.1, $r['total']['meses'][$mesA]['ganancia']);
check('Ganancia del período (2 meses) = 492,10', 492.1, $r['total']['total']['ganancia']);
check('Gastos generales sin repartir', [40.0, 50.0], [$r['general']['meses'][$mesA]['gastos'], $r['general']['meses'][$mesA]['extra']]);
$rr = $fin->resumen([$mesB, $mesA], true);
check('Repartir: TuVocalía (304,10 de 404,10) se lleva 30,10 de los 40', 30.1, $rr['programas']['tuvocalia']['meses'][$mesA]['parte_general']);
check('Repartir: TuSalón se lleva 9,90', 9.9, $rr['programas']['tusalon']['meses'][$mesA]['parte_general']);
check('Repartir no cambia la ganancia total', $r['total']['total']['ganancia'], $rr['total']['total']['ganancia']);
check('Repartir: la suma de las partes = gastos generales', 40.0, round($rr['programas']['tuvocalia']['meses'][$mesA]['parte_general'] + $rr['programas']['tusalon']['meses'][$mesA]['parte_general'], 2));
check('Mes sin ingresos: los gastos generales no se reparten', 0.0, $rr['programas']['tuvocalia']['meses'][$mesB]['parte_general'] + $rr['general']['meses'][$mesB]['repartido']);
$det = $fin->detallePrograma('tuvocalia', [$mesB, $mesA]);
check('Detalle por plan suma los meses', 400.0, $det['por_plan']['5']['monto']);
check('Costos informados del mes (para no anotarlos dos veces)', [['Plux', 'TuVocalía']], array_map(fn($c) => [$c['nombre'], $c['programa']], $fin->costosInformados($mesA)));
// Gastos fijos
$idF = $fin->guardarGasto(['fecha' => "$mesB-15", 'categoria' => 'servidores', 'descripcion' => 'Hosting fijo', 'monto' => '10', 'recurrente' => true, 'dia' => '15'], $uid);
$rec = $db->query("SELECT * FROM recurrentes WHERE descripcion = 'Hosting fijo'")->fetch();
check('Gasto fijo: queda el fijo y el gasto del primer mes', [$mesB, 15, (int) $rec['id']], [$rec['desde_mes'], (int) $rec['dia'], (int) $db->query("SELECT recurrente_id FROM gastos WHERE id = $idF")->fetchColumn()]);
check('Crea el del mes siguiente una sola vez', [1, 0], [$fin->generarFijos($mesA), $fin->generarFijos($mesA)]);
check('Con la fecha del día del fijo', "$mesA-15", $db->query("SELECT fecha FROM gastos WHERE recurrente_id = {$rec['id']} AND mes = '$mesA'")->fetchColumn());
check('No se crea antes de su primer mes', 0, $fin->generarFijos(date('Y-m', strtotime("$mesB-01 -1 month"))));
$fin->cambiarRecurrente((int) $rec['id'], 'monto', 12.5);
check('Cambiar valor: también el de este mes', 12.5, (float) $db->query("SELECT monto FROM gastos WHERE recurrente_id = {$rec['id']} AND mes = '$mesA'")->fetchColumn());
$fin->cambiarRecurrente((int) $rec['id'], 'pausar');
$mesC = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
check('Pausado: no se crea', 0, $fin->generarFijos($mesC));
$fin->cambiarRecurrente((int) $rec['id'], 'reanudar');
$fin->cambiarRecurrente((int) $rec['id'], 'terminar');
check('Terminado: no se crea en los meses que vienen', [0, $mesA], [$fin->generarFijos($mesC), $db->query("SELECT hasta_mes FROM recurrentes WHERE id = {$rec['id']}")->fetchColumn()]);
$fin->guardarIngresoExtra(['fecha' => "$mesA-01", 'concepto' => 'Mantenimiento mensual', 'monto' => '30', 'recurrente' => true, 'dia' => '1', 'instancia_id' => 'tusalon'], $uid);
check('Ingreso fijo asignado a un programa entra en su ganancia', 130.0, $fin->resumen([$mesA], false)['programas']['tusalon']['meses'][$mesA]['ganancia']);
check('Meses de un período', [12, 24, (int) date('n')], [count(Finanzas::meses('12')), count(Finanzas::meses('24')), count(Finanzas::meses('anio'))]);
check('Últimos 12 meses terminan en este mes', $mesA, end(Finanzas::meses('12')) ?: null);
// Licencias (TuGuardería)
$lic = new Licencias($db, $cif);
$lic->guardar('dogssy', 40.0, null, ['nombre' => 'Gabriela', 'telefono' => '0998877665', 'email' => 'g@dogssy.ec'], 'Paga por transferencia');
check('Licencia: contacto cifrado', [false, '0998877665'], [str_contains((string) $db->query("SELECT contacto_cif FROM licencias WHERE instancia_id = 'dogssy'")->fetchColumn(), '0998877665'), $lic->obtener('dogssy')['contacto']['telefono']]);
$rp = $lic->registrarPago($ins->obtener('dogssy'), 120.0, 3, date('Y-m-d'), 'Transferencia', $uid);
check('Pago de licencia: fecha + 3 meses desde hoy', date('Y-m-d', strtotime('+3 months')), $rp['activo_hasta']);
check('Pago de licencia: segundo pago suma desde la fecha que tenía', date('Y-m-d', strtotime('+4 months')), $lic->registrarPago($ins->obtener('dogssy'), 40.0, 1, date('Y-m-d'), 'Transferencia', $uid)['activo_hasta']);
check('Pago de licencia: queda como ingreso extra del programa', 160.0, (float) $db->query("SELECT sum(monto) FROM ingresos_extra WHERE instancia_id = 'dogssy'")->fetchColumn());
check('MRR suma las licencias vigentes', true, $fin->mrr() >= 40.0);
$cu = $lic->aplicar('dogssy', Clientes::limpiar(['id' => 'unico', 'nombre' => 'Dogssy']));
check('El cliente único toma la licencia (estado, vence, valor)', ['activo', date('Y-m-d', strtotime('+4 months')), 40.0], [$cu['estado'], $cu['fechas']['vence'], $cu['mensual_estimado']]);
check('Sin fecha de licencia: activo, sin vencer', ['activo', null], (function () use ($db, $cif) { $l = new Licencias($db, $cif); $c = $l->aplicar('nadie', Clientes::limpiar(['id' => 'unico', 'nombre' => 'X'])); return [$c['estado'], $c['fechas']['vence']]; })());

// ---------------------------------------------------------------------------
titulo('20. Descargas: CSV para Excel en español y Excel (.xlsx)');
$ex = new Exportar($db, $fin);
$csv = $ex->csv([$mesB, $mesA], false);
check('CSV con BOM UTF-8', "\xEF\xBB\xBF", substr($csv, 0, 3));
check('CSV separado por ; con encabezados', 'Tipo;Mes;Fecha;Programa;Categoría o concepto;Descripción;Monto', explode("\r\n", substr($csv, 3))[0]);
check('CSV: decimales con coma, gastos en negativo', true, str_contains($csv, "Gasto;$mesA;$mesA-05;TuVocalía;Servidores/VPS;VPS de TuVocalía;-20"));
check('CSV: ingreso del programa', true, str_contains($csv, "Ingreso del programa;$mesA;;TuVocalía;Suscripciones;;300"));
check('CSV: 4,1 con coma', true, str_contains($csv, ';4,1'));
check('CSV: los anulados no salen', false, str_contains($csv, 'Error;'));
check('CSV: evita fórmulas (=, +, @)', "'=1+1;\"a;b\";'@x\r\n", Exportar::lineaCsv(['=1+1', 'a;b', '@x']));
check('CSV: número 1234.5 -> 1234,5', '1234,5', Exportar::numeroCsv(1234.5));
$x = $ex->xlsx([$mesB, $mesA], false);
check('Excel: es un ZIP', "PK\x03\x04", substr($x, 0, 4));
file_put_contents("$tmp/f.xlsx", $x);
if (class_exists('ZipArchive')) {
    $z = new ZipArchive();
    check('Excel: se abre con ZipArchive', true, $z->open("$tmp/f.xlsx") === true);
    $hojas = (string) $z->getFromName('xl/workbook.xml');
    check('Excel: hojas Resumen, Por programa, Ingresos, Gastos, Ingresos extra', 5, preg_match_all('/<sheet name="(Resumen|Por programa|Ingresos|Gastos|Ingresos extra)"/', $hojas));
    check('Excel: el gasto está en la hoja Gastos con su número', true, str_contains((string) $z->getFromName('xl/worksheets/sheet4.xml'), 'VPS de TuVocalía') && str_contains((string) $z->getFromName('xl/worksheets/sheet4.xml'), '<v>20</v>'));
    $z->close();
}
$py = trim((string) shell_exec("python3 -c 'import openpyxl; print(1)' 2>/dev/null"));
if ($py === '1') {
    $out = trim((string) shell_exec('python3 -c ' . escapeshellarg('import openpyxl,sys; wb=openpyxl.load_workbook(sys.argv[1]); ws=wb["Resumen"]; print(len(wb.sheetnames), ws.cell(1,1).value, round(ws.cell(len(list(ws.rows))-1,6).value,2))') . ' ' . escapeshellarg("$tmp/f.xlsx") . ' 2>&1'));
    check('Excel: lo abre openpyxl (5 hojas, ganancia total)', '5 Mes ' . $fin->resumen([$mesB, $mesA], false)['total']['total']['ganancia'], $out);
}
check('Columna de Excel 0 -> A, 25 -> Z, 26 -> AA', ['A', 'Z', 'AA'], [Xlsx::columna(0), Xlsx::columna(25), Xlsx::columna(26)]);
@unlink("$tmp/f.xlsx");

// ---------------------------------------------------------------------------
titulo('21. Demos, embudo y vencimientos (con la copia local)');
$db->exec("DELETE FROM clientes");
$db->exec("DELETE FROM demos");
(new Sincronizacion($db))->guardar('tuvocalia', 'clientes', time() - 60);
foreach ([
    $mk('11', 'prueba', 2, ['origen' => 'central', 'fechas' => ['alta' => date('Y-m-d')]]),
    $mk('12', 'activo', 30, ['origen' => 'demo_pedida', 'fechas' => ['alta' => date('Y-m-d')], 'pagos' => ['total' => 19.0, 'cantidad' => 1, 'primer_pago' => date('Y-m-d'), 'ultimo_monto' => 19.0], 'mensual_estimado' => 19.0]),
    $mk('13', 'vencido', -5, ['origen' => 'registro', 'fechas' => ['alta' => date('Y-m-d', strtotime('-20 days'))]]),
    $mk('14', 'activo', 40, ['origen' => 'super', 'ultima_actividad' => date('c', strtotime('-30 days')), 'pagos' => ['total' => 50.0, 'cantidad' => 2, 'primer_pago' => date('Y-m-d', strtotime('-60 days'))]]),
] as $c) $cl->guardar('tuvocalia', Clientes::limpiar($c));
$db->exec("INSERT INTO demos (instancia_id, cliente_id, nombre, creada, prueba_hasta) VALUES ('tuvocalia', '12', 'Liga 12', " . time() . ", NULL)");
check('Demo creada desde el Central que pagó: se llena sola', [['nombre' => 'Liga 12', 'monto' => 19.0, 'instancia_id' => 'tuvocalia', 'primer_pago' => date('Y-m-d')]], $cl->vincularDemos('tuvocalia'));
check('...y solo una vez', [], $cl->vincularDemos('tuvocalia'));
$an = new Analisis($db, $cl);
$dm = $an->demos(null);
$idsDemo = array_column($dm['filas'], 'cliente_id');
sort($idsDemo);
check('Demos: central, pedida y registro (no las del súper)', ['11', '12', '13'], $idsDemo);
check('Demos este mes: 2, pagó 1 (50 %)', ['demos' => 2, 'pagaron' => 1, 'porcentaje' => 50], $dm['mes']);
check('Estado de cada demo', ['11' => 'prueba', '12' => 'pago', '13' => 'sin_pagar'], array_column($dm['filas'], 'estado_demo', 'cliente_id'));
$so->guardarUna('tuvocalia', Solicitudes::limpiar($solic('demo_pedida:77', 'demo_pedida')));
$em = $an->embudo(null, [date('Y-m')]);
check('Embudo del mes: interesados 3 (2 cuentas + 1 pedido), creadas 2, pagaron 1, siguen 1', [3, 2, 1, 1],
      array_values(array_intersect_key($em['meses'][date('Y-m')], ['interesados' => 1, 'creadas' => 1, 'pagaron' => 1, 'siguen' => 1])));
check('Pruebas que terminaron sin pagar', ['13'], array_column($em['sin_pagar'], 'cliente_id'));
check('Clientes en riesgo (pagan y no usan hace 15 días)', ['14'], array_column($em['en_riesgo'], 'cliente_id'));
$vc = $an->vencimientos(null, date('Y-m', strtotime('+2 days')));
check('Vencimientos: la prueba sale en amarillo en su día', 'amarillo', $vc['dias'][date('Y-m-d', strtotime('+2 days'))][0]['color'] ?? null);
check('Vencimientos: esta semana 1 · ya vencieron 1', [1, 1], [$vc['semana']['n'], count($vc['vencidos'])]);

// ---------------------------------------------------------------------------
titulo('22. Base de datos: migración a la versión 2');
check('user_version = 2', 2, (int) $db->query('PRAGMA user_version')->fetchColumn());
$tablas = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach (['capacidades', 'clientes', 'solicitudes', 'operaciones', 'demos', 'recordatorios', 'finanzas_mes', 'gastos', 'ingresos_extra', 'recurrentes', 'licencias', 'sincronizacion'] as $tb) {
    check("Tabla $tb", true, in_array($tb, $tablas, true));
}
$vieja = "$tmp/vieja.sqlite";
$pv1 = new PDO("sqlite:$vieja");
$pv1->exec('CREATE TABLE ajustes (clave TEXT PRIMARY KEY, valor TEXT NOT NULL); PRAGMA user_version = 1;');
$pv1 = null;
$migrada = Db::conectar($vieja);
check('Una base v1 se migra sola a v2', 2, (int) $migrada->query('PRAGMA user_version')->fetchColumn());
$migrada = null;
@unlink($vieja);
check('Al quitar un programa se borra su copia (ON DELETE CASCADE)', 0, (function () use ($ins, $db) { $ins->eliminar('tuvocalia'); return (int) $db->query("SELECT count(*) FROM clientes WHERE instancia_id = 'tuvocalia'")->fetchColumn(); })());
check('Ajustes v2 de fábrica', ['22:00', '07:00', '1', '0'], [(new Ajustes($db))->valor('silencio_desde'), (new Ajustes($db))->valor('silencio_hasta'), (new Ajustes($db))->valor('resumen_diario'), (new Ajustes($db))->valor('repartir_gastos_generales')]);
