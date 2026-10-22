<?php
declare(strict_types=1);

/*
 * TUKÁN IDIOMAS — pieza compartida de todos los programas Tukán (misma idea que TuVocalía v28, sin depender de ningún programa).
 * Original: tukan-central/compartido/tukan_idiomas.php. Cada programa guarda una COPIA SIN CAMBIOS (como tukan_ia.php).
 *
 * Idiomas: español (base), inglés, francés, portugués e italiano.
 *  - Botón 🌐 (tukan_idioma_selector) en la barra de arriba y en las páginas públicas.
 *  - El idioma se elige con ?lang=xx, se recuerda en una cookie (1 año) y, si el programa lo pide, en la cuenta del usuario.
 *  - La página se arma normal en español y al final se traduce cada texto visible con el diccionario del programa
 *    (archivo idiomas/xx.php que devuelve ['texto en español' => 'traducción']). Los nombres que escriben los usuarios
 *    (clientes, mensajes, productos) no están en el diccionario, así que no se tocan.
 *  - Acepta números ("Quedan 5 días" usa la entrada "Quedan {n} días") y partes variables ("Hola, {s}").
 *  - Lo que no tiene traducción se queda en español: nunca se rompe nada.
 *  - No se traduce: <script>, <style>, <textarea>, <svg>, <code>, <pre> ni lo que esté entre <!--notr--> y <!--/notr-->.
 *
 * Uso en un programa (una sola vez, al arrancar, antes de imprimir nada):
 *   require __DIR__ . '/tukan_idiomas.php';
 *   tukan_idioma_config([
 *       'dir'            => __DIR__ . '/../idiomas',                 // carpeta con en.php, fr.php, pt.php, it.php
 *       'usuario'        => fn () => $usuario['idioma'] ?? null,     // (opcional) idioma guardado del usuario
 *       'guardar'        => fn (string $l) => ...UPDATE usuarios..., // (opcional) guardar la elección en su cuenta
 *       'defecto'        => fn () => $negocio['idioma'] ?? null,     // (opcional) idioma por defecto del negocio
 *       'faltantes'      => '/ruta/faltantes.txt',                   // (opcional, SOLO pruebas) anota lo que no tiene traducción
 *   ]);
 *   tukan_idioma_iniciar();                // empieza a capturar la página para traducirla al final
 *   ... en la barra: <?= tukan_idioma_selector() ?>
 *   ... en PHP (por ejemplo para JSON o correos): tukan_tr('Guardado')
 *   ... para JavaScript: <?= tukan_idioma_js(['Enviando…', 'No se pudo enviar']) ?>  → window.TR('Enviando…')
 */

const TUKAN_IDIOMAS_VERSION = 1;

function tukan_idiomas(): array
{
    return ['es' => ['Español', '🇪🇸'], 'en' => ['English', '🇬🇧'], 'fr' => ['Français', '🇫🇷'], 'pt' => ['Português', '🇧🇷'], 'it' => ['Italiano', '🇮🇹']];
}

function tukan_idioma_config(?array $c = null): array
{
    static $cfg = ['dir' => null, 'usuario' => null, 'guardar' => null, 'defecto' => null, 'faltantes' => null, 'cookie' => 'idioma'];
    if ($c !== null) {
        $cfg = array_merge($cfg, $c);
        $GLOBALS['__tukan_idioma'] = null;
    }
    return $cfg;
}

/** Idioma de esta página: ?lang= (y se recuerda) › cookie › usuario › negocio › español. */
function tukan_idioma_actual(bool $refrescar = false): string
{
    $i = &$GLOBALS['__tukan_idioma'];
    if ($i !== null && !$refrescar) {
        return $i;
    }
    $cfg = tukan_idioma_config();
    $ok = array_keys(tukan_idiomas());
    $q = (string) ($_GET['lang'] ?? '');
    if (in_array($q, $ok, true)) {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie($cfg['cookie'], $q, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        }
        $_COOKIE[$cfg['cookie']] = $q;
        if (is_callable($cfg['guardar'])) {
            try { ($cfg['guardar'])($q); } catch (\Throwable) { /* no importa: queda en la cookie */ }
        }
        return $i = $q;
    }
    $c = (string) ($_COOKIE[$cfg['cookie']] ?? '');
    if (in_array($c, $ok, true)) {
        return $i = $c;
    }
    foreach (['usuario', 'defecto'] as $k) {
        if (is_callable($cfg[$k])) {
            try { $v = (string) (($cfg[$k])() ?? ''); } catch (\Throwable) { $v = ''; }
            if (in_array($v, $ok, true)) {
                return $i = $v;
            }
        }
    }
    return $i = 'es';
}

