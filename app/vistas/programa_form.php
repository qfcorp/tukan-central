<?php
use Tukan\Productos;

$plantillaTipo = Productos::tipo($d['producto'] ?: 'tuevento')['comando'];
?>
<p class="migas"><a href="<?= e(url('programas')) ?>">Programas</a> / <?= $nuevo ? 'Agregar' : e($inst['nombre']) . ' / Editar' ?></p>
<div class="cabeza">
  <div>
    <h1><?= $nuevo ? 'Agregar programa' : 'Editar ' . e($inst['nombre']) ?></h1>
    <p class="sub"><?= $nuevo ? 'Llena estos datos. Al guardar, el panel te dará un comando para pegar en el servidor del programa.' : 'El ID no se puede cambiar porque es el que tiene guardado el programa.' ?></p>
  </div>
</div>
<?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>

<form method="post" action="<?= e($nuevo ? url('programa_nuevo') : url('programa_editar', ['id' => $inst['id']])) ?>" class="tarjeta" id="form-programa">
  <?= campo_csrf() ?>
  <fieldset class="tipos">
    <legend>1. ¿Qué programa es?</legend>
    <?php foreach (Productos::TIPOS as $k => $t): ?>
      <label class="tipo p-<?= e($k) ?>">
        <input type="radio" name="producto" value="<?= e($k) ?>" required <?= $d['producto'] === $k ? 'checked' : '' ?>
               data-nombre="<?= e($t['nombre']) ?>" data-comando="<?= e($t['comando']) ?>" data-carpeta="<?= Productos::pideCarpeta($t['comando']) ? '1' : '0' ?>">
        <span class="ico chico" aria-hidden="true"><?= e($t['letra']) ?></span>
        <span><strong><?= e($t['nombre']) ?></strong><span class="p"><?= e($t['para']) ?></span></span>
      </label>
    <?php endforeach; ?>
  </fieldset>

  <p class="etq mb"><b>2. Datos del programa</b></p>
  <div class="fila-campos">
    <div class="campo">
      <label for="nombre">Nombre en el panel</label>
      <input id="nombre" name="nombre" type="text" maxlength="60" value="<?= e($d['nombre']) ?>" placeholder="Ej. TuEvento o TuGuardería Dogssy">
      <span class="ayuda">Como quieras verlo en tu pantalla de Inicio.</span>
    </div>
    <div class="campo">
      <label for="id">ID</label>
      <input id="id" name="id" type="text" maxlength="40" pattern="[a-z0-9][a-z0-9_\-]{1,39}" value="<?= e($d['id']) ?>" <?= $nuevo ? 'required' : 'readonly' ?> autocapitalize="none" spellcheck="false" placeholder="Ej. tuevento o dogssy" class="mono">
      <span class="ayuda">Solo minúsculas, números, - y _. Para TuGuardería usa el nombre del cliente (dogssy, huellitas).</span>
    </div>
  </div>
  <div class="campo">
    <label for="url">Dirección del programa</label>
    <input id="url" name="url" type="url" inputmode="url" value="<?= e($d['url']) ?>" required placeholder="https://tuevento.tukanec.com" autocapitalize="none" spellcheck="false">
    <span class="ayuda">La misma dirección que usan los clientes, con https://</span>
  </div>
  <div class="campo" id="campo-carpeta" data-carpeta-campo>
    <label for="carpeta">Carpeta de la instalación (solo TuGuardería)</label>
    <input id="carpeta" name="carpeta" type="text" maxlength="31" value="<?= e($d['carpeta']) ?>" placeholder="Ej. dogssy" autocapitalize="none" spellcheck="false" class="mono">
    <span class="ayuda">El nombre corto que se usó al instalarla (está en /var/www/<b>carpeta</b>). Si lo dejas vacío se usa el ID.</span>
  </div>
  <div class="campo">
    <label for="notas">Notas (opcional)</label>
    <textarea id="notas" name="notas" maxlength="1000" placeholder="Ej. Servidor: VPS de Contabo. Cliente desde octubre."><?= e($d['notas']) ?></textarea>
  </div>
  <?php if (!$nuevo): ?>
    <label class="check mb"><input type="checkbox" name="activo" value="1" <?= $d['activo'] ? 'checked' : '' ?>> <span><b>Activo</b> — si lo desmarcas, el programa queda en pausa: no se revisa ni aparece el botón Entrar.</span></label>
  <?php endif; ?>
  <details class="avanzado"<?= $d['plantilla'] !== '' ? ' open' : '' ?>>
    <summary>Avanzado: comando para el servidor</summary>
    <div class="campo">
      <label for="plantilla">Comando</label>
      <textarea id="plantilla" name="plantilla" class="mono chico" spellcheck="false" placeholder="<?= e($plantillaTipo) ?>"><?= e($d['plantilla']) ?></textarea>
      <span class="ayuda">Déjalo vacío para usar el de siempre. Marcadores: <span class="mono">{ID} {SECRETO} {CARPETA} {URL}</span> (URL = <?= e(url_central()) ?>).</span>
    </div>
  </details>
  <div class="acciones-form">
    <button class="btn btn-primario btn-grande" type="submit"><?= $nuevo ? icono('mas') . ' Agregar y ver el comando' : icono('ok') . ' Guardar cambios' ?></button>
    <a class="btn btn-grande" href="<?= e($nuevo ? url('programas') : url('programa', ['id' => $inst['id']])) ?>">Cancelar</a>
  </div>
</form>
