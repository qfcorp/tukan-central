<?php
declare(strict_types=1);

use Tukan\Db;

/**
 * Consulta otra vez todos los programas (botón "Actualizar" o app.js cuando lo guardado tiene más de 2 minutos).
 * Si ya hay otra consulta en marcha, no se repite.
 */
$u = usuario();
$esJs = isset($_SERVER['HTTP_X_CSRF']);
$candado = fopen(Db::carpetaDatos() . '/refrescar.lock', 'c');
$res = [];
$hecho = false;
if ($candado && flock($candado, LOCK_EX | LOCK_NB)) {
    $res = monitor()->refrescar(null, $u['email'], $u['nombre']);
    flock($candado, LOCK_UN);
    $hecho = true;
}
if ($candado) fclose($candado);

$caidos = count(array_filter($res, fn($r) => !$r['en_linea']));
if ($esJs) responder_json(['ok' => true, 'hecho' => $hecho, 'consultados' => count($res), 'sin_conexion' => $caidos]);

auditar('actualizar', count($res) . ' programas consultados' . ($caidos ? ", $caidos sin conexión" : ''));
aviso($hecho ? ($caidos ? "Cifras actualizadas. $caidos " . ($caidos === 1 ? 'programa no respondió' : 'programas no respondieron') . '.' : 'Cifras actualizadas.')
             : 'Ya se estaban actualizando las cifras. Espera unos segundos.', $caidos ? 'info' : 'ok');
redirigir('inicio');
