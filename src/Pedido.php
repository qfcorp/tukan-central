<?php
declare(strict_types=1);

namespace Tukan;

use RuntimeException;

/**
 * Pedido de actualización firmado (Panel Tukán Central → servicio tukan-actualizar del servidor).
 *
 * El panel (www-data) NO puede correr los instaladores: solo deja un archivo en la cola
 * /var/lib/tukan-actualizar/pedidos. El servicio de root (tukan-actualizar.path → tukan-actualizar-pedidos)
 * toma SOLO los pedidos que pasan esta revisión y corre `actualizar` como el usuario normal (qf).
 *
 * El archivo es JSON: {"v":1, "datos":"<base64url del JSON>", "firma":"<HMAC-SHA256 en hex>"}
 *   datos = {id (32 hex, de un solo uso), tipo (actualizar|revisar), objetivos {programa: commit de 40 hex aprobado},
 *            usuario (correo del dueño), creado, vence (máximo 10 minutos)}
 * La llave (64 hex) está en /etc/tukan-actualizar/llave (root:www-data 640).
 * Un pedido NO trae comandos ni rutas: solo qué programa y qué versión exacta (la que el dueño vio y aprobó).
 * Este archivo no depende de nada más: el instalador del actualizador copia una copia de root a /usr/local/lib/tukan-actualizar.
 */
final class Pedido
{
    public const VERSION = 1;
    public const VIGENCIA = 600;           // 10 minutos para que el servicio lo tome
    public const MAX_BYTES = 8192;
    public const MAX_OBJETIVOS = 30;
    public const TIPOS = ['actualizar', 'revisar'];
    private const CONTEXTO = 'tukan-actualizar:v1:';

    /** Lee la llave (64 hex) de un archivo y la devuelve en binario. */
    public static function leerLlave(string $archivo): string
    {
        $hex = is_file($archivo) && is_readable($archivo) ? trim((string) @file_get_contents($archivo)) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) throw new RuntimeException('llave ilegible o con formato malo');
        return (string) hex2bin($hex);
    }

    public static function idValida(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }

    public static function programaValido(string $p): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $p);
    }

    public static function commitValido(string $c): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', $c);
    }

    /**
     * Arma un pedido firmado.
     * @param array<string,string> $objetivos programa => commit aprobado (vacío para "revisar")
     * @return array{id: string, contenido: string, datos: array}
     */
    public static function crear(string $tipo, array $objetivos, string $usuario, string $llave, ?int $ahora = null, ?string $id = null): array
    {
        $ahora ??= time();
        $id ??= bin2hex(random_bytes(16));
        $datos = [
            'id' => $id, 'tipo' => $tipo, 'objetivos' => (object) $objetivos,
            'usuario' => mb_substr($usuario, 0, 200), 'creado' => $ahora, 'vence' => $ahora + self::VIGENCIA,
        ];
        self::revisarDatos(json_decode((string) json_encode($datos), true), $ahora);
        $b64 = rtrim(strtr(base64_encode((string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $contenido = (string) json_encode(['v' => self::VERSION, 'datos' => $b64, 'firma' => hash_hmac('sha256', self::CONTEXTO . $b64, $llave)], JSON_UNESCAPED_SLASHES);
        $datos['objetivos'] = $objetivos;
        return ['id' => $id, 'contenido' => $contenido, 'datos' => $datos];
    }

    /**
     * Revisa un pedido: tamaño, firma, versión, vigencia y que cada campo sea lo que debe ser.
     * Devuelve los datos o lanza RuntimeException con el motivo (sin tildes: se escribe en los registros del servidor).
     * Si la firma es buena pero el pedido venció (o viene del futuro), el motivo empieza con "valido:": así el servicio
     * sabe que puede anotar el rechazo con ese id (con firma mala no se anota nada a nombre de ningún pedido).
     */
    public static function verificar(string $contenido, string $llave, ?int $ahora = null): array
    {
        $ahora ??= time();
        if (strlen($contenido) > self::MAX_BYTES) throw new RuntimeException('pedido demasiado grande');
        $j = json_decode($contenido, true);
        if (!is_array($j) || ($j['v'] ?? null) !== self::VERSION || !is_string($j['datos'] ?? null) || !is_string($j['firma'] ?? null)) {
            throw new RuntimeException('pedido con formato malo');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,8000}$/', $j['datos']) || !preg_match('/^[0-9a-f]{64}$/', $j['firma'])) {
            throw new RuntimeException('pedido con formato malo');
        }
        if (!hash_equals(hash_hmac('sha256', self::CONTEXTO . $j['datos'], $llave), $j['firma'])) {
            throw new RuntimeException('firma invalida (no lo hizo el panel)');
        }
        $crudo = base64_decode(strtr($j['datos'], '-_', '+/'), true);
        $d = $crudo === false ? null : json_decode($crudo, true);
        if (!is_array($d)) throw new RuntimeException('pedido con formato malo');
        self::revisarDatos($d, $ahora);
        return $d;
    }

    private static function revisarDatos(mixed $d, int $ahora): void
    {
        if (!is_array($d)) throw new RuntimeException('pedido con formato malo');
        $id = $d['id'] ?? null;
        if (!is_string($id) || !self::idValida($id)) throw new RuntimeException('id invalido');
        if (!in_array($d['tipo'] ?? null, self::TIPOS, true)) throw new RuntimeException('tipo invalido');
        if (!is_int($d['creado'] ?? null) || !is_int($d['vence'] ?? null)) throw new RuntimeException('fechas invalidas');
        if ($d['vence'] <= $d['creado'] || $d['vence'] - $d['creado'] > self::VIGENCIA) throw new RuntimeException('vigencia invalida');
        if ($d['creado'] > $ahora + 120) throw new RuntimeException('valido: pedido con fecha futura (revisa la hora del servidor)');
        if ($ahora > $d['vence']) throw new RuntimeException('valido: pedido vencido (pasaron mas de 10 minutos)');
        $u = $d['usuario'] ?? null;
        if (!is_string($u) || mb_strlen($u) > 200 || preg_match('/[\x00-\x1f\x7f]/', $u)) throw new RuntimeException('usuario invalido');
        $obj = $d['objetivos'] ?? null;
        if (!is_array($obj)) throw new RuntimeException('objetivos invalidos');
        if ($d['tipo'] === 'revisar' && $obj) throw new RuntimeException('un pedido de revisar no lleva programas');
        if ($d['tipo'] === 'actualizar' && (!$obj || count($obj) > self::MAX_OBJETIVOS)) throw new RuntimeException('objetivos invalidos');
        foreach ($obj as $p => $c) {
            if (!is_string($p) || !self::programaValido($p) || !is_string($c) || !self::commitValido($c)) throw new RuntimeException('objetivos invalidos');
        }
    }
}