/** Diccionario del idioma (texto en español => traducción). */
function tukan_idioma_diccionario(string $idioma): array
{
    static $d = [];
    if (!isset($d[$idioma])) {
        $d[$idioma] = [];
        $dir = tukan_idioma_config()['dir'];
        if ($idioma !== 'es' && preg_match('/^[a-z]{2}$/', $idioma) && $dir && is_file($f = rtrim((string) $dir, '/') . '/' . $idioma . '.php')) {
            $d[$idioma] = (array) (include $f);
        }
    }
    return $d[$idioma];
}

/** Traduce un texto suelto (sin HTML). Si no hay traducción, lo devuelve igual. */
function tukan_tr(string $texto, ?string $idioma = null): string
{
    $idioma ??= tukan_idioma_actual();
    if ($idioma === 'es') {
        return $texto;
    }
    $dic = tukan_idioma_diccionario($idioma);
    if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $texto, $m)) {
        return $texto;
    }
    [, $ini, $t, $fin] = $m;
    if ($t === '' || !preg_match('/\p{L}{2}/u', $t)) {
        return $texto;
    }
    $t1 = preg_replace('/\s+/u', ' ', $t);
    $r = $dic ? tukan_tr_buscar($t1, $dic) : null;
    if ($r === null) {
        tukan_idioma_faltante($t1);
    }
    return $r === null ? $texto : $ini . $r . $fin;
}

/** Solo en pruebas ('faltantes' => archivo): anota los textos que todavía no tienen traducción. */
function tukan_idioma_faltante(string $t, bool $guardar = false): void
{
    static $lista = [];
    $f = tukan_idioma_config()['faltantes'];
    if (!$f) {
        return;
    }
    if ($t !== '') {
        $lista[$t] = true;
    }
    if ($guardar && $lista) {
        @file_put_contents((string) $f, implode("\n", array_keys($lista)) . "\n", FILE_APPEND | LOCK_EX);
        $lista = [];
    }
}

function tukan_tr_buscar(string $t, array $dic, int $nivel = 0): ?string
{
    if (isset($dic[$t])) {
        return $dic[$t];
    }
    // Con números: "Quedan 5 días" → "Quedan {n} días"
    if (preg_match_all('/\d+(?:[.,:]\d+)*/', $t, $nums)) {
        $k = preg_replace('/\d+(?:[.,:]\d+)*/', '{n}', $t);
        if (isset($dic[$k])) {
            $i = 0;
            return preg_replace_callback('/\{n\}/', function () use (&$i, $nums) { return $nums[0][$i++] ?? ''; }, $dic[$k]);
        }
    }
    if ($nivel > 2) {
        return null;
    }
    // Con íconos o símbolos adelante: "📋 Tomar asistencia" → se traduce "Tomar asistencia"
    if (preg_match('/^([^\p{L}\p{N}¿¡«"(]+)(\p{L}.*)$/u', $t, $m) && $m[1] !== '') {
        $x = tukan_tr_buscar($m[2], $dic, $nivel + 1);
        if ($x !== null) {
            return $m[1] . $x;
        }
    }
    // Con dos puntos o punto al final: "Estado:" → "Estado"
    foreach ([':', '.'] as $fin) {
        if (str_ends_with($t, $fin) && mb_strlen($t) > 2) {
            $x = tukan_tr_buscar(rtrim(mb_substr($t, 0, -1)), $dic, $nivel + 1);
            if ($x !== null) {
                return $x . $fin;
            }
        }
    }
    // Plantillas con {s} (texto variable): "Hola, {s}"
    foreach (tukan_idioma_plantillas($dic) as [$re, $dest]) {
        if (preg_match($re, $t, $m)) {
            $i = 1;
            return preg_replace_callback('/\{s\}/', function () use (&$i, $m, $dic, $nivel) {
                $v = $m[$i++] ?? '';
                return tukan_tr_buscar($v, $dic, $nivel + 1) ?? $v;
            }, $dest);
        }
    }
    // Partes separadas con " · "
    if (str_contains($t, ' · ')) {
        $partes = explode(' · ', $t);
        $alguna = false;
        foreach ($partes as $j => $p) {
            $x = tukan_tr_buscar(trim($p), $dic, $nivel + 1);
            if ($x !== null) {
                $partes[$j] = $x;
                $alguna = true;
            }
        }
        return $alguna ? implode(' · ', $partes) : null;
    }
    // "Etiqueta: valor" → se traduce la etiqueta
    if (preg_match('/^(.{1,40}?:)\s(.+)$/u', $t, $m)) {
        $x = tukan_tr_buscar($m[1], $dic, $nivel + 1);
        if ($x !== null) {
            return $x . ' ' . (tukan_tr_buscar($m[2], $dic, $nivel + 1) ?? $m[2]);
        }
    }
    return null;
}

