<?php
declare(strict_types=1);

/**
 * Pruebas del correo de Tukán (compartido/tukan_correo.php), del «Enviar por correo» de Demo lista (src/CorreoDemo.php)
 * y de deploy/configurar_correo.sh. Las incluye tests/run_tests.php (usa $tmp, check, check_error, titulo).
 * El envío se prueba contra un SMTP DE MENTIRA (tests/smtp_falso.py, puertos 8250-8255) con STARTTLS, SSL y sin TLS.
 */

use Tukan\{Cifrado, CorreoDemo};

require_once dirname(__DIR__) . '/compartido/tukan_correo.php';

$ctmp = "$tmp/correo";
@mkdir($ctmp, 0755, true);
$correoEnv = function (array $v) use ($ctmp): string {
    $f = "$ctmp/env_" . bin2hex(random_bytes(4));
    file_put_contents($f, implode("\n", array_map(fn($k) => "$k=" . $v[$k], array_keys($v))) . "\n");
    putenv("TUKAN_CORREO_ENV=$f");
    tukan_correo_config(true);
    return $f;
};
foreach (['TUKAN_SMTP_HOST', 'TUKAN_SMTP_PUERTO', 'TUKAN_SMTP_SEGURIDAD', 'TUKAN_SMTP_USUARIO', 'TUKAN_SMTP_CLAVE', 'TUKAN_CORREO_DE', 'TUKAN_CORREO_NOMBRE'] as $k) putenv($k);

// ---------------------------------------------------------------------------
titulo('31. Correo de Tukán: configuración, validación y anti-inyección');
putenv("TUKAN_CORREO_ENV=$ctmp/no_existe.env");
tukan_correo_config(true);
check('Sin /etc/tukan-correo.env: no está listo', false, tukan_correo_listo());
check('...y enviar dice cómo configurarlo', [false, true], [tukan_correo_enviar('a@b.com', 'x', '', 'x', $cerr), str_contains((string) $cerr, 'configurar_correo.sh')]);
$claveRara = 'Cl@ve #1 = "ñ" ;x';
$correoEnv(['TUKAN_SMTP_HOST' => 'mail.qfproducciones.com', 'TUKAN_SMTP_PUERTO' => '587', 'TUKAN_SMTP_SEGURIDAD' => 'TLS', 'TUKAN_SMTP_USUARIO' => 'info@tukansoftware.com',
            'TUKAN_SMTP_CLAVE' => $claveRara, 'TUKAN_CORREO_DE' => 'info@tukansoftware.com', 'TUKAN_CORREO_NOMBRE' => '"Tukán Software"', '# comentario' => 'x']);
$cf = tukan_correo_config();
check('Lee el archivo: servidor, puerto, tls, usuario', ['mail.qfproducciones.com', 587, 'tls', 'info@tukansoftware.com'], [$cf['host'], $cf['puerto'], $cf['seguridad'], $cf['usuario']]);
check('La clave se lee tal cual (espacios, #, =, comillas, ñ)', $claveRara, $cf['clave']);
check('Nombre entre comillas: sin las comillas', 'Tukán Software', $cf['nombre']);
check('Reply-To por defecto = remitente; timeout corto (10 s)', ['info@tukansoftware.com', 10], [$cf['responder'], $cf['timeout']]);
check('Listo', true, tukan_correo_listo());
check('El estado para mostrar NO trae la clave', false, str_contains(json_encode(tukan_correo_estado(), JSON_UNESCAPED_UNICODE), $claveRara));
putenv('TUKAN_SMTP_PUERTO=465');
putenv('TUKAN_SMTP_SEGURIDAD=ssl');
tukan_correo_config(true);
check('Una variable de entorno gana sobre el archivo', [465, 'ssl'], [tukan_correo_config()['puerto'], tukan_correo_config()['seguridad']]);
putenv('TUKAN_SMTP_PUERTO');
putenv('TUKAN_SMTP_SEGURIDAD');
tukan_correo_config(true);
check('Correos válidos', [true, true], [tukan_correo_valido('ana.perez@negocio.com.ec'), tukan_correo_valido('a+b@x.io')]);
check('Correos con saltos de línea, <, >, coma o espacios: no', [false, false, false, false, false, false],
      [tukan_correo_valido("a@b.com\r\nBcc: x@y.com"), tukan_correo_valido("a@b.com\nBcc: x@y.com"), tukan_correo_valido('a@b.com>'), tukan_correo_valido('a@b.com, c@d.com'),
       tukan_correo_valido('a b@c.com'), tukan_correo_valido('')]);
