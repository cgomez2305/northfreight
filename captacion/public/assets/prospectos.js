/* NorthStar Captación · prospectos: evita el doble clic al registrar un resultado (se desactivan los botones justo después de enviar). */
(function () {
  'use strict';
  document.querySelectorAll('.pc form').forEach(function (f) {
    f.addEventListener('submit', function () {
      setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  });
})();
