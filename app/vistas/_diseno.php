<?php
/** Diseño general (con sesión). Variables: $titulo, $contenido, $u */
$actual = $_GET['r'] ?? 'inicio';
$grupo = match (true) {
    in_array($actual, ['programas', 'programa', 'programa_nuevo', 'programa_editar', 'programa_comando'], true) => 'programas',
    in_array($actual, ['solicitudes', 'solicitud'], true) => 'solicitudes',
    in_array($actual, ['clientes', 'cliente'], true) => 'clientes',
    in_array($actual, ['demos', 'demo_nueva'], true) => 'demos',
    in_array($actual, ['finanzas', 'gastos', 'ingresos_extra'], true) => 'finanzas',
    in_array($actual, ['mi_cuenta', 'codigos'], true) => 'mi_cuenta',
    default => $actual,
};
$porAtender = solicitudes()->porAtender();
// Arriba (escritorio): Inicio · Solicitudes · Clientes · Demos · Finanzas · Programas · Más
$menu = [['inicio', 'Inicio', 'inicio'], ['solicitudes', 'Solicitudes', 'bandeja'], ['clientes', 'Clientes', 'clientes'], ['demos', 'Demos', 'demo']];
if (puede('finanzas')) $menu[] = ['finanzas', 'Finanzas', 'finanzas'];
$menu[] = ['programas', 'Programas', 'programas'];
$mas = [['vencimientos', 'Vencimientos', 'calendario'], ['embudo', 'Embudo', 'embudo']];
if (puede('auditoria')) $mas[] = ['auditoria', 'Auditoría', 'actividad'];
if (puede('usuarios')) $mas[] = ['usuarios', 'Usuarios', 'usuarios'];
if (puede('ajustes')) $mas[] = ['ajustes', 'Ajustes', 'ajustes'];
$mas[] = ['ayuda', 'Ayuda', 'ayuda'];
$enMas = in_array($grupo, array_column($mas, 0), true);
// Abajo (celular): 4 botones + Más
$abajo = [['inicio', 'Inicio', 'inicio'], ['solicitudes', 'Solicitudes', 'bandeja'], ['clientes', 'Clientes', 'clientes'],
          puede('finanzas') ? ['finanzas', 'Finanzas', 'finanzas'] : ['demos', 'Demos', 'demo']];
$grupoAbajo = in_array($grupo, array_column($abajo, 0), true) ? $grupo : 'mas';
$avisos = tomar_avisos();
$badge = fn() => $porAtender ? '<span class="badge" aria-label="' . $porAtender . ' por atender">' . ($porAtender > 99 ? '99+' : $porAtender) . '</span>' : '';
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
        <a href="<?= e(url($r)) ?>"<?= $grupo === $r ? ' aria-current="page"' : '' ?>><?= e($t) ?><?= $r === 'solicitudes' ? $badge() : '' ?></a>
      <?php endforeach; ?>
      <details class="menu-mas"<?= $enMas ? ' data-actual' : '' ?>>
        <summary<?= $enMas ? ' aria-current="page"' : '' ?>>Más <?= icono('abajo') ?></summary>
        <div class="menu-mas-lista">
          <?php foreach ($mas as [$r, $t, $i]): ?>
            <a href="<?= e(url($r)) ?>"<?= $grupo === $r ? ' aria-current="page"' : '' ?>><?= icono($i) ?><?= e($t) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
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
    <a href="<?= e(url($r)) ?>"<?= $grupoAbajo === $r ? ' aria-current="page"' : '' ?>><span class="ico-nav"><?= icono($i) ?><?= $r === 'solicitudes' ? $badge() : '' ?></span><?= e($t) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('mas')) ?>"<?= $grupoAbajo === 'mas' ? ' aria-current="page"' : '' ?>><span class="ico-nav"><?= icono('mas_menu') ?></span>Más</a>
</nav>
<dialog class="hoja" id="hoja" aria-label="Formulario">
  <div class="hoja-cuerpo" id="hoja-cuerpo"></div>
</dialog>
</body>
</html>
