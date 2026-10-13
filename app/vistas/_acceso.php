<?php
/** Diseño de las pantallas de ingreso (sin sesión). Variables: $titulo, $contenido, $ancha (opcional) */
use Tukan\Productos;

$avisos = tomar_avisos();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14213D">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(token_csrf()) ?>">
<title><?= e($titulo) ?></title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/app.css?v=<?= e((string) @filemtime(RAIZ . '/public/assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(url('colores_css')) ?>">
<script src="/assets/app.js?v=<?= e((string) @filemtime(RAIZ . '/public/assets/app.js')) ?>" defer></script>
</head>
<body class="acceso">
<div class="acceso-arriba">
  <div class="bloques" aria-hidden="true"><i></i><i></i><i></i></div>
  <?= logo_tukan(true, 'logo') ?>
  <h1>Panel Tukán Central</h1>
  <p>Un solo ingreso, con verificación en 2 pasos, para administrar todos tus programas.</p>
  <div class="chips" aria-hidden="true">
    <?php foreach (Productos::TIPOS as $k => $t): if ($k === 'otro') continue; ?>
      <span class="p-<?= e($k) ?>"><b class="ico"><?= e($t['letra']) ?></b><?= e($t['nombre']) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<div class="acceso-cuerpo">
  <div class="acceso-caja<?= !empty($ancha) ? ' ancha' : '' ?>">
    <?php if ($avisos): ?>
      <div class="avisos" role="status">
        <?php foreach ($avisos as $a): ?>
          <div class="aviso <?= e($a['tipo']) ?>"><?= icono($a['tipo'] === 'error' ? 'error' : ($a['tipo'] === 'info' ? 'info' : 'ok')) ?><p><?= e($a['texto']) ?></p></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?= $contenido ?>
    <p class="pie-acceso"><?= icono('candado') ?> Acceso solo para administradores de Tukán Software</p>
  </div>
</div>
</body>
</html>
