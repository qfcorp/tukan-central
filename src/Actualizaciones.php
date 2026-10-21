<?php
declare(strict_types=1);

namespace Tukan;

use PDO;
use RuntimeException;

/**
 * Pantalla «Actualizaciones»: versiones de los programas de ESTE servidor y el botón «Actualizar».
 *
 * El panel corre como www-data y no puede correr los instaladores. Por eso:
 *   1. lee lo que dejó el comando `actualizar` (como qf) en /var/lib/tukan-actualizar/estado/versiones.json
 *   2. cuando el dueño aprueba (con su código de 2 pasos), escribe un pedido FIRMADO (src/Pedido.php) en la cola
 *      /var/lib/tukan-actualizar/pedidos: qué programa y qué commit exacto, nada más
 *   3. el servicio de root tukan-actualizar (deploy/actualizar_pedidos.sh) lo toma, lo revisa y corre `actualizar`
 *   4. el panel lee el resultado en estado/pedido-ID.json (+ .log) y lo anota en la Auditoría
 * Nada se actualiza solo: sin pedido del dueño el servicio no hace nada.
 *
 * Rutas (para las pruebas): TUKAN_ACTUALIZAR_LLAVE, TUKAN_ACTUALIZAR_PEDIDOS, TUKAN_ACTUALIZAR_ESTADO.
 * Tabla "actualizaciones" (se crea sola): un registro por pedido.
 */
final class Actualizaciones
{
    public const FINALES = ['listo', 'fallo', 'rechazado', 'vencido'];
    /** Si en este tiempo el servicio no dejó ni el «en curso», el pedido se da por perdido. */
    public const ESPERA_SEG = Pedido::VIGENCIA + 60;

    public string $llave;
    public string $pedidos;
    public string $estado;

    public function __construct(private PDO $db)
    {
        self::tabla($db);
        $this->llave = (string) (getenv('TUKAN_ACTUALIZAR_LLAVE') ?: '/etc/tukan-actualizar/llave');
        $this->pedidos = rtrim((string) (getenv('TUKAN_ACTUALIZAR_PEDIDOS') ?: '/var/lib/tukan-actualizar/pedidos'), '/');
        $this->estado = rtrim((string) (getenv('TUKAN_ACTUALIZAR_ESTADO') ?: '/var/lib/tukan-actualizar/estado'), '/');
    }

    public static function tabla(PDO $db): void
    {
        static $hecho = [];
        $k = spl_object_id($db);
        if (isset($hecho[$k])) return;
        $db->exec('CREATE TABLE IF NOT EXISTS actualizaciones (
            id TEXT PRIMARY KEY, tipo TEXT NOT NULL, objetivos TEXT NOT NULL, usuario_id INTEGER, email TEXT,
            creado INTEGER NOT NULL, estado TEXT NOT NULL, resumen TEXT, terminado INTEGER, auditado INTEGER NOT NULL DEFAULT 0)');
        $hecho[$k] = true;
    }

    /** ¿Está instalado el actualizador en este servidor? (llave legible y cola escribible) */
    public function instalado(): bool
    {
        try {
            Pedido::leerLlave($this->llave);
        } catch (RuntimeException) {
            return false;
        }
        return is_dir($this->pedidos) && is_writable($this->pedidos) && is_dir($this->estado);
    }

    /** versiones.json (lo escribe `actualizar`): ['revisado' => int, 'servidor' => str, 'programas' => [...]] o null */
    public function versiones(): ?array
    {
        $j = $this->leerJson($this->estado . '/versiones.json');
        if (!$j || !is_array($j['programas'] ?? null)) return null;
        $progs = [];
        foreach ($j['programas'] as $p) {
            if (!is_array($p) || !is_string($p['id'] ?? null) || !Pedido::programaValido($p['id'])) continue;
            $p['nuevos'] = (int) ($p['nuevos'] ?? 0);
            $p['cambios'] = is_array($p['cambios'] ?? null) ? $p['cambios'] : [];
            $p['instancias'] = is_array($p['instancias'] ?? null) ? $p['instancias'] : [];
            $p['nombre'] = self::nombre($p['id'], (string) ($p['nombre'] ?? $p['id']));
            $progs[$p['id']] = $p;
        }
        return ['revisado' => (int) ($j['revisado'] ?? 0), 'servidor' => (string) ($j['servidor'] ?? ''), 'programas' => $progs];
    }

