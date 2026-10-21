<?php
declare(strict_types=1);

/**
 * PROGRAMA DE PRUEBA del Protocolo Tukán Central (v1 y v2) del lado de la instancia.
 * Escrito aparte del panel (no usa sus clases) para probar el contrato de verdad (docs/PROTOCOLO.md y docs/PROTOCOLO_V2.md).
 *
 * Configuración por variables de entorno (php -S con env):
 *   TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL   (las del protocolo)
 *   MOCK_DIR       carpeta para la base de prueba (db.json), nonces, límite y registros (sso.log, estado.log, ejecutar.log, rechazos.log)
 *   MOCK_PRODUCTO  tuevento | tusalon | tuvocalia | tuacademia | tuguarderia
 *   MOCK_V1        ''  = programa v2 completo (por defecto)
 *                  400 = programa v1 que contesta "Acción no válida." (400) a lo que no conoce (como TuEvento/TuSalón v1)
 *                  404 = programa v1 que contesta 404 a lo que no conoce (como TuVocalía v1)
 *   MOCK_CLIENTE   (tuguarderia) nombre del cliente
 *   MOCK_DESFASE   segundos que el reloj de este "servidor" está adelantado/atrasado
 *   MOCK_LENTO     segundos que tarda en responder (todo)
 *   MOCK_ROTO=1    "estado" responde JSON dañado
 *   MOCK_LIMITE    pedidos por minuto por IP (por defecto 120; rechazados: 30)
 *   MOCK_BAJA=1    (tuguarderia) sabe borrarse desde el panel: dar_de_baja / cancelar_baja
 *   MOCK_CARPETA   (tuguarderia) carpeta de la instalación (por defecto el ID) · MOCK_DOMINIO su dirección pública
 *   MOCK_BAJA_ESPERA segundos de espera antes de borrar (por defecto 300)
 *   MOCK_SIN_PAUSA=1 programa que todavía no sabe pausar ni borrar clientes (Anexo A): sin pausar, borrar_cliente ni restaurar_cliente
 *   MOCK_SIN_MODULOS=1 programa sin módulos (Anexo B): sin modulos, cambiar_modulos, guardar_modulos ni cambio_modulos
 *   MOCK_SIN_IA=1  programa que todavía no reporta IA (Anexo C): sin ia_uso
 *   MOCK_IA_PAUSADA=1 ia_uso dice pausada: true · MOCK_IA_TOPE=20 tope del programa
 * Archivos de control en MOCK_DIR (se pueden crear durante la prueba):
 *   total          cambia negocios.total del "estado" (para probar el aviso de negocio nuevo)
 *   lento_ejecutar segundos que tarda "ejecutar" DESPUÉS de hacer la operación (para probar "no respondió" + Revisar)
 *   en_curso       N: las próximas N veces "ejecutar" contesta 409 conflicto en_curso
 *   baja_estado    "en_curso" o "error:texto": cómo va el borrado (lo que haría la tarea de root del servidor)
 */

$id = (string) getenv('TUKAN_CENTRAL_ID');
$secreto = (string) getenv('TUKAN_CENTRAL_SECRET');
$dir = (string) (getenv('MOCK_DIR') ?: sys_get_temp_dir());
$ahora = time() + (int) getenv('MOCK_DESFASE');
$v1 = (string) getenv('MOCK_V1');
$producto = (string) (getenv('MOCK_PRODUCTO') ?: 'tuevento');
$unico = $producto === 'tuguarderia';
$conBaja = $unico && getenv('MOCK_BAJA') === '1';
$conPausa = getenv('MOCK_SIN_PAUSA') !== '1';
$conIa = getenv('MOCK_SIN_IA') !== '1';   // Anexo C: ia_uso
$conModulos = getenv('MOCK_SIN_MODULOS') !== '1';   // Anexo B: módulos (modulos, cambiar_modulos, guardar_modulos, solicitudes cambio_modulos)     // Anexo A: pausar / reactivar (y en los de varios clientes, borrar_cliente / restaurar_cliente)
date_default_timezone_set('America/Guayaquil');
if ((int) getenv('MOCK_DESFASE') !== 0) header('Date: ' . gmdate('D, d M Y H:i:s', $ahora) . ' GMT');

$accion = (string) ($_GET['accion'] ?? '');
$esV2Accion = !in_array($accion, ['sso', 'estado'], true);
$NOMBRES = ['tuevento' => 'TuEvento', 'tusalon' => 'TuSalón', 'tuvocalia' => 'TuVocalía', 'tuacademia' => 'TuAcademia', 'tuguarderia' => 'TuGuardería'];

function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function registrar(string $archivo, string $linea): void { file_put_contents($GLOBALS['dir'] . "/$archivo", $linea . "\n", FILE_APPEND); }

/** Respuesta JSON y fin. */
function json_out(array $d, int $http = 200, array $cab = []): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($cab as $c) header($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** Error v2 (§3.2). */
final class Fallo extends Exception
{
    public function __construct(public string $codigo, string $texto, public int $http, public ?string $campo = null, public array $detalle = [])
    {
        parent::__construct($texto);
    }
}
function fallo(string $codigo, string $texto, int $http, ?string $campo = null, array $detalle = []): never
{
    throw new Fallo($codigo, $texto, $http, $campo, $detalle);
}
function error_json(Fallo $f): array
{
    $e = ['ok' => false, 'protocolo' => 2, 'codigo' => $f->codigo, 'error' => $f->getMessage()];
    if ($f->campo !== null) $e['campo'] = $f->campo;
    if ($f->detalle) $e['detalle'] = $f->detalle;
    return $e;
}

// --- Sin configurar: el conector está apagado ---
if ($id === '' || strlen($secreto) < 32) {
    if ($v1 === '' && ($esV2Accion || $accion === 'estado')) json_out(['ok' => false, 'protocolo' => 2, 'codigo' => 'sin_configurar', 'error' => 'El conector no está configurado.'], 404);
    http_response_code(404);
    exit('No encontrado');
}
if ((int) getenv('MOCK_LENTO') > 0) sleep((int) getenv('MOCK_LENTO'));

// --- Límite por IP: MOCK_LIMITE (120) pedidos por minuto y 30 rechazados ---
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$minuto = intdiv(time(), 60);
$archLimite = "$dir/limite.json";
$lim = is_file($archLimite) ? (json_decode((string) file_get_contents($archLimite), true) ?: []) : [];
$lim = array_filter($lim, fn($k) => str_ends_with($k, "|$minuto"), ARRAY_FILTER_USE_KEY);
$lim["t|$ip|$minuto"] = ($lim["t|$ip|$minuto"] ?? 0) + 1;
file_put_contents($archLimite, json_encode($lim));
if ($lim["t|$ip|$minuto"] > (int) (getenv('MOCK_LIMITE') ?: 120) || ($lim["r|$ip|$minuto"] ?? 0) >= 30) {
    if ($v1 !== '') json_out(['ok' => false, 'error' => 'demasiados pedidos'], 429);
    json_out(['ok' => false, 'protocolo' => 2, 'codigo' => 'limite', 'error' => 'Demasiados pedidos desde esta conexión. Espere un minuto.'], 429, ['Retry-After: 60']);
}
function contar_rechazo(): void
{
    global $archLimite, $ip, $minuto;
    $lim = json_decode((string) @file_get_contents($archLimite), true) ?: [];
    $lim["r|$ip|$minuto"] = ($lim["r|$ip|$minuto"] ?? 0) + 1;
    file_put_contents($archLimite, json_encode($lim));
}

/** Nonce de un solo uso (se guarda con su exp; se borran los vencidos). */
function usar_nonce(string $nonce, int $exp): bool
{
    global $dir, $ahora;
    $fp = fopen("$dir/nonces.json", 'c+');
    flock($fp, LOCK_EX);
    $nonces = json_decode((string) stream_get_contents($fp), true) ?: [];
    $nonces = array_filter($nonces, fn($e) => $e + 30 >= $ahora);
    $ok = !isset($nonces[$nonce]);
    if ($ok) {
        $nonces[$nonce] = $exp;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($nonces));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

// =====================================================================
// Acciones que no conoce un programa v1
// =====================================================================
if ($esV2Accion && $v1 !== '') {
    if ($v1 === '404') { http_response_code(404); exit('No encontrado'); }
    json_out(['ok' => false, 'error' => 'Acción no válida.'], 400);
}
if ($esV2Accion && !in_array($accion, ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'modulos', 'ia_uso'], true)) {
    json_out(['ok' => false, 'protocolo' => 2, 'codigo' => 'no_permitido', 'error' => 'Esa acción no existe.', 'detalle' => ['motivo' => 'no_disponible']], 403);
}

// =====================================================================
// v1: sso y estado por GET (con "codigo" en los errores si el programa es v2)
// =====================================================================
if (!$esV2Accion) {
    $codigos = ['token mal formado' => 'firma', 'firma incorrecta' => 'firma', 'version no soportada' => 'version', 'tipo incorrecto' => 'tipo',
                'inst incorrecto' => 'inst', 'tiempo no valido' => 'invalido', 'token vencido' => 'vencido', 'token del futuro' => 'futuro',
                'token demasiado largo' => 'invalido', 'nonce no valido' => 'invalido', 'nonce ya usado' => 'nonce', 'email no valido' => 'invalido'];
    $rechazar = function (string $motivo) use ($accion, $v1, $codigos): never {
        registrar('rechazos.log', "$accion|$motivo");
        contar_rechazo();
        http_response_code(401);
        if ($v1 === '') header('X-Tukan-Codigo: ' . $codigos[$motivo]);
        if ($accion === 'sso') {
            header('Content-Type: text/html; charset=utf-8');
            $vuelta = htmlspecialchars((string) getenv('TUKAN_CENTRAL_URL'), ENT_QUOTES);
            echo "<!doctype html><meta charset=utf-8><title>No se pudo entrar</title><h1>No se pudo entrar</h1><p>El enlace no es válido o ya se usó ($motivo).</p><p><a href=\"$vuelta\">Volver al Panel Tukán</a></p>";
            exit;
        }
        json_out($v1 === '' ? ['ok' => false, 'protocolo' => 2, 'codigo' => $codigos[$motivo], 'error' => $motivo] : ['ok' => false, 'error' => $motivo], 401);
    };
    $t = (string) ($_GET['t'] ?? '');
    $p = explode('.', $t);
    if (count($p) !== 2) $rechazar('token mal formado');
    if (!hash_equals(b64(hash_hmac('sha256', $p[0], $secreto, true)), $p[1])) $rechazar('firma incorrecta');
    $payload = json_decode((string) base64_decode(strtr($p[0], '-_', '+/'), true), true);
    if (!is_array($payload)) $rechazar('token mal formado');
    if (($payload['v'] ?? null) !== 1) $rechazar('version no soportada');
    if (($payload['tipo'] ?? null) !== $accion) $rechazar('tipo incorrecto');
    if (($payload['inst'] ?? null) !== $id) $rechazar('inst incorrecto');
    $iat = $payload['iat'] ?? null;
    $exp = $payload['exp'] ?? null;
    if (!is_int($iat) || !is_int($exp)) $rechazar('tiempo no valido');
    if ($ahora > $exp + 30) $rechazar('token vencido');
    if ($iat > $ahora + 30) $rechazar('token del futuro');
    if ($exp - $iat > 60) $rechazar('token demasiado largo');
    $nonce = $payload['nonce'] ?? '';
    if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) $rechazar('nonce no valido');
    if (!usar_nonce($nonce, $exp)) $rechazar('nonce ya usado');
    if (!filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)) $rechazar('email no valido');

    if ($accion === 'sso') {
        session_name('mock_' . $id);
        session_start();
        session_regenerate_id(true);
        $_SESSION['admin'] = $payload['email'];
        $_SESSION['nombre'] = $payload['nombre'];
        $_SESSION['desde_central'] = true;
        $ir = $payload['ir'] ?? null;
        $destino = '/admin';
        if ($v1 === '' && $ir !== null) {
            $valido = is_string($ir) && strlen($ir) <= 300 && str_starts_with($ir, '/') && !str_starts_with($ir, '//') && !str_contains($ir, '\\')
                   && preg_match("~^/[A-Za-z0-9._\~!$&'()*+,;=:@%/?#-]*$~", $ir) && str_starts_with($ir, '/admin');
            if ($valido) $destino = $ir;
            else registrar('sso.log', 'ir no valido');
        }
        registrar('sso.log', $payload['email'] . '|' . $payload['nombre'] . '|' . $nonce . ($v1 === '' && $ir !== null ? '|ir=' . $ir : ''));
        header('Location: ' . $destino, true, 302);
        exit;
    }

    // Estado
    registrar('estado.log', date('c'));
    header('Content-Type: application/json; charset=utf-8');
    if (getenv('MOCK_ROTO') === '1') exit('{"ok": true, "protocolo": 1, "producto": ');
    if ($v1 !== '') {
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
                'nuevos_30d' => 1, 'ingresos_mes' => 180.0, 'ingresos_mes_anterior' => 200.0, 'pruebas_vencen_7d' => [], 'pagos_vencidos' => [],
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
                'pruebas_vencen_7d' => [], 'pagos_vencidos' => [], 'extra' => ['Mascotas activas' => 86, 'Reservas este mes' => 142, 'Usuarios' => 6],
            ],
            default => [
                'nombre' => 'TuEvento', 'version' => '75df6b3',
                'negocios' => ['total' => 12, 'prueba' => 4, 'activos' => 6, 'por_vencer' => 1, 'vencidos' => 1, 'suspendidos' => 0],
                'nuevos_30d' => 3, 'ingresos_mes' => 414.0, 'ingresos_mes_anterior' => 276.0,
                'pruebas_vencen_7d' => [['nombre' => 'Quinta Los Alamos', 'vence' => $f(4)], ['nombre' => 'Hacienda La Ruta', 'vence' => $f(1)]],
                'pagos_vencidos' => [['nombre' => 'Salón Imperial', 'desde' => $f(-12)]], 'extra' => ['Eventos este mes' => 37, 'Usuarios' => 41],
            ],
        };
        if (is_file("$dir/total") && is_array($datos['negocios'])) $datos['negocios']['total'] = (int) trim((string) file_get_contents("$dir/total"));
        echo json_encode(['ok' => true, 'protocolo' => 1, 'producto' => $producto] + $datos + ['generado' => date('c')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db = abrir_db();
    echo json_encode(['ok' => true, 'protocolo' => 1] + estado_v2($db) + ['generado' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// v2: validación (§3.1, en este orden)
// =====================================================================
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fallo('invalido', 'Use POST.', 405);
    if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) fallo('invalido', 'El contenido debe ser JSON.', 415);
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($cuerpo) > 65536) fallo('invalido', 'El pedido es demasiado grande.', 413);
    $token = (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? '');
    $partes = explode('.', $token);
    if (count($partes) !== 2 || !hash_equals(b64(hash_hmac('sha256', $partes[0], $secreto, true)), $partes[1])) fallo('firma', 'La firma del pedido no es correcta.', 401);
    $p = json_decode((string) base64_decode(strtr($partes[0], '-_', '+/'), true), true);
    if (!is_array($p)) fallo('firma', 'El pedido no es válido.', 401);
    if (($p['v'] ?? null) !== 2) fallo('version', 'Versión de protocolo no soportada.', 401);
    if (($p['tipo'] ?? null) !== $accion) fallo('tipo', 'El pedido no es para esta acción.', 401);
    if (!is_string($p['inst'] ?? null) || !hash_equals($id, $p['inst'])) fallo('inst', 'El pedido es de otra instalación.', 401);
    $iat = $p['iat'] ?? null;
    $exp = $p['exp'] ?? null;
    $max = $accion === 'ejecutar' ? 30 : 60;
    if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) fallo('invalido', 'El pedido dura más de lo permitido.', 401);
    if ($ahora > $exp + 30) fallo('vencido', 'El pedido venció. Intente otra vez desde el Panel Tukán.', 401);
    if ($iat > $ahora + 30) fallo('futuro', 'El pedido tiene fecha futura (revise la hora del servidor).', 401);
    if (!is_string($p['bh'] ?? null) || !hash_equals(b64(hash('sha256', $cuerpo, true)), $p['bh'])) fallo('firma', 'El contenido del pedido cambió en el camino.', 401, null, ['parte' => 'cuerpo']);
    if (!is_string($p['nonce'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $p['nonce'])) fallo('nonce', 'El pedido no tiene un código de un solo uso válido.', 401);
    if (!usar_nonce($p['nonce'], $exp)) fallo('nonce', 'Este pedido ya se usó.', 401);
    if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) fallo('invalido', 'Correo inválido en el pedido.', 401);
    $datos = json_decode($cuerpo, true, 8);
    if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) fallo('invalido', 'El contenido no es un objeto JSON.', 400);
    $cap = capacidades();
    if (!in_array($accion, $cap['acciones'], true)) fallo('no_permitido', 'Esta acción no está disponible en este programa.', 403, null, ['motivo' => 'no_disponible']);
} catch (Fallo $f) {
    if ($f->http === 401) contar_rechazo();
    registrar('rechazos.log', "$accion|{$f->codigo}");
    json_out(error_json($f), $f->http, $f->codigo === 'limite' ? ['Retry-After: 60'] : []);
}

