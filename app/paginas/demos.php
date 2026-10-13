<?php
declare(strict_types=1);

use Tukan\{Analisis, Capacidades};

/** Demos: crear (botón grande) e historial con cuántas pagan (PROTOCOLO_V2 §9.5 F). */
$inst = param('i');
$a = (new Analisis(db(), clientes()))->demos($inst !== '' ? $inst : null);
$pueden = array_values(array_filter(instancias()->lista(true), fn($i) => Capacidades::puede(capacidades()->datos($i['id']), 'ejecutar', 'crear_demo')));
$filtro = param('ver');
$filas = $a['filas'];
if (in_array($filtro, ['prueba', 'pago', 'sin_pagar'], true)) $filas = array_values(array_filter($filas, fn($f) => $f['estado_demo'] === $filtro));
vista('demos', $a + ['inst' => $inst, 'pueden' => $pueden, 'ver' => $filtro, 'lista' => array_slice($filas, 0, 200),
    'insts' => array_values(array_filter(instancias()->lista(true), fn($i) => capacidades()->esV2($i['id'])))], 'Demos · Panel Tukán');
