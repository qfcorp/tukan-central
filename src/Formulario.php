<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Formularios genéricos del protocolo v2 (§4.4): cada programa dice qué botones mostrar (acciones_posibles)
 * y los campos de cada uno. Aquí se revisan (nunca se confía en lo que llega) y se leen los valores del POST.
 */
final class Formulario
{
    public const TIPOS = ['texto', 'texto_largo', 'numero', 'dinero', 'fecha', 'dias', 'opcion', 'si_no', 'telefono', 'email'];
    public const ESTILOS = ['principal', 'normal', 'peligro'];
    /** Acciones que hace el Central solo (no van a "ejecutar"). */
    public const LOCALES = ['whatsapp', 'abrir'];

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
        $v = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function nombreValido(mixed $n): bool
    {
        return is_string($n) && (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $n);
    }

    /** Ruta para "ir" (§5.3): empieza con "/" (no "//"), sin "\" ni caracteres de control, máximo 300. */
    public static function rutaValida(mixed $ir): bool
    {
        return is_string($ir) && strlen($ir) <= 300 && str_starts_with($ir, '/') && !str_starts_with($ir, '//')
            && !str_contains($ir, '\\') && (bool) preg_match("~^/[A-Za-z0-9._\~!$&'()*+,;=:@%/?#-]*$~", $ir);
    }

    /** @return list<array> campos revisados (los que no se entienden se descartan) */
    public static function limpiarCampos(mixed $campos): array
    {
        if (!is_array($campos) || !array_is_list($campos)) return [];
        $salida = [];
        foreach (array_slice($campos, 0, 30) as $c) {
            if (!is_array($c) || !self::nombreValido($c['campo'] ?? null)) continue;
            $tipo = in_array($c['tipo'] ?? null, self::TIPOS, true) ? $c['tipo'] : 'texto';
            $f = [
                'campo' => $c['campo'],
                'etiqueta' => self::texto($c['etiqueta'] ?? null, 80) ?? $c['campo'],
                'tipo' => $tipo,
                'requerido' => ($c['requerido'] ?? false) === true,
                'valor' => null,
                'min' => is_int($c['min'] ?? null) || is_float($c['min'] ?? null) ? $c['min'] : null,
                'max' => is_int($c['max'] ?? null) || is_float($c['max'] ?? null) ? $c['max'] : null,
                'opciones' => [],
                'ayuda' => self::texto($c['ayuda'] ?? null, 200),
                'cotizar' => ($c['cotizar'] ?? false) === true,
            ];
            $v = $c['valor'] ?? null;
            if (is_bool($v)) $f['valor'] = $v;
            elseif (is_int($v) || is_float($v)) $f['valor'] = $v;
            elseif (is_string($v)) $f['valor'] = mb_substr($v, 0, 6000);
            if (is_array($c['opciones'] ?? null) && array_is_list($c['opciones'])) {
                foreach (array_slice($c['opciones'], 0, 100) as $o) {
                    if (!is_array($o)) continue;
                    $val = $o['valor'] ?? null;
                    if (!is_string($val) && !is_int($val)) continue;
                    $f['opciones'][] = ['valor' => (string) $val, 'etiqueta' => self::texto($o['etiqueta'] ?? null, 100) ?? (string) $val];
                }
            }
            if ($tipo === 'opcion' && !$f['opciones']) $f['tipo'] = 'texto';
            $salida[] = $f;
        }
        return $salida;
    }

    /** Una de acciones_posibles, revisada. null si no sirve. */
    public static function limpiarAccion(mixed $a): ?array
    {
        if (!is_array($a) || !is_string($a['accion'] ?? null) || !preg_match('/^[a-z][a-z_]{1,39}$/', $a['accion'])) return null;
        $r = [
            'accion' => $a['accion'],
            'etiqueta' => self::texto($a['etiqueta'] ?? null, 60) ?? ucfirst(str_replace('_', ' ', $a['accion'])),
            'estilo' => in_array($a['estilo'] ?? null, self::ESTILOS, true) ? $a['estilo'] : 'normal',
            'confirmar' => self::texto($a['confirmar'] ?? null, 300),
            'datos' => [],
            'campos' => self::limpiarCampos($a['campos'] ?? []),
        ];
        if (is_array($a['datos'] ?? null)) {
            foreach ($a['datos'] as $k => $v) {
                if (!self::nombreValido($k)) continue;
                if (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null) $r['datos'][$k] = is_string($v) ? mb_substr($v, 0, 300) : $v;
            }
        }
        if ($r['accion'] === 'whatsapp') {
            $wa = preg_replace('/\D+/', '', (string) ($a['whatsapp'] ?? ''));
            if (strlen($wa) < 8 || strlen($wa) > 15) return null;
            $r['whatsapp'] = $wa;
            $r['texto'] = is_string($a['texto'] ?? null) ? mb_substr($a['texto'], 0, 3000) : '';
        }
        if ($r['accion'] === 'abrir') {
            if (!self::rutaValida($a['ir'] ?? null)) return null;
            $r['ir'] = $a['ir'];
        }
        return $r;
    }