$quien = ['email' => $p['email'], 'nombre' => (string) ($p['nombre'] ?? ''), 'rol' => (string) ($p['rol'] ?? '')];
$base = ['ok' => true, 'protocolo' => 2, 'accion' => $accion, 'producto' => $producto, 'generado' => date('c')];

// La base de prueba se usa con candado (un pedido a la vez)
$candado = fopen("$dir/db.lock", 'c');
flock($candado, LOCK_EX);
$db = abrir_db();
try {
    $r = match ($accion) {
        'capacidades' => capacidades(),
        'modulos' => mod_accion_modulos(),
        'ia_uso' => accion_ia_uso($db, $datos),
        'clientes' => accion_clientes($db, $datos),
        'cliente' => ['cliente' => cliente_detalle($db, cliente_de($db, (string) ($datos['id'] ?? '')))],
        'cotizar' => accion_cotizar($db, $datos),
        'planes' => accion_planes($db),
        'solicitudes' => accion_solicitudes($db, $datos),
        'solicitud' => ['solicitud' => solicitud_de($db, (string) ($datos['id'] ?? ''), true)],
        'adjunto' => accion_adjunto($db, $datos),
        'finanzas' => accion_finanzas($db, $datos),
        'ejecutar' => accion_ejecutar($db, $datos, $quien, $p['bh']),
    };
    guardar_db($db);
    flock($candado, LOCK_UN);
    if ($accion === 'ejecutar' && is_file("$dir/lento_ejecutar")) sleep((int) trim((string) file_get_contents("$dir/lento_ejecutar")));
    json_out($base + $r, (int) ($r['_http'] ?? 200));
} catch (Fallo $f) {
    guardar_db($db);
    flock($candado, LOCK_UN);
    registrar('rechazos.log', "$accion|{$f->codigo}");
    json_out(error_json($f), $f->http);
}

// =====================================================================
// Base de prueba (db.json) con datos de ejemplo que dependen de la fecha de hoy
// =====================================================================

