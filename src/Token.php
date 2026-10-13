<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Token del Protocolo Tukán Central v1 (docs/PROTOCOLO.md §3).
 *   token = b64url(payload_json) . "." . b64url(HMAC_SHA256(b64url(payload_json), SECRETO))
 * El HMAC usa el secreto tal como está escrito (64 hex como texto). Dura 60 segundos y sirve una sola vez (nonce).
 */
final class Token
{
    public const DURACION = 60;
    public const TIPOS = ['sso', 'estado'];

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

    /** Payload en el orden exacto del protocolo. */
    public static function payload(string $tipo, string $inst, string $email, string $nombre, ?int $ahora = null, ?string $nonce = null): array
    {
        if (!in_array($tipo, self::TIPOS, true)) throw new \InvalidArgumentException("Tipo de token no valido: $tipo");
        $ahora ??= time();
        return [
            'v'      => 1,
            'tipo'   => $tipo,
            'inst'   => $inst,
            'email'  => $email,
            'nombre' => $nombre,
            'iat'    => $ahora,
            'exp'    => $ahora + self::DURACION,
            'nonce'  => $nonce ?? bin2hex(random_bytes(16)),
        ];
    }

    public static function crear(string $tipo, string $inst, string $email, string $nombre, string $secreto, ?int $ahora = null): string
    {
        return self::firmar(self::payload($tipo, $inst, $email, $nombre, $ahora), $secreto);
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
