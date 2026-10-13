<?php
declare(strict_types=1);

use Tukan\Monitor;

/** Inicio: cifras de todos los programas + tarjetas para entrar. Se dibuja con lo guardado (rápido aunque algo esté caído). */
$tablero = monitor()->tablero();
$activas = array_values(array_filter($tablero, fn($f) => (int) $f['activo']));
$disp = [];
foreach ($tablero as $f) $disp[$f['id']] = monitor()->disponibilidad($f['id']);

$consultas = array_filter(array_column($activas, 'consultado'));
vista('inicio', [
    'tablero'    => $tablero,
    'totales'    => Monitor::totales($tablero),
    'pendientes' => Monitor::pendientes($tablero),
    'disp'       => $disp,
    'viejo'      => Monitor::hayViejos($tablero),
    'ultima'     => $consultas ? min($consultas) : null,
], 'Inicio · Panel Tukán');
