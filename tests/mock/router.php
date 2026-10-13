<?php
declare(strict_types=1);

/**
 * Router del programa de prueba (php -S 127.0.0.1:PUERTO tests/mock/router.php):
 *   /tukan_central.php      -> el conector del protocolo
 *   /admin                  -> "panel de administración" del programa (muestra quién entró)
 *   /bot<token>/sendMessage -> imita la API de Telegram (guarda los mensajes en MOCK_DIR/telegram.log)
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$dir = (string) (getenv('MOCK_DIR') ?: sys_get_temp_dir());

if ($ruta === '/tukan_central.php') {
    require __DIR__ . '/tukan_central.php';
    return true;
}
if ($ruta === '/admin') {
    session_name('mock_' . getenv('TUKAN_CENTRAL_ID'));
    session_start();
    header('Content-Type: text/html; charset=utf-8');
    if (empty($_SESSION['admin'])) {
        http_response_code(403);
        echo 'Sin sesion';
        return true;
    }
    echo '<!doctype html><meta charset=utf-8><title>Admin</title><h1>Panel de administración de prueba</h1><p id="quien">'
        . htmlspecialchars($_SESSION['admin'] . ' / ' . $_SESSION['nombre']) . '</p><a href="' . htmlspecialchars((string) getenv('TUKAN_CENTRAL_URL')) . '">← Panel Tukán</a>';
    return true;
}
if (preg_match('#^/bot[^/]+/sendMessage$#', $ruta)) {
    $cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
    file_put_contents("$dir/telegram.log", str_replace("\n", ' ', (string) ($cuerpo['text'] ?? '')) . "\n", FILE_APPEND);
    header('Content-Type: application/json');
    echo '{"ok":true,"result":{}}';
    return true;
}
http_response_code(404);
echo 'No encontrado';
return true;
