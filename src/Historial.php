<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Historial de pausas y borrados de cada cliente (Anexo A.3 de PROTOCOLO_V2): quién pausó, reactivó, suspendió,
 * borró o restauró, cuándo y por qué. Lo escribe el panel cuando la operación sale bien (tabla "historial_clientes",
 * se crea sola, sin cambiar user_version). El motivo lo escribe el usuario del panel; nunca lleva claves.
 */
final class Historial
{
    public function __construct(private PDO $db)
    {
        self::tabla($db);
    }

    public static function tabla(PDO $db): void
    {
        static $hecho = [];
        $k = spl_object_id($db);
        if (isset($hecho[$k])) return;
        $db->exec('CREATE TABLE IF NOT EXISTS historial_clientes (
            id INTEGER PRIMARY KEY, instancia_id TEXT NOT NULL, cliente_id TEXT NOT NULL, accion TEXT NOT NULL, tipo TEXT, motivo TEXT,
            usuario_id INTEGER, quien TEXT, momento INTEGER NOT NULL, operacion_id TEXT UNIQUE, datos TEXT)');
        $db->exec('CREATE INDEX IF NOT EXISTS historial_clientes_cliente ON historial_clientes (instancia_id, cliente_id, momento)');
        $hecho[$k] = true;
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Anota una operación hecha (una sola vez por operacion_id). $extra: borrar_definitivo_el, copia… */
    public function anotar(string $inst, string $cliente, string $accion, array $datos, ?array $usuario, string $op, array $extra = [], ?int $ahora = null): void
    {
        $tipo = is_string($datos['tipo'] ?? null) && isset(Operaciones::TIPOS_PAUSA[$datos['tipo']]) ? $datos['tipo'] : null;
        $this->db->prepare('INSERT OR IGNORE INTO historial_clientes (instancia_id, cliente_id, accion, tipo, motivo, usuario_id, quien, momento, operacion_id, datos)
                            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$inst, $cliente, $accion, $tipo, self::texto($datos['motivo'] ?? null, 300), $usuario['id'] ?? null,
                       self::texto($usuario['nombre'] ?? null, 100), $ahora ?? time(), $op, $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    }

    /** @return list<array{accion:string,tipo:?string,motivo:?string,quien:?string,momento:int,datos:array}> lo más nuevo primero */
    public function de(string $inst, string $cliente, int $max = 30): array
    {
        $st = $this->db->prepare('SELECT h.accion, h.tipo, h.motivo, COALESCE(u.nombre, h.quien) AS quien, h.momento, h.datos FROM historial_clientes h
                                  LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.instancia_id = ? AND h.cliente_id = ? ORDER BY h.momento DESC, h.id DESC LIMIT ' . max(1, $max));
        $st->execute([$inst, $cliente]);
        return array_map(fn($f) => ['accion' => $f['accion'], 'tipo' => $f['tipo'], 'motivo' => $f['motivo'], 'quien' => $f['quien'], 'momento' => (int) $f['momento'],
                                    'datos' => json_decode((string) $f['datos'], true) ?: []], $st->fetchAll());
    }

    /** Texto corto de una línea del historial: "Pausó (Falta de pago)", "Borró (a la papelera hasta el 14/11/2026)"… */
    public static function titulo(array $h): string
    {
        $t = match ($h['accion']) {
            'pausar' => 'Pausó', 'reactivar' => 'Reactivó', 'suspender' => 'Suspendió', 'cancelar' => 'Canceló la cuenta',
            'borrar_cliente' => 'Borró (a la papelera)', 'restaurar_cliente' => 'Restauró de la papelera', default => Operaciones::nombre($h['accion']),
        };
        if ($h['tipo'] !== null) $t .= ' · ' . (Operaciones::TIPOS_PAUSA[$h['tipo']] ?? $h['tipo']);
        return $t;
    }
}
