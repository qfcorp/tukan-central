<?php
declare(strict_types=1);

/**
 * Revision automatica (cron cada 15 minutos, como www-data):
 *   0,15,30,45 * * * * php /var/www/tukan-central/bin/revisar.php >> /var/log/tukan-central/revisar.log 2>&1
 * Consulta el estado de todos los programas activos, guarda las cifras y el historial de disponibilidad,
 * y avisa por Telegram (si esta configurado) cuando un programa se cae o vuelve, o cuando hay negocios nuevos.
 * Si un programa falla, espera 10 segundos y lo intenta otra vez antes de darlo por caido.
 * Los mensajes van sin tildes a proposito.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use Tukan\Db;

$host = (string) (parse_url((string) (getenv('TUKAN_CENTRAL_URL') ?: 'https://panel.tukanec.com'), PHP_URL_HOST) ?: 'panel.tukanec.com');
$email = 'revision@' . $host;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'revision@panel.tukanec.com';   // ej. si la URL es una IP
$nombre = 'Panel Tukan (revision automatica)';

try {
    $mon = monitor();
} catch (Throwable $e) {
    decir(date('Y-m-d H:i') . ' ERROR: ' . $e->getMessage(), true);
    exit(1);
}

$candado = fopen(Db::carpetaDatos() . '/refrescar.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX)) {
    decir(date('Y-m-d H:i') . ' ERROR: no se pudo tomar el candado', true);
    exit(1);
}
$res = $mon->refrescar(null, $email, $nombre);
$fallaron = array_keys(array_filter($res, fn($r) => !$r['en_linea']));
if ($fallaron) {
    sleep((int) (getenv('TUKAN_REVISAR_ESPERA') !== false ? getenv('TUKAN_REVISAR_ESPERA') : 10));
    foreach ($mon->refrescar($fallaron, $email, $nombre) as $id => $r) $res[$id] = $r;
}
flock($candado, LOCK_UN);
fclose($candado);

$ok = count(array_filter($res, fn($r) => $r['en_linea']));
$mal = [];
foreach ($res as $id => $r) {
    if (!$r['en_linea']) $mal[] = "$id: " . $r['error'];
}
decir(date('Y-m-d H:i') . " revision: $ok en linea" . ($mal ? ', ' . count($mal) . ' sin conexion (' . implode('; ', $mal) . ')' : '') . (count($res) ? '' : ' (no hay programas activos)'));
