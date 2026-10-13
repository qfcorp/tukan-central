<?php
declare(strict_types=1);

namespace Tukan;

use PDO;
use RuntimeException;

/**
 * Programas conectados (instancias). Cada una tiene su ID (el mismo que TUKAN_CENTRAL_ID en el programa)
 * y su secreto de 64 hex (guardado cifrado; solo se muestra una vez, dentro del comando para el servidor).
 */
final class Instancias
{
    private ?Cifrado $cifrado;

    public function __construct(private PDO $db, ?Cifrado $cifrado = null)
    {
        $this->cifrado = $cifrado;
    }

    private function cifrado(): Cifrado
    {
        return $this->cifrado ??= new Cifrado();
    }

    /** ID del protocolo: minúsculas, números, - y _, de 2 a 40. Aquí además debe empezar con letra o número. */
    public static function idValido(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $id);
    }

    /**
     * Dirección base del programa: https obligatorio (http solo para 127.0.0.1/localhost en pruebas).
     * Se guarda sin "/" al final. Lanza error con el motivo en palabras simples.
     */
    public static function normalizarUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^[a-z]+://#i', $url)) $url = 'https://' . $url;
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['https', 'http'], true)) {
            throw new RuntimeException('Escribe la dirección completa del programa, por ejemplo https://tuevento.tukanec.com');
        }
        $host = strtolower($p['host']);
        $local = in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
        if (strtolower($p['scheme']) !== 'https' && !$local) {
            throw new RuntimeException('La dirección debe empezar con https:// (sin candado no es seguro).');
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new RuntimeException('Escribe solo la dirección del programa, sin ? ni #.');
        }
        if (!preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:]+\]$/', $host)) throw new RuntimeException('La dirección tiene letras no válidas.');
        $ruta = rtrim($p['path'] ?? '', '/');
        if ($ruta !== '' && !preg_match('#^[A-Za-z0-9._~/-]+$#', $ruta)) throw new RuntimeException('La dirección tiene letras no válidas.');
        if (str_ends_with($ruta, '/tukan_central.php')) $ruta = substr($ruta, 0, -strlen('/tukan_central.php'));
        return strtolower($p['scheme']) . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $ruta;
    }

    /** Origen (esquema://host:puerto) para la política de seguridad del navegador. */
    public static function origen(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function nuevoSecreto(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function obtener(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM instancias WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function lista(bool $soloActivas = false): array
    {
        return $this->db->query('SELECT * FROM instancias' . ($soloActivas ? ' WHERE activo = 1' : '') . ' ORDER BY activo DESC, orden, nombre')->fetchAll();
    }

    /** Orígenes de los programas activos (para permitir el envío del formulario "Entrar"). */
    public function origenes(): array
    {
        $o = [];
        foreach ($this->db->query('SELECT url FROM instancias WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $o[self::origen($u)] = true;
        }
        return array_keys($o);
    }

    /** Revisa y limpia los datos del formulario. */
    private function limpiar(array $d, string $id): array
    {
        $tipo = (string) ($d['producto'] ?? '');
        if (!Productos::existe($tipo)) throw new RuntimeException('Elige el tipo de programa.');
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '') $nombre = Productos::nombre($tipo);
        if (mb_strlen($nombre) > 60) throw new RuntimeException('El nombre es muy largo (máximo 60 letras).');
        $url = self::normalizarUrl((string) ($d['url'] ?? ''));
        $plantilla = trim((string) ($d['plantilla'] ?? ''));
        if ($plantilla === Productos::tipo($tipo)['comando']) $plantilla = '';
        if ($plantilla !== '' && ($e = Productos::errorPlantilla($plantilla)) !== null) throw new RuntimeException($e);
        $efectiva = $plantilla !== '' ? $plantilla : Productos::tipo($tipo)['comando'];
        $carpeta = trim((string) ($d['carpeta'] ?? ''));
        if (Productos::pideCarpeta($efectiva)) {
            // Si no la escriben, se usa el ID (dogssy -> dogssy, huellitas-sur -> huellitas_sur)
            if ($carpeta === '' && Productos::carpetaValida(str_replace('-', '_', $id))) $carpeta = str_replace('-', '_', $id);
            if ($carpeta === '') throw new RuntimeException('Escribe la carpeta de la instalación (ej. dogssy).');
            if (!Productos::carpetaValida($carpeta)) throw new RuntimeException('La carpeta solo puede tener minúsculas, números y _ (ej. dogssy).');
        } elseif ($carpeta !== '' && !Productos::carpetaValida($carpeta)) {
            throw new RuntimeException('La carpeta solo puede tener minúsculas, números y _ (ej. dogssy).');
        }
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 1000);
        return [
            'producto' => $tipo, 'nombre' => $nombre, 'url' => $url, 'plantilla' => $plantilla === '' ? null : $plantilla,
            'carpeta' => $carpeta === '' ? null : $carpeta, 'notas' => $notas, 'activo' => !empty($d['activo']) ? 1 : 0,
        ];
    }

    /** Agrega un programa. Devuelve el secreto nuevo (en texto, para armar el comando UNA vez). */
    public function crear(array $d, ?int $ahora = null): string
    {
        $ahora ??= time();
        $id = strtolower(trim((string) ($d['id'] ?? '')));
        if (!self::idValido($id)) throw new RuntimeException('El ID solo puede tener minúsculas, números, - y _ (de 2 a 40, empezando con letra o número). Ej: tuevento, dogssy.');
        if ($this->obtener($id)) throw new RuntimeException("Ya hay un programa con el ID \"$id\".");
        $d['activo'] = $d['activo'] ?? 1;
        $c = $this->limpiar($d, $id);
        $secreto = self::nuevoSecreto();
        $orden = (int) $this->db->query('SELECT coalesce(max(orden), 0) + 1 FROM instancias')->fetchColumn();
        $this->db->prepare('INSERT INTO instancias (id, nombre, producto, url, secreto_cif, activo, notas, plantilla, carpeta, orden, creado, actualizado, secreto_cambiado)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $c['nombre'], $c['producto'], $c['url'], $this->cifrado()->cifrar($secreto, 'instancia:' . $id), $c['activo'],
                       $c['notas'], $c['plantilla'], $c['carpeta'], $orden, $ahora, $ahora, $ahora]);
        return $secreto;
    }

    /** Edita un programa (el ID no cambia: es el que tiene guardado el programa). Devuelve qué cambió. */
    public function editar(string $id, array $d): array
    {
        $antes = $this->obtener($id) ?? throw new RuntimeException('Ese programa no existe.');
        $c = $this->limpiar($d, $id);
        $cambios = [];
        foreach ($c as $k => $v) {
            if ((string) ($antes[$k] ?? '') !== (string) ($v ?? '')) $cambios[] = $k;
        }
        $this->db->prepare('UPDATE instancias SET nombre = ?, producto = ?, url = ?, activo = ?, notas = ?, plantilla = ?, carpeta = ?, actualizado = ? WHERE id = ?')
            ->execute([$c['nombre'], $c['producto'], $c['url'], $c['activo'], $c['notas'], $c['plantilla'], $c['carpeta'], time(), $id]);
        return $cambios;
    }

    /** Secreto nuevo: el anterior deja de servir de inmediato. */
    public function rotar(string $id, ?int $ahora = null): string
    {
        if (!$this->obtener($id)) throw new RuntimeException('Ese programa no existe.');
        $ahora ??= time();
        $secreto = self::nuevoSecreto();
        $this->db->prepare('UPDATE instancias SET secreto_cif = ?, secreto_cambiado = ?, actualizado = ? WHERE id = ?')
            ->execute([$this->cifrado()->cifrar($secreto, 'instancia:' . $id), $ahora, $ahora, $id]);
        return $secreto;
    }

    public function eliminar(string $id): void
    {
        $this->db->prepare('DELETE FROM instancias WHERE id = ?')->execute([$id]);
    }

    public function secreto(array|string $inst): string
    {
        $i = is_array($inst) ? $inst : ($this->obtener($inst) ?? throw new RuntimeException('Ese programa no existe.'));
        return $this->cifrado()->descifrar($i['secreto_cif'], 'instancia:' . $i['id']);
    }

    public static function plantilla(array $inst): string
    {
        return ($inst['plantilla'] ?? '') !== '' && $inst['plantilla'] !== null ? $inst['plantilla'] : Productos::tipo($inst['producto'])['comando'];
    }

    /** Comando exacto para pegar en el servidor del programa. */
    public static function comando(array $inst, string $secreto, string $urlCentral): string
    {
        return Productos::comando(self::plantilla($inst), [
            'ID' => $inst['id'], 'SECRETO' => $secreto, 'URL' => $urlCentral, 'CARPETA' => $inst['carpeta'] ?? '',
        ]);
    }
}
