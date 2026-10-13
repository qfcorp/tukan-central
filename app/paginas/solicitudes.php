<?php
declare(strict_types=1);

use Tukan\Capacidades;

/** Bandeja única: todo lo que piden los clientes de todos los programas (PROTOCOLO_V2 §9.5 B). */
$f = ['pestana' => param('t', 'pendiente'), 'chip' => param('chip', 'todo'), 'inst' => param('i'), 'buscar' => mb_substr(param('q'), 0, 80)];
$r = solicitudes()->lista($f, ve_contacto());
$insts = array_values(array_filter(instancias()->lista(true), fn($i) => Capacidades::puede(capacidades()->datos($i['id']), 'solicitudes')));
$sinV2 = array_values(array_filter(instancias()->lista(true), fn($i) => !capacidades()->esV2($i['id']) && !\Tukan\Productos::tipo($i['producto'])['unico']));
vista('solicitudes', $r + [
    'f' => $f, 'insts' => $insts, 'sinV2' => $sinV2,
    'momento' => sincronizacion()->masViejo(array_column($insts, 'id'), 'solicitudes'),
], 'Solicitudes · Panel Tukán');
