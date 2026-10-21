<?php
declare(strict_types=1);

/**
 * Pruebas de lógica del Panel Tukán Central (entorno simulado: SQLite temporal, sin red).
 * Uso: php tests/run_tests.php
 */

$tmp = sys_get_temp_dir() . '/tukan_central_logica_' . getmypid();
@mkdir($tmp, 0700, true);
putenv("TUKAN_CENTRAL_DATOS=$tmp");
putenv('TUKAN_CENTRAL_KEY=' . bin2hex(random_bytes(32)));
putenv('TUKAN_CENTRAL_ENV=/nonexistent');
putenv('TELEGRAM_BOT_TOKEN=');
require dirname(__DIR__) . '/app/bootstrap.php';

use Tukan\{Acceso, Ajustes, Analisis, Auditoria, Capacidades, Cifrado, ClienteV2, Clientes, Contacto, Db, ErrorPrograma, Estado, EstadoInvalido, Exportar, Finanzas, Formulario,
            Instancias, Licencias, Monitor, Operaciones, Productos, Qr, Sincronizacion, Sincronizador, Solicitudes, Token, Totp, Xlsx};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    $igual = is_float($esperado) || is_float($real) ? abs((float) $esperado - (float) $real) < 0.001 : $esperado === $real;
    if ($igual) {
        $ok++;
        echo "  OK    $nombre\n";
    } else {
        $fallos[] = $nombre;
        echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n";
    }
}
function check_error(string $nombre, string $texto, callable $f): void
{
    global $ok, $fallos;
    try {
        $f();
        $fallos[] = $nombre;
        echo "  FALLA $nombre — esperaba un error con [$texto] y no hubo error\n";
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $texto)) {
            $ok++;
            echo "  OK    $nombre\n";
        } else {
            $fallos[] = $nombre;
            echo "  FALLA $nombre — el error fue [" . $e->getMessage() . "]\n";
        }
    }
}
function titulo(string $t): void { echo "\n== $t\n"; }

