/* NorthStar Captación · editor de reglas (Q5b). Panel lateral en escritorio y hoja inferior en móvil sobre la lista de conjuntos; sin librerías. Las piezas del editor (filas, reglas, acciones) las
   construye el SERVIDOR (/ajax/rules/row) para que haya una sola implementación; aquí solo se mueve el DOM, se valida en vivo (/ajax/rules/preview) y se simula (/ajax/rules/simulate).
   Sin JS el mismo formulario funciona con botones que recargan la página. Esc cierra, el foco vuelve a la tarjeta, aria-modal solo en la hoja móvil. */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS, rp = D.querySelector('#rp');
 if (!NS || !NS.api || !rp) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var body = $('[data-rp-body]', rp), scrim = $('[data-rp-scrim]'), cssV = rp.getAttribute('data-css-v'), cssP = null, origin = null, dirty = false, setId = 0, req = 0, pvTimer = 0, pvReq = 0, simTimer = 0;
 var sheet = function () { return W.matchMedia('(max-width:1024px)').matches; };
 function ready() { return cssP || (cssP = NS.css('rules', cssV)); }
 if (W.requestIdleCallback) W.requestIdleCallback(ready); else setTimeout(ready, 1500);
 var isOpen = function () { return rp.classList.contains('is-open') && !rp.hidden; };
 function url(id) { var u = new URL(location.href); u.searchParams.set('tab', 'reglas'); u.searchParams.delete('nueva'); u.searchParams.delete('simular'); if (id) u.searchParams.set('set', id); else u.searchParams.delete('set'); try { history.replaceState(null, '', u.pathname + u.search + u.hash); } catch (e) {} }
 function skeleton() { var d = NS.el('div', 'rp__sk'); for (var i = 0; i < 6; i++) d.appendChild(NS.el('i')); d.setAttribute('aria-busy', 'true'); return d; }
 function fail(msg) {
  body.textContent = ''; var d = NS.el('div', 'rp__empty'); d.appendChild(NS.el('span', null, msg || 'No se pudo cargar el editor.'));
  var b = NS.el('button', 'btn btn--secondary btn--sm', 'Reintentar'); b.type = 'button'; b.addEventListener('click', function () { load(setId); }); d.appendChild(b); body.appendChild(d);
 }
 function form() { return $('[data-rp-form]', rp); }

 /* ---------- Abrir y cerrar ---------- */
 function mount(html) {
  var t = D.createElement('template'); t.innerHTML = html; body.textContent = ''; body.appendChild(t.content); dirty = false; wire();
  var h = $('#rp-title', rp); if (h) h.focus({ preventScroll: true });
  var sim = $('[data-sim-status="running"]', rp); if (sim) poll(parseInt(sim.getAttribute('data-sim'), 10));
 }
 function load(id) {
  var my = ++req; body.textContent = ''; body.appendChild(skeleton());
  return NS.api('/ajax/rules/panel', { id: id }).then(function (j) {
   if (my !== req) return;
   if (!j.ok) { fail(j.status === 404 ? 'El conjunto ya no existe.' : j.msg); return; }
   mount(j.html);
  });
 }
 function open(id, el) {
  return ready().then(function () {
   if (!isOpen()) { origin = el || D.activeElement; rp.classList.remove('rp--inline'); rp.hidden = false; void rp.offsetWidth; rp.classList.add('is-open'); if (sheet()) { scrim.hidden = false; void scrim.offsetWidth; scrim.classList.add('is-open'); D.body.style.overflow = 'hidden'; } }
   rp.setAttribute('aria-modal', sheet() ? 'true' : 'false'); setId = id; $$('[data-rl-open]').forEach(function (a) { a.classList.toggle('is-cur', parseInt(a.getAttribute('data-rl-open'), 10) === id); });
   url(id); return load(id);
  });
 }
 function close(force) {
  if (!isOpen()) return;
  if (dirty && !force && !W.confirm('Hay cambios sin guardar en este conjunto. ¿Cerrar sin guardar?')) return;
  req++; clearInterval(simTimer); rp.classList.remove('is-open'); scrim.classList.remove('is-open'); D.body.style.overflow = '';
  setTimeout(function () { if (!rp.classList.contains('is-open')) { rp.hidden = true; scrim.hidden = true; } }, 250);
  $$('[data-rl-open].is-cur').forEach(function (a) { a.classList.remove('is-cur'); }); url(0); dirty = false;
  var f = origin && D.contains(origin) ? origin : $('[data-rl-open]'); if (f && f.focus) f.focus({ preventScroll: true });
 }
 $$('[data-rl-open]').forEach(function (a) {
  a.addEventListener('click', function (e) { if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button) return; e.preventDefault(); open(parseInt(a.getAttribute('data-rl-open'), 10), a); });
 });
 scrim.addEventListener('click', function () { close(); });
 W.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
 D.addEventListener('keydown', function (e) {
  if (!isOpen() || D.querySelector('dialog[open]')) return;
  if (e.key === 'Escape') { var t = e.target; if (t && t.tagName === 'SELECT') return; e.preventDefault(); close(); return; }
  if (e.key === 'Tab' && sheet()) {
   var f = $$('a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea,summary,[tabindex="0"]', rp).filter(function (x) { return x.offsetParent !== null; });
   if (f.length) { var a = f[0], z = f[f.length - 1]; if (e.shiftKey && (D.activeElement === a || D.activeElement === rp)) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && D.activeElement === z) { e.preventDefault(); a.focus(); } }
  }
 });

 /* ---------- Piezas del servidor ---------- */
 function row(kind, np, extra) {
  var b = { set: setId, kind: kind, np: np }; if (extra) Object.keys(extra).forEach(function (k) { b[k] = extra[k]; });
  return NS.api('/ajax/rules/row', b).then(function (j) { if (!j.ok) { NS.toast('No se pudo añadir la pieza. Recarga el editor.', { bad: true }); return null; } var t = D.createElement('template'); t.innerHTML = j.html.trim(); return t.content.firstElementChild; });
 }
 function nextKey(container, prefix) {
  var max = 0; $$(':scope > *', container).forEach(function (c) { var m = (c.getAttribute('data-np') || c.getAttribute('data-key') || '').match(/[\[]([a-z]*)(\d+)\]$/); if (m) max = Math.max(max, parseInt(m[2], 10)); });
  var n = max + 1, used = D.body.innerHTML; while (used.indexOf('[' + prefix + n + ']') >= 0) n++; return prefix + n;
 }
 function ruleKeyOf(el) { return el.closest('[data-rule]').getAttribute('data-key'); }
 function touched() { dirty = true; schedule(false); }

 /* ---------- Acciones del editor (delegadas: el contenido llega por fetch) ---------- */
 rp.addEventListener('click', function (e) {
  var t = e.target.closest ? e.target.closest('button,a') : null; if (!t || !rp.contains(t)) return;
  if (t.hasAttribute('data-rp-close')) { close(); return; }
  if (t.hasAttribute('data-hist')) { e.preventDefault(); var h = $('#rp-hist', rp); if (h) { h.scrollIntoView({ behavior: 'smooth', block: 'start' }); } return; }
  if (t.hasAttribute('data-impact-go')) { e.preventDefault(); schedule(true); return; }
  if (t.hasAttribute('data-sim-go')) { e.preventDefault(); simulate(t); return; }
  var add = t.getAttribute('data-add');
  if (add) { e.preventDefault(); addPiece(t, add); return; }
  var del = t.getAttribute('data-del');
  if (del) { e.preventDefault(); delPiece(t, del); return; }
  if (t.hasAttribute('data-delrule')) { e.preventDefault(); var r = t.closest('[data-rule]'), n = r.parentNode; var nx = r.nextElementSibling || r.previousElementSibling; r.remove(); emptyState(); touched(); if (nx) { var f = $('input[type=text]', nx); if (f) f.focus(); } return; }
  if (t.hasAttribute('data-dup')) { e.preventDefault(); dup(t.closest('[data-rule]')); return; }
  var mv = t.getAttribute('data-move');
  if (mv) { e.preventDefault(); move(t.closest('[data-rule]'), mv === 'up' ? -1 : 1, t); return; }
 });
 function emptyState() { var l = $('[data-rules]', rp), em = $('[data-rules-empty]', rp); if (em) em.hidden = l.children.length > 0; }
 function addPiece(btn, kind) {
  var r = btn.closest('[data-rule]');
  if (kind === 'rule') {
   var list = $('[data-rules]', rp), key = nextKey(list, 'n'); btn.disabled = true;
   row('rule', 'rules[' + key + ']').then(function (el) { btn.disabled = false; if (!el) return; list.appendChild(el); emptyState(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = $('.rule__name input', el); if (i) i.focus(); touched(); });
   return;
  }
  if (kind === 'cond' || kind === 'group') {
   var blk = btn.closest('[data-block]'), items = $('[data-items]', blk), np = blk.getAttribute('data-np') + '[items][' + nextKey(items, kind === 'cond' ? 'c' : 'g') + ']';
   row(kind, np).then(function (el) { if (!el) return; items.appendChild(el); var f = $('select', el); if (f) f.focus(); touched(); });
   return;
  }
  if (kind === 'cond-in-group') {
   var g = btn.closest('[data-group]'), it = $('[data-items]', g), gnp = g.getAttribute('data-np') + '[items][' + nextKey(it, 'c') + ']';
   row('cond', gnp).then(function (el) { if (!el) return; it.appendChild(el); var f = $('select', el); if (f) f.focus(); touched(); });
   return;
  }
  if (kind === 'act') {
   var acts = $('[data-acts]', r), anp = 'rules[' + ruleKeyOf(btn) + '][then][' + nextKey(acts, 'a') + ']';
   var have = $$('[data-ra=type]', acts).map(function (s) { return s.value; }), opts = $$('[data-ra=type] option', acts[0] || r).map(function (o) { return o.value; }).filter(Boolean);
   var pick = ''; (opts.length ? opts : []).some(function (v) { if (have.indexOf(v) < 0 || v === 'tag') { pick = v; return true; } return false; });
   row('act', anp, { type: pick }).then(function (el) { if (!el) return; acts.appendChild(el); var f = $('select', el); if (f) f.focus(); touched(); });
   return;
  }
  if (kind === 'except') {
   var key = ruleKeyOf(btn), holder = $('[data-except]', r);
   row('except', 'rules[' + key + '][except]').then(function (el) { if (!el) return; var old = $('[data-block]', holder); if (old) old.remove(); holder.insertBefore(el, holder.firstChild); holder.hidden = false; btn.hidden = true; var f = $('select', el); if (f) f.focus(); touched(); });
 }
 }
 function delPiece(btn, kind) {
  var r = btn.closest('[data-rule]');
  if (kind === 'cond') { var c = btn.closest('[data-cond]'), nx = c.nextElementSibling || c.previousElementSibling; c.remove(); if (nx) { var f = $('select', nx); if (f) f.focus(); } }
  else if (kind === 'group') { btn.closest('[data-group]').remove(); }
  else if (kind === 'act') { btn.closest('[data-act]').remove(); }
  else if (kind === 'except') { var h = $('[data-except]', r), b = $('[data-block]', h); if (b) b.remove(); h.hidden = true; var a = $('[data-add=except]', r); a.hidden = false; a.focus(); }
  touched();
 }
 function move(r, d, btn) {
  var sib = d < 0 ? r.previousElementSibling : r.nextElementSibling; if (!sib) return;
  if (d < 0) r.parentNode.insertBefore(r, sib); else r.parentNode.insertBefore(sib, r);
  if (btn) btn.focus(); r.scrollIntoView({ block: 'nearest' }); touched();
 }
 function syncAttrs(root) {
  $$('input', root).forEach(function (i) { if (i.type === 'checkbox' || i.type === 'radio') { if (i.checked) i.setAttribute('checked', ''); else i.removeAttribute('checked'); } else i.setAttribute('value', i.value); });
  $$('select', root).forEach(function (s) { $$('option', s).forEach(function (o) { if (o.selected) o.setAttribute('selected', ''); else o.removeAttribute('selected'); }); });
 }
 function dup(r) {
  syncAttrs(r); var list = r.parentNode, old = r.getAttribute('data-key'), nk = nextKey(list, 'n'), c = r.cloneNode(true);
  var re1 = new RegExp('rules\\[' + old + '\\]', 'g'), re2 = new RegExp('rules\\.' + old + '\\.', 'g');
  [c].concat($$('*', c)).forEach(function (el) { ['name', 'data-np', 'data-err', 'id', 'value'].forEach(function (a) { var v = el.getAttribute(a); if (v && a !== 'value' || (a === 'value' && /^(del_|add_|dup_|up:|down:|up|down)/.test(v || ''))) { el.setAttribute(a, v.replace(re1, 'rules[' + nk + ']').replace(re2, 'rules.' + nk + '.').replace(new RegExp('(del_rule|dup_rule|up|down|add_act|add_except|del_except):' + old + '\\b', 'g'), '$1:' + nk)); } }); });
  c.setAttribute('data-key', nk); c.classList.remove('has-err');
  var id = $('input[name$="[id]"]', c); if (id) id.value = ''; var ex = $('input[name$="[example]"]', c); if (ex) ex.value = '0'; var ex2 = $('.rule__ex', c); if (ex2) ex2.remove();
  var nm = $('.rule__name input', c); if (nm) nm.value = (nm.value + ' (copia)').slice(0, 80);
  r.parentNode.insertBefore(c, r.nextSibling); wireGrips(c); c.scrollIntoView({ behavior: 'smooth', block: 'center' }); if (nm) nm.focus(); touched();
 }

 /* ---------- Cambios en los campos ---------- */
 rp.addEventListener('change', function (e) {
  var t = e.target; if (!t || !t.getAttribute) return;
  var k = t.getAttribute('data-rc');
  if (k === 'field' || k === 'op') {
   var c = t.closest('[data-cond]'), np = c.getAttribute('data-np'), fs = $('[data-rc=field]', c), os = $('[data-rc=op]', c), keep = null;
   if (k === 'op') { var vi = $('[data-rc=value] input[type=text],[data-rc=value] select', c); keep = vi && !/\]\[\d\]$/.test(vi.name) ? vi.value : ''; }
   var id = t.id; t.disabled = true;
   row('cond', np, { field: fs.value, op: k === 'field' ? '' : os.value, value: keep || '' }).then(function (el) { if (!el) { t.disabled = false; return; } c.replaceWith(el); var f = $('[data-rc=' + k + ']', el); if (f) f.focus(); touched(); });
   return;
  }
  if (t.getAttribute('data-ra') === 'type') {
   var a = t.closest('[data-act]'), anp = a.getAttribute('data-np'); t.disabled = true;
   row('act', anp, { type: t.value }).then(function (el) { if (!el) { t.disabled = false; return; } a.replaceWith(el); var f = $('[data-ra=type]', el); if (f) f.focus(); touched(); });
   return;
  }
  if (t.getAttribute('data-rb') === 'mode') { var b = t.closest('[data-group],[data-block]'); b.setAttribute('data-mode', t.value); }
  if (t.hasAttribute('data-on')) { t.closest('[data-rule]').classList.toggle('is-off', !t.checked); }
  if (t.matches('[data-impact-scope]')) { schedule(true); return; }
  if (t.matches('[data-sim-scope]')) { var rg = $('[data-sim-range]', rp); if (rg) rg.hidden = t.value !== 'range'; return; }
  if (t.name && /^(impact_scope|sim_scope|sim_from|sim_to)$/.test(t.name)) return;
  touched();
 });
 rp.addEventListener('input', function (e) { var t = e.target; if (!t || !t.name || /^(impact_scope|sim_scope|sim_from|sim_to|password|code)$/.test(t.name) || t.closest('.rp__ulf')) return; touched(); });

 /* ---------- Validación en vivo, frases e impacto ---------- */
 function qs() { var f = form(); if (!f) return ''; return new URLSearchParams(new FormData(f)).toString(); }
 function showErrors(map) {
  $$('.rp-err', rp).forEach(function (s) { s.hidden = true; s.textContent = ''; });
  $$('[aria-invalid=true]', rp).forEach(function (x) { x.removeAttribute('aria-invalid'); }); $$('.rule.has-err', rp).forEach(function (x) { x.classList.remove('has-err'); });
  Object.keys(map || {}).forEach(function (p) {
   var s = $('[data-err="' + p + '"]', rp); if (s) { s.textContent = map[p]; s.hidden = false; var c = s.parentNode && $('[aria-invalid]', s.parentNode); }
   var m = p.match(/^rules\.([A-Za-z0-9_]+)\./); if (m) { var ru = $('[data-key="' + m[1] + '"]', rp); if (ru) ru.classList.add('has-err'); }
  });
 }
 function schedule(now) { clearTimeout(pvTimer); pvTimer = setTimeout(preview, now ? 0 : 550); }
 function preview() {
  var f = form(); if (!f) return; var my = ++pvReq, sc = $('[data-impact-scope]', rp), out = $('[data-impact]', rp);
  if (out) out.setAttribute('aria-busy', 'true');
  NS.api('/ajax/rules/preview', { set: setId, form: qs(), impact: true, scope: sc ? sc.value : 'last:500' }).then(function (j) {
   if (my !== pvReq) return; if (out) out.removeAttribute('aria-busy');
   if (!j.ok) { if (out && j.status === 429) { out.textContent = 'Demasiadas vistas previas seguidas. Espera un momento.'; } return; }
   showErrors(j.errors); Object.keys(j.phrases || {}).forEach(function (k) { var ru = $('[data-key="' + k + '"] [data-phrase]', rp); if (!ru) return; var p = j.phrases[k]; ru.textContent = ''; ru.appendChild(D.createTextNode(p.when + '. ')); var b = NS.el('b', null, p.then + '.'); ru.appendChild(b); if (p.except) ru.appendChild(D.createTextNode(' ' + p.except + '.')); });
   if (j.html && out) { var t = D.createElement('template'); t.innerHTML = j.html; out.textContent = ''; out.appendChild(t.content); }
  });
 }

 /* ---------- Simulación ---------- */
 function simulate(btn) {
  var sc = $('[data-sim-scope]', rp), out = $('[data-sim-out]', rp); btn.disabled = true;
  NS.api('/ajax/rules/simulate', { set: setId, form: qs(), scope: sc ? sc.value : 'last:1000', from: ($('[name=sim_from]', rp) || {}).value || '', to: ($('[name=sim_to]', rp) || {}).value || '' }).then(function (j) {
   btn.disabled = false;
   if (!j.ok) { if (j.errors) showErrors(j.errors); NS.toast(j.msg || 'No se pudo simular.', { bad: true }); return; }
   dirty = false; if (out) { var t = D.createElement('template'); t.innerHTML = j.html; out.textContent = ''; out.appendChild(t.content); }
   if (j.status === 'running') poll(j.id); else NS.toast('Simulación terminada (borrador v' + j.version + ' guardado).');
   var s = $('.rp__side', rp); if (s && sheet()) out.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
 }
 function poll(id) {
  clearInterval(simTimer); if (!id) return;
  simTimer = setInterval(function () {
   NS.api('/ajax/rules/simstatus', { id: id }).then(function (j) {
    if (!j.ok) { clearInterval(simTimer); return; } var out = $('[data-sim-out]', rp); if (!out) { clearInterval(simTimer); return; }
    var t = D.createElement('template'); t.innerHTML = j.html; out.textContent = ''; out.appendChild(t.content);
    if (j.status !== 'running') { clearInterval(simTimer); NS.toast(j.status === 'done' ? 'La simulación terminó.' : 'La simulación falló.', { bad: j.status !== 'done' }); }
   });
  }, 2500);
 }

 /* ---------- Arrastrar para reordenar (ratón y tacto) y con teclado en el asa ---------- */
 var drag = null;
 function wireGrips(root) {
  $$('[data-grip]', root || rp).forEach(function (g) {
   if (g.getAttribute('data-wired')) return; g.setAttribute('data-wired', '1'); g.removeAttribute('aria-hidden'); g.setAttribute('role', 'button'); g.setAttribute('tabindex', '0'); g.setAttribute('aria-label', 'Mover la regla: arrastra, o usa las flechas arriba y abajo');
   g.addEventListener('keydown', function (e) { if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); move(g.closest('[data-rule]'), e.key === 'ArrowUp' ? -1 : 1, null); g.focus(); } });
   g.addEventListener('pointerdown', function (e) {
    if (e.button) return; var r = g.closest('[data-rule]'); drag = { r: r, id: e.pointerId }; r.classList.add('is-drag'); try { g.setPointerCapture(e.pointerId); } catch (x) {} e.preventDefault();
   });
   g.addEventListener('pointermove', function (e) {
    if (!drag) return; var el = D.elementFromPoint(e.clientX, e.clientY), over = el && el.closest ? el.closest('[data-rule]') : null; if (!over || over === drag.r || over.parentNode !== drag.r.parentNode) return;
    var b = over.getBoundingClientRect(), after = e.clientY > b.top + b.height / 2; if (after) over.parentNode.insertBefore(drag.r, over.nextSibling); else over.parentNode.insertBefore(drag.r, over);
   });
   var end = function () { if (!drag) return; drag.r.classList.remove('is-drag'); drag = null; touched(); };
   g.addEventListener('pointerup', end); g.addEventListener('pointercancel', end);
  });
 }
 function wire() { wireGrips(); }

 /* ---------- Arranque ---------- */
 var first = $('[data-rp-form]', rp);
 if (first) {   // la página llegó con el editor abierto (?set=ID): pasa a ser el panel deslizable
  setId = parseInt(($('[data-rp-id]', rp) || {}).getAttribute ? $('[data-rp-id]', rp).getAttribute('data-rp-id') : '0', 10) || 0;
  origin = $('[data-rl-open="' + setId + '"]') || null; rp.classList.remove('rp--inline'); rp.hidden = false; void rp.offsetWidth; rp.classList.add('is-open');
  if (sheet()) { scrim.hidden = false; scrim.classList.add('is-open'); D.body.style.overflow = 'hidden'; rp.setAttribute('aria-modal', 'true'); }
  wire(); var sim0 = $('[data-sim-status="running"]', rp); if (sim0) poll(parseInt(sim0.getAttribute('data-sim'), 10));
  var fc = rp.getAttribute('data-focus'), fe = fc === 'sim' ? $('[data-sim-go]', rp) : (fc === 'new' ? $$('.rule__name input', rp).pop() : null);
  (fe || $('#rp-title', rp) || rp).focus({ preventScroll: !fe }); if (fe && fe.scrollIntoView) fe.scrollIntoView({ block: 'center' });
  if (fc === 'new') dirty = true;
 } else if (rp.getAttribute('data-focus') === 'newset') { var dlg = $('#newset'); if (dlg && dlg.showModal) dlg.showModal(); }
})();
