<?php
declare(strict_types=1);

namespace Tukan;

use RuntimeException;

/**
 * Códigos QR en PHP puro (sin TCPDF ni librerías de JavaScript).
 * Modo byte, corrección de errores nivel M, versiones 1 a 20 (hasta 666 bytes: de sobra para otpauth://).
 * Sigue la norma ISO/IEC 18004: Reed-Solomon en GF(256), 8 máscaras con penalización, formato y versión con BCH.
 *   Qr::matriz($texto) -> filas de 0/1 (sin margen)
 *   Qr::svg($texto)    -> <svg> listo para poner en la página
 */
final class Qr
{
    private const MARGEN = 4;

    /** Nivel M por versión: [EC por bloque, bloques grupo 1, datos por bloque g1, bloques g2, datos por bloque g2]. */
    private const BLOQUES_M = [
        1 => [10, 1, 16, 0, 0],   2 => [16, 1, 28, 0, 0],   3 => [26, 1, 44, 0, 0],   4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],   6 => [16, 4, 27, 0, 0],   7 => [18, 4, 31, 0, 0],   8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],  10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
    ];

    private const ALINEACION = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54],
        12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    private int $tam = 0;
    /** @var array<int, array<int, int>> [fila][columna] */
    private array $m = [];
    /** @var array<int, array<int, bool>> módulos fijos (patrones) */
    private array $fijo = [];

    /** Matriz del código (filas de 0 y 1). $mascara = null elige la mejor. */
    public static function matriz(string $texto, ?int $mascara = null): array
    {
        if ($texto === '') throw new RuntimeException('No hay nada que poner en el código QR.');
        return (new self())->generar($texto, $mascara);
    }

    /** Versión que se usará para un texto (1 a 20). */
    public static function version(string $texto): int
    {
        $n = strlen($texto);
        foreach (self::BLOQUES_M as $v => $b) {
            $capacidad = $b[1] * $b[2] + $b[3] * $b[4];
            $bits = 4 + ($v <= 9 ? 8 : 16) + 8 * $n;
            if ($bits <= $capacidad * 8) return $v;
        }
        throw new RuntimeException('El texto es demasiado largo para el código QR.');
    }

    public static function svg(string $texto, ?int $px = 220, string $color = '#14213D', string $titulo = 'Código QR'): string
    {
        $m = self::matriz($texto);
        $n = count($m);
        $lado = $n + 2 * self::MARGEN;
        $d = '';
        foreach ($m as $y => $fila) {
            $x = 0;
            while ($x < $n) {
                if ($fila[$x] === 1) {
                    $ini = $x;
                    while ($x < $n && $fila[$x] === 1) $x++;
                    $d .= 'M' . ($ini + self::MARGEN) . ' ' . ($y + self::MARGEN) . 'h' . ($x - $ini) . 'v1h-' . ($x - $ini) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#14213D';
        $tam = $px ? ' width="' . $px . '" height="' . $px . '"' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '"' . $tam
             . ' shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '">'
             . '<rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/>'
             . '<path fill="' . $color . '" d="' . $d . '"/></svg>';
    }

    /** PNG en blanco y negro (solo para las pruebas; necesita GD). */
    public static function png(string $texto, int $modulo = 8): string
    {
        if (!function_exists('imagecreate')) throw new RuntimeException('Falta GD.');
        $m = self::matriz($texto);
        $n = count($m);
        $lado = ($n + 2 * self::MARGEN) * $modulo;
        $img = imagecreate($lado, $lado);
        imagecolorallocate($img, 255, 255, 255);
        $negro = imagecolorallocate($img, 0, 0, 0);
        foreach ($m as $y => $fila) {
            foreach ($fila as $x => $v) {
                if ($v === 1) {
                    $px = ($x + self::MARGEN) * $modulo;
                    $py = ($y + self::MARGEN) * $modulo;
                    imagefilledrectangle($img, $px, $py, $px + $modulo - 1, $py + $modulo - 1, $negro);
                }
            }
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------

    private function generar(string $texto, ?int $mascaraFija): array
    {
        $v = self::version($texto);
        $this->tam = 17 + 4 * $v;
        $this->m = array_fill(0, $this->tam, array_fill(0, $this->tam, 0));
        $this->fijo = array_fill(0, $this->tam, array_fill(0, $this->tam, false));

        $this->patrones($v);
        $palabras = $this->palabras($texto, $v);
        $this->colocarDatos($palabras);

        $base = $this->m;
        $mejor = null;
        $mejorPuntos = PHP_INT_MAX;
        foreach ($mascaraFija === null ? range(0, 7) : [$mascaraFija] as $k) {
            $this->m = $base;
            $this->aplicarMascara($k);
            $this->formato($k);
            $p = $mascaraFija === null ? $this->penalizacion() : 0;
            if ($p < $mejorPuntos) {
                $mejorPuntos = $p;
                $mejor = $this->m;
            }
        }
        return $mejor;
    }

    private function poner(int $x, int $y, bool $oscuro): void
    {
        $this->m[$y][$x] = $oscuro ? 1 : 0;
        $this->fijo[$y][$x] = true;
    }

    private function patrones(int $v): void
    {
        $t = $this->tam;
        // Líneas de sincronización
        for ($i = 0; $i < $t; $i++) {
            $this->poner(6, $i, $i % 2 === 0);
            $this->poner($i, 6, $i % 2 === 0);
        }
        // Tres cuadros de las esquinas (con su borde blanco)
        foreach ([[3, 3], [$t - 4, 3], [3, $t - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $t || $y >= $t) continue;
                    $dist = max(abs($dx), abs($dy));
                    $this->poner($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
        // Patrones de alineación
        $pos = self::ALINEACION[$v];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->poner($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Reservar las zonas de formato (se escriben de verdad después) y el módulo oscuro
        $this->formato(0);
        // Información de versión (7 en adelante)
        if ($v >= 7) {
            $r = $v;
            for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
            $bits = ($v << 12) | $r;
            for ($i = 0; $i < 18; $i++) {
                $b = (($bits >> $i) & 1) === 1;
                $a = $t - 11 + $i % 3;
                $c = intdiv($i, 3);
                $this->poner($a, $c, $b);
                $this->poner($c, $a, $b);
            }
        }
    }

    /** Bits de formato: nivel M (00) + máscara, con BCH y la máscara 0x5412. */
    private function formato(int $mascara): void
    {
        $datos = (0 << 3) | $mascara;   // M = 0
        $r = $datos;
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $bits = (($datos << 10) | $r) ^ 0x5412;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $t = $this->tam;
        for ($i = 0; $i <= 5; $i++) $this->poner(8, $i, $bit($i));
        $this->poner(8, 7, $bit(6));
        $this->poner(8, 8, $bit(7));
        $this->poner(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->poner(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->poner($t - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->poner(8, $t - 15 + $i, $bit($i));
        $this->poner(8, $t - 8, true);
    }

    /** Datos + corrección de errores, intercalados por bloques. */
    private function palabras(string $texto, int $v): array
    {
        [$ec, $b1, $d1, $b2, $d2] = self::BLOQUES_M[$v];
        $capacidad = $b1 * $d1 + $b2 * $d2;
        $bits = '0100' . str_pad(decbin(strlen($texto)), $v <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($texto) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $bits .= str_repeat('0', min(4, $capacidad * 8 - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        $datos = array_map('bindec', str_split($bits, 8));
        for ($relleno = 0xEC; count($datos) < $capacidad; $relleno ^= 0xEC ^ 0x11) $datos[] = $relleno;

        $divisor = self::rsDivisor($ec);
        $bloques = [];
        $ecs = [];
        $k = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$cuantos, $largo]) {
            for ($i = 0; $i < $cuantos; $i++) {
                $bloque = array_slice($datos, $k, $largo);
                $k += $largo;
                $bloques[] = $bloque;
                $ecs[] = self::rsResto($bloque, $divisor);
            }
        }
        $salida = [];
        $max = max($d1, $d2);
        for ($i = 0; $i < $max; $i++) {
            foreach ($bloques as $b) if ($i < count($b)) $salida[] = $b[$i];
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($ecs as $e) $salida[] = $e[$i];
        }
        return $salida;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $grado): array
    {
        $r = array_fill(0, $grado, 0);
        $r[$grado - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < $grado; $j++) {
                $r[$j] = self::gfMul($r[$j], $raiz);
                if ($j + 1 < $grado) $r[$j] ^= $r[$j + 1];
            }
            $raiz = self::gfMul($raiz, 0x02);
        }
        return $r;
    }

    private static function rsResto(array $datos, array $divisor): array
    {
        $r = array_fill(0, count($divisor), 0);
        foreach ($datos as $b) {
            $f = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $c) $r[$i] ^= self::gfMul($c, $f);
        }
        return $r;
    }

    /** Recorrido en zigzag desde la esquina de abajo a la derecha, de dos en dos columnas. */
    private function colocarDatos(array $palabras): void
    {
        $t = $this->tam;
        $total = count($palabras) * 8;
        $i = 0;
        for ($der = $t - 1; $der >= 1; $der -= 2) {
            if ($der === 6) $der = 5;
            for ($vert = 0; $vert < $t; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $der - $j;
                    $subiendo = (($der + 1) & 2) === 0;
                    $y = $subiendo ? $t - 1 - $vert : $vert;
                    if (!$this->fijo[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = ($palabras[$i >> 3] >> (7 - ($i & 7))) & 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function aplicarMascara(int $k): void
    {
        $t = $this->tam;
        for ($y = 0; $y < $t; $y++) {
            for ($x = 0; $x < $t; $x++) {
                if ($this->fijo[$y][$x]) continue;
                $inv = match ($k) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($inv) $this->m[$y][$x] ^= 1;
            }
        }
    }

    /** Penalización de la norma (reglas 1 a 4): la máscara con menos puntos se lee mejor. */
    private function penalizacion(): int
    {
        $t = $this->tam;
        $p = 0;
        $filas = $this->m;
        $columnas = [];
        for ($x = 0; $x < $t; $x++) $columnas[] = array_column($filas, $x);
        foreach ([$filas, $columnas] as $lineas) {
            foreach ($lineas as $l) {
                // Regla 1: 5 o más iguales seguidos
                $run = 1;
                for ($i = 1; $i < $t; $i++) {
                    if ($l[$i] === $l[$i - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) $p += 3 + $run - 5;
                        $run = 1;
                    }
                }
                if ($run >= 5) $p += 3 + $run - 5;
                // Regla 3: patrón parecido a los cuadros de las esquinas
                $s = implode('', $l);
                foreach (['10111010000', '00001011101'] as $patron) {
                    $off = 0;
                    while (($pos = strpos($s, $patron, $off)) !== false) {
                        $p += 40;
                        $off = $pos + 1;
                    }
                }
            }
        }
        // Regla 2: cuadros de 2x2 del mismo color
        $oscuros = 0;
        for ($y = 0; $y < $t; $y++) {
            for ($x = 0; $x < $t; $x++) {
                $oscuros += $filas[$y][$x];
                if ($x < $t - 1 && $y < $t - 1) {
                    $c = $filas[$y][$x];
                    if ($c === $filas[$y][$x + 1] && $c === $filas[$y + 1][$x] && $c === $filas[$y + 1][$x + 1]) $p += 3;
                }
            }
        }
        // Regla 4: proporción de oscuros lejos del 50 %
        $pct = $oscuros * 100 / ($t * $t);
        $p += (int) (floor(abs($pct - 50) / 5) * 10);
        return $p;
    }
}