/** Entradas del diccionario con {s} convertidas en expresiones regulares (se calculan una vez por idioma). */
function tukan_idioma_plantillas(array $dic): array
{
    static $cache = [];
    $k = md5(count($dic) . '|' . array_key_first($dic) . '|' . array_key_last($dic));
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $out = [];
    foreach ($dic as $es => $tr) {
        if (str_contains((string) $es, '{s}')) {
            $re = '/^' . str_replace('\\{s\\}', '(.+?)', preg_quote((string) $es, '/')) . '$/u';
            $out[] = [$re, $tr];
        }
    }
    // Las más largas primero (más específicas)
    usort($out, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    return $cache[$k] = $out;
}

/** Traduce el HTML de la página completa (textos visibles y algunos atributos). */
function tukan_traducir_html(string $html, string $idioma): string
{
    if ($idioma === 'es' || $html === '') {
        return $html;
    }
    $partes = preg_split('#(<(script|style|textarea|svg|code|pre)\b.*?</\2>|<!--notr-->.*?<!--/notr-->)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    for ($i = 0, $n = count($partes); $i < $n; $i++) {
        $p = $partes[$i];
        if (str_starts_with($p, '<!--notr-->')) {
            $out .= $p;
            continue;
        }
        if (preg_match('#^<(script|style|textarea|svg|code|pre)\b#i', $p)) {
            $out .= $p;
            $i++; // el nombre de la etiqueta capturado
            continue;
        }
        // Textos entre etiquetas
        $p = preg_replace_callback('/>([^<>]+)</u', function ($m) use ($idioma) {
            $raw = $m[1];
            if (!preg_match('/\p{L}{2}/u', $raw)) {
                return $m[0];
            }
            $txt = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = tukan_tr($txt, $idioma);
            return $tr === $txt ? $m[0] : '>' . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . '<';
        }, $p);
        // Atributos visibles
        $p = preg_replace_callback('/\b(placeholder|title|aria-label|alt|data-confirmar|data-confirm)="([^"]*)"/u', function ($m) use ($idioma) {
            $txt = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = tukan_tr($txt, $idioma);
            return $tr === $txt ? $m[0] : $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
        }, $p);
        $p = preg_replace_callback('/<input\b[^>]*\btype="(?:submit|button)"[^>]*>/iu', function ($m) use ($idioma) {
            return preg_replace_callback('/\bvalue="([^"]*)"/u', function ($v) use ($idioma) {
                $txt = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return 'value="' . htmlspecialchars(tukan_tr($txt, $idioma), ENT_QUOTES, 'UTF-8') . '"';
            }, $m[0]);
        }, $p);
        $out .= $p;
    }
    tukan_idioma_faltante('', true);
    return preg_replace('/<html lang="es"/', '<html lang="' . $idioma . '"', $out, 1);
}

/** Empieza a capturar la página para traducirla al final (solo si el idioma no es español). */
function tukan_idioma_iniciar(): void
{
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['__tukan_traduciendo'])) {
        return;
    }
    $idioma = tukan_idioma_actual();
    if ($idioma === 'es') {
        return;
    }
    $GLOBALS['__tukan_traduciendo'] = true;
    ob_start(function (string $buf) use ($idioma) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
                return $buf;
            }
            if (stripos($h, 'Content-Disposition:') === 0) {
                return $buf;
            }
        }
        return tukan_traducir_html($buf, $idioma);
    });
}

