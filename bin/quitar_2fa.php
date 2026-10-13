<?php
declare(strict_types=1);

/**
 * EMERGENCIA: quita la verificacion en 2 pasos de un usuario (perdio el celular y los codigos de respaldo).
 *   sudo -u www-data php /var/www/tukan-central/bin/quitar_2fa.php tu@correo.com
 * Al entrar otra vez, el panel le pedira activarla de nuevo. Queda anotado en la auditoria.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use Tukan\{Acceso, Auditoria};

$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '') {
    decir('Uso: php bin/quitar_2fa.php correo@ejemplo.com', true);
    exit(1);
}
try {
    $acceso = new Acceso(db());
    $u = $acceso->porEmail($email);
} catch (Throwable $e) {
    decir('No se pudo abrir la base de datos: ' . $e->getMessage(), true);
    exit(1);
}
if (!$u) {
    decir("No existe un usuario con el correo $email.", true);
    exit(1);
}
$acceso->quitarTotp((int) $u['id']);
$acceso->limpiarFallos($email, '');
(new Auditoria(db()))->registrar('2fa_quitado', "$email con bin/quitar_2fa.php (emergencia)", null, $u, 'consola', 'consola');
decir("Listo: se quito la verificacion en 2 pasos de $email y se cerraron sus sesiones.");
decir('Al entrar, el panel le pedira activarla otra vez con el celular nuevo.');
