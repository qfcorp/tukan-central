<?php
declare(strict_types=1);

/**
 * Pruebas de la IA de Tukán (compartido/tukan_ia.php: llamada, imágenes, JSON, reintentos, costo, guardián por negocio,
 * pausa global, tope por programa y la AYUDA con FAQ), de deploy/configurar_ia.sh y de la parte del panel (src/Ia.php).
 * Las incluye tests/run_tests.php (usa $tmp, check, titulo). La API es DE MENTIRA: tests/anthropic_falso.py en el puerto 8256.
 */

use Tukan\{Ia, Token};

require_once dirname(__DIR__) . '/compartido/tukan_ia.php';

$itmp = "$tmp/ia";
@mkdir($itmp, 0755, true);
$CLAVE = 'sk-ant-prueba-buena-0123456789';
$URL = 'http://127.0.0.1:8256';
$logApi = "$itmp/api.jsonl";
$logPhp = "$itmp/php.log";
$errorLogAntes = (string) ini_get('error_log');
ini_set('error_log', $logPhp);
$iaProc = proc_open(['python3', dirname(__DIR__) . '/tests/anthropic_falso.py', '8256', $logApi], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$itmp/py.err", 'w']], $tuberias);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8256); $i++) usleep(100000);
$pedidos = fn() => is_file($logApi) ? array_map(fn($l) => json_decode($l, true), file($logApi, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
$nPedidos = fn() => count($pedidos());
$ultimoPedido = fn() => ($p = $pedidos()) ? end($p) : [];
$envIa = function (array $v) use ($itmp): string {
    $f = "$itmp/ia_" . bin2hex(random_bytes(4)) . '.env';
    file_put_contents($f, implode("\n", array_map(fn($k) => "$k=" . $v[$k], array_keys($v))) . "\n");
    putenv("TUKAN_IA_ENV=$f");
    tukan_ia_config(true);
    return $f;
};
foreach (['TUKAN_IA_CLAVE', 'TUKAN_IA_MODELO_RAPIDO', 'TUKAN_IA_MODELO_FUERTE', 'TUKAN_IA_PAUSA', 'TUKAN_IA_URL', 'ANTHROPIC_API_KEY', 'ANTHROPIC_API_BASE', 'IA_MODELO_RAPIDO', 'IA_MODELO_FUERTE'] as $k) putenv($k);
$base = ['TUKAN_IA_CLAVE' => $CLAVE, 'TUKAN_IA_MODELO_RAPIDO' => 'claude-haiku-5-5', 'TUKAN_IA_MODELO_FUERTE' => 'claude-sonnet-5-5', 'TUKAN_IA_URL' => $URL, 'TUKAN_IA_ESPERA_MS' => '0'];
$prohibidas = fn(string $t) => (bool) preg_match('/l[ií]mite|cupo|saldo/iu', $t);

// ---------------------------------------------------------------------------
titulo('36. IA de Tukán: configuración, compatibilidad con TuHospedaje y costo');
putenv("TUKAN_IA_ENV=$itmp/no_existe.env");
tukan_ia_config(true);
check('Sin /etc/tukan-ia.env ni ANTHROPIC_API_KEY: sin clave', [false, 'claude-haiku-5-5', 'claude-sonnet-5-5'], [tukan_ia_estado()['configurada'], tukan_ia_modelo('rapido'), tukan_ia_modelo('fuerte')]);
check('...y la llamada no se hace (motivo sin_clave, costo 0)', ['ok' => false, 'motivo' => 'sin_clave', 'error' => '', 'costo' => 0.0], tukan_ia_llamar(['texto' => 'hola']));
file_put_contents("$itmp/programa.env", "OTRA=1\nANTHROPIC_API_KEY=sk-ant-del-programa-777777\nIA_MODELO_RAPIDO=claude-haiku-5-5-x\nANTHROPIC_API_BASE=http://127.0.0.1:9\n");
tukan_ia_env_programa("$itmp/programa.env");
$cf = tukan_ia_config();
check('Compatibilidad: ANTHROPIC_API_KEY del .env del programa (TuHospedaje)', ['sk-ant-del-programa-777777', 'programa', 'claude-haiku-5-5-x', 'http://127.0.0.1:9'], [$cf['clave'], $cf['origen'], $cf['rapido'], $cf['url']]);
putenv('ANTHROPIC_API_KEY=sk-ant-del-entorno-888888');
check('...una variable de entorno ANTHROPIC_API_KEY gana sobre el .env', 'sk-ant-del-entorno-888888', tukan_ia_config(true)['clave']);
putenv('ANTHROPIC_API_KEY');
$fEnv = $envIa($base + ['TUKAN_IA_TOPE_PROGRAMA_TUEVENTO' => '20', 'TUKAN_IA_TOPE_PROGRAMA_TU_SALON' => '7.5', 'TUKAN_IA_TOPE_PROGRAMA_MAL' => 'abc', '# TUKAN_IA_PAUSA' => '1']);
$cf = tukan_ia_config();
check('/etc/tukan-ia.env gana sobre la del programa', [$CLAVE, 'tukan', 'claude-haiku-5-5', $URL], [$cf['clave'], $cf['origen'], $cf['rapido'], $cf['url']]);
check('Topes por programa (ID en minúsculas; los que no son número se ignoran)', ['tuevento' => 20.0, 'tu_salon' => 7.5], $cf['topes']);
check('Sin TUKAN_IA_PAUSA: encendida (una línea comentada no cuenta)', [false, true], [$cf['pausa'], tukan_ia_activa()]);
check('El estado para mostrar NO trae la clave', false, str_contains(json_encode(tukan_ia_estado()), $CLAVE));
putenv('TUKAN_IA_MODELO_FUERTE=claude-opus-5-5');
check('Una variable de entorno gana sobre el archivo', 'claude-opus-5-5', tukan_ia_modelo('fuerte'));
putenv('TUKAN_IA_MODELO_FUERTE');
tukan_ia_env_programa(null);
check('Costo Haiku 5.5: $0,10 por millón de entrada y $0,50 de salida (100 mil de entrada = $0,01)', [0.01, 0.5], [tukan_ia_costo('claude-haiku-5-5', 100_000, 0), tukan_ia_costo('claude-haiku-5-5', 0, 1_000_000)]);
check('...con más de 100 mil tokens de entrada sube a $0,50 / $2,50', 0.5 * 0.2 + 2.5 * 0.001, tukan_ia_costo('claude-haiku-5-5', 200_000, 1_000));
check('Costo Sonnet 5.5: $2 / $10 por millón', 2.0 * 0.001 + 10.0 * 0.0005, tukan_ia_costo('claude-sonnet-5-5', 1000, 500));
check('Modelo con fecha usa su precio; desconocido = precio del fuerte', [0.01, 2.0], [tukan_ia_costo('claude-haiku-5-5-20261001', 100_000, 0), tukan_ia_costo('otro-modelo', 1_000_000, 0)]);
$envIa($base + ['TUKAN_IA_PRECIOS' => 'claude-haiku-5-5=0.20/1.00;mal']);
check('Tabla de precios configurable (TUKAN_IA_PRECIOS)', [0.2, 1.0], [tukan_ia_costo('claude-haiku-5-5', 1_000_000, 0), tukan_ia_costo('claude-haiku-5-5', 0, 1_000_000)]);
$fEnv = $envIa($base);

// ---------------------------------------------------------------------------
titulo('37. IA de Tukán: llamada, imagen, JSON y reintentos (API de mentira)');
check('La API de mentira está corriendo', true, (bool) @fsockopen('127.0.0.1', 8256));
$r = tukan_ia_llamar(['texto' => 'Hola TOKENS:100000/0', 'sistema' => 'Eres breve.'], ['funcion' => 'prueba']);
$p = $ultimoPedido();
check('Texto: responde', [true, 'OK', 'claude-haiku-5-5', 1], [$r['ok'], $r['texto'], $r['modelo'], $r['intentos']]);
check('...tokens y costo (100 mil de entrada en Haiku = $0,01)', [100000, 0, 0.01], [$r['tokens_entrada'], $r['tokens_salida'], $r['costo']]);
check('...POST /v1/messages con x-api-key, anthropic-version 2023-06-01 y JSON', ['/v1/messages', true, '2023-06-01', 'application/json', 'Eres breve.'], [$p['ruta'], $p['clave_ok'], $p['version'], $p['tipo'], $p['system']]);
$r = tukan_ia_llamar(['texto' => 'Lee la factura', 'modelo' => 'fuerte', 'max_tokens' => 50]);
check('Modelo fuerte', ['claude-sonnet-5-5', 50], [$ultimoPedido()['modelo'], $ultimoPedido()['max_tokens']]);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$r = tukan_ia_llamar(['texto' => 'Lee este documento', 'imagenes' => [['bytes' => $png]]], ['funcion' => 'foto']);
check('Imagen (bytes): va como base64 con su tipo real', [true, 'IMAGEN image/png ' . strlen($png), ['image/png']], [$r['ok'], $r['texto'], $ultimoPedido()['imagenes']]);
$r = tukan_ia_llamar(['texto' => 'Otra', 'imagenes' => [['base64' => base64_encode($png), 'tipo' => 'image/jpeg']]]);
check('Imagen (base64): el tipo se saca del contenido, no del nombre', 'IMAGEN image/png ' . strlen($png), $r['texto']);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'x', 'imagenes' => [['bytes' => 'esto no es una foto']]]);
check('Archivo que no es foto: motivo datos y NO se llama a la API', ['datos', $n0], [$r['motivo'], $nPedidos()]);
$r = tukan_ia_llamar(['texto' => 'x', 'imagenes' => [['bytes' => str_repeat('a', 5 * 1024 * 1024 + 1)]]]);
check('Foto de más de 5 MB: motivo datos', 'datos', $r['motivo']);
$validar = fn(array $j) => isset($j['total']) && is_numeric($j['total']) ? ['total' => (float) $j['total'], 'ruc' => (string) ($j['ruc'] ?? '')] : null;
$r = tukan_ia_llamar(['texto' => 'RESPONDER:```json {"total": 12.5, "ruc": "1790012345001"} ```', 'json' => true, 'validar' => $validar]);
check('JSON: se pide en el prompt, se lee (aunque venga con ```) y se valida', [true, ['total' => 12.5, 'ruc' => '1790012345001']], [$r['ok'], $r['json']]);
check('...el system pide SOLO JSON', true, str_contains($ultimoPedido()['system'], 'Responde SOLO con un objeto JSON válido'));
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'JSON_MALO_UNA_VEZ', 'json' => true]);
check('JSON malo la primera vez: se corrige con una segunda llamada', [true, ['ok' => true, 'corregido' => true], 2], [$r['ok'], $r['json'], $nPedidos() - $n0]);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'RESPONDER:{"total": "doce"}', 'json' => true, 'validar' => $validar]);
check('JSON que no pasa la validación dos veces: motivo respuesta (y se cobra lo usado)', [false, 'respuesta', 2, true], [$r['ok'], $r['motivo'], $nPedidos() - $n0, $r['costo'] > 0]);
$r = tukan_ia_llamar(['texto' => 'Reintento FORZAR_429:2']);
check('429 dos veces: espera y reintenta (3 intentos) y responde', [true, 3], [$r['ok'], $r['intentos']]);
$r = tukan_ia_llamar(['texto' => 'Siempre FORZAR_429:9']);
check('429 siempre: se rinde a los 3 intentos con motivo ocupado', [false, 'ocupado'], [$r['ok'], $r['motivo']]);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'FORZAR_529']);
check('529 (sobrecargada): reintenta y queda ocupado', ['ocupado', 3], [$r['motivo'], $nPedidos() - $n0]);
$envIa($base + ['TUKAN_IA_REINTENTOS' => '0']);
$n0 = $nPedidos();
tukan_ia_llamar(['texto' => 'Sin reintento FORZAR_429:1']);
check('TUKAN_IA_REINTENTOS=0: un solo intento', 1, $nPedidos() - $n0);
$r = tukan_ia_llamar(['texto' => 'x', 'modelo' => 'modelo-inexistente']);
check('Modelo que no existe (404): motivo pedido, sin reintentar', 'pedido', $r['motivo']);
$envIa(['TUKAN_IA_URL' => 'http://127.0.0.1:8259'] + $base + ['TUKAN_IA_REINTENTOS' => '1']);
$r = tukan_ia_llamar(['texto' => 'x']);
check('Sin conexión: motivo red', [false, 'red'], [$r['ok'], $r['motivo']]);

