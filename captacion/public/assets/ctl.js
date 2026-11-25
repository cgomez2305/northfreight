/* NorthStar Captación · controles con estilo propio (fase AR): pide ctl.css (diferida) y cambia los campos de fecha nativos por un calendario propio en español.
   El campo nativo se queda en el formulario (invisible): conserva name, value, required, min/max y los eventos; el botón y el panel solo lo escriben.
   Sin JS, o en navegadores sin Popover API, queda el campo nativo. Teclado: flechas por días, RePág/AvPág por meses, Inicio/Fin de la semana, Intro elige, Esc cierra. */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS = W.NS || {};
 var me = D.currentScript;
 (function () { var l = D.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/ctl.css' + (me && me.getAttribute('data-css-v') ? '?v=' + me.getAttribute('data-css-v') : ''); D.head.appendChild(l); })();
 if (!HTMLElement.prototype.showPopover) return;

 var MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
 var MON = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
 var WD = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'], WI = ['L', 'M', 'X', 'J', 'V', 'S', 'D'], WDL = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
 var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
 var clockEl = D.querySelector('[data-clock][data-tz]');
 var TZ = clockEl ? clockEl.getAttribute('data-tz') : null;

 function pad(n) { return (n < 10 ? '0' : '') + n; }
 function ymd(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
 function parse(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null; }
 function today() {   // el día de hoy en la zona de visualización del panel (la misma del reloj de la barra superior)
  try { if (TZ) return parse(new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())); } catch (e) {}
  var n = new Date(); return new Date(Date.UTC(n.getFullYear(), n.getMonth(), n.getDate()));
 }
 function wd(d) { return (d.getUTCDay() + 6) % 7; }   // 0 = lunes
 function addDays(d, n) { var x = new Date(d.getTime()); x.setUTCDate(x.getUTCDate() + n); return x; }
 function addMonths(d, n) { var x = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1)), last = new Date(Date.UTC(x.getUTCFullYear(), x.getUTCMonth() + 1, 0)).getUTCDate(); x.setUTCDate(Math.min(d.getUTCDate(), last)); return x; }
 function label(d) { return WD[wd(d)] + ' ' + d.getUTCDate() + ' ' + MON[d.getUTCMonth()] + (d.getUTCFullYear() !== today().getUTCFullYear() ? ' ' + d.getUTCFullYear() : ''); }
 function longLabel(d) { return WDL[wd(d)] + ' ' + d.getUTCDate() + ' de ' + MONTHS[d.getUTCMonth()] + ' de ' + d.getUTCFullYear(); }
 function el(tag, cls, txt) { var e = D.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
 function icon(name) { var s = D.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', 'ic'); s.setAttribute('aria-hidden', 'true'); var u = D.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + name); s.appendChild(u); return s; }
 function fieldName(inp) {
  if (inp.getAttribute('aria-label')) return inp.getAttribute('aria-label');
  var l = inp.labels && inp.labels[0]; if (!l) return 'Fecha';
  var sp = l.querySelector('span'); return ((sp && !sp.contains(inp) ? sp.textContent : l.textContent) || 'Fecha').trim();
 }

 var open = null;   // { inp, btn, pk, view, sel, time }

 function enhance(inp) {
  if (inp.dataset.dp || inp.hasAttribute('data-native') || inp.readOnly) return;
  inp.dataset.dp = '1';
  var dt = inp.type === 'datetime-local';
  var w = el('span', 'dp-w'), btn = el('button', 'dp'), v = el('span', 'dp__v');
  btn.type = 'button'; btn.setAttribute('aria-haspopup', 'dialog'); btn.setAttribute('aria-expanded', 'false');
  btn.appendChild(icon('calendar')); btn.appendChild(v);
  inp.parentNode.insertBefore(w, inp); w.appendChild(inp); w.appendChild(btn);
  inp.classList.add('dp-n'); inp.tabIndex = -1; inp.setAttribute('aria-hidden', 'true');
  var name = fieldName(inp);
  function render() {
   var val = proto.get.call(inp), d = parse(val), t = dt && val.length >= 16 ? val.slice(11, 16) : '';
   v.textContent = d ? label(d) + (t ? ' · ' + t : '') : (dt ? 'Elegir fecha y hora' : 'Elegir fecha');
   btn.classList.toggle('is-empty', !d);
   btn.setAttribute('aria-label', name + ': ' + (d ? longLabel(d) + (t ? ', ' + t : '') : 'sin elegir'));
   btn.disabled = inp.disabled;
  }
  // otros scripts escriben inp.value (atajos «Mañana», editar una tarea…): el botón se entera
  Object.defineProperty(inp, 'value', { configurable: true, get: function () { return proto.get.call(inp); }, set: function (x) { proto.set.call(inp, x); render(); clearErr(); } });
  inp.focus = function () { btn.focus(); };
  inp.addEventListener('focus', function () { btn.focus(); });
  inp.addEventListener('change', render);
  function clearErr() { btn.removeAttribute('aria-invalid'); var e = w.querySelector('.dp-err'); if (e) e.remove(); }
  inp.addEventListener('invalid', function (e) {
   e.preventDefault(); btn.setAttribute('aria-invalid', 'true');
   if (!w.querySelector('.dp-err')) { var m = el('span', 'dp-err', inp.validationMessage || 'Elige una fecha.'); m.id = 'dperr-' + Math.random().toString(36).slice(2, 8); m.setAttribute('role', 'alert'); w.appendChild(m); btn.setAttribute('aria-describedby', m.id); }
   btn.focus();
  });
  btn.addEventListener('click', function () { if (open && open.inp === inp) close(true); else show(inp, btn, dt); });
  btn.addEventListener('keydown', function (e) { if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !(open && open.inp === inp)) { e.preventDefault(); show(inp, btn, dt); } });
  render();
  inp._dpRender = render;
 }

 function set(o, val, fire) {
  proto.set.call(o.inp, val); o.inp._dpRender(); o.btn.removeAttribute('aria-invalid'); var er = o.btn.parentNode.querySelector('.dp-err'); if (er) er.remove();
  if (fire) { o.inp.dispatchEvent(new Event('input', { bubbles: true })); o.inp.dispatchEvent(new Event('change', { bubbles: true })); }
 }

 function show(inp, btn, dt) {
  close(false);
  var val = proto.get.call(inp), sel = parse(val), t0 = today();
  var pk = el('div', 'dpk'); pk.setAttribute('popover', 'manual'); pk.setAttribute('role', 'dialog'); pk.setAttribute('aria-label', fieldName(inp));
  var o = open = { inp: inp, btn: btn, pk: pk, view: sel || t0, sel: sel, dt: dt, time: dt && val.length >= 16 ? val.slice(11, 16) : '09:00', min: parse(inp.min), max: parse(inp.max) };
  var head = el('div', 'dpk__h'), title = el('span', 'dpk__t'), nav = el('div', 'dpk__nav');
  title.setAttribute('aria-live', 'polite');
  var prev = el('button', 'dpk__nb'), next = el('button', 'dpk__nb');
  prev.type = next.type = 'button'; prev.setAttribute('aria-label', 'Mes anterior'); next.setAttribute('aria-label', 'Mes siguiente');
  prev.appendChild(icon('chevron-left')); next.appendChild(icon('chevron-right'));
  nav.appendChild(prev); nav.appendChild(next); head.appendChild(title); head.appendChild(nav); pk.appendChild(head);
  var tbl = el('table'); tbl.setAttribute('role', 'grid'); var thead = el('thead'), hr = el('tr');
  WDL.forEach(function (n, i) { var th = el('th', null, WI[i]); th.setAttribute('scope', 'col'); th.setAttribute('abbr', n); hr.appendChild(th); });
  thead.appendChild(hr); tbl.appendChild(thead); var tb = el('tbody'); tbl.appendChild(tb); pk.appendChild(tbl);
  var timeSel = null;
  if (dt) {
   var tr = el('label', 'dpk__time'); tr.appendChild(el('span', null, 'Hora'));
   timeSel = el('select'); var have = false;
   for (var m = 0; m < 24 * 60; m += 15) { var hh = pad(Math.floor(m / 60)) + ':' + pad(m % 60), op = el('option', null, hh); op.value = hh; if (hh === o.time) { op.selected = true; have = true; } timeSel.appendChild(op); }
   if (!have) { var ox = el('option', null, o.time); ox.value = o.time; ox.selected = true; timeSel.insertBefore(ox, timeSel.firstChild); }
   timeSel.addEventListener('change', function () { o.time = timeSel.value; if (o.sel) set(o, ymd(o.sel) + 'T' + o.time, true); });
   tr.appendChild(timeSel); pk.appendChild(tr);
  }
  var foot = el('div', 'dpk__f'), bToday = el('button', 'btn btn--ghost btn--sm', 'Hoy'), right = el('div', 'dpk__nav');
  bToday.type = 'button'; foot.appendChild(bToday);
  if (!inp.required) { var bClear = el('button', 'btn btn--ghost btn--sm', 'Borrar'); bClear.type = 'button'; right.appendChild(bClear); bClear.addEventListener('click', function () { set(o, '', true); close(true); }); }
  if (dt) { var bOk = el('button', 'btn btn--secondary btn--sm', 'Listo'); bOk.type = 'button'; right.appendChild(bOk); bOk.addEventListener('click', function () { close(true); }); }
  foot.appendChild(right); pk.appendChild(foot);

  function ok(d) { return !(o.min && d < o.min) && !(o.max && d > o.max); }
  function pick(d) {
   if (!ok(d)) return;
   o.sel = d; set(o, dt ? ymd(d) + 'T' + o.time : ymd(d), true);
   if (dt) { draw(d); } else close(true);
  }
  function draw(focusDay) {
   var v = o.view, first = new Date(Date.UTC(v.getUTCFullYear(), v.getUTCMonth(), 1)), start = addDays(first, -wd(first));
   var mt = MONTHS[v.getUTCMonth()]; title.textContent = mt.charAt(0).toUpperCase() + mt.slice(1) + ' ' + v.getUTCFullYear();
   tb.textContent = '';
   var fday = focusDay || o.sel || t0; if (fday.getUTCMonth() !== v.getUTCMonth() || fday.getUTCFullYear() !== v.getUTCFullYear()) fday = first;
   for (var r = 0; r < 6; r++) {
    var row = el('tr');
    for (var c = 0; c < 7; c++) {
     var d = addDays(start, r * 7 + c), td = el('td'), b = el('button', 'dpk__d', String(d.getUTCDate()));
     b.type = 'button'; b.setAttribute('data-d', ymd(d)); b.setAttribute('aria-label', longLabel(d));
     if (d.getUTCMonth() !== v.getUTCMonth()) b.classList.add('is-out');
     if (ymd(d) === ymd(t0)) { b.classList.add('is-today'); b.setAttribute('aria-current', 'date'); }
     b.setAttribute('aria-pressed', o.sel && ymd(d) === ymd(o.sel) ? 'true' : 'false');
     if (!ok(d)) b.disabled = true;
     b.tabIndex = ymd(d) === ymd(fday) ? 0 : -1;
     td.appendChild(b); row.appendChild(td);
    }
    tb.appendChild(row);
    if (r >= 4 && addDays(start, (r + 1) * 7).getUTCMonth() !== v.getUTCMonth()) break;
   }
   if (focusDay) { var f = tb.querySelector('[data-d="' + ymd(fday) + '"]'); if (f) f.focus(); }
  }
  function go(d) { o.view = d; draw(d); }
  prev.addEventListener('click', function () { o.view = addMonths(o.view, -1); draw(); });
  next.addEventListener('click', function () { o.view = addMonths(o.view, 1); draw(); });
  bToday.addEventListener('click', function () { o.view = t0; if (ok(t0)) pick(t0); else draw(t0); });
  tb.addEventListener('click', function (e) { var b = e.target.closest('.dpk__d'); if (b && !b.disabled) pick(parse(b.getAttribute('data-d'))); });
  tb.addEventListener('keydown', function (e) {
   var b = e.target.closest('.dpk__d'); if (!b) return;
   var d = parse(b.getAttribute('data-d')), n = null;
   switch (e.key) {
    case 'ArrowLeft': n = addDays(d, -1); break; case 'ArrowRight': n = addDays(d, 1); break;
    case 'ArrowUp': n = addDays(d, -7); break; case 'ArrowDown': n = addDays(d, 7); break;
    case 'Home': n = addDays(d, -wd(d)); break; case 'End': n = addDays(d, 6 - wd(d)); break;
    case 'PageUp': n = addMonths(d, e.shiftKey ? -12 : -1); break; case 'PageDown': n = addMonths(d, e.shiftKey ? 12 : 1); break;
    default: return;
   }
   e.preventDefault(); go(n);
  });
  pk.addEventListener('keydown', function (e) {
   if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
   else if (e.key === 'Tab') {   // el foco no sale del panel mientras está abierto
    var f = Array.prototype.filter.call(pk.querySelectorAll('button,select'), function (x) { return !x.disabled && x.tabIndex !== -1 && x.offsetParent !== null; });
    if (!f.length) return; var i = f.indexOf(D.activeElement);
    if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
   }
  });
  // el panel vive en la capa superior (también encima de los diálogos), junto al botón
  (btn.closest('dialog[open]') || D.body).appendChild(pk);
  pk.showPopover();
  draw(sel || t0);
  place();
  btn.setAttribute('aria-expanded', 'true');
 }

 function place() {
  if (!open) return;
  var r = open.btn.getBoundingClientRect(), pk = open.pk, h = pk.offsetHeight, w = pk.offsetWidth, vw = D.documentElement.clientWidth, vh = W.innerHeight;
  var top = r.bottom + 6;
  if (top + h > vh - 8) top = r.top - h - 6 > 8 ? r.top - h - 6 : vh - h - 8;   // abajo, si no arriba, y si tampoco cabe, pegado al borde inferior
  var left = Math.min(Math.max(8, r.left), vw - w - 8);
  pk.style.top = Math.max(8, top) + 'px'; pk.style.left = left + 'px';
 }

 function close(focusBtn) {
  if (!open) return;
  var o = open; open = null;
  try { o.pk.hidePopover(); } catch (e) {}
  o.pk.remove(); o.btn.setAttribute('aria-expanded', 'false');
  if (focusBtn) o.btn.focus();
 }

 D.addEventListener('pointerdown', function (e) { if (open && !open.pk.contains(e.target) && !open.btn.contains(e.target)) close(false); }, true);
 W.addEventListener('resize', place);
 W.addEventListener('scroll', function (e) { if (open && !open.pk.contains(e.target)) place(); }, true);

 function scan(root) { Array.prototype.forEach.call((root || D).querySelectorAll('input[type=date],input[type=datetime-local]'), enhance); }
 NS.dp = scan;   // contenido que llega después (vistazo rápido, diálogos): NS.dp(contenedor)
 scan(D);
 if (W.MutationObserver) new MutationObserver(function (ms) { ms.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && !n.classList.contains('dpk')) { if (n.matches && n.matches('input[type=date],input[type=datetime-local]')) enhance(n); else if (n.querySelectorAll) scan(n); } }); }); }).observe(D.body, { childList: true, subtree: true });
})();
