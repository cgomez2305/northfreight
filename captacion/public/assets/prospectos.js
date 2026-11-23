/* NorthStar Captación · prospectos: cola de llamadas. Una fila abierta a la vez; al registrar un resultado se recuerda la fila SIGUIENTE y, tras volver a la lista,
   se abre sola y recibe el foco (se trabaja la lista de arriba abajo sin buscar). Evita también el doble clic al enviar. Sin JS: filas <details> normales. */
(function () {
  'use strict';
  var KEY = 'ns-prospect-next';
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
    if (d) { d.open = true; var s = d.querySelector('summary'); if (s) { s.focus({ preventScroll: true }); s.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); } }
  }
})();
