<?php
use Tukan\{Estado, Productos};

$notas = [
    'tuevento'    => 'Vive en el servidor qfcorp. Usa como ID <b>tuevento</b> y como dirección <b>https://tuevento.tukanec.com</b>.',
    'tuplanner'   => 'Vive en el servidor qfcorp. Usa como ID <b>tuplanner</b> y como dirección <b>https://tuplanner.tukanec.com</b>.',
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

<div class="rejilla dos seccion">
  <div class="tarjeta preguntas" id="ayuda-modulos">
    <h2>Módulos: encender y apagar funciones</h2>
    <details><summary>¿Qué es un módulo?</summary><div>
      <p>Cada programa tiene una <b>base</b> que siempre está y <b>módulos</b> que se encienden o se apagan (por ejemplo en TuGuardería: Hotel, Peluquería, Rutas con GPS, Portal de dueños). Cada módulo tiene su precio al mes. Así cada cliente paga solo lo que usa.</p>
      <p>Un <b>plan</b> es un precio con varios módulos incluidos. Lo que el cliente enciende además de su plan es <b>extra</b> (se suma) y lo que apaga de su plan es <b>quitado</b> (se descuenta). Si acordaste otro valor, escríbelo en <b>Precio acordado</b>: vale ese.</p></div></details>
    <details><summary>¿Cómo enciendo o apago un módulo de un cliente?</summary><div>
      <p>Abre su ficha en <a href="<?= e(url('clientes')) ?>">Clientes</a> y toca la pestaña <b>Módulos</b>. Mueve los interruptores: el total al mes cambia en vivo. Toca <b>Revisar y guardar</b>: verás qué se enciende, qué se apaga y el precio nuevo, y confirmas con <b>Sí, guardar</b>.</p>
      <p>Si enciendes uno que necesita otro (por ejemplo Anuncios necesita el Portal), el otro se enciende solo. Apagar <b>no borra datos</b>: si lo vuelves a encender, todo sigue como estaba.</p>
      <p>En TuGuardería los interruptores están en la ficha del programa, junto a la <b>Licencia</b>: el total al mes pasa a ser el valor de la licencia.</p>
      <p>Solo el dueño puede cambiar módulos; soporte los ve.</p></div></details>
    <details><summary>Un cliente pidió un módulo desde «Mi plan»</summary><div>
      <p>Llega a <a href="<?= e(url('solicitudes', ['chip' => 'modulos'])) ?>">Solicitudes</a> como <b>Cambio de módulos</b> (y por Telegram). <b>Aprobar</b> te muestra exactamente qué se enciende y el precio nuevo antes de confirmar. <b>Rechazar</b> pide el motivo, que el cliente ve en «Mi plan».</p></div></details>
    <details><summary>¿Dónde cambio los precios de los módulos y los planes?</summary><div>
      <p>En <a href="<?= e(url('programas')) ?>">Programas</a>, abre el programa y toca <b>Catálogo de módulos y planes</b> → <b>Editar precios y planes</b>. Cambia precios, descripciones (lo que lee el cliente), el orden y qué trae cada plan. Pide tu código de 2 pasos.</p>
      <p>En <a href="<?= e(url('finanzas')) ?>">Finanzas</a>, <b>Ingreso por módulo</b> dice cuánto suman al mes los módulos extra: así ves qué módulos venden más. En Clientes, el filtro <b>Con módulos extra</b> muestra quiénes pagan más que su plan.</p></div></details>
    <details><summary>Demos: ¿con qué módulos?</summary><div>
      <p>Nada se da gratis: quien quiere probar <b>pide una demo</b> (llega a Solicitudes como <b>Pedido de demo</b>) y tú la creas con <b>Crear demo</b>, con sus datos ya llenos. Eliges el plan, los días de la demo y, si el programa lo permite, qué módulos encender (lo normal: todos, para que vea todo).</p></div></details>
    <details><summary>¿Cómo le mando los accesos de la demo por correo?</summary><div>
      <p>En <b>Demo lista</b>, debajo de los accesos, está <b>Enviar por correo</b> con el correo de la demo ya escrito (lo puedes cambiar). Le llega «Su demo de … está lista» desde <b>info@tukansoftware.com</b>, con su usuario, la contraseña, el botón <b>Entrar a su demo</b>, el día en que termina y el contacto de Tukán. Hazlo en esa misma pantalla: la contraseña se ve una sola vez y no se guarda. Queda en la Auditoría (sin la contraseña).</p>
      <p>Si el botón dice <b>Configure el correo de Tukán</b>, en el servidor corre una vez: <code>cd /var/www/tukan-central &amp;&amp; git pull &amp;&amp; bash deploy/configurar_correo.sh</code></p></div></details>
  </div>
  <div class="tarjeta preguntas" id="ayuda-pausar">
    <h2>Pausar o borrar un cliente</h2>
    <details><summary>¿Qué pasa cuando pauso a un cliente?</summary><div>
      <p>En <a href="<?= e(url('clientes')) ?>">Clientes</a> toca <b>Pausar</b>, elige por qué y escribe el motivo. Al entrar, el cliente ve «Tu cuenta está pausada» con el motivo y el WhatsApp de Tukán; con <b>En revisión</b> o <b>Uso indebido</b> ve un texto general. Sus datos no se tocan.</p>
      <p><b>Reactivar</b> lo deja como estaba. Soporte puede pausar, pero reactivar lo hace el dueño.</p></div></details>
    <details><summary>¿Y si lo borro por error?</summary><div>
      <p><b>Borrar</b> pide el nombre exacto, el motivo y tu código de 2 pasos. El programa guarda una copia y lo deja <b>30 días en la Papelera</b> (filtro arriba de la lista); ahí tocas <b>Restaurar</b> y vuelve tal como estaba. Pasados los 30 días se borra para siempre (la copia se guarda 1 año).</p>
      <p>En la ficha, <b>Pausas y borrados</b> dice quién lo hizo, cuándo y por qué.</p></div></details>
    <details><summary>Una demo terminó y no pagó: ¿lo borro desde Solicitudes?</summary><div>
      <p>Sí. En los avisos «La demo terminó sin pagar» o «Plan vencido sin renovar» salen <b>Pausar</b> y <b>Borrar cliente</b> junto a los otros botones (en las demás solicitudes de un cliente están abajo, más discretos). Son los mismos formularios de Clientes, con el motivo ya escrito («No pagó después de la demo») y, al pausar, el tipo <b>Falta de pago</b>.</p>
      <p>Al borrar, la solicitud queda <b>atendida</b> sola y vuelves a la bandeja con el día en que se borra para siempre (hasta entonces se puede restaurar desde la Papelera).</p></div></details>
  </div>
  <div class="tarjeta preguntas" id="ayuda-borrar">
    <h2>Borrar la instalación de un cliente (TuGuardería)</h2>
    <details><summary>¿Cómo borro una instalación que ya no se usa?</summary><div>
      <p>Abre el programa en <a href="<?= e(url('programas')) ?>">Programas</a> y baja hasta <b>Borrar esta instalación</b>. Escribe su ID y el código de 2 pasos de tu app.</p>
      <p>El servidor del cliente espera unos <b>5 minutos</b> (puedes tocar <b>Cancelar el borrado</b>), guarda una <b>copia final completa</b> en <span class="mono">/var/backups/borrados/</span> y luego borra el programa, la base de datos, las fotos y los respaldos automáticos.</p>
      <p>Cuando el programa deja de contestar, el panel lo marca <b>Borrado terminado</b>. Ahí puedes tocar <b>Quitar también del panel</b> (su historial sigue en Auditoría).</p>
      <p>Solo el dueño puede hacerlo. Si la copia final falla, no se borra nada y el panel te lo dice.</p></div></details>
    <details><summary>¿Dónde borro un programa (por ejemplo Dogssy)?</summary><div>
      <p>En <a href="<?= e(url('inicio')) ?>">Inicio</a>, en su tarjeta, toca <b>⋯</b> → <b>Borrar programa…</b> (o en <a href="<?= e(url('programas')) ?>">Programas</a>, el enlace <b>Borrar programa…</b>). Te lleva a la zona de peligro de su ficha.</p>
      <p>Si no responde o tiene un conector antiguo, la ficha te da los 3 pasos: (1) el comando para copiar y pegar en su servidor, <span class="mono">cd /home/qf/github/tuguarderia &amp;&amp; git pull &amp;&amp; sudo bash borrar_cliente.sh CARPETA</span>; (2) quitar su dirección en Cloudflare (con el token de Ajustes, el botón <b>Quitar solo de Cloudflare</b> lo hace); (3) <b>Quitar del panel</b> escribiendo su ID.</p></div></details>
    <details><summary>No me sale el botón de borrar</summary><div>
      <p>Ese cliente todavía no tiene la versión nueva (mientras tanto, usa los pasos a mano de arriba). Para tenerlo, en el servidor corre (cambia DOMINIO y CARPETA):</p>
      <p class="comando">cd /home/qf/github/tuguarderia &amp;&amp; git pull &amp;&amp; sudo bash instalar.sh DOMINIO CARPETA</p>
      <p>Luego toca <b>Probar conexión</b> en el panel.</p></div></details>
    <details><summary>Me arrepentí: ¿cómo recupero un cliente borrado?</summary><div>
      <p>La copia final está en el servidor del cliente, en <span class="mono">/var/backups/borrados/CARPETA_FECHA.tar.gz</span>. Trae la base de datos (<span class="mono">base_CARPETA.sql</span>), los archivos, las fotos, la configuración de nginx y las credenciales. Pídele ayuda a Claude para restaurarla.</p></div></details>
  </div>
  <div class="tarjeta preguntas" id="ayuda-cloudflare">
    <h2>Cloudflare: quitar la dirección sola</h2>
    <details><summary>¿Cómo creo el token de Cloudflare?</summary><div>
      <ol class="pasos mt">
        <li><div><p>Entra a <b>dash.cloudflare.com</b> → arriba a la derecha tu perfil → <b>My Profile</b> → <b>API Tokens</b> → <b>Create Token</b> → <b>Create Custom Token</b>.</p></div></li>
        <li><div><p>Ponle un nombre (ej. <span class="mono">Panel Tukan</span>) y estos permisos: <b>Account → Cloudflare Tunnel → Edit</b>, <b>Zone → DNS → Edit</b> y <b>Zone → Zone → Read</b>.</p></div></li>
        <li><div><p>En <b>Zone Resources</b> elige <b>Include → Specific zone →</b> tu dominio (ej. tukanec.com). Toca <b>Continue to summary</b> y <b>Create Token</b>. Copia el token (se ve una sola vez).</p></div></li>
        <li><div><p>El <b>ID de la cuenta</b> sale en la página de tu dominio (columna derecha, «Account ID»). El <b>ID del túnel</b> sale en Zero Trust → Networks → Tunnels (columna «Tunnel ID»).</p></div></li>
        <li><div><p>Pégalos en <a href="<?= e(url('ajustes')) ?>#cloudflare">Ajustes → Cloudflare</a>, toca <b>Guardar</b> y luego <b>Probar</b>.</p></div></li>
      </ol></div></details>
    <details><summary>¿Qué toca el panel en Cloudflare?</summary><div>
      <p>Solo la dirección <b>exacta</b> de la instalación borrada: lee las rutas del túnel, quita la de esa dirección, las guarda y las vuelve a leer para revisar. Después borra su registro DNS (CNAME) solo si apunta a tu túnel. Nunca toca la dirección de este panel ni las de otros clientes.</p>
      <p>Si no configuras el token, el panel te recuerda quitarla a mano: Zero Trust → Networks → Tunnels → tu túnel → Public Hostname → borrar, y en DNS borrar el CNAME.</p></div></details>
  </div>
</div>
