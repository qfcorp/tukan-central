<?php
declare(strict_types=1);

/**
 * Pruebas de lógica: borrar una instalación desde el panel (Bajas) y Cloudflare (contra un Cloudflare DE MENTIRA:
 * tests/mock/cloudflare.php con "php -S", nunca el real). Las incluye tests/run_tests.php (usa $db, $cif, $ins, $tmp, $uid).
 */

use Tukan\{Acceso, Ajustes, Bajas, Capacidades, ClienteV2, Cloudflare, ErrorPrograma, Operaciones};

// ---------------------------------------------------------------------------
titulo('23. Cloudflare: quitar solo la dirección exacta (servidor de mentira)');
$cfDir = "$tmp/cf";
@mkdir($cfDir, 0700, true);
$CF_PUERTO = 8258;
$CF_TOKEN = 'tok_' . bin2hex(random_bytes(16));
$CF_CUENTA = bin2hex(random_bytes(16));
$CF_TUNEL = '1a2b3c4d-1111-2222-3333-444455556666';
$cfProc = proc_open(['php', '-S', "127.0.0.1:$CF_PUERTO", dirname(__DIR__) . '/tests/mock/cloudflare.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$cfDir/servidor.log", 'a'], 2 => ['file', "$cfDir/servidor.log", 'a']],
    $tuberias, null, ['PATH' => getenv('PATH'), 'MOCK_DIR' => $cfDir, 'CF_TOKEN' => $CF_TOKEN, 'CF_CUENTA' => $CF_CUENTA, 'CF_TUNEL' => $CF_TUNEL]);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $CF_PUERTO)) break; usleep(100000); }
$cfBase = "http://127.0.0.1:$CF_PUERTO/client/v4";
putenv("CLOUDFLARE_API_BASE=$cfBase");
$cfEstado = fn() => json_decode((string) @file_get_contents("$cfDir/cf.json"), true);
$cfHosts = fn() => array_values(array_filter(array_map(fn($r) => $r['hostname'] ?? null, $cfEstado()['config']['ingress'] ?? [])));
$cfDns = fn() => array_column($cfEstado()['dns'] ?? [], 'name');
$cfLog = function (): array { global $cfDir; $l = @file("$cfDir/cf.log", FILE_IGNORE_NEW_LINES) ?: []; @unlink("$cfDir/cf.log"); return $l; };

check('Direcciones válidas e inválidas', [true, true, false, false, false, false],
      [Cloudflare::hostValido('guau.tukanec.com'), Cloudflare::hostValido('a.b.tukanec.com'), Cloudflare::hostValido('127.0.0.1:8264'), Cloudflare::hostValido('localhost'),
       Cloudflare::hostValido('*.tukanec.com'), Cloudflare::hostValido('guau.tukanec.com/x')]);
