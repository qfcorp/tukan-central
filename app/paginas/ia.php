<?php
declare(strict_types=1);

use Tukan\Ia;

/**
 * IA (Más → IA): gasto de la inteligencia artificial del mes, por programa y por negocio, con la acción v2 «ia_uso»
 * (PROTOCOLO_V2 Anexo C). Copia de 5 minutos para no preguntar a cada programa en cada visita (?act=1 = en vivo).
 * Pausar toda la IA no se puede desde aquí (el panel no escribe en /etc): se explica el comando.
 */
$mes = param('mes', date('Y-m'));
$meses = [];
$d = new DateTimeImmutable('first day of this month');
for ($k = 0; $k < 12; $k++) $meses[] = $d->modify("-$k months")->format('Y-m');
if (!in_array($mes, $meses, true)) $mes = $meses[0];
$enVivo = param('act') === '1';
$ia = new Ia(db());
$filas = [];
foreach (instancias()->lista(true) as $inst) {
    $cap = capacidades()->obtener($inst['id']);
    $v2 = ($cap['protocolo'] ?? 1) === 2;
    $reporta = $v2 && Ia::tiene($cap['datos'] ?? []);
    $uso = null;
    if ($reporta) {
        $copia = sincronizacion()->momento($inst['id'], "ia_uso:$mes");
        $vivo = $enVivo || $copia === null || $copia < time() - 300;
        $uso = $ia->uso($inst, cliente_v2(), $mes, usuario_v2(), $vivo);
    }
    $filas[] = ['inst' => $inst, 'reporta' => $reporta, 'uso' => $uso];
}
// Primero los que reportan (por gasto), después los que todavía no
usort($filas, fn($a, $b) => [(int) !$a['reporta'], -($a['uso']['datos']['totales']['costo'] ?? 0)] <=> [(int) !$b['reporta'], -($b['uso']['datos']['totales']['costo'] ?? 0)]);
$estado = Ia::estadoServidor();
$legible = $estado === null || !@is_file($estado['archivo']) || @is_readable($estado['archivo']);
vista('ia', ['mes' => $mes, 'meses' => $meses, 'filas' => $filas, 'tot' => Ia::totales($filas), 'estado' => $estado, 'legible' => $legible,
             'dir' => RAIZ], 'IA · Panel Tukán');
