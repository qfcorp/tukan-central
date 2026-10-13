<?php
declare(strict_types=1);

use Tukan\{Exportar, Finanzas};

/** Descargar Finanzas: Excel (.xlsx) o CSV para Excel en español (PROTOCOLO_V2 §9.5 G). */
$periodo = in_array(param('periodo'), ['12', '24', 'anio'], true) ? param('periodo') : '12';
$meses = Finanzas::meses($periodo);
$ex = new Exportar(db(), finanzas());
$repartir = ajustes()->si('repartir_gastos_generales');
$nombre = 'tukan_finanzas_' . $meses[0] . '_a_' . end($meses);
$xlsx = param('f') !== 'csv';
auditar('exportar', ($xlsx ? 'Excel' : 'CSV') . ' de ' . $meses[0] . ' a ' . end($meses));
$contenido = $xlsx ? $ex->xlsx($meses, $repartir) : $ex->csv($meses, $repartir);
header('Content-Type: ' . ($xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8'));
header('Content-Disposition: attachment; filename="' . $nombre . ($xlsx ? '.xlsx' : '.csv') . '"');
header('Content-Length: ' . strlen($contenido));
header('Cache-Control: private, no-store');
echo $contenido;