// ---------------------------------------------------------------------------
titulo('1. TOTP contra los vectores de prueba del RFC 6238 (apéndice B)');
$k1 = '12345678901234567890';
$k256 = '12345678901234567890123456789012';
$k512 = '1234567890123456789012345678901234567890123456789012345678901234';
$vectores = [
    [59, '94287082', '46119246', '90693936'],
    [1111111109, '07081804', '68084774', '25091201'],
    [1111111111, '14050471', '67062674', '99943326'],
    [1234567890, '89005924', '91819424', '93441116'],
    [2000000000, '69279037', '90698825', '38618901'],
    [20000000000, '65353130', '77737706', '47863826'],
];
foreach ($vectores as [$t, $sha1, $sha256, $sha512]) {
    check("SHA1   t=$t", $sha1, Totp::codigo($k1, $t, 8, 'sha1', false));
    check("SHA256 t=$t", $sha256, Totp::codigo($k256, $t, 8, 'sha256', false));
    check("SHA512 t=$t", $sha512, Totp::codigo($k512, $t, 8, 'sha512', false));
    check("6 dígitos = últimos 6 (t=$t)", substr($sha1, 2), Totp::codigo(Totp::base32($k1), $t));
}
check('Base32 de "12345678901234567890" (RFC)', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32($k1));
check('Base32 ida y vuelta', $k1, Totp::desdeBase32('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
check('Base32 con letra mala -> null', null, Totp::desdeBase32('GEZD1'));
$s = Totp::nuevoSecreto();
check('Secreto nuevo: 32 letras base32 (160 bits)', 1, preg_match('/^[A-Z2-7]{32}$/', $s));
$t0 = 1760371200;
$c = Totp::codigo($s, $t0);
check('Verifica el código del momento', Totp::paso($t0), Totp::verificar($s, $c, $t0));
check('Acepta 30 s antes (ventana -1)', Totp::paso($t0), Totp::verificar($s, $c, $t0 + 30));
check('Acepta 30 s después (ventana +1)', Totp::paso($t0), Totp::verificar($s, $c, $t0 - 30));
check('Rechaza 60 s después (fuera de ventana)', null, Totp::verificar($s, $c, $t0 + 60));
check('Rechaza 60 s antes', null, Totp::verificar($s, $c, $t0 - 60));
check('Rechaza el mismo paso ya usado (no se repite)', null, Totp::verificar($s, $c, $t0, Totp::paso($t0)));
check('Acepta código con espacios "123 456"', Totp::paso($t0), Totp::verificar($s, substr($c, 0, 3) . ' ' . substr($c, 3), $t0));
check('Rechaza 5 dígitos', null, Totp::verificar($s, substr($c, 0, 5), $t0));
$uri = Totp::uri('JBSWY3DPEHPK3PXP', 'dimitry@tukan.ec');
check('URI otpauth', 'otpauth://totp/Tuk%C3%A1n%20Central:dimitry%40tukan.ec?secret=JBSWY3DPEHPK3PXP&issuer=Tuk%C3%A1n%20Central&algorithm=SHA1&digits=6&period=30', $uri);

// ---------------------------------------------------------------------------
titulo('2. Código QR (PHP puro)');
$m = Qr::matriz($uri);
$v = Qr::version($uri);
check('Tamaño = 17 + 4 × versión', 17 + 4 * $v, count($m));
$finder = fn(array $m, int $x, int $y) => implode('', array_map(fn($f) => implode('', array_slice($f, $x, 7)), array_slice($m, $y, 7)));
$patron = '1111111100000110111011011101101110110000011111111';
check('Cuadro arriba-izquierda', $patron, $finder($m, 0, 0));
check('Cuadro arriba-derecha', $patron, $finder($m, count($m) - 7, 0));
check('Cuadro abajo-izquierda', $patron, $finder($m, 0, count($m) - 7));
check('Módulo oscuro fijo', 1, $m[count($m) - 8][8]);
check('Versión 1 para texto corto', 1, Qr::version('hola'));
check('Versión 20 para 666 bytes', 20, Qr::version(str_repeat('a', 666)));
check_error('Texto demasiado largo', 'demasiado largo', fn() => Qr::version(str_repeat('a', 700)));
check('SVG sin estilos en línea', 0, preg_match('/style=/', Qr::svg($uri)));
// Lectura real con OpenCV (si está instalado): textos de distintos tamaños, versiones 1 a 20
$py = trim((string) shell_exec('command -v python3'));
$hayCv = $py !== '' && trim((string) shell_exec("python3 -c 'import cv2; print(1)' 2>/dev/null")) === '1' && function_exists('imagecreate');
if ($hayCv) {
    $textos = [$uri, 'otpauth://totp/x?secret=ABC', str_repeat('Tukán Central ', 10)];
    foreach ([14, 60, 120, 200, 300, 450, 600, 660] as $n) $textos[] = substr(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz-_.', 20), 0, $n);
    $carpetaQr = "$tmp/qr";
    @mkdir($carpetaQr);
    foreach ($textos as $i => $tx) {
        file_put_contents("$carpetaQr/$i.png", Qr::png($tx, 6));
        file_put_contents("$carpetaQr/$i.txt", $tx);
    }
    $script = <<<'PY'
import cv2, glob, sys, os
d1, d2 = cv2.QRCodeDetector(), cv2.QRCodeDetectorAruco()
for f in sorted(glob.glob(os.path.join(sys.argv[1], '*.png')), key=lambda p: int(os.path.basename(p)[:-4])):
    img = cv2.imread(f, cv2.IMREAD_GRAYSCALE); esperado = open(f[:-4] + '.txt', encoding='utf-8').read(); bien = False
    for d in (d1, d2):
        for esc in (1, 0.5, 1.5):
            im = img if esc == 1 else cv2.resize(img, None, fx=esc, fy=esc, interpolation=cv2.INTER_NEAREST)
            try: t, _, _ = d.detectAndDecode(im)
            except Exception: t = ''
            if t == esperado: bien = True; break
        if bien: break
    print(os.path.basename(f), 'ok' if bien else 'mal')
PY;
    file_put_contents("$tmp/leer_qr.py", $script);
    $salida = (string) shell_exec('python3 -I ' . escapeshellarg("$tmp/leer_qr.py") . ' ' . escapeshellarg($carpetaQr) . ' 2>&1');
    foreach ($textos as $i => $tx) {
        check('OpenCV lee el QR (' . strlen($tx) . ' bytes, versión ' . Qr::version($tx) . ')', 1, preg_match("/^$i\\.png ok$/m", $salida));
    }
} else {
    echo "  (se omite la lectura con OpenCV: no está instalado)\n";
}

// ---------------------------------------------------------------------------
titulo('3. Token del protocolo: byte por byte igual al ejemplo de referencia (§7)');
function tc_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function tc_unb64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }
function tc_firmar(array $p, string $secreto): string {
    $a = tc_b64(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $a . '.' . tc_b64(hash_hmac('sha256', $a, $secreto, true));
}
$sec = str_repeat('0123456789abcdef', 4);
$ejemplo = ['v' => 1, 'tipo' => 'sso', 'inst' => 'tuevento', 'email' => 'dimitryfierro@gmail.com', 'nombre' => 'Dimitry', 'iat' => 1760371200, 'exp' => 1760371260, 'nonce' => str_repeat('ab', 16)];
check('Payload en el orden exacto del protocolo', json_encode($ejemplo), json_encode(Token::payload('sso', 'tuevento', 'dimitryfierro@gmail.com', 'Dimitry', 1760371200, str_repeat('ab', 16))));
check('Firma idéntica a tc_firmar (ejemplo del protocolo)', tc_firmar($ejemplo, $sec), Token::firmar($ejemplo, $sec));
$conTildes = Token::payload('estado', 'dogssy', 'peña@ejemplo.ec', 'José Muñoz / Tukán', 1760371200, str_repeat('cd', 16));
check('Idéntica con tildes, ñ y "/" (sin escapar)', tc_firmar($conTildes, $sec), Token::firmar($conTildes, $sec));
check('JSON viaja con UTF-8 crudo y "/" sin escapar', true, str_contains((string) tc_unb64(explode('.', Token::firmar($conTildes, $sec))[0]), 'José Muñoz / Tukán'));
$tok = Token::crear('sso', 'tuevento', 'd@x.ec', 'D', $sec, 1760371200);
$pl = json_decode((string) tc_unb64(explode('.', $tok)[0]), true);
check('exp - iat = 60', 60, $pl['exp'] - $pl['iat']);
check('nonce de 32 hex', 1, preg_match('/^[0-9a-f]{32}$/', $pl['nonce']));
check('Sin relleno "="', false, str_contains($tok, '='));
check('Solo caracteres seguros para URL', 1, preg_match('/^[A-Za-z0-9_.-]+$/', $tok));
check('Nonce distinto en cada token', true, Token::crear('sso', 'a1', 'd@x.ec', 'D', $sec) !== Token::crear('sso', 'a1', 'd@x.ec', 'D', $sec));
check('Clave del HMAC = secreto como texto (no hex decodificado)', tc_b64(hash_hmac('sha256', explode('.', $tok)[0], $sec, true)), explode('.', $tok)[1]);
check('Valida bien', 'tuevento', Token::validar($tok, $sec, 'sso', 'tuevento', 1760371210)['inst']);
check_error('Firma mala', 'firma', fn() => Token::validar($tok, str_repeat('f', 64), 'sso', 'tuevento', 1760371210));
check_error('Otro inst', 'inst', fn() => Token::validar($tok, $sec, 'sso', 'tusalon', 1760371210));
check_error('Tipo cambiado', 'tipo', fn() => Token::validar($tok, $sec, 'estado', 'tuevento', 1760371210));
check_error('Vencido (más de exp+30)', 'vencido', fn() => Token::validar($tok, $sec, 'sso', 'tuevento', 1760371260 + 31));
check('Tolerancia: exp+30 todavía sirve', 'tuevento', Token::validar($tok, $sec, 'sso', 'tuevento', 1760371260 + 30)['inst']);
check_error('Del futuro (iat > ahora+30)', 'futuro', fn() => Token::validar($tok, $sec, 'sso', 'tuevento', 1760371200 - 31));
check_error('Tipo no válido al crear', 'no valido', fn() => Token::payload('otro', 'x1', 'a@b.ec', 'x'));

// ---------------------------------------------------------------------------
titulo('4. Cifrado de secretos (XChaCha20-Poly1305)');
$cif = new Cifrado();
$g = $cif->cifrar($sec, 'instancia:tuevento');
check('Ida y vuelta', $sec, $cif->descifrar($g, 'instancia:tuevento'));
check('No guarda el secreto en claro', false, str_contains($g, $sec));
check('Dos cifrados del mismo texto son distintos (nonce al azar)', true, $g !== $cif->cifrar($sec, 'instancia:tuevento'));
check_error('Otro contexto no lo abre (no se puede copiar de fila)', 'No se pudo leer', fn() => $cif->descifrar($g, 'instancia:tusalon'));
check_error('Otra llave no lo abre', 'No se pudo leer', fn() => (new Cifrado(bin2hex(random_bytes(32))))->descifrar($g, 'instancia:tuevento'));
$alterado = substr($g, 0, -4) . (substr($g, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check_error('Dato alterado se detecta', 'No se pudo leer', fn() => $cif->descifrar($alterado, 'instancia:tuevento'));
check_error('Sin llave en .env: error claro', 'TUKAN_CENTRAL_KEY', fn() => new Cifrado(''));
check('Llave nueva de 64 hex', 1, preg_match('/^[0-9a-f]{64}$/', Cifrado::nuevaLlave()));

// ---------------------------------------------------------------------------
titulo('5. Usuarios, bloqueo por intentos y códigos de respaldo');
$db = Db::conectar("$tmp/logica.sqlite");
$acc = new Acceso($db, $cif);
[$uid] = $acc->guardarUsuario('Dimitry Fierro', 'Dimitry@Tukan.ec', 'claveMuySegura1');
check('Correo se guarda en minúsculas', 'dimitry@tukan.ec', $acc->usuario($uid)['email']);
check('Contraseña con password_hash', true, password_verify('claveMuySegura1', $acc->usuario($uid)['clave_hash']));
check_error('Contraseña corta', 'al menos 10', fn() => $acc->guardarUsuario('Xavier', 'x@y.ec', 'corta'));
check_error('Correo malo', 'correo', fn() => $acc->guardarUsuario('Xavier', 'no-es-correo', 'claveMuySegura1'));
$ip = '200.1.2.3';
$ahora = 1760371200;
for ($i = 1; $i <= 4; $i++) $acc->verificarClave('dimitry@tukan.ec', 'mala', $ip, $ahora + $i);
check('4 errores: todavía no bloquea', 0, $acc->bloqueado('dimitry@tukan.ec', $ip, $ahora + 5));
$r = $acc->verificarClave('dimitry@tukan.ec', 'mala', $ip, $ahora + 5);
check('5.º error: bloqueado', true, $r['bloqueo'] > 0);
check('Bloqueado ~15 minutos', 900, $acc->bloqueado('dimitry@tukan.ec', $ip, $ahora + 5));
check('Bloqueado aunque ponga la contraseña buena', false, $acc->verificarClave('dimitry@tukan.ec', 'claveMuySegura1', $ip, $ahora + 60)['ok']);
check('Bloqueo por correo también desde otra IP', true, $acc->bloqueado('dimitry@tukan.ec', '9.9.9.9', $ahora + 60) > 0);
check('Bloqueo por IP también con otro correo', true, $acc->bloqueado('otro@tukan.ec', $ip, $ahora + 60) > 0);
check('Mensaje en palabras simples', true, str_contains(Acceso::textoBloqueo(840), 'espera 14 minutos'));
check('A los 15 minutos se desbloquea', true, $acc->verificarClave('dimitry@tukan.ec', 'claveMuySegura1', '9.9.9.9', $ahora + 5 + 901)['ok']);
check('Correo que no existe: mismo mensaje', 'El correo o la contraseña no son correctos.', $acc->verificarClave('nadie@tukan.ec', 'x', '5.5.5.5', $ahora)['error']);
$acc->limpiarFallos('dimitry@tukan.ec', $ip);
check('Limpiar intentos después de entrar', 0, $acc->bloqueado('dimitry@tukan.ec', $ip, $ahora + 10));
// 2FA
$sec2 = Totp::nuevoSecreto();
check('Activar con código malo no activa', null, $acc->activarTotp($uid, $sec2, '000000', $ahora));
$codigos = $acc->activarTotp($uid, $sec2, Totp::codigo($sec2, $ahora), $ahora);
check('Activar devuelve 8 códigos de respaldo', 8, count($codigos));
check('Formato de los códigos xxxx-xxxx', 8, count(array_filter($codigos, fn($c) => preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}$/', $c))));
$u = $acc->usuario($uid);
check('Secreto 2FA guardado cifrado', false, str_contains((string) $u['totp_cif'], $sec2));
check('Códigos guardados con hash (no en claro)', 0, (int) $db->query("SELECT count(*) FROM codigos_respaldo WHERE hash = '" . $codigos[0] . "'")->fetchColumn());
check('El código usado al activar no sirve otra vez', null, $acc->verificarCodigo($u, Totp::codigo($sec2, $ahora), $ip, $ahora));
$u = $acc->usuario($uid);
check('Código del paso siguiente sí sirve', 'totp', $acc->verificarCodigo($u, Totp::codigo($sec2, $ahora + 30), $ip, $ahora + 30));
check('Código de respaldo sirve', 'respaldo', $acc->verificarCodigo($acc->usuario($uid), $codigos[3], $ip, $ahora + 31));
check('El mismo código de respaldo no sirve dos veces', null, $acc->verificarCodigo($acc->usuario($uid), $codigos[3], $ip, $ahora + 32));
check('Respaldo sin guion y en mayúsculas', 'respaldo', $acc->verificarCodigo($acc->usuario($uid), strtoupper(str_replace('-', '', $codigos[4])), $ip, $ahora + 33));
check('Quedan 6 códigos', 6, $acc->codigosRestantes($uid));
check('Códigos nuevos reemplazan a los anteriores', 8, count($acc->nuevosCodigosRespaldo($uid)));
check('Un código viejo ya no sirve', null, $acc->verificarCodigo($acc->usuario($uid), $codigos[0], '7.7.7.7', $ahora + 34));
$acc->limpiarFallos('dimitry@tukan.ec', $ip);
// Sesiones
$ref = $acc->abrirSesion($uid, $ip, 'Mozilla/5.0 (Android) Chrome/120', $ahora);
check('Sesión vigente', null, $acc->revisarSesion($ref, $uid, $ahora + 60));
check('Inactiva 30 min -> se cierra', 'inactiva', Acceso::motivoVencida($ahora, $ahora, $ahora + 1801));
check('29 min sin usar -> sigue', null, Acceso::motivoVencida($ahora, $ahora, $ahora + 1799));
check('Máximo 12 horas aunque la use', 'maxima', Acceso::motivoVencida($ahora, $ahora + 43200, $ahora + 43201));
check('revisarSesion cierra por inactividad', 'inactiva', $acc->revisarSesion($ref, $uid, $ahora + 60 + 1801));
check('Después de cerrada no revive', 'cerrada', $acc->revisarSesion($ref, $uid, $ahora + 60 + 1802));
check('Sesión de otro usuario no sirve', 'cerrada', $acc->revisarSesion($acc->abrirSesion($uid, $ip, '', $ahora), $uid + 99, $ahora));
// Roles
check('Dueño: puede todo', true, Acceso::puede('dueno', 'programas') && Acceso::puede('dueno', 'usuarios') && Acceso::puede('dueno', 'auditoria'));
check('Soporte: entra a programas', true, Acceso::puede('soporte', 'entrar') && Acceso::puede('soporte', 'ver') && Acceso::puede('soporte', 'probar'));
check('Soporte: NO agrega/borra programas', false, Acceso::puede('soporte', 'programas'));
check('Soporte: NO ve secretos ni usuarios ni auditoría', false, Acceso::puede('soporte', 'secretos') || Acceso::puede('soporte', 'usuarios') || Acceso::puede('soporte', 'auditoria') || Acceso::puede('soporte', 'ajustes'));
check('Rol desconocido: nada', false, Acceso::puede('hacker', 'ver'));
check_error('No se puede quitar el último dueño', 'al menos un dueño', fn() => $acc->cambiarRol($uid, 'soporte'));
check_error('No se puede desactivar el último dueño', 'al menos un dueño', fn() => $acc->activar($uid, false));
[$sid] = $acc->guardarUsuario('Socio', 'socio@tukan.ec', 'claveMuySegura2', 'soporte');
check('Usuario soporte creado', 'soporte', $acc->usuario($sid)['rol']);

// ---------------------------------------------------------------------------
titulo('6. Programas (instancias) y comandos');
$ins = new Instancias($db, $cif);
check('ID válido: tuevento', true, Instancias::idValido('tuevento'));
check('ID válido: huellitas-sur_2', true, Instancias::idValido('huellitas-sur_2'));
check('ID inválido: mayúsculas', false, Instancias::idValido('TuEvento'));
check('ID inválido: empieza con guion', false, Instancias::idValido('-rm'));
check('ID inválido: 1 letra', false, Instancias::idValido('a'));
check('ID inválido: espacio / ;', false, Instancias::idValido('a b') || Instancias::idValido('a;b'));
check('URL sin https se completa', 'https://tuevento.tukanec.com', Instancias::normalizarUrl('tuevento.tukanec.com/'));
check('URL quita /tukan_central.php', 'https://x.tukanec.com/sub', Instancias::normalizarUrl('https://x.tukanec.com/sub/tukan_central.php'));
check_error('http:// no se acepta', 'https://', fn() => Instancias::normalizarUrl('http://tuevento.tukanec.com'));
check('http://127.0.0.1 sí (pruebas)', 'http://127.0.0.1:8311', Instancias::normalizarUrl('http://127.0.0.1:8311'));
check_error('URL con ?', 'sin ?', fn() => Instancias::normalizarUrl('https://x.ec/?a=1'));
$secTe = $ins->crear(['id' => 'tuevento', 'producto' => 'tuevento', 'url' => 'https://tuevento.tukanec.com', 'nombre' => '']);
check('Secreto de 64 hex', 1, preg_match('/^[0-9a-f]{64}$/', $secTe));
check('Nombre por defecto = nombre del producto', 'TuEvento', $ins->obtener('tuevento')['nombre']);
check('Secreto guardado cifrado', false, str_contains($ins->obtener('tuevento')['secreto_cif'], $secTe));
check('Se puede leer el secreto', $secTe, $ins->secreto('tuevento'));
check_error('ID repetido', 'Ya hay', fn() => $ins->crear(['id' => 'tuevento', 'producto' => 'tuevento', 'url' => 'https://a.ec']));
check_error('Tipo desconocido', 'tipo', fn() => $ins->crear(['id' => 'xx', 'producto' => 'erp', 'url' => 'https://a.ec']));
$secG = $ins->crear(['id' => 'dogssy', 'producto' => 'tuguarderia', 'url' => 'https://dogssy.com', 'nombre' => 'TuGuardería Dogssy']);
check('TuGuardería: carpeta = ID si no se escribe', 'dogssy', $ins->obtener('dogssy')['carpeta']);
check_error('TuGuardería: carpeta inválida', 'carpeta', fn() => $ins->crear(['id' => 'huella', 'producto' => 'tuguarderia', 'url' => 'https://h.ec', 'carpeta' => 'Mala Carpeta']));
$central = 'https://panel.tukanec.com';
$cmds = [
    'tuevento'    => "sudo bash /var/www/tuevento/deploy/conectar_central.sh tuevento $secTe $central",
    'tuguarderia' => "sudo bash /home/qf/github/tuguarderia/conectar_central.sh dogssy dogssy $secG $central",
];
check('Comando TuEvento exacto', $cmds['tuevento'], Instancias::comando($ins->obtener('tuevento'), $secTe, $central));
check('Comando TuGuardería exacto (CARPETA ID SECRETO URL)', $cmds['tuguarderia'], Instancias::comando($ins->obtener('dogssy'), $secG, $central));
$plantillas = [
    'tusalon'    => 'sudo bash /var/www/tusalon/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'tuvocalia'  => 'sudo bash /var/www/ligas/vps/conectar_central.sh {ID} {SECRETO} {URL}',
    'tuacademia' => 'sudo bash /var/www/tuacademia/vps/conectar_central.sh {ID} {SECRETO} {URL}',
    'tuplanner'  => 'sudo bash /var/www/tuplanner/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'tuhospedaje' => 'sudo bash /var/www/tuhospedaje/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'turemolque' => 'sudo bash /var/www/turemolque/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'tuspa' => 'sudo bash /var/www/tuspa/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'tugroomer' => 'sudo bash /var/www/tugroomer/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'erp_productoras' => 'sudo bash /var/www/erp-productoras/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
    'tupass' => 'sudo bash /var/www/tupass/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
];
foreach (['turemolque', 'erp_productoras', 'tupass'] as $u) check("$u es de una sola instalación (unico)", true, Productos::tipo($u)['unico']);
// TuPlanner: la plantilla es la misma que dice su script (si el repositorio está al lado)
$scriptTp = dirname(__DIR__, 2) . '/tuplanner/deploy/conectar_central.sh';
if (is_file($scriptTp)) {
    $txtTp = (string) file_get_contents($scriptTp);
    check('Plantilla de TuPlanner = la de su conectar_central.sh (ID SECRETO URL)', [true, true],
          [str_contains($txtTp, '#   sudo bash /var/www/tuplanner/deploy/conectar_central.sh ID SECRETO URL'), str_contains($txtTp, 'APP="${APP:-/var/www/tuplanner}"')]);
}
foreach ($plantillas as $tipo => $pl) check("Plantilla de $tipo", $pl, Productos::tipo($tipo)['comando']);
$cmd = Productos::comando(Productos::tipo('tusalon')['comando'], ['ID' => 'tusalon', 'SECRETO' => $secTe, 'URL' => $central]);
check('Comando renderizado sin tildes ni ñ', 0, preg_match('/[^\x20-\x7E]/', $cmd));
foreach (Productos::TIPOS as $k => $t) check("Plantilla $k sin letras especiales", null, Productos::errorPlantilla($t['comando']));
check('Plantilla propia sin {SECRETO}: error', 'El comando debe tener {SECRETO}.', Productos::errorPlantilla('sudo bash x.sh {ID}'));
check('Plantilla con marcador desconocido: error', true, str_contains((string) Productos::errorPlantilla('bash x.sh {ID} {SECRETO} {CLAVE}'), '{CLAVE}'));
check('Plantilla con tilde: error', true, str_contains((string) Productos::errorPlantilla('sudo bash /var/www/tusalón/x.sh {ID} {SECRETO}'), 'tildes'));
check('Plantilla con ; : error (seguridad)', true, str_contains((string) Productos::errorPlantilla('bash x.sh {ID} {SECRETO}; rm -rf /'), 'seguridad'));
check_error('Secreto no hex no se pega en el comando', 'Secreto', fn() => Productos::comando($plantillas['tusalon'], ['ID' => 'tusalon', 'SECRETO' => 'abc; rm', 'URL' => $central]));
check_error('URL del panel rara no se pega', 'TUKAN_CENTRAL_URL', fn() => Productos::comando($plantillas['tusalon'], ['ID' => 'tusalon', 'SECRETO' => $secTe, 'URL' => 'https://x.ec/$(id)']));
$ins->editar('tuevento', ['producto' => 'tuevento', 'url' => 'https://tuevento.tukanec.com', 'nombre' => 'TuEvento', 'plantilla' => 'sudo bash /opt/te/conectar.sh {ID} {SECRETO} {URL}', 'activo' => 1]);
check('Plantilla propia por programa', "sudo bash /opt/te/conectar.sh tuevento $secTe $central", Instancias::comando($ins->obtener('tuevento'), $secTe, $central));
$nuevo = $ins->rotar('tuevento');
check('Cambiar secreto: es otro', true, $nuevo !== $secTe);
check('Cambiar secreto: queda guardado', $nuevo, $ins->secreto('tuevento'));
check('CSS de colores por producto', true, str_contains(Productos::css(), '.p-tuevento{--c:#8E3B52}'));

// ---------------------------------------------------------------------------
titulo('7. Respuesta de estado: revisión y JSON malformado');
$bueno = '{"ok":true,"protocolo":1,"producto":"tuevento","nombre":"TuEvento","version":"75df6b3","generado":"2026-10-13T12:00:00-05:00",
 "negocios":{"total":12,"prueba":4,"activos":6,"por_vencer":1,"vencidos":1,"suspendidos":0},"nuevos_30d":3,"ingresos_mes":414.00,"ingresos_mes_anterior":276.00,
 "pruebas_vencen_7d":[{"nombre":"Quinta Los Alamos","vence":"2026-10-17"}],"pagos_vencidos":[{"nombre":"Salón Imperial","desde":"2026-10-01"}],"extra":{"Eventos este mes":37,"Usuarios":41}}';
$d = Estado::validar($bueno);
check('Ejemplo del protocolo: producto', 'tuevento', $d['producto']);
check('Negocios total', 12, $d['negocios']['total']);
check('Ingresos como número', 414.0, $d['ingresos_mes']);
check('Pruebas que vencen', [['nombre' => 'Quinta Los Alamos', 'vence' => '2026-10-17']], $d['pruebas_vencen_7d']);
check('Extra', ['Eventos este mes' => 37, 'Usuarios' => 41], $d['extra']);
$g = Estado::validar('{"ok":true,"protocolo":1,"producto":"tuguarderia","negocios":null,"cliente":"Dogssy","extra":{"Mascotas activas":86},"pruebas_vencen_7d":[],"pagos_vencidos":[]}');
check('TuGuardería: negocios null', null, $g['negocios']);
check('TuGuardería: cliente', 'Dogssy', $g['cliente']);
check('Campos que faltan = null / lista vacía', [null, null, []], [$g['nuevos_30d'], $g['ingresos_mes'], $g['pagos_vencidos']]);
check('"extra": [] (PHP vacío) se acepta', [], Estado::validar('{"ok":true,"protocolo":1,"producto":"tusalon","extra":[]}')['extra']);
$lista = json_encode(['ok' => true, 'protocolo' => 1, 'producto' => 'tusalon', 'pagos_vencidos' => array_fill(0, 30, ['nombre' => 'X', 'desde' => '2026-10-01']),
    'extra' => array_combine(array_map(fn($i) => "k$i", range(1, 12)), range(1, 12))]);
check('Listas: máximo 20', 20, count(Estado::validar($lista)['pagos_vencidos']));
check('Extra: máximo 8', 8, count(Estado::validar($lista)['extra']));
check('Fecha mala se vuelve null', null, Estado::validar('{"ok":true,"protocolo":1,"producto":"x1","pagos_vencidos":[{"nombre":"A","desde":"ayer"}]}')['pagos_vencidos'][0]['desde']);
check('Texto con control se limpia', 'A B', Estado::validar("{\"ok\":true,\"protocolo\":1,\"producto\":\"x1\",\"cliente\":\"A\\u0000B\"}")['cliente']);
$malos = [
    'no es JSON'          => ['{"ok":true,', 'JSON'],
    'vacío'               => ['', 'JSON'],
    'lista en vez de objeto' => ['[1,2]', 'objeto'],
    'ok=false'            => ['{"ok":false,"error":"firma"}', 'ok=false: firma'],
    'sin protocolo'       => ['{"ok":true,"producto":"tuevento"}', 'protocolo'],
    'protocolo 2'         => ['{"ok":true,"protocolo":2,"producto":"tuevento"}', 'protocolo'],
    'protocolo "1" texto' => ['{"ok":true,"protocolo":"1","producto":"tuevento"}', 'protocolo'],
    'sin producto'        => ['{"ok":true,"protocolo":1}', 'producto'],
    'negocios lista'      => ['{"ok":true,"protocolo":1,"producto":"a1","negocios":[1,2]}', 'negocios'],
    'total negativo'      => ['{"ok":true,"protocolo":1,"producto":"a1","negocios":{"total":-1}}', 'negocios.total'],
    'total texto'         => ['{"ok":true,"protocolo":1,"producto":"a1","negocios":{"total":"doce"}}', 'negocios.total'],
    'ingresos texto'      => ['{"ok":true,"protocolo":1,"producto":"a1","ingresos_mes":"mucho"}', 'ingresos_mes'],
    'lista que no es lista' => ['{"ok":true,"protocolo":1,"producto":"a1","pagos_vencidos":{"a":1}}', 'pagos_vencidos'],
    'extra lista'         => ['{"ok":true,"protocolo":1,"producto":"a1","extra":[1,2]}', 'extra'],
    'demasiado profundo'  => [str_repeat('[', 40) . str_repeat(']', 40), 'JSON'],
];
foreach ($malos as $n => [$json, $texto]) check_error("Rechaza: $n", $texto, fn() => Estado::validar($json));
check('Acepta números como texto ("12")', 12, Estado::validar('{"ok":true,"protocolo":1,"producto":"a1","negocios":{"total":"12"}}')['negocios']['total']);

titulo('8. Errores de conexión en palabras simples');
check('Sin conexión (curl 7)', 'sin_conexion', Estado::clasificar(0, '', 7, 'Failed to connect')[0]);
check('Tiempo (curl 28)', 'tiempo', Estado::clasificar(0, '', 28)[0]);
check('DNS (curl 6)', 'dns', Estado::clasificar(0, '', 6)[0]);
check('Certificado (curl 60)', 'tls', Estado::clasificar(0, '', 60)[0]);
check('404 = programa sin conectar', 'no_conectado', Estado::clasificar(404, 'No encontrado')[0]);
check('401 firma = secreto incorrecto', 'secreto', Estado::clasificar(401, '{"ok":false,"error":"firma incorrecta"}')[0]);
check('401 vencido = reloj', 'reloj', Estado::clasificar(401, '{"ok":false,"error":"token vencido"}')[0]);
check('401 con reloj del programa corrido (Date) = reloj', 'reloj', Estado::clasificar(401, '{"ok":false,"error":"cualquier cosa"}', 0, '', 300)[0]);
check('401 inst = ID', 'id', Estado::clasificar(401, '{"ok":false,"error":"inst incorrecto"}')[0]);
check('401 nonce = repetido', 'repetido', Estado::clasificar(401, '{"ok":false,"error":"nonce ya usado"}')[0]);
check('401 otro motivo = rechazado con su texto', true, str_contains(Estado::clasificar(401, '{"ok":false,"error":"no quiero"}')[1], 'no quiero'));
check('429 = límite', 'limite', Estado::clasificar(429, '')[0]);
check('429: texto en palabras simples (esperar un minuto)', true, str_contains(Estado::clasificar(429, '')[1], 'Espera un minuto'));
check('400 = pedido no entendido, con el texto del programa', ['peticion', true], [Estado::clasificar(400, '{"ok":false,"error":"Acción no válida."}')[0], str_contains(Estado::clasificar(400, '{"ok":false,"error":"Acción no válida."}')[1], 'Acción no válida')]);
// Textos reales de los conectores (TuEvento/TuSalón, TuVocalía, TuGuardería, TuAcademia)
foreach ([
    'La firma del enlace no es correcta.' => 'secreto', 'Firma incorrecta' => 'secreto',
    'Este enlace ya se usó. Vuelve a intentar desde el Panel Tukán.' => 'repetido', 'Token ya usado' => 'repetido',
    'El enlace ya venció. Vuelve a intentar desde el Panel Tukán.' => 'reloj', 'Token con fecha futura' => 'reloj',
    'El enlace es de otra instalación.' => 'id', 'El token es para otra instancia' => 'id',
    'Versión del protocolo no soportada.' => 'version', 'El conector no está configurado.' => 'sin_configurar',
    'Demasiados pedidos desde esta conexión. Espere un minuto.' => 'limite',
    'El enlace no es para esta acción.' => 'rechazado',
] as $txt => $esperado) {
    check("401 real: \"$txt\" → $esperado", $esperado, Estado::clasificar(401, json_encode(['ok' => false, 'error' => $txt]))[0]);
}
check('500 = error del programa', 'error_programa', Estado::clasificar(500, '')[0]);
check('302 = redirección', 'redirige', Estado::clasificar(302, '')[0]);
check('Textos sin "null" ni códigos raros', false, str_contains(Estado::clasificar(404, '')[1], 'null'));
$i1 = ['id' => 'tuevento', 'producto' => 'tuevento', 'nombre' => 'TuEvento'];
check('interpretar: 200 bueno -> en línea', true, Monitor::interpretar($i1, 200, $bueno, 0, '', null, 80)['en_linea']);
check('interpretar: 200 JSON malo -> formato', 'formato', Monitor::interpretar($i1, 200, '{"ok":', 0, '', null, 80)['error']);
check('interpretar: producto distinto -> aviso', true, str_contains((string) Monitor::interpretar(['producto' => 'tusalon'] + $i1, 200, $bueno, 0, '', null, 80)['aviso'], 'tuevento'));

titulo('9. Totales, pendientes y disponibilidad');
$tab = [
    ['id' => 'a1', 'nombre' => 'TuEvento', 'producto' => 'tuevento', 'activo' => 1, 'consultado' => 1, 'en_linea' => 1, 'datos' => Estado::validar($bueno)],
    ['id' => 'b1', 'nombre' => 'TuSalón', 'producto' => 'tusalon', 'activo' => 1, 'consultado' => 1, 'en_linea' => 0, 'datos' => Estado::validar(str_replace(['"total":12', '414.00', '276.00', '2026-10-17', '"producto":"tuevento"'], ['"total":20', '100', '0', '2026-10-15', '"producto":"tusalon"'], $bueno))],
    ['id' => 'c1', 'nombre' => 'Dogssy', 'producto' => 'tuguarderia', 'activo' => 1, 'consultado' => 1, 'en_linea' => 1, 'datos' => $g],
    ['id' => 'd1', 'nombre' => 'Pausado', 'producto' => 'tuevento', 'activo' => 0, 'consultado' => null, 'en_linea' => 0, 'datos' => Estado::validar($bueno)],
];
$t = Monitor::totales($tab);
check('Total de negocios (solo activos, con último dato)', 32, $t['total']);
check('En prueba sumados', 8, $t['prueba']);
check('Ingresos del mes sumados (sin TuGuardería: son ventas de Dogssy, no de Tukán)', 514.0, $t['ingresos_mes']);
check('TuGuardería no cuenta como plata de Tukán', false, Monitor::ingresosSonDeTukan($tab[2]));
check('TuGuardería con 2110 no cambia el total', 514.0, Monitor::totales([$tab[0], $tab[1], ['datos' => ['ingresos_mes' => 2110.0, 'ingresos_mes_anterior' => 0.0] + $tab[2]['datos']] + $tab[2]])['ingresos_mes']);
check('TuEvento sí cuenta como plata de Tukán', true, Monitor::ingresosSonDeTukan($tab[0]));
check('Ingresos mes anterior', 276.0, $t['ingresos_mes_anterior']);
check('Variación %', 86.2, $t['variacion']);
check('En línea / sin conexión', [2, 1], [$t['en_linea'], $t['sin_conexion']]);
$p = Monitor::pendientes($tab);
check('Pendientes: pruebas de los 2 programas, ordenadas por fecha', ['2026-10-15', '2026-10-17'], array_column($p['pruebas'], 'vence'));
check('Pendientes: llevan el programa para el botón Entrar', 'b1', $p['pruebas'][0]['instancia']);
check('Pendientes: pagos vencidos', 2, count($p['pagos']));
$mon = new Monitor($db, $ins, null);
$ahora = time();
$inst = $ins->obtener('dogssy');
for ($h = 0; $h < 7 * 24; $h++) {
    $mon->guardar($inst, ['en_linea' => $h % 24 !== 5, 'http' => 200, 'ms' => 50, 'error' => null, 'error_detalle' => 'x', 'datos' => $g, 'aviso' => null], $ahora - (7 * 24 - $h) * 3600 + 60);
}
$dp = $mon->disponibilidad('dogssy', $ahora);
check('28 tramos de 6 horas', 28, count($dp['tramos']));
check('Disponibilidad %', round(161 / 168 * 100, 1), $dp['pct']);
check('Tramo con una caída = medio', true, in_array('medio', array_column($dp['tramos'], 'clase'), true));
check('Tramos sin caída = ok', true, in_array('ok', array_column($dp['tramos'], 'clase'), true));
check('Programa sin historial = sin datos', null, $mon->disponibilidad('tuevento', $ahora)['pct']);
check('Hay datos viejos (más de 2 min)', true, Monitor::hayViejos([['activo' => 1, 'consultado' => $ahora - 121]], $ahora));
check('Datos frescos', false, Monitor::hayViejos([['activo' => 1, 'consultado' => $ahora - 60]], $ahora));

titulo('10. Auditoría y textos de consola');
$aud = new Auditoria($db);
$aud->registrar('login_ok', 'prueba', null, ['id' => $uid, 'email' => 'dimitry@tukan.ec'], '1.2.3.4', 'UA');
$aud->registrar('entrar', 'Entró a TuEvento', 'tuevento', ['id' => $uid, 'email' => 'dimitry@tukan.ec'], '1.2.3.4', 'UA');
check('Filtro por acción', 1, $aud->buscar(['accion' => 'entrar'])['total']);
check('Filtro por programa', 1, $aud->buscar(['instancia' => 'tuevento'])['total']);
check('Filtro por grupo acceso', 1, $aud->buscar(['grupo' => 'acceso'])['total']);
check('Búsqueda de texto (con % escapado)', 0, $aud->buscar(['q' => '%'])['total']);
check('Búsqueda por IP', 2, $aud->buscar(['q' => '1.2.3.4'])['total']);
check('Consola sin tildes', 'Contrasena cambiada - accion', sin_tildes('Contraseña cambiada · acción'));
foreach (glob(dirname(__DIR__) . '/{bin,deploy}/*.{php,sh}', GLOB_BRACE) as $archivo) {
    $txt = (string) file_get_contents($archivo);
    preg_match_all('/(?:decir|echo|fwrite)\s*\(?\s*[\'"][^\n]*/u', $txt, $mm);
    $conTilde = array_filter($mm[0], fn($l) => preg_match('/[áéíóúñÁÉÍÓÚÑ]/u', $l));
    check('Mensajes de ' . basename($archivo) . ' sin tildes', [], array_values($conTilde));
}

require __DIR__ . '/run_tests_v2.php';
require __DIR__ . '/run_tests_bajas.php';
require __DIR__ . '/run_tests_pausa.php';
require __DIR__ . '/run_tests_modulos.php';
require __DIR__ . '/run_tests_correo.php';
require __DIR__ . '/run_tests_ia.php';
require __DIR__ . '/run_tests_actualizar.php';

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Correctas: $ok  ·  Fallas: " . count($fallos) . "\n";
if ($fallos) echo 'FALLARON: ' . implode(' | ', $fallos) . "\n";
array_map('unlink', glob("$tmp/qr/*") ?: []);
@rmdir("$tmp/qr");
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir("$tmp/sesiones");
@rmdir($tmp);
exit($fallos ? 1 : 0);
