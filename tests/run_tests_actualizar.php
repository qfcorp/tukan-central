<?php
declare(strict_types=1);

/**
 * Pruebas del pedido firmado (src/Pedido.php) y de la parte del panel de «Actualizaciones» (src/Actualizaciones.php).
 * Las incluye tests/run_tests.php (usa $tmp, check, check_error, titulo). Las del comando "actualizar" y del servicio
 * están en tests/run_actualizar.sh (repos git de mentira).
 */

use Tukan\{Actualizaciones, Auditoria, Db, Pedido};

$atmp = "$tmp/actualizar";
@mkdir("$atmp/pedidos", 0770, true);
@mkdir("$atmp/estado", 0750, true);
$LL = bin2hex(random_bytes(32));
file_put_contents("$atmp/llave", $LL . "\n");
$llave = hex2bin($LL);
$sha1 = str_repeat('a', 40);
$sha2 = str_repeat('b', 40);

titulo('Actualizar 1. Pedido firmado (panel -> servicio de root)');
$p = Pedido::crear('actualizar', ['tuevento' => $sha1], 'dimitryfierro@gmail.com', $llave, 1_800_000_000);
check('Id de 32 hex', 1, preg_match('/^[0-9a-f]{32}$/', $p['id']));
$d = Pedido::verificar($p['contenido'], $llave, 1_800_000_100);
check('Se verifica con la misma llave', ['actualizar', ['tuevento' => $sha1], 'dimitryfierro@gmail.com', 1_800_000_600], [$d['tipo'], $d['objetivos'], $d['usuario'], $d['vence']]);
check('Leer la llave del archivo', $llave, Pedido::leerLlave("$atmp/llave"));
check_error('Llave con formato malo', 'llave ilegible', function () use ($atmp) { file_put_contents("$atmp/mala", 'xyz'); Pedido::leerLlave("$atmp/mala"); });
check_error('Otra llave: firma invalida', 'firma invalida', fn() => Pedido::verificar($p['contenido'], random_bytes(32), 1_800_000_100));
$j = json_decode($p['contenido'], true);
$dd = json_decode(base64_decode(strtr($j['datos'], '-_', '+/')), true);
$dd['objetivos'] = ['tusalon' => $sha1];
$j2 = $j; $j2['datos'] = rtrim(strtr(base64_encode(json_encode($dd)), '+/', '-_'), '=');
check_error('Datos cambiados (otro programa): firma invalida', 'firma invalida', fn() => Pedido::verificar(json_encode($j2), $llave, 1_800_000_100));
check_error('Vencido (mas de 10 minutos): «valido: ...vencido»', 'valido: pedido vencido', fn() => Pedido::verificar($p['contenido'], $llave, 1_800_000_601));
check_error('Del futuro', 'valido: pedido con fecha futura', fn() => Pedido::verificar($p['contenido'], $llave, 1_799_999_000));
check_error('No es JSON', 'formato malo', fn() => Pedido::verificar('hola', $llave));
check_error('Demasiado grande', 'demasiado grande', fn() => Pedido::verificar(str_repeat('x', 9000), $llave));
check_error('Version distinta', 'formato malo', fn() => Pedido::verificar(json_encode(['v' => 2] + $j), $llave, 1_800_000_100));
check_error('Revisar no lleva programas', 'no lleva programas', fn() => Pedido::crear('revisar', ['tuevento' => $sha1], 'x', $llave));
check_error('Actualizar sin programas', 'objetivos invalidos', fn() => Pedido::crear('actualizar', [], 'x', $llave));
check_error('Programa con nombre raro', 'objetivos invalidos', fn() => Pedido::crear('actualizar', ['../etc' => $sha1], 'x', $llave));
check_error('Commit corto o con otra cosa', 'objetivos invalidos', fn() => Pedido::crear('actualizar', ['tuevento' => 'abc1234; rm -rf /'], 'x', $llave));
check_error('Tipo desconocido', 'tipo invalido', fn() => Pedido::crear('borrar', [], 'x', $llave));
check_error('Mas de 30 programas', 'objetivos invalidos', fn() => Pedido::crear('actualizar', array_fill_keys(array_map(fn($i) => "p$i", range(1, 31)), $sha1), 'x', $llave));
check_error('Usuario con saltos de linea', 'usuario invalido', fn() => Pedido::crear('revisar', [], "a\nb", $llave));
$r = Pedido::crear('revisar', [], 'dimitryfierro@gmail.com', $llave);
check('Revisar: sin programas', [], Pedido::verificar($r['contenido'], $llave)['objetivos']);

