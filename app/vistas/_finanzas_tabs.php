<nav class="pestanas" aria-label="Finanzas">
  <a href="<?= e(url('finanzas')) ?>"<?= ($_GET['r'] ?? '') === 'finanzas' ? ' aria-current="page"' : '' ?>>Resumen</a>
  <a href="<?= e(url('gastos')) ?>"<?= ($_GET['r'] ?? '') === 'gastos' ? ' aria-current="page"' : '' ?>>Gastos</a>
  <a href="<?= e(url('ingresos_extra')) ?>"<?= ($_GET['r'] ?? '') === 'ingresos_extra' ? ' aria-current="page"' : '' ?>>Ingresos extra</a>
</nav>
