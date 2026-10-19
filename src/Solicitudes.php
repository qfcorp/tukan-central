<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Bandeja única de solicitudes de todos los programas (PROTOCOLO_V2 §5.8 y §9.5 B).
 * Copia local: lo personal (quién, detalle y los botones de WhatsApp) va cifrado en quien_cif.
 */
final class Solicitudes
{
    public const ABIERTAS = ['pendiente', 'en_proceso'];
    public const CERRADAS = ['atendida', 'descartada', 'cerrada'];   // "cerrada" = el programa ya no la manda abierta

    public const TIPOS = [
        'registro_nuevo' => ['Pidió una demo (registro)', '🆕'], 'demo_pedida' => ['Pedido de demo', '📝'], 'comprobante' => ['Comprobante por revisar', '💵'],
        'cambio_plan' => ['Cambio de plan', '🔁'], 'soporte' => ['Ticket de soporte', '🎫'], 'derechos_datos' => ['Datos personales (LOPDP)', '🛡️'],
        'pago_automatico' => ['Pago automático', '💳'], 'seguimiento_prueba' => ['Demo por terminar', '⏳'], 'renovacion' => ['Renovación', '📅'],
        'dominio_gratis' => ['Dominio incluido por registrar', '🌐'], 'comision_vendedor' => ['Comisión de vendedor', '🤝'],
        'liquidacion_pendiente' => ['Dinero por transferir', '🏦'],
        // Sin demos gratis: el registro público es un pedido de demo (los programas lo llaman "demo" o "alta")
        'demo' => ['Pedido de demo', '📝'], 'alta' => ['Pedido de demo', '📝'],
        // Anexo B: el cliente pidió encender o apagar módulos desde «Mi plan»
        'cambio_modulos' => ['Cambio de módulos', '🧩'],
    ];

    /** Chips de la bandeja: clave => [texto, tipos (o "recordatorio" = la categoría)] */
    public const FILTROS = [
        'todo' => ['Todo', []],
        'pagos' => ['Pagos por aprobar', ['comprobante', 'cambio_plan']],
        'demos' => ['Pedidos de demo', ['demo_pedida', 'demo', 'alta', 'registro_nuevo']],
        'modulos' => ['Módulos', ['cambio_modulos']],
        'soporte' => ['Soporte', ['soporte', 'derechos_datos']],
        'recordatorios' => ['Recordatorios', ['*recordatorio']],
    ];

    public const MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private ?Cifrado $cifrado;

    public function __construct(private PDO $db, ?Cifrado $cifrado = null)
    {
        $this->cifrado = $cifrado;
    }

    private function cifrado(): Cifrado
    {
        return $this->cifrado ??= new Cifrado();
    }

    public static function nombreTipo(string $tipo): string
    {
        return self::TIPOS[$tipo][0] ?? ucfirst(str_replace('_', ' ', $tipo));
    }