function hoy(): string { return date('Y-m-d', $GLOBALS['ahora']); }
function dia(int $d): string { return date('Y-m-d', strtotime(hoy() . " $d days")); }
function mas_meses(string $f, int $m): string { return (new DateTimeImmutable($f))->modify(sprintf("%+d months", $m))->format("Y-m-d"); }
function dias_entre(string $a, string $b): int { return (int) (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a'); }

function abrir_db(): array
{
    $archivo = $GLOBALS['dir'] . '/db.json';
    $db = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : sembrar();
    // Archivo de control "agregar_demos": N pedidos de demo nuevos (para probar los avisos del cron)
    $ctl = $GLOBALS['dir'] . '/agregar_demos';
    if (is_file($ctl)) {
        $n = max(1, (int) trim((string) file_get_contents($ctl)));
        unlink($ctl);
        for ($k = 0; $k < $n; $k++) {
            $id = 'demo_pedida:' . (20 + count($db['solicitudes']));
            $db['solicitudes'][$id] = ['tipo' => 'demo_pedida', 'estado' => 'nueva', 'fecha' => date('c'), 'cliente_id' => null,
                'quien' => ['nombre' => 'Marta Ruiz', 'organizacion' => 'Club Nuevo ' . (count($db['solicitudes'])), 'telefono' => '0987001122', 'email' => 'marta' . count($db['solicitudes']) . '@club.ec'],
                'detalle' => ['tipo' => 'escuela', 'ciudad' => 'Loja', 'tamano' => '50 alumnos', 'plan_interes' => 'Básico', 'mensaje' => 'Me interesa.'], 'nota' => ''];
        }
        file_put_contents($archivo, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
    if (is_file($archivo)) return $db;
    file_put_contents($archivo, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    return $db;
}

function guardar_db(array $db): void
{
    file_put_contents($GLOBALS['dir'] . '/db.json', json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

function planes_lista(): array
{
    return [
        '1' => ['codigo' => '1', 'nombre' => 'Básico', 'mensual' => 19.0],
        '2' => ['codigo' => '2', 'nombre' => 'Completo', 'mensual' => 29.0],
    ];
}
function periodos(): array { return ['mensual' => ['meses' => 1, 'paga' => 1], 'semestral' => ['meses' => 6, 'paga' => 5], 'anual' => ['meses' => 12, 'paga' => 10]]; }
function metodos(): array { return ['transferencia' => 'Transferencia', 'deposito' => 'Depósito', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta / Plux', 'otro' => 'Otro']; }
function precio(string $plan, string $periodo): float { return round(planes_lista()[$plan]['mensual'] * periodos()[$periodo]['paga'], 2); }

function sembrar(): array
{
    global $producto, $unico;
    $nombres = [
        'tuevento' => ['Quinta Los Álamos', 'Hacienda La Ruta', 'Salón Imperial', 'Quinta El Edén', 'Hacienda San Rafael', 'Salón Cristal', 'Quinta Villa Real', 'Eventos La Colina', 'Hacienda Santa Clara'],
        'tuvocalia' => ['Liga Norte', 'Escuela Fútbol Kids', 'Liga Barrial Chillogallo', 'Liga Parroquial Conocoto', 'Escuela Sur', 'Liga La Floresta', 'Liga Carcelén', 'Federación Barrial Pichincha', 'Liga Solanda'],
        'tuacademia' => ['Academia Ritmo', 'Escuela de Natación Delfines', 'Academia Musical Allegro', 'Club de Danza Sol', 'Academia Kyo', 'Escuela de Patinaje Rueda', 'Academia de Canto Voces', 'Club de Tenis Raqueta', 'Academia Ballet Luna'],
        'tusalon' => ['Barbería El Bigote', 'Peluquería Glamour', 'Estética Sofi', 'Barbería Clásica', 'Salón Belleza Total', 'Peluquería Estilo', 'Spa Relax', 'Barbería Urbana', 'Salón Diva'],
    ][$producto] ?? array_map(fn($i) => "Negocio $i", range(1, 9));
    $duenos = ['Juan Pérez', 'María López', 'Carlos Andrade', 'Ana Torres', 'Luis Guamán', 'Sofía Cevallos', 'Diego Vera', 'Paola Jaramillo', 'Jorge Mena'];
    $ciudades = ['Quito', 'Guayaquil', 'Cuenca', 'Quito', 'Ambato', 'Loja', 'Quito', 'Manta', 'Ibarra'];
    // [estado de ejemplo, plan, vence (días), alta (días), origen, pagos: [periodo, cuántos, último hace N días], actividad hace N días, método]
    $plantillas = [
        ['prueba', '2', 2, -5, 'demo_pedida', null, 1, null],
        ['prueba', '1', 6, -1, 'registro', null, 0, null],
        ['pagado', '1', 3, -200, 'super', ['mensual', 6], 2, 'tarjeta'],
        ['pagado', '2', 20, -400, 'super', ['mensual', 12], 20, 'transferencia'],
        ['pagado', '1', 150, -40, 'demo_pedida', ['semestral', 1], 3, 'transferencia'],
        ['pagado', '1', -2, -100, 'registro', ['mensual', 3], 6, 'efectivo'],
        ['prueba', '1', -10, -25, 'registro', null, 30, null],
        ['pagado', '2', 40, -300, 'super', ['mensual', 2], 60, 'transferencia'],
        ['cortesia', '2', null, -500, 'super', null, 1, null],
    ];
    $db = ['clientes' => [], 'solicitudes' => [], 'atenciones' => [], 'operaciones' => [], 'registro' => [], 'sig_cliente' => 100, 'sig_pago' => 1];
    foreach ($plantillas as $i => [$tipo, $plan, $vence, $alta, $origen, $pagos, $actividad, $metodo]) {
        $cid = (string) ($i + 1);
        $c = [
            'id' => $cid, 'nombre' => $nombres[$i], 'tipo' => $producto === 'tuvocalia' ? ($i % 3 === 1 ? 'escuela' : 'liga') : null, 'ciudad' => $ciudades[$i],
            'plan' => $plan, 'es_prueba' => $tipo === 'prueba', 'vence' => $vence === null ? null : dia($vence), 'suspendido' => $i === 7,
            'alta' => dia($alta), 'dueno' => ['nombre' => $duenos[$i], 'telefono' => '09912345' . str_pad((string) (60 + $i), 2, '0', STR_PAD_LEFT),
                                              'email' => strtolower(strtr(explode(' ', $duenos[$i])[0], ['í' => 'i', 'é' => 'e', 'á' => 'a'])) . ($i + 1) . '@ejemplo.com'],
            'ultima_actividad' => date('c', strtotime(hoy() . " 10:00 -$actividad days")), 'origen' => $origen, 'notas' => '', 'pagos' => [],
        ];
        if ($pagos) {
            [$periodo, $n] = $pagos;
            $meses = periodos()[$periodo]['meses'];
            $hasta = $c['vence'];
            for ($k = 0; $k < $n; $k++) {
                $desde = mas_meses($hasta, -$meses);
                $fecha = $desde < hoy() ? $desde : hoy();
                array_unshift($c['pagos'], ['id' => (string) $db['sig_pago']++, 'fecha' => $fecha, 'monto' => precio($plan, $periodo), 'plan' => $plan, 'periodo' => $periodo,
                                            'meses' => $meses, 'desde' => $desde, 'hasta' => $hasta, 'metodo' => $k === 0 && $metodo === 'tarjeta' ? 'tarjeta' : ($metodo ?? 'transferencia'),
                                            'nota' => '', 'registrado_por' => 'Súper', 'anulado' => false]);
                $hasta = $desde;
            }
            // los ids de pago en orden de fecha
            foreach ($c['pagos'] as $k => &$pg) $pg['id'] = $cid . '0' . ($k + 1);
            unset($pg);
        }
        $db['clientes'][$cid] = $c;
    }
    if (in_array($producto, ['tuvocalia', 'tuacademia'], true)) {
        $db['solicitudes']['demo_pedida:12'] = ['tipo' => 'demo_pedida', 'estado' => 'nueva', 'fecha' => date('c', $GLOBALS['ahora'] - 7200), 'cliente_id' => null,
            'quien' => ['nombre' => 'Roberto Salazar', 'organizacion' => $producto === 'tuvocalia' ? 'Liga Deportiva Calderón' : 'Academia Pasos', 'telefono' => '0987654321', 'email' => 'roberto@calderon.ec'],
            'detalle' => ['tipo' => 'liga', 'ciudad' => 'Quito', 'tamano' => '10 equipos', 'plan_interes' => 'Completo', 'mensaje' => 'Quiero probar el sistema para el campeonato de este año.'],
            'nota' => ''];
        $db['solicitudes']['comprobante:45'] = ['tipo' => 'comprobante', 'estado' => 'pendiente', 'fecha' => date('c', $GLOBALS['ahora'] - 3600), 'cliente_id' => '3',
            'plan' => '1', 'meses' => 6, 'monto' => 95.0, 'forma_pago' => 'transferencia', 'nota' => 'Pichincha 12345', 'respuesta' => ''];
        $db['solicitudes']['soporte:9'] = ['tipo' => 'soporte', 'estado' => 'nuevo', 'sin_leer' => true, 'prioridad' => 'alta', 'subtipo' => 'problema',
            'fecha' => date('c', $GLOBALS['ahora'] - 86400), 'cliente_id' => '4', 'asunto' => 'No puedo subir el logo',
            'mensajes' => [['de' => 'cliente', 'autor' => 'Ana Torres', 'momento' => date('c', $GLOBALS['ahora'] - 86400), 'texto' => 'Hola, al subir el logo sale un error. ¿Me ayudan?', 'adjunto_id' => '31']]];
        if ($producto === 'tuvocalia') {
            $db['solicitudes']['derechos_datos:3'] = ['tipo' => 'derechos_datos', 'estado' => 'nueva', 'fecha' => date('c', $GLOBALS['ahora'] - 3 * 86400), 'cliente_id' => null,
                'quien' => ['nombre' => 'Pedro Ruiz', 'organizacion' => null, 'telefono' => null, 'email' => 'pedro.ruiz@correo.ec'], 'derecho' => 'acceso',
                'mensaje' => 'Quiero saber qué datos míos tienen.', 'respuesta' => ''];
        }
    }
    if ($unico) {
        $db['unico'] = ['nombre' => (string) (getenv('MOCK_CLIENTE') ?: 'Dogssy'), 'dueno' => ['nombre' => 'Gabriela Ortiz', 'telefono' => '0998877665', 'email' => 'gabriela@dogssy.ec'],
                        'mods' => ['plan' => 'completo', 'extra' => [], 'quitados' => ['hotel'], 'acordado' => null]];
        if ($GLOBALS['conModulos']) $db['solicitudes']['cambio_modulos:1'] = ['tipo' => 'cambio_modulos', 'estado' => 'pendiente', 'fecha' => date('c', $GLOBALS['ahora'] - 1800),
            'cliente_id' => 'unico', 'encender' => ['hotel'], 'apagar' => [], 'nota' => 'Abrimos el hotel en diciembre'];
    } elseif ($GLOBALS['conModulos']) {
        $db['clientes']['3']['mods'] = ['plan' => '1', 'extra' => ['reportes'], 'quitados' => [], 'acordado' => null];
        $db['solicitudes']['cambio_modulos:7'] = ['tipo' => 'cambio_modulos', 'estado' => 'pendiente', 'fecha' => date('c', $GLOBALS['ahora'] - 5400),
            'cliente_id' => '5', 'encender' => ['pagos_linea'], 'apagar' => [], 'nota' => 'Quiero cobrar con tarjeta'];
    }
    return $db;
}

/** Estado del cliente con la regla única de §4.2 (gracia de 3 días). */
function estado_de(array $c): array
{
    $hoy = hoy();
    if (!empty($c['pausa'])) return ['suspendido', 'pausada', false, true, null];
    if ($c['suspendido']) return ['suspendido', 'suspendida', false, true, null];
    if ($c['vence'] === null) return ['activo', 'sin_limite', false, false, null];
    $dias = dias_entre($hoy, $c['vence']);
    if ($c['es_prueba']) return $dias >= 0 ? ['prueba', 'prueba', false, false, $dias] : ['vencido', 'prueba_vencida', false, true, $dias];
    if ($dias > 7) return ['activo', 'activa', false, false, $dias];
    if ($dias >= 0) return ['por_vencer', 'activa', false, false, $dias];
    if ($dias >= -3) return ['vencido', 'gracia', true, false, $dias];
    return ['vencido', 'vencida', false, true, $dias];
}

function pagos_validos(array $c): array { return array_values(array_filter($c['pagos'], fn($p) => !$p['anulado'])); }
/** Los clientes que no están en la papelera (los borrados no cuentan en listas, cifras ni finanzas). */
function vivos(array $db): array { return array_filter($db['clientes'], fn($c) => empty($c['papelera'])); }
function tipos_pausa(): array { return ['falta_pago' => 'Falta de pago', 'pedido_cliente' => 'A pedido del cliente', 'revision' => 'En revisión', 'abuso' => 'Uso indebido', 'otro' => 'Otro']; }
function pausa_publica(?array $p): ?array { return $p ? $p + ['tipo_nombre' => tipos_pausa()[$p['tipo']] ?? $p['tipo']] : null; }
function campos_pausa(): array
{
    return [['campo' => 'tipo', 'etiqueta' => 'Tipo de pausa', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'falta_pago',
             'opciones' => array_map(fn($k, $v) => ['valor' => $k, 'etiqueta' => $v], array_keys(tipos_pausa()), tipos_pausa())],
            ['campo' => 'motivo', 'etiqueta' => 'Motivo (lo ve el cliente)', 'tipo' => 'texto_largo', 'requerido' => true, 'min' => 3, 'max' => 300]];
}
/** pausar (Anexo A.1): tipo + motivo. Devuelve [pausa nueva, sin_cambios]. */
function pedir_pausa(?array $antes, array $d, array $quien): array
{
    $tipo = $d['tipo'] ?? null;
    if (!is_string($tipo) || !isset(tipos_pausa()[$tipo])) fallo('invalido', 'Elija el tipo de pausa.', 422, 'datos.tipo');
    $motivo = texto_campo($d, 'motivo', 3, 300, 'Escriba el motivo (3 a 300 letras).');
    if ($antes && $antes['tipo'] === $tipo && $antes['motivo'] === $motivo) return [$antes, true];
    return [['tipo' => $tipo, 'motivo' => $motivo, 'desde' => $antes['desde'] ?? date('c'), 'quien' => "{$quien['nombre']} <{$quien['email']}>"], false];
}

function cliente_resumen(array $c): array
{
    global $NOMBRES, $producto;
    [$estado, $detalle, $gracia, $bloqueado, $dias] = estado_de($c);
    $pv = pagos_validos($c);
    $ultimo = $pv ? end($pv) : null;
    $plan = planes_lista()[$c['plan']] ?? null;
    $texto = match ($estado) {
        'prueba' => 'Demo hasta el ' . $c['vence'] . " (faltan $dias días)",
        'por_vencer' => 'Vence el ' . $c['vence'] . " (faltan $dias días)",
        'activo' => $c['vence'] ? 'Activa hasta el ' . $c['vence'] : 'Sin vencimiento (cortesía)',
        'vencido' => ($gracia ? 'Venció el ' . $c['vence'] . ' (en gracia)' : 'Venció el ' . $c['vence']),
        default => !empty($c['pausa']) ? 'Pausada (' . tipos_pausa()[$c['pausa']['tipo']] . '): ' . $c['pausa']['motivo'] : 'Suspendida',
    };
    $wa = preg_replace('/\D+/', '', $c['dueno']['telefono']);
    return [
        'id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'tipo_nombre' => $c['tipo'] ? ucfirst($c['tipo']) : null, 'ciudad' => $c['ciudad'],
        'plan' => $plan ? ['codigo' => $plan['codigo'], 'nombre' => $plan['nombre']] : null,
        'estado' => $estado, 'estado_detalle' => $detalle, 'estado_texto' => $texto, 'en_gracia' => $gracia, 'bloqueado' => $bloqueado,
        'fechas' => ['alta' => $c['alta'], 'prueba_hasta' => $c['es_prueba'] ? $c['vence'] : null, 'activo_hasta' => $c['es_prueba'] ? null : $c['vence'],
                     'vence' => $c['vence'], 'dias_para_vencer' => $dias],
        'dueno' => ['nombre' => $c['dueno']['nombre'], 'telefono' => $c['dueno']['telefono'], 'whatsapp' => str_starts_with($wa, '0') ? '593' . substr($wa, 1) : $wa, 'email' => $c['dueno']['email']],
        'ultima_actividad' => $c['ultima_actividad'],
        'pagos' => ['total' => round(array_sum(array_column($pv, 'monto')), 2), 'cantidad' => count($pv), 'primer_pago' => $pv[0]['fecha'] ?? null,
                    'ultimo_pago' => $ultimo['fecha'] ?? null, 'ultimo_monto' => $ultimo['monto'] ?? null],
        'mensual_estimado' => $ultimo && in_array($estado, ['activo', 'por_vencer'], true) ? round($ultimo['monto'] / $ultimo['meses'], 2) : 0.0,
        'origen' => $c['origen'], 'vendedor' => null,
        'uso' => $producto === 'tuvocalia' ? ['Equipos' => 4 + (int) $c['id'], 'Campeonatos' => 1] : ['Usuarios' => 2 + (int) $c['id']],
        'ir' => '/admin?cliente=' . $c['id'], 'ir_trabajar' => '/admin?entrar=' . $c['id'], 'actualizado' => date('c'),
        'pausa' => pausa_publica($c['pausa'] ?? null),
        'papelera' => !empty($c['papelera']) ? ['desde' => $c['papelera']['desde'], 'hasta' => $c['papelera']['hasta'], 'motivo' => $c['papelera']['motivo']] : null,
    ] + ($GLOBALS['conModulos'] ? ['modulos' => mod_resumen($c['mods'] ?? mod_defecto($c['plan']))] : []);
}

function cliente_de(array $db, string $cid): array
{
    global $unico;
    if ($unico) {
        if ($cid !== 'unico') fallo('no_encontrado', 'Ese cliente no existe.', 404);
        return ['unico' => true];
    }
    if (!isset($db['clientes'][$cid])) fallo('no_encontrado', 'Ese cliente no existe.', 404);
    return $db['clientes'][$cid];
}

function opciones_planes(): array { return array_values(array_map(fn($p) => ['valor' => $p['codigo'], 'etiqueta' => $p['nombre']], planes_lista())); }
function opciones_periodos(): array { return [['valor' => 'mensual', 'etiqueta' => '1 mes'], ['valor' => 'semestral', 'etiqueta' => '6 meses (1 gratis)'], ['valor' => 'anual', 'etiqueta' => '12 meses (2 gratis)']]; }
function opciones_metodos(): array { return array_map(fn($k, $v) => ['valor' => $k, 'etiqueta' => $v], array_keys(metodos()), metodos()); }

function acciones_cliente(array $c, array $r): array
{
    global $NOMBRES, $producto;
    $cap = capacidades();
    $puede = fn(string $op) => in_array($op, $cap['ejecutar'], true);
    $a = [];
    if (!empty($c['papelera'])) {
        // En la papelera: solo se puede restaurar
        return $puede('restaurar_cliente') ? [['accion' => 'restaurar_cliente', 'etiqueta' => 'Restaurar', 'estilo' => 'principal',
            'confirmar' => '¿Restaurar ' . $c['nombre'] . '? Vuelve tal como estaba.', 'datos' => ['cliente_id' => $c['id']], 'campos' => []]] : [];
    }
    $sinPagos = $r['pagos']['cantidad'] === 0;
    if ($puede('registrar_pago') && $r['estado'] !== 'suspendido') {
        $a[] = ['accion' => 'registrar_pago', 'etiqueta' => 'Registrar pago', 'estilo' => 'principal', 'confirmar' => '¿Registrar el pago de ' . $c['nombre'] . '?',
                'datos' => ['cliente_id' => $c['id']], 'campos' => [
                    ['campo' => 'plan', 'etiqueta' => 'Plan', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $c['plan'], 'opciones' => opciones_planes()],
                    ['campo' => 'periodo', 'etiqueta' => 'Tiempo', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'mensual', 'opciones' => opciones_periodos()],
                    ['campo' => 'monto', 'etiqueta' => 'Valor recibido ($)', 'tipo' => 'dinero', 'requerido' => true, 'valor' => precio($c['plan'], 'mensual'), 'min' => 0, 'cotizar' => true],
                    ['campo' => 'metodo', 'etiqueta' => 'Forma de pago', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'transferencia', 'opciones' => opciones_metodos()],
                    ['campo' => 'fecha', 'etiqueta' => 'Fecha del pago', 'tipo' => 'fecha', 'requerido' => true, 'valor' => hoy()],
                    ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 200, 'ayuda' => 'N° de transferencia, factura…'],
                ]];
    }
    if ($puede('extender_prueba') && ($r['estado'] === 'prueba' || ($r['estado'] === 'vencido' && $sinPagos))) {
        $a[] = ['accion' => 'extender_prueba', 'etiqueta' => 'Alargar prueba', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['cliente_id' => $c['id']],
                'campos' => [['campo' => 'dias', 'etiqueta' => 'Días más', 'tipo' => 'dias', 'requerido' => true, 'valor' => 7, 'min' => 1, 'max' => 90]]];
    }
    if ($puede('cambiar_plan')) {
        $a[] = ['accion' => 'cambiar_plan', 'etiqueta' => 'Cambiar plan', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['cliente_id' => $c['id']],
                'campos' => [['campo' => 'plan', 'etiqueta' => 'Plan nuevo (sin cobrar)', 'tipo' => 'opcion', 'requerido' => true, 'valor' => $c['plan'], 'opciones' => opciones_planes()]]];
    }
    if ($puede('nueva_clave_dueno')) {
        $a[] = ['accion' => 'nueva_clave_dueno', 'etiqueta' => 'Nueva clave del dueño', 'estilo' => 'normal', 'confirmar' => '¿Crear una contraseña nueva para el dueño? La anterior deja de servir.', 'datos' => ['cliente_id' => $c['id']], 'campos' => []];
    }
    if ($puede('nota_interna')) {
        $a[] = ['accion' => 'nota_interna', 'etiqueta' => 'Nota interna', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['cliente_id' => $c['id']],
                'campos' => [['campo' => 'texto', 'etiqueta' => 'Nota (el cliente no la ve)', 'tipo' => 'texto_largo', 'requerido' => false, 'valor' => $c['notas'], 'max' => 4000]]];
    }
    if ($r['estado'] === 'suspendido' && $puede('reactivar')) {
        $a[] = ['accion' => 'reactivar', 'etiqueta' => 'Reactivar', 'estilo' => 'principal', 'confirmar' => '¿Reactivar ' . $c['nombre'] . '?', 'datos' => ['cliente_id' => $c['id']], 'campos' => []];
    } else {
        if ($puede('suspender')) {
            $a[] = ['accion' => 'suspender', 'etiqueta' => 'Suspender', 'estilo' => 'peligro', 'confirmar' => '¿Suspender ' . $c['nombre'] . '? No podrá trabajar hasta que lo reactives.', 'datos' => ['cliente_id' => $c['id']],
                    'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 200]]];
        }
        if ($puede('pausar')) {
            // estilo "normal" a propósito: el panel lo pone rojo igual (Anexo A)
            $a[] = ['accion' => 'pausar', 'etiqueta' => 'Pausar', 'estilo' => 'normal', 'confirmar' => '¿Pausar ' . $c['nombre'] . '? Verá «Tu cuenta está pausada» hasta que lo reactives.',
                    'datos' => ['cliente_id' => $c['id']], 'campos' => campos_pausa()];
        }
    }
    if ($puede('borrar_cliente')) {
        $a[] = ['accion' => 'borrar_cliente', 'etiqueta' => 'Borrar', 'estilo' => 'peligro', 'confirmar' => 'Se guarda una copia y queda 30 días en la papelera. ¿Borrar ' . $c['nombre'] . '?',
                'datos' => ['cliente_id' => $c['id']], 'campos' => [
                    ['campo' => 'confirmar', 'etiqueta' => 'Escriba el nombre exacto (' . $c['nombre'] . ')', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
                    ['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto_largo', 'requerido' => true, 'min' => 3, 'max' => 300]]];
    }
    $a[] = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'whatsapp' => $r['dueno']['whatsapp'], 'texto' => 'Hola ' . explode(' ', $c['dueno']['nombre'])[0] . ', le saluda Tukán Software de ' . $NOMBRES[$producto] . '.'];
    $a[] = ['accion' => 'abrir', 'etiqueta' => 'Ver en ' . $NOMBRES[$producto], 'ir' => '/admin?cliente=' . $c['id']];
    return $a;
}

function cliente_detalle(array $db, array $c): array
{
    global $NOMBRES, $producto, $unico;
    if ($unico) {
        $pu = $db['unico']['pausa'] ?? null;
        return ['id' => 'unico', 'nombre' => $db['unico']['nombre'], 'tipo' => null, 'ciudad' => 'Quito', 'plan' => null, 'estado' => $pu ? 'suspendido' : 'activo', 'estado_detalle' => $pu ? 'pausado' : null,
                'estado_texto' => $pu ? 'Pausado (' . tipos_pausa()[$pu['tipo']] . '): ' . $pu['motivo'] : null, 'en_gracia' => false, 'bloqueado' => $pu !== null,
                'fechas' => ['alta' => '2026-01-10', 'prueba_hasta' => null, 'activo_hasta' => null, 'vence' => null, 'dias_para_vencer' => null],
                'dueno' => $db['unico']['dueno'] + ['whatsapp' => '593998877665'], 'ultima_actividad' => date('c', $GLOBALS['ahora'] - 3600),
                'pagos' => ['total' => 0.0, 'cantidad' => 0, 'primer_pago' => null, 'ultimo_pago' => null, 'ultimo_monto' => null], 'mensual_estimado' => 0.0,
                'origen' => 'super', 'vendedor' => null, 'uso' => ['Mascotas activas' => 86, 'Usuarios' => 6], 'ir' => '/admin?usuarios', 'ir_trabajar' => null,
                'notas_admin' => '', 'usuarios' => 6, 'pagos_detalle' => [], 'registro' => [], 'mensajes_sugeridos' => [],
                'pausa' => pausa_publica($db['unico']['pausa'] ?? null),
                'solicitudes' => $GLOBALS['conModulos'] ? array_values(array_filter(todas_solicitudes($db), fn($s) => $s['estado'] === 'pendiente')) : [],
                'acciones_posibles' => array_merge([['accion' => 'nueva_clave_dueno', 'etiqueta' => 'Nueva clave del administrador', 'estilo' => 'normal', 'confirmar' => '¿Crear una contraseña nueva para el administrador?', 'datos' => ['cliente_id' => 'unico'], 'campos' => []],
                                        ['accion' => 'abrir', 'etiqueta' => 'Ver usuarios', 'ir' => '/admin?usuarios']], acciones_pausa_unico($db), acciones_baja($db))]
               + ($GLOBALS['conBaja'] ? ['baja' => baja_de($db) + ['carpeta' => carpeta_mock()]] : [])
               + ($GLOBALS['conModulos'] ? ['modulos' => mod_resumen($db['unico']['mods'] ?? null)] : []);
    }
    $r = cliente_resumen($c);
    $plan = planes_lista()[$c['plan']];
    $pagos = [];
    foreach (array_reverse($c['pagos']) as $pg) {
        $pagos[] = ['id' => $pg['id'], 'fecha' => $pg['fecha'], 'monto' => $pg['monto'], 'plan' => ['codigo' => $pg['plan'], 'nombre' => planes_lista()[$pg['plan']]['nombre']],
                    'periodo' => $pg['periodo'], 'meses' => $pg['meses'], 'desde' => $pg['desde'], 'hasta' => $pg['hasta'], 'metodo' => $pg['metodo'],
                    'metodo_nombre' => metodos()[$pg['metodo']] ?? $pg['metodo'], 'nota' => $pg['nota'], 'registrado_por' => $pg['registrado_por'],
                    'origen' => 'manual', 'anulado' => $pg['anulado'], 'puede_anular' => !$pg['anulado']];
    }
    $nombre = explode(' ', $c['dueno']['nombre'])[0];
    $registro = array_values(array_filter($db['registro'], fn($x) => $x['cliente_id'] === $c['id']));
    return $r + [
        'notas_admin' => $c['notas'], 'usuarios' => 3, 'recomendado_por' => null, 'combo_con' => null, 'dominio' => '',
        'enlace_publico' => 'https://' . $producto . '.ejemplo.ec/' . $c['id'],
        'pagos_detalle' => $pagos,
        'solicitudes' => array_values(array_filter(todas_solicitudes($db), fn($s) => $s['cliente_id'] === $c['id'] && in_array($s['estado'], ['pendiente', 'en_proceso'], true))),
        'registro' => array_slice(array_reverse($registro), 0, 20),
        'mensajes_sugeridos' => [
            'bienvenida' => "Hola $nombre, le saluda Tukán Software de {$NOMBRES[$producto]}. ¡Bienvenido!",
            'recordar_prueba' => $r['estado'] === 'prueba' ? "Hola $nombre, ¿cómo le va con la demo de {$c['nombre']}? Le quedan {$r['fechas']['dias_para_vencer']} día(s). El plan {$plan['nombre']} cuesta $" . number_format($plan['mensual'], 2, ',', '.') . ' al mes.' : null,
            'recordar_renovacion' => in_array($r['estado'], ['por_vencer', 'activo'], true) && $c['vence'] ? "Hola $nombre, el plan de {$c['nombre']} vence el {$c['vence']}. Renovar cuesta $" . number_format($plan['mensual'], 2, ',', '.') . '. Transferencia a Banco Pichincha 2200XXXX.' : null,
            'vencido' => $r['estado'] === 'vencido' ? "Hola $nombre, el plan de {$c['nombre']} venció el {$c['vence']}." : null,
        ],
        'acciones_posibles' => acciones_cliente($c, $r),
    ];
}

// =====================================================================
// Acciones de lectura
// =====================================================================

function carpeta_mock(): string { return (string) (getenv('MOCK_CARPETA') ?: getenv('TUKAN_CENTRAL_ID')); }

/** Cómo va el borrado (como lo leería el conector de TuGuardería en /var/lib/tukan_bajas). */
function baja_de(array $db): array
{
    $b = $db['baja'] ?? null;
    if ($b === null) return ['estado' => null];
    $f = $GLOBALS['dir'] . '/baja_estado';
    $ctl = is_file($f) ? trim((string) file_get_contents($f)) : '';
    if ($ctl === 'en_curso') return ['estado' => 'en_curso', 'texto' => 'Borrando ahora (primero la copia final).', 'borrar_desde' => date('c', $b['desde']), 'copia_en' => '/var/backups/borrados/'];
    if (str_starts_with($ctl, 'error:')) return ['estado' => 'error', 'texto' => substr($ctl, 6), 'momento' => date('c')];
    return ['estado' => 'programada', 'texto' => 'Borrado programado.', 'borrar_desde' => date('c', $b['desde']), 'segundos' => max(0, $b['desde'] - $GLOBALS['ahora']), 'quien' => $b['quien'], 'copia_en' => '/var/backups/borrados/'];
}

function acciones_pausa_unico(array $db): array
{
    if (!$GLOBALS['conPausa']) return [];
    if (!empty($db['unico']['pausa'])) return [['accion' => 'reactivar', 'etiqueta' => 'Reactivar', 'estilo' => 'principal', 'confirmar' => '¿Quitar la pausa?', 'datos' => ['cliente_id' => 'unico'], 'campos' => []]];
    return [['accion' => 'pausar', 'etiqueta' => 'Pausar', 'estilo' => 'peligro', 'confirmar' => '¿Pausar toda la instalación?', 'datos' => ['cliente_id' => 'unico'], 'campos' => campos_pausa()]];
}

function acciones_baja(array $db): array
{
    if (!$GLOBALS['conBaja']) return [];
    $e = baja_de($db)['estado'];
    if ($e === 'programada') return [['accion' => 'cancelar_baja', 'etiqueta' => 'Cancelar el borrado', 'estilo' => 'normal', 'confirmar' => '¿Cancelar?', 'datos' => ['cliente_id' => 'unico'], 'campos' => []]];
    if ($e === 'en_curso') return [];
    return [['accion' => 'dar_de_baja', 'etiqueta' => 'Borrar esta instalación', 'estilo' => 'peligro', 'confirmar' => '¿Seguro?', 'datos' => ['cliente_id' => 'unico'],
             'campos' => [['campo' => 'confirmar', 'etiqueta' => 'Escriba la carpeta (' . carpeta_mock() . ') para confirmar', 'tipo' => 'texto', 'requerido' => true]]]];
}

function capacidades(): array
{
    global $producto, $NOMBRES, $unico;
    if ($unico) {
        return ['revision' => 1, 'producto' => $producto, 'nombre' => $NOMBRES[$producto], 'version' => '57ed3ce', 'unico' => true, 'moneda' => 'USD',
                'zona_horaria' => 'America/Guayaquil', 'url_publica' => getenv('MOCK_DOMINIO') ? 'https://' . getenv('MOCK_DOMINIO') : 'https://dogssy.ejemplo.ec',
                'acciones' => array_merge(['capacidades', 'estado', 'sso', 'cliente', 'ejecutar'], $GLOBALS['conModulos'] ? ['modulos', 'solicitudes', 'solicitud'] : [], $GLOBALS['conIa'] ? ['ia_uso'] : []), 'sso_ir' => true, 'rutas_sso' => ['/admin'],
                'ejecutar' => array_merge(['nueva_clave_dueno'], $GLOBALS['conPausa'] ? ['pausar', 'reactivar'] : [], $GLOBALS['conBaja'] ? ['dar_de_baja', 'cancelar_baja'] : [],
                                          $GLOBALS['conModulos'] ? ['cambiar_modulos', 'guardar_modulos', 'atender_solicitud'] : []),
                'tipos_solicitud' => $GLOBALS['conModulos'] ? ['cambio_modulos'] : [],
                'modulos' => ['editable' => true, 'por_instalacion' => true, 'cambia_plan' => true, 'precio_acordado' => true], 'estados_detalle' => [], 'periodos' => [], 'meses_libres' => false,
                'metodos_pago' => [], 'planes_editar' => false, 'filtros_clientes' => [], 'formularios' => ['crear_demo' => []],
                'limites' => ['por_pagina_max' => 1, 'meses_finanzas_max' => 1, 'dias_prueba_max' => 1]];
    }
    $completo = in_array($producto, ['tuvocalia', 'tuacademia'], true);
    $ejecutar = ['crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'suspender', 'reactivar', 'cambiar_plan', 'nueva_clave_dueno', 'nota_interna', 'atender_solicitud'];
    if ($completo) array_push($ejecutar, 'aprobar_comprobante', 'rechazar_comprobante', 'responder_ticket');
    if ($GLOBALS['conPausa']) array_push($ejecutar, 'pausar', 'borrar_cliente', 'restaurar_cliente');
    if ($GLOBALS['conModulos']) array_push($ejecutar, 'cambiar_modulos', 'guardar_modulos');
    $tipos = ['registro_nuevo', 'seguimiento_prueba', 'renovacion'];
    if ($completo) array_push($tipos, 'demo_pedida', 'comprobante', 'soporte');
    if ($producto === 'tuvocalia') $tipos[] = 'derechos_datos';
    if ($GLOBALS['conModulos']) $tipos[] = 'cambio_modulos';
    return [
        'revision' => 1, 'producto' => $producto, 'nombre' => $NOMBRES[$producto], 'version' => $producto === 'tuvocalia' ? '3f0b7aa' : '75df6b3', 'unico' => false,
        'moneda' => 'USD', 'zona_horaria' => 'America/Guayaquil', 'url_publica' => 'https://' . $producto . '.ejemplo.ec',
        'acciones' => array_merge(['capacidades', 'estado', 'sso', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar'], $GLOBALS['conModulos'] ? ['modulos'] : [], $GLOBALS['conIa'] ? ['ia_uso'] : []),
        'sso_ir' => true, 'rutas_sso' => ['/admin'], 'ejecutar' => $ejecutar, 'tipos_solicitud' => $tipos,
        'modulos' => ['editable' => true, 'por_instalacion' => false, 'cambia_plan' => true, 'precio_acordado' => true, 'crear_demo' => true],
        'estados_detalle' => ['prueba' => 'Demo', 'activa' => 'Activa', 'sin_limite' => 'Sin vencimiento', 'gracia' => 'En gracia', 'vencida' => 'Vencida', 'prueba_vencida' => 'Demo terminada', 'suspendida' => 'Suspendida'],
        'periodos' => [['periodo' => 'mensual', 'meses' => 1], ['periodo' => 'semestral', 'meses' => 6], ['periodo' => 'anual', 'meses' => 12]],
        'meses_libres' => $completo,
        'metodos_pago' => array_map(fn($k, $v) => ['metodo' => $k, 'nombre' => $v], array_keys(metodos()), metodos()),
        'planes_editar' => !$completo,
        'filtros_clientes' => ['buscar', 'estados', 'plan', 'vence_desde', 'vence_hasta', 'origen'],
        'formularios' => ['crear_demo' => $producto === 'tuvocalia'
            ? [['campo' => 'tipo', 'etiqueta' => 'Tipo de cuenta', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'liga', 'opciones' => [['valor' => 'liga', 'etiqueta' => 'Liga (campeonatos)'], ['valor' => 'escuela', 'etiqueta' => 'Escuela deportiva']]],
               ['campo' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'requerido' => false, 'max' => 80]]
            : [['campo' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'requerido' => false, 'max' => 80]]],
        'limites' => ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => 90],
    ];
}

function accion_clientes(array $db, array $d): array
{
    $pagina = max(1, (int) ($d['pagina'] ?? 1));
    $porPagina = (int) ($d['por_pagina'] ?? 50);
    if ($porPagina < 1 || $porPagina > 200) fallo('invalido', 'por_pagina debe ser de 1 a 200.', 422, 'por_pagina');
    $todos = array_map('cliente_resumen', array_values(vivos($db)));
    $cont = ['total' => count($todos), 'prueba' => 0, 'activo' => 0, 'por_vencer' => 0, 'vencido' => 0, 'suspendido' => 0];
    foreach ($todos as $c) $cont[$c['estado']]++;
    // Papelera (Anexo A.1): se acepta "estado", "filtros.estado" o "estados" con "papelera"
    $pap = ($d['estado'] ?? null) === 'papelera' || ($d['filtros']['estado'] ?? null) === 'papelera' || in_array('papelera', (array) ($d['estados'] ?? []), true);
    registrar('clientes.log', $pap ? 'papelera' : 'normal');
    if ($pap) {
        $d['estados'] = [];
        $todos = $GLOBALS['conPausa'] ? array_map('cliente_resumen', array_values(array_filter($db['clientes'], fn($c) => !empty($c['papelera'])))) : $todos;
    }
    $cont['papelera'] = count(array_filter($db['clientes'], fn($c) => !empty($c['papelera'])));
    $l = $todos;
    if (!empty($d['ids'])) $l = array_values(array_filter($l, fn($c) => in_array($c['id'], $d['ids'], true)));
    if (!empty($d['estados'])) $l = array_values(array_filter($l, fn($c) => in_array($c['estado'], $d['estados'], true)));
    if (($d['buscar'] ?? '') !== '') $l = array_values(array_filter($l, fn($c) => str_contains(mb_strtolower($c['nombre']), mb_strtolower($d['buscar']))));
    usort($l, fn($a, $b) => [$a['fechas']['vence'] === null, $a['fechas']['vence']] <=> [$b['fechas']['vence'] === null, $b['fechas']['vence']]);
    $total = count($l);
    return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int) ceil($total / $porPagina)),
            'contadores' => $cont, 'clientes' => array_slice($l, ($pagina - 1) * $porPagina, $porPagina)];
}

function vigencia(array $c, int $meses): array
{
    [$estado] = estado_de($c);
    $desde = !$c['es_prueba'] && $c['vence'] !== null && $c['vence'] >= hoy() ? $c['vence'] : hoy();
    return [$desde, mas_meses($desde, $meses)];
}

function cotizacion(array $c, array $d): array
{
    $plan = (string) ($d['plan'] ?? $c['plan']);
    if (!isset(planes_lista()[$plan])) fallo('invalido', 'Ese plan no existe.', 422, 'datos.plan');
    if (isset($d['meses']) && !isset($d['periodo'])) {
        $m = $d['meses'];
        if (!is_int($m) || $m < 1 || $m > 36) fallo('invalido', 'Elija de 1 a 36 meses.', 422, 'datos.meses');
        $monto = round(planes_lista()[$plan]['mensual'] * $m, 2);
        $periodo = $m === 1 ? 'mensual' : "$m meses";
        $meses = $m;
    } else {
        $periodo = (string) ($d['periodo'] ?? 'mensual');
        if (!isset(periodos()[$periodo])) fallo('invalido', 'Ese tiempo no existe.', 422, 'datos.periodo');
        $meses = periodos()[$periodo]['meses'];
        $monto = precio($plan, $periodo);
    }
    [$desde, $hasta] = vigencia($c, $meses);
    return ['plan' => $plan, 'periodo' => $periodo, 'monto' => $monto, 'meses' => $meses, 'desde' => $desde, 'hasta' => $hasta,
            'detalle' => planes_lista()[$plan]['nombre'] . " $periodo"];
}

function accion_cotizar(array $db, array $d): array
{
    $c = cliente_de($db, (string) ($d['cliente_id'] ?? ''));
    $q = cotizacion($c, $d);
    return ['monto' => $q['monto'], 'meses' => $q['meses'], 'desde' => $q['desde'], 'hasta' => $q['hasta'], 'detalle' => $q['detalle'], 'incluye_dominio' => false, 'descuentos' => []];
}

/** Anexo C: uso de IA del mes (solo lectura). Cifras fijas por cliente para poder revisarlas en las pruebas. */
function accion_ia_uso(array $db, array $d): array
{
    global $unico;
    $mes = is_string($d['mes'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $d['mes']) ? $d['mes'] : date('Y-m');
    if (array_diff(array_keys($d), ['mes'])) fallo('invalido', 'Campo desconocido.', 422, array_values(array_diff(array_keys($d), ['mes']))[0]);
    registrar('ia_uso.log', $mes);
    $neg = [];
    if ($mes === date('Y-m')) {
        $lista = $unico ? ['unico' => $db['unico']] : array_slice($db['clientes'], 0, 3, true);
        $n = 0;
        foreach ($lista as $cid => $c) {
            $n++;
            $neg[] = ['cliente_id' => (string) $cid, 'nombre' => (string) $c['nombre'], 'costo' => round(0.0123 * $n, 6), 'llamadas' => 10 * $n, 'fallidas' => $n === 2 ? 1 : 0,
                      'ahorradas' => 25 * $n, 'ayudas_nuevas' => 3 * $n, 'tope' => $n === 1 ? 0.0123 : ($n === 2 ? 3.0 : null), 'modulo' => $n !== 3];
        }
    }
    $tot = ['costo' => round(array_sum(array_column($neg, 'costo')), 6), 'llamadas' => array_sum(array_column($neg, 'llamadas')), 'fallidas' => array_sum(array_column($neg, 'fallidas')),
            'ahorradas' => array_sum(array_column($neg, 'ahorradas')), 'ayudas_nuevas' => array_sum(array_column($neg, 'ayudas_nuevas')), 'tokens_entrada' => 1200, 'tokens_salida' => 300];
    return ['mes' => $mes, 'moneda' => 'USD', 'modulo' => 'asistente_ia', 'version_libreria' => '1', 'configurada' => true,
            'pausada' => getenv('MOCK_IA_PAUSADA') === '1', 'modelo_rapido' => 'claude-haiku-5-5', 'modelo_fuerte' => 'claude-sonnet-5-5',
            'tope_programa' => getenv('MOCK_IA_TOPE') !== false && getenv('MOCK_IA_TOPE') !== '' ? (float) getenv('MOCK_IA_TOPE') : null,
            'totales' => $tot, 'faq' => ['total' => 12, 'automaticas' => 9, 'manuales' => 3, 'sin_aprobar' => 4, 'usos' => 80], 'negocios' => $neg];
}

function accion_planes(array $db): array
{
    $r = [];
    foreach (planes_lista() as $p) {
        $precios = [];
        foreach (periodos() as $k => $x) $precios[] = ['periodo' => $k, 'meses' => $x['meses'], 'precio' => precio($p['codigo'], $k), 'meses_gratis' => $x['meses'] - $x['paga']];
        $r[] = ['codigo' => $p['codigo'], 'nombre' => $p['nombre'], 'activo' => true, 'para' => 'Todos', 'descripcion' => '', 'precios' => $precios, 'limites' => [],
                'clientes' => count(array_filter(vivos($db), fn($c) => $c['plan'] === $p['codigo']))];
    }
    return ['editable' => capacidades()['planes_editar'], 'planes' => $r, 'ajustes' => ['dias_prueba' => 7, 'dias_demo' => 7, 'dias_gracia' => 3, 'dias_gracia_demo' => 0, 'registro_abierto' => true]];
}

/** Todas las solicitudes (guardadas + recordatorios armados al momento), con la forma de §5.8. */
function todas_solicitudes(array $db): array
{
    global $NOMBRES, $producto;
    $tipos = capacidades()['tipos_solicitud'] ?? [];
    $r = [];
    $prog = $NOMBRES[$producto];
    $wa = fn(?string $t) => $t ? '593' . substr(preg_replace('/\D+/', '', $t), 1) : null;
    $quienDe = fn(array $c) => ['nombre' => $c['dueno']['nombre'], 'organizacion' => $c['nombre'], 'telefono' => $c['dueno']['telefono'], 'whatsapp' => $wa($c['dueno']['telefono']), 'email' => $c['dueno']['email']];
    $atendida = fn(string $sid) => $db['atenciones'][$sid]['estado'] ?? null;
    foreach ($db['solicitudes'] as $sid => $s) {
        if (!in_array($s['tipo'], $tipos, true)) continue;
        $c = $s['cliente_id'] !== null ? ($db['clientes'][$s['cliente_id']] ?? null) : null;
        if ($s['tipo'] === 'cambio_modulos') {
            $r[] = mod_solicitud($db, $sid, $s);
            continue;
        }
        if ($s['tipo'] === 'demo_pedida') {
            $estado = ['nueva' => 'pendiente', 'contactada' => 'en_proceso', 'creada' => 'atendida', 'descartada' => 'descartada'][$s['estado']];
            $q = $s['quien'] + ['whatsapp' => $wa($s['quien']['telefono'])];
            $acc = [
                ['accion' => 'crear_demo', 'etiqueta' => 'Crear demo', 'estilo' => 'principal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid],
                 'campos' => [['campo' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'texto', 'valor' => $q['organizacion']], ['campo' => 'contacto_nombre', 'etiqueta' => 'Contacto', 'tipo' => 'texto', 'valor' => $q['nombre']],
                              ['campo' => 'email', 'etiqueta' => 'Correo', 'tipo' => 'email', 'valor' => $q['email']], ['campo' => 'telefono', 'etiqueta' => 'Celular', 'tipo' => 'telefono', 'valor' => $q['telefono']],
                              ['campo' => 'tipo', 'etiqueta' => 'Tipo', 'tipo' => 'texto', 'valor' => $s['detalle']['tipo']], ['campo' => 'ciudad', 'etiqueta' => 'Ciudad', 'tipo' => 'texto', 'valor' => $s['detalle']['ciudad']]]],
                ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'whatsapp' => $q['whatsapp'], 'texto' => 'Hola ' . explode(' ', $q['nombre'])[0] . ", gracias por pedir la demo de $prog. Le cuento cómo funciona…"],
                ['accion' => 'atender_solicitud', 'etiqueta' => 'Ya lo contacté', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid, 'resultado' => 'en_proceso'],
                 'campos' => [['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]],
                ['accion' => 'atender_solicitud', 'etiqueta' => 'Descartar', 'estilo' => 'peligro', 'confirmar' => '¿Descartar este pedido?', 'datos' => ['solicitud_id' => $sid, 'resultado' => 'descartada'],
                 'campos' => [['campo' => 'nota', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]],
            ];
            $r[] = ['id' => $sid, 'tipo' => 'demo_pedida', 'categoria' => 'pedido', 'titulo' => 'Pedido de demo', 'fecha' => $s['fecha'], 'actualizado' => $s['fecha'],
                    'cliente_id' => null, 'quien' => $q, 'resumen' => ucfirst($s['detalle']['tipo']) . ' · ' . $s['detalle']['ciudad'] . ' · ' . $s['detalle']['tamano'],
                    'monto' => null, 'estado' => $estado, 'estado_producto' => $s['estado'], 'prioridad' => 'normal', 'vence_el' => null, 'detalle' => $s['detalle'] + ['nota' => $s['nota']],
                    'adjuntos' => [], 'acciones_posibles' => in_array($estado, ['pendiente', 'en_proceso'], true) ? $acc : [], 'ir' => '/admin?demos'];
        } elseif ($s['tipo'] === 'comprobante' && $c) {
            $estado = ['pendiente' => 'pendiente', 'aprobada' => 'atendida', 'rechazada' => 'descartada'][$s['estado']];
            $plan = planes_lista()[$s['plan']];
            $q = $quienDe($c);
            $r[] = ['id' => $sid, 'tipo' => 'comprobante', 'categoria' => 'pedido', 'titulo' => 'Comprobante de pago por revisar', 'fecha' => $s['fecha'], 'actualizado' => $s['fecha'],
                    'cliente_id' => $c['id'], 'quien' => $q, 'resumen' => "Plan {$plan['nombre']} · {$s['meses']} meses · $" . number_format($s['monto'], 2, ',', '.') . ' · Transferencia',
                    'monto' => $s['monto'], 'estado' => $estado, 'estado_producto' => $s['estado'], 'prioridad' => 'alta', 'vence_el' => null,
                    'detalle' => ['plan' => ['codigo' => $plan['codigo'], 'nombre' => $plan['nombre']], 'meses' => $s['meses'], 'forma_pago' => $s['forma_pago'], 'nota' => $s['nota'],
                                  'enviado_por' => "{$c['dueno']['nombre']} <{$c['dueno']['email']}>", 'cambia_plan' => $s['plan'] !== $c['plan'],
                                  'plan_actual' => ['codigo' => $c['plan'], 'nombre' => planes_lista()[$c['plan']]['nombre']], 'respuesta' => $s['respuesta']],
                    'adjuntos' => [['id' => '1', 'nombre' => 'comprobante_45.png', 'tipo_mime' => 'image/png']],
                    'acciones_posibles' => $estado === 'pendiente' ? [
                        ['accion' => 'aprobar_comprobante', 'etiqueta' => 'Aprobar y renovar', 'estilo' => 'principal', 'confirmar' => "¿Aprobar y renovar {$c['nombre']} por {$s['meses']} meses?", 'datos' => ['solicitud_id' => $sid],
                         'campos' => [['campo' => 'monto', 'etiqueta' => 'Valor recibido ($)', 'tipo' => 'dinero', 'requerido' => true, 'valor' => $s['monto'], 'min' => 0],
                                      ['campo' => 'nota', 'etiqueta' => 'Nota', 'tipo' => 'texto', 'requerido' => false, 'max' => 300]]],
                        ['accion' => 'rechazar_comprobante', 'etiqueta' => 'Rechazar', 'estilo' => 'peligro', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid],
                         'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo (lo verá el cliente)', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 300]]],
                        ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'whatsapp' => $q['whatsapp'], 'texto' => 'Hola ' . explode(' ', $q['nombre'])[0] . ', recibimos su comprobante. En un momento lo revisamos.'],
                        ['accion' => 'abrir', 'etiqueta' => "Ver en $prog", 'ir' => '/admin?suscripciones#comprobantes'],
                    ] : [], 'ir' => '/admin?suscripciones#comprobantes'];
        } elseif ($s['tipo'] === 'soporte' && $c) {
            $estado = $s['sin_leer'] ? 'pendiente' : ['nuevo' => 'pendiente', 'en_proceso' => 'en_proceso', 'esperando' => 'en_proceso', 'resuelto' => 'atendida', 'cerrado' => 'atendida'][$s['estado']];
            $q = $quienDe($c);
            $r[] = ['id' => $sid, 'tipo' => 'soporte', 'categoria' => 'pedido', 'titulo' => 'Ticket: ' . $s['asunto'], 'fecha' => $s['fecha'], 'actualizado' => end($s['mensajes'])['momento'],
                    'cliente_id' => $c['id'], 'quien' => $q, 'resumen' => mb_substr(end($s['mensajes'])['texto'], 0, 120), 'monto' => null, 'estado' => $estado, 'estado_producto' => $s['estado'],
                    'prioridad' => $s['prioridad'], 'vence_el' => null, 'detalle' => ['subtipo' => $s['subtipo'], 'asunto' => $s['asunto'], 'mensajes' => $s['mensajes']],
                    'adjuntos' => [['id' => '31', 'nombre' => 'captura.png', 'tipo_mime' => 'image/png']],
                    'acciones_posibles' => in_array($estado, ['pendiente', 'en_proceso'], true) ? [
                        ['accion' => 'responder_ticket', 'etiqueta' => 'Responder', 'estilo' => 'principal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid],
                         'campos' => [['campo' => 'texto', 'etiqueta' => 'Respuesta', 'tipo' => 'texto_largo', 'requerido' => true, 'min' => 1, 'max' => 6000],
                                      ['campo' => 'estado', 'etiqueta' => 'Queda', 'tipo' => 'opcion', 'requerido' => true, 'valor' => 'esperando', 'opciones' => [['valor' => 'en_proceso', 'etiqueta' => 'En proceso'], ['valor' => 'esperando', 'etiqueta' => 'Esperando al cliente'], ['valor' => 'resuelto', 'etiqueta' => 'Resuelto']]]]],
                        ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar resuelto', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid, 'resultado' => 'atendida'], 'campos' => []],
                        ['accion' => 'abrir', 'etiqueta' => "Ver en $prog", 'ir' => '/admin?soporte=9'],
                    ] : [], 'ir' => '/admin?soporte=9'];
        } elseif ($s['tipo'] === 'derechos_datos') {
            $estado = ['nueva' => 'pendiente', 'en_proceso' => 'en_proceso', 'respondida' => 'atendida', 'rechazada' => 'descartada'][$s['estado']];
            $r[] = ['id' => $sid, 'tipo' => 'derechos_datos', 'categoria' => 'pedido', 'titulo' => 'Pedido de datos personales (LOPDP)', 'fecha' => $s['fecha'], 'actualizado' => $s['fecha'],
                    'cliente_id' => null, 'quien' => $s['quien'] + ['whatsapp' => null], 'resumen' => 'Derecho de ' . $s['derecho'] . ': ' . $s['mensaje'], 'monto' => null, 'estado' => $estado,
                    'estado_producto' => $s['estado'], 'prioridad' => 'normal', 'vence_el' => date('Y-m-d', strtotime($s['fecha'] . ' +15 days')),
                    'detalle' => ['derecho' => $s['derecho'], 'mensaje' => $s['mensaje'], 'respuesta' => $s['respuesta']], 'adjuntos' => [],
                    'acciones_posibles' => in_array($estado, ['pendiente', 'en_proceso'], true) ? [
                        ['accion' => 'atender_solicitud', 'etiqueta' => 'Responder', 'estilo' => 'principal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid, 'resultado' => 'atendida'],
                         'campos' => [['campo' => 'respuesta', 'etiqueta' => 'Respuesta (se le envía por correo)', 'tipo' => 'texto_largo', 'requerido' => true, 'min' => 5, 'max' => 3000]]],
                    ] : [], 'ir' => '/admin?datos'];
        }
    }
    // Recordatorios y registros (se arman al momento)
    foreach (vivos($db) as $c) {
        [$estado, , , , $dias] = estado_de($c);
        $pv = pagos_validos($c);
        $q = $quienDe($c);
        $wa1 = ['accion' => 'whatsapp', 'etiqueta' => 'WhatsApp', 'whatsapp' => $q['whatsapp'], 'texto' => 'Hola ' . explode(' ', $c['dueno']['nombre'])[0] . ", ¿cómo le va con $prog?"];
        $pago = ['accion' => 'registrar_pago', 'etiqueta' => 'Registrar pago', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['cliente_id' => $c['id']],
                 'campos' => acciones_cliente($c, cliente_resumen($c))[0]['campos'] ?? []];
        $mk = function (string $sid, string $tipo, string $cat, string $titulo, string $resumen, array $detalle, array $acc) use ($c, $q, $atendida, &$r) {
            $at = $atendida($sid);
            $estado = $at ?? 'pendiente';
            $r[] = ['id' => $sid, 'tipo' => $tipo, 'categoria' => $cat, 'titulo' => $titulo, 'fecha' => ($tipo === 'registro_nuevo' ? $c['alta'] : hoy()) . 'T08:00:00-05:00', 'actualizado' => date('c'),
                    'cliente_id' => $c['id'], 'quien' => $q, 'resumen' => $resumen, 'monto' => null, 'estado' => $estado, 'estado_producto' => $at ?? 'pendiente',
                    'prioridad' => 'normal', 'vence_el' => null, 'detalle' => $detalle, 'adjuntos' => [],
                    'acciones_posibles' => in_array($estado, ['pendiente', 'en_proceso'], true) ? array_merge($acc, [
                        ['accion' => 'atender_solicitud', 'etiqueta' => 'Marcar atendida', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['solicitud_id' => $sid, 'resultado' => 'atendida'], 'campos' => []],
                    ]) : [], 'ir' => '/admin?cliente=' . $c['id']];
        };
        if (in_array('registro_nuevo', $tipos, true) && $c['origen'] === 'registro' && dias_entre($c['alta'], hoy()) <= 30) {
            $mk('registro_nuevo:' . $c['id'], 'registro_nuevo', 'pedido', 'Se registró solo', 'Prueba hasta el ' . ($c['vence'] ?? '—') . ' · ' . $c['ciudad'], ['ciudad' => $c['ciudad']], [$wa1]);
        }
        if (in_array('seguimiento_prueba', $tipos, true) && $c['es_prueba'] && $dias !== null) {
            $etapa = $dias === 2 ? 'faltan_2' : ($dias === 0 ? 'vence_hoy' : ($dias < 0 && $dias >= -60 ? 'terminada' : null));
            if ($etapa) {
                $ext = ['accion' => 'extender_prueba', 'etiqueta' => 'Alargar 7 días', 'estilo' => 'normal', 'confirmar' => null, 'datos' => ['cliente_id' => $c['id']],
                        'campos' => [['campo' => 'dias', 'etiqueta' => 'Días más', 'tipo' => 'dias', 'requerido' => true, 'valor' => 7, 'min' => 1, 'max' => 90]]];
                $mk("seguimiento_prueba:{$c['id']}:$etapa", 'seguimiento_prueba', 'recordatorio', $etapa === 'terminada' ? 'Prueba terminada sin pagar' : 'Prueba por terminar',
                    $etapa === 'terminada' ? 'Terminó el ' . $c['vence'] : "Termina el {$c['vence']}", ['etapa' => $etapa], [$wa1, $ext, $pago]);
            }
        }
        if (in_array('renovacion', $tipos, true) && !$c['es_prueba'] && $pv && $dias !== null && !$c['suspendido']) {
            $etapa = $dias >= 0 && $dias <= 5 ? 'vence_pronto' : ($dias < 0 && $dias >= -30 ? 'vencida' : null);
            if ($etapa) $mk("renovacion:{$c['id']}:$etapa", 'renovacion', 'recordatorio', $etapa === 'vencida' ? 'Plan vencido sin renovar' : 'Plan por vencer',
                            ($etapa === 'vencida' ? 'Venció el ' : 'Vence el ') . $c['vence'], ['etapa' => $etapa], [$wa1, $pago]);
        }
    }
    return $r;
}

function accion_solicitudes(array $db, array $d): array
{
    $estado = (string) ($d['estado'] ?? 'abiertas');
    if (!in_array($estado, ['abiertas', 'cerradas', 'todas'], true)) fallo('invalido', 'estado debe ser abiertas, cerradas o todas.', 422, 'estado');
    $l = array_values(array_filter(todas_solicitudes($db), fn($s) => $estado === 'todas' || ($estado === 'abiertas') === in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
    if (!empty($d['tipos'])) $l = array_values(array_filter($l, fn($s) => in_array($s['tipo'], $d['tipos'], true)));
    if (!empty($d['cliente_id'])) $l = array_values(array_filter($l, fn($s) => $s['cliente_id'] === $d['cliente_id']));
    $pagina = max(1, (int) ($d['pagina'] ?? 1));
    $por = max(1, min(200, (int) ($d['por_pagina'] ?? 100)));
    $cont = [];
    foreach ($l as $s) $cont[$s['tipo']] = ($cont[$s['tipo']] ?? 0) + 1;
    return ['total' => count($l), 'pagina' => $pagina, 'paginas' => max(1, (int) ceil(count($l) / $por)), 'contadores' => $cont, 'solicitudes' => array_slice($l, ($pagina - 1) * $por, $por)];
}

function solicitud_de(array $db, string $sid, bool $abiertaONo = true): array
{
    foreach (todas_solicitudes($db) as $s) if ($s['id'] === $sid) return $s;
    fallo('no_encontrado', 'Esa solicitud no existe.', 404);
}

function imagen_png(string $texto): string
{
    if (!function_exists('imagecreatetruecolor')) return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $im = imagecreatetruecolor(360, 220);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 244, 250));
    imagefilledrectangle($im, 0, 0, 360, 40, imagecolorallocate($im, 20, 33, 61));
    imagestring($im, 5, 12, 12, 'Banco Pichincha', imagecolorallocate($im, 255, 255, 255));
    imagestring($im, 5, 12, 70, $texto, imagecolorallocate($im, 20, 33, 61));
    imagestring($im, 4, 12, 110, 'Transferencia exitosa', imagecolorallocate($im, 31, 138, 76));
    imagestring($im, 3, 12, 150, 'Ref. 12345 - ' . hoy(), imagecolorallocate($im, 74, 84, 112));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
}

function accion_adjunto(array $db, array $d): array
{
    $sid = (string) ($d['solicitud_id'] ?? '');
    $aid = (string) ($d['adjunto_id'] ?? '');
    if (str_contains($aid, '..') || str_contains($aid, '/')) fallo('no_encontrado', 'Ese archivo no existe.', 404);
    $s = solicitud_de($db, $sid);
    if (!in_array($aid, array_column($s['adjuntos'], 'id'), true)) fallo('no_encontrado', 'Ese archivo no es de esta solicitud.', 404);
    $png = imagen_png($s['tipo'] === 'comprobante' ? 'Valor: $' . number_format((float) $s['monto'], 2) : 'Captura del error');
    return ['nombre' => $s['adjuntos'][0]['nombre'], 'tipo_mime' => 'image/png', 'tamano' => strlen($png), 'base64' => base64_encode($png)];
}

function accion_finanzas(array $db, array $d): array
{
    global $producto;
    $hasta = (string) ($d['hasta'] ?? date('Y-m', $GLOBALS['ahora']));
    $desde = (string) ($d['desde'] ?? date('Y-m', strtotime(hoy() . ' -11 months')));
    foreach (['desde' => $desde, 'hasta' => $hasta] as $k => $v) if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) fallo('invalido', "$k debe ser un mes (2026-10).", 422, $k);
    $meses = [];
    for ($m = new DateTimeImmutable("$desde-01"); $m->format('Y-m') <= $hasta; $m = $m->modify('+1 month')) $meses[] = $m->format('Y-m');
    if (count($meses) > 24 || !$meses) fallo('invalido', 'Máximo 24 meses.', 422, 'desde');
    $primeros = [];
    foreach (vivos($db) as $c) if ($pv = pagos_validos($c)) $primeros[$c['id']] = substr($pv[0]['fecha'], 0, 7);
    $salida = [];
    $tot = ['ingresos' => 0.0, 'otros_ingresos' => 0.0, 'costos' => 0.0, 'pagos' => 0];
    foreach ($meses as $mes) {
        $ing = 0.0; $n = 0; $quienes = []; $plan = []; $per = []; $met = [];
        foreach (vivos($db) as $c) {
            foreach (pagos_validos($c) as $pg) {
                if (substr($pg['fecha'], 0, 7) !== $mes) continue;
                $ing += $pg['monto']; $n++; $quienes[$c['id']] = true;
                $plan[$pg['plan']] = ['plan' => $pg['plan'], 'nombre' => planes_lista()[$pg['plan']]['nombre'], 'monto' => ($plan[$pg['plan']]['monto'] ?? 0) + $pg['monto'], 'pagos' => ($plan[$pg['plan']]['pagos'] ?? 0) + 1];
                $per[$pg['periodo']] = ['periodo' => $pg['periodo'], 'meses' => $pg['meses'], 'monto' => ($per[$pg['periodo']]['monto'] ?? 0) + $pg['monto'], 'pagos' => ($per[$pg['periodo']]['pagos'] ?? 0) + 1];
                $met[$pg['metodo']] = ['metodo' => $pg['metodo'], 'nombre' => metodos()[$pg['metodo']] ?? $pg['metodo'], 'monto' => ($met[$pg['metodo']]['monto'] ?? 0) + $pg['monto'], 'pagos' => ($met[$pg['metodo']]['pagos'] ?? 0) + 1];
            }
        }
        $tarjeta = $met['tarjeta']['monto'] ?? 0;
        $costos = $tarjeta > 0 ? [['concepto' => 'comision_pasarela', 'categoria' => 'comisiones_pasarela', 'nombre' => 'Comisión de Plux en suscripciones', 'monto' => round($tarjeta * 0.045, 2), 'estimado' => false]] : [];
        $otros = $producto === 'tuvocalia' && $ing > 0 ? [['concepto' => 'gestion_cobros', 'nombre' => 'Gestión de cobros con tarjeta (1 %)', 'monto' => 4.1]] : [];
        $salida[] = ['mes' => $mes, 'ingresos' => round($ing, 2), 'pagos' => $n, 'clientes_que_pagaron' => count($quienes),
                     'nuevos_pagadores' => count(array_filter($primeros, fn($m) => $m === $mes)), 'devengado' => null,
                     'por_plan' => array_values($plan), 'por_periodo' => array_values($per), 'por_metodo' => array_values($met), 'otros_ingresos' => $otros, 'costos' => $costos];
        $tot['ingresos'] += $ing; $tot['pagos'] += $n; $tot['otros_ingresos'] += array_sum(array_column($otros, 'monto')); $tot['costos'] += array_sum(array_column($costos, 'monto'));
    }
    $mrr = 0.0;
    foreach (vivos($db) as $c) $mrr += cliente_resumen($c)['mensual_estimado'];
    return ['moneda' => 'USD', 'criterio' => 'Pagos de suscripción no anulados, por fecha de pago.', 'mrr' => round($mrr, 2), 'meses' => $salida,
            'totales' => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $tot)];
}

function estado_v2(array $db): array
{
    global $producto, $NOMBRES, $unico, $dir;
    $abiertas = array_values(array_filter(todas_solicitudes($db), fn($s) => in_array($s['estado'], ['pendiente', 'en_proceso'], true)));
    $h = array_map(fn($s) => $s['id'] . ':' . $s['estado'], $abiertas);
    sort($h);
    $comun = ['producto' => $producto, 'nombre' => $NOMBRES[$producto], 'protocolo_max' => 2, 'solicitudes_abiertas' => count($abiertas),
              'solicitudes_huella' => substr(hash('sha256', implode("\n", $h)), 0, 16)];
    if ($unico) {
        return $comun + ['version' => '57ed3ce', 'cliente' => $db['unico']['nombre'], 'negocios' => null, 'nuevos_30d' => null, 'ingresos_mes' => null, 'ingresos_mes_anterior' => null,
                         'pruebas_vencen_7d' => [], 'pagos_vencidos' => [], 'extra' => ['Mascotas activas' => 86, 'Reservas este mes' => 142, 'Usuarios' => 6]];
    }
    $n = ['total' => 0, 'prueba' => 0, 'activos' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'suspendidos' => 0];
    $plural = ['prueba' => 'prueba', 'activo' => 'activos', 'por_vencer' => 'por_vencer', 'vencido' => 'vencidos', 'suspendido' => 'suspendidos'];
    $pruebas = [];
    $vencidos = [];
    $mes = date('Y-m', $GLOBALS['ahora']);
    $anterior = date('Y-m', strtotime(date('Y-m-01', $GLOBALS['ahora']) . ' -1 month'));
    $ing = [$mes => 0.0, $anterior => 0.0];
    $nuevos = 0;
    foreach (vivos($db) as $c) {
        $r = cliente_resumen($c);
        $n['total']++;
        $n[$plural[$r['estado']]]++;
        if ($r['estado'] === 'prueba' && $r['fechas']['dias_para_vencer'] <= 7) $pruebas[] = ['nombre' => $c['nombre'], 'vence' => $c['vence']];
        if ($r['estado'] === 'vencido' && $r['pagos']['cantidad'] > 0) $vencidos[] = ['nombre' => $c['nombre'], 'desde' => $c['vence']];
        if (dias_entre($c['alta'], hoy()) <= 30) $nuevos++;
        foreach (pagos_validos($c) as $pg) if (isset($ing[substr($pg['fecha'], 0, 7)])) $ing[substr($pg['fecha'], 0, 7)] += $pg['monto'];
    }
    if (is_file("$dir/total")) $n['total'] = (int) trim((string) file_get_contents("$dir/total"));
    return $comun + ['version' => $producto === 'tuvocalia' ? '3f0b7aa' : '75df6b3', 'negocios' => $n, 'nuevos_30d' => $nuevos,
                     'ingresos_mes' => round($ing[$mes], 2), 'ingresos_mes_anterior' => round($ing[$anterior], 2),
                     'pruebas_vencen_7d' => $pruebas, 'pagos_vencidos' => $vencidos, 'extra' => ['Usuarios' => 40]];
}

// =====================================================================
// ejecutar (§6) con idempotencia (§7.1)
// =====================================================================

function texto_campo(array $d, string $k, int $min, int $max, string $error): string
{
    $v = $d[$k] ?? null;
    if (!is_string($v) || mb_strlen(trim($v)) < $min || mb_strlen($v) > $max) fallo('invalido', $error, 422, "datos.$k");
    return trim($v);
}

function anotar(array &$db, string $cid, array $quien, string $texto, string $op): void
{
    $db['registro'][] = ['cliente_id' => $cid, 'momento' => date('c'), 'quien' => "Panel Tukán Central · {$quien['nombre']} <{$quien['email']}>", 'texto' => $texto . ' (op ' . substr($op, 0, 8) . ')'];
    registrar('bitacora.log', "{$quien['email']}|$cid|$texto|$op");
}

function accion_ejecutar(array &$db, array $d, array $quien, string $bh): array
{
    global $dir, $unico;
    $op = $d['operacion_id'] ?? null;
    if (!is_string($op) || !preg_match('/^[0-9a-f]{32}$/', $op)) fallo('invalido', 'Falta operacion_id (32 hex).', 422, 'operacion_id');
    $accion = $d['accion'] ?? null;
    if (!is_string($accion) || !in_array($accion, capacidades()['ejecutar'], true)) fallo('no_permitido', 'Esa operación no está disponible.', 403, 'accion', ['motivo' => 'no_disponible']);
    if (is_file("$dir/en_curso") && ($n = (int) trim((string) file_get_contents("$dir/en_curso"))) > 0) {
        file_put_contents("$dir/en_curso", (string) ($n - 1));
        registrar('ejecutar.log', "$op|$accion|en_curso");
        fallo('conflicto', 'La operación todavía está en curso.', 409, null, ['motivo' => 'en_curso']);
    }
    if (isset($db['operaciones'][$op])) {
        $o = $db['operaciones'][$op];
        if ($o['bh'] !== $bh) fallo('conflicto', 'Esa operación ya se usó con otros datos.', 409);
        registrar('ejecutar.log', "$op|$accion|repetida");
        $r = $o['respuesta'];
        if ($o['estado'] === 'error') { $f = new Fallo($r['codigo'], $r['error'], $o['http'], $r['campo'] ?? null, $r['detalle'] ?? []); throw $f; }
        return ['repetida' => true] + $r;
    }
    $datos = is_array($d['datos'] ?? null) ? $d['datos'] : [];
    $cid = isset($d['cliente_id']) ? (string) $d['cliente_id'] : null;
    try {
        $r = hacer($db, $accion, $cid, $datos, $quien, $op);
    } catch (Fallo $f) {
        if ($f->codigo !== 'interno') $db['operaciones'][$op] = ['accion' => $accion, 'bh' => $bh, 'estado' => 'error', 'http' => $f->http, 'respuesta' => error_json($f)];
        registrar('ejecutar.log', "$op|$accion|error {$f->codigo}");
        throw $f;
    }
    $r = ['operacion' => $accion, 'operacion_id' => $op, 'repetida' => false, 'sin_cambios' => $r['sin_cambios'] ?? false] + $r;
    // Lo guardado NUNCA lleva las claves de un solo uso
    $guardar = $r;
    $clave = $r['resultado']['acceso']['clave'] ?? $r['resultado']['clave'] ?? null;
    if ($clave !== null) {
        $guardar = json_decode(str_replace($clave, '', json_encode($guardar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
        if (isset($guardar['resultado']['acceso'])) $guardar['resultado']['acceso']['clave'] = null;
        if (array_key_exists('clave', $guardar['resultado'])) $guardar['resultado']['clave'] = null;
        $guardar['resultado']['clave_oculta'] = true;
        $guardar['resultado']['mensaje_whatsapp'] = null;
        $guardar['resultado']['whatsapp_url'] = null;
    }
    $db['operaciones'][$op] = ['accion' => $accion, 'bh' => $bh, 'estado' => 'ok', 'http' => 200, 'respuesta' => $guardar];
    registrar('ejecutar.log', "$op|$accion|ok");
    return $r;
}

function hacer(array &$db, string $accion, ?string $cid, array $d, array $quien, string $op): array
{
    global $unico, $NOMBRES, $producto;
    $sid = isset($d['solicitud_id']) ? (string) $d['solicitud_id'] : null;
    if (in_array($accion, ['cambiar_modulos', 'guardar_modulos'], true) || ($accion === 'atender_solicitud' && str_starts_with((string) $sid, 'cambio_modulos:'))) {
        return mod_hacer($db, $accion, $cid, $d, $quien, $op);
    }
    if ($unico && $accion === 'dar_de_baja') {
        $c = carpeta_mock();
        $conf = $d['confirmar'] ?? null;
        if (!is_string($conf) || trim($conf) === '') fallo('invalido', "Para confirmar escriba el nombre de la carpeta ($c).", 422, 'datos.confirmar');
        if (trim($conf) !== $c) fallo('invalido', "No coincide: la carpeta de esta instalación es $c. No se borró nada.", 422, 'datos.confirmar');
        $e = baja_de($db)['estado'];
        if ($e === 'programada') fallo('conflicto', 'Ya hay un borrado programado para esta instalación.', 409, null, ['motivo' => 'ya_programada']);
        if ($e === 'en_curso') fallo('conflicto', 'El borrado ya está en curso.', 409, null, ['motivo' => 'ya_empezo']);
        @unlink($GLOBALS['dir'] . '/baja_estado');
        $espera = (int) (getenv('MOCK_BAJA_ESPERA') !== false && getenv('MOCK_BAJA_ESPERA') !== '' ? getenv('MOCK_BAJA_ESPERA') : 300);
        $db['baja'] = ['desde' => $GLOBALS['ahora'] + $espera, 'quien' => $quien['email'], 'op' => $op];
        registrar('bitacora.log', "{$quien['email']}|unico|Borrado programado de $c|$op");
        registrar('bajas.log', "programada|$c|$op|{$quien['email']}|{$quien['rol']}");
        $dom = (string) (getenv('MOCK_DOMINIO') ?: '');
        return ['mensaje' => "Programada: la instalación $c se borrará " . ($espera < 60 ? 'en menos de 1 minuto' : 'en unos ' . round($espera / 60) . ' minutos') . '. Antes se guarda una copia final en /var/backups/borrados/',
                'resultado' => ['estado' => 'programada', 'carpeta' => $c, 'dominio' => $dom !== '' ? $dom : null, 'borrar_desde' => date('c', $GLOBALS['ahora'] + $espera),
                                'espera_segundos' => $espera, 'copia_en' => '/var/backups/borrados/', 'se_puede_cancelar' => $espera >= 60],
                'cliente' => cliente_detalle($db, ['unico' => true])];
    }
    if ($unico && $accion === 'cancelar_baja') {
        $e = baja_de($db)['estado'];
        if ($e === 'en_curso') fallo('conflicto', 'El borrado ya empezó: no se puede cancelar.', 409, null, ['motivo' => 'ya_empezo']);
        if ($e !== 'programada') return ['sin_cambios' => true, 'mensaje' => 'No había ningún borrado programado.', 'resultado' => ['estado' => null]];
        $db['baja'] = null;
        registrar('bajas.log', "cancelada|" . carpeta_mock() . "|$op|{$quien['email']}");
        return ['mensaje' => 'Borrado cancelado: la instalación sigue igual.', 'resultado' => ['estado' => 'cancelada', 'carpeta' => carpeta_mock()]];
    }
    if ($unico && in_array($accion, ['pausar', 'reactivar'], true)) {
        $antes = $db['unico']['pausa'] ?? null;
        if ($accion === 'reactivar') {
            if (!$antes) return ['sin_cambios' => true, 'mensaje' => 'No estaba pausada.', 'resultado' => ['estado' => 'activo'], 'cliente' => cliente_detalle($db, ['unico' => true])];
            $db['unico']['pausa'] = null;
            registrar('bitacora.log', "{$quien['email']}|unico|Instalación reactivada|$op");
            return ['mensaje' => 'Reactivada: vuelve a funcionar normal.', 'resultado' => ['estado' => 'activo'], 'cliente' => cliente_detalle($db, ['unico' => true])];
        }
        [$nueva, $sin] = pedir_pausa($antes, $d, $quien);
        $db['unico']['pausa'] = $nueva;
        if (!$sin) registrar('bitacora.log', "{$quien['email']}|unico|Instalación pausada ({$nueva['tipo']}): {$nueva['motivo']}|$op");
        return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'Ya estaba pausada con ese motivo.' : 'Pausada: el personal y los dueños ven «Servicio pausado».',
                'resultado' => ['estado' => 'pausado'] + pausa_publica($nueva), 'cliente' => cliente_detalle($db, ['unico' => true])];
    }
    if ($unico) {
        $clave = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
        registrar('bitacora.log', "{$quien['email']}|unico|Clave nueva del administrador|$op");
        return ['mensaje' => 'Contraseña nueva para el administrador principal.', 'resultado' => ['usuario' => $db['unico']['dueno']['email'], 'clave' => $clave,
                'mensaje_whatsapp' => "Hola, su nueva contraseña de {$NOMBRES[$producto]} es: $clave"], 'cliente' => cliente_detalle($db, ['unico' => true])];
    }
    if ($accion === 'crear_demo') return crear_demo($db, $d, $quien, $op);
    if (in_array($accion, ['aprobar_comprobante', 'rechazar_comprobante', 'atender_solicitud', 'responder_ticket'], true)) {
        if ($sid === null) fallo('invalido', 'Falta la solicitud.', 422, 'datos.solicitud_id');
        $s = solicitud_de($db, $sid);
        $cid = $s['cliente_id'];
    }
    if ($cid !== null && !isset($db['clientes'][$cid])) fallo('no_encontrado', 'Ese cliente no existe.', 404);
    $c = $cid !== null ? $db['clientes'][$cid] : null;
    if ($c === null && in_array($accion, ['registrar_pago', 'anular_pago', 'extender_prueba', 'suspender', 'reactivar', 'cambiar_plan', 'nueva_clave_dueno', 'nota_interna', 'pausar', 'borrar_cliente', 'restaurar_cliente'], true)) {
        fallo('invalido', 'Falta el cliente.', 422, 'cliente_id');
    }
    // En la papelera solo se puede restaurar (para lo demás "ya no existe")
    if ($c !== null && !empty($c['papelera']) && $accion !== 'restaurar_cliente' && $accion !== 'borrar_cliente') fallo('no_encontrado', 'Ese cliente está en la papelera.', 404);
    $sin = false;
    switch ($accion) {
        case 'registrar_pago':
            $q = cotizacion($c, $d);
            $monto = $d['monto'] ?? $q['monto'];
            if (!is_int($monto) && !is_float($monto)) fallo('invalido', 'Escriba el monto con números, por ejemplo 39,00.', 422, 'datos.monto');
            if ($monto < 0) fallo('invalido', 'El monto no puede ser negativo.', 422, 'datos.monto');
            $metodo = (string) ($d['metodo'] ?? '');
            if (!isset(metodos()[$metodo])) fallo('invalido', 'Elija la forma de pago.', 422, 'datos.metodo');
            $fecha = (string) ($d['fecha'] ?? hoy());
            if ($fecha > hoy() || $fecha < dia(-366)) fallo('invalido', 'La fecha del pago no puede ser futura ni de hace más de un año.', 422, 'datos.fecha');
            $pid = $cid . '9' . (string) $db['sig_pago']++;
            $c['pagos'][] = ['id' => $pid, 'fecha' => $fecha, 'monto' => round((float) $monto, 2), 'plan' => $q['plan'], 'periodo' => $q['periodo'], 'meses' => $q['meses'],
                             'desde' => $q['desde'], 'hasta' => $q['hasta'], 'metodo' => $metodo, 'nota' => (string) ($d['nota'] ?? ''), 'registrado_por' => 'Panel Tukán Central (' . $quien['nombre'] . ')', 'anulado' => false];
            $c['vence'] = $q['hasta'];
            $c['es_prueba'] = false;
            $c['plan'] = $q['plan'];
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, 'Pago registrado: $' . number_format((float) $monto, 2, ',', '.') . " hasta el {$q['hasta']}", $op);
            return ['mensaje' => "Pago registrado. {$c['nombre']} queda activa hasta el {$q['hasta']}.", 'resultado' => ['pago_id' => $pid, 'activo_hasta' => $q['hasta'], 'monto' => round((float) $monto, 2), 'comision' => null],
                    'cliente' => cliente_resumen($c), 'solicitud' => null];
        case 'anular_pago':
            $pid = (string) ($d['pago_id'] ?? '');
            $motivo = texto_campo($d, 'motivo', 5, 200, 'Escriba el motivo (5 a 200 letras).');
            $k = array_search($pid, array_column($c['pagos'], 'id'), true);
            if ($k === false || $c['pagos'][$k]['anulado']) fallo('no_encontrado', 'Ese pago no existe o ya está anulado.', 404);
            $c['pagos'][$k]['anulado'] = true;
            $pv = pagos_validos($c);
            if ($pv) $c['vence'] = max(array_column($pv, 'hasta'));
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, "Anuló el pago $pid: $motivo", $op);
            return ['mensaje' => 'Pago anulado.' . ($pv ? " Ahora vence el {$c['vence']}." : ' No quedan pagos: la fecha no cambió.'), 'resultado' => ['activo_hasta' => $c['vence']], 'cliente' => cliente_resumen($c)];
        case 'extender_prueba':
            $dias = $d['dias'] ?? null;
            if (!is_int($dias) || $dias < 1 || $dias > 90) fallo('invalido', 'Elija de 1 a 90 días.', 422, 'datos.dias');
            [$estado] = estado_de($c);
            if (!($estado === 'prueba' || ($estado === 'vencido' && !pagos_validos($c)))) fallo('no_permitido', 'Este cliente ya paga: no se puede alargar la prueba.', 403);
            $base = $c['vence'] !== null && $c['vence'] >= hoy() ? $c['vence'] : hoy();
            $c['vence'] = date('Y-m-d', strtotime("$base +$dias days"));
            $c['es_prueba'] = true;
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, "Alargó la demo hasta el {$c['vence']}", $op);
            return ['mensaje' => "Listo: la prueba de {$c['nombre']} ahora termina el {$c['vence']}.", 'resultado' => ['prueba_hasta' => $c['vence']], 'cliente' => cliente_resumen($c)];
        case 'aprobar_comprobante':
        case 'rechazar_comprobante':
            if ($s['tipo'] !== 'comprobante') fallo('no_permitido', 'Esa solicitud no es un comprobante.', 403);
            $raw = &$db['solicitudes'][$sid];
            if ($raw['estado'] !== 'pendiente') fallo('conflicto', 'Ese comprobante ya fue atendido.', 409, null, ['motivo' => 'ya_atendida', 'estado' => $raw['estado']]);
            if ($accion === 'rechazar_comprobante') {
                $raw['respuesta'] = texto_campo($d, 'motivo', 5, 300, 'Escriba el motivo (5 a 300 letras), lo verá el cliente.');
                $raw['estado'] = 'rechazada';
                anotar($db, $cid, $quien, 'Rechazó el comprobante: ' . $raw['respuesta'], $op);
                return ['mensaje' => 'Comprobante rechazado. El cliente verá el motivo.', 'resultado' => [], 'cliente' => cliente_resumen($c), 'solicitud' => solicitud_de($db, $sid)];
            }
            $monto = $d['monto'] ?? $raw['monto'];
            if (!is_int($monto) && !is_float($monto)) fallo('invalido', 'Escriba el monto con números.', 422, 'datos.monto');
            [$desde, $hasta] = vigencia($c, $raw['meses']);
            $pid = $cid . '9' . (string) $db['sig_pago']++;
            $c['pagos'][] = ['id' => $pid, 'fecha' => hoy(), 'monto' => round((float) $monto, 2), 'plan' => $raw['plan'], 'periodo' => $raw['meses'] === 6 ? 'semestral' : 'mensual', 'meses' => $raw['meses'],
                             'desde' => $desde, 'hasta' => $hasta, 'metodo' => 'transferencia', 'nota' => 'Comprobante ' . $sid, 'registrado_por' => 'Panel Tukán Central', 'anulado' => false];
            $c['vence'] = $hasta;
            $c['es_prueba'] = false;
            $c['plan'] = $raw['plan'];
            $db['clientes'][$cid] = $c;
            $raw['estado'] = 'aprobada';
            unset($raw);
            anotar($db, $cid, $quien, "Aprobó el comprobante y renovó hasta el $hasta", $op);
            return ['mensaje' => "Listo: {$c['nombre']} queda activa hasta el $hasta.", 'resultado' => ['pago_id' => $pid, 'activo_hasta' => $hasta], 'cliente' => cliente_resumen($c), 'solicitud' => solicitud_de($db, $sid)];
        case 'suspender':
            $motivo = texto_campo($d, 'motivo', 5, 200, 'Escriba el motivo (5 a 200 letras).');
            if ($c['suspendido']) $sin = true;
            $c['suspendido'] = true;
            $db['clientes'][$cid] = $c;
            if (!$sin) anotar($db, $cid, $quien, "Suspendió la cuenta: $motivo", $op);
            return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'Ya estaba suspendida.' : "{$c['nombre']} quedó suspendida.", 'resultado' => ['estado' => 'suspendido'], 'cliente' => cliente_resumen($c)];
        case 'pausar':
            [$nueva, $sin] = pedir_pausa($c['pausa'] ?? null, $d, $quien);
            $c['pausa'] = $nueva;
            $db['clientes'][$cid] = $c;
            if (!$sin) anotar($db, $cid, $quien, 'Pausó la cuenta (' . tipos_pausa()[$nueva['tipo']] . '): ' . $nueva['motivo'], $op);
            return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'Ya estaba pausada con ese motivo.' : "{$c['nombre']} quedó pausada. Al entrar verá «Tu cuenta está pausada».",
                    'resultado' => ['estado' => 'suspendido'], 'cliente' => cliente_resumen($c)];
        case 'borrar_cliente':
            $conf = $d['confirmar'] ?? null;
            if (!is_string($conf) || trim($conf) === '') fallo('invalido', 'Para confirmar escriba el nombre exacto del cliente.', 422, 'datos.confirmar');
            if (trim($conf) !== $c['nombre'] && trim($conf) !== $c['id']) fallo('invalido', 'No coincide con el nombre del cliente. No se borró nada.', 422, 'datos.confirmar');
            $motivo = texto_campo($d, 'motivo', 3, 300, 'Escriba el motivo (3 a 300 letras).');
            if (!empty($c['papelera'])) return ['sin_cambios' => true, 'mensaje' => 'Ya estaba en la papelera.', 'copia' => $c['papelera']['copia'], 'borrar_definitivo_el' => $c['papelera']['hasta'],
                                                'resultado' => ['copia' => $c['papelera']['copia'], 'borrar_definitivo_el' => $c['papelera']['hasta']], 'cliente' => cliente_resumen($c)];
            $hasta = dia(30);
            $copia = '/var/www/' . $producto . '/respaldos/borrados/' . $cid . '_' . date('Ymd_His', $GLOBALS['ahora']) . '.json.gz';
            $c['papelera'] = ['desde' => hoy(), 'hasta' => $hasta, 'motivo' => $motivo, 'copia' => $copia, 'quien' => $quien['email']];
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, "Borró el cliente (a la papelera hasta el $hasta): $motivo", $op);
            return ['mensaje' => "{$c['nombre']} quedó en la papelera. Se guardó una copia completa.", 'copia' => $copia, 'borrar_definitivo_el' => $hasta,
                    'resultado' => ['copia' => $copia, 'borrar_definitivo_el' => $hasta], 'cliente' => cliente_resumen($c)];
        case 'restaurar_cliente':
            if (empty($c['papelera'])) return ['sin_cambios' => true, 'mensaje' => 'No estaba en la papelera.', 'resultado' => [], 'cliente' => cliente_resumen($c)];
            $c['papelera'] = null;
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, 'Restauró el cliente de la papelera', $op);
            return ['mensaje' => "{$c['nombre']} volvió tal como estaba.", 'resultado' => [], 'cliente' => cliente_resumen($c)];
        case 'reactivar':
            if (!$c['suspendido'] && empty($c['pausa'])) $sin = true;
            $c['suspendido'] = false;
            $c['pausa'] = null;
            $db['clientes'][$cid] = $c;
            if (!$sin) anotar($db, $cid, $quien, 'Reactivó la cuenta', $op);
            $r = cliente_resumen($c);
            return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'No estaba suspendida.' : "{$c['nombre']} está activa otra vez.", 'resultado' => ['estado' => $r['estado']], 'cliente' => $r];
        case 'cambiar_plan':
            $plan = (string) ($d['plan'] ?? '');
            if (!isset(planes_lista()[$plan])) fallo('invalido', 'Ese plan no existe.', 422, 'datos.plan');
            $sin = $plan === $c['plan'];
            $c['plan'] = $plan;
            $db['clientes'][$cid] = $c;
            if (!$sin) anotar($db, $cid, $quien, 'Cambió el plan a ' . planes_lista()[$plan]['nombre'], $op);
            return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'Ya tenía ese plan.' : 'Plan cambiado a ' . planes_lista()[$plan]['nombre'] . '.', 'resultado' => ['plan' => ['codigo' => $plan, 'nombre' => planes_lista()[$plan]['nombre']]], 'cliente' => cliente_resumen($c)];
        case 'nueva_clave_dueno':
            $clave = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
            anotar($db, $cid, $quien, 'Creó una contraseña nueva para el dueño', $op);
            return ['mensaje' => 'Contraseña nueva para ' . $c['dueno']['nombre'] . '.', 'resultado' => ['usuario' => $c['dueno']['email'], 'clave' => $clave,
                    'mensaje_whatsapp' => 'Hola ' . explode(' ', $c['dueno']['nombre'])[0] . ", su nueva contraseña de {$NOMBRES[$producto]} es: $clave (cámbiela al entrar)."], 'cliente' => cliente_resumen($c)];
        case 'nota_interna':
            $t = $d['texto'] ?? '';
            if (!is_string($t) || mb_strlen($t) > 4000) fallo('invalido', 'La nota es muy larga (máximo 4000).', 422, 'datos.texto');
            $c['notas'] = $t;
            $db['clientes'][$cid] = $c;
            anotar($db, $cid, $quien, 'Guardó la nota interna', $op);
            return ['mensaje' => 'Nota guardada.', 'resultado' => [], 'cliente' => cliente_resumen($c)];
        case 'atender_solicitud':
            $res = (string) ($d['resultado'] ?? '');
            if (!in_array($res, ['en_proceso', 'atendida', 'descartada'], true)) fallo('invalido', 'resultado debe ser en_proceso, atendida o descartada.', 422, 'datos.resultado');
            if ($s['tipo'] === 'comprobante') fallo('no_permitido', 'Los comprobantes se aprueban o se rechazan.', 403);
            if ($s['estado'] === $res) return ['sin_cambios' => true, 'mensaje' => 'Ya estaba así.', 'resultado' => ['solicitud' => $s], 'solicitud' => $s];
            if (isset($db['solicitudes'][$sid])) {
                $raw = &$db['solicitudes'][$sid];
                if ($s['tipo'] === 'demo_pedida') { $raw['estado'] = $res === 'descartada' ? 'descartada' : 'contactada'; $raw['nota'] = (string) ($d['nota'] ?? ''); }
                elseif ($s['tipo'] === 'soporte') { $raw['estado'] = ['atendida' => 'resuelto', 'descartada' => 'cerrado', 'en_proceso' => 'en_proceso'][$res]; $raw['sin_leer'] = false; }
                elseif ($s['tipo'] === 'derechos_datos') {
                    if ($res === 'atendida') $raw['respuesta'] = texto_campo($d, 'respuesta', 5, 3000, 'Escriba la respuesta (al menos 5 letras).');
                    $raw['estado'] = ['atendida' => 'respondida', 'descartada' => 'rechazada', 'en_proceso' => 'en_proceso'][$res];
                }
                unset($raw);
            } else {
                $db['atenciones'][$sid] = ['estado' => $res, 'nota' => (string) ($d['nota'] ?? ''), 'email' => $quien['email']];
            }
            if ($cid !== null) anotar($db, $cid, $quien, "Atendió la solicitud $sid ($res)", $op);
            else registrar('bitacora.log', "{$quien['email']}|-|Atendió la solicitud $sid ($res)|$op");
            $nueva = null;
            foreach (todas_solicitudes($db) as $x) if ($x['id'] === $sid) $nueva = $x;
            return ['mensaje' => $res === 'en_proceso' ? 'Marcada en proceso.' : ($res === 'descartada' ? 'Solicitud descartada.' : 'Solicitud atendida.'),
                    'resultado' => ['solicitud' => $nueva], 'solicitud' => $nueva, 'cliente' => $c ? cliente_resumen($c) : null];
        case 'responder_ticket':
            if ($s['tipo'] !== 'soporte') fallo('no_permitido', 'Esa solicitud no es un ticket.', 403);
            $t = texto_campo($d, 'texto', 1, 6000, 'Escriba la respuesta.');
            $est = (string) ($d['estado'] ?? 'esperando');
            if (!in_array($est, ['en_proceso', 'esperando', 'resuelto', 'cerrado'], true)) fallo('invalido', 'Estado no válido.', 422, 'datos.estado');
            $db['solicitudes'][$sid]['mensajes'][] = ['de' => 'tukan', 'autor' => $quien['nombre'], 'momento' => date('c'), 'texto' => $t, 'adjunto_id' => null];
            $db['solicitudes'][$sid]['estado'] = $est;
            $db['solicitudes'][$sid]['sin_leer'] = false;
            anotar($db, $cid, $quien, 'Respondió el ticket', $op);
            $nueva = solicitud_de($db, $sid);
            return ['mensaje' => 'Respuesta enviada al cliente.', 'resultado' => ['solicitud' => $nueva], 'solicitud' => $nueva];
    }
    fallo('no_permitido', 'Operación no disponible.', 403);
}

