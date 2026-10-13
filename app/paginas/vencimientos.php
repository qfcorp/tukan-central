<?php
declare(strict_types=1);

use Tukan\{Analisis, Finanzas};

/** Calendario: qué día vence cada prueba y cada plan (PROTOCOLO_V2 §9.5 E). */
$mes = Finanzas::mesValido(param('mes')) ? param('mes') : date('Y-m');
$inst = param('i');
$a = (new Analisis(db(), clientes()))->vencimientos($inst !== '' ? $inst : null, $mes);
$primero = new DateTimeImmutable("$mes-01");
vista('vencimientos', $a + [
    'mes' => $mes, 'inst' => $inst, 'primero' => $primero,
    'anterior' => $primero->modify('-1 month')->format('Y-m'), 'siguiente' => $primero->modify('+1 month')->format('Y-m'),
    'insts' => array_values(array_filter(instancias()->lista(true), fn($i) => isset(array_flip(array_column(clientes()->todas(), 'instancia_id'))[$i['id']]))),
], 'Vencimientos · Panel Tukán');
