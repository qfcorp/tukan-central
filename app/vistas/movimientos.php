<?php
use Tukan\Finanzas;

$v = fn(string $k, string $d = '') => e(is_string($valores[$k] ?? null) ? $valores[$k] : $d);
$hoy = date('Y-m-d');
$fechaDef = substr($hoy, 0, 7) === $mes ? $hoy : $mes . '-01';
?>
<div class="cabeza">
  <div>
    <h1>Finanzas</h1>
    <p class="sub"><?= $esGasto ? 'Lo que gasta Tukán: servidores, dominios, publicidad…' : 'Lo que se cobra fuera de los programas: licencias, instalaciones, capacitaciones.' ?></p>
  </div>
</div>
<?php require __DIR__ . '/_finanzas_tabs.php'; ?>

<div class="cal-nav">
  <a class="btn btn-chico btn-icono" href="<?= e(url($ruta, ['mes' => $anterior])) ?>" aria-label="Mes anterior"><?= icono('atras') ?></a>
  <h2><?= e(ucfirst(Finanzas::nombreMes($mes))) ?></h2>
  <a class="btn btn-chico btn-icono" href="<?= e(url($ruta, ['mes' => $siguiente])) ?>" aria-label="Mes siguiente"><?= icono('chevron') ?></a>
  <span class="total-mes"><?= $esGasto ? 'Gastos' : 'Ingresos extra' ?> del mes: <b data-total-mes><?= e(dinero($total)) ?></b></span>
</div>