$CLAVE_MALA = 'sk-ant-mala-SECRETO-ZZ9988776655';
@unlink($logPhp);
$envIa(['TUKAN_IA_CLAVE' => $CLAVE_MALA] + $base);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'hola'], ['programa' => 'tuevento', 'funcion' => 'prueba']);
check('Clave mala (401): motivo clave, sin reintentar', [false, 'clave', 1], [$r['ok'], $r['motivo'], $nPedidos() - $n0]);
check('...la API recibió la clave (y no era la buena)', [false, strlen($CLAVE_MALA)], [$ultimoPedido()['clave_ok'], $ultimoPedido()['clave_larga']]);
check('...la clave NO sale en el resultado', false, str_contains(json_encode($r), 'ZZ9988776655'));
check('...ni en el registro de PHP (que sí anotó el error)', [true, false], [str_contains((string) @file_get_contents($logPhp), 'authentication_error'), str_contains((string) @file_get_contents($logPhp), 'ZZ9988776655')]);
check('tukan_ia_tapar quita la clave y cualquier sk-ant-…', 'a *** b sk-ant-*** c', tukan_ia_tapar("a $CLAVE_MALA b sk-ant-otraclave123456 c"));
$envIa(['TUKAN_IA_CLAVE' => 'sk-ant-sin-saldo-111111111'] + $base);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'hola']);
check('Sin saldo en la cuenta (400 credit balance): motivo saldo, sin reintentar', ['saldo', 1], [$r['motivo'], $nPedidos() - $n0]);
$fEnv = $envIa($base);

