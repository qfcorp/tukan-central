<?php
declare(strict_types=1);

use Tukan\Token;

/**
 * "Entrar a X": crea el token de un solo uso (60 s) y redirige al programa (Protocolo §3 y §4).
 * El token va en la dirección, por eso este panel manda Referrer-Policy: no-referrer.
 */
$u = usuario();
$inst = instancias()->obtener(campo('id'));
if (!$inst) {
    aviso('Ese programa no existe.', 'error');
    redirigir('inicio');
}
if (!(int) $inst['activo']) {
    aviso('Ese programa está pausado. Actívalo en Programas para poder entrar.', 'error');
    redirigir('programa', ['id' => $inst['id']]);
}
try {
    $secreto = instancias()->secreto($inst);
} catch (Throwable) {
    aviso('No se puede leer el secreto guardado de este programa. Toca "Cambiar secreto" y pega el comando nuevo en su servidor.', 'error');
    redirigir('programa', ['id' => $inst['id']]);
}
$token = Token::crear('sso', $inst['id'], $u['email'], $u['nombre'], $secreto);
auditar('entrar', 'Entró a ' . $inst['nombre'] . ' (' . host($inst['url']) . ')' . (campo('nueva') === '1' ? ' en pestaña nueva' : ''), $inst['id']);
header('Location: ' . $inst['url'] . '/tukan_central.php?accion=sso&t=' . $token, true, 302);
exit;
