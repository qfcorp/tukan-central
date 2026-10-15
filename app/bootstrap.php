<?php
declare(strict_types=1);

/**
 * Arranque del Panel Tukán Central: configuración, sesión segura, conexión y funciones de ayuda.
 * La configuración vive en config/.env (fuera de la carpeta pública y fuera de GitHub).
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
mb_internal_encoding('UTF-8');

// 1) Configuración: config/.env -> variables de entorno (lo que ya venga del entorno tiene prioridad)
$envFile = getenv('TUKAN_CENTRAL_ENV') ?: RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) putenv("$k=$v");
    }
}

// 2) Clases de src/
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'Tukan\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 6) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use Tukan\{Acceso, Auditoria, Bajas, Db, Instancias, Monitor, Telegram, Ajustes, Capacidades, ClienteV2, Clientes, Solicitudes, Finanzas, Licencias,
            Operaciones, Sincronizador, Sincronizacion};

require RAIZ . '/app/iconos.php';
require RAIZ . '/app/ui.php';

// 3) Sesión segura (solo en la web)
if (PHP_SAPI !== 'cli') {
    $carpetaSesiones = Db::carpetaDatos() . '/sesiones';
    if (!is_dir($carpetaSesiones)) @mkdir($carpetaSesiones, 0700, true);
    if (is_dir($carpetaSesiones) && is_writable($carpetaSesiones)) session_save_path($carpetaSesiones);
    ini_set('session.gc_maxlifetime', (string) Acceso::MAXIMA_SEG);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cache_limiter', '');
    session_name(es_https() ? '__Host-tukan_central' : 'tukan_central');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// En la consola: si se corre como root, los archivos de datos se devuelven a www-data al terminar
// (si quedaran de root, la página no podría escribir en la base).
if (PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    register_shutdown_function(function (): void {
        $w = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!$w) return;
        $dir = Db::carpetaDatos();
        foreach (array_merge([$dir], glob($dir . '/*') ?: []) as $f) {
            @chown($f, (int) $w['uid']);
            @chgrp($f, (int) $w['gid']);
        }
    });
}

// ---------------------------------------------------------------------
// Funciones de ayuda
// ---------------------------------------------------------------------

/** ¿Llegó por https? (directo, o detrás del túnel de Cloudflare: X-Forwarded-Proto / CF-Visitor) */
function es_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on') return true;
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    $cf = json_decode((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    return is_array($cf) && ($cf['scheme'] ?? '') === 'https';
}

/**
 * IP del visitante. Si la conexión viene del túnel de Cloudflare (que corre en este mismo servidor),
 * se usa CF-Connecting-IP; si viene de otro lado, esa cabecera se ignora (no se puede falsificar).
 */
function ip_visitante(): string
{
    $remota = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $confiables = array_map('trim', explode(',', (string) (getenv('TUKAN_PROXIES_CONFIABLES') ?: '127.0.0.1,::1')));
    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && in_array($remota, $confiables, true) && filter_var($cf, FILTER_VALIDATE_IP)) return substr($cf, 0, 45);
    return substr($remota, 0, 45);
}

function agente(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= Db::conectar();
}

function acceso(): Acceso
{
    static $a = null;
    return $a ??= new Acceso(db());
}

function instancias(): Instancias
{
    static $i = null;
    return $i ??= new Instancias(db());
}

function monitor(): Monitor
{
    static $m = null;
    return $m ??= new Monitor(db(), instancias(), new Telegram());
}

function ajustes(): Ajustes
{
    static $a = null;
    return $a ??= new Ajustes(db());
}

// --- Protocolo v2 ---
function capacidades(): Capacidades
{
    static $c = null;
    return $c ??= new Capacidades(db());
}

function cliente_v2(): ClienteV2
{
    static $c = null;
    return $c ??= new ClienteV2(instancias());
}

function clientes(): Clientes
{
    static $c = null;
    return $c ??= new Clientes(db());
}

