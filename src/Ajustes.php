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
    ];

    public function __construct(private PDO $db) {}

    public function valor(string $clave): string
    {
        $st = $this->db->prepare('SELECT valor FROM ajustes WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? (self::PREDETERMINADOS[$clave] ?? '') : (string) $v;
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
