/* Panel Tukán Central — JavaScript mínimo, sin librerías (todo funciona también sin él). */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  // Copiar al portapapeles: <button data-copiar="#selector">
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copiar]');
    if (!b) return;
    var origen = document.querySelector(b.getAttribute('data-copiar'));
    if (!origen) return;
    var texto = origen.children.length && origen.tagName === 'UL'
      ? Array.prototype.map.call(origen.children, function (li) { return li.textContent.trim(); }).join('\n')
      : origen.textContent.trim();
    var aviso = b.parentNode.querySelector('.copiado');
    var listo = function () { if (aviso) { aviso.textContent = 'Copiado ✓'; setTimeout(function () { aviso.textContent = ''; }, 4000); } };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(texto).then(listo, function () { seleccionar(origen); });
    } else {
      seleccionar(origen);
      try { document.execCommand('copy'); listo(); } catch (e) { /* se queda seleccionado para copiar a mano */ }
    }
  });
  function seleccionar(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  // Imprimir
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-imprimir]')) window.print();
  });

  // Confirmar antes de enviar: <form data-confirmar="¿Seguro?">
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var t = f.getAttribute && f.getAttribute('data-confirmar');
    if (t && !window.confirm(t)) { ev.preventDefault(); return; }
    // Evita doble envío
    var b = f.querySelector('button[type="submit"]');
    if (b && f.target !== '_blank') { setTimeout(function () { b.disabled = true; }, 0); setTimeout(function () { b.disabled = false; }, 8000); }
  });

  // Código de 6 dígitos: solo números y se envía solo al completar
  document.querySelectorAll('input[data-auto-enviar]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/\s+/g, '');
      if (/^\d{6}$/.test(v) && inp.form) {
        inp.value = v;
        if (inp.form.requestSubmit) inp.form.requestSubmit(); else inp.form.submit();
      }
    });
  });

  // Formulario de programa: según el tipo, muestra la carpeta y sugiere ID y nombre
  var form = document.getElementById('form-programa');
  if (form) {
    var campoCarpeta = form.querySelector('[data-carpeta-campo]');
    var id = form.querySelector('#id');
    var nombre = form.querySelector('#nombre');
    var plantilla = form.querySelector('#plantilla');
    var actualizar = function (sugerir) {
      var r = form.querySelector('input[name="producto"]:checked');
      if (!r) { if (campoCarpeta) campoCarpeta.hidden = true; return; }
      if (campoCarpeta) campoCarpeta.hidden = r.getAttribute('data-carpeta') !== '1' && !(plantilla && plantilla.value.indexOf('{CARPETA}') >= 0);
      if (plantilla) plantilla.placeholder = r.getAttribute('data-comando');
      if (sugerir && id && !id.readOnly && (id.value === '' || id.dataset.sugerido === '1') && r.value !== 'tuguarderia' && r.value !== 'otro') {
        id.value = r.value; id.dataset.sugerido = '1';
      }
      if (sugerir && nombre && (nombre.value === '' || nombre.dataset.sugerido === '1')) {
        var cliente = r.value === 'tuguarderia' && id && id.value ? ' ' + id.value.charAt(0).toUpperCase() + id.value.slice(1) : '';
        nombre.value = r.getAttribute('data-nombre') + cliente; nombre.dataset.sugerido = '1';
      }
    };
    form.addEventListener('change', function (ev) { if (ev.target.name === 'producto') actualizar(true); });
    if (id) id.addEventListener('input', function () { id.dataset.sugerido = '0'; id.value = id.value.toLowerCase(); actualizar(true); });
    if (nombre) nombre.addEventListener('input', function () { nombre.dataset.sugerido = '0'; });
    actualizar(false);
  }

  // Inicio: si las cifras guardadas tienen más de 2 minutos, se piden de nuevo sin bloquear la pantalla
  var tablero = document.getElementById('tablero');
  if (tablero && tablero.getAttribute('data-viejo') === '1' && window.fetch) {
    var sub = document.getElementById('actualizado');
    var boton = document.querySelector('form[data-refrescar] button');
    if (boton) { boton.disabled = true; boton.querySelector('svg') && boton.querySelector('svg').classList.add('cargando'); }
    if (sub) sub.textContent = 'Actualizando las cifras…';
    fetch('/?r=refrescar', { method: 'POST', headers: { 'X-CSRF': csrf, 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok && j.hecho) window.location.reload(); else if (boton) { boton.disabled = false; } })
      .catch(function () { if (boton) boton.disabled = false; if (sub) sub.textContent = 'No se pudieron actualizar las cifras. Toca "Actualizar".'; });
  }
})();
