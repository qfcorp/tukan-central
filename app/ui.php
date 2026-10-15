<?php
declare(strict_types=1);

/**
 * Piezas de pantalla del protocolo v2: etiquetas de estado, botones de acciones_posibles (genéricos para todos los
 * programas), campos de formulario y botones de WhatsApp / Entrar.
 */

use Tukan\{Capacidades, Clientes, Contacto, Formulario, Operaciones, Productos};

/** Etiqueta de color con el estado del cliente. */
function etiqueta_estado(string $estado, ?string $texto = null): string
{
    return '<span class="etiqueta e-' . e($estado) . '">' . e($texto ?? (Clientes::ESTADOS[$estado] ?? $estado)) . '</span>';
}

/**
 * Botones de la lista de Clientes del Anexo A (Pausar / Reactivar, Borrar y, en la papelera, Restaurar).
 * Salen solo si el programa tiene la operación y el usuario el permiso; el formulario lo arma el programa
 * (acciones_posibles de la ficha). TuGuardería (una sola empresa): Borrar lleva a "Borrar esta instalación".
 */
function botones_pausa_borrar(array $fila): string
{
    $inst = $fila['instancia_id'];
    $id = $fila['cliente_id'];
    $d = $fila['datos'];
    $volver = aqui();
    $link = fn(string $accion, string $texto, string $ico, string $clase) => '<a class="btn btn-chico ' . $clase . '" data-hoja href="'
        . e(url('accion', ['i' => $inst, 'a' => $accion, 'cliente' => $id, 'volver' => $volver])) . '">' . icono($ico) . ' ' . e($texto) . '</a>';
    $h = '';
    if (!empty($d['papelera'])) {
        return puede_operacion($inst, 'restaurar_cliente') ? $link('restaurar_cliente', 'Restaurar', 'restaurar', 'btn-primario') : '';
    }
    if ($fila['estado'] === 'suspendido') {
        if (puede_operacion($inst, 'reactivar')) $h .= $link('reactivar', 'Reactivar', 'refrescar', 'btn-suave');
    } elseif (puede_operacion($inst, 'pausar')) {
        $h .= $link('pausar', 'Pausar', 'pausa', 'btn-suave');
    }
    if ($id === 'unico') {
        if (puede('programas') && Capacidades::puede(capacidades()->datos($inst), 'ejecutar', 'dar_de_baja')) {
            $h .= '<a class="btn btn-chico btn-peligro" href="' . e(url('programa', ['id' => $inst])) . '#baja">' . icono('basura') . ' Borrar</a>';
        }
    } elseif (puede_operacion($inst, 'borrar_cliente')) {
        $h .= $link('borrar_cliente', 'Borrar', 'basura', 'btn-peligro');
    }
    return $h;
}

/** Cuadrito del programa (color + letra). */
function ico_programa(string $producto, bool $chico = true): string
{
    return '<span class="ico' . ($chico ? ' chico' : '') . ' p-' . e($producto) . '" aria-hidden="true">' . e(Productos::tipo($producto)['letra']) . '</span>';
}

/** Ruta de vuelta segura (solo de este panel: "/?r=..."). */
function volver_valido(?string $v, string $defecto = '/?r=inicio'): string
{
    return is_string($v) && preg_match('#^/\?r=[a-z_]+(&[A-Za-z0-9_%\-.\[\]=:&+]*)?$#', $v) && !str_contains($v, '//') ? $v : $defecto;
}

/** La URL de esta página (para "volver" después de un formulario). */
function aqui(): string
{
    return volver_valido('/?' . http_build_query($_GET));
}