check('ID de cuenta (32 hex) y de túnel (UUID)', [true, false, true, false], [Cloudflare::cuentaValida($CF_CUENTA), Cloudflare::cuentaValida('abc'), Cloudflare::tunelValido($CF_TUNEL), Cloudflare::tunelValido('servidorqf')]);
$aj = new Ajustes($db);
check('Sin configurar', [false, null], [Cloudflare::configurado($aj), Cloudflare::desdeAjustes($aj, $cif)]);
Cloudflare::guardar($aj, $cif, $CF_TOKEN, $CF_CUENTA, $CF_TUNEL);
check('Guardado: configurado', true, Cloudflare::configurado($aj));
check('El token se guarda cifrado (nunca en claro en la base)', 0, (int) $db->query("SELECT count(*) FROM ajustes WHERE valor LIKE '%" . $CF_TOKEN . "%'")->fetchColumn());
check('Cifrado atado a su contexto', true, str_starts_with($aj->valor('cf_token_cif'), 'v1:') && $cif->descifrar($aj->valor('cf_token_cif'), 'cloudflare:token') === $CF_TOKEN);
Cloudflare::guardar($aj, $cif, '', $CF_CUENTA, $CF_TUNEL);
check('Guardar sin token conserva el anterior', $CF_TOKEN, $cif->descifrar($aj->valor('cf_token_cif'), 'cloudflare:token'));
$cf = Cloudflare::desdeAjustes($aj, $cif);
check('Probar: lee el túnel (nombre)', 'servidorqf', $cf->probar());
check('El token va como "Authorization: Bearer"', ["GET /client/v4/accounts/$CF_CUENTA/cfd_tunnel/$CF_TUNEL"], $cfLog());
check_error('Token malo -> texto simple de permisos', 'token de Cloudflare no sirve', fn() => (new Cloudflare('malo_' . str_repeat('x', 30), $CF_CUENTA, $CF_TUNEL))->probar());
check_error('Túnel que no existe -> no encontró', 'no encontró la cuenta o el túnel', fn() => (new Cloudflare($CF_TOKEN, $CF_CUENTA, '99999999-1111-2222-3333-444455556666'))->probar());
$cfLog();
$antes = $cfEstado() ?? null;
$r = $cf->quitarHostname('Guau.TukanEC.com');
check('Quitar guau.tukanec.com: ok, túnel (2 rutas) y DNS quitados', [true, 'quitado', 'quitado'], [$r['ok'], $r['tunel'], $r['dns']]);
check('Mensajes en palabras simples', true, str_contains(implode(' ', $r['mensajes']), 'Se quitó guau.tukanec.com del túnel (2 rutas)') && str_contains(implode(' ', $r['mensajes']), 'Se borró el DNS'));
check('Las demás rutas quedan iguales (panel, dogssy, huellitas)', ['panel.tukanec.com', 'dogssy.tukanec.com', 'huellitas.tukanec.com'], $cfHosts());
$ing = $cfEstado()['config']['ingress'];
check('La regla final (http_status:404) sigue al final', ['service' => 'http_status:404'], end($ing));
check('Las rutas que quedan conservan sus opciones (originRequest)', 'dogssy.tukanec.com', $ing[1]['originRequest']['httpHostHeader'] ?? null);
check('warp-routing y lo demás de la config se conserva', ['enabled' => false], $cfEstado()['config']['warp-routing'] ?? null);
check('DNS: solo se borró el de guau', ['panel.tukanec.com', 'dogssy.tukanec.com', 'huellitas.tukanec.com', 'ajeno.tukanec.com'], $cfDns());
$l = $cfLog();
check('Orden: leer, guardar, leer otra vez (verificar), zona, DNS, borrar, revisar DNS', ['GET', 'PUT', 'GET', 'GET', 'GET', 'DELETE', 'GET'], array_map(fn($x) => strtok($x, ' '), $l));
check('Busca la zona por el dominio (tukanec.com)', true, (bool) preg_grep('#^GET /client/v4/zones\?name=tukanec\.com#', $l));
$r = $cf->quitarHostname('guau.tukanec.com');
check('Repetir: ya no estaba (ok, sin guardar nada)', [true, 'no_estaba', 'no_estaba', 0], [$r['ok'], $r['tunel'], $r['dns'], count(preg_grep('/^(PUT|DELETE) /', $cfLog()))]);
$r = $cf->quitarHostname('ajeno.tukanec.com');
check('DNS que apunta a otro lado: no se toca', ['otro_destino', true], [$r['dns'], in_array('ajeno.tukanec.com', $cfDns(), true)]);
$r = $cf->quitarHostname('x.otrodominio.com');
check('Dominio que no es de la cuenta: sin DNS que borrar', ['no_estaba', 'no_estaba', true], [$r['tunel'], $r['dns'], $r['ok']]);
$cfLog();
$r = $cf->quitarHostname('127.0.0.1:8264');
check('Dirección no válida: no se pide nada a Cloudflare', [false, []], [$r['ok'], $cfLog()]);
file_put_contents("$cfDir/cf_falla", 'put_no_guarda');
$r = $cf->quitarHostname('huellitas.tukanec.com');
check('Si al verificar la ruta sigue ahí: error claro (no dice que está listo)', [false, 'error'], [$r['ok'], $r['tunel']]);
check('...el DNS se limpia igual (es otra parte)', 'quitado', $r['dns']);
check('...y el texto lo dice', true, str_contains(implode(' ', $r['mensajes']), 'sigue ahí'));
file_put_contents("$cfDir/cf_falla", 'dns_error');
$r = $cf->quitarHostname('panel.tukanec.com');
check('Si borrar el DNS falla: dns error, ok = false', [false, 'quitado', 'error', true], [$r['ok'], $r['tunel'], $r['dns'], in_array('panel.tukanec.com', $cfDns(), true)]);
@unlink("$cfDir/cf_falla");
check('Transporte de pruebas: un túnel sin rutas no se toca', 'error', (new Cloudflare($CF_TOKEN, $CF_CUENTA, $CF_TUNEL, fn() => ['http' => 200, 'cuerpo' => '{"success":true,"result":{"config":{}}}', 'errno' => 0, 'error' => '']))->quitarHostname('a.tukanec.com')['tunel']);

