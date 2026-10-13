<?php
declare(strict_types=1);

/**
 * PROGRAMA DE PRUEBA que implementa el Protocolo Tukán Central v1 (docs/PROTOCOLO.md) del lado de la instancia.
 * Escrito aparte del panel (no usa sus clases) para probar el contrato de verdad.
 * Configuración por variables de entorno (php -S con env):
 *   TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL   (las del protocolo)
 *   MOCK_DIR       carpeta para nonces, límite y registros (sso.log, estado.log)
 *   MOCK_PRODUCTO  tuevento | tusalon | tuvocalia | tuacademia | tuguarderia
 *   MOCK_CLIENTE   (tuguarderia) nombre del cliente
 *   MOCK_DESFASE   segundos que el reloj de este "servidor" está adelantado/atrasado
 *   MOCK_LENTO     segundos que tarda en responder
 *   MOCK_ROTO=1    responde JSON dañado
 *   MOCK_LIMITE    pedidos por minuto por IP (protocolo: 30)
 *   archivo MOCK_DIR/total -> cambia negocios.total (para probar el aviso de negocio nuevo)
 */

$id = (string) getenv('TUKAN_CENTRAL_ID');
$secreto = (string) getenv('TUKAN_CENTRAL_SECRET');
$dir = (string) (getenv('MOCK_DIR') ?: sys_get_temp_dir());
$ahora = time() + (int) getenv('MOCK_DESFASE');
if ((int) getenv('MOCK_DESFASE') !== 0) header('Date: ' . gmdate('D, d M Y H:i:s', $ahora) . ' GMT');

// Sin configurar: el conector está apagado
if ($id === '' || strlen($secreto) < 32) {
    http_response_code(404);
    exit('No encontrado');
}
if ((int) getenv('MOCK_LENTO') > 0) sleep((int) getenv('MOCK_LENTO'));

$accion = (string) ($_GET['accion'] ?? '');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

function rechazar(string $accion, string $motivo): never
{
    file_put_contents($GLOBALS['dir'] . '/rechazos.log', "$accion|$motivo\n", FILE_APPEND);
    http_response_code(401);
    if ($accion === 'sso') {
        header('Content-Type: text/html; charset=utf-8');
        $vuelta = htmlspecialchars((string) getenv('TUKAN_CENTRAL_URL'), ENT_QUOTES);
        echo "<!doctype html><meta charset=utf-8><title>No se pudo entrar</title><h1>No se pudo entrar</h1><p>El enlace no es válido o ya se usó ($motivo).</p><p><a href=\"$vuelta\">Volver al Panel Tukán</a></p>";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $motivo]);
    }
    exit;
}

// 6. Límite: 30 pedidos por minuto por IP
$archLimite = "$dir/limite.json";
$lim = is_file($archLimite) ? (json_decode((string) file_get_contents($archLimite), true) ?: []) : [];
$minuto = intdiv(time(), 60);
$clave = "$ip|$minuto";
$lim = array_filter($lim, fn($k) => str_ends_with($k, "|$minuto"), ARRAY_FILTER_USE_KEY);
$lim[$clave] = ($lim[$clave] ?? 0) + 1;
file_put_contents($archLimite, json_encode($lim));
if ($lim[$clave] > (int) (getenv('MOCK_LIMITE') ?: 30)) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['ok' => false, 'error' => 'demasiados pedidos']));
}

if (!in_array($accion, ['sso', 'estado'], true)) {
    http_response_code(400);
    exit('accion no valida');
}

// 1. Dos partes y firma
$t = (string) ($_GET['t'] ?? '');
$p = explode('.', $t);
if (count($p) !== 2) rechazar($accion, 'token mal formado');
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
if (!hash_equals($b64(hash_hmac('sha256', $p[0], $secreto, true)), $p[1])) rechazar($accion, 'firma incorrecta');
$payload = json_decode((string) base64_decode(strtr($p[0], '-_', '+/'), true), true);
if (!is_array($payload)) rechazar($accion, 'token mal formado');
// 2. versión, tipo, instancia
if (($payload['v'] ?? null) !== 1) rechazar($accion, 'version no soportada');
if (($payload['tipo'] ?? null) !== $accion) rechazar($accion, 'tipo incorrecto');
if (($payload['inst'] ?? null) !== $id) rechazar($accion, 'inst incorrecto');
// 3. tiempo (30 s de tolerancia)
$iat = $payload['iat'] ?? null;
$exp = $payload['exp'] ?? null;
if (!is_int($iat) || !is_int($exp)) rechazar($accion, 'tiempo no valido');
if ($ahora > $exp + 30) rechazar($accion, 'token vencido');
if ($iat > $ahora + 30) rechazar($accion, 'token del futuro');
if ($exp - $iat > 60) rechazar($accion, 'token demasiado largo');
// 4. nonce de un solo uso
$nonce = $payload['nonce'] ?? '';
if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) rechazar($accion, 'nonce no valido');
$archNonces = "$dir/nonces.json";
$fp = fopen($archNonces, 'c+');
flock($fp, LOCK_EX);
$nonces = json_decode((string) stream_get_contents($fp), true) ?: [];
$nonces = array_filter($nonces, fn($e) => $e + 30 >= $ahora);   // se borran los vencidos
if (isset($nonces[$nonce])) {
    flock($fp, LOCK_UN);
    rechazar($accion, 'nonce ya usado');
}
$nonces[$nonce] = $exp;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($nonces));
flock($fp, LOCK_UN);
fclose($fp);
// 5. email
if (!filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)) rechazar($accion, 'email no valido');