function crear_demo(array &$db, array $d, array $quien, string $op): array
{
    global $NOMBRES, $producto;
    $nombre = texto_campo($d, 'nombre', 3, 120, 'Escriba el nombre del negocio (3 a 120 letras).');
    $contacto = texto_campo($d, 'contacto_nombre', 2, 80, 'Escriba el nombre del contacto (2 a 80 letras).');
    $email = (string) ($d['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fallo('invalido', 'El correo no es válido.', 422, 'datos.email');
    foreach ($db['clientes'] as $c) if (strcasecmp($c['dueno']['email'], $email) === 0) fallo('invalido', 'Ese correo ya tiene una cuenta en ' . $NOMBRES[$producto] . '.', 422, 'datos.email');
    $tel = (string) ($d['telefono'] ?? '');
    if (strlen($tel) < 9 || strlen($tel) > 30 || strlen(preg_replace('/\D+/', '', $tel)) < 9) fallo('invalido', 'Escriba un celular con al menos 9 números.', 422, 'datos.telefono');
    $plan = $d['plan'] ?? null;
    if ($plan !== null && !isset(planes_lista()[(string) $plan])) fallo('invalido', 'Ese plan no existe.', 422, 'datos.plan');
    $dias = $d['dias'] ?? 7;
    if (!is_int($dias) || $dias < 1 || $dias > 90) fallo('invalido', 'Elija de 1 a 90 días de prueba.', 422, 'datos.dias');
    $extra = is_array($d['extra'] ?? null) ? $d['extra'] : [];
    $sid = $d['solicitud_id'] ?? null;
    if ($sid !== null) {
        if (!isset($db['solicitudes'][$sid]) || $db['solicitudes'][$sid]['tipo'] !== 'demo_pedida') fallo('no_encontrado', 'Ese pedido de demo no existe.', 404);
        $db['solicitudes'][$sid]['estado'] = 'creada';
    }
    $cid = (string) $db['sig_cliente']++;
    $clave = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);
    $c = ['id' => $cid, 'nombre' => $nombre, 'tipo' => $extra['tipo'] ?? null, 'ciudad' => $extra['ciudad'] ?? null, 'plan' => (string) ($plan ?? '1'), 'es_prueba' => true,
          'vence' => dia($dias), 'suspendido' => false, 'alta' => hoy(), 'dueno' => ['nombre' => $contacto, 'telefono' => $tel, 'email' => strtolower($email)],
          'ultima_actividad' => null, 'origen' => $sid ? 'demo_pedida' : 'central', 'notas' => '', 'pagos' => []];
    // Anexo B: la demo con todo encendido, salvo que se elijan los módulos
    if ($GLOBALS['conModulos']) {
        $mods = $d['modulos'] ?? null;
        if ($mods === 'ninguno') $mods = [];
        elseif ($mods === []) $mods = null;   // lista vacía = todos (como TuSalón)
        if ($mods !== null && (!is_array($mods) || array_diff($mods, array_keys(mod_catalogo()['modulos'])))) fallo('invalido', 'Módulos de la demo no válidos.', 422, 'datos.modulos');
        $c['mods'] = mod_desde_activos($c['plan'], $mods ?? array_keys(mod_catalogo()['modulos']), null);
        registrar('demos.log', $cid . '|' . $c['plan'] . '|' . implode(',', $mods ?? ['*']));
    }
    $db['clientes'][$cid] = $c;
    anotar($db, $cid, $quien, "Creó la demo hasta el {$c['vence']}", $op);
    $wa = preg_replace('/\D+/', '', $tel);
    $wa = str_starts_with($wa, '0') ? '593' . substr($wa, 1) : $wa;
    $url = 'https://' . $producto . '.ejemplo.ec';
    $msg = '¡Hola ' . explode(' ', $contacto)[0] . "! Su cuenta *$nombre* ya está lista en {$NOMBRES[$producto]}.\n\n🌐 Página pública: $url/$cid\n🔐 Panel: $url/admin.php\n👤 Usuario: " . strtolower($email) . "\n🔑 Contraseña: $clave\n\nLe recomendamos cambiar la contraseña al ingresar.";
    return ['mensaje' => "Demo creada: $nombre, hasta el {$c['vence']}.", 'resultado' => [
        'cliente_id' => $cid, 'prueba_hasta' => $c['vence'], 'acceso' => ['url_panel' => "$url/admin.php", 'usuario' => strtolower($email), 'clave' => $clave],
        'enlace_publico' => "$url/$cid", 'mensaje_whatsapp' => $msg, 'whatsapp_url' => 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg), 'correo_enviado' => ($d['enviar_correo'] ?? false) === true,
    ], 'cliente' => cliente_resumen($c), 'solicitud' => $sid ? solicitud_de($db, $sid) : null];
}