// ---------------------------------------------------------------------------
titulo('24. Borrar una instalación desde el panel (dar_de_baja)');
@unlink("$cfDir/cf.json");   // Cloudflare de mentira como nuevo (guau vuelve a estar)
$ins->crear(['id' => 'guau', 'producto' => 'tuguarderia', 'url' => 'http://127.0.0.1:8267', 'nombre' => 'TuGuardería Guau', 'carpeta' => 'guau']);
$ig = $ins->obtener('guau');
$capG = ['acciones' => ['capacidades', 'estado', 'sso', 'cliente', 'ejecutar'], 'ejecutar' => ['nueva_clave_dueno', 'dar_de_baja', 'cancelar_baja']];
check('disponible: solo si el programa dice dar_de_baja', [true, false, false], [Bajas::disponible($capG), Bajas::disponible(['acciones' => ['ejecutar'], 'ejecutar' => ['nueva_clave_dueno']]), Bajas::disponible([])]);
check('Dirección: la del panel si es un nombre; si es una IP, la que dice el programa', ['dogssy.tukanec.com', 'guau.tukanec.com', null],
      [Bajas::hostname(['url' => 'https://dogssy.tukanec.com']), Bajas::hostname($ig, 'guau.tukanec.com'), Bajas::hostname($ig, 'nada')]);
check('Carpeta: la del panel, o la del programa, o null', ['guau', 'otra', null], [Bajas::carpeta($ig, ['carpeta' => 'x1']), Bajas::carpeta(['carpeta' => ''], ['carpeta' => 'otra']), Bajas::carpeta(['carpeta' => ''], ['carpeta' => '../x'])]);
check('Permiso: solo el dueño (programas)', ['programas', 'programas', false, true], [Operaciones::permiso('dar_de_baja'), Operaciones::permiso('cancelar_baja'), Acceso::puede('soporte', 'programas'), Acceso::puede('dueno', 'programas')]);
check('No van por el formulario genérico', ['dar_de_baja', 'cancelar_baja'], Operaciones::DE_LA_FICHA);

$pedidosB = [];
$resB = [];
$falsoB = function (string $url, array $cab, string $cuerpo) use (&$pedidosB, &$resB): array {
    $pedidosB[] = ['url' => $url, 'cuerpo' => $cuerpo];
    $r = array_shift($resB) ?? [200, '{"ok":true,"protocolo":2}'];
    if (is_callable($r)) $r = $r($cuerpo);
    return ['http' => $r[0], 'cuerpo' => $r[1], 'errno' => $r[2] ?? 0, 'error' => $r[3] ?? '', 'cabeceras' => [], 'ms' => 3];
};
$cvB = new ClienteV2($ins, $falsoB);
$opsB = new Operaciones($db, $cvB, $cif);
$opsB->espera = 0;
$notas = [];
$bajasT = new Bajas($db, $cif, function (string $a, string $d, string $i) use (&$notas) { $notas[] = "$a|$i|$d"; });
$bajasT->confirmarSeg = 0;
$usuarioB = ['id' => $uid, 'email' => 'dimitry@tukan.ec', 'nombre' => 'Dimitry', 'rol' => 'dueno'];
$respProgramada = fn(int $desde) => [200, json_encode(['ok' => true, 'protocolo' => 2, 'operacion' => 'dar_de_baja', 'repetida' => false,
    'mensaje' => 'Programada: la instalación guau se borrará en unos 5 minutos. Antes se guarda una copia final en /var/backups/borrados/',
    'resultado' => ['estado' => 'programada', 'carpeta' => 'guau', 'dominio' => 'guau.tukanec.com', 'borrar_desde' => date('c', $desde), 'espera_segundos' => 300,
                    'copia_en' => '/var/backups/borrados/', 'se_puede_cancelar' => true]])];
