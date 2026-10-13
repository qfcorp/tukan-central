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
        if ($version < 2) {
            // Protocolo v2 (docs/PROTOCOLO_V2.md §9.2): copia local de clientes, solicitudes y finanzas,
            // operaciones, demos, gastos e ingresos extra, gastos fijos y licencias.
            $db->beginTransaction();
            $db->exec(self::ESQUEMA_V2);
            $db->exec('PRAGMA user_version = 2');
            $db->commit();
        }
        // Siguiente: if ($version < 3) { ... PRAGMA user_version = 3 }
    }

    private const ESQUEMA_V2 = <<<'SQL'
CREATE TABLE IF NOT EXISTS capacidades (
    instancia_id TEXT PRIMARY KEY REFERENCES instancias(id) ON DELETE CASCADE,
    protocolo INTEGER NOT NULL,                 -- 1 = programa todavía v1, 2 = v2
    datos TEXT NOT NULL,                        -- JSON de "capacidades" ya revisado
    consultado INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS clientes (
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    cliente_id TEXT NOT NULL,
    nombre TEXT NOT NULL, estado TEXT NOT NULL, estado_texto TEXT, plan TEXT,
    alta TEXT, vence TEXT, dias_para_vencer INTEGER, ultima_actividad TEXT,
    total_pagado REAL NOT NULL DEFAULT 0, primer_pago TEXT, mensual_estimado REAL NOT NULL DEFAULT 0,
    origen TEXT, contacto_cif TEXT,             -- {"nombre","telefono","whatsapp","email"} cifrado
    datos TEXT NOT NULL,                        -- el resto del JSON (sin contacto)
    visto INTEGER NOT NULL,
    PRIMARY KEY (instancia_id, cliente_id)
);
CREATE INDEX IF NOT EXISTS clientes_vence ON clientes (vence);
CREATE TABLE IF NOT EXISTS solicitudes (
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    solicitud_id TEXT NOT NULL, tipo TEXT NOT NULL, categoria TEXT NOT NULL, estado TEXT NOT NULL, prioridad TEXT,
    cliente_id TEXT, fecha TEXT NOT NULL, titulo TEXT, resumen TEXT, monto REAL,
    quien_cif TEXT,                             -- quien + detalle + WhatsApp, cifrado (datos personales)
    datos TEXT NOT NULL,
    primera_vez INTEGER NOT NULL, ultima_vez INTEGER NOT NULL, avisada INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (instancia_id, solicitud_id)
);
CREATE INDEX IF NOT EXISTS solicitudes_estado ON solicitudes (estado, fecha);
CREATE TABLE IF NOT EXISTS operaciones (
    operacion_id TEXT PRIMARY KEY, instancia_id TEXT NOT NULL, accion TEXT NOT NULL, cliente_id TEXT, solicitud_id TEXT,
    usuario_id INTEGER, estado TEXT NOT NULL,   -- enviada | ok | error | sin_respuesta
    http INTEGER, codigo TEXT, mensaje TEXT, resumen TEXT, creada INTEGER NOT NULL, respondida INTEGER,
    cuerpo_cif TEXT                             -- agregado: cuerpo exacto (cifrado) para reintentar byte por byte; se borra al terminar
);
CREATE TABLE IF NOT EXISTS demos (
    id INTEGER PRIMARY KEY, instancia_id TEXT NOT NULL, cliente_id TEXT NOT NULL, nombre TEXT NOT NULL, contacto TEXT,
    plan TEXT, prueba_hasta TEXT, solicitud_id TEXT, creada_por INTEGER, creada INTEGER NOT NULL,
    primer_pago TEXT, monto_primer_pago REAL
);
CREATE UNIQUE INDEX IF NOT EXISTS demos_cliente ON demos (instancia_id, cliente_id);
CREATE TABLE IF NOT EXISTS recordatorios (
    id INTEGER PRIMARY KEY, instancia_id TEXT NOT NULL, cliente_id TEXT NOT NULL, tipo TEXT NOT NULL,
    usuario_id INTEGER, momento INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS recordatorios_cliente ON recordatorios (instancia_id, cliente_id, momento);
CREATE TABLE IF NOT EXISTS finanzas_mes (
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE, mes TEXT NOT NULL, datos TEXT NOT NULL,
    consultado INTEGER NOT NULL, PRIMARY KEY (instancia_id, mes)
);
CREATE TABLE IF NOT EXISTS gastos (
    id INTEGER PRIMARY KEY, fecha TEXT NOT NULL, mes TEXT NOT NULL, categoria TEXT NOT NULL, descripcion TEXT NOT NULL,
    monto REAL NOT NULL CHECK (monto >= 0), producto TEXT, instancia_id TEXT, recurrente_id INTEGER,
    metodo TEXT, referencia TEXT, creado_por INTEGER, creado INTEGER NOT NULL, anulado INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS gastos_mes ON gastos (mes);
CREATE UNIQUE INDEX IF NOT EXISTS gastos_recurrente_mes ON gastos (recurrente_id, mes) WHERE recurrente_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS ingresos_extra (
    id INTEGER PRIMARY KEY, fecha TEXT NOT NULL, mes TEXT NOT NULL, concepto TEXT NOT NULL, monto REAL NOT NULL CHECK (monto >= 0),
    producto TEXT, instancia_id TEXT, recurrente_id INTEGER, metodo TEXT, creado_por INTEGER, creado INTEGER NOT NULL,
    anulado INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS ingresos_recurrente_mes ON ingresos_extra (recurrente_id, mes) WHERE recurrente_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS recurrentes (
    id INTEGER PRIMARY KEY, tipo TEXT NOT NULL CHECK (tipo IN ('gasto','ingreso')), categoria TEXT, descripcion TEXT NOT NULL,
    monto REAL NOT NULL, dia INTEGER NOT NULL CHECK (dia BETWEEN 1 AND 28), producto TEXT, instancia_id TEXT,
    desde_mes TEXT NOT NULL, hasta_mes TEXT, activo INTEGER NOT NULL DEFAULT 1, creado INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS licencias (
    instancia_id TEXT PRIMARY KEY REFERENCES instancias(id) ON DELETE CASCADE,
    valor_mensual REAL NOT NULL DEFAULT 0, activo_hasta TEXT, contacto_cif TEXT, notas TEXT, actualizado INTEGER NOT NULL
);
-- Agregado al §9.2: cuándo se trajo cada cosa de cada programa (y su huella / contadores / MRR)
CREATE TABLE IF NOT EXISTS sincronizacion (
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    que TEXT NOT NULL,                          -- capacidades | clientes | solicitudes | finanzas
    momento INTEGER NOT NULL, huella TEXT, datos TEXT,
    PRIMARY KEY (instancia_id, que)
);
SQL;

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
