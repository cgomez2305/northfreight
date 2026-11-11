/* NorthStar — calculadora IFTA. Vanilla, sin dependencias. Filas por estado con agregar/quitar; recalcula en cada `input`.
   MPG de flota = millas totales / galones totales; galones gravables = millas del estado / MPG;
   neto = gravables - comprados; impuesto = neto x tasa (solo si hay tasa). Las tasas no se precargan. */
(function () {
  'use strict';

  var t = function (s) { var i = window.NS_I18N; return (i && i[s]) || s; };
  var root = document.querySelector('[data-calc="ifta"]');
  if (!root) { return; }

  var body = root.querySelector('[data-rows]');
  var tpl = root.querySelector('[data-row-template]');
  var addBtn = root.querySelector('[data-add]');
  var status = root.querySelector('[data-status]');
  var outs = {};
  Array.prototype.forEach.call(root.querySelectorAll('[data-out]'), function (el) {
    outs[el.getAttribute('data-out')] = el;
  });
  if (!body || !tpl) { return; }

  var MAX = 1e9;
  var MINUS = '−';
  var nf0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
  var nf1 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  var nf2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var seq = body.querySelectorAll('tr[data-row]').length;

  /** Número >= 0. Vacío = {empty:true}; texto no numérico, negativo o enorme = {bad:true} (se marca, no bloquea). */
  function read(input) {
    var raw = String(input.value).replace(/[\s$,]/g, '');
    if (raw === '') { return { v: 0, empty: true, bad: false }; }
    var x = Number(raw);
    if (!isFinite(x) || x < 0 || x > MAX) { return { v: 0, empty: false, bad: true }; }
    return { v: x, empty: false, bad: false };
  }

  function mark(input, bad) {
    if (bad) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
  }

  /** Redondea primero y luego decide el signo, para no mostrar "-0.0". */
  function signed(x, nf, prefix) {
    var step = nf === nf1 ? 10 : 100;
    var r = Math.round(x * step) / step;
    return (r < 0 ? MINUS : '') + prefix + nf.format(Math.abs(r));
  }

  function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr[data-row]')); }
  function q(row, sel) { return row.querySelector(sel); }

  function calc() {
    var list = rows().map(function (row) {
      var mi = read(q(row, '[data-f="mi"]'));
      var gal = read(q(row, '[data-f="gal"]'));
      var rate = read(q(row, '[data-f="rate"]'));
      mark(q(row, '[data-f="mi"]'), mi.bad);
      mark(q(row, '[data-f="gal"]'), gal.bad);
      mark(q(row, '[data-f="rate"]'), rate.bad);
      return { row: row, mi: mi.v, gal: gal.v, rate: rate.v, hasRate: !rate.empty && !rate.bad };
    });

    var totalMiles = 0;
    var totalGal = 0;
    list.forEach(function (r) { totalMiles += r.mi; totalGal += r.gal; });
    var mpg = totalGal > 0 ? totalMiles / totalGal : 0;

    var due = 0;
    var anyRate = false;
    list.forEach(function (r) {
      var taxable = mpg > 0 ? r.mi / mpg : 0;
      var net = taxable - r.gal;
      var netCell = q(r.row, '[data-o="net"]');
      q(r.row, '[data-o="taxable"]').textContent = nf1.format(taxable);
      netCell.textContent = signed(net, nf1, '');
      netCell.classList.toggle('is-credit', Math.round(net * 10) / 10 < 0);
      if (r.hasRate) {
        due += net * r.rate;
        anyRate = true;
        q(r.row, '[data-o="due"]').textContent = signed(net * r.rate, nf2, '$');
      } else {
        q(r.row, '[data-o="due"]').textContent = '—';
      }
    });

    outs.totalMiles.textContent = nf0.format(totalMiles);
    outs.totalGal.textContent = nf0.format(totalGal);
    outs.mpg.textContent = mpg > 0 ? nf2.format(mpg) : '—';
    outs.totalDue.textContent = anyRate ? signed(due, nf2, '$') : t('Add rates');
  }

  function announce(text) {
    if (!status) { return; }
    status.textContent = '';
    window.setTimeout(function () { status.textContent = text; }, 30);
  }

  function addRow() {
    seq += 1;
    var html = tpl.innerHTML.split('{n}').join(String(seq));
    var holder = document.createElement('tbody');
    holder.innerHTML = html;
    var row = holder.querySelector('tr');
    if (!row) { return; }
    body.appendChild(row);
    calc();
    var first = q(row, '[data-f="st"]');
    if (first) { first.focus(); }
    announce(t('State row added. ') + rows().length + t(' rows.'));
  }

  function removeRow(row) {
    var all = rows();
    var idx = all.indexOf(row);
    body.removeChild(row);
    calc();
    var rest = rows();
    var next = rest[Math.min(idx, rest.length - 1)];
    var target = next ? q(next, '[data-f="st"]') : addBtn;
    if (target) { target.focus(); }
    announce(t('State row removed. ') + rest.length + (rest.length === 1 ? t(' row.') : t(' rows.')));
  }

  body.addEventListener('input', function (e) {
    var t = e.target;
    if (t && t.getAttribute && t.getAttribute('data-f') === 'st') {
      var clean = t.value.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 2);
      if (clean !== t.value) { t.value = clean; }
    }
    calc();
  });
  body.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-remove]') : null;
    if (btn) { removeRow(btn.closest('tr')); }
  });
  if (addBtn) { addBtn.addEventListener('click', addRow); }

  root.classList.add('is-ready');
  calc();
})();