$fichaB = fn(?array $baja) => [200, json_encode(['ok' => true, 'protocolo' => 2, 'accion' => 'cliente', 'cliente' => ['id' => 'unico', 'nombre' => 'Guau', 'baja' => $baja]])];
check('La tabla bajas se crea sola (sin cambiar user_version)', [true, 2], [(bool) $db->query("SELECT 1 FROM sqlite_master WHERE name = 'bajas'")->fetchColumn(), (int) $db->query('PRAGMA user_version')->fetchColumn()]);

$resB[] = [422, '{"ok":false,"protocolo":2,"codigo":"invalido","error":"No coincide: la carpeta de esta instalación es guau2. No se borró nada.","campo":"datos.confirmar"}'];
$r = $bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB);
check('Si el programa no acepta la carpeta: no se programa y dice por qué', [false, true, null], [$r['ok'], str_contains($r['mensaje'], 'guau2'), $bajasT->obtener('guau')]);
check('...y queda en la auditoría (baja_rechazada)', true, str_starts_with((string) end($notas), 'baja_rechazada|guau|'));
$desde = time() + 300;
$resB[] = $respProgramada($desde);
$opB = Operaciones::nuevaId();
$r = $bajasT->programar($ig, $opsB, $opB, 'guau', $usuarioB);
$b = $bajasT->obtener('guau');
$cuerpo = json_decode(end($pedidosB)['cuerpo'], true);
check('Programar: manda dar_de_baja con cliente_id unico y confirmar = carpeta', ['dar_de_baja', 'unico', 'guau', 'unico'], [$cuerpo['accion'], $cuerpo['cliente_id'], $cuerpo['datos']['confirmar'], $cuerpo['datos']['cliente_id']]);
check('Programar: ok con el mensaje del programa (copia final)', [true, true], [$r['ok'], str_contains($r['mensaje'], '/var/backups/borrados/')]);
check('Estado programada, hora, copia, carpeta y dirección (del programa: la URL es una IP)', ['programada', $desde, '/var/backups/borrados/', 'guau', 'guau.tukanec.com', $opB],
      [$b['estado'], (int) $b['borrar_desde'], $b['copia_en'], $b['carpeta'], $b['hostname'], $b['operacion_id']]);
check('Auditoría: baja_programada con la carpeta y la copia', true, (bool) preg_grep('#^baja_programada\|guau\|Carpeta guau · se borra desde .* copia final en /var/backups/borrados/#u', $notas));
check('Mientras tanto no salen avisos de "sin conexión"', [true, false], [Bajas::apagando($db, 'guau'), Bajas::apagando($db, 'tuevento')]);
$resB[] = [409, '{"ok":false,"protocolo":2,"codigo":"conflicto","error":"Ya hay un borrado programado para esta instalación.","detalle":{"motivo":"ya_programada"}}'];
check('Programar otra vez: el programa dice ya_programada -> sigue programada', [true, 'programada'], [$bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB)['ok'], $bajasT->obtener('guau')['estado']]);

