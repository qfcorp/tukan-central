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

  // Confirmar antes de enviar: <form data-confirmar="¿Seguro?"> (data-dos-veces: lo delicado se confirma dos veces)
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var t = f.getAttribute && f.getAttribute('data-confirmar');
    if (t && !window.confirm(t)) { ev.preventDefault(); return; }
    if (f.hasAttribute && f.hasAttribute('data-dos-veces') && !window.confirm('Esto no se puede deshacer fácilmente. ¿Continuar?')) { ev.preventDefault(); return; }
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

  // ------------------------------------------------------------------ Protocolo v2

  // Hoja (celular) / ventana (escritorio) con el formulario de un botón: <a data-hoja href="/?r=accion&...">
  var hoja = document.getElementById('hoja');
  var cuerpoHoja = document.getElementById('hoja-cuerpo');
  function cerrarHoja() { if (hoja && hoja.open) hoja.close(); }
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[data-hoja]');
    if (a && hoja && hoja.showModal && window.fetch) {
      ev.preventDefault();
      cuerpoHoja.innerHTML = '<p class="suave">Cargando…</p>';
      if (!hoja.open) hoja.showModal();
      fetch(a.href + '&parcial=1', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
        .then(function (r) { return r.text(); })
        .then(function (html) { cuerpoHoja.innerHTML = html; prepararFormulario(cuerpoHoja); var p = cuerpoHoja.querySelector('input:not([type=hidden]),select,textarea'); if (p) p.focus(); })
        .catch(function () { window.location.href = a.href; });
      return;
    }
    var c = ev.target.closest('[data-cerrar]');
    if (c && hoja && hoja.contains(c)) { ev.preventDefault(); cerrarHoja(); }
  });
  if (hoja) hoja.addEventListener('click', function (ev) { if (ev.target === hoja) cerrarHoja(); });

  // Formularios v2: valor sugerido (cotizar) y confirmación con el valor y la fecha
  function prepararFormulario(raiz) {
    (raiz || document).querySelectorAll('form[data-cotizar-url]').forEach(function (f) {
      var aviso = f.querySelector('[data-cotizado]');
      var monto = f.querySelector('[data-campo="monto"]');
      var nombre = f.getAttribute('data-nombre') || 'este cliente';
      var hasta = '';
      var confirmar = function () {
        var m = monto ? monto.value : '';
        f.setAttribute('data-confirmar', 'Vas a registrar $' + m + ' de ' + nombre + '.' + (hasta ? ' Quedará activa hasta el ' + hasta + '.' : '') + ' ¿Confirmas?');
      };
      var pedir = function () {
        var q = '';
        ['plan', 'periodo', 'meses'].forEach(function (k) { var c = f.querySelector('[data-campo="' + k + '"]'); if (c && c.value) q += '&' + k + '=' + encodeURIComponent(c.value); });
        fetch(f.getAttribute('data-cotizar-url') + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.ok) { if (aviso) aviso.textContent = ''; return; }
            if (monto && j.monto_texto) monto.value = j.monto_texto;
            hasta = j.hasta_texto || '';
            if (aviso) aviso.textContent = (j.detalle ? j.detalle + ' · ' : '') + j.monto_bonito + (hasta ? ' · quedará activa hasta el ' + hasta : '');
            confirmar();
          }).catch(function () {});
      };
      f.addEventListener('change', function (ev) { var k = ev.target.getAttribute('data-campo'); if (k === 'plan' || k === 'periodo' || k === 'meses') pedir(); });
      if (monto) monto.addEventListener('input', confirmar);
      pedir();
    });
    (raiz || document).querySelectorAll('input[type=checkbox][data-muestra]').forEach(function (cb) {
      var obj = document.querySelector(cb.getAttribute('data-muestra'));
      var ver = function () { if (obj) obj.hidden = !cb.checked; };
      cb.addEventListener('change', ver); ver();
    });
  }
  prepararFormulario(document);

  // Selector que se envía solo (filtros)
  document.addEventListener('change', function (ev) {
    var s = ev.target.closest('select[data-enviar-al-cambiar]');
    if (s && s.form) s.form.submit();
  });

  // Borrado de una instalación en marcha: la ficha se recarga sola (<section data-recargar="10">), sin molestar si se está escribiendo
  var recargar = document.querySelector('[data-recargar]');
  if (recargar) {
    var seg = parseInt(recargar.getAttribute('data-recargar'), 10) || 15;
    var otraVez = function () {
      var a = document.activeElement;
      if (document.hidden || (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) { setTimeout(otraVez, seg * 1000); return; }
      window.location.reload();
    };
    setTimeout(otraVez, seg * 1000);
  }

  // Módulos (Anexo B): total al mes en vivo, dependencias y etiquetas de cada interruptor
  document.querySelectorAll('form[data-modulos]').forEach(function (f) {
    var planes = JSON.parse(f.getAttribute('data-planes') || '{}'), deps = JSON.parse(f.getAttribute('data-deps') || '{}');
    var selPlan = f.querySelector('[data-mods-plan]'), acordado = f.querySelector('[data-mods-acordado]');
    var fmt = function (n) { return '$' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
    var caja = function (id) { return f.querySelector('input[name="mod[]"][value="' + id + '"]'); };
    var calcular = function () {
      var p = planes[selPlan ? selPlan.value : ''] || { p: 0, m: [] }, ext = 0, qui = 0;
      f.querySelectorAll('li.mod').forEach(function (li) {
        var id = li.getAttribute('data-id'), on = caja(id).checked, en = p.m.indexOf(id) >= 0, pr = parseFloat(li.getAttribute('data-precio')) || 0;
        li.classList.toggle('on', on);
        if (on && !en) ext += pr;
        if (!on && en) qui += pr;
        var t = li.querySelector('[data-tag]');
        if (t) t.textContent = en ? (on ? 'En el plan' : 'Quitado del plan') : (on ? 'Extra' : 'No incluido');
      });
      var tot = Math.max(0, p.p + ext - qui), a = acordado ? acordado.value.trim().replace(/\./g, '').replace(',', '.') : '';
      if (a !== '' && !isNaN(parseFloat(a))) tot = parseFloat(a);
      var put = function (k, v) { var x = f.querySelector('[data-m="' + k + '"]'); if (x) x.textContent = v; };
      put('plan', fmt(p.p)); put('extra', '+' + fmt(ext)); put('descuento', '−' + fmt(qui)); put('total', fmt(tot));
    };
    f.addEventListener('change', function (ev) {
      var c = ev.target;
      if (c.name === 'mod[]') {
        if (c.checked) (deps[c.value] || []).forEach(function (d) { var x = caja(d); if (x && !x.checked) { x.checked = true; x.dispatchEvent(new Event('change', { bubbles: true })); } });
        else Object.keys(deps).forEach(function (k) { if ((deps[k] || []).indexOf(c.value) >= 0) { var x = caja(k); if (x && x.checked) { x.checked = false; x.dispatchEvent(new Event('change', { bubbles: true })); } } });
      }
      calcular();
    });
    if (acordado) acordado.addEventListener('input', calcular);
    calcular();
  });

  // Menú "Más" (escritorio): se cierra al tocar fuera
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.menu-mas[open], details.menu-prog[open]').forEach(function (d) { if (!d.contains(ev.target)) d.removeAttribute('open'); });
  });
})();