// =====================================================================
// Anexo B: programa modular (MOCK_SIN_MODULOS=1 = programa sin módulos)
// =====================================================================

function mod_catalogo_base(): array
{
    if ($GLOBALS['unico']) {
        return [
            'modulos' => [
                'guarderia' => ['Guardería por días', 'calendar-check', 15.0, [], 'Servicios', 'Llegada y salida con la placa QR y planes de días.'],
                'hotel' => ['Hotel y estadías', 'bed-double', 10.0, [], 'Servicios', 'Cuartos y reservas por noches.'],
                'peluqueria' => ['Peluquería y spa', 'scissors', 10.0, [], 'Servicios', 'Agenda de baño y corte.'],
                'rutas' => ['Rutas con GPS y chofer', 'route', 15.0, [], 'Servicios', 'Recogida y entrega con la camioneta en vivo.'],
                'portal' => ['Portal de dueños', 'house', 10.0, [], 'Dueños de mascotas', 'Los dueños ven a sus perros y piden reservas.'],
                'anuncios' => ['Anuncios y eventos', 'megaphone', 5.0, ['portal'], 'Dueños de mascotas', 'Avisos y eventos con confirmación.'],
                'fotos' => ['Fotos y videos', 'images', 8.0, [], 'Día a día', 'Galería de cada perro.'],
            ],
            'planes' => ['esencial' => ['Esencial', 25.0, ['guarderia', 'portal']], 'completo' => ['Completo', 59.0, '*']],
            'base' => ['Fichas de perros y dueños', 'Usuarios, ajustes y marca propia'],
        ];
    }
    return [
        'modulos' => [
            'agenda' => ['Agenda y reservas', 'calendar', 8.0, [], 'Trabajo diario', 'Reservas con calendario.'],
            'web' => ['Página pública', 'globe', 6.0, [], 'Clientes', 'Su página con fotos y precios.'],
            'pagos_linea' => ['Cobros con tarjeta', 'card', 10.0, [], 'Clientes', 'Sus clientes pagan en línea.'],
            'whatsapp' => ['Avisos por WhatsApp', 'chat', 5.0, ['agenda'], 'Trabajo diario', 'Recordatorios automáticos de las reservas.'],
            'reportes' => ['Reportes', 'chart', 4.0, [], 'Administración', 'Ventas y gráficos del mes.'],
        ],
        'planes' => ['1' => ['Básico', 19.0, ['agenda', 'web']], '2' => ['Completo', 29.0, '*']],
        'base' => ['Usuarios y ajustes', 'Clientes y contactos'],
    ];
}