check('Enviar a un correo con salto de línea: no se intenta', [false, 'El correo del destinatario no es válido.'], [tukan_correo_enviar("a@b.com\r\nBcc: x@y.com", 'x', '', 'x', $cerr), $cerr]);
check('Reply-To con salto de línea: no se intenta', false, tukan_correo_enviar('a@b.com', 'x', '', 'x', $cerr, ['responder_a' => "c@d.com\r\nBcc: x@y.com"]));
$msg = tukan_correo_armar('cliente@ejemplo.com', "Hola\r\nBcc: espia@malo.com", '<p>Hola</p>', 'Hola', ['nombre_para' => "Ana\r\nBcc: espia@malo.com"]);
[$cabs] = explode("\r\n\r\n", $msg, 2);
check('Asunto o nombre con salto de línea: no aparece una cabecera Bcc', 0, preg_match('/^Bcc:/mi', $cabs));
check('...ni un salto suelto en las cabeceras (todo CRLF)', 0, preg_match("/(?<!\r)\n/", $cabs));
check('Correo en la cabecera To entre < >', true, str_contains($cabs, '<cliente@ejemplo.com>'));

// ---------------------------------------------------------------------------
titulo('32. Correo de Tukán: mensaje UTF-8 multipart y plantilla de la demo');
$asuntoLargo = 'Su demo de TuVocalía está lista — ñandú, acción, corazón y café para la Liga Deportiva Calderón';
$msg = tukan_correo_armar('cliente@ejemplo.com', $asuntoLargo, '<p>Café ñandú</p>', "Línea 1\n.empieza con punto\nCafé ñandú", ['responder_a' => 'ventas@tukansoftware.com']);
[$cabs, $cuerpo] = explode("\r\n\r\n", $msg, 2);
preg_match('/^Subject: ([^\r\n]*(?:\r\n [^\r\n]*)*)/m', $cabs, $mS);
check('Asunto con =?UTF-8?B?...?=', 1, preg_match('/^=\?UTF-8\?B\?/', $mS[1] ?? ''));
check('...se decodifica igual (sin cortar letras)', "Subject: $asuntoLargo", iconv_mime_decode('Subject: ' . ($mS[1] ?? ''), 0, 'UTF-8'));
check('...cada palabra codificada de 75 o menos', true, max(array_map('strlen', preg_split('/\s+/', $mS[1] ?? ''))) <= 75);
check('Remitente "Tukán Software" codificado', true, str_contains($cabs, 'From: =?UTF-8?B?' . base64_encode('Tukán Software') . '?= <info@tukansoftware.com>'));
check('Reply-To configurable', true, str_contains($cabs, 'Reply-To: <ventas@tukansoftware.com>'));
check('Cabeceras: Date, Message-ID, MIME-Version', [1, 1, 1], [preg_match('/^Date: /m', $cabs), preg_match('/^Message-ID: <[0-9a-f]+\.\d+@tukansoftware\.com>\r?$/m', $cabs), preg_match('/^MIME-Version: 1\.0\r?$/m', $cabs)]);
check('multipart/alternative con límite', 1, preg_match('/Content-Type: multipart\/alternative; boundary="([^"]+)"/', $cabs, $mB));
$partes = explode('--' . ($mB[1] ?? 'x'), $cuerpo);
check('Dos partes (texto y HTML) y cierre', [4, true], [count($partes), str_starts_with($partes[3] ?? '', '--')]);
check('Parte 1: text/plain UTF-8 quoted-printable', 1, preg_match('/Content-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable/', $partes[1] ?? ''));
check('Parte 2: text/html UTF-8 quoted-printable', 1, preg_match('/Content-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable/', $partes[2] ?? ''));
check('El texto se decodifica en UTF-8 (CRLF)', "Línea 1\r\n.empieza con punto\r\nCafé ñandú", trim(quoted_printable_decode(explode("\r\n\r\n", $partes[1] ?? '', 2)[1] ?? '')));
check('Ninguna línea de más de 78', true, max(array_map('strlen', explode("\r\n", $msg))) <= 78);
check('Sin HTML: un solo text/plain', 1, preg_match('/^Content-Type: text\/plain; charset=UTF-8\r?$/m', tukan_correo_armar('a@b.com', 'x', '', 'hola')));
check('Sin texto: se saca del HTML', true, str_contains(quoted_printable_decode(tukan_correo_armar('a@b.com', 'x', '<p>Hola <b>Ana</b></p><p>Chao</p>', '')), "Hola Ana\r\n\r\nChao"));

