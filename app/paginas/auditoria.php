<?php
declare(strict_types=1);

use Tukan\Auditoria;

/** Auditoría con filtros. */
$f = [
    'grupo' => param('grupo'), 'accion' => param('accion'), 'usuario' => param('usuario'),
    'instancia' => param('instancia'), 'desde' => param('desde'), 'hasta' => param('hasta'), 'q' => param('q'),
];
if (!isset(Auditoria::ACCIONES[$f['accion']])) $f['accion'] = '';
$pagina = max(1, (int) param('pagina', '1'));
$r = (new Auditoria(db()))->buscar($f, $pagina, 50);
vista('auditoria', [
    'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => 50,
    'emails' => db()->query('SELECT DISTINCT email FROM auditoria WHERE email IS NOT NULL ORDER BY email LIMIT 200')->fetchAll(PDO::FETCH_COLUMN),
    'instancias' => db()->query('SELECT DISTINCT instancia_id FROM auditoria WHERE instancia_id IS NOT NULL ORDER BY instancia_id')->fetchAll(PDO::FETCH_COLUMN),
], 'Auditoría · Panel Tukán');
