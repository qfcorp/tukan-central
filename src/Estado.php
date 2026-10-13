<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Respuesta de "estado" de un programa (docs/PROTOCOLO.md §5): revisión estricta y limpieza,
 * y traducción de los errores de conexión a palabras simples.
 */
final class Estado
{
    public const CLAVES_NEGOCIOS = ['total', 'prueba', 'activos', 'por_vencer', 'vencidos', 'suspendidos'];
    public const MAX_LISTA = 20;
    public const MAX_EXTRA = 8;

    /**
     * Revisa el JSON. Devuelve los datos limpios o lanza EstadoInvalido con el motivo.
     * @return array{producto:string,nombre:string,version:?string,generado:?string,negocios:?array,nuevos_30d:?int,
     *   ingresos_mes:?float,ingresos_mes_anterior:?float,pruebas_vencen_7d:array,pagos_vencidos:array,extra:array,cliente:?string}
     */
    public static function validar(string $json): array
    {
        if (strlen($json) > 512 * 1024) throw new EstadoInvalido('la respuesta es demasiado grande');
        try {
            $d = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new EstadoInvalido('la respuesta no es JSON válido');
        }
        if (!is_array($d) || array_is_list($d)) throw new EstadoInvalido('la respuesta no es un objeto JSON');
        if (($d['ok'] ?? null) !== true) {
            $err = is_string($d['error'] ?? null) ? ': ' . mb_substr($d['error'], 0, 120) : '';
            throw new EstadoInvalido('el programa respondió ok=false' . $err);
        }
        if (($d['protocolo'] ?? null) !== 1) throw new EstadoInvalido('falta "protocolo": 1 o es otra versión');
        $producto = $d['producto'] ?? null;
        if (!is_string($producto) || !preg_match('/^[a-z0-9_-]{2,40}$/', $producto)) throw new EstadoInvalido('falta "producto"');

        $negocios = null;
        if (array_key_exists('negocios', $d) && $d['negocios'] !== null) {
            if (!is_array($d['negocios']) || (array_is_list($d['negocios']) && $d['negocios'] !== [])) throw new EstadoInvalido('"negocios" debe ser un objeto o null');
            $negocios = [];
            foreach (self::CLAVES_NEGOCIOS as $k) {
                $negocios[$k] = self::entero($d['negocios'][$k] ?? null, "negocios.$k");
            }
        }

        return [
            'producto'              => $producto,
            'nombre'                => self::texto($d['nombre'] ?? null, 60) ?? Productos::nombre($producto),
            'version'               => self::texto($d['version'] ?? null, 40),
            'generado'              => self::texto($d['generado'] ?? null, 40),
            'negocios'              => $negocios,
            'nuevos_30d'            => self::entero($d['nuevos_30d'] ?? null, 'nuevos_30d'),
            'ingresos_mes'          => self::numero($d['ingresos_mes'] ?? null, 'ingresos_mes'),
            'ingresos_mes_anterior' => self::numero($d['ingresos_mes_anterior'] ?? null, 'ingresos_mes_anterior'),
            'pruebas_vencen_7d'     => self::lista($d['pruebas_vencen_7d'] ?? null, 'vence', 'pruebas_vencen_7d'),
            'pagos_vencidos'        => self::lista($d['pagos_vencidos'] ?? null, 'desde', 'pagos_vencidos'),
            'extra'                 => self::extra($d['extra'] ?? null),
            'cliente'               => self::texto($d['cliente'] ?? null, 80),
        ];
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if ($v === null) return null;
        if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function entero(mixed $v, string $campo): ?int
    {
        if ($v === null) return null;
        if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) $v = (int) $v;
        if (is_float($v) && floor($v) === $v) $v = (int) $v;
        if (!is_int($v) || $v < 0 || $v > 100000000) throw new EstadoInvalido("\"$campo\" debe ser un número entero");
        return $v;
    }

