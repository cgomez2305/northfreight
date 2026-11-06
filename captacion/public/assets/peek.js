/* NorthStar Captación · vistazo rápido de un lead (Q5a-B). Panel lateral en escritorio y hoja inferior en móvil, sobre la bandeja y el tablero, sin salir de la lista.
   Un clic abre el vistazo (petición JSON con CSRF, sin datos en la URL); doble clic o Enter abren la ficha. J/K y flechas navegan; Esc cierra y devuelve el foco. Estado en la URL: ?peek=ID. */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS, pk = D.querySelector('#peek');
 if (!NS || !NS.api || !pk) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var cssV = pk.getAttribute('data-css-v'), cssP = null;
 function ready() { return cssP || (cssP = NS.css('peek', cssV)); }
 if (W.requestIdleCallback) W.requestIdleCallback(ready); else setTimeout(ready, 1200);
 var body = $('[data-pk-body]', pk), scrim = $('[data-pk-scrim]'), cur = 0, req = 0, sel = null, origin = null;
 var sheet = function () { return W.matchMedia('(max-width:1024px)').matches; };
 var idOf = function (el) { return parseInt(el.getAttribute('data-peek'), 10); };
 var find = function (id) { return $('[data-peek="' + id + '"]'); };
 var isOpen = function () { return !pk.hidden; };
 function items() { return $$('[data-peek]').filter(function (e) { return e.offsetParent !== null && !e.closest('[hidden]'); }); }
 function select(el) { if (sel) sel.classList.remove('is-cur'); sel = el; if (el) { el.classList.add('is-cur'); el.scrollIntoView({ block: 'nearest' }); } }
 function url(id) { var u = new URL(location.href); if (id) u.searchParams.set('peek', id); else u.searchParams.delete('peek'); try { history.replaceState(null, '', u.pathname + u.search + u.hash); } catch (e) {} }
 function skeleton() { var d = NS.el('div', 'pk__sk'); for (var i = 0; i < 5; i++) d.appendChild(NS.el('i')); d.setAttribute('aria-busy', 'true'); return d; }
 function fail(msg, id) {
  body.textContent = ''; var d = NS.el('div', 'pk__err'); d.appendChild(NS.el('p', null, msg || 'No se pudo cargar el vistazo.'));
  var b = NS.el('button', 'btn btn--secondary btn--sm', 'Reintentar'); b.type = 'button'; b.addEventListener('click', function () { load(id, true); }); d.appendChild(b); body.appendChild(d);
 }
 function render(j, keep) {
  var t = D.createElement('template'); t.innerHTML = j.html; var top = body.scrollTop; body.textContent = ''; body.appendChild(t.content); cur = j.id;
  NS.ctx = { leadId: j.id, stage: j.stage, name: j.name, stages: j.stages };
  var row = find(j.id), cell = row && $('.k-stage', row); if (cell) cell.innerHTML = j.stage_chip;
  if (keep) body.scrollTop = top; else { var h = $('#pk-title', pk); if (h) h.focus({ preventScroll: true }); }
 }
 function load(id, keep) {
  var my = ++req; if (!keep) { body.textContent = ''; body.appendChild(skeleton()); }
  return NS.api('/ajax/peek', { id: id }).then(function (j) {
   if (my !== req) return;
   if (!j.ok) { fail(j.status === 404 ? 'El lead ya no existe.' : j.msg, id); return; }
   render(j, keep);
  });
 }
 function open(id, el) {
  if (!cssP || !isOpen()) { var args = arguments; return ready().then(function () { return open2(id, el); }); }
  return open2(id, el);
 }
 function open2(id, el) {
  if (!isOpen()) { origin = el || D.activeElement; pk.hidden = false; void pk.offsetWidth; pk.classList.add('is-open'); if (sheet()) { scrim.hidden = false; void scrim.offsetWidth; scrim.classList.add('is-open'); D.body.style.overflow = 'hidden'; } }
  pk.setAttribute('aria-modal', sheet() ? 'true' : 'false');
  var row = el || find(id); if (row) select(row);
  $$('[data-peek].is-open').forEach(function (r) { r.classList.remove('is-open'); }); if (row) row.classList.add('is-open');
  url(id); return load(id);
 }
 function close(back) {
  if (!isOpen()) return; req++; pk.classList.remove('is-open'); scrim.classList.remove('is-open'); D.body.style.overflow = '';
  setTimeout(function () { if (!pk.classList.contains('is-open')) { pk.hidden = true; scrim.hidden = true; } }, 230);
  $$('[data-peek].is-open').forEach(function (r) { r.classList.remove('is-open'); }); url(0); NS.ctx = NS.pageCtx; cur = 0;
  if (back !== false) { var f = (sel && (sel.matches('[data-peek-link]') ? sel : $('a.who, a.kc__n, [data-peek-link]', sel))) || origin; if (f && f.focus) f.focus({ preventScroll: true }); }
 }
 NS.peek = { open: open, close: close };
 function step(d) {
  var list = items(); if (!list.length) return; var i = list.indexOf(sel); i = i < 0 ? (d > 0 ? 0 : list.length - 1) : Math.max(0, Math.min(list.length - 1, i + d));
  select(list[i]); if (isOpen()) open(idOf(list[i]), list[i]);
 }
 $('[data-pk-close]', pk).addEventListener('click', function () { close(); });
 scrim.addEventListener('click', function () { close(); });
 $('[data-pk-prev]', pk).addEventListener('click', function () { step(-1); });
 $('[data-pk-next]', pk).addEventListener('click', function () { step(1); });

 /* ---- Clic en una fila o tarjeta ---- */
 var interactive = 'input,select,textarea,label,button,[data-move],.dd,.icon-btn,summary';
 D.addEventListener('click', function (e) {
  var t = e.target; if (!t.closest || t.closest('#peek') || t.closest('dialog')) return;
  var row = t.closest('[data-peek]'); if (!row || t.closest(interactive)) return;
  var a = t.closest('a'); if (a && !(a.classList.contains('who') || a.classList.contains('kc__n') || a.hasAttribute('data-peek-link'))) return;
  if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button) return;
  if (a && e.detail === 0) return;   // activación por teclado de un enlace: va a la ficha
  e.preventDefault(); open(idOf(row), row);
 });
 D.addEventListener('dblclick', function (e) { var t = e.target; if (!t.closest || t.closest('#peek') || t.closest(interactive)) return; var row = t.closest('[data-peek]'); if (row) location.href = '/leads/' + idOf(row); });

 /* ---- Teclado ---- */
 D.addEventListener('keydown', function (e) {
  var t = e.target, typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
  if (e.key === 'Tab' && isOpen() && sheet()) {
   var f = $$('a[href],button:not([disabled]),input,select,textarea,summary,[tabindex="-1"]#pk-title', pk).filter(function (x) { return x.offsetParent !== null; });
   if (f.length) { var a = f[0], z = f[f.length - 1]; if (e.shiftKey && (D.activeElement === a || D.activeElement === pk)) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && D.activeElement === z) { e.preventDefault(); a.focus(); } }
   return;
  }
  if (e.ctrlKey || e.metaKey || e.altKey || D.querySelector('dialog[open]')) return;
  if (e.key === 'Escape') { if (isOpen() && !(typing && t.tagName === 'SELECT')) { e.preventDefault(); close(); } return; }
  if (typing) return;
  var k = e.key, inBar = t && t.closest && (t.closest('.peek__bar') || t.closest('[data-peek]')), onBody = t === D.body || t === D.documentElement || t === pk || (t && t.id === 'main');
  if (k === 'j' || (k === 'ArrowDown' && isOpen() && inBar)) { e.preventDefault(); step(1); }
  else if (k === 'k' || (k === 'ArrowUp' && isOpen() && inBar)) { e.preventDefault(); step(-1); }
  else if (k === 'Enter' && sel && onBody) { e.preventDefault(); location.href = '/leads/' + idOf(sel); }
  else if (k === ' ' && sel && onBody) { e.preventDefault(); open(idOf(sel), sel); }
 });

 /* ---- Acciones dentro del vistazo (mismas funciones del servidor que la ficha) ---- */
 function reload() { if (cur) load(cur, true); }
 body.addEventListener('click', function (e) {
  var t = e.target.closest ? e.target : null; if (!t) return;
  var mv = t.closest('[data-pk-move]');
  if (mv) { var s = $('[data-pk-stage]', body), opt = s.options[s.selectedIndex]; if (s.value) NS.move(cur, s.value, { name: $('#pk-title', body).textContent, stageLabel: opt ? opt.textContent : '' }); return; }
  var dn = t.closest('[data-pk-done]'); if (dn) { dn.disabled = true; NS.act(cur, 'task_done', { task_id: parseInt(dn.getAttribute('data-pk-done'), 10) }).then(reload); return; }
  var sv = t.closest('[data-pk-save]'); if (!sv) return;
  var kind = sv.getAttribute('data-pk-save'), fields, field;
  if (kind === 'note') { field = $('#pk-note', body); fields = { body: field.value }; if (!field.value.trim()) { field.focus(); return; } }
  else if (kind === 'task') { field = $('#pk-task-t', body); fields = { title: field.value, due: $('#pk-task-d', body).value }; if (!field.value.trim()) { field.focus(); return; } }
  else { field = $('#pk-call-o', body); fields = { outcome: field.value, minutes: $('#pk-call-m', body).value, note: $('#pk-call-n', body).value }; }
  sv.disabled = true; NS.act(cur, kind, fields).then(function (j) { sv.disabled = false; if (j.ok) reload(); });
 });
 D.addEventListener('ns:lead-action', function (e) {
  if (!isOpen() || !cur || e.detail.leadId !== cur) return;
  var kind = e.detail.act; if (kind === 'responded') return;
  var f = $('[data-pk-form="' + kind + '"]', body); if (!f) return;
  e.preventDefault(); f.open = true; var i = $('textarea,input:not([type=hidden]),select', f); if (i) { f.scrollIntoView({ block: 'center' }); i.focus(); }
 });
 D.addEventListener('ns:moved', function (e) { if (cur && e.detail.id === cur) reload(); });
 D.addEventListener('ns:undone', function () { reload(); });
 var first = parseInt(pk.getAttribute('data-peek-id'), 10); if (first > 0) { var r0 = find(first); open(first, r0 || null); }
})();
