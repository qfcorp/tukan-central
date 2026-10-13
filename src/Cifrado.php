<?php
declare(strict_types=1);

namespace Tukan;

use RuntimeException;

/**
 * Cifrado de los secretos guardados en la base (secretos de los programas y de 2FA).
 * XChaCha20-Poly1305 (libsodium) con la llave TUKAN_CENTRAL_KEY de config/.env (64 hex = 32 bytes).
 * Cada secreto va "atado" a su contexto (ej. "instancia:tuevento"): no se puede copiar el texto cifrado de
 * una fila a otra. Formato guardado: "v1:" + base64(nonce de 24 bytes + texto cifrado).
 */
final class Cifrado
{
    private string $llave;

    public function __construct(?string $llaveHex = null)
    {
        $llaveHex ??= (string) getenv('TUKAN_CENTRAL_KEY');
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $llaveHex)) {
            throw new RuntimeException('Falta TUKAN_CENTRAL_KEY (64 caracteres hex) en config/.env. Corre otra vez el instalador.');
        }
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('Falta la extension sodium de PHP.');
        }
        $this->llave = (string) hex2bin($llaveHex);
    }

    public static function nuevaLlave(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function cifrar(string $texto, string $contexto): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $c = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($texto, $contexto, $nonce, $this->llave);
        return 'v1:' . base64_encode($nonce . $c);
    }

    public function descifrar(string $guardado, string $contexto): string
    {
        if (!str_starts_with($guardado, 'v1:')) throw new RuntimeException('Secreto guardado con un formato desconocido.');
        $bin = base64_decode(substr($guardado, 3), true);
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($bin === false || strlen($bin) <= $n) throw new RuntimeException('Secreto guardado dañado.');
        $t = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bin, $n), $contexto, substr($bin, 0, $n), $this->llave);
        if ($t === false) {
            throw new RuntimeException('No se pudo leer un secreto guardado (la llave TUKAN_CENTRAL_KEY cambio o el dato fue alterado).');
        }
        return $t;
    }
}