function solicitudes(): Solicitudes
{
    static $s = null;
    return $s ??= new Solicitudes(db());
}

function finanzas(): Finanzas
{
    static $f = null;
    return $f ??= new Finanzas(db());
}

function licencias(): Licencias
{
    static $l = null;
    return $l ??= new Licencias(db());
}

function operaciones(): Operaciones
{
    static $o = null;
    return $o ??= new Operaciones(db(), cliente_v2());
}

/** Borrar instalaciones desde el panel (lo que pasa queda en la auditoría con el usuario de la sesión). */
function bajas(): Bajas
{
    static $b = null;
    return $b ??= new Bajas(db(), null, fn(string $accion, string $detalle, string $inst) => auditar($accion, $detalle, $inst));
}

function sincronizacion(): Sincronizacion
{
    static $s = null;
    return $s ??= new Sincronizacion(db());
}

function sincronizador(): Sincronizador
{
    static $s = null;
    return $s ??= new Sincronizador(db(), instancias(), monitor(), cliente_v2(), new Telegram());
}

/** Usuario para los pedidos v2 (email, nombre y rol del que está en el panel). */
function usuario_v2(): array
{
    $u = usuario();
    return ['id' => $u['id'] ?? null, 'email' => $u['email'] ?? ClienteV2::EMAIL_REVISION, 'nombre' => $u['nombre'] ?? 'Panel Tukán', 'rol' => $u['rol'] ?? 'dueno'];
}

/** ¿El rol de este usuario ve teléfonos y correos? (§9.3) */
function ve_contacto(): bool
{
    return puede('contacto');
}

/** "20/10" o "20/10/2027" a partir de "2026-10-20" */
function fecha_dm(?string $f, bool $anio = false): string
{
    if (!$f || !($d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($f, 0, 10)))) return '—';
    return $d->format($anio ? 'd/m/Y' : 'd/m');
}

/** "faltan 7 días" / "hoy" / "mañana" / "hace 2 días" */
function falta(?int $dias): string
{
    if ($dias === null) return '';
    return match (true) {
        $dias === 0 => 'hoy',
        $dias === 1 => 'mañana',
        $dias > 1 => "faltan $dias días",
        $dias === -1 => 'ayer',
        default => 'hace ' . -$dias . ' días',
    };
}

/** "hace 2 horas" a partir de un momento ISO ("2026-10-13T09:12:00-05:00") */
function hace_iso(?string $iso): string
{
    $t = $iso ? strtotime($iso) : false;
    return $t ? hace($t) : 'nunca';
}

/** Enlace a la pantalla actual con otros parámetros (para filtros y pestañas). */
function url_aqui(array $cambios, array $quitar = []): string
{
    $p = array_diff_key(array_merge($_GET, $cambios), array_flip(array_merge($quitar, array_key_exists('pagina', $cambios) ? [] : ['pagina'])));
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    $r = (string) ($p['r'] ?? 'inicio');
    unset($p['r']);
    return url($r, $p);
}

/** Datos de un formulario v2 que vienen como campo[...] */
function campos_post(): array
{
    $c = $_POST['campo'] ?? [];
    return is_array($c) ? $c : [];
}

/** Escapa texto para mostrarlo en HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $ruta, array $params = []): string
{
    return '/?' . http_build_query(array_merge(['r' => $ruta], $params));
}

function redirigir(string $ruta, array $params = []): never
{
    header('Location: ' . url($ruta, $params), true, 303);
    exit;
}

/** Dirección pública de este panel (para los comandos y el botón "Volver al Panel Tukán" de cada programa). */
function url_central(): string
{
    $u = rtrim((string) getenv('TUKAN_CENTRAL_URL'), '/');
    if ($u !== '') return $u;
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'panel.tukanec.com'));
    return (es_https() ? 'https://' : 'http://') . $host;
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function campo(string $nombre, string $defecto = ''): string
{
    $v = $_POST[$nombre] ?? $defecto;
    return is_string($v) ? trim($v) : $defecto;
}