titulo('Actualizar 2. Ayudante del servicio (validar: un solo uso)');
$ay = dirname(__DIR__) . '/deploy/actualizar_ayudante.php';
$pf = "$atmp/p1.json";
$pp = Pedido::crear('actualizar', ['tuevento' => $sha1, 'tusalon' => $sha2], 'dimitryfierro@gmail.com', $llave);
file_put_contents($pf, $pp['contenido']);
exec('php ' . escapeshellarg($ay) . ' validar ' . escapeshellarg($pf) . ' ' . escapeshellarg("$atmp/llave") . ' ' . escapeshellarg("$atmp/usados") . ' 2>&1', $sal, $rc);
check('Pedido bueno: OK con id, tipo y usuario', [0, "OK\t{$pp['id']}\tactualizar\tdimitryfierro@gmail.com"], [$rc, $sal[0] ?? '']);
check('...una linea por programa con su commit', ["tuevento\t$sha1", "tusalon\t$sha2"], array_slice($sal, 1));
$sal = [];
exec('php ' . escapeshellarg($ay) . ' validar ' . escapeshellarg($pf) . ' ' . escapeshellarg("$atmp/llave") . ' ' . escapeshellarg("$atmp/usados") . ' 2>&1', $sal, $rc);
check('El mismo otra vez: repetido', [1, "NO\t-\tpedido repetido (ya se uso)"], [$rc, $sal[0] ?? '']);
$pv = Pedido::crear('actualizar', ['tuevento' => $sha1], 'x', $llave, time() - 1000);
file_put_contents($pf, $pv['contenido']);
$sal = [];
exec('php ' . escapeshellarg($ay) . ' validar ' . escapeshellarg($pf) . ' ' . escapeshellarg("$atmp/llave") . ' ' . escapeshellarg("$atmp/usados") . ' 2>&1', $sal, $rc);
check('Vencido con firma buena: rechazo con su id (para avisar al panel)', [1, "NO\t{$pv['id']}\tpedido vencido (pasaron mas de 10 minutos)"], [$rc, $sal[0] ?? '']);
check('...y no queda como usado', false, is_file("$atmp/usados/{$pv['id']}"));

