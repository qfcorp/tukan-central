<?php
declare(strict_types=1);

/** Paso 2: código de 6 dígitos de la app (o un código de respaldo). */
$u = pre_usuario();
if (!$u['totp_cif']) redirigir('activar_2fa');

$error = null;
if (es_post()) {
    $falta = acceso()->bloqueado($u['email'], ip_visitante());
    if ($falta > 0) {
        $error = \Tukan\Acceso::textoBloqueo($falta);
        auditar('login_bloqueado', 'En el código de 2 pasos', null, $u);
        http_response_code(429);
    } else {
        $tipo = acceso()->verificarCodigo($u, campo('codigo'), ip_visitante());
        if ($tipo === null) {
            auditar('2fa_fallido', '', null, $u);
            $falta = acceso()->bloqueado($u['email'], ip_visitante());
            $error = $falta > 0 ? \Tukan\Acceso::textoBloqueo($falta) : 'Ese código no es correcto. Escribe el que aparece ahora en tu app (cambia cada 30 segundos).';
            if ($falta > 0) unset($_SESSION['pre']);
            http_response_code($falta > 0 ? 429 : 401);
        } else {
            if ($tipo === 'respaldo') {
                auditar('2fa_respaldo', 'Quedan ' . acceso()->codigosRestantes((int) $u['id']) . ' códigos', null, $u);
            }
            completar_ingreso($u, $tipo);
            if ($tipo === 'respaldo') {
                $quedan = acceso()->codigosRestantes((int) $u['id']);
                aviso("Entraste con un código de respaldo. Te quedan $quedan. Si perdiste tu celular, genera códigos nuevos en Mi cuenta.", $quedan <= 2 ? 'error' : 'info');
            }
            redirigir('inicio');
        }
    }
}

vista('verificar', ['error' => $error, 'pre' => $u], 'Código de seguridad · Panel Tukán');
