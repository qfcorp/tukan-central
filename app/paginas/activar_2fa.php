<?php
declare(strict_types=1);

use Tukan\{Qr, Totp};

/** Primera vez: activar la verificación en 2 pasos (obligatoria). */
$u = pre_usuario();
if ($u['totp_cif']) redirigir('verificar');

// El secreto nuevo vive en la sesión hasta que se confirma con un código
$_SESSION['pre']['totp'] ??= Totp::nuevoSecreto();
$secreto = $_SESSION['pre']['totp'];

$error = null;
if (es_post()) {
    $codigos = acceso()->activarTotp((int) $u['id'], $secreto, campo('codigo'));
    if ($codigos === null) {
        $error = 'El código no coincide. Escribe el número que aparece ahora en la app. Si sigue fallando, revisa que la hora del celular sea automática.';
        http_response_code(401);
    } else {
        $u = acceso()->usuario((int) $u['id']);
        auditar('2fa_activado', '', null, $u);
        completar_ingreso($u, 'activacion');
        $_SESSION['codigos_mostrar'] = $codigos;
        redirigir('codigos');
    }
}

$uri = Totp::uri($secreto, $u['email']);
vista('activar_2fa', [
    'error' => $error, 'pre' => $u, 'clave' => Totp::agrupado($secreto), 'qr' => Qr::svg($uri, null, '#14213D', 'Código QR para tu app de autenticación'),
], 'Activa la verificación en 2 pasos · Panel Tukán');