// ---------------------------------------------------------------------------
titulo('38. IA de Tukán: gasto por negocio, pausa global y tope por programa');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
tukan_ia_crear_tablas($pdo);
tukan_ia_crear_tablas($pdo);
check('Las tablas se crean (y otra vez no falla)', ['tukan_ia_faq', 'tukan_ia_gasto'], array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'tukan_ia_%' ORDER BY name")->fetchAll(), 'name'));
$g1 = new TukanIaGuardianPdo($pdo, 'quinta-1', 0.02);
$r = tukan_ia_llamar(['texto' => 'Uno TOKENS:100000/0'], ['guardian' => $g1, 'programa' => 'tuevento', 'funcion' => 'foto']);
check('Llamada anotada al negocio: $0,01 (100 mil de entrada)', [true, 0.01, 0.01], [$r['ok'], $r['costo'], $g1->gastoMes()]);
tukan_ia_llamar(['texto' => 'Dos TOKENS:0/20000'], ['guardian' => $g1, 'programa' => 'tuevento']);
check('...se suma en la misma fila del mes', [0.02, 1], [$g1->gastoMes(), (int) $pdo->query('SELECT count(*) FROM tukan_ia_gasto')->fetchColumn()]);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'Tres'], ['guardian' => $g1, 'programa' => 'tuevento']);
check('Negocio en su tope: ok=false, motivo tope y NO se llama a la API', [false, 'tope', 0.0, $n0], [$r['ok'], $r['motivo'], $r['costo'], $nPedidos()]);
check('...tukan_ia_sin_gasto lo reconoce', true, tukan_ia_sin_gasto($r));
$g2 = new TukanIaGuardianPdo($pdo, 'quinta-2', null);
check('Otro negocio del mismo programa sigue (tope de cada uno)', true, tukan_ia_llamar(['texto' => 'Cuatro TOKENS:10/10'], ['guardian' => $g2, 'programa' => 'tuevento'])['ok']);
tukan_ia_llamar(['texto' => 'FORZAR_529'], ['guardian' => $g2]);
$f2 = $pdo->query("SELECT llamadas, fallidas, tokens_entrada, tokens_salida FROM tukan_ia_gasto WHERE negocio_id = 'quinta-2'")->fetch(PDO::FETCH_ASSOC);
check('Llamadas buenas, fallidas y tokens por negocio', ['llamadas' => 1, 'fallidas' => 1, 'tokens_entrada' => 10, 'tokens_salida' => 10], array_map('intval', $f2));
check('Gasto de todo el programa en el mes', 0.02 + tukan_ia_costo('claude-haiku-5-5', 10, 10), $g1->gastoProgramaMes());
$anotado = [];
$gf = ['gasto' => fn() => 0.02, 'tope' => 0.03, 'anotar' => function (array $u) use (&$anotado) { $anotado[] = $u; }];
$r = tukan_ia_llamar(['texto' => 'Cinco TOKENS:100/100'], ['guardian' => $gf, 'funcion' => 'audio']);
check('Guardián con funciones (arreglo): anota funcion, modelo, tokens y costo', [true, 'audio', 'claude-haiku-5-5', 100, 100, true], [$r['ok'], $anotado[0]['funcion'], $anotado[0]['modelo'], $anotado[0]['tokens_entrada'], $anotado[0]['tokens_salida'], $anotado[0]['ok']]);
check('...con tope alcanzado no llama', 'tope', tukan_ia_llamar(['texto' => 'x'], ['guardian' => ['gasto' => fn() => 0.03, 'tope' => fn() => 0.03, 'anotar' => fn() => null]])['motivo']);
check('...si leer el gasto falla: no se gasta (motivo tope)', 'tope', tukan_ia_llamar(['texto' => 'x'], ['guardian' => ['gasto' => function () { throw new RuntimeException('base caida'); }, 'tope' => 1.0, 'anotar' => fn() => null]])['motivo']);
check('Guardián incompleto: error de programación claro', 'gasto', (function () { try { tukan_ia_guardian(['anotar' => fn() => null]); return ''; } catch (InvalidArgumentException $e) { return str_contains($e->getMessage(), "'gasto'") ? 'gasto' : $e->getMessage(); } })());

// Pausa global: cambiar el archivo corta al instante (sin recargar a mano)
file_put_contents($fEnv, "TUKAN_IA_PAUSA=1\n", FILE_APPEND);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'Seis'], ['guardian' => $g2, 'programa' => 'tusalon']);
check('TUKAN_IA_PAUSA=1: se nota sin reiniciar nada, motivo pausa y NO llama', [false, 'pausa', $n0], [$r['ok'], $r['motivo'], $nPedidos()]);
check('...el estado dice pausada', [true, false], [tukan_ia_estado()['pausada'], tukan_ia_activa()]);
check('...solo la prueba de consola puede ignorarla', true, tukan_ia_llamar(['texto' => 'x'], ['ignorar_pausa' => true])['ok']);
file_put_contents($fEnv, str_replace("TUKAN_IA_PAUSA=1\n", "TUKAN_IA_PAUSA=0\n", (string) file_get_contents($fEnv)));
check('TUKAN_IA_PAUSA=0: vuelve', true, tukan_ia_llamar(['texto' => 'Siete'], ['guardian' => $g2])['ok']);

