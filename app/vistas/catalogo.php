<?php
/** Catálogo de módulos y planes de un programa (Anexo B). */
use Tukan\Modulos;

$dis = $puede ? '' : ' disabled';
$v = function (string $grupo, string $id, string $campo, string $def) use ($valores): string {
    $x = $valores[$grupo][$id][$campo] ?? null;
    return is_string($x) ? $x : $def;
};
$err = fn(string $k) => isset($errores[$k]) ? '<span class="error-campo">' . e($errores[$k]) . '</span>' : '';
$num = fn(float $n) => number_format($n, 2, ',', '');
?>
<p class="migas"><a href="<?= e(url('programas')) ?>">Programas</a> / <a href="<?= e(url('programa', ['id' => $inst['id']])) ?>"><?= e($inst['nombre']) ?></a> / Catálogo de módulos</p>
<div class="cabeza p-<?= e($inst['producto']) ?>">
  <div class="prog-top"><?= ico_programa($inst['producto'], false) ?><div class="prog-tit">
    <h1>Catálogo de módulos y planes</h1>
    <span class="url"><?= e($inst['nombre']) ?> · <?= count($cat['modulos']) ?> módulos · <?= count($cat['planes']) ?> planes<?= !empty($cat['de_copia']) ? ' · ' . e(datos_de((int) $cat['de_copia'])) : '' ?></span></div></div>
</div>
<p class="suave mb">Aquí se ponen los precios que se cobran al mes por cada módulo y qué trae cada plan. Los cambios valen para los clientes nuevos y para el total de los que ya tienen ese módulo como extra.</p>