$dm = tukan_correo_demo(['programa' => 'TuVocalía', 'enlace' => 'https://ligas.tukanec.com/admin.php', 'usuario' => 'roberto@calderon.ec', 'clave' => 'k7#Qp<9x&Z',
                         'hasta' => '2026-10-30', 'contacto' => 'Roberto Calderón', 'negocio' => 'Liga <script>alert(1)</script>']);
check('Asunto de la demo', 'Su demo de TuVocalía está lista', $dm['asunto']);
check('HTML con la marca (azul marino y amarillo)', [true, true, true], [str_contains($dm['html'], '#14213D'), str_contains($dm['html'], '#FFC93C'), str_contains($dm['html'], 'Tukán</span>')]);
check('Botón «Entrar a su demo» al enlace', 1, preg_match('#<a href="https://ligas\.tukanec\.com/admin\.php"[^>]*>Entrar a su demo</a>#', $dm['html']));
check('Usuario y clave (escapada en el HTML)', [true, true, true], [str_contains($dm['html'], 'roberto@calderon.ec'), str_contains($dm['html'], 'k7#Qp&lt;9x&amp;Z'), str_contains($dm['texto'], 'Contraseña: k7#Qp<9x&Z')]);
check('Fecha en que termina, en palabras', [true, true], [str_contains($dm['html'], 'viernes 30 de octubre de 2026'), str_contains($dm['texto'], 'Su demo termina el viernes 30 de octubre de 2026.')]);
check('Recomienda cambiar la contraseña', [true, true], [str_contains($dm['html'], 'cambiar la contraseña'), str_contains($dm['texto'], 'cambiar la contraseña')]);
check('Contacto: WhatsApp 099 640 8397 · info@tukansoftware.com · tukansoftware.com', [true, true, true],
      [str_contains($dm['html'], 'WhatsApp 099 640 8397'), str_contains($dm['html'], 'mailto:info@tukansoftware.com'), str_contains($dm['texto'], 'WhatsApp 099 640 8397 · info@tukansoftware.com · tukansoftware.com')]);
check('Saluda por el nombre', true, str_contains($dm['texto'], 'Hola Roberto,'));
check('Nada de «gratis» ni precios', [0, 0], [preg_match('/grat/i', $dm['html'] . $dm['texto']), preg_match('/\$\s?\d|USD|precio/i', $dm['html'] . $dm['texto'])]);
check('Lo que escribe el cliente no se cuela como HTML', [false, true], [str_contains($dm['html'], '<script>'), str_contains($dm['html'], '&lt;script&gt;')]);
check('Enlace que no es http(s): sin botón', false, str_contains(tukan_correo_demo(['programa' => 'X', 'enlace' => 'javascript:alert(1)'])['html'], 'javascript:'));