$resB[] = $fichaB(['estado' => 'programada', 'borrar_desde' => date('c', $desde + 60), 'carpeta' => 'guau']);
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Revisar: pide "cliente" (id unico) y actualiza la hora', ['programada', $desde + 60, true], [$b['estado'], (int) $b['borrar_desde'], str_ends_with(end($pedidosB)['url'], 'accion=cliente') && json_decode(end($pedidosB)['cuerpo'], true) === ['id' => 'unico']]);
$resB[] = [0, '', 7, 'Connection refused'];
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('No responde ANTES de la hora: sigue programada (no se marca borrada)', ['programada', 0], [$b['estado'], (int) $b['fallos']]);
$resB[] = $fichaB(['estado' => 'en_curso', 'carpeta' => 'guau']);
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('El programa dice en_curso', 'en_curso', $b['estado']);
check('...auditoría: en curso', true, str_starts_with((string) end($notas), 'baja_estado|guau|Borrado en curso'));
$db->prepare('UPDATE bajas SET borrar_desde = ? WHERE instancia_id = ?')->execute([time() - 5, 'guau']);
$resB[] = [401, '{"ok":false,"protocolo":2,"codigo":"firma","error":"x"}'];
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Un error del conector (firma) = sigue vivo: no se marca borrada', ['en_curso', 0], [$b['estado'], (int) $b['fallos']]);
$resB[] = [500, '{"ok":false,"protocolo":2,"codigo":"interno","error":"El programa tuvo un error interno."}'];
check('500 con codigo del conector = sigue vivo', 'en_curso', $bajasT->revisar($ig, $cvB, $usuarioB)['estado']);
check('noEsta: 404 sin código, sin conexión, 502 de Cloudflare -> sí; firma/limite -> no', [true, true, true, false, false],
      [Bajas::noEsta(ErrorPrograma::desdeRespuesta(404, 'Not found')), Bajas::noEsta(ErrorPrograma::desdeRespuesta(0, '', 7, 'refused')),
       Bajas::noEsta(ErrorPrograma::desdeRespuesta(502, '<html>Bad gateway</html>')), Bajas::noEsta(ErrorPrograma::desdeRespuesta(401, '{"ok":false,"codigo":"firma","error":"x"}')),
       Bajas::noEsta(ErrorPrograma::desdeRespuesta(429, '{"ok":false,"codigo":"limite","error":"x"}'))]);
$resB[] = [0, '', 7, 'Connection refused'];
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Primera vez que no responde después de la hora: se confirma en la próxima', ['en_curso', 1, true], [$b['estado'], (int) $b['fallos'], str_contains((string) $b['mensaje'], 'próxima')]);
$resB[] = [404, 'Not found'];
$cfLog();
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Segunda vez: borrada (terminada)', ['borrada', true], [$b['estado'], (int) $b['terminada'] > 0]);
check('...auditoría: Terminado con la copia final', true, (bool) preg_grep('#^baja_estado\|guau\|Terminado: la instalación ya no responde .*Copia final en /var/backups/borrados/#u', $notas));
check('Al terminar quita guau.tukanec.com de Cloudflare (túnel y DNS) solo', [true, 'quitado', 'quitado'], [$b['cloudflare']['ok'] ?? null, $b['cloudflare']['tunel'] ?? null, $b['cloudflare']['dns'] ?? null]);
$cfMem = $cfEstado();
check('...en el Cloudflare de mentira ya no está (y los demás sí)', [false, true, false, true], [in_array('guau.tukanec.com', $cfHosts(), true), in_array('dogssy.tukanec.com', $cfHosts(), true),
      in_array('guau.tukanec.com', $cfDns(), true), in_array('dogssy.tukanec.com', $cfDns(), true)]);
check('...auditoría: cloudflare Listo', true, str_starts_with((string) end($notas), 'cloudflare|guau|Listo:'));
$n = count($pedidosB);
check('Ya borrada: revisar no vuelve a preguntar al programa', ['borrada', $n], [$bajasT->revisar($ig, $cvB, $usuarioB)['estado'], count($pedidosB)]);
$r = $bajasT->limpiarCloudflare($ig);
check('Quitar de Cloudflare otra vez: ya no estaba (ok)', [true, 'no_estaba'], [$r['ok'], $r['tunel']]);
putenv('TUKAN_CENTRAL_URL=https://guau.tukanec.com');
$r = $bajasT->limpiarCloudflare($ig);
check('Nunca toca la dirección del panel', [false, true], [$r['ok'], str_contains($r['mensajes'][0], 'dirección de este panel')]);
putenv('TUKAN_CENTRAL_URL');
$bajasT->quitar('guau');
check('Quitar la fila', null, $bajasT->obtener('guau'));