$envIa($base + ['TUKAN_IA_TOPE_PROGRAMA_TUEVENTO' => '0.02']);
$n0 = $nPedidos();
$r = tukan_ia_llamar(['texto' => 'Ocho'], ['guardian' => $g2, 'programa' => 'tuevento']);
check('Tope del programa alcanzado ($0,02 entre todos sus negocios): motivo tope_programa, sin llamar', ['tope_programa', $n0], [$r['motivo'], $nPedidos()]);
check('...otro programa sin tope sigue', true, tukan_ia_llamar(['texto' => 'Nueve'], ['guardian' => $g2, 'programa' => 'tusalon'])['ok']);
check('...con tope de programa y un guardián que no sabe el total: no se gasta', 'tope_programa', tukan_ia_llamar(['texto' => 'x'], ['guardian' => ['gasto' => fn() => 0, 'anotar' => fn() => null], 'programa' => 'tuevento'])['motivo']);
$fEnv = $envIa($base);
$neutro = [tukan_ia_mensaje_neutro('foto', '/?r=gasto_nuevo'), tukan_ia_mensaje_neutro('audio'), tukan_ia_mensaje_neutro('texto')];
check('Mensaje neutro de la foto', 'En este momento no pudimos procesar la foto automáticamente; puede hacerlo a mano aquí: /?r=gasto_nuevo.', $neutro[0]);
check('Los mensajes neutros no dicen límite, cupo ni saldo', [false, false, false], array_map($prohibidas, $neutro));

// ---------------------------------------------------------------------------
titulo('39. Ayuda: normalizar, comparar y buscar en el manual');
check('Normalizar: minúsculas, sin tildes, sin signos ni palabras vacías, mismos verbos', ['crear factura', 'crear factura', 'anular factura', 'cambiar clave', 'ver pagar cliente'],
      array_map('tukan_ia_normalizar', ['¿Cómo hago una FACTURA?', 'como creo facturas!!', 'Cómo anulo una factura', '¿Dónde cambio mi contraseña?', 'quiero ver los pagos de mis clientes']));
check('Parecido: misma pregunta con otras palabras = 1; otra pregunta < 0,75', [1.0, true, true],
      [tukan_ia_similitud('¿Cómo hago una factura?', 'como emito facturas'), tukan_ia_similitud('como anulo una factura', 'como hago una factura') < 0.75, tukan_ia_similitud('como veo las facturas del cliente', 'como veo facturas') >= 0.75]);
$manual = "# Manual de TuEvento\n\nBienvenido al programa.\n\n## Facturas\n\n### Crear una factura\n1. Entre a **Facturas**.\n2. Toque **Nueva factura**.\n3. Elija el cliente y los servicios.\n4. Toque **Emitir**.\n5. Revise.\n6. Listo.\n7. Línea siete.\n\n"
    . "### Anular una factura\n1. Abra la factura.\n2. Toque **Anular** y escriba el motivo.\n\n## Reservas\n\nPara reservar una fecha toque **Calendario** y luego **Reservar**.\n\n## Contraseña\n\nEn **Mi cuenta** toque **Cambiar contraseña**.\n";
$sec = tukan_ia_secciones($manual);
check('Secciones del Markdown (con su sección de arriba)', ['Manual de TuEvento', 'Facturas', 'Crear una factura', 'Anular una factura', 'Reservas', 'Contraseña'], array_column($sec, 'titulo'));
check('...«Crear una factura» está dentro de «Manual de TuEvento › Facturas»', 'Manual de TuEvento › Facturas', $sec[2]['padre']);
$rel = tukan_ia_secciones_relevantes($sec, '¿Cómo hago una factura?', 3);
check('Las que más coinciden con «¿Cómo hago una factura?»: primero «Crear una factura»', 'Crear una factura', $rel[0]['titulo']);
check('...y nada de Reservas', false, in_array('Reservas', array_column($rel, 'titulo'), true));
check('Pregunta sin nada del manual: ninguna sección', [], tukan_ia_secciones_relevantes($sec, 'clima de mañana en Quito'));
$html = '<html><head><style>.x{}</style><script>alert(1)</script></head><body><nav>menu</nav><h1>Manual</h1><p>Hola &amp; bienvenido</p><h2>Pagos</h2><ul><li>Toque <b>Cobrar</b></li><li>Listo</li></ul></body></html>';
check('manual.html a texto con encabezados', "# Manual\n\nHola & bienvenido\n\n## Pagos\n\n- Toque Cobrar\n- Listo", tukan_ia_html_a_texto($html));
check('Texto sin encabezados: secciones por párrafos', true, count(tukan_ia_secciones(str_repeat("Párrafo largo de ayuda sobre pagos y facturas.\n\n", 120))) > 1);

// ---------------------------------------------------------------------------
titulo('40. Ayuda con preguntas frecuentes (FAQ) guardadas');
$faq = new TukanIaFaqPdo($pdo);
$ga = new TukanIaGuardianPdo($pdo, 'quinta-3', 1.00);
$opc = ['guardian' => $ga, 'programa' => 'tuevento', 'nombre' => 'TuEvento'];
$n0 = $nPedidos();
$a = tukan_ia_ayuda('¿Cómo hago una factura?', $manual, $faq, $opc);
$p = $ultimoPedido();
check('Pregunta nueva: responde la IA (modelo rápido)', ['ia', 1, 'claude-haiku-5-5'], [$a['fuente'], $nPedidos() - $n0, $p['modelo']]);
check('...con instrucciones: español, corto, paso a paso, solo el manual y el WhatsApp', [true, true, true, true],
      [str_contains($p['system'], 'español sencillo'), str_contains($p['system'], 'paso a paso'), str_contains($p['system'], 'SOLO lo que dice el manual'), str_contains($p['system'], 'escríbanos por WhatsApp 099 640 8397')]);
check('...le pasa solo las secciones que coinciden (no Reservas ni Contraseña)', [true, false, false],
      [str_contains($p['texto'], '## Manual de TuEvento › Facturas › Crear una factura'), str_contains($p['texto'], 'Reservas'), str_contains($p['texto'], 'Cambiar contraseña')]);
check('...la pregunta va como dato', true, str_contains($p['texto'], "<pregunta>\n¿Cómo hago una factura?\n</pregunta>"));
$guardada = $faq->obtener($a['faq_id']);
check('...se guarda como FAQ automática, sin aprobar, con la pregunta normalizada', ['¿Cómo hago una factura?', 'crear factura', 'automatica', 0, 1, $a['respuesta']],
      [$guardada['pregunta'], $guardada['pregunta_normalizada'], $guardada['origen'], $guardada['aprobada'], $guardada['usos'], $guardada['respuesta']]);
