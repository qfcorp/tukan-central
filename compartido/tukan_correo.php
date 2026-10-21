<?php
declare(strict_types=1);

/**
 * Tukán Correo: librería de UN SOLO ARCHIVO para mandar correos desde info@tukansoftware.com
 * (Panel Tukán Central y los programas Tukán del mismo servidor). Sin composer, sin dependencias.
 *
 *   require __DIR__ . '/tukan_correo.php';
 *   if (tukan_correo_listo()) {
 *       $ok = tukan_correo_enviar('cliente@ejemplo.com', 'Asunto', '<p>HTML</p>', 'Texto plano', $error);
 *   }
 *
 * - SMTP con STARTTLS (587, "tls") o SSL directo (465, "ssl"), AUTH LOGIN (o PLAIN si el servidor solo ofrece esa).
 * - multipart/alternative (texto + HTML) en UTF-8, quoted-printable; asunto y nombres con =?UTF-8?B?...?=.
 * - Configuración en /etc/tukan-correo.env (lo escribe deploy/configurar_correo.sh del Panel Tukán Central):
 *     TUKAN_SMTP_HOST, TUKAN_SMTP_PUERTO, TUKAN_SMTP_SEGURIDAD (tls|ssl), TUKAN_SMTP_USUARIO, TUKAN_SMTP_CLAVE,
 *     TUKAN_CORREO_DE, TUKAN_CORREO_NOMBRE, y opcionales: TUKAN_CORREO_RESPONDER (Reply-To), TUKAN_SMTP_TIMEOUT (seg, 10),
 *     TUKAN_SMTP_CA (archivo de certificados propio), TUKAN_SMTP_VERIFICAR (1; 0 = no revisar el certificado).
 *   Otro archivo: variable de entorno TUKAN_CORREO_ENV. Una variable de entorno con el mismo nombre gana sobre el archivo.
 *   TUKAN_SMTP_SEGURIDAD=ninguna SOLO sirve con un servidor en esta misma máquina (127.0.0.1 / localhost): es para pruebas.
 * - La clave nunca sale en los errores ni en los registros.
 * - Probar desde la consola:  sudo -u www-data php tukan_correo.php probar destino@correo.com
 *
 * Versión 1 (2026-10-21). Si cambias este archivo en el Panel Tukán Central, copia la versión nueva a cada programa.
 */

