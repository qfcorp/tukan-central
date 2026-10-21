<?php
declare(strict_types=1);

use Tukan\{Cifrado, CorreoDemo};

/**
 * «Enviar por correo» desde la pantalla «Demo lista» (solo POST con CSRF; permiso demos = dueño).
 * Llega el sello (datos de la demo cifrados, ver src/CorreoDemo.php) y el correo del cliente. Se manda desde
 * info@tukansoftware.com con compartido/tukan_correo.php, queda en la auditoría SIN la contraseña y se vuelve a la
 * misma pantalla con el resultado.
 */
$u = usuario();
$cd = new CorreoDemo(new Cifrado());
$s = $cd->abrir(campo('sello'), (int) $u['id'], $_SESSION);
$inst = $s ? instancias()->obtener((string) ($s['d']['inst'] ?? '')) : null;
if (!$s || !$inst || !ve_contacto()) {
    http_response_code(410);
    vista('error', ['mensaje' => 'Este envío ya caducó (pasaron más de 30 minutos o se cerró la sesión). La contraseña de la demo solo se muestra una vez: si hace falta mandarla otra vez, usa «Nueva clave del dueño» en la ficha del cliente.'], 'Envío caducado · Panel Tukán');
    exit;
}
$d = $s['d'];
$para = mb_substr(campo('para'), 0, 254);
$volver = (string) ($d['volver'] ?? '/?r=demos');
$pantalla = fn(array $correo, bool $conClave = true) => vista('resultado', [
    'inst' => $inst, 'tipo' => 'clave', 'mensaje' => (string) ($d['mensaje'] ?? 'Demo creada.'), 'volver' => $volver,
    'resultado' => $conClave ? $d['resultado'] : array_merge(array_diff_key($d['resultado'], ['clave' => 1]),
        is_array($d['resultado']['acceso'] ?? null) ? ['acceso' => array_diff_key($d['resultado']['acceso'], ['clave' => 1]), 'clave_oculta' => true] : ['clave_oculta' => true]),
    'respuesta' => ['cliente' => $d['cliente'] ?? []],
    'correo' => $correo + ['sello' => campo('sello'), 'para' => $para, 'listo' => CorreoDemo::listo(), 'estado' => null, 'error' => null],
], 'Demo lista · Panel Tukán');

if ($s['usado']) {
    // Ya se mandó con este sello (o es de otra sesión): no se manda otra vez y la clave ya no se muestra
    $pantalla(['estado' => 'usado'], false);
    exit;
}
if (!tukan_correo_valido($para)) {
    http_response_code(422);
    $pantalla(['error' => 'Escribe un correo válido (ej. nombre@negocio.com).']);
    exit;
}
if (!CorreoDemo::listo()) {
    http_response_code(503);
    $pantalla(['error' => 'El correo de Tukán no está configurado. En el servidor: ' . CorreoDemo::comandoConfigurar()]);
    exit;
}
$ok = CorreoDemo::enviar($d, $para, $error);
if ($ok) {
    CorreoDemo::gastar($s['n'], $_SESSION);
    auditar('correo_demo', "Envió la demo por correo a $para", $inst['id']);
    $pantalla(['estado' => 'enviado']);
    exit;
}
auditar('correo_demo', "No se pudo enviar la demo por correo a $para: " . mb_substr((string) $error, 0, 300), $inst['id']);
http_response_code(502);
$pantalla(['error' => 'No se pudo enviar: ' . $error]);
