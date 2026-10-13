<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/*
 * Rutas del Panel Tukán Central: /?r=<ruta>. Cada ruta es un archivo app/paginas/<ruta>.php
 * El valor es el permiso que pide (ver Acceso::puede):
 *   publica = sin sesión · pre = ya puso la contraseña, falta el código de 2 pasos
 */
$rutas = [
    'login' => 'publica', 'colores_css' => 'publica',
    'verificar' => 'pre', 'activar_2fa' => 'pre',
    'inicio' => 'ver', 'programas' => 'ver', 'programa' => 'ver', 'ayuda' => 'ver',
    'refrescar' => 'actualizar', 'entrar' => 'entrar', 'probar' => 'probar',
    'mi_cuenta' => 'mi_cuenta', 'codigos' => 'mi_cuenta', 'salir' => 'mi_cuenta',
    // Solo el dueño
    'programa_nuevo' => 'programas', 'programa_editar' => 'programas', 'programa_comando' => 'programas',
    'rotar' => 'programas', 'eliminar' => 'programas',
    'usuarios' => 'usuarios', 'auditoria' => 'auditoria', 'ajustes' => 'ajustes',
];
// Rutas que solo aceptan POST (cambian algo)
$soloPost = ['refrescar', 'entrar', 'probar', 'rotar', 'eliminar', 'salir'];

$ruta = param('r', 'inicio');
if (!isset($rutas[$ruta]) || !is_file(RAIZ . "/app/paginas/$ruta.php")) {
    http_response_code(404);
    $ruta = 'inicio';
}

try {
    // Usuario con sesión completa
    if (!empty($_SESSION['uid']) && !empty($_SESSION['ref'])) {
        $motivo = acceso()->revisarSesion((string) $_SESSION['ref'], (int) $_SESSION['uid']);
        if ($motivo === null) {
            $GLOBALS['usuario_actual'] = acceso()->usuario((int) $_SESSION['uid']);
        } else {
            $quien = acceso()->usuario((int) $_SESSION['uid']);
            if ($motivo !== 'cerrada') auditar('sesion_vencida', $motivo === 'maxima' ? 'Pasaron 12 horas' : 'Más de 30 minutos sin usar', null, $quien);
            $_SESSION = [];
            session_regenerate_id(true);
            aviso(match ($motivo) {
                'inactiva' => 'Tu sesión se cerró porque pasó más de 30 minutos sin uso. Vuelve a entrar.',
                'maxima' => 'Por seguridad, cada 12 horas hay que volver a entrar.',
                default => 'Tu sesión se cerró. Vuelve a entrar.',
            }, 'info');
        }
    }

    // Cabeceras de seguridad. Las direcciones de los programas (para el botón Entrar) solo con sesión iniciada.
    cabeceras_seguridad(usuario() && $ruta !== 'colores_css' ? instancias()->origenes() : []);

    // Formularios: token CSRF y origen obligatorios
    if (es_post() && $ruta !== 'colores_css') {
        if (!csrf_valido() || !origen_valido()) {
            auditar('csrf_rechazado', "Ruta: $ruta");
            http_response_code(400);
            vista('error', ['mensaje' => 'La página caducó o el formulario no es válido. Vuelve atrás, recarga la página e intenta otra vez.'], 'Página caducada');
            exit;
        }
    } elseif (in_array($ruta, $soloPost, true)) {
        redirigir(usuario() ? 'inicio' : 'login');
    }

    $permiso = $rutas[$ruta];
    if ($permiso === 'pre') {
        if (usuario()) redirigir('inicio');
        if (!pre_usuario()) {
            aviso('Pasaron más de 5 minutos. Vuelve a escribir tu correo y contraseña.', 'info');
            redirigir('login');
        }
    } elseif ($permiso !== 'publica') {
        $u = usuario();
        if (!$u) {
            if (isset($_SERVER['HTTP_X_CSRF'])) responder_json(['ok' => false, 'sesion' => false], 401);
            redirigir('login');
        }
        // Contraseña temporal: primero hay que cambiarla
        if ((int) $u['cambiar_clave'] && !in_array($ruta, ['mi_cuenta', 'salir', 'codigos'], true)) {
            aviso('Antes de seguir, cambia la contraseña temporal por una tuya.', 'info');
            redirigir('mi_cuenta');
        }
        exigir($permiso);
    }

    require RAIZ . "/app/paginas/$ruta.php";
} catch (Throwable $e) {
    error_log('Tukan Central: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (isset($_SERVER['HTTP_X_CSRF'])) responder_json(['ok' => false, 'error' => 'interno'], 500);
    vista('error', ['mensaje' => 'Algo falló al abrir esta página. Intenta de nuevo en un momento.'], 'Error');
}