if ($accion === 'sso') {
    session_name('mock_' . $id);
    session_start();
    session_regenerate_id(true);
    $_SESSION['admin'] = $payload['email'];
    $_SESSION['nombre'] = $payload['nombre'];
    $_SESSION['desde_central'] = true;
    file_put_contents("$dir/sso.log", $payload['email'] . '|' . $payload['nombre'] . '|' . $nonce . "\n", FILE_APPEND);
    header('Location: /admin', true, 302);
    exit;
}

// Estado
file_put_contents("$dir/estado.log", date('c') . "\n", FILE_APPEND);
header('Content-Type: application/json; charset=utf-8');
if (getenv('MOCK_ROTO') === '1') exit('{"ok": true, "protocolo": 1, "producto": ');
$producto = (string) (getenv('MOCK_PRODUCTO') ?: 'tuevento');
$f = fn(int $d) => date('Y-m-d', strtotime("$d days"));
$datos = match ($producto) {
    'tusalon' => [
        'nombre' => 'TuSalón', 'version' => 'a1c9e02',
        'negocios' => ['total' => 23, 'prueba' => 7, 'activos' => 14, 'por_vencer' => 1, 'vencidos' => 1, 'suspendidos' => 0],
        'nuevos_30d' => 5, 'ingresos_mes' => 690.5, 'ingresos_mes_anterior' => 655.0,
        'pruebas_vencen_7d' => [['nombre' => 'Barbería El Bigote', 'vence' => $f(2)]],
        'pagos_vencidos' => [['nombre' => 'Peluquería Glamour', 'desde' => $f(-5)], ['nombre' => 'Estética Sofi', 'desde' => $f(-20)]],
        'extra' => ['Citas este mes' => 1240, 'Profesionales' => 58],
    ],
    'tuvocalia' => [
        'nombre' => 'TuVocalía', 'version' => '3f0b7aa',
        'negocios' => ['total' => 9, 'prueba' => 2, 'activos' => 6, 'por_vencer' => 0, 'vencidos' => 1, 'suspendidos' => 0],
        'nuevos_30d' => 1, 'ingresos_mes' => 180.0, 'ingresos_mes_anterior' => 200.0,
        'pruebas_vencen_7d' => [], 'pagos_vencidos' => [],
        'extra' => ['Ligas' => 9, 'Partidos este mes' => 214],
    ],
    'tuacademia' => [
        'nombre' => 'TuAcademia', 'version' => 'c41d8e0',
        'negocios' => ['total' => 6, 'prueba' => 3, 'activos' => 3, 'por_vencer' => 0, 'vencidos' => 0, 'suspendidos' => 0],
        'nuevos_30d' => 2, 'ingresos_mes' => 87.0, 'ingresos_mes_anterior' => 58.0,
        'pruebas_vencen_7d' => [['nombre' => 'Academia de Baile Ritmo', 'vence' => $f(3)]], 'pagos_vencidos' => [],
        'extra' => ['Alumnos' => 412, 'Clases esta semana' => 96],
    ],
    'tuguarderia' => [
        'nombre' => 'TuGuardería', 'version' => '57ed3ce', 'cliente' => (string) (getenv('MOCK_CLIENTE') ?: 'Dogssy'),
        'negocios' => null, 'nuevos_30d' => null, 'ingresos_mes' => null, 'ingresos_mes_anterior' => null,
        'pruebas_vencen_7d' => [], 'pagos_vencidos' => [],
        'extra' => ['Mascotas activas' => 86, 'Reservas este mes' => 142, 'Usuarios' => 6],
    ],
    default => [
        'nombre' => 'TuEvento', 'version' => '75df6b3',
        'negocios' => ['total' => 12, 'prueba' => 4, 'activos' => 6, 'por_vencer' => 1, 'vencidos' => 1, 'suspendidos' => 0],
        'nuevos_30d' => 3, 'ingresos_mes' => 414.0, 'ingresos_mes_anterior' => 276.0,
        'pruebas_vencen_7d' => [['nombre' => 'Quinta Los Alamos', 'vence' => $f(4)], ['nombre' => 'Hacienda La Ruta', 'vence' => $f(1)]],
        'pagos_vencidos' => [['nombre' => 'Salón Imperial', 'desde' => $f(-12)]],
        'extra' => ['Eventos este mes' => 37, 'Usuarios' => 41],
    ],
};
if (is_file("$dir/total") && is_array($datos['negocios'])) $datos['negocios']['total'] = (int) trim((string) file_get_contents("$dir/total"));
echo json_encode(['ok' => true, 'protocolo' => 1, 'producto' => $producto] + $datos + ['generado' => date('c')], JSON_UNESCAPED_UNICODE);
