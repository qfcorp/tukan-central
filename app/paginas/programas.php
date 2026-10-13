<?php
declare(strict_types=1);

/** Lista de programas conectados (el dueño los agrega, edita y elimina). */
$tablero = monitor()->tablero();
$disp = [];
foreach ($tablero as $f) $disp[$f['id']] = monitor()->disponibilidad($f['id']);
vista('programas', ['tablero' => $tablero, 'disp' => $disp], 'Programas · Panel Tukán');