check('...costo anotado al negocio como «respuesta nueva de la Ayuda»', [true, 1], [$ga->gastoMes() > 0, $ga->ayudasNuevasMes()]);
$n0 = $nPedidos();
$a2 = tukan_ia_ayuda('como hago una factura', $manual, $faq, $opc);
check('Misma pregunta (sin tildes ni signos): desde la FAQ, costo 0, sin llamar a la IA', ['faq', 0.0, $n0, $a['respuesta'], 1.0], [$a2['fuente'], $a2['costo'], $nPedidos(), $a2['respuesta'], $a2['similitud']]);
$a3 = tukan_ia_ayuda('Necesito emitir facturas, ¿cómo?', $manual, $faq, $opc);
check('Pregunta parecida: también desde la FAQ, sin llamar', ['faq', $n0], [$a3['fuente'], $nPedidos()]);
check('...suma usos y respuestas ahorradas del negocio', [3, 2], [$faq->obtener($a['faq_id'])['usos'], (int) $pdo->query("SELECT ahorradas FROM tukan_ia_gasto WHERE negocio_id = 'quinta-3'")->fetchColumn()]);
$a4 = tukan_ia_ayuda('¿Cómo anulo una factura?', $manual, $faq, $opc);
check('Otra pregunta (anular ≠ crear): nueva respuesta de la IA', ['ia', $n0 + 1, 2], [$a4['fuente'], $nPedidos(), count($faq->todas())]);

// Sin poder gastar: solo FAQ + manual, sin decir por qué
$respuestasCliente = [];
file_put_contents($fEnv, "TUKAN_IA_PAUSA=1\n", FILE_APPEND);
$n0 = $nPedidos();
$a5 = tukan_ia_ayuda('¿Cómo reservo una fecha?', $manual, $faq, $opc);
$respuestasCliente[] = $a5['respuesta'];
check('En pausa, pregunta nueva: responde con el manual tal cual, sin llamar a la IA', ['manual', $n0, true, true], [$a5['fuente'], $nPedidos(), str_contains($a5['respuesta'], '**Reservas**'), str_contains($a5['respuesta'], 'toque **Calendario** y luego **Reservar**')]);
check('...con el contacto', true, str_contains($a5['respuesta'], 'escríbanos por WhatsApp 099 640 8397'));
$a6 = tukan_ia_ayuda('como hago una factura?', $manual, $faq, $opc);
$respuestasCliente[] = $a6['respuesta'];
check('En pausa, pregunta ya guardada: la FAQ sigue respondiendo', 'faq', $a6['fuente']);
$a7 = tukan_ia_ayuda('¿Cuánto cuesta el clima en Marte?', $manual, $faq, $opc);
$respuestasCliente[] = $a7['respuesta'];
check('En pausa, nada en el manual: lo dice y da el contacto', ['manual', 'No encontramos eso en el manual de TuEvento. Por favor, escríbanos por WhatsApp 099 640 8397 y le ayudamos.'], [$a7['fuente'], $a7['respuesta']]);
file_put_contents($fEnv, str_replace("TUKAN_IA_PAUSA=1\n", '', (string) file_get_contents($fEnv)));
$gTope = new TukanIaGuardianPdo($pdo, 'quinta-1', 0.02);   // ya gastó $0,02
$a8 = tukan_ia_ayuda('¿Cómo cambio la contraseña?', $manual, $faq, ['guardian' => $gTope] + $opc);
$respuestasCliente[] = $a8['respuesta'];
check('Negocio en su tope: el manual («Contraseña»), sin llamar', ['manual', $n0, true], [$a8['fuente'], $nPedidos(), str_contains($a8['respuesta'], 'Cambiar contraseña')]);
$a9 = tukan_ia_ayuda('¿Cómo anulo una reserva?', $manual, $faq, ['max_nuevas_mes' => 2] + $opc);   // quinta-3 ya hizo 2 nuevas
$respuestasCliente[] = $a9['respuesta'];
check('Sin el módulo: más de N respuestas nuevas en el mes -> manual (silencioso)', ['manual', $n0], [$a9['fuente'], $nPedidos()]);
$a10 = tukan_ia_ayuda('¿Cómo anulo una reserva?', $manual, $faq, ['max_nuevas_mes' => null] + $opc);
check('Con el módulo (sin tope de respuestas nuevas): sí llama', 'ia', $a10['fuente']);
$envIa(['TUKAN_IA_CLAVE' => $CLAVE_MALA] + $base);
$a11 = tukan_ia_ayuda('¿Dónde veo las reservas del mes?', $manual, $faq, $opc);
$respuestasCliente[] = $a11['respuesta'];
check('Si la IA falla (clave mala): también el manual, sin dar el error', ['manual', false], [$a11['fuente'], str_contains($a11['respuesta'], 'clave')]);
$envIa(['TUKAN_IA_CLAVE' => ''] + $base);
$a12 = tukan_ia_ayuda('¿Cómo hago una reserva?', $manual, $faq, $opc);
$respuestasCliente[] = $a12['respuesta'];
check('Sin clave configurada: el manual', 'manual', $a12['fuente']);
$respuestasCliente[] = tukan_ia_ayuda('???', $manual, $faq, $opc)['respuesta'];
check('NUNCA aparecen «límite», «cupo» ni «saldo» en lo que ve el cliente', [], array_values(array_filter($respuestasCliente, $prohibidas)));
$fEnv = $envIa($base);
$faqF = ['todas' => fn() => [['id' => 'x1', 'pregunta' => 'Como reservo', 'pregunta_normalizada' => '', 'respuesta' => 'Así se reserva.', 'aprobada' => 1]], 'guardar' => fn() => 'n', 'usar' => fn($id) => null];
$af = tukan_ia_ayuda('¿cómo reservo?', $manual, $faqF);
check('Almacén de FAQ con funciones (sin tabla)', ['faq', 'Así se reserva.', 'x1'], [$af['fuente'], $af['respuesta'], $af['faq_id']]);