    public static function iconoTipo(string $tipo): string
    {
        return self::TIPOS[$tipo][1] ?? '📌';
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
        $v = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Detalle libre del programa: solo textos, números, booleanos, listas y objetos (profundidad 4). */
    private static function detalle(mixed $v, int $nivel = 0): mixed
    {
        if ($nivel > 4) return null;
        if (is_string($v)) return mb_substr($v, 0, 6000);
        if (is_int($v) || is_float($v) || is_bool($v) || $v === null) return $v;
        if (!is_array($v)) return null;
        $r = [];
        foreach (array_slice($v, 0, 200, true) as $k => $x) {
            if (!is_int($k) && !preg_match('/^[A-Za-z0-9_]{1,40}$/', (string) $k)) continue;
            $r[$k] = self::detalle($x, $nivel + 1);
        }
        return $r;
    }

    /** Revisa una solicitud (§5.8). null si no tiene id ni tipo. */
    public static function limpiar(mixed $s): ?array
    {
        if (!is_array($s) || !is_string($s['id'] ?? null) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $s['id'])) return null;
        if (!is_string($s['tipo'] ?? null) || !preg_match('/^[a-z][a-z_]{1,29}$/', $s['tipo'])) return null;
        $q = is_array($s['quien'] ?? null) ? $s['quien'] : [];
        $adj = [];
        foreach (is_array($s['adjuntos'] ?? null) ? array_slice($s['adjuntos'], 0, 20) : [] as $a) {
            if (!is_array($a)) continue;
            $aid = is_int($a['id'] ?? null) ? (string) $a['id'] : ($a['id'] ?? null);
            if (!is_string($aid) || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $aid)) continue;
            $adj[] = ['id' => $aid, 'nombre' => self::texto($a['nombre'] ?? null, 120) ?? 'archivo',
                      'tipo_mime' => in_array($a['tipo_mime'] ?? null, self::MIME, true) ? $a['tipo_mime'] : 'application/octet-stream'];
        }
        $fecha = self::texto($s['fecha'] ?? null, 40);
        $cid = is_int($s['cliente_id'] ?? null) ? (string) $s['cliente_id'] : ($s['cliente_id'] ?? null);
        $venceEl = is_string($s['vence_el'] ?? null) && \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s['vence_el'], 0, 10)) ? substr($s['vence_el'], 0, 10) : null;
        return [
            'id' => $s['id'],
            'tipo' => $s['tipo'],
            'categoria' => in_array($s['categoria'] ?? null, ['pedido', 'recordatorio', 'aviso'], true) ? $s['categoria'] : 'aviso',
            'titulo' => self::texto($s['titulo'] ?? null, 150) ?? self::nombreTipo($s['tipo']),
            'fecha' => $fecha !== null && strtotime($fecha) !== false ? $fecha : date('c'),
            'actualizado' => self::texto($s['actualizado'] ?? null, 40),
            'cliente_id' => Clientes::idValido($cid) ? $cid : null,
            'quien' => [
                'nombre' => self::texto($q['nombre'] ?? null, 100), 'organizacion' => self::texto($q['organizacion'] ?? null, 120),
                'telefono' => self::texto($q['telefono'] ?? null, 40), 'whatsapp' => Contacto::numeroWhatsapp(is_string($q['whatsapp'] ?? null) ? $q['whatsapp'] : null),
                'email' => is_string($q['email'] ?? null) && filter_var($q['email'], FILTER_VALIDATE_EMAIL) ? mb_substr($q['email'], 0, 150) : null,
            ],
            'resumen' => self::texto($s['resumen'] ?? null, 300),
            'monto' => is_int($s['monto'] ?? null) || is_float($s['monto'] ?? null) ? round((float) $s['monto'], 2) : null,
            'estado' => in_array($s['estado'] ?? null, ['pendiente', 'en_proceso', 'atendida', 'descartada'], true) ? $s['estado'] : 'pendiente',
            'estado_producto' => self::texto($s['estado_producto'] ?? null, 30),
            'prioridad' => in_array($s['prioridad'] ?? null, ['alta', 'normal', 'baja'], true) ? $s['prioridad'] : 'normal',
            'vence_el' => $venceEl,
            'detalle' => is_array($s['detalle'] ?? null) ? self::detalle($s['detalle']) : [],
            'adjuntos' => $adj,
            'acciones_posibles' => Formulario::limpiarAcciones($s['acciones_posibles'] ?? []),
            'ir' => Formulario::rutaValida($s['ir'] ?? null) ? $s['ir'] : null,
        ];
    }

    /** Separa lo personal (va cifrado) del resto. */
    private function partir(string $inst, array $s): array
    {
        $privado = ['quien' => $s['quien'], 'detalle' => $s['detalle'], 'whatsapp' => []];
        $publico = $s;
        unset($publico['quien'], $publico['detalle']);
        $publico['acciones_posibles'] = [];
        foreach ($s['acciones_posibles'] as $i => $a) {
            if ($a['accion'] === 'whatsapp') {
                $privado['whatsapp'][$i] = $a;
                $publico['acciones_posibles'][] = ['accion' => 'whatsapp', 'etiqueta' => $a['etiqueta'], 'privado' => $i, 'estilo' => $a['estilo'], 'datos' => [], 'campos' => [], 'confirmar' => null];
            } else {
                $publico['acciones_posibles'][] = $a;
            }
        }
        $publico['tiene_quien'] = array_filter($s['quien'], fn($v) => $v !== null) !== [];
        $cif = $this->cifrado()->cifrar(json_encode($privado, JSON_UNESCAPED_UNICODE), "solicitud:$inst:{$s['id']}");
        return [$publico, $cif];
    }

    public function guardarUna(string $inst, array $s, ?int $ahora = null, bool $avisar = true): bool
    {
        $ahora ??= time();
        [$publico, $cif] = $this->partir($inst, $s);
        $st = $this->db->prepare('SELECT estado FROM solicitudes WHERE instancia_id = ? AND solicitud_id = ?');
        $st->execute([$inst, $s['id']]);
        $antes = $st->fetchColumn();
        $nueva = $antes === false;
        $this->db->prepare('INSERT INTO solicitudes (instancia_id, solicitud_id, tipo, categoria, estado, prioridad, cliente_id, fecha, titulo, resumen, monto,
                              quien_cif, datos, primera_vez, ultima_vez, avisada)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                            ON CONFLICT (instancia_id, solicitud_id) DO UPDATE SET tipo = excluded.tipo, categoria = excluded.categoria, estado = excluded.estado,
                              prioridad = excluded.prioridad, cliente_id = excluded.cliente_id, fecha = excluded.fecha, titulo = excluded.titulo,
                              resumen = excluded.resumen, monto = excluded.monto, quien_cif = excluded.quien_cif, datos = excluded.datos, ultima_vez = excluded.ultima_vez,
                              avisada = CASE WHEN excluded.estado IN (\'pendiente\', \'en_proceso\') THEN solicitudes.avisada ELSE 1 END')
            ->execute([$inst, $s['id'], $s['tipo'], $s['categoria'], $s['estado'], $s['prioridad'], $s['cliente_id'], $s['fecha'], $s['titulo'],
                       $s['resumen'], $s['monto'], $cif, json_encode($publico, JSON_UNESCAPED_UNICODE), $ahora, $ahora,
                       $avisar && in_array($s['estado'], self::ABIERTAS, true) ? 0 : 1]);
        return $nueva;
    }

    /**
     * Guarda la lista de abiertas de un programa: inserta las nuevas, actualiza las que siguen y
     * marca "cerrada" las que ya no vienen. $avisar = false la primera vez (no se avisa lo viejo).
     * @return array{nuevas: list<string>, cerradas: int}
     */
    public function guardarAbiertas(string $inst, array $lista, ?int $ahora = null, bool $avisar = true): array
    {
        $ahora ??= time();
        $this->db->beginTransaction();
        $vinieron = [];
        $nuevas = [];
        foreach ($lista as $s) {
            if (($l = self::limpiar($s)) === null) continue;
            $vinieron[] = $l['id'];
            if ($this->guardarUna($inst, $l, $ahora, $avisar)) $nuevas[] = $l['id'];
        }
        $st = $this->db->prepare("SELECT solicitud_id FROM solicitudes WHERE instancia_id = ? AND estado IN ('pendiente', 'en_proceso')");
        $st->execute([$inst]);
        $cerrar = array_diff($st->fetchAll(PDO::FETCH_COLUMN), $vinieron);
        $up = $this->db->prepare("UPDATE solicitudes SET estado = 'cerrada', ultima_vez = ?, avisada = 1 WHERE instancia_id = ? AND solicitud_id = ?");
        foreach ($cerrar as $id) $up->execute([$ahora, $inst, $id]);
        $this->db->prepare("DELETE FROM solicitudes WHERE estado NOT IN ('pendiente', 'en_proceso') AND ultima_vez < ?")->execute([$ahora - 90 * 86400]);
        $this->db->commit();
        return ['nuevas' => $nuevas, 'cerradas' => count($cerrar)];
    }

    /** Trae las abiertas de un programa (todas las páginas). */
    public function refrescar(array $inst, ClienteV2 $cv, ?array $usuario = null, ?string $huella = null, ?int $ahora = null): array
    {
        $ahora ??= time();
        $sync = new Sincronizacion($this->db);
        $primera = $sync->momento($inst['id'], 'solicitudes') === null;
        $todas = [];
        $pagina = 1;
        do {
            $r = $cv->pedir($inst, 'solicitudes', ['estado' => 'abiertas', 'pagina' => $pagina, 'por_pagina' => 100], $usuario);
            $lista = is_array($r['solicitudes'] ?? null) && array_is_list($r['solicitudes']) ? $r['solicitudes'] : [];
            array_push($todas, ...$lista);
            $paginas = is_int($r['paginas'] ?? null) ? $r['paginas'] : 1;
            $pagina++;
        } while ($pagina <= min($paginas, 20) && $lista !== []);
        $res = $this->guardarAbiertas($inst['id'], $todas, $ahora, !$primera);
        $sync->guardar($inst['id'], 'solicitudes', $ahora, $huella);
        return $res + ['primera' => $primera, 'total' => count($todas)];
    }

    // ------------------------------------------------------------- lectura

    /** Fila completa (con lo personal descifrado si $conContacto; para soporte, enmascarado y sin WhatsApp). */
    public function completa(array $f, bool $conContacto): array
    {
        $d = is_array($f['datos']) ? $f['datos'] : (json_decode((string) $f['datos'], true) ?: []);
        $privado = ['quien' => [], 'detalle' => [], 'whatsapp' => []];
        if (!empty($f['quien_cif'])) {
            try {
                $privado = json_decode($this->cifrado()->descifrar($f['quien_cif'], "solicitud:{$f['instancia_id']}:{$f['solicitud_id']}"), true) ?: $privado;
            } catch (\Throwable) {
            }
        }
        $quien = $privado['quien'] ?? [];
        $acciones = [];
        foreach ($d['acciones_posibles'] ?? [] as $a) {
            if ($a['accion'] === 'whatsapp') {
                if (!$conContacto) continue;
                $a = $privado['whatsapp'][$a['privado'] ?? -1] ?? null;
                if (!$a) continue;
            }
            $acciones[] = $a;
        }
        if (!$conContacto) {
            $quien['telefono'] = Contacto::telefono($quien['telefono'] ?? null);
            $quien['email'] = Contacto::email($quien['email'] ?? null);
            $quien['whatsapp'] = null;
            $privado['detalle'] = Contacto::enmascararTodo($privado['detalle'] ?? []);
        }
        return $d + [
            'instancia_id' => $f['instancia_id'], 'programa' => $f['programa'] ?? null, 'producto' => $f['producto'] ?? null,
            'estado_local' => $f['estado'], 'primera_vez' => (int) $f['primera_vez'], 'ultima_vez' => (int) $f['ultima_vez'],
        ] + ['quien' => $quien, 'detalle' => $privado['detalle'] ?? [], 'acciones' => $acciones];
    }

    public function obtener(string $inst, string $id): ?array
    {
        $st = $this->db->prepare('SELECT s.*, i.nombre AS programa, i.producto FROM solicitudes s JOIN instancias i ON i.id = s.instancia_id WHERE s.instancia_id = ? AND s.solicitud_id = ?');
        $st->execute([$inst, $id]);
        return $st->fetch() ?: null;
    }

    private static function rango(string $tipo): int
    {
        return ['comprobante' => 0, 'cambio_plan' => 0, 'demo_pedida' => 1, 'registro_nuevo' => 2, 'derechos_datos' => 2, 'soporte' => 3][$tipo] ?? 5;
    }

    public static function enFiltro(array $f, string $chip): bool
    {
        $tipos = self::FILTROS[$chip][1] ?? [];
        if ($tipos === []) return true;
        if ($tipos === ['*recordatorio']) return $f['categoria'] === 'recordatorio';
        return in_array($f['tipo'], $tipos, true);
    }

    /**
     * Bandeja: pestaña (pendiente | en_proceso | atendidas), chip (FILTROS), inst, buscar.
     * @return array{filas: array, pestanas: array, chips: array}
     */
    public function lista(array $f, bool $conContacto): array
    {
        $pestana = in_array($f['pestana'] ?? '', ['pendiente', 'en_proceso', 'atendidas'], true) ? $f['pestana'] : 'pendiente';
        $chip = isset(self::FILTROS[$f['chip'] ?? '']) ? $f['chip'] : 'todo';
        $sql = 'SELECT s.*, i.nombre AS programa, i.producto FROM solicitudes s JOIN instancias i ON i.id = s.instancia_id AND i.activo = 1 WHERE (s.estado IN (\'pendiente\', \'en_proceso\') OR s.ultima_vez > ?)';
        $p = [time() - 60 * 86400];
        if (($f['inst'] ?? '') !== '') {
            $sql .= ' AND s.instancia_id = ?';
            $p[] = $f['inst'];
        }
        $st = $this->db->prepare($sql);
        $st->execute($p);
        $q = mb_strtolower(trim((string) ($f['buscar'] ?? '')));
        $qDig = preg_replace('/\D+/', '', $q) ?? '';
        $pestanas = ['pendiente' => 0, 'en_proceso' => 0, 'atendidas' => 0];
        $chips = array_fill_keys(array_keys(self::FILTROS), 0);
        $filas = [];
        foreach ($st->fetchAll() as $fila) {
            $s = $this->completa($fila, $conContacto);
            if ($q !== '') {
                $texto = mb_strtolower(implode(' ', array_filter([$s['titulo'], $s['resumen'], $s['quien']['nombre'] ?? null, $s['quien']['organizacion'] ?? null,
                                                                   $conContacto ? ($s['quien']['email'] ?? null) : null])));
                $tel = $conContacto ? preg_replace('/\D+/', '', (string) ($s['quien']['telefono'] ?? '')) . ' ' . ($s['quien']['whatsapp'] ?? '') : '';
                if (!str_contains($texto, $q) && !(strlen($qDig) >= 4 && str_contains($tel, $qDig))) continue;
            }
            $tab = in_array($fila['estado'], self::CERRADAS, true) ? 'atendidas' : $fila['estado'];
            $pestanas[$tab]++;
            if ($tab !== $pestana) continue;
            foreach (array_keys(self::FILTROS) as $c) if (self::enFiltro($fila, $c)) $chips[$c]++;
            if (!self::enFiltro($fila, $chip)) continue;
            $filas[] = $s;
        }
        $prio = ['alta' => 0, 'normal' => 1, 'baja' => 2];
        usort($filas, fn($a, $b) => $pestana === 'atendidas'
            ? $b['ultima_vez'] <=> $a['ultima_vez']
            : [$prio[$a['prioridad']] ?? 1, self::rango($a['tipo']), -strtotime($a['fecha'])] <=> [$prio[$b['prioridad']] ?? 1, self::rango($b['tipo']), -strtotime($b['fecha'])]);
        return ['filas' => $filas, 'pestanas' => $pestanas, 'chips' => $chips, 'pestana' => $pestana, 'chip' => $chip];
    }

    /**
     * ¿Es un recordatorio de "la demo terminó sin pagar" o "el plan venció sin renovar"?
     * Devuelve el motivo sugerido para pausar o borrar al cliente (o null si es otra cosa).
     * Se mira la etapa (seguimiento_prueba: terminada · renovacion: vencida) y, si el programa no la manda, el título.
     */
    public static function motivoSinPagar(array $s): ?string
    {
        $tipo = (string) ($s['tipo'] ?? '');
        $etapa = (string) ($s['detalle']['etapa'] ?? '');
        if ($tipo === 'seguimiento_prueba' && in_array($etapa, ['terminada', 'vencida', 'sin_pagar'], true)) return 'No pagó después de la demo';
        if ($tipo === 'renovacion' && in_array($etapa, ['vencida', 'sin_pagar'], true)) return 'No pagó la renovación del plan';
        if (!in_array($tipo, ['seguimiento_prueba', 'renovacion'], true) && ($s['categoria'] ?? '') !== 'recordatorio') return null;
        $t = mb_strtolower((string) ($s['titulo'] ?? ''));
        if (preg_match('/(demo|prueba)\s+(termin[oó]|terminada|vencida|venci[oó])\s+sin\s+pagar/u', $t)) return 'No pagó después de la demo';
        if (preg_match('/(vencid[oa]|venci[oó])\s+sin\s+(renovar|pagar)|no\s+renov[oó]/u', $t)) return 'No pagó la renovación del plan';
        return null;
    }

    /** La marca atendida en la copia local (cuando el programa ya no la tiene, p. ej. porque se borró el cliente). */
    public function marcarAtendida(string $inst, string $id, ?int $ahora = null): bool
    {
        $st = $this->db->prepare("UPDATE solicitudes SET estado = 'atendida', ultima_vez = ?, avisada = 1 WHERE instancia_id = ? AND solicitud_id = ?");
        $st->execute([$ahora ?? time(), $inst, $id]);
        return $st->rowCount() > 0;
    }

    /** Número rojo del menú: solicitudes por atender. */
    public function porAtender(): int
    {
        return (int) $this->db->query("SELECT count(*) FROM solicitudes s JOIN instancias i ON i.id = s.instancia_id AND i.activo = 1 WHERE s.estado = 'pendiente'")->fetchColumn();
    }

    /** Por programa: abiertas. */
    public function porPrograma(): array
    {
        return $this->db->query("SELECT instancia_id, count(*) FROM solicitudes WHERE estado IN ('pendiente', 'en_proceso') GROUP BY instancia_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Las de un cliente (abiertas). */
    public function deCliente(string $inst, string $cliente, bool $conContacto): array
    {
        $st = $this->db->prepare("SELECT s.*, i.nombre AS programa, i.producto FROM solicitudes s JOIN instancias i ON i.id = s.instancia_id
                                  WHERE s.instancia_id = ? AND s.cliente_id = ? AND s.estado IN ('pendiente', 'en_proceso') ORDER BY s.fecha DESC");
        $st->execute([$inst, $cliente]);
        return array_map(fn($f) => $this->completa($f, $conContacto), $st->fetchAll());
    }

    // ------------------------------------------------------------- avisos por Telegram

    /** ¿Se avisa este tipo? Los marcados en Ajustes, y los "pedido" de tipos nuevos que el panel todavía no conoce. */
    public static function avisable(array $f, array $tipos): bool
    {
        return in_array($f['tipo'], $tipos, true) || ($f['categoria'] === 'pedido' && !isset(self::TIPOS[$f['tipo']]));
    }

    /** Nuevas que hay que avisar (abiertas y sin avisar). */
    public function porAvisar(array $tipos): array
    {
        $st = $this->db->query("SELECT s.*, i.nombre AS programa, i.producto FROM solicitudes s JOIN instancias i ON i.id = s.instancia_id AND i.activo = 1
                                WHERE s.avisada = 0 AND s.estado IN ('pendiente', 'en_proceso') ORDER BY s.primera_vez, s.fecha");
        return array_values(array_filter($st->fetchAll(), fn($f) => self::avisable($f, $tipos)));
    }

    public function marcarAvisadas(array $filas): void
    {
        $st = $this->db->prepare('UPDATE solicitudes SET avisada = 1 WHERE instancia_id = ? AND solicitud_id = ?');
        foreach ($filas as $f) $st->execute([$f['instancia_id'], $f['solicitud_id']]);
    }

    /** Las que no tocaba avisar se dan por avisadas (si después se marca el tipo, no llegan las viejas). */
    public function marcarNoAvisables(array $tipos): void
    {
        $filas = $this->db->query('SELECT instancia_id, solicitud_id, tipo, categoria FROM solicitudes WHERE avisada = 0')->fetchAll();
        $this->marcarAvisadas(array_filter($filas, fn($f) => !self::avisable($f, $tipos)));
    }

    /** Texto del aviso de una solicitud (el teléfono enmascarado, sin correo). */
    public function textoAviso(array $f, string $urlCentral): string
    {
        $s = $this->completa($f, true);
        $q = $s['quien'];
        $quien = $q['organizacion'] ?? $q['nombre'] ?? $s['titulo'];
        $ciudad = is_string($s['detalle']['ciudad'] ?? null) ? ' (' . mb_substr($s['detalle']['ciudad'], 0, 40) . ')' : '';
        $titulo = self::nombreTipo($s['tipo']);
        if ($s['tipo'] === 'soporte' && $s['prioridad'] === 'alta') $titulo = 'Ticket urgente';
        if ($s['tipo'] === 'comprobante' && !empty($s['detalle']['cambia_plan'])) $titulo = 'Comprobante y cambio de plan';
        $partes = [self::iconoTipo($s['tipo']) . ' ' . $f['programa'] . ' · ' . $titulo . ': ' . $quien . $ciudad
                   . ($s['resumen'] && $s['tipo'] !== 'demo_pedida' ? ' · ' . $s['resumen'] : '') . '.'];
        $contacto = array_filter([($q['organizacion'] ?? null) !== null ? ($q['nombre'] ?? null) : null, Contacto::telefonoAviso($q['telefono'] ?? $q['whatsapp'] ?? null) ?: null]);
        if ($contacto) $partes[] = implode(' · ', $contacto) . '.';
        $partes[] = 'Abrir: ' . rtrim($urlCentral, '/') . '/?r=solicitud&i=' . rawurlencode($f['instancia_id']) . '&id=' . rawurlencode($f['solicitud_id']);
        return Contacto::enmascararTexto(implode(' ', $partes));
    }
}