/** Botón "Entrar" (SSO), con enlace directo si el programa lo permite. */
function boton_entrar(string $inst, ?string $ir, string $texto, string $clase = 'btn btn-chico', bool $nueva = false): string
{
    $cap = capacidades()->datos($inst);
    $ir = Capacidades::irPermitido($cap, $ir) ? $ir : null;
    return '<form method="post" action="' . e(url('entrar')) . '" class="enlinea"' . ($nueva ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . campo_csrf()
        . '<input type="hidden" name="id" value="' . e($inst) . '">' . ($ir ? '<input type="hidden" name="ir" value="' . e($ir) . '">' : '')
        . ($nueva ? '<input type="hidden" name="nueva" value="1">' : '')
        . '<button class="' . e($clase) . '" type="submit">' . $texto . '</button></form>';
}

/** Botón de WhatsApp (solo el rol dueño ve teléfonos). */
function boton_whatsapp(?string $numero, string $texto, string $etiqueta = 'WhatsApp', string $clase = 'btn btn-chico btn-wa'): string
{
    if (!ve_contacto() || !$numero) return '';
    return '<a class="' . e($clase) . '" href="' . e(Contacto::urlWhatsapp($numero, $texto)) . '" target="_blank" rel="noopener noreferrer">' . icono('whatsapp') . e($etiqueta) . '</a>';
}

/** Firma de los datos ocultos de un formulario v2 (para que no se cambien en el navegador). */
function firma_form(string ...$partes): string
{
    return hash_hmac('sha256', implode("\n", $partes), token_csrf() . '|formulario-v2');
}

/** ¿Puede este usuario hacer esta operación en este programa? */
function puede_operacion(string $inst, string $accion): bool
{
    return puede(Operaciones::permiso($accion)) && Capacidades::puede(capacidades()->datos($inst), 'ejecutar', $accion);
}

/**
 * Botones de acciones_posibles (§4.4). $ctx: inst, cliente_id, sol (solicitud_id), nombre, volver, origen ('cliente'|'solicitud'), max.
 * - whatsapp: enlace a wa.me (solo dueño) · abrir: Entrar con enlace directo
 * - sin campos ni peligro: un toque (POST directo a "ejecutar", con confirmación si el programa la pide)
 * - con campos: abre el formulario (hoja abajo en el celular, ventana en escritorio; sin JavaScript, otra página)
 */
function botones_acciones(array $acciones, array $ctx): string
{
    $inst = $ctx['inst'];
    $html = '';
    $n = 0;
    foreach ($acciones as $i => $a) {
        if (isset($ctx['max']) && $n >= $ctx['max']) break;
        $clase = 'btn btn-chico' . ($a['estilo'] === 'principal' ? ' btn-primario' : ($a['estilo'] === 'peligro' ? ' btn-peligro' : ''));
        if ($a['accion'] === 'whatsapp') {
            $b = boton_whatsapp($a['whatsapp'] ?? null, $a['texto'] ?? '', $a['etiqueta']);
            if ($b !== '') { $html .= $b; $n++; }
            continue;
        }
        if ($a['accion'] === 'abrir') {
            if (!puede('entrar')) continue;
            $html .= boton_entrar($inst, $a['ir'] ?? null, e($a['etiqueta']) . ' ' . icono('externo'), 'btn btn-chico');
            $n++;
            continue;
        }
        if (!puede_operacion($inst, $a['accion'])) continue;
        $params = ['i' => $inst, 'a' => $a['accion'], 'n' => (string) $i, 'volver' => $ctx['volver'] ?? aqui()];
        if (!empty($ctx['sol'])) $params['sol'] = $ctx['sol'];
        if (!empty($ctx['cliente_id'])) $params['cliente'] = $ctx['cliente_id'];
        if (in_array($a['accion'], Operaciones::DE_LA_FICHA, true)) {
            // Borrar / cancelar el borrado: en la zona de peligro de la ficha del programa (ID + código de 2 pasos)
            $html .= '<a class="' . $clase . '" href="' . e(url('programa', ['id' => $inst])) . '#baja">' . e($a['etiqueta']) . '</a>';
            $n++;
            continue;
        }
        if ($a['accion'] === 'crear_demo') {
            $html .= '<a class="' . $clase . '" href="' . e(url('demo_nueva', ['i' => $inst] + (!empty($ctx['sol']) ? ['sol' => $ctx['sol']] : []))) . '">' . icono('demo') . e($a['etiqueta']) . '</a>';
            $n++;
            continue;
        }
        if ($a['campos'] === [] && $a['estilo'] !== 'peligro' && !in_array($a['accion'], Operaciones::PIDEN_2FA, true)) {
            // Un toque
            $fijos = json_encode($a['datos'], JSON_UNESCAPED_UNICODE);
            $op = Operaciones::nuevaId();
            $cid = (string) ($ctx['cliente_id'] ?? ($a['datos']['cliente_id'] ?? ''));
            $html .= '<form method="post" action="' . e(url('ejecutar')) . '" class="enlinea"' . ($a['confirmar'] ? ' data-confirmar="' . e($a['confirmar']) . '"' : '') . '>' . campo_csrf()
                . '<input type="hidden" name="i" value="' . e($inst) . '"><input type="hidden" name="accion" value="' . e($a['accion']) . '">'
                . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="cliente_id" value="' . e($cid) . '">'
                . '<input type="hidden" name="fijos" value="' . e($fijos) . '"><input type="hidden" name="def" value="[]">'
                . '<input type="hidden" name="nombre" value="' . e((string) ($ctx['nombre'] ?? '')) . '">'
                . '<input type="hidden" name="volver" value="' . e($params['volver']) . '">'
                . '<input type="hidden" name="firma" value="' . e(firma_form($inst, $a['accion'], $cid, $fijos, '[]')) . '">'
                . '<button class="' . $clase . '" type="submit">' . e($a['etiqueta']) . '</button></form>';
            $n++;
            continue;
        }
        $html .= '<a class="' . $clase . '" href="' . e(url('accion', $params)) . '" data-hoja>' . e($a['etiqueta']) . '</a>';
        $n++;
    }
    return $html;
}

/** Valor de un campo para mostrar en el formulario. */
function valor_campo(array $c, array $valores): string
{
    $v = array_key_exists($c['campo'], $valores) ? $valores[$c['campo']] : $c['valor'];
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_float($v) || ($c['tipo'] === 'dinero' && is_int($v))) return number_format((float) $v, 2, ',', '');
    return (string) ($v ?? '');
}