/** Botón 🌐 con la lista de idiomas (para la barra de arriba). Trae su propio estilo. */
function tukan_idioma_selector(string $clase = ''): string
{
    $act = tukan_idioma_actual();
    $q = $_GET;
    $esc = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $base = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)) ?: '';
    $h = '<details class="tukan-idioma ' . $esc($clase) . '"><summary title="Idioma / Language" aria-label="Idioma / Language">🌐 <span>' . strtoupper($act) . '</span></summary><div class="tukan-idioma-menu"><!--notr-->';
    foreach (tukan_idiomas() as $k => [$nom, $flag]) {
        $q['lang'] = $k;
        $h .= '<a href="' . $esc($base . '?' . http_build_query($q)) . '"' . ($k === $act ? ' class="activo" aria-current="true"' : '') . ' lang="' . $k . '" hreflang="' . $k . '">' . $flag . ' ' . $esc($nom) . '</a>';
    }
    static $css = false;
    $estilo = '';
    if (!$css) {
        $css = true;
        $estilo = '<style>.tukan-idioma{position:relative;display:inline-block}.tukan-idioma>summary{list-style:none;cursor:pointer;padding:6px 8px;border-radius:8px;font-size:14px;white-space:nowrap;user-select:none}'
            . '.tukan-idioma>summary::-webkit-details-marker{display:none}.tukan-idioma>summary:hover{background:rgba(127,127,127,.15)}'
            . '.tukan-idioma-menu{position:absolute;right:0;top:calc(100% + 4px);z-index:1000;min-width:160px;background:#fff;color:#14211F;border:1px solid rgba(0,0,0,.12);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:6px;display:flex;flex-direction:column}'
            . '.tukan-idioma-menu a{display:block;padding:8px 10px;border-radius:6px;color:inherit;text-decoration:none;font-size:15px}.tukan-idioma-menu a:hover,.tukan-idioma-menu a.activo{background:#F3EEE6}.tukan-idioma-menu a.activo{font-weight:600}</style>';
    }
    return $estilo . $h . '<!--/notr--></div></details>';
}

/**
 * Textos para JavaScript (lo que el navegador escribe solo: avisos, botones que cambian, confirmaciones).
 * Pone window.TR('texto') que devuelve la traducción o el mismo texto si no hay.
 */
function tukan_idioma_js(array $frases): string
{
    $idioma = tukan_idioma_actual();
    $mapa = [];
    if ($idioma !== 'es') {
        foreach ($frases as $f) {
            $t = tukan_tr((string) $f, $idioma);
            if ($t !== $f) {
                $mapa[$f] = $t;
            }
        }
    }
    $json = json_encode($mapa ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return '<script>window.TUKAN_IDIOMA=' . json_encode($idioma) . ';window.TUKAN_TR=' . $json . ';window.TR=function(t){return (window.TUKAN_TR&&window.TUKAN_TR[t])||t;};</script>';
}

/** Lista de idiomas para un <select> (por ejemplo "Idioma de mi cuenta" o "Idioma del negocio"). */
function tukan_idioma_opciones(string $actual): string
{
    $h = '';
    foreach (tukan_idiomas() as $k => [$n, $f]) {
        $h .= '<option value="' . $k . '"' . ($k === $actual ? ' selected' : '') . '>' . $f . ' ' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return '<!--notr-->' . $h . '<!--/notr-->';
}
