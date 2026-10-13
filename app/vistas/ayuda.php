<?php
use Tukan\{Estado, Productos};

$notas = [
    'tuevento'    => 'Vive en el servidor qfcorp. Usa como ID <b>tuevento</b> y como dirección <b>https://tuevento.tukanec.com</b>.',
    'tusalon'     => 'Vive en el servidor qfcorp. Usa como ID <b>tusalon</b>.',
    'tuvocalia'   => 'Vive en el VPS de las ligas. Usa como ID <b>tuvocalia</b>.',
    'tuacademia'  => 'Vive en el VPS. Usa como ID <b>tuacademia</b>.',
    'tuguarderia' => 'Cada cliente tiene su propia instalación: agrega <b>una por cliente</b> (dogssy, huellitas…). El ID y la carpeta suelen ser el nombre corto del cliente.',
    'otro'        => 'Para un programa nuevo de la familia Tukán que ya tenga su archivo tukan_central.php.',
];
?>
<div class="cabeza">
  <div>
    <h1>Ayuda</h1>
    <p class="sub">Cómo conectar cada programa al panel y qué hacer si algo falla.</p>
  </div>
</div>

<div class="tarjeta">
  <h2>Conectar un programa en 4 pasos</h2>
  <ol class="pasos mt">
    <li><div><p><b>Agrega el programa aquí.</b> En <a href="<?= e(url('programas')) ?>">Programas</a> toca <b>Agregar programa</b>, elige cuál es, escribe un ID corto y su dirección (https://…).</p></div></li>
    <li><div><p><b>Copia el comando que aparece.</b> Tiene un secreto que solo se muestra una vez. Si lo pierdes, toca <b>Cambiar secreto</b> y sale otro.</p></div></li>
    <li><div><p><b>Pégalo en el servidor del programa.</b> Abre tu app de SSH (por ejemplo Termius), entra al servidor donde vive ese programa, pega el comando y toca Enter. Si pide contraseña, es la del servidor.</p></div></li>
    <li><div><p><b>Vuelve y toca "Probar conexión".</b> Si sale <span class="estado on">En línea</span>, ya puedes entrar con un toque desde Inicio.</p></div></li>
  </ol>
</div>

<div class="tarjeta">
  <h2>El comando de cada programa</h2>
  <p class="suave mb">El panel lo arma solo, con el ID y el secreto. Así se ve (las palabras en MAYÚSCULAS se reemplazan):</p>
  <?php foreach (Productos::TIPOS as $k => $t): ?>
    <div class="ayuda-prog p-<?= e($k) ?>" id="<?= e($k) ?>">
      <span class="ico" aria-hidden="true"><?= e($t['letra']) ?></span>
      <div>
        <h3><?= e($t['nombre']) ?></h3>
        <p class="chico suave"><?= e($t['para']) ?>. <?= $notas[$k] ?? '' ?></p>
        <div class="comando"><?= e(strtr($t['comando'], ['{ID}' => 'ID', '{SECRETO}' => 'SECRETO', '{CARPETA}' => 'CARPETA', '{URL}' => url_central()])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="tarjeta preguntas">
  <h2>Si algo falla</h2>
  <?php foreach (['no_conectado', 'secreto', 'reloj', 'sin_conexion', 'tiempo', 'dns', 'tls', 'id', 'formato', 'limite'] as $k): ?>
    <details><summary><?= e(strtok(Estado::ERRORES[$k], ':.')) ?></summary><div><p><?= e(Estado::ERRORES[$k]) ?></p>
      <?php if ($k === 'reloj'): ?><p>En el servidor del programa: <span class="mono">sudo timedatectl set-ntp true</span></p><?php endif; ?>
      <?php if ($k === 'no_conectado'): ?><p>Si ya pegaste el comando, revisa que la dirección sea la correcta y que el programa esté actualizado (debe tener el archivo tukan_central.php).</p><?php endif; ?>
    </div></details>
  <?php endforeach; ?>
</div>

<div class="rejilla dos seccion">
  <div class="tarjeta preguntas">
    <h2>Seguridad</h2>
    <details><summary>¿Por qué pide un código de 6 dígitos?</summary><div><p>Este panel abre todos tus programas. Aunque alguien sepa tu contraseña, sin tu celular no puede entrar.</p></div></details>
    <details><summary>Perdí mi celular</summary><div><p>Entra con uno de tus <b>códigos de respaldo</b> (escríbelo donde va el código de 6 dígitos). Luego, en <a href="<?= e(url('mi_cuenta')) ?>">Mi cuenta</a>, genera códigos nuevos.</p><p>Si no tienes ni el celular ni los códigos, en el servidor del panel corre (cambia el correo):</p><p class="comando">sudo -u www-data php /var/www/tukan-central/bin/quitar_2fa.php tu@correo.com</p></div></details>
    <details><summary>Olvidé mi contraseña</summary><div><p>En el servidor del panel corre:</p><p class="comando">sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php</p><p>Escribe tu mismo correo y una contraseña nueva.</p></div></details>
    <details><summary>¿Puedo apagar el ingreso normal del super usuario en cada programa?</summary><div><p>Sí. En el servidor del programa pon <span class="mono">TUKAN_SOLO_CENTRAL=1</span> en su configuración: el super usuario solo podrá entrar desde este panel. El ingreso de los clientes no cambia.</p></div></details>
  </div>
  <div class="tarjeta preguntas">
    <h2>Cifras de Inicio</h2>
    <details><summary>¿Cada cuánto se actualizan?</summary><div><p>Cada 15 minutos automáticamente, y al abrir Inicio si pasaron más de 2 minutos. También puedes tocar <b>Actualizar</b>.</p></div></details>
    <details><summary>Un programa sale "Sin conexión" pero sí funciona</summary><div><p>Toca su tarjeta y luego <b>Probar conexión</b>: el panel te dice exactamente qué pasa. Mientras tanto se muestran los últimos datos conocidos.</p></div></details>
    <details><summary>¿Qué son las barritas de colores?</summary><div><p>La disponibilidad de los últimos 7 días, en tramos de 6 horas: verde = siempre en línea, amarillo = falló alguna vez, rojo = caído, gris = sin datos.</p></div></details>
  </div>
</div>