/** Catálogo con lo que se cambió (guardar_modulos) encima. */
function mod_catalogo(): array
{
    $b = mod_catalogo_base();
    $cam = $GLOBALS['db']['catalogo'] ?? ['modulos' => [], 'planes' => []];
    $mods = [];
    $orden = 0;
    foreach ($b['modulos'] as $id => [$n, $ic, $p, $dep, $g, $desc]) {
        $o = $cam['modulos'][$id] ?? [];
        $mods[$id] = ['id' => $id, 'nombre' => $o['nombre'] ?? $n, 'icono' => $ic, 'descripcion' => $o['descripcion'] ?? $desc,
                      'precio_mensual' => (float) ($o['precio_mensual'] ?? $p), 'depende_de' => $dep, 'grupo' => $g, 'orden' => $o['orden'] ?? ($orden += 10)];
    }
    $planes = [];
    foreach ($b['planes'] as $cod => [$n, $p, $m]) {
        if (!empty($cam['planes'][$cod]['borrado'])) continue;
        $o = $cam['planes'][$cod] ?? [];
        $m = $o['modulos'] ?? $m;
        $planes[(string) $cod] = ['codigo' => (string) $cod, 'nombre' => $o['nombre'] ?? $n, 'precio_mensual' => (float) ($o['precio_mensual'] ?? $p),
                                  'todos' => $m === '*', 'modulos' => $m === '*' ? array_keys($mods) : $m];
    }
    foreach ($cam['planes'] ?? [] as $cod => $o) {
        if (isset($planes[$cod]) || !empty($o['borrado']) || !isset($o['nombre'])) continue;
        $planes[(string) $cod] = ['codigo' => (string) $cod, 'nombre' => $o['nombre'], 'precio_mensual' => (float) $o['precio_mensual'], 'todos' => $o['modulos'] === '*',
                                  'modulos' => $o['modulos'] === '*' ? array_keys($mods) : $o['modulos']];
    }
    return ['base' => $b['base'], 'modulos' => $mods, 'planes' => $planes];
}