<form method="post" action="<?= e(url('catalogo')) ?>" id="form-catalogo">
  <?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($inst['id']) ?>"><input type="hidden" name="op" value="<?= e($op) ?>">
  <?php if (isset($errores['_programa'])): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($errores['_programa']) ?></p></div>
  <?php elseif ($errores): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p>Revisa los campos marcados.</p></div><?php endif; ?>

  <section class="tarjeta seccion" id="cat-modulos">
    <h2>Módulos <span class="cuenta"><?= count($cat['modulos']) ?></span></h2>
    <div class="tabla-env">
    <table class="tabla apilable catalogo-tabla">
      <thead><tr><th>Módulo</th><th>Precio al mes</th><th>Para vender (descripción)</th><th>Orden</th></tr></thead>
      <tbody>
      <?php foreach (Modulos::agrupados($cat) as $grupo => $lista): ?>
        <tr class="grupo"><td colspan="4"><strong><?= e($grupo) ?></strong></td></tr>
        <?php foreach ($lista as $m): $id = $m['id']; ?>
          <tr>
            <td data-t="Módulo"><input type="text" name="m[<?= e($id) ?>][nombre]" value="<?= e($v('m', $id, 'nombre', $m['nombre'])) ?>" maxlength="80" aria-label="Nombre de <?= e($m['nombre']) ?>"<?= $dis ?>>
              <small class="suave mono"><?= e($id) ?><?= $m['depende_de'] ? ' · necesita ' . e(Modulos::nombres($cat, $m['depende_de'])) : '' ?></small><?= $err("m.$id.nombre") ?></td>
            <td data-t="Precio al mes" class="precio"><div class="con-prefijo"><span>$</span><input type="text" name="m[<?= e($id) ?>][precio]" inputmode="decimal" value="<?= e($v('m', $id, 'precio', $num($m['precio_mensual']))) ?>" aria-label="Precio de <?= e($m['nombre']) ?>"<?= $dis ?>></div><?= $err("m.$id.precio") ?></td>
            <td data-t="Descripción"><textarea name="m[<?= e($id) ?>][descripcion]" rows="2" maxlength="400" aria-label="Descripción de <?= e($m['nombre']) ?>"<?= $dis ?>><?= e($v('m', $id, 'descripcion', $m['descripcion'])) ?></textarea><?= $err("m.$id.descripcion") ?></td>
            <td data-t="Orden" class="orden"><input type="text" name="m[<?= e($id) ?>][orden]" inputmode="numeric" value="<?= e($v('m', $id, 'orden', (string) $m['orden'])) ?>" aria-label="Orden de <?= e($m['nombre']) ?>"<?= $dis ?>><?= $err("m.$id.orden") ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="seccion" id="cat-planes">
    <h2>Planes <span class="cuenta"><?= count($cat['planes']) ?></span></h2>
    <div class="catalogo-planes">
      <?php foreach (array_merge($cat['planes'], $puede ? ['_nuevo' => ['codigo' => '_nuevo', 'nombre' => '', 'precio_mensual' => 0.0, 'todos' => false, 'modulos' => []]] : []) as $cod => $p): $nuevo = $cod === '_nuevo';
        $sel = isset($valores['p'][$cod]) ? (is_array($valores['p'][$cod]['mods'] ?? null) ? $valores['p'][$cod]['mods'] : []) : $p['modulos'];
        $todos = isset($valores['p'][$cod]) ? !empty($valores['p'][$cod]['todos']) : $p['todos']; ?>
        <div class="tarjeta plan-cat<?= $nuevo ? ' nuevo' : '' ?>">
          <?php if ($nuevo): ?>
            <h3>Plan nuevo <span class="opcional">(opcional)</span></h3>
            <div class="campo"><label for="pn-cod">Código (minúsculas, sin espacios)</label><input type="text" id="pn-cod" name="p[_nuevo][codigo]" maxlength="40" value="<?= e($v('p', '_nuevo', 'codigo', '')) ?>" placeholder="basico"><?= $err('p._nuevo.codigo') ?></div>
          <?php else: ?>
            <h3><?= e($p['nombre']) ?> <small class="suave mono"><?= e($cod) ?></small></h3>
          <?php endif; ?>
          <div class="fila-campos">
            <div class="campo"><label for="p-<?= e($cod) ?>-n">Nombre</label><input type="text" id="p-<?= e($cod) ?>-n" name="p[<?= e($cod) ?>][nombre]" maxlength="80" value="<?= e($v('p', $cod, 'nombre', $p['nombre'])) ?>"<?= $dis ?>><?= $err("p.$cod.nombre") ?></div>
            <div class="campo"><label for="p-<?= e($cod) ?>-p">Precio al mes</label><div class="con-prefijo"><span>$</span><input type="text" id="p-<?= e($cod) ?>-p" name="p[<?= e($cod) ?>][precio]" inputmode="decimal" value="<?= e($v('p', $cod, 'precio', $nuevo ? '' : $num($p['precio_mensual']))) ?>"<?= $dis ?>></div><?= $err("p.$cod.precio") ?></div>
          </div>
          <label class="check mb"><input type="checkbox" name="p[<?= e($cod) ?>][todos]" value="1"<?= $todos ? ' checked' : '' ?><?= $dis ?>> <span>Trae <b>todos</b> los módulos</span></label>
          <fieldset class="plan-mods"><legend class="chico suave">Módulos que trae</legend>
            <?php foreach ($cat['modulos'] as $id => $m): ?><label class="check"><input type="checkbox" name="p[<?= e($cod) ?>][mods][]" value="<?= e($id) ?>"<?= in_array($id, $sel, true) || $todos ? ' checked' : '' ?><?= $dis ?>> <span><?= e($m['nombre']) ?></span></label><?php endforeach; ?>
          </fieldset>
          <?= $err("p.$cod.mods") ?>
          <?php if (!$nuevo && $puede): ?><label class="check mt chico"><input type="checkbox" name="p[<?= e($cod) ?>][borrar]" value="1"> <span>Borrar este plan</span></label><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($cat['base']): ?><section class="tarjeta seccion"><h2>Siempre incluido (base)</h2><ul><?php foreach ($cat['base'] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></section><?php endif; ?>

  <?php if ($puede): ?>
    <div class="tarjeta seccion form-sola">
      <div class="campo"><label for="codigo">Código de 2 pasos (de tu app)</label><input id="codigo" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required class="corto"><?= $err('_codigo') ?></div>
      <div class="acciones-form"><button class="btn btn-primario btn-grande" type="submit"><?= icono('check') ?> Guardar catálogo</button>
        <a class="btn btn-grande" href="<?= e(url('programa', ['id' => $inst['id']])) ?>">Volver</a></div>
      <p class="chico suave mt">Se mandan solo los cambios. <?= e($inst['nombre']) ?> revisa que cada plan traiga lo que sus módulos necesitan.</p>
    </div>
  <?php else: ?>
    <p class="aviso info"><?= icono('info') ?> Solo el dueño del panel puede cambiar precios y planes.</p>
  <?php endif; ?>
</form>