// Pantalla del súper: aprobar, editar, borrar, agregar
$faq->aprobar($a4['faq_id']);
check('Aprobar', 1, $faq->obtener($a4['faq_id'])['aprobada']);
$faq->editar($a4['faq_id'], '¿Cómo cancelo una factura?', '1. Abra la factura. 2. Toque Anular.');
$e = $faq->obtener($a4['faq_id']);
check('Editar: pregunta normalizada otra vez, pasa a manual y aprobada', ['anular factura', 'manual', 1, '1. Abra la factura. 2. Toque Anular.'], [$e['pregunta_normalizada'], $e['origen'], $e['aprobada'], $e['respuesta']]);
$idM = $faq->agregar('¿Cómo imprimo el contrato?', 'Toque Contratos → Imprimir.');
check('Agregar a mano: manual y aprobada; la encuentra la ayuda', ['manual', 1, 'faq'], [$faq->obtener($idM)['origen'], $faq->obtener($idM)['aprobada'], tukan_ia_ayuda('como imprimo contratos', $manual, $faq, $opc)['fuente']]);
check('Lista «sin aprobar»', true, count($faq->listar('sin_aprobar')) >= 1 && !in_array($idM, array_column($faq->listar('sin_aprobar'), 'id'), true));
$faq->borrar($idM);
check('Borrar', null, $faq->obtener($idM));
$res = $faq->resumen();
check('Resumen de FAQ (para ia_uso)', [true, $res['total'] - $res['automaticas']], [$res['total'] >= 3 && $res['sin_aprobar'] >= 1, $res['manuales']]);

// ---------------------------------------------------------------------------
titulo('41. ia_uso (lado del programa), SQL de ejemplo y consola');
$envIa($base + ['TUKAN_IA_TOPE_PROGRAMA_TUEVENTO' => '20']);
$u = tukan_ia_uso_pdo($pdo, date('Y-m'), fn(array $ids) => ['quinta-1' => ['nombre' => 'Quinta Uno', 'tope' => 0.10, 'modulo' => true]], 'tukan_ia_gasto', $faq, 'tuevento');
$porId = array_column($u['negocios'], null, 'cliente_id');
$costos = array_column($u['negocios'], 'costo');
check('ia_uso: mes, módulo, versión, tope del programa', [date('Y-m'), 'asistente_ia', '1', 20.0, true], [$u['mes'], $u['modulo'], $u['version_libreria'], $u['tope_programa'], $u['configurada']]);
$ordenados = $costos;
rsort($ordenados);
check('...un renglón por negocio, el que más gastó primero, con nombre y tope', [3, $ordenados, 'Quinta Uno', 0.1, true, 'quinta-2', null],
      [count($u['negocios']), $costos, $porId['quinta-1']['nombre'], $porId['quinta-1']['tope'], $porId['quinta-1']['modulo'], $porId['quinta-2']['nombre'], $porId['quinta-2']['tope']]);
check('...totales = suma de los negocios', [round(array_sum(array_column($u['negocios'], 'costo')), 6), array_sum(array_column($u['negocios'], 'ahorradas'))], [$u['totales']['costo'], $u['totales']['ahorradas']]);
check('...con el resumen de FAQ y sin la clave', [true, false], [is_array($u['faq']), str_contains(json_encode($u), $CLAVE)]);
check('...mes sin datos: listas vacías', [[], 0.0], [tukan_ia_uso_pdo($pdo, '2020-01')['negocios'], tukan_ia_uso_pdo($pdo, '2020-01')['totales']['costo']]);
$sqlPg = tukan_ia_sql('pgsql');
$sqlMy = tukan_ia_sql('mysql');
check('SQL PostgreSQL: BIGSERIAL, TIMESTAMP, PRIMARY KEY (negocio_id, mes)', [true, true, true], [str_contains($sqlPg, 'id BIGSERIAL PRIMARY KEY'), str_contains($sqlPg, 'fecha TIMESTAMP'), str_contains($sqlPg, 'PRIMARY KEY (negocio_id, mes)')]);
check('SQL MySQL: AUTO_INCREMENT, DATETIME, utf8mb4, índice con largo', [true, true, true, true], [str_contains($sqlMy, 'AUTO_INCREMENT PRIMARY KEY'), str_contains($sqlMy, 'DATETIME'), str_contains($sqlMy, 'utf8mb4'), str_contains($sqlMy, 'pregunta_normalizada(191)')]);
$leeme = (string) @file_get_contents(dirname(__DIR__) . '/compartido/LEEME_IA.md');
check('LEEME_IA.md trae el mismo SQL que la librería (PostgreSQL y MySQL)', [true, true], [str_contains($leeme, trim($sqlPg)), str_contains($leeme, trim($sqlMy))]);
foreach (['Mensaje neutro (foto)' => tukan_ia_mensaje_neutro('foto', '{enlace}'), 'Mensaje neutro (audio)' => tukan_ia_mensaje_neutro('audio', '{enlace}'), 'Uso del LEEME' => 'En este momento no pudimos procesar la foto automáticamente; puede hacerlo a mano'] as $k => $txt) {
    check("LEEME_IA.md tiene el texto exacto: $k", true, str_contains($leeme, $txt));
}
$lib = dirname(__DIR__) . '/compartido/tukan_ia.php';
$out = (string) shell_exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' TUKAN_IA_ENV=' . escapeshellarg($fEnv) . ' php ' . escapeshellarg($lib) . ' probar 2>&1; echo "rc=$?"');
check('Consola: php tukan_ia.php probar -> IA: FUNCIONA', [true, true], [str_contains($out, 'IA: FUNCIONA (claude-haiku-5-5'), str_contains($out, 'rc=0')]);
check('...sin la clave y sin tildes', [false, 0], [str_contains($out, $CLAVE), preg_match('/[áéíóúñ]/u', $out)]);
check('...pidió como mucho 5 tokens', 5, $ultimoPedido()['max_tokens']);

