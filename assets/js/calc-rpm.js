/* NorthStar — calculadora de rate per mile. Vanilla, sin dependencias. Recalcula en cada `input`. */
(function () {
  'use strict';

  var root = document.querySelector('[data-calc="rpm"]');
  if (!root) { return; }

  var MAX = 1e9;
  var nf = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var INVALID = 'Enter a number, 0 or higher.';

  var fields = {};
  Array.prototype.forEach.call(root.querySelectorAll('[data-field]'), function (input) {
    var wrap = input.closest('.tools-field');
    fields[input.getAttribute('data-field')] = { input: input, msg: wrap ? wrap.querySelector('[data-msg]') : null };
  });
  var outs = {};
  Array.prototype.forEach.call(root.querySelectorAll('[data-out]'), function (el) {
    outs[el.getAttribute('data-out')] = el;
  });

  /** Lee un campo: vacío = 0; texto no numérico, negativo o enorme = 0 y se marca (sin bloquear). */
  function read(key) {
    var f = fields[key];
    var raw = String(f.input.value).replace(/[\s$,]/g, '');
    if (raw === '') { return { v: 0, empty: true, bad: false }; }
    var x = Number(raw);
    if (!isFinite(x) || x < 0 || x > MAX) { return { v: 0, empty: false, bad: true }; }
    return { v: x, empty: false, bad: false };
  }

  function safe(x) { return isFinite(x) ? x : 0; }

  function money(x) {
    var r = Math.round(safe(x) * 100) / 100;
    return (r < 0 ? '−$' : '$') + nf.format(Math.abs(r));
  }

  function flag(key, bad, text) {
    var f = fields[key];
    if (bad) { f.input.setAttribute('aria-invalid', 'true'); } else { f.input.removeAttribute('aria-invalid'); }
    if (f.msg) {
      f.msg.textContent = text || '';
      f.msg.hidden = !text;
    }
  }

  function calc() {
    var r = {};
    Object.keys(fields).forEach(function (k) { r[k] = read(k); });

    var pay = r.pay.v;
    var loaded = r.loaded.v;
    var total = loaded + r.deadhead.v;
    var mpg = r.mpg.v;
    var fuel = mpg > 0 ? (total / mpg) * r.fuel.v : 0;
    var net = pay - fuel - r.extras.v;

    outs.rpmLoaded.textContent = money(loaded > 0 ? pay / loaded : 0);
    outs.rpmAll.textContent = money(total > 0 ? pay / total : 0);
    outs.fuelCost.textContent = money(fuel);
    outs.net.textContent = money(net);
    outs.netPerMile.textContent = money(total > 0 ? net / total : 0);

    Object.keys(fields).forEach(function (k) {
      flag(k, r[k].bad, r[k].bad ? INVALID : '');
    });
    if (!r.mpg.bad && mpg === 0 && total > 0) {
      flag('mpg', false, 'Add your MPG to include fuel cost.');
    }
  }

  root.addEventListener('input', calc);
  root.addEventListener('change', calc);
  var form = root.querySelector('form');
  if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); }); }
  calc();
})();
