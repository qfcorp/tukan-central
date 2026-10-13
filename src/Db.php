<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Base de datos SQLite en datos/central.sqlite (fuera de la carpeta pública).
 * Las tablas se crean solas la primera vez; los cambios futuros van en MIGRACIONES (PRAGMA user_version).
 */
final class Db
{
    public static function carpetaDatos(): string
    {
        $d = (string) getenv('TUKAN_CENTRAL_DATOS');
        return rtrim($d !== '' ? $d : dirname(__DIR__) . '/datos', '/');
    }

    public static function conectar(?string $archivo = null): PDO
    {
        $archivo ??= self::carpetaDatos() . '/central.sqlite';
        $dir = dirname($archivo);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $nueva = !is_file($archivo);
        $db = new PDO('sqlite:' . $archivo, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA busy_timeout = 8000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA synchronous = NORMAL');
        if ($nueva) @chmod($archivo, 0640);
        self::migrar($db);
        return $db;
    }

    public static function migrar(PDO $db): void
    {
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $db->exec(self::ESQUEMA);
            $db->exec('PRAGMA user_version = 1');
        }
        // Aquí irán las migraciones siguientes: if ($version < 2) { ... PRAGMA user_version = 2 }
    }

    private const ESQUEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY,
    nombre TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    clave_hash TEXT NOT NULL,
    rol TEXT NOT NULL DEFAULT 'dueno' CHECK (rol IN ('dueno', 'soporte')),
    activo INTEGER NOT NULL DEFAULT 1,
    totp_cif TEXT,                              -- secreto de 2FA cifrado (NULL = falta activarlo)
    totp_ultimo_paso INTEGER NOT NULL DEFAULT 0, -- para no aceptar dos veces el mismo código
    cambiar_clave INTEGER NOT NULL DEFAULT 0,
    creado INTEGER NOT NULL,
    actualizado INTEGER NOT NULL,
    ultimo_ingreso INTEGER
);
CREATE TABLE IF NOT EXISTS codigos_respaldo (
    id INTEGER PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    hash TEXT NOT NULL,
    usado INTEGER
);
CREATE TABLE IF NOT EXISTS intentos_fallidos (
    id INTEGER PRIMARY KEY,
    clave TEXT NOT NULL,          -- "email:..." o "ip:..."
    momento INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS intentos_clave ON intentos_fallidos (clave, momento);
CREATE TABLE IF NOT EXISTS sesiones (
    id TEXT PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    ip TEXT,
    agente TEXT,
    creada INTEGER NOT NULL,
    ultima INTEGER NOT NULL,
    cerrada INTEGER,
    motivo TEXT
);
CREATE INDEX IF NOT EXISTS sesiones_usuario ON sesiones (usuario_id, creada);
CREATE TABLE IF NOT EXISTS instancias (
    id TEXT PRIMARY KEY,
    nombre TEXT NOT NULL,
    producto TEXT NOT NULL,
    url TEXT NOT NULL,
    secreto_cif TEXT NOT NULL,
    activo INTEGER NOT NULL DEFAULT 1,
    notas TEXT NOT NULL DEFAULT '',
    plantilla TEXT,               -- comando propio (NULL = el del tipo de programa)
    carpeta TEXT,                 -- TuGuardería: carpeta de la instalación
    orden INTEGER NOT NULL DEFAULT 0,
    creado INTEGER NOT NULL,
    actualizado INTEGER NOT NULL,
    secreto_cambiado INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS estados (
    instancia_id TEXT PRIMARY KEY REFERENCES instancias(id) ON DELETE CASCADE,
    consultado INTEGER,
    en_linea INTEGER NOT NULL DEFAULT 0,
    http INTEGER,
    ms INTEGER,
    error TEXT,
    error_detalle TEXT,
    datos TEXT,                   -- última respuesta buena (JSON ya revisado)
    datos_momento INTEGER,
    fallos_seguidos INTEGER NOT NULL DEFAULT 0,
    aviso_caido INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS historial (
    id INTEGER PRIMARY KEY,
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    momento INTEGER NOT NULL,
    en_linea INTEGER NOT NULL,
    ms INTEGER
);
CREATE INDEX IF NOT EXISTS historial_inst ON historial (instancia_id, momento);
CREATE TABLE IF NOT EXISTS auditoria (
    id INTEGER PRIMARY KEY,
    momento INTEGER NOT NULL,
    usuario_id INTEGER,
    email TEXT,
    accion TEXT NOT NULL,
    detalle TEXT NOT NULL DEFAULT '',
    instancia_id TEXT,
    ip TEXT,
    agente TEXT
);
CREATE INDEX IF NOT EXISTS auditoria_momento ON auditoria (momento);
CREATE INDEX IF NOT EXISTS auditoria_accion ON auditoria (accion, momento);
CREATE TABLE IF NOT EXISTS ajustes (
    clave TEXT PRIMARY KEY,
    valor TEXT NOT NULL
);
SQL;
}
