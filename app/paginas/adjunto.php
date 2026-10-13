<?php
declare(strict_types=1);

use Tukan\{ErrorPrograma, Solicitudes};

/**
 * Archivo de una solicitud (foto del comprobante, captura de un ticket) (PROTOCOLO_V2 §5.10).
 * El panel nunca lo guarda: lo pide al programa y lo pasa al navegador. Los comprobantes solo los ve el dueño.
 */
$inst = instancias()->obtener(param('i'));
$sol = param('sol');
$adj = param('adj');
$fila = $inst ? solicitudes()->obtener($inst['id'], $sol) : null;
$fuera = function (int $http, string $texto): never {
    http_response_code($http);
    header('Content-Type: text/plain; charset=utf-8');
    echo $texto;
    exit;
};
if (!$fila || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $adj)) $fuera(404, 'No encontrado');
if (!ve_contacto() && $fila['tipo'] !== 'soporte') $fuera(403, 'Solo el dueño del panel ve los comprobantes.');
try {
    $r = cliente_v2()->pedir($inst, 'adjunto', ['solicitud_id' => $sol, 'adjunto_id' => $adj], usuario_v2());
} catch (ErrorPrograma $e) {
    $fuera($e->codigo === 'no_encontrado' ? 404 : 502, $e->getMessage());
}
$mime = (string) ($r['tipo_mime'] ?? '');
$bin = base64_decode((string) ($r['base64'] ?? ''), true);
if (!in_array($mime, Solicitudes::MIME, true) || $bin === false || strlen($bin) > 6 * 1024 * 1024) $fuera(502, 'El programa mandó un archivo que no se puede mostrar.');
// El tipo real tiene que coincidir con el que dice el programa
$real = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
if ($real !== $mime) $fuera(502, 'El archivo no es lo que dice ser.');
$nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($r['nombre'] ?? 'archivo')) ?: 'archivo';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $nombre . '"');
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($bin));
echo $bin;