function mod_accion_modulos(): array
{
    $c = mod_catalogo();
    return ['moneda' => 'USD', 'editable' => true, 'base' => $c['base'], 'modulos' => array_values($c['modulos']), 'planes' => array_values($c['planes'])];
}

/** Estado por defecto de los módulos de un cliente (su plan, nada extra). */
function mod_defecto(?string $plan): array
{
    return ['plan' => $plan ?? ($GLOBALS['unico'] ? 'completo' : '1'), 'extra' => [], 'quitados' => [], 'acordado' => null];
}

/** El objeto "modulos" del cliente (B.2). */
function mod_resumen(?array $m): array
{
    $cat = mod_catalogo();
    $m ??= mod_defecto(null);
    $del = $cat['planes'][$m['plan']]['modulos'] ?? [];
    $act = array_values(array_intersect(array_keys($cat['modulos']), array_merge(array_diff($del, $m['quitados']), $m['extra'])));
    $pe = round(array_sum(array_map(fn($x) => $cat['modulos'][$x]['precio_mensual'], $m['extra'])), 2);
    $de = round(array_sum(array_map(fn($x) => $cat['modulos'][$x]['precio_mensual'], array_intersect($m['quitados'], $del))), 2);
    $pp = $cat['planes'][$m['plan']]['precio_mensual'] ?? 0.0;
    $calc = round(max(0, $pp + $pe - $de), 2);
    return ['plan' => ['codigo' => $m['plan'], 'nombre' => $cat['planes'][$m['plan']]['nombre'] ?? $m['plan']], 'del_plan' => $del, 'extra' => $m['extra'],
            'quitados' => array_values(array_intersect($m['quitados'], $del)), 'activos' => $act, 'precio_plan' => $pp, 'precio_extra' => $pe, 'descuento' => $de,
            'precio_acordado' => $m['acordado'], 'calculado' => $calc, 'mensual' => $m['acordado'] ?? $calc];
}

