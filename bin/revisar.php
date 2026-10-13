<?php
declare(strict_types=1);

/**
 * Revision automatica (cron cada 15 minutos, como www-data):
 *   0,15,30,45 * * * * php /var/www/tukan-central/bin/revisar.php >> /var/log/tukan-central/revisar.log 2>&1
 * 1. Consulta el estado de todos los programas activos (si uno falla, espera 10 s y lo intenta otra vez).
 * 2. Con los programas del protocolo v2: capacidades (una vez al dia o si cambia la version), solicitudes nuevas
 *    (si cambio su huella), clientes (cada hora) y finanzas (una vez al dia, desde las 03:10).
 * 3. Telegram: caidas y vueltas, negocios nuevos, solicitudes nuevas (fuera del horario de silencio, con el
 *    telefono escondido), demos que pagaron y el resumen de las 07:30.
 * 4. Crea los gastos e ingresos fijos del mes.
 * Los mensajes de la consola van sin tildes a proposito.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use Tukan\Db;

$urlCentral = rtrim((string) (getenv('TUKAN_CENTRAL_URL') ?: 'https://panel.tukanec.com'), '/');
$host = (string) (parse_url($urlCentral, PHP_URL_HOST) ?: 'panel.tukanec.com');
$email = 'revision@' . $host;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'revision@panel.tukanec.com';   // ej. si la URL es una IP
$nombre = 'Panel Tukan (revision automatica)';

try {
    $sin = sincronizador();
} catch (Throwable $e) {
    decir(date('Y-m-d H:i') . ' ERROR: ' . $e->getMessage(), true);
    exit(1);
}

$candado = fopen(Db::carpetaDatos() . '/refrescar.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX)) {
    decir(date('Y-m-d H:i') . ' ERROR: no se pudo tomar el candado', true);
    exit(1);
}
$res = $sin->revisar(null, $email, $nombre, true);
$avisadas = $sin->avisarSolicitudes($urlCentral);
$resumen = $sin->resumenDiario();
$fijos = $sin->fijosDelMes();
flock($candado, LOCK_UN);
fclose($candado);

$ok = count(array_filter($res, fn($r) => $r['en_linea']));
$mal = [];
foreach ($res as $id => $r) {
    if (!$r['en_linea']) $mal[] = "$id: " . $r['error'];
}
decir(date('Y-m-d H:i') . " revision: $ok en linea" . ($mal ? ', ' . count($mal) . ' sin conexion (' . implode('; ', $mal) . ')' : '') . (count($res) ? '' : ' (no hay programas activos)'));
if ($sin->registro) decir('  v2: ' . implode(' | ', $sin->registro));
if ($avisadas || $resumen || $fijos) decir("  avisos: $avisadas solicitudes" . ($resumen ? ', resumen diario' : '') . ($fijos ? ", $fijos fijos del mes creados" : ''));
