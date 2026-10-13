<?php
declare(strict_types=1);

use Tukan\Db;

/**
 * Botón "Actualizar" de Solicitudes, Clientes, Finanzas y las fichas: pide otra vez esa parte a los programas v2
 * (todos o uno: i=). Las pantallas siempre se dibujan con la copia local; esto la renueva.
 */
$que = campo('que');
$partes = match ($que) {
    'solicitudes' => ['solicitudes'], 'clientes' => ['clientes'], 'finanzas' => ['finanzas'],
    default => ['capacidades', 'solicitudes', 'clientes', 'finanzas'],
};
$volver = volver_valido(campo('volver'));
$solo = campo('i');
$candado = fopen(Db::carpetaDatos() . '/refrescar.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) {
    aviso('Ya se están trayendo los datos. Espera unos segundos.', 'info');
    header('Location: ' . $volver, true, 303);
    exit;
}
$s = sincronizador();
$s->registro = [];
$n = 0;
foreach (monitor()->tablero(true) as $f) {
    if ($solo !== '' && $f['id'] !== $solo) continue;
    if (!capacidades()->esV2($f['id']) && !in_array('capacidades', $partes, true)) continue;
    $s->programa($f, $f['datos'], false, $partes, usuario_v2());
    $n++;
}
flock($candado, LOCK_UN);
fclose($candado);
$fallos = array_values(array_filter($s->registro, fn($l) => preg_match('/: (sin_conexion|tiempo|dns|tls|no_conectado|firma|inst|interno|formato|limite|v1|vencido|futuro|nonce)$/', $l)));
auditar('actualizar_datos', implode(', ', $partes) . " de $n programas" . ($fallos ? ' · fallaron: ' . implode('; ', $fallos) : ''));
aviso($fallos ? 'Datos actualizados, pero ' . count($fallos) . ' ' . (count($fallos) === 1 ? 'programa no respondió' : 'programas no respondieron') . '. Se muestran sus últimos datos.' : 'Datos actualizados.', $fallos ? 'info' : 'ok');
header('Location: ' . $volver, true, 303);
exit;
