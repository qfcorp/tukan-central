<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/** Ajustes del panel (clave → valor). */
final class Ajustes
{
    public const PREDETERMINADOS = [
        'avisar_caidas'   => '1',   // Telegram cuando un programa se cae o vuelve
        'avisar_negocios' => '1',   // Telegram cuando se registra un negocio nuevo
        // Protocolo v2 (§9.2)
        'avisar_solicitudes' => '1',            // Telegram por cada solicitud nueva
        'avisar_tipos' => '',                   // tipos a avisar, separados por coma ('' = los pedidos)
        'silencio_desde' => '22:00',            // las de la noche salen juntas al terminar el silencio
        'silencio_hasta' => '07:00',
        'resumen_diario' => '1',                // ☀️ a las 07:30
        'repartir_gastos_generales' => '0',     // Finanzas: repartir los gastos sin programa según lo que gana cada uno
        'wa_recordar_prueba' => '', 'wa_recordar_renovacion' => '', 'wa_vencido' => '', 'wa_bienvenida' => '',   // '' = el texto de fábrica
    ];
    /** Ajustes que son casillas (sí / no). */
    public const CASILLAS = ['avisar_caidas', 'avisar_negocios', 'avisar_solicitudes', 'resumen_diario', 'repartir_gastos_generales'];

    public function __construct(private PDO $db) {}

    public function valor(string $clave): string
    {
        $st = $this->db->prepare('SELECT valor FROM ajustes WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? (self::PREDETERMINADOS[$clave] ?? '') : (string) $v;
    }

    /** Plantilla de WhatsApp (la de Ajustes o la de fábrica). */
    public function plantilla(string $clave): string
    {
        $v = trim($this->valor($clave));
        return $v !== '' ? $v : (Contacto::PLANTILLAS[$clave] ?? '');
    }

    public function si(string $clave): bool
    {
        return $this->valor($clave) === '1';
    }

    public function guardar(string $clave, string $valor): void
    {
        $this->db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = excluded.valor')
            ->execute([$clave, $valor]);
    }
}
