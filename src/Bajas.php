<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Borrar una instalación desde el panel (TuGuardería: una instalación por cliente).
 * El programa NO borra en el momento: deja un pedido para la tarea de root de su servidor (cada minuto), que primero
 * guarda una copia final y después borra nginx, la base, los respaldos automáticos y los archivos.
 *
 * Estados (tabla "bajas", se crea sola):
 *   programada → en_curso → borrada        (o error / cancelada)
 *   manual: conector antiguo o sin conexión; el dueño borró a mano y el panel solo quitó su dirección de Cloudflare
 * "borrada" = el programa dejó de contestar después de la hora programada (dos revisiones seguidas, con al menos
 * $confirmarSeg segundos entre la primera y la última). Entonces, si Cloudflare está configurado en Ajustes, se quita
 * su dirección del túnel y su DNS. La auditoría del programa se conserva aunque se quite del panel.
 */
final class Bajas
{
    public const ACTIVAS = ['programada', 'en_curso'];
    /** Errores que dicen "el programa todavía contesta" (vienen del conector). */
    private const VIVO = ['firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'limite', 'no_encontrado', 'invalido', 'no_permitido', 'conflicto', 'secreto_ilegible'];

    /** Segundos entre el primer "no responde" y marcarla borrada (evita marcarla por un corte de internet de un momento). */
    public int $confirmarSeg;
    /** @var callable|null fn(string $accion, string $detalle, string $instancia): void */
    private $anotar;
    /** @var callable|null transporte de Cloudflare (pruebas) */
    private $cfTransporte;

    public function __construct(private PDO $db, private ?Cifrado $cifrado = null, ?callable $anotar = null, ?callable $cfTransporte = null)
    {
        self::tabla($db);
        $this->anotar = $anotar;
        $this->cfTransporte = $cfTransporte;
        $s = getenv('TUKAN_BAJAS_CONFIRMAR_SEG');
        $this->confirmarSeg = is_string($s) && ctype_digit($s) ? (int) $s : 20;
    }

    public static function tabla(PDO $db): void
    {
        static $hecho = [];
        $k = spl_object_id($db);
        if (isset($hecho[$k])) return;
        $db->exec('CREATE TABLE IF NOT EXISTS bajas (
            instancia_id TEXT PRIMARY KEY, carpeta TEXT, hostname TEXT, estado TEXT NOT NULL, operacion_id TEXT, usuario_id INTEGER,
            programada INTEGER, borrar_desde INTEGER, copia_en TEXT, mensaje TEXT, revisada INTEGER,
            fallos INTEGER NOT NULL DEFAULT 0, primer_fallo INTEGER, terminada INTEGER, cloudflare TEXT)');
        $hecho[$k] = true;
    }

    /** ¿Tiene un borrado programado, en curso o ya borrado? (para no mandar avisos de "sin conexión") */
    public static function apagando(PDO $db, string $inst): bool
    {
        self::tabla($db);
        $st = $db->prepare("SELECT 1 FROM bajas WHERE instancia_id = ? AND estado IN ('programada','en_curso','borrada','manual')");
        $st->execute([$inst]);
        return (bool) $st->fetchColumn();
    }

    /** ¿El programa sabe borrarse desde el panel? */
    public static function disponible(array $capDatos): bool
    {
        return Capacidades::puede($capDatos, 'ejecutar', 'dar_de_baja');
    }

    public function obtener(string $inst): ?array
    {
        $st = $this->db->prepare('SELECT * FROM bajas WHERE instancia_id = ?');
        $st->execute([$inst]);
        $b = $st->fetch();
        if (!$b) return null;
        $b['cloudflare'] = $b['cloudflare'] ? (json_decode($b['cloudflare'], true) ?: null) : null;
        return $b;
    }

    private function poner(string $inst, array $campos): void
    {
        if (isset($campos['cloudflare']) && is_array($campos['cloudflare'])) $campos['cloudflare'] = json_encode($campos['cloudflare'], JSON_UNESCAPED_UNICODE);
        $existe = $this->obtener($inst) !== null;
        if (!$existe) {
            $campos += ['estado' => 'programada'];
            $cols = array_keys($campos);
            $this->db->prepare('INSERT INTO bajas (instancia_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
                ->execute(array_merge([$inst], array_values($campos)));
            return;
        }
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
        $this->db->prepare("UPDATE bajas SET $set WHERE instancia_id = ?")->execute(array_merge(array_values($campos), [$inst]));
    }

    private function anotar(string $accion, string $detalle, string $inst): void
    {
        if ($this->anotar) ($this->anotar)($accion, mb_substr($detalle, 0, 500), $inst);
    }

    /** Dirección pública a quitar de Cloudflare: la del panel (si es un nombre válido) o la que dice el programa. */
    public static function hostname(array $inst, ?string $dePrograma = null): ?string
    {
        $h = strtolower((string) parse_url((string) $inst['url'], PHP_URL_HOST));
        if (Cloudflare::hostValido($h)) return $h;
        $p = strtolower(trim((string) $dePrograma));
        return Cloudflare::hostValido($p) ? $p : null;
    }

    /** Carpeta que se manda para confirmar: la guardada en el panel o la que dice el programa en su ficha. */
    public static function carpeta(array $inst, ?array $fichaBaja): ?string
    {
        $c = trim((string) ($inst['carpeta'] ?? ''));
        if ($c === '' && is_string($fichaBaja['carpeta'] ?? null)) $c = $fichaBaja['carpeta'];
        return preg_match('/^[a-z][a-z0-9_]{1,30}$/', $c) ? $c : null;
    }

    /**
     * Programa el borrado (operación dar_de_baja). Los permisos, el ID escrito y el código de 2 pasos los revisa la página.
     * @return array{ok:bool, mensaje:string, sin_respuesta:bool}
     */
    public function programar(array $inst, Operaciones $ops, string $op, string $carpeta, array $usuario): array
    {
        $res = $ops->ejecutar($inst, 'dar_de_baja', $op, 'unico', ['cliente_id' => 'unico', 'confirmar' => $carpeta], $usuario, $inst['nombre']);
        if ($res['ok']) {
            $r = $res['respuesta'];
            $x = is_array($r['resultado'] ?? null) ? $r['resultado'] : [];
            $desde = is_string($x['borrar_desde'] ?? null) && ($t = strtotime($x['borrar_desde'])) !== false ? $t : time() + 300;
            $copia = is_string($x['copia_en'] ?? null) ? mb_substr($x['copia_en'], 0, 200) : '/var/backups/borrados/';
            $this->poner($inst['id'], ['carpeta' => $carpeta, 'hostname' => self::hostname($inst, is_string($x['dominio'] ?? null) ? $x['dominio'] : null),
                'estado' => 'programada', 'operacion_id' => $op, 'usuario_id' => $usuario['id'] ?? null, 'programada' => time(), 'borrar_desde' => $desde,
                'copia_en' => $copia, 'mensaje' => null, 'revisada' => time(), 'fallos' => 0, 'primer_fallo' => null, 'terminada' => null, 'cloudflare' => null]);
            $mensaje = is_string($r['mensaje'] ?? null) ? mb_substr($r['mensaje'], 0, 400) : 'Borrado programado.';
            $this->anotar('baja_programada', "Carpeta $carpeta · se borra desde " . date('d/m/Y H:i', $desde) . " · copia final en $copia (op " . substr($op, 0, 8) . ')', $inst['id']);
            return ['ok' => true, 'mensaje' => $mensaje, 'sin_respuesta' => false];
        }
        $e = $res['error'];
        if ($e->codigo === 'conflicto' && in_array($e->detalle['motivo'] ?? '', ['ya_programada', 'ya_empezo'], true)) {
            $prev = $this->obtener($inst['id']);
            $this->poner($inst['id'], ['carpeta' => $prev['carpeta'] ?? $carpeta, 'hostname' => $prev['hostname'] ?? self::hostname($inst),
                'estado' => $e->detalle['motivo'] === 'ya_empezo' ? 'en_curso' : 'programada', 'borrar_desde' => $prev['borrar_desde'] ?? time(),
                'copia_en' => $prev['copia_en'] ?? '/var/backups/borrados/', 'mensaje' => null]);
            return ['ok' => true, 'mensaje' => $e->getMessage(), 'sin_respuesta' => false];
        }
        $this->anotar('baja_rechazada', ($res['sin_respuesta'] ? 'El programa no respondió' : "El programa no lo aceptó ({$e->codigo})") . ': ' . $e->getMessage() . ' (op ' . substr($op, 0, 8) . ')', $inst['id']);
        return ['ok' => false, 'mensaje' => $e->getMessage(), 'sin_respuesta' => $res['sin_respuesta']];
    }

    /** @return array{ok:bool, mensaje:string} */
    public function cancelar(array $inst, Operaciones $ops, string $op, array $usuario): array
    {
        $res = $ops->ejecutar($inst, 'cancelar_baja', $op, 'unico', ['cliente_id' => 'unico'], $usuario, $inst['nombre']);
        if (!$res['ok']) {
            $this->anotar('baja_rechazada', 'Cancelar: ' . $res['error']->getMessage(), $inst['id']);
            if (($res['error']->detalle['motivo'] ?? '') === 'ya_empezo') $this->poner($inst['id'], ['estado' => 'en_curso']);
            return ['ok' => false, 'mensaje' => $res['error']->getMessage()];
        }
        $this->poner($inst['id'], ['estado' => 'cancelada', 'mensaje' => 'Borrado cancelado.', 'revisada' => time(), 'terminada' => time()]);
        $this->anotar('baja_cancelada', 'Borrado cancelado (op ' . substr($op, 0, 8) . ')', $inst['id']);
        return ['ok' => true, 'mensaje' => is_string($res['respuesta']['mensaje'] ?? null) ? $res['respuesta']['mensaje'] : 'Borrado cancelado.'];
    }

    /** ¿Este error quiere decir que el programa ya no está? (nginx apagado, sin túnel, página de otro, etc.) */
    public static function noEsta(ErrorPrograma $e): bool
    {
        if (in_array($e->codigo, self::VIVO, true)) return false;
        if ($e->codigo === 'interno' && $e->textoPrograma !== '') return false;   // el conector contestó con su error
        return true;
    }

    /** Pregunta al programa cómo va (si el borrado está activo). Devuelve la fila actualizada. */
    public function revisar(array $inst, ClienteV2 $cv, ?array $usuario = null, ?int $ahora = null): ?array
    {
        $b = $this->obtener($inst['id']);
        if (!$b || !in_array($b['estado'], self::ACTIVAS, true)) return $b;
        $ahora ??= time();
        try {
            $r = $cv->pedir($inst, 'cliente', ['id' => 'unico'], $usuario);
            $bj = is_array($r['cliente']['baja'] ?? null) ? $r['cliente']['baja'] : [];
            $e = $bj['estado'] ?? null;
            $texto = is_string($bj['texto'] ?? null) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $bj['texto']) ?? ''), 0, 300) : null;
            $base = ['revisada' => $ahora, 'fallos' => 0, 'primer_fallo' => null];
            if ($e === 'programada') {
                $t = is_string($bj['borrar_desde'] ?? null) ? strtotime($bj['borrar_desde']) : false;
                $this->poner($inst['id'], $base + ['estado' => 'programada', 'mensaje' => null] + ($t ? ['borrar_desde' => $t] : []));
            } elseif ($e === 'en_curso') {
                if ($b['estado'] !== 'en_curso') $this->anotar('baja_estado', 'Borrado en curso (copia final y borrado)', $inst['id']);
                $this->poner($inst['id'], $base + ['estado' => 'en_curso', 'mensaje' => null]);
            } elseif ($e === 'error') {
                $this->poner($inst['id'], $base + ['estado' => 'error', 'mensaje' => $texto ?? 'El borrado no se pudo hacer.', 'terminada' => $ahora]);
                $this->anotar('baja_estado', 'El borrado no se hizo: ' . ($texto ?? 'error'), $inst['id']);
            } else {
                $this->poner($inst['id'], $base + ['estado' => 'cancelada', 'mensaje' => 'El programa ya no tiene un borrado programado (se canceló).', 'terminada' => $ahora]);
                $this->anotar('baja_estado', 'El programa ya no tiene el borrado programado (cancelado)', $inst['id']);
            }
        } catch (ErrorPrograma $e) {
            if (!self::noEsta($e)) {
                $this->poner($inst['id'], ['revisada' => $ahora, 'mensaje' => 'No se pudo revisar ahora: ' . $e->getMessage()]);
            } elseif ($ahora < (int) $b['borrar_desde']) {
                $this->poner($inst['id'], ['revisada' => $ahora, 'mensaje' => 'El programa no respondió ahora (' . $e->getMessage() . '). Todavía no es la hora del borrado.']);
            } else {
                $fallos = (int) $b['fallos'] + 1;
                $primer = $b['primer_fallo'] !== null ? (int) $b['primer_fallo'] : $ahora;
                if ($fallos >= 2 && $ahora - $primer >= $this->confirmarSeg) {
                    $this->poner($inst['id'], ['estado' => 'borrada', 'revisada' => $ahora, 'fallos' => $fallos, 'primer_fallo' => $primer, 'terminada' => $ahora,
                        'mensaje' => 'La instalación ya no responde: el borrado terminó.']);
                    $this->anotar('baja_estado', 'Terminado: la instalación ya no responde (' . $e->codigo . '). Copia final en ' . ($b['copia_en'] ?: '/var/backups/borrados/'), $inst['id']);
                    $this->limpiarCloudflare($inst);
                } else {
                    $this->poner($inst['id'], ['revisada' => $ahora, 'fallos' => $fallos, 'primer_fallo' => $primer, 'estado' => 'en_curso',
                        'mensaje' => 'Ya no responde: se confirma en la próxima revisión.']);
                }
            }
        }
        return $this->obtener($inst['id']);
    }

    /** Revisa todas las que están activas (lo usa bin/revisar.php). @return list<string> */
    public function revisarPendientes(Instancias $ins, ClienteV2 $cv, ?array $usuario = null): array
    {
        $r = [];
        foreach ($this->db->query("SELECT instancia_id FROM bajas WHERE estado IN ('programada','en_curso')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $inst = $ins->obtener($id);
            if (!$inst) continue;
            $b = $this->revisar($inst, $cv, $usuario);
            $r[] = "$id: " . ($b['estado'] ?? '?');
        }
        return $r;
    }

    /**
     * Quita la dirección de Cloudflare (si está configurado en Ajustes). Guarda y anota el resultado.
     * @return array|null el resultado, o null si Cloudflare no está configurado
     */
    public function limpiarCloudflare(array $inst): ?array
    {
        $b = $this->obtener($inst['id']);
        if (!$b) return null;
        try {
            $cf = Cloudflare::desdeAjustes(new Ajustes($this->db), $this->cifrado, $this->cfTransporte);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'tunel' => 'error', 'dns' => 'error', 'mensajes' => ['No se pudo leer el token de Cloudflare guardado: ' . $e->getMessage()]];
            $this->poner($inst['id'], ['cloudflare' => $res + ['momento' => time()]]);
            $this->anotar('cloudflare', implode(' ', $res['mensajes']), $inst['id']);
            return $res;
        }
        if ($cf === null) return null;
        $host = (string) $b['hostname'];
        $panel = strtolower((string) parse_url((string) (getenv('TUKAN_CENTRAL_URL') ?: ''), PHP_URL_HOST));
        if ($host === '' || !Cloudflare::hostValido($host)) {
            $res = ['ok' => false, 'tunel' => 'error', 'dns' => 'error', 'mensajes' => ['No se sabe la dirección de esta instalación: quítala a mano en Cloudflare.']];
        } elseif ($panel !== '' && $host === $panel) {
            $res = ['ok' => false, 'tunel' => 'error', 'dns' => 'error', 'mensajes' => ["$host es la dirección de este panel: no se toca."]];
        } else {
            $res = $cf->quitarHostname($host);
        }
        $this->poner($inst['id'], ['cloudflare' => $res + ['momento' => time()]]);
        $this->anotar('cloudflare', ($res['ok'] ? 'Listo: ' : 'Con problemas: ') . implode(' ', $res['mensajes']), $inst['id']);
        return $res;
    }

    /**
     * Borrado a mano (conector antiguo o sin conexión: el dueño corre borrar_cliente.sh en el servidor): quita SOLO su
     * dirección de Cloudflare. Deja la fila en estado "manual" con el resultado (y sin avisos de caída por Telegram).
     * @return array|null el resultado, o null si Cloudflare no está configurado
     */
    public function soloCloudflare(array $inst, ?string $hostname): ?array
    {
        $b = $this->obtener($inst['id']);
        if ($b && in_array($b['estado'], self::ACTIVAS, true)) {
            return ['ok' => false, 'tunel' => 'error', 'dns' => 'error', 'mensajes' => ['Hay un borrado en marcha: Cloudflare se limpia solo al terminar.']];
        }
        if (!Cloudflare::configurado(new Ajustes($this->db))) return null;
        $campos = ['hostname' => $hostname, 'carpeta' => $inst['carpeta'] ?? null];
        if (!$b || $b['estado'] !== 'borrada') $campos += ['estado' => 'manual', 'mensaje' => 'Borrado a mano (comando en el servidor).', 'revisada' => time()];
        $this->poner($inst['id'], $campos);
        return $this->limpiarCloudflare($inst);
    }

    public function quitar(string $inst): void
    {
        $this->db->prepare('DELETE FROM bajas WHERE instancia_id = ?')->execute([$inst]);
    }
}