function param(string $nombre, string $defecto = ''): string
{
    $v = $_GET[$nombre] ?? $defecto;
    return is_string($v) ? trim($v) : $defecto;
}

function aviso(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['avisos'][] = ['texto' => $texto, 'tipo' => $tipo];
}

function tomar_avisos(): array
{
    $a = $_SESSION['avisos'] ?? [];
    unset($_SESSION['avisos']);
    return $a;
}

function token_csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

/** El token puede venir en el formulario o en la cabecera X-CSRF (pedidos de app.js). */
function csrf_valido(): bool
{
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($enviado) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

/**
 * Si el navegador dice de dónde viene el formulario (Origin), debe ser este mismo sitio.
 * Con Referrer-Policy: no-referrer, Chrome manda "Origin: null" en los formularios: eso no dice nada
 * y se acepta (la protección real es el token CSRF + SameSite=Lax); lo que se rechaza es otro sitio.
 */
function origen_valido(): bool
{
    $origen = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origen === '' || $origen === 'null') return true;
    $host = (string) parse_url($origen, PHP_URL_HOST) . (parse_url($origen, PHP_URL_PORT) ? ':' . parse_url($origen, PHP_URL_PORT) : '');
    return strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0;
}

/**
 * Cabeceras de seguridad. form-action incluye los programas para que el botón "Entrar" pueda redirigir,
 * y WhatsApp para el botón "Recordar por WhatsApp" (se anota y luego redirige a wa.me).
 */
function cabeceras_seguridad(array $origenes = []): void
{
    $form = implode(' ', array_merge(["'self'"], $origenes, $origenes ? ['https://wa.me', 'https://api.whatsapp.com'] : []));
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
         . "connect-src 'self'; manifest-src 'self'; form-action $form; frame-ancestors 'none'; base-uri 'none'");
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cache-Control: no-store, max-age=0');
    if (es_https()) header('Strict-Transport-Security: max-age=31536000');
}

/** Usuario con sesión completa (contraseña + 2FA) o null. Lo deja listo el enrutador. */
function usuario(): ?array
{
    return $GLOBALS['usuario_actual'] ?? null;
}

function puede(string $accion): bool
{
    $u = usuario();
    return $u !== null && Acceso::puede($u['rol'], $accion);
}

/** Corta la página si el usuario no tiene permiso. */
function exigir(string $accion): void
{
    if (!puede($accion)) {
        auditar('permiso_negado', 'Pidió: ' . ($_GET['r'] ?? '') . " ($accion)");
        http_response_code(403);
        vista('error', ['mensaje' => 'Tu usuario no tiene permiso para esto. Pídeselo al dueño del panel.'], 'Sin permiso');
        exit;
    }
}

/** Deja constancia en la auditoría (con el usuario de la sesión, la IP y el navegador). */
function auditar(string $accion, string $detalle = '', ?string $instancia = null, ?array $quien = null): void
{
    (new Auditoria(db()))->registrar($accion, $detalle, $instancia, $quien ?? usuario(), ip_visitante(), agente());
}

