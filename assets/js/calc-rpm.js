/* NorthStar — calculadora de rate per mile. Vanilla, sin dependencias. Recalcula en cada `input`.
   Sirve a la calculadora completa (/resources/rate-per-mile-calculator/) y a la mini calculadora incrustada (partial minicalc: solo pay, loaded y deadhead y dos resultados):
   cada [data-calc="rpm"] se inicia por separado y los campos o resultados que no existen en esa instancia cuentan como 0 / no se pintan. */
(function () {
  'use strict';

  var roots = document.querySelectorAll('[data-calc="rpm"]');
  if (!roots.length) { return; }

  var MAX = 1e9;
  var nf = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var t = function (s) { var i = window.NS_I18N; return (i && i[s]) || s; };
  var INVALID = t('Enter a number, 0 or higher.');

  function safe(x) { return isFinite(x) ? x : 0; }

  function money(x) {
    var r = Math.round(safe(x) * 100) / 100;
    return (r < 0 ? '−$' : '$') + nf.format(Math.abs(r));
  }

  Array.prototype.forEach.call(roots, function (root) {
    var fields = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-field]'), function (input) {
      var wrap = input.closest('.tools-field, .mcalc__field');
      fields[input.getAttribute('data-field')] = { input: input, msg: wrap ? wrap.querySelector('[data-msg]') : null };
    });
    var outs = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-out]'), function (el) {
      outs[el.getAttribute('data-out')] = el;
    });
    function out(key, text) { if (outs[key]) { outs[key].textContent = text; } }

    /** Lee un campo: vacío o inexistente = 0; texto no numérico, negativo o enorme = 0 y se marca (sin bloquear). */
    function read(key) {
      var f = fields[key];
      if (!f) { return { v: 0, empty: true, bad: false }; }
      var raw = String(f.input.value).replace(/[\s$,]/g, '');
      if (raw === '') { return { v: 0, empty: true, bad: false }; }
      var x = Number(raw);
      if (!isFinite(x) || x < 0 || x > MAX) { return { v: 0, empty: false, bad: true }; }
      return { v: x, empty: false, bad: false };
    }

    function flag(key, bad, text) {
      var f = fields[key];
      if (!f) { return; }
      if (bad) { f.input.setAttribute('aria-invalid', 'true'); } else { f.input.removeAttribute('aria-invalid'); }
      if (f.msg) {
        f.msg.textContent = text || '';
        f.msg.hidden = !text;
      }
    }

    function calc() {
      var r = {};
      ['pay', 'loaded', 'deadhead', 'fuel', 'mpg', 'extras'].forEach(function (k) { r[k] = read(k); });

      var pay = r.pay.v;
      var loaded = r.loaded.v;
      var total = loaded + r.deadhead.v;
      var mpg = r.mpg.v;
      var fuel = mpg > 0 ? (total / mpg) * r.fuel.v : 0;
      var net = pay - fuel - r.extras.v;

      out('rpmLoaded', money(loaded > 0 ? pay / loaded : 0));
      out('rpmAll', money(total > 0 ? pay / total : 0));
      out('fuelCost', money(fuel));
      out('net', money(net));
      out('netPerMile', money(total > 0 ? net / total : 0));

      Object.keys(fields).forEach(function (k) {
        flag(k, r[k].bad, r[k].bad ? INVALID : '');
      });
      if (fields.mpg && !r.mpg.bad && mpg === 0 && total > 0) {
        flag('mpg', false, t('Add your MPG to include fuel cost.'));
      }
    }

    root.addEventListener('input', calc);
    root.addEventListener('change', calc);
    var form = root.querySelector('form');
    if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); }); }
    calc();
  });
})();
