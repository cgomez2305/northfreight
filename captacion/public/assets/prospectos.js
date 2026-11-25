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
  document.querySelectorAll('.pc form').forEach(function (f) {
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
