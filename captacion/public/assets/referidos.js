/* NorthStar Captación · referidos: botón «Copiar» del enlace de cada código. */
(function () {
  'use strict';
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy') || '', done = function () { var o = b.innerHTML; b.textContent = 'Copiado'; setTimeout(function () { b.innerHTML = o; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () {}); return; }
      var i = b.parentNode.querySelector('input'); if (i) { i.select(); try { document.execCommand('copy'); done(); } catch (e) { /* sin portapapeles */ } }
    });
  });
})();
