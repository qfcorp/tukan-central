<?php
/**
 * Interruptores de módulos de un cliente (Anexo B). Se usa en la ficha del cliente (pestaña Módulos) y en la ficha
 * del programa (TuGuardería, junto a la licencia).
 * Variables: $mInst (programa), $mCat (catálogo limpio), $mCli (modulos del cliente), $mCid, $mVolver, $mPuede (guardar),
 *            $mFlags (capacidades.modulos), $mNombre (nombre del cliente)
 */
use Tukan\Modulos;

$mAct = $mCli['activos'] ?? [];
$mPlan = (string) ($mCli['plan']['codigo'] ?? '');
$mDel = $mCat['planes'][$mPlan]['modulos'] ?? ($mCli['del_plan'] ?? []);
$mDis = $mPuede ? '' : ' disabled';
$mAcordado = $mCli['precio_acordado'] ?? null;
?>
<form method="post" action="<?= e(url('modulos')) ?>" class="mods" id="form-modulos" data-modulos
      data-planes="<?= e(json_encode(array_map(fn($p) => ['p' => $p['precio_mensual'], 'm' => $p['modulos']], $mCat['planes']), JSON_UNESCAPED_UNICODE)) ?>"
      data-deps="<?= e(json_encode(array_map(fn($m) => $m['depende_de'], $mCat['modulos']), JSON_UNESCAPED_UNICODE)) ?>">
  <?= campo_csrf() ?>
  <input type="hidden" name="paso" value="revisar"><input type="hidden" name="i" value="<?= e($mInst['id']) ?>">
  <input type="hidden" name="cliente" value="<?= e($mCid) ?>"><input type="hidden" name="volver" value="<?= e($mVolver) ?>">

  <div class="mods-resumen tarjeta" aria-live="polite">
    <div class="mods-plan">
      <?php if ($mPuede && ($mFlags['cambia_plan'] ?? true) && count($mCat['planes']) > 1): ?>
        <label for="m-plan" class="chico suave">Plan base</label>
        <select id="m-plan" name="plan" data-mods-plan>
          <?php foreach ($mCat['planes'] as $p): ?><option value="<?= e($p['codigo']) ?>"<?= $p['codigo'] === $mPlan ? ' selected' : '' ?>><?= e($p['nombre']) ?> · <?= e(dinero($p['precio_mensual'])) ?></option><?php endforeach; ?>
        </select>
      <?php else: ?>
        <span class="chico suave">Plan base</span>
        <strong><?= e($mCli['plan']['nombre'] ?? $mPlan) ?></strong> <span class="chico"><?= e(dinero((float) ($mCli['precio_plan'] ?? 0))) ?></span>
        <input type="hidden" name="plan" value="<?= e($mPlan) ?>" data-mods-plan>
      <?php endif; ?>
    </div>
    <dl class="mods-cuentas">
      <div><dt>Plan</dt><dd data-m="plan"><?= e(dinero((float) ($mCli['precio_plan'] ?? 0))) ?></dd></div>
      <div><dt>Extras</dt><dd data-m="extra">+<?= e(dinero((float) ($mCli['precio_extra'] ?? 0))) ?></dd></div>
      <div><dt>Quitados</dt><dd data-m="descuento">−<?= e(dinero((float) ($mCli['descuento'] ?? 0))) ?></dd></div>
      <div class="total"><dt>Total al mes</dt><dd data-m="total" data-actual="<?= e(number_format((float) ($mCli['mensual'] ?? 0), 2, '.', '')) ?>"><?= e(dinero((float) ($mCli['mensual'] ?? 0))) ?></dd></div>
    </dl>
    <?php if ($mFlags['precio_acordado'] ?? true): ?>
    <div class="campo mods-acordado">
      <label for="m-acordado">Precio acordado <span class="opcional">(opcional: si se escribe, vale este en vez del total)</span></label>
      <div class="con-prefijo"><span>$</span><input type="text" id="m-acordado" name="precio_acordado" inputmode="decimal" value="<?= $mAcordado !== null ? e(number_format((float) $mAcordado, 2, ',', '')) : '' ?>" placeholder="Total calculado"<?= $mDis ?> data-mods-acordado></div>
    </div>
    <?php endif; ?>
  </div>

  <?php foreach (Modulos::agrupados($mCat) as $grupo => $lista): ?>
    <h3 class="mods-grupo"><?= e($grupo) ?></h3>
    <ul class="mods-lista">
      <?php foreach ($lista as $m): $on = in_array($m['id'], $mAct, true); $enPlan = in_array($m['id'], $mDel, true); ?>
        <li class="mod<?= $on ? ' on' : '' ?>" data-id="<?= e($m['id']) ?>" data-precio="<?= e(number_format($m['precio_mensual'], 2, '.', '')) ?>">
          <label class="mod-sw">
            <input type="checkbox" role="switch" name="mod[]" value="<?= e($m['id']) ?>"<?= $on ? ' checked' : '' ?><?= $mDis ?> aria-describedby="md-<?= e($m['id']) ?>">
            <span class="sw" aria-hidden="true"></span>
            <span class="mod-nombre"><?= e($m['nombre']) ?></span>
          </label>
          <span class="mod-precio"><?= e(dinero($m['precio_mensual'])) ?><small>/mes</small></span>
          <p class="mod-desc" id="md-<?= e($m['id']) ?>"><?= e($m['descripcion']) ?>
            <?php if ($m['depende_de']): ?><span class="mod-dep">Necesita: <?= e(Modulos::nombres($mCat, $m['depende_de'])) ?>.</span><?php endif; ?></p>
          <span class="mod-tag" data-tag><?= $enPlan ? ($on ? 'En el plan' : 'Quitado del plan') : ($on ? 'Extra' : 'No incluido') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>

  <?php if ($mCat['base'] ?? []): ?>
    <details class="mods-base"><summary>Siempre incluido (base)</summary><ul><?php foreach ($mCat['base'] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>

  <?php if ($mPuede): ?>
    <div class="mods-guardar">
      <div class="campo"><label for="m-motivo">Nota <span class="opcional">(opcional, queda en el programa)</span></label><input type="text" id="m-motivo" name="motivo" maxlength="300"></div>
      <button class="btn btn-primario btn-grande" type="submit">Revisar y guardar</button>
      <p class="chico suave">Antes de guardar verás qué se enciende, qué se apaga y el nuevo precio. Apagar un módulo no borra datos.</p>
    </div>
  <?php else: ?>
    <p class="aviso info mt"><?= icono('info') ?> Solo el dueño del panel puede encender o apagar módulos.</p>
  <?php endif; ?>
</form>