    /** @return list<array> */
    public static function limpiarAcciones(mixed $lista): array
    {
        if (!is_array($lista) || !array_is_list($lista)) return [];
        return array_values(array_filter(array_map([self::class, 'limpiarAccion'], array_slice($lista, 0, 20))));
    }

    /** Número escrito a la ecuatoriana ("39,50", "1.234,50") o con punto. */
    public static function numero(string $v): ?float
    {
        $v = trim(str_replace(['$', ' '], '', $v));
        if ($v === '') return null;
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v);
        elseif (str_contains($v, ',') && !str_contains($v, '.')) $v = str_replace(',', '.', $v);
        elseif (str_contains($v, ',')) $v = str_replace(',', '', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Lee los valores de los campos desde el POST (campo[nombre]) con su tipo.
     * @return array{0: array, 1: array<string,string>} [datos, errores por campo]
     */
    public static function leer(array $campos, array $post): array
    {
        $datos = [];
        $errores = [];
        foreach ($campos as $c) {
            $n = $c['campo'];
            $crudo = $post[$n] ?? null;
            if (is_array($crudo)) $crudo = null;
            $crudo = is_string($crudo) ? trim($crudo) : null;
            if ($c['tipo'] === 'si_no') {
                $datos[$n] = $crudo === '1';
                continue;
            }
            if ($crudo === null || $crudo === '') {
                if ($c['requerido']) $errores[$n] = 'Llena este campo.';
                continue;
            }
            switch ($c['tipo']) {
                case 'dinero':
                case 'numero':
                    $x = self::numero($crudo);
                    if ($x === null) { $errores[$n] = $c['tipo'] === 'dinero' ? 'Escribe el valor con números, por ejemplo 39,00.' : 'Escribe un número.'; break; }
                    if ($c['min'] !== null && $x < $c['min']) { $errores[$n] = 'Debe ser al menos ' . str_replace('.', ',', (string) $c['min']) . '.'; break; }
                    if ($c['max'] !== null && $x > $c['max']) { $errores[$n] = 'Debe ser máximo ' . str_replace('.', ',', (string) $c['max']) . '.'; break; }
                    $datos[$n] = $c['tipo'] === 'dinero' ? round($x, 2) : (floor($x) === $x && abs($x) < 1e9 ? (int) $x : $x);
                    break;
                case 'dias':
                    if (!preg_match('/^\d{1,4}$/', $crudo)) { $errores[$n] = 'Escribe el número de días.'; break; }
                    $x = (int) $crudo;
                    if (($c['min'] !== null && $x < $c['min']) || ($c['max'] !== null && $x > $c['max'])) { $errores[$n] = 'Entre ' . ($c['min'] ?? 1) . ' y ' . ($c['max'] ?? 365) . ' días.'; break; }
                    $datos[$n] = $x;
                    break;
                case 'fecha':
                    if (!\DateTimeImmutable::createFromFormat('!Y-m-d', $crudo)) { $errores[$n] = 'Elige una fecha.'; break; }
                    $datos[$n] = $crudo;
                    break;
                case 'opcion':
                    if (!in_array($crudo, array_column($c['opciones'], 'valor'), true)) { $errores[$n] = 'Elige una opción de la lista.'; break; }
                    $datos[$n] = $crudo;
                    break;
                case 'email':
                    if (!filter_var($crudo, FILTER_VALIDATE_EMAIL)) { $errores[$n] = 'El correo no es válido.'; break; }
                    $datos[$n] = mb_strtolower($crudo);
                    break;
                default:
                    $max = is_int($c['max']) ? $c['max'] : ($c['tipo'] === 'texto_largo' ? 6000 : 300);
                    $min = is_int($c['min']) ? $c['min'] : 0;
                    if (mb_strlen($crudo) < $min) { $errores[$n] = "Escribe al menos $min letras."; break; }
                    if (mb_strlen($crudo) > $max) { $errores[$n] = "Máximo $max letras."; break; }
                    $datos[$n] = $crudo;
            }
        }
        return [$datos, $errores];
    }
}