    /** Programas con versión nueva (que se pueden actualizar): id => commit disponible */
    public function conNovedad(?array $v = null): array
    {
        $v ??= $this->versiones();
        $r = [];
        foreach ($v['programas'] ?? [] as $id => $p) {
            $c = $p['disponible']['commit'] ?? null;
            if (($p['estado'] ?? '') === 'nuevo' && is_string($c) && Pedido::commitValido($c)) $r[$id] = $c;
        }
        return $r;
    }

    /** Nombre bonito (con tildes) para los programas que conoce el panel. */
    public static function nombre(string $id, string $defecto): string
    {
        return match ($id) {
            'tukan-central' => 'Panel Tukán Central',
            'erp-productoras' => 'ERP para productoras',
            default => Productos::existe($id) ? Productos::nombre($id) : $defecto,
        };
    }

    /** Último resultado de una corrida (consola o panel): ultima.json */
    public function ultima(): ?array
    {
        return $this->leerJson($this->estado . '/ultima.json');
    }

    /** Pedido sin terminar (pendiente o en curso), o null */
    public function enCurso(): ?array
    {
        $f = $this->db->query("SELECT * FROM actualizaciones WHERE estado IN ('pendiente','en_curso') ORDER BY creado DESC LIMIT 1")->fetch();
        return $f ?: null;
    }

    public function historial(int $n = 10): array
    {
        $st = $this->db->prepare('SELECT * FROM actualizaciones ORDER BY creado DESC LIMIT ' . max(1, min(50, $n)));
        $st->execute();
        return $st->fetchAll();
    }