// ---------------------------------------------------------------------------
titulo('33. Correo de Tukán: envío real contra un SMTP de mentira (STARTTLS, SSL, sin TLS)');
$py = trim((string) shell_exec('command -v python3'));
$ossl = trim((string) shell_exec('command -v openssl'));
$procs = [];
register_shutdown_function(function () use (&$procs): void { foreach ($procs as $p) { @proc_terminate($p); @proc_close($p); } });
$smtp = function (int $puerto, string $modo, array $extra = []) use (&$procs, $ctmp): string {
    $dir = "$ctmp/smtp_$puerto";
    @mkdir($dir, 0755, true);
    array_map('unlink', glob("$dir/*") ?: []);
    $cmd = array_merge(['python3', dirname(__DIR__) . '/tests/smtp_falso.py', '--puerto', (string) $puerto, '--modo', $modo, '--cert', "$ctmp/cert.pem", '--key', "$ctmp/key.pem",
                        '--usuario', 'info@tukansoftware.com', '--clave', 'Cl@ve ñ #1', '--dir', $dir], $extra);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$dir.log", 'a']], $pipes);
    $procs[] = $p;
    stream_set_timeout($pipes[1], 5);
    fgets($pipes[1]);   // "LISTO"
    return $dir;
};
$ultimo = function (string $dir): array {
    static $vistos = [];
    for ($i = 0; $i < 40; $i++) {   // el servidor anota la conversación al cerrarse: se espera hasta 2 s
        $n = count(array_filter(explode("\n", (string) @file_get_contents("$dir/registro.jsonl"))));
        if ($n > ($vistos[$dir] ?? 0)) break;
        usleep(50000);
    }
    $vistos[$dir] = $n;
    $f = glob("$dir/*.eml") ?: [];
    usort($f, fn($a, $b) => (int) basename($b) <=> (int) basename($a));
    $l = array_filter(explode("\n", (string) @file_get_contents("$dir/registro.jsonl")));
    return [$f ? (string) file_get_contents($f[0]) : '', $l ? json_decode((string) end($l), true) : null];
};
if ($py === '' || $ossl === '') {
    echo "  (se omite: falta python3 u openssl)\n";
} else {
    shell_exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$ctmp/key.pem") . ' -out ' . escapeshellarg("$ctmp/cert.pem")
             . " -days 2 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' 2>/dev/null");
    $d587 = $smtp(8250, 'starttls');
    $d465 = $smtp(8251, 'ssl');
    $dPl = $smtp(8252, 'plano');
    $dPlain = $smtp(8253, 'starttls', ['--sin-auth-login']);
    $smtp(8254, 'plano', ['--mudo']);
    $base = ['TUKAN_SMTP_HOST' => 'localhost', 'TUKAN_SMTP_PUERTO' => '8250', 'TUKAN_SMTP_SEGURIDAD' => 'tls', 'TUKAN_SMTP_USUARIO' => 'info@tukansoftware.com',
             'TUKAN_SMTP_CLAVE' => 'Cl@ve ñ #1', 'TUKAN_CORREO_DE' => 'info@tukansoftware.com', 'TUKAN_CORREO_NOMBRE' => 'Tukán Software', 'TUKAN_SMTP_CA' => "$ctmp/cert.pem", 'TUKAN_SMTP_TIMEOUT' => '3'];

    $correoEnv($base);
    $cok = tukan_correo_enviar('roberto@calderon.ec', $dm['asunto'], $dm['html'], $dm['texto'] . "\n.línea que empieza con punto", $cerr, ['nombre_para' => 'Roberto Calderón']);
    check('STARTTLS (587) con certificado verificado: enviado', [true, null], [$cok, $cerr]);
    [$eml, $reg] = $ultimo($d587);
    check('...entró con AUTH LOGIN ya con TLS', [true, true, 'info@tukansoftware.com'], [$reg['tls_al_entrar'] ?? null, $reg['auth_ok'] ?? null, $reg['usuario'] ?? null]);
    check('...orden de la conversación', ['STARTTLS', 'AUTH', 'MAIL FROM:<info@tukansoftware.com>', 'RCPT TO:<roberto@calderon.ec>', 'DATA', 'QUIT'],
          array_values(array_filter($reg['ordenes'] ?? [], fn($o) => !str_starts_with($o, 'EHLO'))));
    [$cE, $bE] = array_pad(explode("\r\n\r\n", $eml, 2), 2, '');
    $hdr = iconv_mime_decode_headers($cE, 0, 'UTF-8') ?: [];
    check('...llegó el asunto en UTF-8', 'Su demo de TuVocalía está lista', $hdr['Subject'] ?? null);
    check('...De y Para con nombre', ['Tukán Software <info@tukansoftware.com>', 'Roberto Calderón <roberto@calderon.ec>'], [$hdr['From'] ?? null, $hdr['To'] ?? null]);
    check('...el punto al inicio de línea llega bien (dot-stuffing)', true, str_contains(quoted_printable_decode($bE), "\r\n.línea que empieza con punto"));
    check('...con la clave de la demo en el cuerpo', true, str_contains(quoted_printable_decode($bE), 'k7#Qp<9x&Z'));

    $correoEnv(['TUKAN_SMTP_PUERTO' => '8251', 'TUKAN_SMTP_SEGURIDAD' => 'ssl'] + $base);
    check('SSL directo (465): enviado', [true, null], [tukan_correo_enviar('a@ejemplo.com', 'Prueba SSL', '<p>hola</p>', 'hola', $cerr), $cerr]);
    check('...entró con TLS', true, $ultimo($d465)[1]['tls_al_entrar'] ?? null);

    $correoEnv(['TUKAN_SMTP_PUERTO' => '8253'] + $base);
    check('Servidor que solo ofrece AUTH PLAIN: enviado', [true, null], [tukan_correo_enviar('a@ejemplo.com', 'Prueba PLAIN', '', 'hola', $cerr), $cerr]);

    $correoEnv(['TUKAN_SMTP_HOST' => '127.0.0.1', 'TUKAN_SMTP_PUERTO' => '8252', 'TUKAN_SMTP_SEGURIDAD' => 'ninguna'] + $base);
    check('Sin TLS ("ninguna") solo en 127.0.0.1, para pruebas: enviado', [true, null], [tukan_correo_enviar('a@ejemplo.com', 'Prueba', '', 'hola', $cerr), $cerr]);
    check('...y el servidor lo recibió sin TLS', false, $ultimo($dPl)[1]['tls_al_entrar'] ?? null);
    $correoEnv(['TUKAN_SMTP_HOST' => 'mail.qfproducciones.com', 'TUKAN_SMTP_SEGURIDAD' => 'ninguna'] + $base);
    check('"ninguna" con un servidor de afuera: no se manda (la clave iría sin cifrar)', [false, true], [tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr), str_contains((string) $cerr, 'solo se permite con un servidor local')]);

    $correoEnv(['TUKAN_SMTP_CLAVE' => 'clave-equivocada-123'] + $base);
    $t0 = microtime(true);
    $cok = tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr);
    check('Clave equivocada: error claro', [false, true], [$cok, str_contains((string) $cerr, 'rechazó el usuario o la clave')]);
    check('...sin la clave en el error (ni en base64)', [false, false], [str_contains((string) $cerr, 'clave-equivocada-123'), str_contains((string) $cerr, base64_encode('clave-equivocada-123'))]);

    $correoEnv(['TUKAN_SMTP_HOST' => '127.0.0.1'] + $base);
    $cok = tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr);
    check('Certificado que no es de ese nombre: no se manda y sugiere mail.tukansoftware.com', [false, true, true],
          [$cok, str_contains((string) $cerr, 'certificado'), str_contains((string) $cerr, 'mail.tukansoftware.com')]);
    $correoEnv(['TUKAN_SMTP_CA' => ''] + $base);
    check('Certificado propio sin TUKAN_SMTP_CA: no se confía', false, tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr));

    $correoEnv(['TUKAN_SMTP_PUERTO' => '8259'] + $base);
    $cok = tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr);
    check('Puerto cerrado: «conexión rechazada»', [false, true], [$cok, str_contains((string) $cerr, 'conexión rechazada')]);
    $correoEnv(['TUKAN_SMTP_PUERTO' => '8254', 'TUKAN_SMTP_TIMEOUT' => '2'] + $base);
    $t0 = microtime(true);
    $cok = tukan_correo_enviar('a@ejemplo.com', 'x', '', 'x', $cerr);
    $dt = microtime(true) - $t0;
    check('Servidor que no contesta: se rinde rápido (timeout corto)', [false, true, true], [$cok, $dt < 6, str_contains((string) $cerr, 'tiempo agotado')]);

    // Consola: php tukan_correo.php probar destino
    $correoEnv($base);
    $out = (string) shell_exec('TUKAN_CORREO_ENV=' . escapeshellarg((string) getenv('TUKAN_CORREO_ENV')) . ' php ' . escapeshellarg(dirname(__DIR__) . '/compartido/tukan_correo.php') . ' probar prueba@ejemplo.com 2>&1');
    check('Consola: «Correo de prueba: ENVIADO»', true, str_contains($out, 'Correo de prueba: ENVIADO a prueba@ejemplo.com'));
    check('...sin la clave en lo que muestra', false, str_contains($out, 'Cl@ve'));

    // -----------------------------------------------------------------------
    titulo('34. deploy/configurar_correo.sh (sin preguntar: con variables de entorno)');
    $yo = trim((string) shell_exec('id -un'));
    $script = dirname(__DIR__) . '/deploy/configurar_correo.sh';
    $envSh = "$ctmp/tukan-correo.env";
    $correr = fn(array $v) => (string) shell_exec(implode(' ', array_map(fn($k) => $k . '=' . escapeshellarg($v[$k]), array_keys($v))) . ' setsid -w bash ' . escapeshellarg($script) . ' < /dev/null 2>&1; echo "rc=$?"');
    $vSh = ['CORREO_ENV' => $envSh, 'PROBAR_COMO' => $yo, 'TUKAN_SMTP_HOST' => 'localhost', 'TUKAN_SMTP_PUERTO' => '8250', 'TUKAN_SMTP_SEGURIDAD' => 'tls',
            'TUKAN_SMTP_USUARIO' => 'info@tukansoftware.com', 'TUKAN_SMTP_CLAVE' => 'Cl@ve ñ #1', 'TUKAN_SMTP_CA' => "$ctmp/cert.pem", 'PRUEBA_A' => 'dimitry@ejemplo.com'];
    $esRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    if (!$esRoot && trim((string) shell_exec('sudo -n true 2>&1; echo $?')) !== '0') {
        echo "  (se omite: hace falta root o sudo sin contraseña para escribir el archivo como root:www-data)\n";
    } else {
        $out = $correr($vSh);
        check('Correo de prueba: ENVIADO', true, str_contains($out, 'Correo de prueba: ENVIADO a dimitry@ejemplo.com'));
        check('...termina bien', true, str_contains($out, 'rc=0'));
        clearstatcache();
        check('Archivo root:www-data con permisos 640', ['root', 'www-data', '640'],
              [posix_getpwuid(fileowner($envSh))['name'] ?? '', posix_getgrgid(filegroup($envSh))['name'] ?? '', substr(sprintf('%o', fileperms($envSh)), -3)]);
        $txt = (string) file_get_contents($envSh);
        check('Guarda servidor, puerto, tls, usuario, remitente y nombre «Tukán Software»', [1, 1, 1, 1, 1, 1],
              [preg_match('/^TUKAN_SMTP_HOST=localhost$/m', $txt), preg_match('/^TUKAN_SMTP_PUERTO=8250$/m', $txt), preg_match('/^TUKAN_SMTP_SEGURIDAD=tls$/m', $txt),
               preg_match('/^TUKAN_SMTP_USUARIO=info@tukansoftware\.com$/m', $txt), preg_match('/^TUKAN_CORREO_DE=info@tukansoftware\.com$/m', $txt), preg_match('/^TUKAN_CORREO_NOMBRE=Tukán Software$/m', $txt)]);
        check('La clave queda tal cual en el archivo (y la lee la librería)', 'Cl@ve ñ #1', (function () use ($envSh) { putenv("TUKAN_CORREO_ENV=$envSh"); return tukan_correo_config(true)['clave']; })());
        check('...pero no sale en pantalla', false, str_contains($out, 'Cl@ve'));
        check('Mensajes sin tildes', 0, preg_match('/[áéíóúñÁÉÍÓÚÑ]/u', $out));
        $out2 = $correr(array_diff_key($vSh, ['TUKAN_SMTP_CLAVE' => 1]));
        check('Se puede repetir: sin escribir la clave, conserva la de antes', [true, 'Cl@ve ñ #1'], [str_contains($out2, 'Correo de prueba: ENVIADO'), tukan_correo_config(true)['clave']]);
        $out3 = $correr(['TUKAN_SMTP_CLAVE' => 'otra-mala'] + $vSh);
        check('Clave equivocada: «NO SE ENVIO» con la explicación', [true, true, true],
              [str_contains($out3, 'Correo de prueba: NO SE ENVIO'), str_contains($out3, 'La clave o el usuario no son correctos'), str_contains($out3, 'rc=1')]);
        check('...sin la clave en pantalla', false, str_contains($out3, 'otra-mala'));
        $out4 = $correr(['TUKAN_SMTP_HOST' => '127.0.0.1'] + $vSh);
        check('Certificado de otro nombre: sugiere mail.tukansoftware.com', true, str_contains($out4, 'mail.tukansoftware.com'));
        $out5 = $correr(['TUKAN_SMTP_PUERTO' => '8259'] + $vSh);
        check('Sin conexión: lo dice claro', true, str_contains($out5, 'No hay conexion con localhost:8259'));
        check('Puerto que no es número: error', true, str_contains($correr(['TUKAN_SMTP_PUERTO' => 'abc'] + $vSh), 'el puerto debe ser un numero'));
    }
    check('Por defecto: mail.qfproducciones.com, 587, tls, info@tukansoftware.com', [1, 1, 1, 1],
          [preg_match('/echo mail\.qfproducciones\.com\)/', (string) file_get_contents($script)), preg_match('/echo 587\)/', (string) file_get_contents($script)),
           preg_match('/DEF_SEG=tls/', (string) file_get_contents($script)), preg_match('/echo info@tukansoftware\.com\)/', (string) file_get_contents($script))]);
}

