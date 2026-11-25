/* NorthStar Captación · borrador de correos a prospectos (fase AQ): insertar variables donde está el cursor y recontar destinatarios al cambiar el segmento.
   Sin JS: las variables se escriben a mano y «Contar destinatarios» es un botón normal. */
(function () {
  'use strict';
  var body = document.getElementById('pm-body');
  // la variable va al último campo de texto en el que estuviste (asunto, mensaje o segundo correo)
  document.querySelectorAll('#pm-form input[name=subject],#pm-form textarea,#pm-form input[name=followup_subject]').forEach(function (f) { f.addEventListener('focus', function () { body = f; }); });
  var fu = document.querySelector('[data-fu]'), fuF = document.querySelector('[data-fu-fields]');
  if (fu && fuF) { var showFu = function () { fuF.hidden = fu.value === '0'; }; fu.addEventListener('change', showFu); showFu(); }
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
