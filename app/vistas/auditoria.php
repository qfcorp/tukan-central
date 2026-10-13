<?php
use Tukan\Auditoria;

$grupos = ['' => 'Todo', 'acceso' => 'Ingresos al panel', 'fallos' => 'Fallos y bloqueos', 'programas' => 'Entradas a programas', 'cambios' => 'Cambios'];
$malas = ['login_fallido', 'login_bloqueado', '2fa_fallido', 'csrf_rechazado', 'permiso_negado'];
$cambios = ['instancia_agregada', 'instancia_editada', 'instancia_eliminada', 'secreto_cambiado', 'usuario_creado', 'usuario_editado', 'ajustes', 'clave_cambiada', '2fa_quitado', 'codigos_nuevos', '2fa_activado', 'cli'];
$paginas = max(1, (int) ceil($total / $porPagina));
$q = array_filter($f);
?>
<div class="cabeza">
  <div>
    <h1>Auditoría</h1>
    <p class="sub">Todo lo que pasa en el panel queda anotado: quién, qué, cuándo y desde dónde. No se puede borrar.</p>
  </div>
</div>
<form method="get" action="/" class="tarjeta filtros">
  <input type="hidden" name="r" value="auditoria">
  <div class="campo"><label for="f-grupo">Mostrar</label>
    <select id="f-grupo" name="grupo"><?php foreach ($grupos as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['grupo'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="f-accion">Acción</label>
    <select id="f-accion" name="accion"><option value="">Todas</option><?php foreach (Auditoria::ACCIONES as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['accion'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="f-usuario">Correo</label>
    <select id="f-usuario" name="usuario"><option value="">Todos</option><?php foreach ($emails as $em): ?><option value="<?= e($em) ?>" <?= $f['usuario'] === $em ? 'selected' : '' ?>><?= e($em) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="f-inst">Programa</label>
    <select id="f-inst" name="instancia"><option value="">Todos</option><?php foreach ($instancias as $i): ?><option value="<?= e($i) ?>" <?= $f['instancia'] === $i ? 'selected' : '' ?>><?= e($i) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="f-desde">Desde</label><input id="f-desde" type="date" name="desde" value="<?= e($f['desde']) ?>"></div>
  <div class="campo"><label for="f-hasta">Hasta</label><input id="f-hasta" type="date" name="hasta" value="<?= e($f['hasta']) ?>"></div>
  <div class="campo q"><label for="f-q">Buscar</label><input id="f-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="IP, correo o texto"></div>
  <div class="acciones-form"><button class="btn btn-oscuro" type="submit">Filtrar</button><?php if ($q): ?><a class="btn" href="<?= e(url('auditoria')) ?>">Limpiar</a><?php endif; ?></div>
</form>

<div class="tarjeta">
  <p class="chico suave mb"><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'registro' : 'registros' ?></p>
  <?php if (!$filas): ?>
    <p class="suave">No hay registros con esos filtros.</p>
  <?php else: ?>
  <div class="tabla-env">
    <table class="tabla apilable" id="tabla-auditoria">
      <thead><tr><th>Cuándo</th><th>Qué</th><th>Quién</th><th>Programa</th><th>Detalle</th><th>Desde</th></tr></thead>
      <tbody>
      <?php foreach ($filas as $r): ?>
        <tr data-accion="<?= e($r['accion']) ?>">
          <td class="nowrap" data-t="Cuándo"><?= e(fecha_hora((int) $r['momento'])) ?></td>
          <td data-t="Qué"><span class="etiqueta <?= in_array($r['accion'], $malas, true) ? 'mal' : (in_array($r['accion'], $cambios, true) ? 'alerta' : ($r['accion'] === 'login_ok' || $r['accion'] === 'entrar' ? 'ok' : '')) ?>"><?= e(Auditoria::texto($r['accion'])) ?></span></td>
          <td data-t="Quién"><?= e($r['email'] ?? 'Sistema') ?></td>
          <td data-t="Programa" class="mono"><?= e($r['instancia_id'] ?? '') ?></td>
          <td data-t="Detalle"><?= e($r['detalle']) ?></td>
          <td data-t="Desde" class="chico"><span class="mono"><?= e($r['ip'] ?? '') ?></span><br><span class="suave"><?= e(navegador($r['agente'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="paginas">
    <?= $pagina > 1 ? '<a class="btn btn-chico" href="' . e(url('auditoria', $q + ['pagina' => $pagina - 1])) . '">← Más nuevos</a>' : '<span></span>' ?>
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
    <?= $pagina < $paginas ? '<a class="btn btn-chico" href="' . e(url('auditoria', $q + ['pagina' => $pagina + 1])) . '">Más antiguos →</a>' : '<span></span>' ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