    private static function numero(mixed $v, string $campo): ?float
    {
        if ($v === null) return null;
        if (is_string($v) && is_numeric($v)) $v = (float) $v;
        if (!is_int($v) && !is_float($v)) throw new EstadoInvalido("\"$campo\" debe ser un número");
        $v = (float) $v;
        if (!is_finite($v) || abs($v) > 1e10) throw new EstadoInvalido("\"$campo\" está fuera de rango");
        return round($v, 2);
    }

    private static function lista(mixed $v, string $campoFecha, string $campo): array
    {
        if ($v === null) return [];
        if (!is_array($v) || (!array_is_list($v))) throw new EstadoInvalido("\"$campo\" debe ser una lista");
        $salida = [];
        foreach (array_slice($v, 0, self::MAX_LISTA) as $item) {
            if (!is_array($item)) throw new EstadoInvalido("\"$campo\" tiene un elemento que no es objeto");
            $nombre = self::texto($item['nombre'] ?? null, 80);
            if ($nombre === null) continue;
            $fecha = self::texto($item[$campoFecha] ?? null, 25);
            if ($fecha !== null) {
                $fecha = substr($fecha, 0, 10);
                if (!\DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)) $fecha = null;
            }
            $salida[] = ['nombre' => $nombre, $campoFecha => $fecha];
        }
        return $salida;
    }

    private static function extra(mixed $v): array
    {
        if ($v === null || $v === []) return [];
        if (!is_array($v) || array_is_list($v)) throw new EstadoInvalido('"extra" debe ser un objeto');
        $salida = [];
        foreach ($v as $k => $valor) {
            if (count($salida) >= self::MAX_EXTRA) break;
            $k = self::texto((string) $k, 40);
            if ($k === null) continue;
            if (is_bool($valor)) $valor = $valor ? 'Sí' : 'No';
            if (is_int($valor) || is_float($valor)) {
                $salida[$k] = $valor;
            } elseif (is_string($valor) || $valor === null) {
                $salida[$k] = self::texto($valor, 80) ?? '—';
            }
        }
        return $salida;
    }

    // ------------------------------------------------------------- errores

    public const ERRORES = [
        'dns'          => 'No se encuentra esa dirección. Revisa que la URL del programa esté bien escrita.',
        'sin_conexion' => 'Sin conexión: el servidor del programa no contesta (puede estar apagado o sin internet).',
        'tiempo'       => 'El programa no respondió a tiempo (más de 5 segundos). Puede estar caído o muy lento.',
        'tls'          => 'Problema con el candado https (certificado) del programa.',
        'no_conectado' => 'Programa sin conectar: el servidor responde, pero el conector está apagado o no existe (404). Pega el comando en el servidor del programa.',
        'secreto'      => 'Secreto incorrecto: el programa no reconoce la firma. Toca "Cambiar secreto" y pega el comando nuevo en su servidor.',
        'id'           => 'El ID no coincide con el que tiene guardado el programa. Revisa el ID o vuelve a pegar el comando.',
        'reloj'        => 'Reloj desfasado: la hora del servidor del programa (o de este panel) está mal. Hay que corregir la hora del servidor.',
        'repetido'     => 'El programa dice que el token ya se usó. Intenta otra vez.',
        'rechazado'    => 'El programa rechazó el pedido.',
        'limite'       => 'Demasiados pedidos seguidos: el programa pide esperar un minuto. Espera un minuto y vuelve a probar.',
        'peticion'     => 'El programa no entendió el pedido (HTTP 400). Puede que su conector esté desactualizado: actualiza ese programa.',
        'version'      => 'El programa usa otra versión del Protocolo Tukán Central. Actualiza ese programa.',
        'sin_configurar' => 'El conector del programa está apagado o sin configurar. Pega el comando en el servidor del programa.',
        'error_programa' => 'El programa tuvo un error interno. Revisa el programa.',
        'redirige'     => 'El programa respondió con una redirección. Revisa que la URL sea la correcta (con https y sin rutas de más).',
        'formato'      => 'El programa respondió, pero los datos no tienen el formato del Protocolo Tukán Central.',
        'otro'         => 'No se pudo consultar el programa.',
        'secreto_ilegible' => 'No se puede leer el secreto guardado (la llave del panel cambió). Toca "Cambiar secreto".',
    ];

    /**
     * Traduce lo que pasó a un código y un texto simple.
     * @param int $http código HTTP (0 si no hubo respuesta)
     * @param ?int $desfase segundos de diferencia entre el reloj del programa (cabecera Date) y el nuestro
     * @return array{0:string,1:string} [codigo, texto]
     */
    public static function clasificar(int $http, string $cuerpo, int $curlErrno = 0, string $curlError = '', ?int $desfase = null): array
    {
        if ($curlErrno !== 0 || $http === 0) {
            $codigo = match (true) {
                $curlErrno === 6 => 'dns',
                $curlErrno === 28 => 'tiempo',
                in_array($curlErrno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => 'tls',
                in_array($curlErrno, [7, 52, 55, 56], true) => 'sin_conexion',
                default => 'sin_conexion',
            };
            return [$codigo, self::ERRORES[$codigo] . ($curlError !== '' && $codigo === 'tls' ? " ($curlError)" : '')];
        }
        $msg = '';
        $j = json_decode($cuerpo, true);
        if (is_array($j) && is_string($j['error'] ?? null)) $msg = mb_substr(trim($j['error']), 0, 150);
        $dijo = $msg !== '' ? ' El programa dijo: "' . $msg . '".' : '';

        if ($http === 404) return ['no_conectado', self::ERRORES['no_conectado']];
        if ($http === 429) return ['limite', self::ERRORES['limite']];
        if ($http === 400) return ['peticion', self::ERRORES['peticion'] . $dijo];
        if ($http >= 300 && $http < 400) return ['redirige', self::ERRORES['redirige']];
        if ($http >= 500) return ['error_programa', self::ERRORES['error_programa'] . " (HTTP $http)$dijo"];
        if ($http === 401 || $http === 403) {
            if ($desfase !== null && abs($desfase) > 30) {
                return ['reloj', self::ERRORES['reloj'] . ' Diferencia: ' . self::segundos($desfase) . '.'];
            }
            $m = mb_strtolower($msg);
            // Los conectores no mandan un código fijo, solo texto: se reconoce el motivo por palabras.
            // El orden importa (ej. "nonce ya usado" es repetido, no un nonce mal formado).
            $codigo = match (true) {
                $m === '' => 'rechazado',
                (bool) preg_match('/demasiados pedidos|too many|rate limit/u', $m) => 'limite',
                (bool) preg_match('/firma|signature|secret|hmac/u', $m) => 'secreto',
                (bool) preg_match('/ya se us|usad|reus|repet|replay/u', $m) => 'repetido',
                (bool) preg_match('/versi[oó]n/u', $m) => 'version',
                (bool) preg_match('/no est[aá] configurad|sin configurar|not configured/u', $m) => 'sin_configurar',
                (bool) preg_match('/venc|expir|futur|reloj|hora|tiempo|time|\\biat\\b|\\bexp\\b/u', $m) => 'reloj',
                (bool) preg_match('/\\binst\\b|instancia|instalaci|\\bid\\b/u', $m) => 'id',
                default => 'rechazado',
            };
            return [$codigo, self::ERRORES[$codigo] . ($codigo === 'rechazado' || $codigo === 'reloj' ? $dijo : '')];
        }
        if ($http !== 200) return ['otro', self::ERRORES['otro'] . " (HTTP $http)$dijo"];
        return ['formato', self::ERRORES['formato']];
    }

    public static function segundos(int $s): string
    {
        $a = abs($s);
        if ($a < 120) return "$a segundos";
        if ($a < 7200) return round($a / 60) . ' minutos';
        return round($a / 3600, 1) . ' horas';
    }
}
