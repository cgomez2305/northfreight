/* NorthStar Captación · kanban (/pipeline). Sin librerías. Arrastrar y soltar con pointer events (ratón y lápiz) + menú «Mover a…» (teclado y táctil).
   Estilos solo por CSSOM (el CSP prohíbe atributos style). El servidor valida todo: la interfaz es optimista y revierte si el servidor rechaza. */
(function () {
 'use strict';
 var D = document, board = D.querySelector('[data-board]');
 if (!board) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var CSRF = (D.querySelector('script[data-csrf]') || {}).getAttribute ? D.querySelector('script[data-csrf]').getAttribute('data-csrf') : '';
 var PL = board.getAttribute('data-pl'), QS = board.getAttribute('data-qs') || '', WRITE = board.getAttribute('data-write') === '1';
 var STAGES = {}, CLOSED = [];
 try { STAGES = JSON.parse(board.getAttribute('data-stages')); CLOSED = JSON.parse(board.getAttribute('data-closed')); } catch (e) {}
 var live = $('[data-live]', board), scroller = $('[data-scroll]', board), menu = $('[data-menu]'), dlg = $('#kb-dlg');
 var cols = {};
 $$('.kcol', board).forEach(function (el) {
  var st = el.getAttribute('data-col'), list = $('[data-list]', el);
  cols[st] = { el: el, list: list, count: $('[data-count]', el), more: $('[data-more]', el), empty: $('[data-empty]', el), rest: $('[data-rest]', el), chip: $('[data-chip="' + st + '"] [data-chip-n]'),
   offset: $$('.kcard', list).length, loaded: $$('.kcard', list).length > 0 || el.getAttribute('data-total') === '0' };
 });
 function total(st) { return parseInt(cols[st].el.getAttribute('data-total'), 10) || 0; }
 function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 40); }
 var toastEl = null, toastT = 0;
 function toast(msg, bad) {
  if (toastEl) toastEl.remove(); clearTimeout(toastT);
  toastEl = D.createElement('div'); toastEl.className = 'ktoast' + (bad ? ' ktoast--bad' : ''); toastEl.setAttribute('role', bad ? 'alert' : 'status');
  var t = D.createElement('span'); t.textContent = msg; var b = D.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Cerrar aviso'); b.textContent = '×';
  b.addEventListener('click', function () { if (toastEl) toastEl.remove(); toastEl = null; }); toastEl.appendChild(t); toastEl.appendChild(b); D.body.appendChild(toastEl);
  toastT = setTimeout(function () { if (toastEl) toastEl.remove(); toastEl = null; }, bad ? 9000 : 4500);
 }
 function api(path, body) {
  return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) })
   .then(function (r) { return r.json().then(function (j) { j.status = r.status; return j; }, function () { return { ok: false, status: r.status, msg: 'Respuesta no válida del servidor.' }; }); });
 }
 function frag(html) { var t = D.createElement('template'); t.innerHTML = html; return t.content; }
 function stageOf(card) { return card.getAttribute('data-stage'); }

 /* ---- Conteos y estados de columna ---- */
 function refresh(st) {
  var c = cols[st], n = total(st), shown = $$('.kcard', c.list).length;
  c.count.textContent = n; if (c.chip) c.chip.textContent = n;
  c.empty.hidden = n > 0 || shown > 0;
  var rest = Math.max(0, n - shown);
  c.more.hidden = !(rest > 0 && c.loaded);
  if (c.rest) c.rest.textContent = '(' + rest + ' restantes)';
 }
 function setTotals(counts) { Object.keys(counts).forEach(function (st) { if (cols[st]) { cols[st].el.setAttribute('data-total', counts[st]); refresh(st); } }); }
 function bump(st, d) { cols[st].el.setAttribute('data-total', Math.max(0, total(st) + d)); }

 /* ---- Colocar una tarjeta en una columna (ordenada por puntuación, como el servidor) ---- */
 function place(card, st) {
  var c = cols[st], score = parseInt(card.getAttribute('data-score'), 10) || 0, before = null;
  if (st === 'lost') { before = c.list.firstChild; } else {
   $$('.kcard', c.list).some(function (o) { if ((parseInt(o.getAttribute('data-score'), 10) || 0) < score) { before = o; return true; } return false; });
  }
  c.list.insertBefore(card, before);
  card.setAttribute('data-stage', st);
 }
 function snapshot(card) {
  var s = { parent: card.parentNode, next: card.nextSibling, stage: stageOf(card), totals: {}, offsets: {} };
  Object.keys(cols).forEach(function (st) { s.totals[st] = total(st); s.offsets[st] = cols[st].offset; });
  return s;
 }
 function restore(card, s) {
  s.parent.insertBefore(card, s.next); card.setAttribute('data-stage', s.stage); card.classList.remove('is-pending'); card.removeAttribute('aria-busy');
  Object.keys(s.totals).forEach(function (st) { cols[st].el.setAttribute('data-total', s.totals[st]); cols[st].offset = s.offsets[st]; refresh(st); });
 }

 /* ---- Mover (optimista) ---- */
 var ctx = null;   // diálogo pendiente
 function move(card, to, extra) {
  extra = extra || {};
  var from = stageOf(card), id = card.getAttribute('data-id'), name = card.getAttribute('data-name');
  if (!WRITE || to === from) return;
  if (to === 'lost' && !extra.reason) return ask(card, to, 'reason', 'Elige un motivo para marcar «' + STAGES.lost + '».');
  if (CLOSED.indexOf(from) >= 0 && !extra.override) return ask(card, to, 'override', 'Sacar un lead de «' + STAGES[from] + '» necesita confirmar que quieres saltarte el orden.');
  var snap = snapshot(card);
  bump(from, -1); bump(to, 1); cols[from].offset = Math.max(0, cols[from].offset - 1);
  if (cols[to].loaded) place(card, to); else { card.remove(); }
  card.classList.add('is-pending'); card.setAttribute('aria-busy', 'true');
  refresh(from); refresh(to);
  api('/ajax/pipeline/move', { lead_id: parseInt(id, 10), to: to, qs: QS, reason: extra.reason || '', note: extra.note || '', override: !!extra.override }).then(function (j) {
   if (j.ok) {
    var fresh = frag(j.card).firstElementChild;
    if (fresh && cols[to].loaded) { card.replaceWith(fresh); fresh.classList.add('is-just-moved'); setTimeout(function () { fresh.classList.remove('is-just-moved'); }, 1800); var b = $('[data-move]', fresh); if (b && D.activeElement === D.body) b.focus(); } else { card.remove(); }
    if (j.counts) setTotals(j.counts);
    toast(name + ': ' + j.msg); say(name + ' movido a ' + (STAGES[to] || to) + '.');
    return;
   }
   restore(card, snap);
   if (j.status === 409 && j.needs) { ask(card, to, j.needs, j.msg, extra); return; }
   toast(j.msg || (j.status === 401 ? 'La sesión caducó. Recarga la página.' : 'No se pudo mover el lead.'), true); say('No se pudo mover a ' + name + '. ' + (j.msg || ''));
  }, function () { restore(card, snap); toast('Sin conexión con el servidor. El lead no se movió.', true); });
 }

 /* ---- Diálogo de datos que pide el servidor ---- */
 function ask(card, to, needs, msg, prev) {
  if (!dlg || !dlg.showModal) { toast(msg, true); return; }
  prev = prev || {}; ctx = { card: card, to: to };
  var f = $('[data-kb-form]', dlg);
  $('.btn__t', dlg).textContent = to === 'lost' ? 'Marcar como perdido' : 'Mover';
  $('#kb-dlg-t').textContent = to === 'lost' ? 'Marcar como «' + STAGES.lost + '»' : 'Mover a «' + (STAGES[to] || to) + '»';
  $('[data-kb-msg]', dlg).textContent = card.getAttribute('data-name') + ': ' + msg;
  var needReason = needs === 'reason' || needs === 'note' || to === 'lost', needOverride = needs === 'override' || !!prev.override;
  $('[data-kb-reason]', dlg).hidden = !needReason; f.elements.reason.required = needReason; f.elements.reason.value = prev.reason || '';
  $('[data-kb-note]', dlg).hidden = !(needReason || needs === 'note'); f.elements.note.value = prev.note || '';
  $('[data-kb-note-hint]', dlg).textContent = needs === 'note' ? '(obligatoria con «Otro»)' : '(opcional)';
  $('[data-kb-override]', dlg).hidden = !needOverride; f.elements.override.required = needOverride; f.elements.override.checked = false;
  dlg.showModal();
  var first = needReason ? f.elements.reason : needOverride ? f.elements.override : f.elements.note; if (first) first.focus();
 }
 if (dlg) {
  var kf = $('[data-kb-form]', dlg);
  kf.addEventListener('submit', function (e) {
   e.preventDefault(); if (!ctx) { dlg.close(); return; }
   var c = ctx, o = { reason: kf.elements.reason.value, note: kf.elements.note.value.trim(), override: kf.elements.override.checked };
   if (o.reason === 'other' && o.note.length < 3) { kf.elements.note.focus(); $('[data-kb-msg]', dlg).textContent = 'Con el motivo «Otro» escribe una nota que lo explique.'; return; }
   ctx = null; dlg.close(); move(c.card, c.to, o);
  });
  dlg.addEventListener('close', function () { ctx = null; });
 }

 /* ---- Menú «Mover a…» ---- */
 var menuFor = null, menuBtn = null;
 function closeMenu(focus) {
  if (!menu || menu.hidden) return; menu.hidden = true; D.body.classList.remove('kmenu-open');
  if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); if (focus) menuBtn.focus(); } menuFor = menuBtn = null;
 }
 function openMenu(btn) {
  var card = btn.closest('.kcard'), cur = stageOf(card);
  closeMenu(); menuFor = card; menuBtn = btn; menu.textContent = '';
  var h = D.createElement('div'); h.className = 'kmenu__t'; h.textContent = 'Mover a'; menu.appendChild(h);
  Object.keys(STAGES).forEach(function (st) {
   var b = D.createElement('button'); b.type = 'button'; b.setAttribute('role', 'menuitem'); b.setAttribute('data-to', st);
   var d = D.createElement('i'); d.className = 'dot st-' + st; var w = D.createElement('span'); w.className = 'st-' + st; w.appendChild(d);
   b.appendChild(w); b.appendChild(D.createTextNode(STAGES[st] + (st === cur ? ' (actual)' : ''))); if (st === cur) b.setAttribute('aria-disabled', 'true'); menu.appendChild(b);
  });
  menu.setAttribute('aria-label', 'Mover a… ' + card.getAttribute('data-name'));
  menu.hidden = false; D.body.classList.add('kmenu-open'); btn.setAttribute('aria-expanded', 'true');
  var r = btn.getBoundingClientRect(), mh = menu.offsetHeight, mw = menu.offsetWidth;
  var left = Math.max(8, Math.min(r.left, window.innerWidth - mw - 8)) + window.pageXOffset;
  var top = (r.bottom + mh + 12 > window.innerHeight && r.top - mh - 8 > 0) ? r.top - mh - 6 : r.bottom + 6;
  menu.style.left = left + 'px'; menu.style.top = (top + window.pageYOffset) + 'px';
  var first = $('button:not([aria-disabled="true"])', menu); if (first) first.focus();
 }
 if (menu) {
  menu.addEventListener('click', function (e) {
   var b = e.target.closest('button[data-to]'); if (!b || b.getAttribute('aria-disabled') === 'true') return;
   var card = menuFor, to = b.getAttribute('data-to'); closeMenu(false); if (card) move(card, to);
  });
  menu.addEventListener('keydown', function (e) {
   var items = $$('button:not([aria-disabled="true"])', menu), i = items.indexOf(D.activeElement);
   if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
   else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); } else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
   else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); } else if (e.key === 'Tab') { closeMenu(false); }
  });
  D.addEventListener('click', function (e) { if (!menu.hidden && !menu.contains(e.target) && !e.target.closest('[data-move]')) closeMenu(false); });
  window.addEventListener('resize', function () { closeMenu(false); });
 }
 board.addEventListener('click', function (e) {
  var mv = e.target.closest('[data-move]');
  if (mv) { e.preventDefault(); if (menuBtn === mv) closeMenu(true); else openMenu(mv); return; }
  var more = e.target.closest('[data-more]'); if (more) { loadMore(more.closest('.kcol').getAttribute('data-col'), more); return; }
  var tg = e.target.closest('[data-tog]'); if (tg) toggleLost(tg.closest('.kcol'), tg);
 });

 /* ---- Ver más y columna Perdido ---- */
 function loadMore(st, btn) {
  var c = cols[st]; if (c.busy) return; c.busy = true; if (btn) btn.setAttribute('aria-busy', 'true');
  api('/ajax/pipeline/cards', { pl: PL, stage: st, offset: c.offset, qs: QS }).then(function (j) {
   c.busy = false; if (btn) btn.removeAttribute('aria-busy');
   if (!j.ok) { toast(j.error === 'rate_limited' ? 'Demasiadas cargas seguidas. Espera un momento.' : 'No se pudieron cargar más tarjetas.', true); return; }
   var f = frag(j.html), have = {}; $$('.kcard', c.list).forEach(function (k) { have[k.getAttribute('data-id')] = 1; });
   var added = 0; $$('.kcard', f).forEach(function (k) { if (!have[k.getAttribute('data-id')]) { c.list.appendChild(k); added++; } });
   c.offset += j.shown; c.loaded = true; c.el.setAttribute('data-total', j.total); refresh(st);
   if (j.next === null) c.more.hidden = true;
   if (added) { say(added + ' tarjetas más en ' + STAGES[st] + '.'); var nk = $$('.kcard', c.list)[c.list.children.length - added]; var l = nk && $('a', nk); if (l) l.focus({ preventScroll: false }); }
  }, function () { c.busy = false; if (btn) btn.removeAttribute('aria-busy'); toast('Sin conexión con el servidor.', true); });
 }
 function toggleLost(col, btn) {
  var st = col.getAttribute('data-col'), c = cols[st], open = btn.getAttribute('aria-expanded') !== 'true';
  btn.setAttribute('aria-expanded', open ? 'true' : 'false'); col.classList.toggle('is-collapsed', !open); $('.kcol__b', col).hidden = !open;
  if (open && !c.loaded) { c.loaded = true; refresh(st); if (total(st) > 0) loadMore(st, null); }
 }

 /* ---- Arrastrar y soltar (ratón y lápiz; en táctil se usa «Mover a…» para no pelear con el desplazamiento) ---- */
 var drag = null, raf = 0, justDragged = false;
 function colAt(x, y) { var el = D.elementFromPoint(x, y); return el ? el.closest('.kcol') : null; }
 function setOver(col) { if (drag.over === col) return; if (drag.over) drag.over.classList.remove('is-over'); drag.over = col; if (col && col.getAttribute('data-col') !== drag.from) col.classList.add('is-over'); }
 function autoscroll() {
  if (!drag || !drag.started) return;
  var r = scroller.getBoundingClientRect(), x = drag.x, y = drag.y;
  if (x < r.left + 70) scroller.scrollLeft -= 16; else if (x > r.right - 70) scroller.scrollLeft += 16;
  if (y < 110) window.scrollBy(0, -14); else if (y > window.innerHeight - 70) window.scrollBy(0, 14);
  setOver(colAt(x, y)); raf = requestAnimationFrame(autoscroll);
 }
 function endDrag() {
  if (!drag) return; cancelAnimationFrame(raf);
  if (drag.ghost) drag.ghost.remove(); drag.card.classList.remove('is-dragging'); if (drag.over) drag.over.classList.remove('is-over');
  D.body.classList.remove('is-dragging'); drag = null;
 }
 if (WRITE) {
  board.addEventListener('pointerdown', function (e) {
   if (e.button !== 0 || e.pointerType === 'touch' || e.target.closest('button,input,select,textarea')) return;
   var card = e.target.closest('.kcard'); if (!card || card.classList.contains('is-pending')) return;
   drag = { card: card, from: stageOf(card), x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, started: false, ghost: null, over: null };
  });
  D.addEventListener('pointermove', function (e) {
   if (!drag) return; drag.x = e.clientX; drag.y = e.clientY;
   if (!drag.started) {
    if (Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) < 7) return;
    closeMenu(false); drag.started = true;
    var r = drag.card.getBoundingClientRect(); drag.dx = drag.x0 - r.left; drag.dy = drag.y0 - r.top;
    var g = drag.card.cloneNode(true); g.classList.add('kcard--ghost'); g.removeAttribute('data-id'); g.setAttribute('aria-hidden', 'true'); g.style.width = r.width + 'px'; D.body.appendChild(g); drag.ghost = g;
    drag.card.classList.add('is-dragging'); D.body.classList.add('is-dragging'); var sel = window.getSelection && window.getSelection(); if (sel) sel.removeAllRanges();
    raf = requestAnimationFrame(autoscroll);
   }
   e.preventDefault(); drag.ghost.style.transform = 'translate(' + (e.clientX - drag.dx) + 'px,' + (e.clientY - drag.dy) + 'px) rotate(1.5deg)';
   setOver(colAt(e.clientX, e.clientY));
  });
  D.addEventListener('pointerup', function (e) {
   if (!drag) return;
   if (!drag.started) { drag = null; return; }
   var target = colAt(e.clientX, e.clientY), card = drag.card, from = drag.from; justDragged = true; setTimeout(function () { justDragged = false; }, 60); endDrag();
   if (target && target.getAttribute('data-col') !== from) move(card, target.getAttribute('data-col'));
  });
  D.addEventListener('pointercancel', endDrag);
  D.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drag) { e.preventDefault(); endDrag(); } });
  board.addEventListener('click', function (e) { if (justDragged) { e.preventDefault(); e.stopPropagation(); } }, true);
  board.addEventListener('dragstart', function (e) { e.preventDefault(); });
 }

 /* ---- Chips de etapa (móvil/tablet): desplazan al carril y marcan la columna visible ---- */
 var chips = $$('[data-chip]', board);
 chips.forEach(function (ch) {
  ch.addEventListener('click', function (e) {
   e.preventDefault(); var col = cols[ch.getAttribute('data-chip')].el;
   scroller.scrollTo({ left: col.offsetLeft - 16, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
   var t = $('.kcol__t', col); if (t) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); }
  });
 });
 function markChip() {
  var left = scroller.scrollLeft, best = null, bd = 1e9;
  Object.keys(cols).forEach(function (st) { var d = Math.abs(cols[st].el.offsetLeft - 16 - left); if (d < bd) { bd = d; best = st; } });
  chips.forEach(function (ch) { var on = ch.getAttribute('data-chip') === best; ch.classList.toggle('is-on', on); if (on) { ch.setAttribute('aria-current', 'true'); var bar = ch.parentNode; if (bar.scrollWidth > bar.clientWidth) bar.scrollLeft = ch.offsetLeft - 16; } else ch.removeAttribute('aria-current'); });
 }
 if (chips.length) { var tk = 0; scroller.addEventListener('scroll', function () { cancelAnimationFrame(tk); tk = requestAnimationFrame(markChip); }, { passive: true }); markChip(); }

 /* ---- Filtros: el selector de fuente envía el formulario ---- */
 $$('[data-autosubmit] select').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
})();
