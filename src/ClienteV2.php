<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Pedidos v2 a un programa (docs/PROTOCOLO_V2.md §2): POST con el cuerpo JSON firmado (hash "bh" en el token),
 * token en la cabecera X-Tukan-Token, tiempos de §2.1 y errores con "codigo" (§3).
 * El cuerpo que se firma es exactamente el que se manda.
 */
final class ClienteV2
{
    public const EMAIL_REVISION = 'revision@panel.tukanec.com';

    /** @var callable|null fn(string $url, array $cabeceras, string $cuerpo, int $tiempo, int $max): array{http:int,cuerpo:string,errno:int,error:string,cabeceras:array,ms:int} */
    private $transporte;

    public function __construct(private Instancias $instancias, ?callable $transporte = null)
    {
        $this->transporte = $transporte;
    }

    public static function tiempo(string $accion): int
    {
        return match ($accion) {
            'ejecutar' => 15,
            'adjunto', 'finanzas' => 20,
            default => 5,
        };
    }

    public static function maximo(string $accion): int
    {
        return $accion === 'adjunto' ? 8 * 1024 * 1024 : 2 * 1024 * 1024;
    }

    /** JSON exacto que viaja (objeto vacío = "{}"). */
    public static function json(array $cuerpo): string
    {
        if ($cuerpo === []) return '{}';
        return json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Hace el pedido y devuelve la respuesta (ya revisada: ok=true, protocolo 2) o lanza ErrorPrograma.
     * $usuario: el del panel que pide (email, nombre, rol); null = la revisión automática.
     */
    public function pedir(array $inst, string $accion, array $cuerpo = [], ?array $usuario = null): array
    {
        if (!in_array($accion, Token::TIPOS_V2, true)) throw new \InvalidArgumentException("Accion v2 no valida: $accion");
        try {
            $secreto = $this->instancias->secreto($inst);
        } catch (\Throwable) {
            throw new ErrorPrograma('secreto_ilegible', Estado::ERRORES['secreto_ilegible']);
        }
        return $this->pedirCrudo($inst, $accion, self::json($cuerpo), $usuario);
    }

    /** Igual que pedir(), con el cuerpo JSON ya armado (para reintentar con los mismos bytes). */
    public function pedirCrudo(array $inst, string $accion, string $json, ?array $usuario = null): array
    {
        if (!in_array($accion, Token::TIPOS_V2, true)) throw new \InvalidArgumentException("Accion v2 no valida: $accion");
        try {
            $secreto = $this->instancias->secreto($inst);
        } catch (\Throwable) {
            throw new ErrorPrograma('secreto_ilegible', Estado::ERRORES['secreto_ilegible']);
        }
        if (strlen($json) > 65536) throw new ErrorPrograma('invalido', 'El pedido es demasiado grande (más de 64 KiB).');
        $email = (string) ($usuario['email'] ?? self::EMAIL_REVISION);
        $nombre = (string) ($usuario['nombre'] ?? 'Panel Tukán (revisión automática)');
        $rol = (string) ($usuario['rol'] ?? 'dueno');
        $token = Token::crearV2($accion, $inst['id'], $email, $nombre, $rol, $json, $secreto);
        $url = rtrim($inst['url'], '/') . '/tukan_central.php?accion=' . $accion;
        $cab = ['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'X-Tukan-Token: ' . $token];
        $r = ($this->transporte ?? [self::class, 'curl'])($url, $cab, $json, self::tiempo($accion), self::maximo($accion));
        $desfase = null;
        if (!empty($r['cabeceras']['date']) && ($t = strtotime($r['cabeceras']['date'])) !== false) $desfase = $t - time();
        if ($r['errno'] === 23) throw new ErrorPrograma('formato', Estado::ERRORES['formato'] . ' (la respuesta es demasiado grande)', $r['http']);
        if ($r['errno'] !== 0 || $r['http'] !== 200) {
            throw ErrorPrograma::desdeRespuesta($r['http'], $r['cuerpo'], $r['errno'], $r['error'], $desfase);
        }
        try {
            $d = json_decode($r['cuerpo'], true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ErrorPrograma('formato', Estado::ERRORES['formato'] . ' (la respuesta no es JSON válido)', 200);
        }
        if (!is_array($d) || array_is_list($d)) throw new ErrorPrograma('formato', Estado::ERRORES['formato'], 200);
        if (($d['ok'] ?? null) !== true) throw ErrorPrograma::desdeRespuesta(422, $r['cuerpo']);
        if (($d['protocolo'] ?? null) !== 2) throw new ErrorPrograma('formato', Estado::ERRORES['formato'] . ' (falta "protocolo": 2)', 200);
        return $d;
    }

    /** Transporte real: curl, sin seguir redirecciones, con tope de tamaño. */
    public static function curl(string $url, array $cabeceras, string $cuerpo, int $tiempo, int $max): array
    {
        $recibido = '';
        $cab = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(4, $tiempo),
            CURLOPT_TIMEOUT => $tiempo,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => array_merge($cabeceras, ['Expect:']),
            CURLOPT_USERAGENT => 'TukanCentral/2',
            CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1',
            CURLOPT_HEADERFUNCTION => function ($ch, string $h) use (&$cab): int {
                $p = explode(':', $h, 2);
                if (count($p) === 2) $cab[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $trozo) use (&$recibido, $max): int {
                if (strlen($recibido) + strlen($trozo) > $max) return 0;
                $recibido .= $trozo;
                return strlen($trozo);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $r = [
            'http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'cuerpo' => $recibido,
            'errno' => $errno,
            'error' => $errno ? (curl_strerror($errno) ?: '') : '',
            'cabeceras' => $cab,
            'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        ];
        curl_close($ch);
        return $r;
    }
}
