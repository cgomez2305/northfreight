/* NorthStar Captación · prospectos: cola de llamadas. Una fila abierta a la vez; al registrar un resultado se recuerda la fila SIGUIENTE y, tras volver a la lista,
   se abre sola y recibe el foco (se trabaja la lista de arriba abajo sin buscar). Evita también el doble clic al enviar.
   Barra de arriba (revisión de la grabación del 2026-11-24): panel «Filtros» que se abre y cierra (Esc, clic fuera), filtros que se aplican al elegirlos,
   «Por página» que recarga la lista, panel de reglas que se cierra al hacer clic fuera y atajos de fecha para el siguiente intento. Sin JS: todo sigue funcionando
   (filas <details>, panel de filtros visible con su botón Aplicar y el selector de fecha nativo). */
(function () {
  'use strict';
  var KEY = 'ns-prospect-next';
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var rows = Array.prototype.slice.call(document.querySelectorAll('details[data-pr]'));
  rows.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      rows.forEach(function (o) { if (o !== d && o.open) o.open = false; });
    });
  });
  document.querySelectorAll('.pc form, .pcall form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var li = f.closest('.pc'), nx = li && li.nextElementSibling;
      try { if (f.hasAttribute('data-pr-form') && nx && nx.id) sessionStorage.setItem(KEY, nx.id); } catch (e) {}
      setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  });
  var want = null;
  try { want = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); } catch (e) {}
  // Tras registrar: la fila siguiente si sigue en la lista; si no (pasó de pestaña), la primera
  if (want !== null && rows.length) {
    var li = document.getElementById(want), d = li ? li.querySelector('details[data-pr]') : rows[0];
    if (d) { d.open = true; var s = d.querySelector('summary'); if (s) { s.focus({ preventScroll: true }); s.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' }); } }
  }

  // Guion: inglés o español (sin JS se ven los dos; el idioma elegido se recuerda en este navegador)
  var LK = 'ns-script-lang';
  document.querySelectorAll('[data-script]').forEach(function (box) {
    var seg = box.querySelector('[data-script-lang]');
    if (!seg) return;
    seg.hidden = false;
    var show = function (l) {
      box.querySelectorAll('[data-sc]').forEach(function (d) { d.classList.toggle('is-on', d.getAttribute('data-sc') === l); });
      seg.querySelectorAll('button').forEach(function (b) { var on = b.getAttribute('data-lang') === l; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    };
    var saved = null;
    try { saved = localStorage.getItem(LK); } catch (e) {}
    if (saved === 'en' || saved === 'es') show(saved);
    seg.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { var l = b.getAttribute('data-lang'); show(l); try { localStorage.setItem(LK, l); } catch (e) {} }); });
  });

  // Fase AV: llamadas por Vonage desde el navegador. El SDK (versión fija, con SRI) se descarga SOLO al pulsar «Llamar con Vonage». El servidor comprueba horario,
  // «No llamar más» y teléfono, y entrega un ticket de un solo uso: el navegador nunca decide a qué número se llama. Una llamada a la vez.
  var V = { client: null, session: false, callId: null, rowId: null, btn: null, box: null, t0: 0, timer: null, muted: false };
  var vbox = function () { return V.btn ? V.btn.parentNode.querySelector('[data-vcall-box]') : null; };
  var vsay = function (txt) { var b = vbox(); if (!b) return; b.hidden = false; var s = b.querySelector('[data-vcall-status]'); if (s) s.textContent = txt; };
  var vtick = function () { var b = vbox(); if (!b || !V.t0) return; var s = Math.floor((Date.now() - V.t0) / 1000); b.querySelector('[data-vcall-timer]').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };
  var loadSdk = function (btn) {
    if (window.vonageClientSDK) return Promise.resolve();
    return new Promise(function (ok, ko) {
      var sc = document.createElement('script');
      sc.src = btn.getAttribute('data-sdk'); sc.integrity = btn.getAttribute('data-sri'); sc.crossOrigin = 'anonymous'; sc.async = true;
      sc.onload = function () { window.vonageClientSDK ? ok() : ko(new Error('sdk')); }; sc.onerror = function () { ko(new Error('sdk')); };
      document.head.appendChild(sc);
    });
  };
  var vend = function (msg) {
    clearInterval(V.timer); V.timer = null;
    var b = vbox(), done = V.rowId, btn = V.btn;
    if (b) { b.querySelector('[data-vcall-mute]').hidden = true; b.querySelector('[data-vcall-hang]').hidden = true; }
    if (msg) vsay(msg);
    if (btn) btn.disabled = false;
    V.callId = null; V.t0 = 0; V.muted = false;
    if (!done || !window.NS || !NS.post) return;
    setTimeout(function () {   // el estado final lo dan los webhooks de Vonage: se pregunta al servidor y se sugiere el resultado
      NS.post('/ajax/vonage/call', { id: done }).then(function (r) {
        if (!r || !r.ok) return;
        vsay(r.answered ? 'Terminada · contestó' + (r.duration ? ' · ' + Math.floor(r.duration / 60) + ' min ' + (r.duration % 60) + ' s' : '') : r.label + (r.reason ? ': ' + r.reason : ''));
        var form = btn && btn.closest('.pr__main') ? btn.closest('.pr__main').querySelector('[data-pr-form]') : null;
        if (form && r.suggest) {
          form.querySelectorAll('.rbtn').forEach(function (x) { x.classList.toggle('is-suggested', x.value === r.suggest); });
          var sb = form.querySelector('.rbtn[value="' + r.suggest + '"]'); if (sb) sb.focus({ preventScroll: true });
        }
      });
    }, 2500);
  };
  document.querySelectorAll('[data-vcall]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (V.callId) { vsay('Ya hay una llamada en curso.'); return; }
      V.btn = btn; btn.disabled = true; vsay('Conectando…');
      var b = vbox(); if (b) { b.querySelector('[data-vcall-mute]').hidden = false; b.querySelector('[data-vcall-hang]').hidden = false; b.querySelector('[data-vcall-timer]').textContent = ''; }
      var start;
      loadSdk(btn).then(function () {
        return NS.post('/ajax/vonage/start', { prospect_id: parseInt(btn.getAttribute('data-vcall'), 10) });
      }).then(function (r) {
        if (!r || !r.ok) throw new Error((r && r.msg) || 'No se pudo iniciar la llamada.');
        start = r; V.rowId = r.call_id;
        if (!V.client) {
          V.client = new window.vonageClientSDK.VonageClient();
          V.client.on('legStatusUpdate', function (callId, legId, st) {
            if (callId !== V.callId || legId === callId) return;   // solo el tramo al teléfono del prospecto (el del navegador se contesta solo)
            if (st === 'RINGING' || st === 'ringing') vsay('Sonando…');
            if (st === 'ANSWERED' || st === 'answered') { vsay('En llamada'); V.t0 = Date.now(); clearInterval(V.timer); V.timer = setInterval(vtick, 1000); }
          });
          V.client.on('callHangup', function (callId) { if (callId === V.callId) vend('Llamada terminada'); });
          V.client.on('sessionError', function () { V.session = false; });
        }
        return V.client.createSession(start.jwt).then(function () { V.session = true; });
      }).then(function () {
        vsay('Llamando…');
        return V.client.serverCall({ ticket: start.ticket });
      }).then(function (callId) { V.callId = callId; }).catch(function (e) {
        var m = e && e.message && e.message !== 'sdk' ? e.message : 'No se pudo conectar con Vonage. Revisa el permiso del micrófono e inténtalo de nuevo.';
        V.rowId = null; vend(m);
      });
    });
  });
  document.addEventListener('click', function (e) {
    var h = e.target.closest('[data-vcall-hang]'), m = e.target.closest('[data-vcall-mute]');
    if (h && V.client && V.callId) V.client.hangup(V.callId).catch(function () {});
    if (m && V.client && V.callId) {
      var p = V.muted ? V.client.unmute(V.callId) : V.client.mute(V.callId);
      p.then(function () { V.muted = !V.muted; m.setAttribute('aria-pressed', V.muted ? 'true' : 'false'); m.textContent = V.muted ? 'Activar micrófono' : 'Silenciar'; }).catch(function () {});
    }
  });
  window.addEventListener('beforeunload', function (e) { if (V.callId) { e.preventDefault(); e.returnValue = ''; } });

  // Por página
  var per = document.querySelector('.pbar [data-per]');
  if (per) per.addEventListener('change', function () { var u = new URL(location.href); u.searchParams.set('per', per.value); u.searchParams.delete('page'); location.href = u.toString(); });

  // Reglas: el panel se cierra al hacer clic fuera o con Esc
  var rules = document.querySelector('[data-rules]');
  if (rules) {
    document.addEventListener('click', function (e) { if (rules.open && !rules.contains(e.target)) rules.open = false; });
    rules.addEventListener('keydown', function (e) { if (e.key === 'Escape' && rules.open) { rules.open = false; rules.querySelector('summary').focus(); } });
  }

  // Siguiente intento: atajos «Mañana», «En 3 días», «En 1 semana» (fecha de Nueva York, como el campo)
  var etToday = function () {
    try { var p = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); return new Date(p + 'T12:00:00Z'); } catch (e) { return new Date(); }
  };
  document.querySelectorAll('[data-qd]').forEach(function (q) {
    var inp = q.parentNode.querySelector('input[type=date]');
    if (!inp) return;
    q.hidden = false;
    var mark = function () { q.querySelectorAll('button').forEach(function (b) { var on = b.getAttribute('data-v') === inp.value; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }); };
    q.querySelectorAll('button').forEach(function (b) {
      var d = etToday(); d.setUTCDate(d.getUTCDate() + parseInt(b.getAttribute('data-days'), 10));
      b.setAttribute('data-v', d.toISOString().slice(0, 10));
      b.addEventListener('click', function () { inp.value = inp.value === b.getAttribute('data-v') ? '' : b.getAttribute('data-v'); mark(); });
    });
    inp.addEventListener('change', mark); mark();
  });
})();
