<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/** Cuándo se trajo cada cosa de cada programa (capacidades, clientes, solicitudes, finanzas) y su huella. */
final class Sincronizacion
{
    public function __construct(private PDO $db) {}

    /** @return array{momento:int,huella:?string,datos:?array}|null */
    public function obtener(string $inst, string $que): ?array
    {
        $st = $this->db->prepare('SELECT momento, huella, datos FROM sincronizacion WHERE instancia_id = ? AND que = ?');
        $st->execute([$inst, $que]);
        $f = $st->fetch();
        if (!$f) return null;
        return ['momento' => (int) $f['momento'], 'huella' => $f['huella'], 'datos' => $f['datos'] !== null ? json_decode($f['datos'], true) : null];
    }

    public function momento(string $inst, string $que): ?int
    {
        return $this->obtener($inst, $que)['momento'] ?? null;
    }

    public function guardar(string $inst, string $que, int $momento, ?string $huella = null, ?array $datos = null): void
    {
        $this->db->prepare('INSERT INTO sincronizacion (instancia_id, que, momento, huella, datos) VALUES (?,?,?,?,?)
                            ON CONFLICT (instancia_id, que) DO UPDATE SET momento = excluded.momento, huella = excluded.huella, datos = excluded.datos')
            ->execute([$inst, $que, $momento, $huella, $datos === null ? null : json_encode($datos, JSON_UNESCAPED_UNICODE)]);
    }

    /** Momento más viejo de una cosa entre varias instancias (para "Datos de hace 40 minutos"). */
    public function masViejo(array $insts, string $que): ?int
    {
        $m = null;
        foreach ($insts as $i) {
            $x = $this->momento($i, $que);
            if ($x !== null) $m = $m === null ? $x : min($m, $x);
        }
        return $m;
    }
}
