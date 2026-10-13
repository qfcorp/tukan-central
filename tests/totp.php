<?php
declare(strict_types=1);
// Para las pruebas: imprime el código TOTP de un secreto base32 para un paso dado (o el actual).
//   php tests/totp.php SECRETO [PASO]
require dirname(__DIR__) . '/src/Totp.php';
$s = (string) ($argv[1] ?? '');
$paso = isset($argv[2]) ? (int) $argv[2] : Tukan\Totp::paso();
echo Tukan\Totp::hotp((string) Tukan\Totp::desdeBase32($s), $paso), "\n";
