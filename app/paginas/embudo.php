<?php
declare(strict_types=1);

use Tukan\{Analisis, Finanzas};

/** Embudo: cuántos interesados se vuelven clientes que pagan (PROTOCOLO_V2 §9.5 H). */
$inst = param('i');
$meses = array_slice(Finanzas::meses('12'), -6);
$a = (new Analisis(db(), clientes()))->embudo($inst !== '' ? $inst : null, $meses);
vista('embudo', $a + ['inst' => $inst, 'insts' => array_values(array_filter(instancias()->lista(true), fn($i) => capacidades()->esV2($i['id'])))], 'Embudo · Panel Tukán');