/** Termina el ingreso (después del segundo paso): sesión nueva, registro y limpieza de intentos. */
function completar_ingreso(array $u, string $como): void
{
    session_regenerate_id(true);
    unset($_SESSION['pre']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['ref'] = acceso()->abrirSesion((int) $u['id'], ip_visitante(), agente());
    acceso()->limpiarFallos($u['email'], ip_visitante());
    $GLOBALS['usuario_actual'] = $u;
    auditar('login_ok', $como === 'respaldo' ? 'Con código de respaldo' : ($como === 'activacion' ? 'Primera vez (activó 2FA)' : 'Con código de la app'), null, $u);
}

/** Usuario a medio entrar (ya puso la contraseña, falta el código). Dura 5 minutos. */
function pre_usuario(): ?array
{
    $p = $_SESSION['pre'] ?? null;
    if (!is_array($p) || time() - (int) ($p['momento'] ?? 0) > 300) {
        unset($_SESSION['pre']);
        return null;
    }
    $u = acceso()->usuario((int) $p['uid']);
    return $u && (int) $u['activo'] ? $u : null;
}

/** Muestra una vista dentro del diseño general. */
function vista(string $nombre, array $datos = [], string $titulo = 'Panel Tukán'): void
{
    extract($datos, EXTR_SKIP);
    $u = usuario();
    ob_start();
    require RAIZ . "/app/vistas/$nombre.php";
    $contenido = ob_get_clean();
    $u = usuario();   // una vista podría usar $u para otra cosa
    require RAIZ . ($u ? '/app/vistas/_diseno.php' : '/app/vistas/_acceso.php');
}

/** JSON para app.js. */
function responder_json(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** "hace 3 min", "hace 2 h", "ayer"... */
function hace(?int $momento, ?int $ahora = null): string
{
    if (!$momento) return 'nunca';
    $s = ($ahora ?? time()) - $momento;
    if ($s < 45) return 'hace un momento';
    if ($s < 3600) return 'hace ' . max(1, (int) round($s / 60)) . ' min';
    if ($s < 86400) return 'hace ' . (int) round($s / 3600) . ' h';
    $d = (int) round($s / 86400);
    return $d === 1 ? 'ayer' : "hace $d días";
}

/** "13 oct 2026, 14:05" */
function fecha_hora(?int $momento, bool $conAnio = true): string
{
    if (!$momento) return '—';
    $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $momento) . ' ' . $meses[(int) date('n', $momento)] . ($conAnio ? ' ' . date('Y', $momento) : '') . ', ' . date('H:i', $momento);
}

/** "vie 17 oct" a partir de "2026-10-17" */
function fecha_corta(?string $fecha): string
{
    if (!$fecha || !($d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha))) return '';
    $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . $meses[(int) $d->format('n')];
}

/** Días desde hoy hasta una fecha (negativo = ya pasó). */
function dias_hasta(?string $fecha): ?int
{
    if (!$fecha || !($d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha))) return null;
    $hoy = new DateTimeImmutable('today');
    return (int) $hoy->diff($d)->format('%r%a');
}

/** Dinero al estilo ecuatoriano: $1.234,50 */
function dinero(float|int|null $v): string
{
    $v = (float) $v;
    return ($v < 0 ? '−$' : '$') . number_format(abs($v), 2, ',', '.');
}

function numero_corto(int|float|string|null $v): string
{
    if ($v === null || $v === '') return '—';
    if (is_string($v)) return $v;
    return is_float($v) && floor($v) !== $v ? number_format($v, 2, ',', '.') : number_format((float) $v, 0, ',', '.');
}

/** Iniciales para el avatar. */
function iniciales(string $nombre): string
{
    $p = preg_split('/\s+/', trim($nombre)) ?: [''];
    return mb_strtoupper(mb_substr($p[0], 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
}

/** Nombre corto de la dirección (sin https://). */
function host(string $url): string
{
    return (string) preg_replace('#^https?://#', '', $url);
}

/** Navegador en palabras simples a partir del User-Agent. */
function navegador(?string $ua): string
{
    $ua = (string) $ua;
    $sis = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'Mac',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    $nav = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Safari/') => 'Safari',
        str_contains($ua, 'curl/') => 'curl',
        default => 'Navegador',
    };
    return trim("$nav " . ($sis ? "en $sis" : ''));
}

/** Texto para la consola del servidor: sin tildes ni ñ (la app de SSH del celular las daña). */
function sin_tildes(string $t): string
{
    return strtr($t, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        '¿' => '', '¡' => '', '→' => '->', '—' => '-', '…' => '...', '·' => '-',
    ]);
}

/** Escribe una línea en la consola (sin tildes). */
function decir(string $t, bool $error = false): void
{
    fwrite($error ? STDERR : STDOUT, sin_tildes($t) . "\n");
}