/** Estado (plan, extra, quitados) a partir de lo que debe quedar encendido. */
function mod_desde_activos(string $plan, array $activos, ?float $acordado): array
{
    $cat = mod_catalogo();
    $del = $cat['planes'][$plan]['modulos'] ?? [];
    $orden = array_keys($cat['modulos']);
    return ['plan' => $plan, 'extra' => array_values(array_intersect($orden, array_diff($activos, $del))),
            'quitados' => array_values(array_intersect($orden, array_diff($del, $activos))), 'acordado' => $acordado];
}

/** Solicitud cambio_modulos con la forma de §5.8. */
function mod_solicitud(array $db, string $sid, array $s): array
{
    $cat = mod_catalogo();
    $cid = $s['cliente_id'];
    $nombre = $GLOBALS['unico'] ? $db['unico']['nombre'] : ($db['clientes'][$cid]['nombre'] ?? $cid);
    $m = $GLOBALS['unico'] ? ($db['unico']['mods'] ?? mod_defecto(null)) : ($db['clientes'][$cid]['mods'] ?? mod_defecto($db['clientes'][$cid]['plan'] ?? null));
    $antes = mod_resumen($m);
    $act = array_values(array_diff(array_unique(array_merge($antes['activos'], $s['encender'])), $s['apagar']));
    $nuevo = mod_resumen(mod_desde_activos($m['plan'], $act, $m['acordado']));
    $estado = ['pendiente' => 'pendiente', 'aprobada' => 'atendida', 'rechazada' => 'descartada'][$s['estado']];
    $lista = fn(array $ids) => array_map(fn($x) => ['id' => $x, 'nombre' => $cat['modulos'][$x]['nombre'], 'precio_mensual' => $cat['modulos'][$x]['precio_mensual']], $ids);
    $txt = 'Encender: ' . implode(', ', array_map(fn($x) => $cat['modulos'][$x]['nombre'], $s['encender']));
    return ['id' => $sid, 'tipo' => 'cambio_modulos', 'categoria' => 'pedido', 'titulo' => 'Pide agregar ' . implode(', ', array_map(fn($x) => $cat['modulos'][$x]['nombre'], $s['encender'])),
            'fecha' => $s['fecha'], 'actualizado' => $s['fecha'], 'cliente_id' => $cid,
            'quien' => ['nombre' => 'Gabriela Ortiz', 'organizacion' => $nombre, 'telefono' => '0998877665', 'whatsapp' => '593998877665', 'email' => 'gabriela@dogssy.ec'],
            'resumen' => $txt . ' · Nuevo mensual $' . number_format($nuevo['mensual'], 2, ',', '.'), 'monto' => $nuevo['mensual'],
            'estado' => $estado, 'estado_producto' => $s['estado'], 'prioridad' => 'normal', 'vence_el' => null,
            'detalle' => ['encender' => $lista($s['encender']), 'apagar' => $lista($s['apagar']), 'mensual_actual' => $antes['mensual'], 'mensual_nuevo' => $nuevo['mensual'],
                          'nota' => $s['nota'], 'pedido_por' => 'Gabriela Ortiz'],
            'adjuntos' => [],
            'acciones_posibles' => $estado === 'pendiente' ? [
                ['accion' => 'cambiar_modulos', 'etiqueta' => 'Aprobar', 'estilo' => 'principal', 'confirmar' => null,
                 'datos' => ['cliente_id' => $cid, 'solicitud_id' => $sid], 'campos' => []],
                ['accion' => 'atender_solicitud', 'etiqueta' => 'Rechazar', 'estilo' => 'peligro', 'confirmar' => '¿Rechazar este pedido?', 'datos' => ['solicitud_id' => $sid, 'resultado' => 'descartada'],
                 'campos' => [['campo' => 'nota', 'etiqueta' => 'Motivo (lo ve el cliente)', 'tipo' => 'texto', 'requerido' => true, 'min' => 3, 'max' => 300]]],
            ] : [], 'ir' => '/admin?mi_plan'];
}

function mod_lista(array $d, string $k, array $cat): array
{
    $v = $d[$k] ?? [];
    if (!is_array($v) || ($v !== [] && !array_is_list($v))) fallo('invalido', "«$k» debe ser una lista.", 422, "datos.$k");
    foreach ($v as $x) if (!is_string($x) || !isset($cat['modulos'][$x])) fallo('invalido', 'Módulo desconocido.', 422, "datos.$k");
    return array_values(array_unique($v));
}

/** cambiar_modulos, guardar_modulos y atender_solicitud de un cambio_modulos. */
function mod_hacer(array &$db, string $accion, ?string $cid, array $d, array $quien, string $op): array
{
    global $unico;
    $cat = mod_catalogo();
    if ($accion === 'guardar_modulos') {
        $cambios = 0;
        foreach (is_array($d['modulos'] ?? null) ? $d['modulos'] : [] as $i => $m) {
            if (!isset($cat['modulos'][$m['id'] ?? ''])) fallo('invalido', 'Módulo desconocido.', 422, "datos.modulos.$i.id");
            if (isset($m['precio_mensual']) && (!is_int($m['precio_mensual']) && !is_float($m['precio_mensual']) || $m['precio_mensual'] < 0)) fallo('invalido', 'Precio no válido.', 422, "datos.modulos.$i.precio_mensual");
            foreach (['nombre', 'descripcion', 'precio_mensual', 'orden'] as $k) if (array_key_exists($k, $m)) { $db['catalogo']['modulos'][$m['id']][$k] = $m[$k]; $cambios++; }
        }
        foreach (is_array($d['planes'] ?? null) ? $d['planes'] : [] as $i => $p) {
            $cod = (string) ($p['codigo'] ?? '');
            if (!empty($p['borrar'])) {
                $actual = $unico ? ($db['unico']['mods']['plan'] ?? 'completo') : null;
                if ($cod === $actual) fallo('conflicto', 'Ese plan está en uso.', 409, null, ['motivo' => 'plan_en_uso']);
                $db['catalogo']['planes'][$cod] = ['borrado' => true]; $cambios++; continue;
            }
            if (!isset($cat['planes'][$cod]) && (!isset($p['nombre'], $p['precio_mensual'], $p['modulos']))) fallo('invalido', 'Plan nuevo incompleto.', 422, "datos.planes.$i");
            foreach (['nombre', 'precio_mensual', 'modulos'] as $k) if (array_key_exists($k, $p)) { $db['catalogo']['planes'][$cod][$k] = $p[$k]; $cambios++; }
        }
        if (!$cambios) fallo('invalido', 'No vino nada para guardar.', 422, 'datos');
        registrar('bitacora.log', "{$quien['email']}|-|Catálogo de módulos guardado ($cambios cambios)|$op");
        $GLOBALS['db'] = $db;
        $nuevo = mod_accion_modulos();
        return ['mensaje' => "Catálogo guardado ($cambios cambios).", 'catalogo' => $nuevo, 'resultado' => ['catalogo' => $nuevo]];
    }
    $sid = isset($d['solicitud_id']) ? (string) $d['solicitud_id'] : null;
    $sol = null;
    if ($sid !== null) {
        $sol = $db['solicitudes'][$sid] ?? null;
        if (!$sol || $sol['tipo'] !== 'cambio_modulos') fallo('no_encontrado', 'Esa solicitud no existe.', 404);
        if ($sol['estado'] !== 'pendiente') {
            if ($accion === 'atender_solicitud' && ($d['resultado'] ?? '') === 'descartada' && $sol['estado'] === 'rechazada') return ['sin_cambios' => true, 'mensaje' => 'Ya estaba rechazado.', 'solicitud' => mod_solicitud($db, $sid, $sol)];
            fallo('conflicto', 'Ese pedido ya fue atendido.', 409, null, ['motivo' => 'ya_atendida']);
        }
        $cid = $sol['cliente_id'];
    }
    if ($accion === 'atender_solicitud') {
        $res = (string) ($d['resultado'] ?? '');
        if ($res === 'atendida') fallo('no_permitido', 'Para aprobar use cambiar_modulos.', 403, null, ['motivo' => 'usar_cambiar_modulos']);
        if ($res !== 'descartada') fallo('invalido', 'resultado no válido.', 422, 'datos.resultado');
        texto_campo($d, 'nota', 3, 300, 'Escriba el motivo del rechazo.');
        $db['solicitudes'][$sid]['estado'] = 'rechazada';
        registrar('bitacora.log', "{$quien['email']}|$cid|Rechazó el pedido de módulos $sid|$op");
        $s = mod_solicitud($db, $sid, $db['solicitudes'][$sid]);
        return ['mensaje' => 'Pedido rechazado.', 'resultado' => ['solicitud' => $s], 'solicitud' => $s];
    }
    // cambiar_modulos
    $ref = $unico ? ($db['unico']['mods'] ?? mod_defecto(null)) : (isset($db['clientes'][$cid]) ? ($db['clientes'][$cid]['mods'] ?? mod_defecto($db['clientes'][$cid]['plan'])) : null);
    if ($ref === null) fallo('no_encontrado', 'Ese cliente no existe.', 404);
    $enc = mod_lista($d, 'encender', $cat);
    $apa = mod_lista($d, 'apagar', $cat);
    if ($sol && !$enc && !$apa) { $enc = $sol['encender']; $apa = $sol['apagar']; }
    if (array_intersect($enc, $apa)) fallo('invalido', 'No se puede encender y apagar a la vez.', 422, 'datos.apagar');
    $plan = isset($d['plan']) ? (string) $d['plan'] : $ref['plan'];
    if (!isset($cat['planes'][$plan])) fallo('invalido', 'Ese plan no existe.', 422, 'datos.plan');
    $acordado = array_key_exists('precio_acordado', $d) ? ($d['precio_acordado'] === null ? null : (float) $d['precio_acordado']) : $ref['acordado'];
    $antes = mod_resumen($ref);
    $act = array_values(array_diff(array_unique(array_merge($antes['activos'], $enc)), $apa));
    $porDep = [];
    foreach ($act as $x) foreach ($cat['modulos'][$x]['depende_de'] as $dd) if (!in_array($dd, $act, true)) {
        if (in_array($dd, $apa, true)) fallo('invalido', 'No se puede apagar «' . $cat['modulos'][$dd]['nombre'] . '»: lo usa «' . $cat['modulos'][$x]['nombre'] . '».', 422, 'datos.apagar', ['motivo' => 'dependencia']);
        $act[] = $dd; $porDep[] = $dd;
    }
    $nuevo = mod_desde_activos($plan, $act, $acordado);
    $sin = $nuevo == $ref && !$sol;
    if ($unico) $db['unico']['mods'] = $nuevo; else $db['clientes'][$cid]['mods'] = $nuevo;
    if ($sol) $db['solicitudes'][$sid]['estado'] = 'aprobada';
    $GLOBALS['db'] = $db;
    $r = mod_resumen($nuevo);
    if (!$sin) registrar('bitacora.log', "{$quien['email']}|" . ($cid ?? 'unico') . '|Módulos: ' . implode(',', $r['activos']) . " ({$r['mensual']})|$op");
    return ['sin_cambios' => $sin, 'mensaje' => $sin ? 'No había nada que cambiar.' : ($sol ? 'Pedido aprobado. ' : '') . 'Módulos guardados. Valor mensual: $' . number_format($antes['mensual'], 2, ',', '.') . ' → $' . number_format($r['mensual'], 2, ',', '.') . '.',
            'modulos' => $r, 'resultado' => ['modulos' => $r, 'por_dependencia' => $porDep, 'mensual_antes' => $antes['mensual'], 'mensual' => $r['mensual']],
            'cliente' => $unico ? cliente_detalle($db, ['unico' => true]) : cliente_resumen($db['clientes'][$cid]),
            'solicitud' => $sol ? mod_solicitud($db, $sid, $db['solicitudes'][$sid]) : null];
}