    public function obtener(string $id): ?array
    {
        if (!Pedido::idValida($id)) return null;
        $st = $this->db->prepare('SELECT * FROM actualizaciones WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Deja un pedido firmado en la cola. $objetivos = programa => commit aprobado ([] para «revisar»).
     * @return string id del pedido
     */
    public function pedir(string $tipo, array $objetivos, array $usuario, ?int $ahora = null): string
    {
        $ahora ??= time();
        if (!$this->instalado()) throw new RuntimeException('El actualizador no está instalado en este servidor.');
        if ($this->enCurso()) throw new RuntimeException('Ya hay una actualización en curso. Espera a que termine.');
        $p = Pedido::crear($tipo, $objetivos, (string) ($usuario['email'] ?? 'panel'), Pedido::leerLlave($this->llave), $ahora);
        $tmp = $this->pedidos . '/.' . $p['id'] . '.tmp';
        if (@file_put_contents($tmp, $p['contenido']) === false || !@chmod($tmp, 0640) || !@rename($tmp, $this->pedidos . '/' . $ahora . '-' . $p['id'] . '.json')) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo dejar el pedido en la cola del servidor.');
        }
        $this->db->prepare('INSERT INTO actualizaciones (id, tipo, objetivos, usuario_id, email, creado, estado) VALUES (?,?,?,?,?,?,?)')
            ->execute([$p['id'], $tipo, json_encode($objetivos), $usuario['id'] ?? null, $usuario['email'] ?? null, $ahora, 'pendiente']);
        return $p['id'];
    }

    /** Resultado que dejó el servicio para un pedido (pedido-ID.json), o null */
    public function resultado(string $id): ?array
    {
        return Pedido::idValida($id) ? $this->leerJson($this->estado . "/pedido-$id.json") : null;
    }

    /** Lo que va escribiendo `actualizar` para ese pedido (últimos 20 KB, sin tildes ni códigos de color). */
    public function log(string $id): string
    {
        $f = $this->estado . "/pedido-$id.log";
        if (!Pedido::idValida($id) || !is_file($f) || !is_readable($f)) return '';
        $t = (string) @file_get_contents($f, false, null, max(0, (int) filesize($f) - 20000));
        $t = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $t);
        return mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Pone al día la tabla con lo que dejó el servicio. Por cada pedido que terminó, llama una vez a
     * $anotar(id, estado, resumen, objetivos) (para la Auditoría).
     */
    public function sincronizar(callable $anotar, ?int $ahora = null): void
    {
        $ahora ??= time();
        foreach ($this->db->query("SELECT * FROM actualizaciones WHERE estado IN ('pendiente','en_curso') OR auditado = 0")->fetchAll() as $f) {
            $r = $this->resultado($f['id']);
            $estado = $f['estado'];
            $resumen = $f['resumen'];
            if ($r && in_array($r['estado'] ?? '', ['en_curso', 'listo', 'fallo', 'rechazado'], true)) {
                $estado = $r['estado'];
                $resumen = self::resumen($r);
            } elseif (in_array($estado, ['pendiente', 'en_curso'], true) && $ahora - (int) $f['creado'] > self::ESPERA_SEG && !$r) {
                $estado = 'vencido';
                $resumen = 'El servidor no tomó el pedido en ' . intdiv(self::ESPERA_SEG, 60) . ' minutos (¿está activo tukan-actualizar.path?). No se cambió nada.';
            }
            $final = in_array($estado, self::FINALES, true);
            if ($estado !== $f['estado'] || $resumen !== $f['resumen']) {
                $this->db->prepare('UPDATE actualizaciones SET estado = ?, resumen = ?, terminado = ? WHERE id = ?')
                    ->execute([$estado, $resumen, $final ? (int) ($r['fin'] ?? $ahora) : null, $f['id']]);
            }
            if ($final && !(int) $f['auditado']) {
                $this->db->prepare('UPDATE actualizaciones SET auditado = 1 WHERE id = ? AND auditado = 0')->execute([$f['id']]);
                $anotar($f['id'], $estado, (string) $resumen, json_decode((string) $f['objetivos'], true) ?: [], $f['tipo']);
            }
        }
    }

    /** "TuEvento: listo (a1b2c3d → e4f5g6h) · TuSalón: volvió a la versión anterior" */
    public static function resumen(array $r): string
    {
        if (($r['estado'] ?? '') === 'rechazado' || (($r['estado'] ?? '') === 'fallo' && !($r['programas'] ?? []))) {
            return 'No se hizo: ' . ($r['motivo'] ?? 'sin detalle');
        }
        if (!($r['programas'] ?? [])) return ($r['estado'] ?? '') === 'en_curso' ? 'En curso…' : 'Revisión de GitHub terminada.';
        $partes = [];
        foreach ($r['programas'] as $p) {
            $n = self::nombre((string) ($p['programa'] ?? ''), (string) ($p['programa'] ?? ''));
            $partes[] = $n . ': ' . self::textoEstado((string) ($p['estado'] ?? ''))
                . (($p['estado'] ?? '') === 'ok' ? ' (' . substr((string) $p['antes'], 0, 7) . ' → ' . substr((string) $p['despues'], 0, 7) . ')' : '');
        }
        return implode(' · ', $partes);
    }

    public static function textoEstado(string $e): string
    {
        return match ($e) {
            'ok' => 'actualizado', 'al_dia' => 'ya estaba al día', 'revertido' => 'falló y volvió a la versión anterior',
            'grave' => 'falló y NO pudo volver atrás (revisa ya)', 'sin_respaldo' => 'no se actualizó (no se pudo respaldar la base)',
            'rechazado' => 'no se actualizó (GitHub cambió: vuelve a buscar novedades)', 'error' => 'error',
            'listo' => 'listo', 'fallo' => 'con problemas', 'pendiente' => 'esperando al servidor', 'en_curso' => 'en curso', 'vencido' => 'el servidor no lo tomó',
            default => $e,
        };
    }

    private function leerJson(string $f): ?array
    {
        if (!is_file($f) || !is_readable($f) || filesize($f) > 2_000_000) return null;
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }
}
