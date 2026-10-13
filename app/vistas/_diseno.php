<?php
/** Diseño general (con sesión). Variables: $titulo, $contenido, $u */
$actual = $_GET['r'] ?? 'inicio';
$grupo = match (true) {
    in_array($actual, ['programas', 'programa', 'programa_nuevo', 'programa_editar', 'programa_comando'], true) => 'programas',
    in_array($actual, ['mi_cuenta', 'codigos'], true) => 'mi_cuenta',
    default => $actual,
};
$menu = [['inicio', 'Inicio', 'inicio'], ['programas', 'Programas', 'programas']];
if (puede('auditoria')) $menu[] = ['auditoria', 'Auditoría', 'actividad'];
if (puede('usuarios')) $menu[] = ['usuarios', 'Usuarios', 'usuarios'];
if (puede('ajustes')) $menu[] = ['ajustes', 'Ajustes', 'ajustes'];
$menu[] = ['ayuda', 'Ayuda', 'ayuda'];
// Abajo (celular) solo 5: Inicio, Programas, Auditoría/Ayuda, Ayuda, Cuenta
$abajo = [['inicio', 'Inicio', 'inicio'], ['programas', 'Programas', 'programas']];
if (puede('auditoria')) $abajo[] = ['auditoria', 'Actividad', 'actividad'];
$abajo[] = ['ayuda', 'Ayuda', 'ayuda'];
$abajo[] = ['mi_cuenta', 'Cuenta', 'cuenta'];
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
<body class="r-<?= e($actual) ?>">
<header class="barra">
  <div class="wrap">
    <a class="marca" href="<?= e(url('inicio')) ?>" aria-label="Panel Tukán, ir al inicio"><?= logo_tukan(true) ?><span class="sello">Central</span></a>
    <nav class="nav-arriba" aria-label="Principal">
      <?php foreach ($menu as [$r, $t]): ?>
        <a href="<?= e(url($r)) ?>"<?= $grupo === $r ? ' aria-current="page"' : '' ?>><?= e($t) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="yo">
      <a class="avatar" href="<?= e(url('mi_cuenta')) ?>" title="Mi cuenta"><?= e(iniciales($u['nombre'])) ?></a>
      <div class="quien"><?= e($u['nombre']) ?><small><?= $u['rol'] === 'dueno' ? 'Dueño' : 'Soporte' ?></small></div>
      <form method="post" action="<?= e(url('salir')) ?>" class="enlinea"><?= campo_csrf() ?>
        <button class="btn-salir" type="submit" title="Salir del panel"><?= icono('salir') ?><span>Salir</span></button>
      </form>
    </div>
  </div>
</header>
<main class="wrap" id="contenido">
<?php if ($avisos): ?>
  <div class="avisos" role="status">
    <?php foreach ($avisos as $a): ?>
      <div class="aviso <?= e($a['tipo']) ?>"><?= icono($a['tipo'] === 'error' ? 'error' : ($a['tipo'] === 'info' ? 'info' : 'ok')) ?><p><?= e($a['texto']) ?></p></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?= $contenido ?>
</main>
<nav class="nav-abajo" aria-label="Menú">
  <?php foreach ($abajo as [$r, $t, $i]): ?>
    <a href="<?= e(url($r)) ?>"<?= $grupo === $r ? ' aria-current="page"' : '' ?>><?= icono($i) ?><?= e($t) ?></a>
  <?php endforeach; ?>
</nav>
</body>
</html>