if (!function_exists('tukan_correo_enviar')) {

    define('TUKAN_CORREO_VERSION', '1');

    /** Lee la configuración (archivo + variables de entorno). Uso interno: trae la clave. */
    function tukan_correo_config(bool $recargar = false): array
    {
        static $cache = null;
        if ($cache !== null && !$recargar) return $cache;
        $archivo = getenv('TUKAN_CORREO_ENV') ?: '/etc/tukan-correo.env';
        $v = [];
        if (@is_file($archivo) && @is_readable($archivo)) {
            foreach (preg_split('/\n/', (string) @file_get_contents($archivo)) ?: [] as $linea) {
                $linea = rtrim($linea, "\r");
                if (trim($linea) === '' || str_starts_with(ltrim($linea), '#')) continue;
                $p = strpos($linea, '=');
                if ($p === false) continue;
                $k = trim((string) preg_replace('/^\s*export\s+/', '', substr($linea, 0, $p)));
                $val = substr($linea, $p + 1);   // sin recortar: una clave puede tener espacios o #
                if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) $val = substr($val, 1, -1);
                if (preg_match('/^TUKAN_[A-Z_]+$/', $k)) $v[$k] = $val;
            }
        }
        foreach (['TUKAN_SMTP_HOST', 'TUKAN_SMTP_PUERTO', 'TUKAN_SMTP_SEGURIDAD', 'TUKAN_SMTP_USUARIO', 'TUKAN_SMTP_CLAVE', 'TUKAN_CORREO_DE',
                  'TUKAN_CORREO_NOMBRE', 'TUKAN_CORREO_RESPONDER', 'TUKAN_SMTP_TIMEOUT', 'TUKAN_SMTP_CA', 'TUKAN_SMTP_VERIFICAR'] as $k) {
            $e = getenv($k);
            if ($e !== false && $e !== '') $v[$k] = $e;
        }
        $seg = strtolower(trim((string) ($v['TUKAN_SMTP_SEGURIDAD'] ?? 'tls')));
        if (!in_array($seg, ['tls', 'ssl', 'ninguna'], true)) $seg = 'tls';
        $puerto = (int) ($v['TUKAN_SMTP_PUERTO'] ?? 0);
        if ($puerto < 1 || $puerto > 65535) $puerto = $seg === 'ssl' ? 465 : 587;
        $de = trim((string) ($v['TUKAN_CORREO_DE'] ?? ''));
        return $cache = [
            'archivo'   => $archivo,
            'host'      => trim((string) ($v['TUKAN_SMTP_HOST'] ?? '')),
            'puerto'    => $puerto,
            'seguridad' => $seg,
            'usuario'   => trim((string) ($v['TUKAN_SMTP_USUARIO'] ?? '')),
            'clave'     => (string) ($v['TUKAN_SMTP_CLAVE'] ?? ''),
            'de'        => $de,
            'nombre'    => trim((string) ($v['TUKAN_CORREO_NOMBRE'] ?? '')) ?: 'Tukán Software',
            'responder' => trim((string) ($v['TUKAN_CORREO_RESPONDER'] ?? '')) ?: $de,
            'timeout'   => max(2, min(60, (int) ($v['TUKAN_SMTP_TIMEOUT'] ?? 10) ?: 10)),
            'ca'        => trim((string) ($v['TUKAN_SMTP_CA'] ?? '')),
            'verificar' => !in_array(strtolower(trim((string) ($v['TUKAN_SMTP_VERIFICAR'] ?? '1'))), ['0', 'no', 'false'], true),
        ];
    }

    /** ¿Hay lo mínimo para mandar? (servidor, remitente válido y, si hay usuario, su clave) */
    function tukan_correo_listo(): bool
    {
        $c = tukan_correo_config();
        return $c['host'] !== '' && tukan_correo_valido($c['de']) && ($c['usuario'] === '' || $c['clave'] !== '');
    }

    /** Lo que se puede mostrar de la configuración (SIN la clave). */
    function tukan_correo_estado(): array
    {
        $c = tukan_correo_config();
        return ['listo' => tukan_correo_listo(), 'archivo' => $c['archivo'], 'host' => $c['host'], 'puerto' => $c['puerto'],
                'seguridad' => $c['seguridad'], 'usuario' => $c['usuario'], 'de' => $c['de'], 'nombre' => $c['nombre']];
    }

    /** Correo válido y sin nada que pueda romper una cabecera (saltos de línea, <, >, comillas, comas). */
    function tukan_correo_valido(string $correo): bool
    {
        if ($correo === '' || strlen($correo) > 254 || preg_match('/[\x00-\x20\x7F<>"(),;:\\\\\[\]]/', $correo)) return false;
        return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Quita saltos de línea y caracteres de control de un valor de cabecera (anti-inyección). */
    function tukan_correo_limpiar_cabecera(string $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v)));
    }

    /** "=?UTF-8?B?...?=" en pedazos (sin cortar letras) si hace falta; ASCII sencillo va tal cual. */
    function tukan_correo_codificar(string $texto, bool $nombre = false): string
    {
        $texto = tukan_correo_limpiar_cabecera($texto);
        if (!preg_match('//u', $texto)) $texto = (string) @iconv('ISO-8859-1', 'UTF-8', $texto);
        // Un nombre ("Tukan Software") va tal cual solo si son letras, números y espacios; si no, codificado.
        $simple = $nombre ? '/^[A-Za-z0-9 ]*$/' : '/^[\x20-\x7E]*$/';
        if (preg_match($simple, $texto) && strlen($texto) <= 70 && !str_contains($texto, '=?')) return $texto;
        $partes = [];
        $actual = '';
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
            if (strlen($actual . $letra) > 36) {   // 36 bytes -> 48 en base64 -> palabra de 60 (la línea queda en menos de 78)
                $partes[] = $actual;
                $actual = '';
            }
            $actual .= $letra;
        }
        if ($actual !== '') $partes[] = $actual;
        return implode("\r\n ", array_map(fn($p) => '=?UTF-8?B?' . base64_encode($p) . '?=', $partes));
    }

    /** Texto con saltos de línea \r\n y en UTF-8. */
    function tukan_correo_normalizar(string $t): string
    {
        if (!preg_match('//u', $t)) $t = (string) @iconv('ISO-8859-1', 'UTF-8', $t);
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $t));
    }

    /** Arma el mensaje completo (cabeceras + cuerpo). Útil también para revisarlo en las pruebas. */
    function tukan_correo_armar(string $para, string $asunto, string $html, string $texto, array $opciones = []): string
    {
        $c = tukan_correo_config();
        $de = $opciones['de'] ?? $c['de'];
        $nombre = $opciones['nombre'] ?? $c['nombre'];
        $responder = $opciones['responder_a'] ?? $c['responder'];
        $dominio = substr((string) strrchr($de, '@'), 1) ?: 'localhost';
        if ($texto === '' && $html !== '') {
            $texto = html_entity_decode(strip_tags((string) preg_replace(['#<br\s*/?>#i', '#</(p|div|tr|h[1-6]|li)>#i'], ["\n", "\n\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $texto = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $texto)));
        }
        $cab = [
            'Date: ' . date('D, d M Y H:i:s O'),
            'From: ' . tukan_correo_codificar($nombre, true) . " <$de>",
            'To: ' . (isset($opciones['nombre_para']) && tukan_correo_limpiar_cabecera((string) $opciones['nombre_para']) !== ''
                ? tukan_correo_codificar((string) $opciones['nombre_para'], true) . " <$para>" : "<$para>"),
            'Subject: ' . tukan_correo_codificar($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '.' . time() . "@$dominio>",
            'MIME-Version: 1.0',
            'X-Mailer: Tukan Correo ' . TUKAN_CORREO_VERSION,
        ];
        if ($responder !== '' && tukan_correo_valido($responder)) $cab[] = "Reply-To: <$responder>";
        $qp = fn(string $t) => quoted_printable_encode(tukan_correo_normalizar($t));
        if ($html === '') {
            $cab[] = 'Content-Type: text/plain; charset=UTF-8';
            $cab[] = 'Content-Transfer-Encoding: quoted-printable';
            return implode("\r\n", $cab) . "\r\n\r\n" . $qp($texto) . "\r\n";
        }
        $limite = '=_tukan_' . bin2hex(random_bytes(8));
        $cab[] = "Content-Type: multipart/alternative; boundary=\"$limite\"";
        return implode("\r\n", $cab) . "\r\n\r\n"
            . "Este es un mensaje en formato MIME.\r\n\r\n"
            . "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($texto) . "\r\n"
            . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($html) . "\r\n"
            . "--$limite--\r\n";
    }

    /**
     * Manda un correo. Devuelve true si el servidor lo aceptó; si no, false y $error en palabras sencillas (sin la clave).
     * $opciones (opcional): responder_a (Reply-To), nombre_para (nombre del destinatario).
     */
    function tukan_correo_enviar(string $para, string $asunto, string $html, string $texto, ?string &$error = null, array $opciones = []): bool
    {
        $error = null;
        $c = tukan_correo_config();
        $para = trim($para);
        if (!tukan_correo_valido($para)) {
            $error = 'El correo del destinatario no es válido.';
            return false;
        }
        if (isset($opciones['responder_a']) && !tukan_correo_valido((string) $opciones['responder_a'])) {
            $error = 'El correo para responder (Reply-To) no es válido.';
            return false;
        }
        if (!tukan_correo_listo()) {
            $error = 'El correo de Tukán no está configurado (falta ' . $c['archivo'] . '). En el servidor: bash /var/www/tukan-central/deploy/configurar_correo.sh';
            return false;
        }
        $local = in_array(strtolower($c['host']), ['127.0.0.1', 'localhost', '::1'], true);
        if ($c['seguridad'] === 'ninguna' && !$local) {
            $error = 'TUKAN_SMTP_SEGURIDAD=ninguna solo se permite con un servidor local (pruebas). Usa tls o ssl.';
            return false;
        }
        $mensaje = tukan_correo_armar($para, $asunto, $html, $texto, $opciones);

        $ctx = stream_context_create(['ssl' => array_filter([
            'verify_peer' => $c['verificar'], 'verify_peer_name' => $c['verificar'], 'allow_self_signed' => !$c['verificar'],
            'peer_name' => $c['host'], 'SNI_enabled' => true, 'cafile' => $c['ca'] !== '' ? $c['ca'] : null,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
        ], fn($x) => $x !== null)]);
        $destino = ($c['seguridad'] === 'ssl' ? 'ssl://' : 'tcp://') . (str_contains($c['host'], ':') ? '[' . $c['host'] . ']' : $c['host']) . ':' . $c['puerto'];
        $donde = $c['host'] . ':' . $c['puerto'];
        $secretos = array_filter([$c['clave'], base64_encode($c['clave']), base64_encode("\0" . $c['usuario'] . "\0" . $c['clave'])], fn($s) => $s !== '');
        $tapar = fn(string $t) => str_replace($secretos, '***', $t);

        $falla = null;
        set_error_handler(function (int $n, string $m) use (&$falla): bool { $falla = $m; return true; });
        try {
            $fp = stream_socket_client($destino, $errno, $errstr, (float) $c['timeout'], STREAM_CLIENT_CONNECT, $ctx);
        } finally {
            restore_error_handler();
        }
        if (!$fp) {
            $detalle = $tapar(trim($errstr ?: (string) $falla));
            $error = match (true) {
                $c['seguridad'] === 'ssl' && (str_contains((string) $falla, 'certificate') || str_contains((string) $falla, 'SSL')) => "No se pudo abrir la conexión segura con $donde (certificado no válido para {$c['host']} o el puerto no es SSL). Pruebe con el servidor " . ($c['host'] === 'mail.tukansoftware.com' ? 'mail.qfproducciones.com' : 'mail.tukansoftware.com') . '.',
                str_contains(strtolower($detalle), 'timed out') || $errno === 110 => "No se pudo conectar con el servidor de correo $donde: no contestó a tiempo.",
                str_contains(strtolower($detalle), 'refused') || $errno === 111 => "No se pudo conectar con el servidor de correo $donde: conexión rechazada.",
                str_contains(strtolower($detalle), 'getaddrinfo') || str_contains(strtolower($detalle), 'name or service') => "No se encontró el servidor de correo {$c['host']} (revisa el nombre).",
                default => "No se pudo conectar con el servidor de correo $donde." . ($detalle !== '' ? " ($detalle)" : ''),
            };
            return false;
        }
        stream_set_timeout($fp, $c['timeout']);

        $leer = function () use ($fp): array {
            $texto = '';
            while (($l = fgets($fp, 2048)) !== false) {
                $texto .= $l;
                if (strlen($l) < 4 || $l[3] !== '-') break;
            }
            $meta = stream_get_meta_data($fp);
            if ($texto === '') return [0, !empty($meta['timed_out']) ? 'sin respuesta (tiempo agotado)' : 'se cortó la conexión'];
            return [(int) substr($texto, 0, 3), trim((string) preg_replace('/\s+/', ' ', $texto))];
        };
        $orden = function (string $linea) use ($fp, $leer): array {
            if (@fwrite($fp, $linea . "\r\n") === false) return [0, 'se cortó la conexión'];
            return $leer();
        };
        $cerrar = function () use ($fp): void { @fwrite($fp, "QUIT\r\n"); @fclose($fp); };
        $mal = function (string $paso, array $r) use (&$error, $tapar, $cerrar, $donde): bool {
            $error = $tapar("El servidor de correo ($donde) no aceptó $paso: " . ($r[1] !== '' ? substr($r[1], 0, 200) : 'sin respuesta') . '.');
            $cerrar();
            return false;
        };

        $yo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname()) ?: 'localhost';
        $r = $leer();
        if ($r[0] !== 220) return $mal('la conexión', $r);
        $r = $orden("EHLO $yo");
        if ($r[0] !== 250) return $mal('el saludo (EHLO)', $r);
        if ($c['seguridad'] === 'tls') {
            if (!preg_match('/[\s-]STARTTLS\b/i', ' ' . $r[1])) {
                $error = "El servidor de correo $donde no ofrece STARTTLS. Usa el puerto 465 con seguridad ssl.";
                $cerrar();
                return false;
            }
            $r = $orden('STARTTLS');
            if ($r[0] !== 220) return $mal('STARTTLS', $r);
            $falla = null;
            set_error_handler(function (int $n, string $m) use (&$falla): bool { $falla = $m; return true; });
            try {
                $cripto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0));
            } finally {
                restore_error_handler();
            }
            if ($cripto !== true) {
                $error = "No se pudo activar el cifrado TLS con $donde" . (str_contains((string) $falla, 'certificate')
                    ? ': el certificado del servidor no es válido para ' . $c['host'] . '. Pruebe con el servidor ' . ($c['host'] === 'mail.tukansoftware.com' ? 'mail.qfproducciones.com' : 'mail.tukansoftware.com') . '.' : '.');
                @fclose($fp);
                return false;
            }
            $r = $orden("EHLO $yo");
            if ($r[0] !== 250) return $mal('el saludo después de TLS', $r);
        }
        if ($c['usuario'] !== '') {
            $ofrece = preg_match('/AUTH[ =]([^\n]*)/i', $r[1], $m) ? strtoupper($m[1]) : '';
            if ($ofrece !== '' && !str_contains($ofrece, 'LOGIN') && str_contains($ofrece, 'PLAIN')) {
                $r = $orden('AUTH PLAIN ' . base64_encode("\0" . $c['usuario'] . "\0" . $c['clave']));
            } else {
                $r = $orden('AUTH LOGIN');
                if ($r[0] === 334) $r = $orden(base64_encode($c['usuario']));
                if ($r[0] === 334) $r = $orden(base64_encode($c['clave']));
            }
            if ($r[0] !== 235) {
                $error = in_array($r[0], [535, 534, 454, 530], true) || $r[0] === 334
                    ? "El servidor de correo rechazó el usuario o la clave ({$c['usuario']}). Revisa la clave del buzón en Hestia y vuelve a correr configurar_correo.sh."
                    : $tapar("El servidor de correo ($donde) no aceptó el ingreso: " . substr($r[1], 0, 200) . '.');
                $cerrar();
                return false;
            }
        }
        $r = $orden('MAIL FROM:<' . $c['de'] . '>');
        if ($r[0] !== 250) return $mal('el remitente ' . $c['de'], $r);
        $r = $orden("RCPT TO:<$para>");
        if ($r[0] !== 250 && $r[0] !== 251) return $mal("el destinatario $para", $r);
        $r = $orden('DATA');
        if ($r[0] !== 354) return $mal('el mensaje (DATA)', $r);
        $cuerpo = (string) preg_replace('/^\./m', '..', $mensaje);   // "dot-stuffing"
        if (@fwrite($fp, $cuerpo . (str_ends_with($cuerpo, "\r\n") ? '' : "\r\n") . ".\r\n") === false) return $mal('el mensaje', [0, 'se cortó la conexión']);
        $r = $leer();
        if ($r[0] !== 250) return $mal('el mensaje', $r);
        $cerrar();
        return true;
    }

    /** "viernes 30 de octubre de 2026" a partir de "2026-10-30" (o el texto tal cual si no es una fecha). */
    function tukan_correo_fecha(?string $fecha): string
    {
        $fecha = trim((string) $fecha);
        $d = $fecha !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($fecha, 0, 10)) : false;
        if (!$d) return $fecha;
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    /**
     * El correo «Su demo de {Programa} está lista» con la marca Tukán. Devuelve ['asunto', 'html', 'texto'].
     * $d: programa, enlace (dirección para entrar), usuario, clave, hasta (Y-m-d), contacto (nombre), negocio (opcionales).
     * Nunca dice «gratis» ni precios.
     */
    function tukan_correo_demo(array $d): array
    {
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $una = fn($v) => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        $programa = $una($d['programa'] ?? '') ?: 'Tukán';
        $enlace = trim((string) ($d['enlace'] ?? ''));
        if (!preg_match('#^https?://[^\s"<>]+$#i', $enlace)) $enlace = '';
        $usuario = $una($d['usuario'] ?? '');
        $clave = (string) ($d['clave'] ?? '');
        $hasta = tukan_correo_fecha($d['hasta'] ?? null);
        $nombre = explode(' ', $una($d['contacto'] ?? ''))[0];
        $negocio = $una($d['negocio'] ?? '');
        $asunto = "Su demo de $programa está lista";
        $saludo = $nombre !== '' ? "Hola $nombre," : 'Hola,';
        $intro = "Su demo de $programa" . ($negocio !== '' ? " para $negocio" : '') . ' ya está lista. Estos son sus datos de entrada:';

        $t = [$saludo, '', $intro, ''];
        if ($enlace !== '') $t[] = "Enlace: $enlace";
        if ($usuario !== '') $t[] = "Usuario: $usuario";
        if ($clave !== '') $t[] = "Contraseña: $clave";
        if ($hasta !== '') array_push($t, '', "Su demo termina el $hasta.");
        array_push($t, '', 'Le recomendamos cambiar la contraseña la primera vez que entre.', '',
                   '¿Dudas? Escríbanos:', 'WhatsApp 099 640 8397 · info@tukansoftware.com · tukansoftware.com', '', 'Tukán Software');
        $texto = implode("\n", $t);

        $fila = fn(string $k, string $v, bool $mono = false) => '<tr><td style="padding:8px 0;color:#5A6378;font-size:14px;width:110px;vertical-align:top">' . $h($k)
            . '</td><td style="padding:8px 0;color:#14213D;font-size:16px;font-weight:bold;word-break:break-all;' . ($mono ? 'font-family:Consolas,Menlo,monospace;' : '') . '">' . $v . '</td></tr>';
        $datos = '';
        if ($enlace !== '') $datos .= $fila('Enlace', '<a href="' . $h($enlace) . '" style="color:#14213D">' . $h($enlace) . '</a>');
        if ($usuario !== '') $datos .= $fila('Usuario', $h($usuario), true);
        if ($clave !== '') $datos .= $fila('Contraseña', $h($clave), true);
        $boton = $enlace !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px"><tr><td style="border-radius:10px;background:#FFC93C">'
            . '<a href="' . $h($enlace) . '" style="display:inline-block;padding:14px 28px;font-size:17px;font-weight:bold;color:#14213D;text-decoration:none;border-radius:10px">Entrar a su demo</a></td></tr></table>' : '';
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($asunto) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F3F4F8;font-family:Arial,Helvetica,sans-serif;color:#14213D">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F8"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:#14213D;padding:20px 28px"><span style="font-size:24px;font-weight:bold;color:#FFC93C">Tukán</span>'
            . '<span style="font-size:24px;color:#FFFFFF"> Software</span></td></tr>'
            . '<tr><td style="height:4px;background:#FFC93C;font-size:0;line-height:0">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px">'
            . '<h1 style="margin:0 0 12px;font-size:22px;color:#14213D">' . $h($asunto) . '</h1>'
            . '<p style="margin:0 0 8px;font-size:16px;line-height:1.5">' . $h($saludo) . '</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.5">' . $h($intro) . '</p>'
            . ($datos !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-top:1px solid #E3E6EE;border-bottom:1px solid #E3E6EE">' . $datos . '</table>' : '')
            . $boton
            . ($hasta !== '' ? '<p style="margin:16px 0 0;font-size:16px;line-height:1.5">Su demo termina el <b>' . $h($hasta) . '</b>.</p>' : '')
            . '<p style="margin:16px 0 0;padding:12px 14px;background:#FFF6DB;border-radius:10px;font-size:14px;line-height:1.5">Le recomendamos <b>cambiar la contraseña</b> la primera vez que entre.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:20px 28px;background:#14213D;color:#FFFFFF;font-size:14px;line-height:1.6">¿Dudas? Escríbanos:<br>'
            . '<a href="https://wa.me/593996408397" style="color:#FFC93C;text-decoration:none">WhatsApp 099 640 8397</a> · '
            . '<a href="mailto:info@tukansoftware.com" style="color:#FFC93C;text-decoration:none">info@tukansoftware.com</a> · '
            . '<a href="https://tukansoftware.com" style="color:#FFC93C;text-decoration:none">tukansoftware.com</a></td></tr>'
            . '</table></td></tr></table></body></html>';
        return ['asunto' => $asunto, 'html' => $html, 'texto' => $texto];
    }

    // Consola: php tukan_correo.php probar destino@correo.com  -> manda un correo de prueba (mensajes sin tildes)
    if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        if (!ini_get('date.timezone')) date_default_timezone_set('America/Guayaquil');
        $sinTildes = fn(string $t) => strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', '¿' => '', '¡' => '']);
        $args = array_slice($argv ?? [], 1);
        if (($args[0] ?? '') !== 'probar' || !isset($args[1])) {
            fwrite(STDERR, "Uso: php tukan_correo.php probar destino@correo.com\n");
            exit(2);
        }
        $e = tukan_correo_estado();
        fwrite(STDOUT, $sinTildes("Servidor: {$e['host']}:{$e['puerto']} ({$e['seguridad']}) - usuario: {$e['usuario']} - remitente: {$e['de']}") . "\n");
        $hora = date('d/m/Y H:i');
        $ok = tukan_correo_enviar($args[1], 'Prueba del correo de Tukán Software',
            '<div style="font-family:Arial,sans-serif;color:#14213D"><p style="font-size:20px;font-weight:bold"><span style="color:#FFC93C;background:#14213D;padding:2px 8px;border-radius:6px">Tukán</span> Software</p>'
            . "<p>Este es un correo de prueba. Si lo está leyendo, el correo de Tukán funciona.</p><p style=\"color:#5A6378\">Enviado el $hora.</p></div>",
            "Tukán Software\n\nEste es un correo de prueba. Si lo está leyendo, el correo de Tukán funciona.\n\nEnviado el $hora.", $err);
        fwrite(STDOUT, $ok ? "Correo de prueba: ENVIADO a {$args[1]}\n" : $sinTildes("Correo de prueba: ERROR - $err") . "\n");
        exit($ok ? 0 : 1);
    }
}
