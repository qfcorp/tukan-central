<?php
declare(strict_types=1);

namespace Tukan;

use InvalidArgumentException;

/**
 * Tipos de programa de la familia Tukán.
 * Para agregar uno nuevo (ej. la veterinaria) basta con sumar una entrada aquí:
 *   nombre  = cómo se ve en el panel
 *   color   = color del producto (tarjetas e icono)
 *   letra   = letra del icono
 *   para    = para quién es (texto corto)
 *   comando = comando que se pega en el servidor donde vive el programa para conectarlo.
 *             Marcadores: {ID} {SECRETO} {CARPETA} {URL} (URL = dirección de este panel).
 *   unico   = true si cada instalación es de un solo cliente (marca blanca, como TuGuardería)
 */
final class Productos
{
    public const TIPOS = [
        'tuevento' => [
            'nombre' => 'TuEvento', 'color' => '#8E3B52', 'letra' => 'E', 'unico' => false,
            'para' => 'Salones de eventos, quintas y haciendas',
            'comando' => 'sudo bash /var/www/tuevento/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tuplanner' => [
            'nombre' => 'TuPlanner', 'color' => '#8A3F5C', 'letra' => 'P', 'unico' => false,
            'para' => 'Wedding planners y organizadores de eventos',
            'comando' => 'sudo bash /var/www/tuplanner/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tusalon' => [
            'nombre' => 'TuSalón', 'color' => '#E0457B', 'letra' => 'S', 'unico' => false,
            'para' => 'Peluquerías y barberías',
            'comando' => 'sudo bash /var/www/tusalon/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tuvocalia' => [
            'nombre' => 'TuVocalía', 'color' => '#0E9AA7', 'letra' => 'V', 'unico' => false,
            'para' => 'Ligas y campeonatos deportivos',
            'comando' => 'sudo bash /var/www/ligas/vps/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tuacademia' => [
            'nombre' => 'TuAcademia', 'color' => '#3B6FE0', 'letra' => 'A', 'unico' => false,
            'para' => 'Academias de baile, música, deportes y natación',
            'comando' => 'sudo bash /var/www/tuacademia/vps/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tuguarderia' => [
            'nombre' => 'TuGuardería Canina', 'color' => '#2E9E5B', 'letra' => 'G', 'unico' => true,
            'para' => 'Guarderías y hoteles de perros (una instalación por cliente)',
            'comando' => 'sudo bash /home/qf/github/tuguarderia/conectar_central.sh {CARPETA} {ID} {SECRETO} {URL}',
        ],
        'tuhospedaje' => [
            'nombre' => 'TuHospedaje', 'color' => '#14254A', 'letra' => 'H', 'unico' => false,
            'para' => 'Hoteles, hostales y hosterías',
            'comando' => 'sudo bash /var/www/tuhospedaje/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'turemolque' => [
            'nombre' => 'TuRemolque', 'color' => '#CB1F26', 'letra' => 'R', 'unico' => true,
            'para' => 'Fábricas de remolques y carrocerías (una instalación por cliente)',
            'comando' => 'sudo bash /var/www/turemolque/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'erp_productoras' => [
            'nombre' => 'ERP para productoras', 'color' => '#3B6FE0', 'letra' => 'Q', 'unico' => true,
            'para' => 'Productoras de eventos y transmisiones (una instalación por cliente)',
            'comando' => 'sudo bash /var/www/erp-productoras/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        'tupass' => [
            'nombre' => 'TuPass', 'color' => '#7C4DFF', 'letra' => 'T', 'unico' => true,
            'para' => 'Venta de eventos en vivo por internet (PPV)',
            'comando' => 'sudo bash /var/www/tupass/deploy/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
        // Ejemplo para el futuro:
        // 'tuvet' => ['nombre' => 'TuVet', 'color' => '#C2410C', 'letra' => 'P', 'unico' => false,
        //             'para' => 'Veterinarias', 'comando' => 'sudo bash /var/www/tuvet/deploy/conectar_central.sh {ID} {SECRETO} {URL}'],
        'otro' => [
            'nombre' => 'Otro programa', 'color' => '#5A6378', 'letra' => 'T', 'unico' => false,
            'para' => 'Cualquier otro programa que use el Protocolo Tukán Central',
            'comando' => 'sudo bash /ruta/del/programa/conectar_central.sh {ID} {SECRETO} {URL}',
        ],
    ];

    public const MARCADORES = ['{ID}', '{SECRETO}', '{CARPETA}', '{URL}'];

    public static function existe(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]);
    }

    public static function tipo(string $tipo): array
    {
        return self::TIPOS[$tipo] ?? self::TIPOS['otro'];
    }

    public static function nombre(string $tipo): string
    {
        return self::tipo($tipo)['nombre'];
    }

    /** Hoja de estilos con una clase por producto (.p-tuevento { --c: ... }): así no hay estilos en línea. */
    public static function css(): string
    {
        $css = '';
        foreach (self::TIPOS as $k => $t) {
            $css .= '.p-' . $k . '{--c:' . $t['color'] . '}' . "\n";
        }
        return $css;
    }

    /** ¿La plantilla necesita la carpeta? */
    public static function pideCarpeta(string $plantilla): bool
    {
        return str_contains($plantilla, '{CARPETA}');
    }

    /** Revisa una plantilla escrita a mano. Devuelve el texto de error o null si está bien. */
    public static function errorPlantilla(string $plantilla): ?string
    {
        if (trim($plantilla) === '') return 'Escribe el comando.';
        if (!str_contains($plantilla, '{SECRETO}')) return 'El comando debe tener {SECRETO}.';
        if (!str_contains($plantilla, '{ID}')) return 'El comando debe tener {ID}.';
        if (preg_match_all('/\{[A-Za-z_]+\}/', $plantilla, $m)) {
            foreach ($m[0] as $marca) {
                if (!in_array($marca, self::MARCADORES, true)) return "No conozco el marcador $marca. Usa solo {ID}, {SECRETO}, {CARPETA} y {URL}.";
            }
        }
        if (preg_match('/[^\x20-\x7E]/', $plantilla)) return 'El comando no puede tener tildes, ñ ni saltos de línea (la app de SSH del celular los daña).';
        if (preg_match('/[;&|`$<>\\\\]/', str_replace(self::MARCADORES, '', $plantilla))) return 'El comando no puede tener ; & | ` $ < > ni \\ (por seguridad).';
        return null;
    }

    /**
     * Arma el comando final. Todos los valores se validan para que sea seguro pegarlo en la terminal.
     * @param array{ID:string,SECRETO:string,URL:string,CARPETA?:?string} $v
     */
    public static function comando(string $plantilla, array $v): string
    {
        if (($e = self::errorPlantilla($plantilla)) !== null) throw new InvalidArgumentException($e);
        if (!Instancias::idValido($v['ID'] ?? '')) throw new InvalidArgumentException('ID no válido.');
        if (!preg_match('/^[0-9a-f]{64}$/', $v['SECRETO'] ?? '')) throw new InvalidArgumentException('Secreto no válido.');
        if (!preg_match('#^https?://[A-Za-z0-9.:\-]+(/[A-Za-z0-9._~/\-]*)?$#', $v['URL'] ?? '')) throw new InvalidArgumentException('La dirección del panel (TUKAN_CENTRAL_URL) no es válida.');
        $carpeta = (string) ($v['CARPETA'] ?? '');
        if (self::pideCarpeta($plantilla)) {
            if ($carpeta === '') throw new InvalidArgumentException('Este programa necesita la carpeta de la instalación.');
            if (!self::carpetaValida($carpeta)) throw new InvalidArgumentException('La carpeta solo puede tener minúsculas, números y _ (ej. dogssy).');
        }
        $salida = strtr($plantilla, ['{ID}' => $v['ID'], '{SECRETO}' => $v['SECRETO'], '{URL}' => $v['URL'], '{CARPETA}' => $carpeta]);
        if (preg_match('/[^\x20-\x7E]/', $salida)) throw new InvalidArgumentException('El comando quedó con letras especiales.');
        return $salida;
    }

    /** Carpeta de TuGuardería: nombre corto del cliente (igual que su instalador). */
    public static function carpetaValida(string $c): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,30}$/', $c);
    }
}