// ---------------------------------------------------------------------------
titulo('35. «Enviar por correo» en Demo lista: sello de un solo uso, sin guardar la clave');
$cdm = new CorreoDemo(new Cifrado());
$ses = [];
$datosDemo = ['inst' => 'tuvocalia', 'programa' => 'TuVocalía', 'inst_url' => 'https://ligas.tukanec.com', 'mensaje' => 'Demo creada.', 'email' => 'roberto@calderon.ec',
              'contacto' => 'Roberto Calderón', 'negocio' => 'Liga Calderón',
              'resultado' => ['cliente_id' => '9', 'prueba_hasta' => '2026-10-30', 'acceso' => ['url_panel' => 'https://ligas.tukanec.com/admin.php', 'usuario' => 'roberto@calderon.ec', 'clave' => 'Zq8!mK2pLw']]];
$sello = $cdm->sellar($datosDemo, 7, $ses);
check('El sello no deja ver la clave', false, str_contains($sello, 'Zq8!mK2pLw') || str_contains(base64_decode(substr($sello, 3)), 'Zq8!mK2pLw'));
check('En la sesión solo queda un número (sin la clave)', [1, false], [count($ses['correo_demo']), str_contains(json_encode($ses), 'Zq8!mK2pLw')]);
$ab = $cdm->abrir($sello, 7, $ses);
check('Se abre con el mismo usuario', ['Zq8!mK2pLw', false], [$ab['d']['resultado']['acceso']['clave'] ?? null, $ab['usado'] ?? null]);
check('Otro usuario del panel: no sirve', null, $cdm->abrir($sello, 8, $ses));
check('Sello cambiado: no sirve', null, $cdm->abrir(substr($sello, 0, -4) . 'AAAA', 7, $ses));
check('Pasados 30 minutos: no sirve', null, $cdm->abrir($sello, 7, $ses, time() + CorreoDemo::DURA_SEG + 1));
CorreoDemo::gastar($ab['n'], $ses);
check('Después de enviar: el mismo sello queda «usado»', true, $cdm->abrir($sello, 7, $ses)['usado'] ?? null);
check('Otra sesión (sin el número): «usado»', true, $cdm->abrir($cdm->sellar($datosDemo, 7, $ses), 7, [])['usado'] ?? null);
for ($i = 0; $i < 30; $i++) $cdm->sellar($datosDemo, 7, $ses);
check('La sesión guarda como mucho 20 pendientes', 20, count($ses['correo_demo']));
check('Correo sugerido: el de la demo', 'roberto@calderon.ec', CorreoDemo::correoSugerido($datosDemo));
check('...si no, el usuario si es un correo, o el del dueño', ['u@x.ec', 'd@x.ec'],
      [CorreoDemo::correoSugerido(['resultado' => ['acceso' => ['usuario' => 'u@x.ec']]]), CorreoDemo::correoSugerido(['resultado' => ['usuario' => 'admin'], 'cliente' => ['dueno' => ['email' => 'd@x.ec']]])]);
