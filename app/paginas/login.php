<?php
declare(strict_types=1);

/** Paso 1: correo y contraseña. */
if (usuario()) redirigir('inicio');

$error = null;
$email = '';
if (es_post()) {
    $email = strtolower(campo('email'));
    $r = acceso()->verificarClave($email, (string) ($_POST['clave'] ?? ''), ip_visitante());
    if (!$r['ok']) {
        $error = $r['error'];
        auditar(($r['bloqueo'] ?? 0) > 0 ? 'login_bloqueado' : 'login_fallido', 'Correo escrito: ' . mb_substr($email, 0, 120),
            null, ['id' => null, 'email' => $email]);
        http_response_code(($r['bloqueo'] ?? 0) > 0 ? 429 : 401);
    } else {
        $u = $r['usuario'];
        session_regenerate_id(true);
        $_SESSION['pre'] = ['uid' => (int) $u['id'], 'momento' => time()];
        redirigir($u['totp_cif'] ? 'verificar' : 'activar_2fa');
    }
}

vista('login', ['error' => $error, 'email' => $email], 'Entrar · Panel Tukán');
