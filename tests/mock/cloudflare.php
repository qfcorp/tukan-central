<?php
declare(strict_types=1);

/**
 * CLOUDFLARE DE MENTIRA para las pruebas (php -S 127.0.0.1:PUERTO tests/mock/cloudflare.php). Nunca se usa el Cloudflare real.
 * Imita lo que usa src/Cloudflare.php de la API v4 (https://api.cloudflare.com/client/v4):
 *   GET    /client/v4/accounts/{cuenta}/cfd_tunnel/{tunel}                  -> el túnel (nombre)
 *   GET    /client/v4/accounts/{cuenta}/cfd_tunnel/{tunel}/configurations   -> config.ingress (rutas públicas)
 *   PUT    /client/v4/accounts/{cuenta}/cfd_tunnel/{tunel}/configurations   -> guarda {"config": {...}}
 *   GET    /client/v4/zones?name=...                                        -> zonas
 *   GET    /client/v4/zones/{zona}/dns_records?type=CNAME&name=...          -> registros
 *   DELETE /client/v4/zones/{zona}/dns_records/{id}
 * Variables: MOCK_DIR (estado en cf.json, pedidos en cf.log), CF_TOKEN, CF_CUENTA, CF_TUNEL.
 * Archivo de control MOCK_DIR/cf_falla: "put_no_guarda" (contesta bien pero no guarda) o "dns_error" (el DELETE falla).
 */
$dir = (string) (getenv('MOCK_DIR') ?: sys_get_temp_dir());
$token = (string) getenv('CF_TOKEN');
$cuenta = (string) getenv('CF_CUENTA');
$tunel = (string) getenv('CF_TUNEL');
$metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ruta = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$falla = is_file("$dir/cf_falla") ? trim((string) file_get_contents("$dir/cf_falla")) : '';

function responder(int $http, array $d): never
{
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}
function mal(int $http, int $codigo, string $texto): never
{
    responder($http, ['success' => false, 'errors' => [['code' => $codigo, 'message' => $texto]], 'messages' => [], 'result' => null]);
}
function bien(mixed $r): never
{
    responder(200, ['success' => true, 'errors' => [], 'messages' => [], 'result' => $r]);
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents("$dir/cf.log", "$metodo $ruta" . ($q ? '?' . http_build_query($q) : '') . ($auth === "Bearer $token" ? '' : ' (token malo)') . "\n", FILE_APPEND);
if ($auth !== "Bearer $token") mal(403, 10000, 'Authentication error');

// Estado (se crea la primera vez)
$archivo = "$dir/cf.json";
$zonaId = str_repeat('a1', 16);
$destino = "$tunel.cfargotunnel.com";
$e = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
if (!is_array($e)) {
    $rutas = [];
    foreach (['panel.tukanec.com' => 80, 'dogssy.tukanec.com' => 8101, 'guau.tukanec.com' => 8102, 'huellitas.tukanec.com' => 8103] as $h => $p) {
        $rutas[] = ['hostname' => $h, 'service' => "http://localhost:$p", 'originRequest' => ['httpHostHeader' => $h]];
    }
    $rutas[] = ['hostname' => 'guau.tukanec.com', 'path' => '^/fotos', 'service' => 'http://localhost:8102'];
    $rutas[] = ['service' => 'http_status:404'];
    $dns = [];
    $n = 1;
    foreach (['panel', 'dogssy', 'guau', 'huellitas'] as $s) $dns[] = ['id' => str_pad(dechex($n++), 32, '0', STR_PAD_LEFT), 'type' => 'CNAME', 'name' => "$s.tukanec.com", 'content' => $destino, 'proxied' => true];
    $dns[] = ['id' => str_pad(dechex($n++), 32, '0', STR_PAD_LEFT), 'type' => 'CNAME', 'name' => 'ajeno.tukanec.com', 'content' => 'otro-servidor.ejemplo.com', 'proxied' => true];
    $e = ['version' => 1, 'config' => ['ingress' => $rutas, 'warp-routing' => ['enabled' => false]], 'dns' => $dns];
}
$guardar = function () use (&$e, $archivo): void { file_put_contents($archivo, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); };

$base = '/client/v4';
if (!str_starts_with($ruta, $base)) mal(404, 7003, 'Could not route');
$r = substr($ruta, strlen($base));

if (preg_match('#^/accounts/([^/]+)/cfd_tunnel/([^/]+)(/configurations)?$#', $r, $m)) {
    if ($m[1] !== $cuenta || $m[2] !== $tunel) mal(404, 1003, 'Tunnel not found');
    if (empty($m[3])) {
        if ($metodo !== 'GET') mal(405, 10405, 'Method not allowed');
        bien(['id' => $tunel, 'name' => 'servidorqf', 'status' => 'healthy']);
    }
    if ($metodo === 'GET') bien(['tunnel_id' => $tunel, 'version' => $e['version'], 'config' => $e['config'], 'source' => 'cloudflare', 'created_at' => '2026-01-01T00:00:00Z']);
    if ($metodo === 'PUT') {
        $c = json_decode((string) file_get_contents('php://input'), true);
        $ing = $c['config']['ingress'] ?? null;
        if (!is_array($ing) || $ing === [] || isset(end($ing)['hostname'])) mal(400, 1055, 'The last ingress rule must match all URLs');
        if ($falla !== 'put_no_guarda') {
            $e['config'] = $c['config'];
            $e['version']++;
            $guardar();
        }
        bien(['tunnel_id' => $tunel, 'version' => $e['version'], 'config' => $e['config']]);
    }
    mal(405, 10405, 'Method not allowed');
}
if ($r === '/zones' && $metodo === 'GET') {
    bien(($q['name'] ?? '') === 'tukanec.com' ? [['id' => $zonaId, 'name' => 'tukanec.com', 'status' => 'active']] : []);
}
if (preg_match('#^/zones/([^/]+)/dns_records(?:/([^/]+))?$#', $r, $m)) {
    if ($m[1] !== $zonaId) mal(404, 7003, 'Zone not found');
    if (empty($m[2]) && $metodo === 'GET') {
        bien(array_values(array_filter($e['dns'], fn($x) => (!isset($q['name']) || $x['name'] === $q['name']) && (!isset($q['type']) || $x['type'] === $q['type']))));
    }
    if (!empty($m[2]) && $metodo === 'DELETE') {
        if ($falla === 'dns_error') mal(500, 1000, 'Internal error');
        $antes = count($e['dns']);
        $e['dns'] = array_values(array_filter($e['dns'], fn($x) => $x['id'] !== $m[2]));
        if (count($e['dns']) === $antes) mal(404, 81044, 'Record not found');
        $guardar();
        bien(['id' => $m[2]]);
    }
    mal(405, 10405, 'Method not allowed');
}
mal(404, 7003, 'Could not route');
