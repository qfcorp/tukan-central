<?php
declare(strict_types=1);

namespace Tukan;

use Throwable;

require_once dirname(__DIR__) . '/compartido/tukan_correo.php';

/**
 * «Enviar por correo» en la pantalla «Demo lista» (desde info@tukansoftware.com, con compartido/tukan_correo.php).
 *
 * La contraseña de la demo se ve UNA vez y nunca se guarda (ni base, ni auditoría, ni sesión). Para mandarla por
 * correo, la misma pantalla lleva un «sello»: los datos de la demo CIFRADOS con TUKAN_CENTRAL_KEY, atados al usuario
 * del panel, que vencen en 30 minutos. En la sesión solo queda un número de un solo uso (sin la clave): al enviar
 * bien, se borra y el mismo sello ya no sirve para otro envío.
 */
final class CorreoDemo
{
    public const DURA_SEG = 1800;
    private const MAX_PENDIENTES = 20;

    public function __construct(private Cifrado $cifrado) {}

    public static function listo(): bool
    {
        return tukan_correo_listo();
    }

    /** Comando para configurar el correo en el servidor. */
    public static function comandoConfigurar(): string
    {
        return 'cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh';
    }

    /**
     * Sella los datos de la pantalla (instancia, mensaje, resultado con la clave, cliente) y anota el número de un solo
     * uso en $sesion (por referencia: normalmente $_SESSION).
     */
    public function sellar(array $datos, int $uid, array &$sesion, ?int $ahora = null): string
    {
        $ahora ??= time();
        $nonce = bin2hex(random_bytes(16));
        $pend = array_filter(is_array($sesion['correo_demo'] ?? null) ? $sesion['correo_demo'] : [], fn($exp) => is_int($exp) && $exp > $ahora);
        if (count($pend) >= self::MAX_PENDIENTES) $pend = array_slice($pend, -(self::MAX_PENDIENTES - 1), null, true);
        $pend[$nonce] = $ahora + self::DURA_SEG;
        $sesion['correo_demo'] = $pend;
        $json = json_encode(['n' => $nonce, 'exp' => $ahora + self::DURA_SEG, 'd' => $datos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->cifrado->cifrar($json, "correo_demo:$uid");
    }

    /**
     * Abre un sello. Devuelve ['n' => nonce, 'd' => datos, 'usado' => bool] o null si es falso, de otro usuario o venció.
     * usado = el número ya no está en la sesión (se envió antes, o es otra sesión).
     */
    public function abrir(string $sello, int $uid, array $sesion, ?int $ahora = null): ?array
    {
        $ahora ??= time();
        if ($sello === '' || strlen($sello) > 60000) return null;
        try {
            $x = json_decode($this->cifrado->descifrar($sello, "correo_demo:$uid"), true);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($x) || !is_string($x['n'] ?? null) || !is_int($x['exp'] ?? null) || !is_array($x['d'] ?? null) || $x['exp'] <= $ahora) return null;
        $pend = is_array($sesion['correo_demo'] ?? null) ? $sesion['correo_demo'] : [];
        return ['n' => $x['n'], 'd' => $x['d'], 'usado' => !isset($pend[$x['n']])];
    }

    /** Borra el número de un solo uso (después de enviar bien). */
    public static function gastar(string $nonce, array &$sesion): void
    {
        unset($sesion['correo_demo'][$nonce]);
    }

    /**
     * Arma el correo «Su demo de {Programa} está lista» con los datos de la pantalla.
     * $d: programa, inst_url, resultado (acceso.url_panel / usuario / clave, prueba_hasta), contacto, negocio.
     */
    public static function mensaje(array $d): array
    {
        $r = is_array($d['resultado'] ?? null) ? $d['resultado'] : [];
        $acceso = is_array($r['acceso'] ?? null) ? $r['acceso'] : [];
        return tukan_correo_demo([
            'programa' => (string) ($d['programa'] ?? ''),
            'enlace'   => (string) ($acceso['url_panel'] ?? $d['inst_url'] ?? ''),
            'usuario'  => (string) ($acceso['usuario'] ?? $r['usuario'] ?? ''),
            'clave'    => (string) ($acceso['clave'] ?? $r['clave'] ?? ''),
            'hasta'    => (string) ($r['prueba_hasta'] ?? ''),
            'contacto' => (string) ($d['contacto'] ?? ''),
            'negocio'  => (string) ($d['negocio'] ?? ''),
        ]);
    }

    /** Correo que se propone en el campo: el de la demo, el usuario (si es un correo) o el del dueño. */
    public static function correoSugerido(array $d): string
    {
        $r = is_array($d['resultado'] ?? null) ? $d['resultado'] : [];
        foreach ([$d['email'] ?? null, $r['acceso']['usuario'] ?? null, $r['usuario'] ?? null, $d['cliente']['dueno']['email'] ?? null] as $c) {
            if (is_string($c) && tukan_correo_valido(trim($c))) return trim($c);
        }
        return '';
    }

    /** Manda el correo de la demo. El $error nunca trae la clave de la demo ni la del buzón. */
    public static function enviar(array $d, string $para, ?string &$error = null): bool
    {
        $m = self::mensaje($d);
        $ok = tukan_correo_enviar($para, $m['asunto'], $m['html'], $m['texto'], $error, array_filter(['nombre_para' => (string) ($d['contacto'] ?? '')]));
        $clave = (string) ($d['resultado']['acceso']['clave'] ?? $d['resultado']['clave'] ?? '');
        if ($error !== null && $clave !== '') $error = str_replace($clave, '***', $error);
        return $ok;
    }
}