titulo('Actualizar 3. Panel: cola, estado y auditoría');
putenv("TUKAN_ACTUALIZAR_LLAVE=$atmp/no-existe");
putenv("TUKAN_ACTUALIZAR_PEDIDOS=$atmp/pedidos");
putenv("TUKAN_ACTUALIZAR_ESTADO=$atmp/estado");
$adb = Db::conectar("$atmp/central.sqlite");
$act = new Actualizaciones($adb);
check('Sin la llave: no esta instalado', false, $act->instalado());
check_error('Sin instalar no se puede pedir', 'no está instalado', fn() => $act->pedir('revisar', [], ['id' => 1, 'email' => 'd@x']));
putenv("TUKAN_ACTUALIZAR_LLAVE=$atmp/llave");
$act = new Actualizaciones($adb);
check('Con llave, cola y estado: instalado', true, $act->instalado());
check('La tabla se crea sola (user_version sigue en 2)', [true, 2], [(bool) $adb->query("SELECT 1 FROM sqlite_master WHERE name = 'actualizaciones'")->fetchColumn(), (int) $adb->query('PRAGMA user_version')->fetchColumn()]);
check('Sin versiones.json: null', null, $act->versiones());
file_put_contents("$atmp/estado/versiones.json", json_encode(['revisado' => 1_800_000_000, 'servidor' => 'qfcorp', 'programas' => [
    ['id' => 'tuevento', 'nombre' => 'TuEvento', 'estado' => 'nuevo', 'nuevos' => 2, 'instalada' => ['commit' => $sha2, 'corto' => 'bbbbbbb'],
     'disponible' => ['commit' => $sha1, 'corto' => 'aaaaaaa'], 'cambios' => [['corto' => 'aaaaaaa', 'titulo' => 'Añade reservas']]],
    ['id' => 'tukan-central', 'nombre' => 'Panel Tukan Central', 'estado' => 'al_dia', 'instalada' => ['commit' => $sha2]],
    ['id' => 'erp-productoras', 'nombre' => 'ERP', 'estado' => 'error', 'mensaje' => 'no se pudo revisar GitHub'],
    ['id' => '../malo', 'estado' => 'nuevo', 'disponible' => ['commit' => $sha1]],
]]));
$v = $act->versiones();
check('versiones.json: 3 programas (el id malo se ignora)', ['tuevento', 'tukan-central', 'erp-productoras'], array_keys($v['programas']));
check('Nombres con tildes', ['TuEvento', 'Panel Tukán Central', 'ERP para productoras'], array_column($v['programas'], 'nombre'));
check('Con novedad: solo tuevento con su commit', ['tuevento' => $sha1], $act->conNovedad($v));
$id = $act->pedir('actualizar', ['tuevento' => $sha1], ['id' => 7, 'email' => 'dimitryfierro@gmail.com'], 1_800_000_000);
$archivos = glob("$atmp/pedidos/*.json");
check('El pedido queda en la cola con nombre momento-id.json', ["$atmp/pedidos/1800000000-$id.json"], $archivos);
check('...firmado: lo verifica el servicio', ['tuevento' => $sha1], Pedido::verificar((string) file_get_contents($archivos[0]), $llave, 1_800_000_010)['objetivos']);
check('...permisos 640', '640', substr(sprintf('%o', fileperms($archivos[0])), -3));
check('...sin archivos temporales', [], glob("$atmp/pedidos/.*.tmp"));
check('...registrado como pendiente', ['pendiente', 'dimitryfierro@gmail.com'], [$act->obtener($id)['estado'], $act->obtener($id)['email']]);
check_error('Con uno en curso no se puede pedir otro', 'Ya hay una actualización en curso', fn() => $act->pedir('revisar', [], ['id' => 7, 'email' => 'd@x']));
$auditados = [];
$anotar = function (string $i, string $e, string $r) use (&$auditados): void { $auditados[] = "$e|$r"; };
$act->sincronizar($anotar, 1_800_000_030);
check('Sin respuesta todavia: sigue pendiente, sin auditoria', ['pendiente', []], [$act->obtener($id)['estado'], $auditados]);
file_put_contents("$atmp/estado/pedido-$id.json", json_encode(['id' => $id, 'estado' => 'en_curso', 'programas' => []]));
file_put_contents("$atmp/estado/pedido-$id.log", "== tuevento: bbbbbbb -> aaaaaaa\n\e[1mrespaldo\e[0m listo\n");
$act->sincronizar($anotar, 1_800_000_040);
check('El servicio lo tomo: en curso, sin auditoria todavia', ['en_curso', []], [$act->obtener($id)['estado'], $auditados]);
check('Log sin codigos de color', "== tuevento: bbbbbbb -> aaaaaaa\nrespaldo listo\n", $act->log($id));
check('Log de un id raro: vacio', '', $act->log('../../etc/passwd'));
file_put_contents("$atmp/estado/pedido-$id.json", json_encode(['id' => $id, 'estado' => 'listo', 'fin' => 1_800_000_090, 'programas' => [
    ['programa' => 'tuevento', 'estado' => 'ok', 'antes' => $sha2, 'despues' => $sha1, 'mensaje' => ''],
]]));
$act->sincronizar($anotar, 1_800_000_100);
check('Terminado: listo con resumen', ['listo', 'TuEvento: actualizado (bbbbbbb → aaaaaaa)', 1_800_000_090], [$act->obtener($id)['estado'], $act->obtener($id)['resumen'], (int) $act->obtener($id)['terminado']]);
check('...se anota UNA vez en la auditoria', ['listo|TuEvento: actualizado (bbbbbbb → aaaaaaa)'], $auditados);
$act->sincronizar($anotar, 1_800_000_200);
check('...al volver a mirar no se repite', 1, count($auditados));
check('Ya no hay nada en curso', null, $act->enCurso());
$id2 = $act->pedir('revisar', [], ['id' => 7, 'email' => 'd@x'], 1_800_001_000);
$act->sincronizar($anotar, 1_800_001_000 + Actualizaciones::ESPERA_SEG + 5);
check('El servidor no lo tomo en 11 minutos: vencido', 'vencido', $act->obtener($id2)['estado']);
check('...con explicacion', true, str_contains((string) $act->obtener($id2)['resumen'], 'tukan-actualizar.path'));
check('Historial: el mas nuevo primero', [$id2, $id], array_column($act->historial(), 'id'));
check('Resumen de un fallo con vuelta atras', 'TuSalón: falló y volvió a la versión anterior',
      Actualizaciones::resumen(['estado' => 'fallo', 'programas' => [['programa' => 'tusalon', 'estado' => 'revertido', 'antes' => $sha2, 'despues' => $sha2]]]));
check('Resumen de un rechazo', 'No se hizo: pedido vencido (pasaron mas de 10 minutos)', Actualizaciones::resumen(['estado' => 'rechazado', 'motivo' => 'pedido vencido (pasaron mas de 10 minutos)']));
check('Textos de la auditoria', ['Pidió actualizar programas', 'Resultado de una actualización'], [Auditoria::texto('actualizacion_pedida'), Auditoria::texto('actualizacion_resultado')]);
check('Solo el dueño tiene permiso', [true, false], [\Tukan\Acceso::puede('dueno', 'actualizaciones'), \Tukan\Acceso::puede('soporte', 'actualizaciones')]);

$adb = null;
$act = null;
foreach (['TUKAN_ACTUALIZAR_LLAVE', 'TUKAN_ACTUALIZAR_PEDIDOS', 'TUKAN_ACTUALIZAR_ESTADO'] as $k) putenv($k);
exec('rm -rf ' . escapeshellarg($atmp));
