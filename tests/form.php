<?php
declare(strict_types=1);
// Para las pruebas: lee un formulario de una página guardada y escribe sus campos (separados por \0) para curl.
//   php tests/form.php PAGINA.html SELECTOR [nombre=valor ...]
// SELECTOR: "#id" del formulario, o "accion:<ruta>" (el primero cuyo action tenga ?r=<ruta>), o un número (orden).
// Primero sale "ACTION=<action>", después cada campo "nombre=valor" (lo que se pase como argumento lo reemplaza;
// "nombre=" sin valor lo quita). Respeta selected / checked como un navegador.
$html = (string) file_get_contents($argv[1]);
$sel = (string) ($argv[2] ?? '0');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8">' . $html);
$forms = iterator_to_array($dom->getElementsByTagName('form'));
$f = null;
foreach ($forms as $i => $x) {
    if ((str_starts_with($sel, '#') && $x->getAttribute('id') === substr($sel, 1))
        || (str_starts_with($sel, 'accion:') && preg_match('/[?&]r=' . preg_quote(substr($sel, 7), '/') . '(&|$)/', html_entity_decode($x->getAttribute('action'))))
        || (str_starts_with($sel, 'boton:') && str_contains(trim((new DOMXPath($dom))->evaluate('string(.//button)', $x)), substr($sel, 6)))
        || ((string) $i === $sel)) { $f = $x; break; }
}
if (!$f) { fwrite(STDERR, "No hay formulario $sel\n"); exit(1); }
$campos = [];
$xp = new DOMXPath($dom);
foreach ($xp->query('.//input|.//select|.//textarea', $f) as $el) {
    $n = $el->getAttribute('name');
    if ($n === '' || $el->hasAttribute('disabled')) continue;
    $tipo = strtolower($el->getAttribute('type'));
    if ($el->nodeName === 'input' && in_array($tipo, ['checkbox', 'radio'], true)) {
        if ($el->hasAttribute('checked')) $campos[] = [$n, $el->getAttribute('value') ?: 'on'];
        continue;
    }
    if ($el->nodeName === 'input' && in_array($tipo, ['submit', 'button'], true)) continue;
    if ($el->nodeName === 'select') {
        $v = null;
        foreach ($xp->query('.//option', $el) as $o) { if ($v === null || $o->hasAttribute('selected')) $v = $o->getAttribute('value'); if ($o->hasAttribute('selected')) break; }
        $campos[] = [$n, (string) $v];
        continue;
    }
    $campos[] = [$n, $el->nodeName === 'textarea' ? $el->textContent : $el->getAttribute('value')];
}
foreach (array_slice($argv, 3) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    $campos = array_values(array_filter($campos, fn($c) => $c[0] !== $k));
    if ($v !== '' || str_ends_with($kv, '=') === false) $campos[] = [$k, $v];
}
echo 'ACTION=' . html_entity_decode($f->getAttribute('action')) . "\0";
foreach ($campos as [$k, $v]) echo "$k=$v\0";