/** Campos de un formulario genérico (§4.4). $nombre: "campo" (campo[x]) o "extra" (crear_demo). */
function campos_html(array $campos, array $valores = [], array $errores = [], string $nombre = 'campo', string $prefijoId = 'f'): string
{
    $h = '';
    foreach ($campos as $c) {
        $id = $prefijoId . '-' . $c['campo'];
        $n = $nombre . '[' . $c['campo'] . ']';
        $v = valor_campo($c, $valores);
        $req = $c['requerido'] ? ' required' : '';
        $err = $errores[$c['campo']] ?? null;
        $aria = $err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-err"' : '';
        $datos = ' data-campo="' . e($c['campo']) . '"' . ($c['cotizar'] ? ' data-cotizar' : '');
        if ($c['tipo'] === 'si_no') {
            $h .= '<div class="campo"><input type="hidden" name="' . e($n) . '" value="0"><label class="check"><input type="checkbox" id="' . e($id) . '" name="' . e($n) . '" value="1"' . ($v === '1' ? ' checked' : '') . $datos . '> <span>' . e($c['etiqueta']) . '</span></label>';
        } else {
            $h .= '<div class="campo"><label for="' . e($id) . '">' . e($c['etiqueta']) . ($c['requerido'] ? '' : ' <span class="opcional">(opcional)</span>') . '</label>';
            switch ($c['tipo']) {
                case 'opcion':
                    $h .= '<select id="' . e($id) . '" name="' . e($n) . '"' . $req . $aria . $datos . '>';
                    if (!$c['requerido']) $h .= '<option value="">—</option>';
                    foreach ($c['opciones'] as $o) $h .= '<option value="' . e($o['valor']) . '"' . ($o['valor'] === $v ? ' selected' : '') . '>' . e($o['etiqueta']) . '</option>';
                    $h .= '</select>';
                    break;
                case 'texto_largo':
                    $h .= '<textarea id="' . e($id) . '" name="' . e($n) . '"' . $req . $aria . $datos . (is_int($c['max']) ? ' maxlength="' . $c['max'] . '"' : '') . '>' . e($v) . '</textarea>';
                    break;
                default:
                    $tipo = match ($c['tipo']) { 'fecha' => 'date', 'email' => 'email', 'telefono' => 'tel', default => 'text' };
                    $modo = match ($c['tipo']) { 'dinero' => ' inputmode="decimal"', 'numero', 'dias' => ' inputmode="numeric"', default => '' };
                    $largo = in_array($c['tipo'], ['texto', 'telefono', 'email'], true) && is_int($c['max']) ? ' maxlength="' . $c['max'] . '"' : '';
                    $pre = $c['tipo'] === 'dinero' ? '<div class="con-prefijo"><span>$</span>' : '';
                    $h .= $pre . '<input type="' . $tipo . '" id="' . e($id) . '" name="' . e($n) . '" value="' . e($v) . '"' . $req . $aria . $modo . $largo . $datos
                        . ($c['tipo'] === 'dias' ? ' class="corto"' : '') . ($c['tipo'] === 'telefono' ? ' autocomplete="tel"' : '') . '>' . ($pre ? '</div>' : '');
            }
        }
        if ($c['ayuda']) $h .= '<span class="ayuda">' . e($c['ayuda']) . '</span>';
        if ($err) $h .= '<span class="error-campo" id="' . e($id) . '-err">' . e($err) . '</span>';
        $h .= '</div>';
    }
    return $h;
}

/** Nombre de un programa por su ID (de la lista de instancias). */
function nombre_programa(string $inst): string
{
    static $n = null;
    $n ??= array_column(instancias()->lista(), 'nombre', 'id');
    return $n[$inst] ?? $inst;
}

/** "Datos de hace 40 minutos" para la copia local. */
function datos_de(?int $momento): string
{
    return $momento ? 'Datos de ' . hace($momento) : 'Todavía sin datos';
}
