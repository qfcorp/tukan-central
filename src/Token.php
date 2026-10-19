<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Token del Protocolo Tukán Central v1 (docs/PROTOCOLO.md §3) y v2 (docs/PROTOCOLO_V2.md §2).
 *   token = b64url(payload_json) . "." . b64url(HMAC_SHA256(b64url(payload_json), SECRETO))
 * El HMAC usa el secreto tal como está escrito (64 hex como texto). Dura 60 segundos y sirve una sola vez (nonce).
 * v2 agrega "rol" y "bh" (hash del cuerpo exacto del POST); "ejecutar" dura 30 segundos.
 */
final class Token
{
    public const DURACION = 60;
    public const DURACION_EJECUTAR = 30;
    public const TIPOS = ['sso', 'estado'];
    public const TIPOS_V2 = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'modulos'];

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** Firma un payload ya armado (igual que tc_firmar del protocolo, byte por byte). */
    public static function firmar(array $payload, string $secreto): string
    {
        $a = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $a . '.' . self::b64(hash_hmac('sha256', $a, $secreto, true));
    }

    /** Payload en el orden exacto del protocolo. $ir (solo sso, v2 §5.3): enlace directo, va al final. */
    public static function payload(string $tipo, string $inst, string $email, string $nombre, ?int $ahora = null, ?string $nonce = null, ?string $ir = null): array
    {
        if (!in_array($tipo, self::TIPOS, true)) throw new \InvalidArgumentException("Tipo de token no valido: $tipo");
        $ahora ??= time();
        $p = [
            'v'      => 1,
            'tipo'   => $tipo,
            'inst'   => $inst,
            'email'  => $email,
            'nombre' => $nombre,
            'iat'    => $ahora,
            'exp'    => $ahora + self::DURACION,
            'nonce'  => $nonce ?? bin2hex(random_bytes(16)),
        ];
        if ($ir !== null && $tipo === 'sso') $p['ir'] = $ir;
        return $p;
    }

    public static function crear(string $tipo, string $inst, string $email, string $nombre, string $secreto, ?int $ahora = null, ?string $ir = null): string
    {
        return self::firmar(self::payload($tipo, $inst, $email, $nombre, $ahora, null, $ir), $secreto);
    }

    /** Hash del cuerpo (v2 §2.2): b64url(SHA256(bytes exactos)), 43 caracteres. */
    public static function bh(string $cuerpo): string
    {
        return self::b64(hash('sha256', $cuerpo, true));
    }

    /** Payload v2 en el orden del ejemplo del protocolo (§2.2). */
    public static function payloadV2(string $tipo, string $inst, string $email, string $nombre, string $rol, string $cuerpo, ?int $vida = null, ?int $ahora = null, ?string $nonce = null): array
    {
        if (!in_array($tipo, self::TIPOS_V2, true)) throw new \InvalidArgumentException("Tipo de token v2 no valido: $tipo");
        $max = $tipo === 'ejecutar' ? self::DURACION_EJECUTAR : self::DURACION;
        $vida = min($vida ?? $max, $max);
        $ahora ??= time();
        return [
            'v'      => 2,
            'tipo'   => $tipo,
            'inst'   => $inst,
            'email'  => $email,
            'nombre' => $nombre,
            'rol'    => $rol === 'soporte' ? 'soporte' : 'dueno',
            'iat'    => $ahora,
            'exp'    => $ahora + $vida,
            'nonce'  => $nonce ?? bin2hex(random_bytes(16)),
            'bh'     => self::bh($cuerpo),
        ];
    }

    /** Token v2 (§9.1): firma el payload con el hash del cuerpo que se va a mandar tal cual. */
    public static function crearV2(string $tipo, string $inst, string $email, string $nombre, string $rol, string $cuerpo, string $secreto, ?int $vida = null, ?int $ahora = null): string
    {
        return self::firmar(self::payloadV2($tipo, $inst, $email, $nombre, $rol, $cuerpo, $vida, $ahora), $secreto);
    }

    /**
     * Revisa un token v2 como lo haría un programa (§3.1, pasos 4 a 11). Lanza \RuntimeException con el código
     * del protocolo como mensaje (firma, version, tipo, inst, invalido, vencido, futuro). No guarda nonces.
     */
    public static function validarV2(string $token, string $secreto, string $accion, string $inst, string $cuerpo, ?int $ahora = null): array
    {
        $ahora ??= time();
        $partes = explode('.', $token);
        if (count($partes) !== 2 || !hash_equals(self::b64(hash_hmac('sha256', $partes[0], $secreto, true)), $partes[1])) throw new \RuntimeException('firma');
        $json = self::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new \RuntimeException('firma');
        if (($p['v'] ?? null) !== 2) throw new \RuntimeException('version');
        if (($p['tipo'] ?? null) !== $accion) throw new \RuntimeException('tipo');
        if (!is_string($p['inst'] ?? null) || !hash_equals($inst, $p['inst'])) throw new \RuntimeException('inst');
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        $max = $accion === 'ejecutar' ? self::DURACION_EJECUTAR : self::DURACION;
        if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) throw new \RuntimeException('invalido');
        if ($ahora > $exp + 30) throw new \RuntimeException('vencido');
        if ($iat > $ahora + 30) throw new \RuntimeException('futuro');
        if (!is_string($p['bh'] ?? null) || !hash_equals(self::bh($cuerpo), $p['bh'])) throw new \RuntimeException('firma');
        if (!is_string($p['nonce'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $p['nonce'])) throw new \RuntimeException('nonce');
        if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('invalido');
        return $p;
    }

    /**
     * Revisa un token como lo haría una instancia (lo usan las pruebas y sirve de referencia para los programas).
     * Devuelve el payload o lanza un error con el motivo. No guarda nonces (eso lo hace quien llama).
     */
    public static function validar(string $token, string $secreto, string $tipo, string $inst, ?int $ahora = null): array
    {
        $ahora ??= time();
        $partes = explode('.', $token);
        if (count($partes) !== 2) throw new \RuntimeException('token mal formado');
        [$a, $firma] = $partes;
        if (!hash_equals(self::b64(hash_hmac('sha256', $a, $secreto, true)), $firma)) throw new \RuntimeException('firma incorrecta');
        $json = self::unb64($a);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new \RuntimeException('token mal formado');
        if (($p['v'] ?? null) !== 1) throw new \RuntimeException('version no soportada');
        if (($p['tipo'] ?? null) !== $tipo) throw new \RuntimeException('tipo incorrecto');
        if (($p['inst'] ?? null) !== $inst) throw new \RuntimeException('instancia incorrecta (inst)');
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) throw new \RuntimeException('tiempo no valido');
        if ($ahora > $exp + 30) throw new \RuntimeException('token vencido');
        if ($iat > $ahora + 30) throw new \RuntimeException('token del futuro (reloj)');
        if ($exp - $iat > 60) throw new \RuntimeException('token demasiado largo');
        if (!is_string($p['nonce'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $p['nonce'])) throw new \RuntimeException('nonce no valido');
        if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('email no valido');
        return $p;
    }
}