<div class="rejilla dos-tercio">
  <div>
    <div class="tarjeta">
      <h2><?= $esGasto ? 'Gastos' : 'Ingresos extra' ?> de <?= e(Finanzas::nombreMes($mes)) ?></h2>
      <?php if (!$lista): ?><p class="vacio"><?= icono('info') ?> Nada anotado en este mes.</p><?php else: ?>
      <ul class="lista-p movimientos" id="lista-movimientos">
        <?php foreach ($lista as $g): ?>
          <li class="<?= $g['anulado'] ? 'anulado' : '' ?>">
            <div class="dato"><strong><?= e($g['descripcion'] ?? $g['concepto']) ?></strong>
              <span><?= e(fecha_dm($g['fecha'])) ?><?= $esGasto ? ' · ' . e(Finanzas::CATEGORIAS[$g['categoria']] ?? $g['categoria']) : '' ?> · <?= e($g['programa'] ?? 'General') ?><?= $g['recurrente_id'] ? ' · <span class="etiqueta">fijo</span>' : '' ?><?= $g['metodo'] ? ' · ' . e($g['metodo']) : '' ?><?= !empty($g['referencia']) ? ' · ' . e($g['referencia']) : '' ?><?= $g['anulado'] ? ' · <span class="etiqueta mal">Anulado</span>' : '' ?></span></div>
            <b class="monto<?= $esGasto ? ' neg' : '' ?>"><?= e(dinero($esGasto ? -(float) $g['monto'] : (float) $g['monto'])) ?></b>
            <?php if (!$g['anulado']): ?>
              <form method="post" action="<?= e(url($ruta, ['mes' => $mes])) ?>" data-confirmar="¿Anular «<?= e($g['descripcion'] ?? $g['concepto']) ?>»? Ya no contará en las finanzas."><?= campo_csrf() ?>
                <input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <button class="btn btn-chico btn-icono" type="submit" aria-label="Anular"><?= icono('basura') ?></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <?php if ($costos): ?>
    <div class="tarjeta">
      <h2>Costos que informan los programas</h2>
      <p class="chico suave mb">Ya se cuentan en la ganancia: no los anotes otra vez.</p>
      <ul class="lista-p">
        <?php foreach ($costos as $c): ?>
          <li><div class="dato"><strong><?= e($c['nombre']) ?></strong><span><?= e(Finanzas::CATEGORIAS[$c['categoria']] ?? $c['categoria']) ?> · (lo informa <?= e($c['programa']) ?>)<?= $c['estimado'] ? ' · estimado' : '' ?></span></div><b class="monto neg"><?= e(dinero(-$c['monto'])) ?></b></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="tarjeta">
      <h2>Fijos de cada mes</h2>
      <?php if (!$fijos): ?><p class="suave">Ninguno todavía. Al agregar, marca "Se repite cada mes".</p><?php else: ?>
      <ul class="lista-p fijos">
        <?php foreach ($fijos as $r): $terminado = $r['hasta_mes'] !== null; ?>
          <li class="<?= !$r['activo'] ? 'pausado' : '' ?>">
            <div class="dato"><strong><?= e($r['descripcion']) ?></strong>
              <span>Día <?= (int) $r['dia'] ?> · <?= e($r['programa'] ?? 'General') ?> · desde <?= e(Finanzas::nombreMes($r['desde_mes'])) ?><?= $terminado ? ' · terminó en ' . e(Finanzas::nombreMes($r['hasta_mes'])) : (!$r['activo'] ? ' · <span class="etiqueta alerta">Pausado</span>' : '') ?></span></div>
            <b class="monto"><?= e(dinero((float) $r['monto'])) ?></b>
            <?php if (!$terminado): ?>
            <details class="mas-acc"><summary class="btn btn-chico">Cambiar</summary>
              <div class="mas-acc-cuerpo">
                <form method="post" action="<?= e(url($ruta, ['mes' => $mes])) ?>" class="fila-mini"><?= campo_csrf() ?><input type="hidden" name="accion" value="fijo"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="que" value="monto">
                  <label class="oculto" for="m<?= (int) $r['id'] ?>">Valor nuevo</label><input id="m<?= (int) $r['id'] ?>" name="monto" inputmode="decimal" value="<?= e(number_format((float) $r['monto'], 2, ',', '')) ?>" class="corto"><button class="btn btn-chico" type="submit">Cambiar valor</button></form>
                <form method="post" action="<?= e(url($ruta, ['mes' => $mes])) ?>" class="enlinea"><?= campo_csrf() ?><input type="hidden" name="accion" value="fijo"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="que" value="<?= $r['activo'] ? 'pausar' : 'reanudar' ?>">
                  <button class="btn btn-chico" type="submit"><?= $r['activo'] ? 'Pausar' : 'Reanudar' ?></button></form>
                <form method="post" action="<?= e(url($ruta, ['mes' => $mes])) ?>" class="enlinea" data-confirmar="¿Terminar «<?= e($r['descripcion']) ?>»? Desde el mes que viene ya no se crea."><?= campo_csrf() ?><input type="hidden" name="accion" value="fijo"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="que" value="terminar">
                  <button class="btn btn-chico btn-peligro" type="submit">Terminar</button></form>
              </div></details>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" action="<?= e(url($ruta, ['mes' => $mes])) ?>" class="tarjeta form-gasto" id="form-movimiento">
    <h2><?= icono('mas') ?> <?= $esGasto ? 'Agregar gasto' : 'Agregar ingreso extra' ?></h2>
    <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
    <?= campo_csrf() ?><input type="hidden" name="accion" value="agregar"><input type="hidden" name="mes" value="<?= e($mes) ?>">
    <div class="fila-campos">
      <div class="campo"><label for="g-fecha">Fecha</label><input id="g-fecha" type="date" name="fecha" value="<?= $v('fecha', $fechaDef) ?>" required></div>
      <div class="campo"><label for="g-monto">Valor ($)</label><div class="con-prefijo"><span>$</span><input id="g-monto" name="monto" inputmode="decimal" value="<?= $v('monto') ?>" placeholder="0,00" required></div></div>
    </div>
    <?php if ($esGasto): ?>
      <div class="campo"><label for="g-cat">Categoría</label><select id="g-cat" name="categoria" required><option value="">Elige…</option>
        <?php foreach (Finanzas::CATEGORIAS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($valores['categoria'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label for="g-desc">En qué se gastó</label><input id="g-desc" type="text" name="descripcion" value="<?= $v('descripcion') ?>" maxlength="200" placeholder="VPS de Contabo, dominio tuvocalia.com…" required></div>
    <?php else: ?>
      <div class="campo"><label for="g-desc">Concepto</label><input id="g-desc" type="text" name="concepto" value="<?= $v('concepto') ?>" maxlength="200" placeholder="Licencia TuGuardería Dogssy, instalación…" required></div>
    <?php endif; ?>
    <div class="campo"><label for="g-prog">Programa</label><select id="g-prog" name="instancia_id"><option value="">General (de todo Tukán)</option>
      <?php foreach ($insts as $i): ?><option value="<?= e($i['id']) ?>" <?= ($valores['instancia_id'] ?? '') === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="fila-campos">
      <div class="campo"><label for="g-met">Forma de pago <span class="opcional">(opcional)</span></label><select id="g-met" name="metodo"><option value="">—</option>
        <?php foreach (Finanzas::METODOS as $k => $t): ?><option value="<?= e($t) ?>" <?= ($valores['metodo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <?php if ($esGasto): ?><div class="campo"><label for="g-ref">Referencia <span class="opcional">(opcional)</span></label><input id="g-ref" type="text" name="referencia" value="<?= $v('referencia') ?>" maxlength="100" placeholder="N° de factura"></div><?php endif; ?>
    </div>
    <label class="check"><input type="checkbox" name="recurrente" value="1" <?= ($valores['recurrente'] ?? '') === '1' ? 'checked' : '' ?> data-muestra="#g-dia-campo"> <span><b>Se repite cada mes</b></span></label>
    <div class="campo mt" id="g-dia-campo"><label for="g-dia">Día del mes</label><input id="g-dia" name="dia" inputmode="numeric" value="<?= $v('dia', (string) min(28, (int) date('j'))) ?>" class="corto"><span class="ayuda">Del 1 al 28. Se anota solo cada mes.</span></div>
    <button class="btn btn-primario btn-grande btn-ancho mt" type="submit"><?= $esGasto ? 'Guardar gasto' : 'Guardar ingreso' ?></button>
  </form>
</div>
