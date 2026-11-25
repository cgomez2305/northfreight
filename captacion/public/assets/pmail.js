/* NorthStar Captación · borrador de correos a prospectos (fase AQ): insertar variables donde está el cursor y recontar destinatarios al cambiar el segmento.
   Sin JS: las variables se escriben a mano y «Contar destinatarios» es un botón normal. */
(function () {
  'use strict';
  var body = document.getElementById('pm-body');
  document.querySelectorAll('[data-var]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!body) return;
      var v = b.getAttribute('data-var'), s = body.selectionStart || 0, e = body.selectionEnd || 0;
      body.value = body.value.slice(0, s) + v + body.value.slice(e);
      body.focus();
      body.setSelectionRange(s + v.length, s + v.length);
    });
  });
  var count = document.querySelector('[data-count-btn]');
  document.querySelectorAll('[data-recount]').forEach(function (sel) {
    sel.addEventListener('change', function () { if (count) count.click(); });
  });
})();