// Cancelar
$resB[] = $respProgramada(time() + 300);
$bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB);
$resB[] = [200, json_encode(['ok' => true, 'protocolo' => 2, 'operacion' => 'cancelar_baja', 'repetida' => false, 'sin_cambios' => false, 'mensaje' => 'Borrado cancelado: la instalación sigue igual.', 'resultado' => ['estado' => 'cancelada']])];
$r = $bajasT->cancelar($ig, $opsB, Operaciones::nuevaId(), $usuarioB);
check('Cancelar: manda cancelar_baja y queda cancelada', [true, 'cancelar_baja', 'cancelada'], [$r['ok'], json_decode(end($pedidosB)['cuerpo'], true)['accion'], $bajasT->obtener('guau')['estado']]);
check('...auditoría: baja_cancelada', true, str_starts_with((string) end($notas), 'baja_cancelada|guau|'));
check('Cancelada: ya no cuenta como "apagando"', false, Bajas::apagando($db, 'guau'));
$resB[] = $respProgramada(time() + 300);
$bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB);
$resB[] = [409, '{"ok":false,"protocolo":2,"codigo":"conflicto","error":"El borrado ya empezó: no se puede cancelar.","detalle":{"motivo":"ya_empezo"}}'];
$r = $bajasT->cancelar($ig, $opsB, Operaciones::nuevaId(), $usuarioB);
check('Cancelar tarde: el programa dice ya_empezo -> en_curso', [false, 'en_curso'], [$r['ok'], $bajasT->obtener('guau')['estado']]);
$resB[] = $fichaB(['estado' => 'error', 'texto' => 'No se pudo copiar la base de datos. No se borro nada.', 'carpeta' => 'guau']);
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Si la copia falla en el servidor: estado error con su texto (no borrada)', ['error', true], [$b['estado'], str_contains((string) $b['mensaje'], 'No se borro nada')]);
$resB[] = $respProgramada(time() + 300);
$bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB);
$resB[] = $fichaB(['estado' => null, 'carpeta' => 'guau']);
check('Si el programa ya no tiene el borrado (lo cancelaron allá): cancelada', 'cancelada', $bajasT->revisar($ig, $cvB, $usuarioB)['estado']);
$resB[] = $respProgramada(time() - 1);
$bajasT->programar($ig, $opsB, Operaciones::nuevaId(), 'guau', $usuarioB);
$bajasT->confirmarSeg = 3600;
$resB[] = [0, '', 7, 'refused'];
$resB[] = [0, '', 7, 'refused'];
$bajasT->revisar($ig, $cvB, $usuarioB);
check('Un corte corto (menos de confirmarSeg entre fallos) no la marca borrada', 'en_curso', $bajasT->revisar($ig, $cvB, $usuarioB)['estado']);
$bajasT->confirmarSeg = 0;
Cloudflare::borrarConfig($aj);
$resB[] = [0, '', 7, 'refused'];
$b = $bajasT->revisar($ig, $cvB, $usuarioB);
check('Sin Cloudflare configurado: borrada y sin resultado de Cloudflare (solo el recordatorio)', ['borrada', null], [$b['estado'], $b['cloudflare']]);
$bajasT->quitar('guau');
$ins->eliminar('guau');
check('revisarPendientes (cron) sin pendientes: nada', [], $bajasT->revisarPendientes($ins, $cvB));

proc_terminate($cfProc);
proc_close($cfProc);
putenv('CLOUDFLARE_API_BASE');
foreach (glob("$cfDir/*") ?: [] as $f) @unlink($f);
@rmdir($cfDir);