$mdm = CorreoDemo::mensaje($datosDemo);
check('Correo de la demo: asunto, enlace, usuario, clave y fecha', [true, true, true, true],
      ['Su demo de TuVocalía está lista' === $mdm['asunto'], str_contains($mdm['html'], 'https://ligas.tukanec.com/admin.php'), str_contains($mdm['texto'], 'Contraseña: Zq8!mK2pLw'), str_contains($mdm['texto'], 'viernes 30 de octubre de 2026')]);
check('Sin url_panel: entra por la dirección del programa', true, str_contains(CorreoDemo::mensaje(['resultado' => ['clave' => 'x']] + ['inst_url' => 'https://otro.tukanec.com'])['html'], 'href="https://otro.tukanec.com"'));
if ($py !== '' && $ossl !== '') {
    $correoEnv($base);
    check('Enviar la demo (SMTP de mentira)', [true, null], [CorreoDemo::enviar($datosDemo, 'roberto@calderon.ec', $cerr), $cerr]);
    $hdr = iconv_mime_decode_headers(explode("\r\n\r\n", $ultimo($d587)[0], 2)[0], 0, 'UTF-8') ?: [];
    check('...llegó «Su demo de TuVocalía está lista» a Roberto', ['Su demo de TuVocalía está lista', 'Roberto Calderón <roberto@calderon.ec>'], [$hdr['Subject'] ?? null, $hdr['To'] ?? null]);
}
check('Auditoría: la acción tiene nombre', 'Envió una demo por correo', \Tukan\Auditoria::ACCIONES['correo_demo'] ?? null);
check('Ruta demo_correo: solo POST y con permiso de demos', [true, true], [str_contains((string) file_get_contents(dirname(__DIR__) . '/public/index.php'), "'demo_correo' => 'demos'"),
      (bool) preg_match("/soloPost = \[[^\]]*'demo_correo'/", (string) file_get_contents(dirname(__DIR__) . '/public/index.php'))]);
$pag = (string) file_get_contents(dirname(__DIR__) . '/app/paginas/demo_correo.php');
check('La auditoría dice a quién, nunca la clave', [true, false], [str_contains($pag, '"Envió la demo por correo a $para"'), (bool) preg_match('/auditar\([^;]*clave/', $pag)]);

putenv('TUKAN_CORREO_ENV');
tukan_correo_config(true);
foreach ($procs as $p) { @proc_terminate($p); @proc_close($p); }
$procs = [];
shell_exec('rm -rf ' . escapeshellarg($ctmp));
