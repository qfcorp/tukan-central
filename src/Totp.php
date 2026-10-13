<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Códigos de 6 dígitos que cambian cada 30 segundos (TOTP, RFC 6238 / RFC 4226).
 * Funciona con Google Authenticator, Microsoft Authenticator, Authy, 2FAS, etc.
 * Hecho a mano, sin librerías.
 */
final class Totp
{
    public const PERIODO = 30;
    public const DIGITOS = 6;
    private const ABC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo: 20 bytes al azar (160 bits) en base32 = 32 letras. */
    public static function nuevoSecreto(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ABC[bindec(str_pad($grupo, 5, '0'))];
        }
        return $bytes === '' ? '' : $salida;
    }

    /** Base32 -> bytes. Acepta minúsculas, espacios y guiones. Devuelve null si tiene letras no válidas. */
    public static function desdeBase32(string $texto): ?string
    {
        $texto = strtoupper(preg_replace('/[\s=-]+/', '', $texto) ?? '');
        $bits = '';
        for ($i = 0, $n = strlen($texto); $i < $n; $i++) {
            $v = strpos(self::ABC, $texto[$i]);
            if ($v === false) return null;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) $bytes .= chr(bindec($b));
        }
        return $bytes;
    }

    /** Paso de tiempo (contador) para un momento dado. */
    public static function paso(?int $momento = null): int
    {
        return intdiv($momento ?? time(), self::PERIODO);
    }

    /** HOTP (RFC 4226) con la clave en bytes. */
    public static function hotp(string $clave, int $contador, int $digitos = self::DIGITOS, string $algoritmo = 'sha1'): string
    {
        $mensaje = pack('J', $contador);   // 8 bytes, big endian
        $h = hash_hmac($algoritmo, $mensaje, $clave, true);
        $o = ord($h[strlen($h) - 1]) & 0x0F;
        $num = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($num % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /** Código TOTP para un secreto base32 (o clave en bytes con $esBase32=false). */
    public static function codigo(string $secreto, ?int $momento = null, int $digitos = self::DIGITOS, string $algoritmo = 'sha1', bool $esBase32 = true): string
    {
        $clave = $esBase32 ? (self::desdeBase32($secreto) ?? '') : $secreto;
        return self::hotp($clave, self::paso($momento), $digitos, $algoritmo);
    }

    /**
     * Verifica un código con tolerancia de ±1 paso (±30 s).
     * Devuelve el paso que coincidió (para no aceptar el mismo código dos veces) o null.
     * $ultimoPaso: el último paso ya usado; solo se aceptan pasos mayores.
     */
    public static function verificar(string $secreto, string $codigo, ?int $momento = null, int $ultimoPaso = 0, int $ventana = 1): ?int
    {
        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        if (strlen($codigo) !== self::DIGITOS) return null;
        $clave = self::desdeBase32($secreto);
        if ($clave === null || $clave === '') return null;
        $actual = self::paso($momento);
        $encontrado = null;
        for ($d = -$ventana; $d <= $ventana; $d++) {
            $p = $actual + $d;
            // Se revisan todos (sin cortar antes) para que el tiempo de respuesta no dé pistas
            if (hash_equals(self::hotp($clave, $p), $codigo) && $p > $ultimoPaso && $encontrado === null) {
                $encontrado = $p;
            }
        }
        return $encontrado;
    }

    /** Dirección otpauth:// para el código QR. */
    public static function uri(string $secreto, string $cuenta, string $emisor = 'Tukán Central'): string
    {
        return 'otpauth://totp/' . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
             . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor)
             . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PERIODO;
    }

    /** Secreto en grupos de 4 para escribirlo a mano: "ABCD EFGH ...". */
    public static function agrupado(string $secreto): string
    {
        return trim(chunk_split($secreto, 4, ' '));
    }
}