// ---------------------------------------------------------------------------
titulo('42. deploy/configurar_ia.sh contra la API de mentira');
$script = dirname(__DIR__) . '/deploy/configurar_ia.sh';
$envSh = "$itmp/tukan-ia.env";
$yo = (string) (posix_getpwuid(posix_geteuid())['name'] ?? 'root');
$correrIa = fn(array $v, string $args = '') => (string) shell_exec(implode(' ', array_map(fn($k) => $k . '=' . escapeshellarg($v[$k]), array_keys($v))) . ' setsid -w bash ' . escapeshellarg($script) . " $args < /dev/null 2>&1; echo \"rc=\$?\"");
$vIa = ['IA_ENV' => $envSh, 'PROBAR_COMO' => $yo, 'TUKAN_IA_CLAVE' => $CLAVE, 'TUKAN_IA_MODELO_RAPIDO' => 'claude-haiku-5-5', 'TUKAN_IA_MODELO_FUERTE' => 'claude-sonnet-5-5', 'TUKAN_IA_PAUSA' => 'no', 'TUKAN_IA_URL' => $URL];
$esRootIa = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (!$esRootIa && trim((string) shell_exec('sudo -n true 2>&1; echo $?')) !== '0') {
    echo "  (se omite: hace falta root o sudo sin contraseña para escribir el archivo como root:www-data)\n";
} else {
    $out = $correrIa($vIa);
    check('Con la clave buena: «IA: FUNCIONA»', [true, true], [str_contains($out, "IA: FUNCIONA\n"), str_contains($out, 'rc=0')]);
    clearstatcache();
    check('Archivo root:www-data con permisos 640', ['root', 'www-data', '640'],
          [posix_getpwuid(fileowner($envSh))['name'] ?? '', posix_getgrgid(filegroup($envSh))['name'] ?? '', substr(sprintf('%o', fileperms($envSh)), -3)]);
    $txt = (string) file_get_contents($envSh);
    check('Guarda clave, modelos y pausa', [1, 1, 1, 1], [preg_match('/^TUKAN_IA_CLAVE=' . preg_quote($CLAVE, '/') . '$/m', $txt), preg_match('/^TUKAN_IA_MODELO_RAPIDO=claude-haiku-5-5$/m', $txt),
                                                   preg_match('/^TUKAN_IA_MODELO_FUERTE=claude-sonnet-5-5$/m', $txt), preg_match('/^TUKAN_IA_PAUSA=0$/m', $txt)]);
    check('...la clave no sale en pantalla; mensajes sin tildes', [false, 0], [str_contains($out, $CLAVE), preg_match('/[áéíóúñÁÉÍÓÚÑ]/u', $out)]);
    $out = $correrIa(['IA_ENV' => $envSh], '--pausar');
    check('--pausar: TUKAN_IA_PAUSA=1 y lo dice', [true, 1, 1], [str_contains($out, 'IA: PAUSADA para todos los programas'), preg_match('/^TUKAN_IA_PAUSA=1$/m', (string) file_get_contents($envSh)), preg_match('/^TUKAN_IA_CLAVE=/m', (string) file_get_contents($envSh))]);
    putenv("TUKAN_IA_ENV=$envSh");
    check('...y la librería deja de gastar al instante', 'pausa', tukan_ia_llamar(['texto' => 'x'])['motivo']);
    $out = $correrIa(['IA_ENV' => $envSh], '--tope tuevento 12,50');
    check('--tope tuevento 12,50: TUKAN_IA_TOPE_PROGRAMA_TUEVENTO=12.50', [true, 1], [str_contains($out, 'Tope del programa tuevento: $12.50 al mes'), preg_match('/^TUKAN_IA_TOPE_PROGRAMA_TUEVENTO=12\.50$/m', (string) file_get_contents($envSh))]);
    check('--tope con monto malo: error', true, str_contains($correrIa(['IA_ENV' => $envSh], '--tope tuevento mucho'), 'el monto debe ser un numero'));
    $out = $correrIa(['IA_ENV' => $envSh], '--reanudar');
    check('--reanudar: TUKAN_IA_PAUSA=0 (conserva clave y tope)', [true, 1, 1, 1], [str_contains($out, 'IA: ENCENDIDA'), preg_match('/^TUKAN_IA_PAUSA=0$/m', (string) file_get_contents($envSh)),
          preg_match('/^TUKAN_IA_CLAVE=/m', (string) file_get_contents($envSh)), preg_match('/^TUKAN_IA_TOPE_PROGRAMA_TUEVENTO=/m', (string) file_get_contents($envSh))]);
    check('...la librería vuelve a gastar', true, tukan_ia_llamar(['texto' => 'x'])['ok']);
    $out = $correrIa(array_diff_key($vIa, ['TUKAN_IA_CLAVE' => 1]));
    check('Otra vez sin escribir la clave: conserva la de antes y el tope', [true, 1], [str_contains($out, "IA: FUNCIONA\n"), preg_match('/^TUKAN_IA_TOPE_PROGRAMA_TUEVENTO=12\.50$/m', (string) file_get_contents($envSh))]);
    $out = $correrIa(['TUKAN_IA_CLAVE' => $CLAVE_MALA] + $vIa);
    check('Clave mala: «IA: NO FUNCIONA» con la explicación', [true, true, true], [str_contains($out, 'IA: NO FUNCIONA'), str_contains($out, 'La clave esta mal copiada o fue borrada'), str_contains($out, 'rc=1')]);
    check('...sin la clave en pantalla', false, str_contains($out, 'ZZ9988776655'));
    $out = $correrIa(['TUKAN_IA_CLAVE' => 'sk-ant-sin-saldo-111111111'] + $vIa);
    check('Sin saldo: lo dice claro (cargar créditos)', [true, true], [str_contains($out, 'IA: NO FUNCIONA'), str_contains($out, 'Carga creditos')]);
    $out = $correrIa(['TUKAN_IA_URL' => 'http://127.0.0.1:8259'] + $vIa);
    check('Sin conexión con la API: lo dice', true, str_contains($out, 'no llega a api.anthropic.com'));
    $out = $correrIa(['TUKAN_IA_PAUSA' => 'si'] + $vIa);
    check('Configurar en pausa: guarda 1 y la prueba igual se hace', [1, true, true], [preg_match('/^TUKAN_IA_PAUSA=1$/m', (string) file_get_contents($envSh)), str_contains($out, 'Estado: PAUSADA'), str_contains($out, "IA: FUNCIONA\n")]);
    check('Pausa con respuesta rara: error', true, str_contains($correrIa(['TUKAN_IA_PAUSA' => 'tal vez'] + $vIa), 'responde si o no'));
    check('Opción desconocida: error', true, str_contains($correrIa(['IA_ENV' => $envSh], '--borrar'), 'opcion desconocida'));
    // La clave que ya está en TuHospedaje (config/.env, solo root la lee): se ofrece reusarla
    $thEnv = "$itmp/tuhospedaje.env";
    file_put_contents($thEnv, "HOSPEDAJE_DB_NAME=x\nANTHROPIC_API_KEY=$CLAVE\nIA_MODELO_RAPIDO=claude-haiku-5-5\n");
    chmod($thEnv, 0600);
    $envTh = "$itmp/tukan-ia-th.env";
    $vTh = ['IA_ENV' => $envTh, 'TUHOSPEDAJE_ENV' => $thEnv] + array_diff_key($vIa, ['TUKAN_IA_CLAVE' => 1, 'IA_ENV' => 1]);
    $out = $correrIa(['USAR_CLAVE_TUHOSPEDAJE' => 'n'] + $vTh);
    check('TuHospedaje: si se contesta «n», no la usa (y falta la clave)', [true, true, false], [str_contains($out, 'No se usa la clave de TuHospedaje'), str_contains($out, 'ERROR: falta la clave'), is_file($envTh)]);
    $out = $correrIa($vTh);
    check('TuHospedaje: sin clave en /etc/tukan-ia.env la ofrece; Enter = sí, y funciona', [true, true, 1],
          [str_contains($out, 'Se usa la clave de TuHospedaje.'), str_contains($out, "IA: FUNCIONA\n"), preg_match('/^TUKAN_IA_CLAVE=' . preg_quote($CLAVE, '/') . '$/m', (string) @file_get_contents($envTh))]);
    check('...la clave no sale en pantalla', false, str_contains($out, $CLAVE));
    check('...la pregunta es «Se encontro la clave de TuHospedaje, usarla? [S/n]»', 1, preg_match('/printf "Se encontro la clave de TuHospedaje, usarla\? \[S\/n\]: "/', (string) file_get_contents($script)));
    $out = $correrIa(['TUKAN_IA_CLAVE' => $CLAVE_MALA] + $vTh);
    $out = $correrIa($vTh);
    check('Si /etc/tukan-ia.env ya tiene clave: no pregunta y conserva la suya', [false, 1], [str_contains($out, 'TuHospedaje'), preg_match('/ZZ9988776655/', (string) file_get_contents($envTh))]);
    $out = $correrIa($vTh, '--usar-clave-tuhospedaje');
    check('--usar-clave-tuhospedaje: la usa sin preguntar (reemplaza la otra) y funciona', [true, true, 1, false],
          [str_contains($out, 'Se usa la clave de TuHospedaje ('), str_contains($out, "IA: FUNCIONA\n"), preg_match('/^TUKAN_IA_CLAVE=' . preg_quote($CLAVE, '/') . '$/m', (string) file_get_contents($envTh)), str_contains($out, $CLAVE)]);
    $out = $correrIa(['TUHOSPEDAJE_ENV' => "$itmp/no_existe.env"] + $vTh, '--usar-clave-tuhospedaje');
    check('--usar-clave-tuhospedaje sin archivo: error claro', [true, true], [str_contains($out, 'no se encontro ANTHROPIC_API_KEY'), str_contains($out, 'rc=1')]);
    $out = $correrIa(['IA_ENV' => $envSh, 'PROBAR_COMO' => $yo], '--probar');
    check('--probar solo prueba', [true, true], [str_contains($out, 'Llamada de prueba al modelo rapido'), str_contains($out, "IA: FUNCIONA\n")]);
    if ($yo === 'root' && posix_getpwnam('www-data')) {
        $out = $correrIa(['IA_ENV' => $envSh, 'PROBAR_COMO' => 'www-data'], '--probar');   // la carpeta temporal de la prueba es 700
        check('Si www-data no puede leer el archivo: lo dice claro', [true, true], [str_contains($out, 'El usuario www-data no puede leer'), str_contains($out, 'rc=1')]);
    }
}
check('Por defecto: claude-haiku-5-5 y claude-sonnet-5-5; la clave se pide sin que se vea (read -s)', [1, 1, 1],
      [preg_match('/echo claude-haiku-5-5\)/', (string) file_get_contents($script)), preg_match('/echo claude-sonnet-5-5\)/', (string) file_get_contents($script)), preg_match('/read -r -s TUKAN_IA_CLAVE/', (string) file_get_contents($script))]);

