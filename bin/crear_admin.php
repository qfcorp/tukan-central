<?php
declare(strict_types=1);

/**
 * Crea un usuario del Panel Tukán Central, o le cambia la contraseña si el correo ya existe.
 * Se corre en el servidor (como www-data, que es el dueño de la base):
 *   sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php
 *   sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php --quitar-2fa      (ademas borra su 2FA)
 *   sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php --rol=soporte
 * Pregunta nombre, correo y contrasena (la contrasena no se ve al escribir).
 * Para pruebas automaticas: TUKAN_ADMIN_NOMBRE, TUKAN_ADMIN_EMAIL, TUKAN_ADMIN_CLAVE, TUKAN_ADMIN_ROL.
 * Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use Tukan\{Acceso, Auditoria};

$opciones = getopt('', ['quitar-2fa', 'reset-2fa', 'rol:', 'ayuda']);
if (isset($opciones['ayuda'])) {
    decir("Uso: php bin/crear_admin.php [--quitar-2fa] [--rol=dueno|soporte]");
    exit(0);
}
$quitar2fa = isset($opciones['quitar-2fa']) || isset($opciones['reset-2fa']);

try {
    $db = db();
    $acceso = new Acceso($db);
} catch (Throwable $e) {
    decir('No se pudo abrir la base de datos: ' . $e->getMessage(), true);
    decir('Corre este comando como www-data: sudo -u www-data php ' . RAIZ . '/bin/crear_admin.php', true);
    exit(1);
}

$preguntar = function (string $texto, bool $oculto = false): string {
    echo $texto;
    $tty = $oculto && DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) system('stty -echo');
    $r = trim((string) fgets(STDIN));
    if ($tty) { system('stty echo'); echo "\n"; }
    return $r;
};

$nombre = trim((string) (getenv('TUKAN_ADMIN_NOMBRE') ?: $preguntar('Tu nombre: ')));
$email = strtolower(trim((string) (getenv('TUKAN_ADMIN_EMAIL') ?: $preguntar('Tu correo (con este entras al panel): '))));
$existente = $email !== '' ? $acceso->porEmail($email) : null;
if ($existente) decir('Ese correo ya existe: se le cambiara la contrasena.');
$clave = (string) (getenv('TUKAN_ADMIN_CLAVE') ?: '');
if ($clave === '') {
    $clave = $preguntar('Elige una contrasena (minimo ' . Acceso::CLAVE_MINIMA . ' caracteres, no se ve al escribir): ', true);
    $otra = $preguntar('Escribela otra vez: ', true);
    if ($clave !== $otra) { decir('Las contrasenas no coinciden. Vuelve a intentar.', true); exit(1); }
}
$rol = (string) ($opciones['rol'] ?? getenv('TUKAN_ADMIN_ROL') ?: ($existente['rol'] ?? 'dueno'));

try {
    [$id, $nuevo] = $acceso->guardarUsuario($nombre, $email, $clave, $rol);
} catch (Throwable $e) {
    decir($e->getMessage(), true);
    exit(1);
}
$aud = new Auditoria($db);
$aud->registrar('cli', ($nuevo ? 'Usuario creado' : 'Contrasena cambiada') . " para $email (rol $rol) con bin/crear_admin.php", null, ['id' => $id, 'email' => $email], 'consola', 'consola');
$acceso->cerrarTodas($id, 'contrasena cambiada en consola');
if ($quitar2fa) {
    $acceso->quitarTotp($id);
    $aud->registrar('2fa_quitado', "$email con bin/crear_admin.php --quitar-2fa", null, ['id' => $id, 'email' => $email], 'consola', 'consola');
}
$u = $acceso->usuario($id);
decir($nuevo ? "Listo: usuario creado para $email (rol $rol)." : "Listo: contrasena cambiada para $email.");
if (!$u['totp_cif']) decir('Al entrar por primera vez el panel te pedira activar la verificacion en 2 pasos (ten tu celular a mano).');
decir('Entra en: ' . rtrim((string) (getenv('TUKAN_CENTRAL_URL') ?: 'https://panel.tukanec.com'), '/'));