// ---------------------------------------------------------------------------
titulo('43. Panel: acción v2 ia_uso y pantalla IA (lógica)');
check('El token v2 acepta ia_uso', true, in_array('ia_uso', Token::TIPOS_V2, true));
$l = Ia::limpiar(['mes' => '2026-10', 'pausada' => true, 'configurada' => true, 'tope_programa' => 20, 'totales' => ['costo' => 0.123456789, 'llamadas' => 7, 'ahorradas' => 'x'],
                  'negocios' => [['cliente_id' => 5, 'nombre' => "Quinta\nUno", 'costo' => 0.01, 'tope' => 3], ['nombre' => 'sin id'], ['cliente_id' => 'b', 'costo' => 0.5, 'modulo' => true]],
                  'faq' => ['total' => 3, 'sin_aprobar' => 1]]);
check('Limpia lo que manda el programa (números, textos, ids)', ['2026-10', true, 20.0, 0.123457, 7, 0, ['b', '5'], 'Quinta Uno', 3.0, [true, false]],
      [$l['mes'], $l['pausada'], $l['tope_programa'], $l['totales']['costo'], $l['totales']['llamadas'], $l['totales']['ahorradas'], array_column($l['negocios'], 'cliente_id'),
       $l['negocios'][1]['nombre'], $l['negocios'][1]['tope'], array_column($l['negocios'], 'modulo')]);
check('Respuesta vacía o rara: todo en 0', [null, false, 0.0, []], [Ia::limpiar('x')['mes'], Ia::limpiar(null)['pausada'], Ia::limpiar([])['totales']['costo'], Ia::limpiar(['negocios' => 'x'])['negocios']]);
$t = Ia::totales([['uso' => ['datos' => Ia::limpiar(['totales' => ['costo' => 0.5, 'llamadas' => 10, 'ahorradas' => 30], 'pausada' => true])]], ['uso' => null],
                  ['uso' => ['datos' => Ia::limpiar(['totales' => ['costo' => 0.25, 'llamadas' => 10, 'ahorradas' => 10]])]]]);
check('Totales de todos los programas y % ahorrado', [0.75, 20, 40, 2, 1, 67], [$t['costo'], $t['llamadas'], $t['ahorradas'], $t['programas'], $t['pausados'], $t['pct_ahorro']]);
check('Dinero de IA: centavos con 4 decimales', ['$0,00', '$0,0042', '$0,123', '$12,30'], [Ia::dinero(0.0), Ia::dinero(0.0042), Ia::dinero(0.123), Ia::dinero(12.3)]);
putenv("TUKAN_IA_ENV=$itmp/no_existe.env");
check('Estado del servidor (sin archivo): sin configurar, sin clave', [false, false], [Ia::estadoServidor()['configurada'], str_contains(json_encode(Ia::estadoServidor()), 'sk-ant')]);
$doc = (string) file_get_contents(dirname(__DIR__) . '/docs/PROTOCOLO_V2.md');
check('PROTOCOLO_V2.md documenta ia_uso (Anexo C)', [true, true], [str_contains($doc, '## Anexo C'), str_contains($doc, '"accion": "ia_uso"')]);

// Limpieza
putenv('TUKAN_IA_ENV');
tukan_ia_config(true);
ini_set('error_log', $errorLogAntes);
@proc_terminate($iaProc);
@proc_close($iaProc);
shell_exec('rm -rf ' . escapeshellarg($itmp));
