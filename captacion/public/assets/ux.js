/* NorthStar Captación · experiencia del panel (Q5a). Sin librerías; CSP: script externo con nonce, estilos solo por CSSOM o clases.
   Expone window.NS (api, toast, undo, move, ask, act, leadAction, ctx) que usan peek.js, views.js, kanban.js y tareas.js. Todo lo que hacen los atajos se puede hacer con el ratón. */
(function () {
 'use strict';
 var D = document, W = window, H = D.documentElement, NS = W.NS = W.NS || {};
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var CSRF = (D.currentScript && D.currentScript.getAttribute('data-csrf')) || NS.csrf || '';
 var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
 var reduce = W.matchMedia && W.matchMedia('(prefers-reduced-motion: reduce)').matches;

 function api(path, body) {
  return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) })
   .then(function (r) { return r.json().then(function (j) { j.status = r.status; return j; }, function () { return { ok: false, status: r.status, msg: 'Respuesta no válida del servidor.' }; }); },
    function () { return { ok: false, status: 0, msg: 'Sin conexión con el servidor.' }; });
 }
 NS.api = api;
 function el(tag, cls, text) { var e = D.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
 function icon(name) { var s = D.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', 'ic'); s.setAttribute('aria-hidden', 'true'); var u = D.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + name); s.appendChild(u); return s; }
 NS.el = el; NS.icon = icon;
 function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

 /* ---------- Avisos (toast) con «Deshacer» de 6 s ---------- */
 var tbox = $('#toasts');
 NS.toast = function (msg, o) {
  o = o || {}; if (!tbox) return;
  while (tbox.children.length >= 3) tbox.firstChild.remove();
  var t = el('div', 'toast' + (o.bad ? ' toast--bad' : '')); t.setAttribute('role', o.bad ? 'alert' : 'status');
  t.appendChild(el('span', 'toast__t', msg));
  var timer = 0, gone = function () { clearTimeout(timer); if (t.parentNode) t.remove(); };
  if (o.undo) {
   var u = el('button', 'toast__u'); u.type = 'button'; u.appendChild(icon('undo')); u.appendChild(D.createTextNode('Deshacer'));
   u.addEventListener('click', function () { u.disabled = true; NS.undo(o.undo).then(gone); }); t.appendChild(u);
   if (!reduce) t.appendChild(el('i', 'toast__bar'));
  }
  var x = el('button', 'toast__x'); x.type = 'button'; x.setAttribute('aria-label', 'Cerrar aviso'); x.appendChild(icon('x')); x.addEventListener('click', gone); t.appendChild(x);
  tbox.appendChild(t); timer = setTimeout(gone, o.bad ? 9000 : (o.undo ? 6000 : 4500));
  return t;
 };
 NS.undo = function (token) {
  return api('/ajax/undo', { token: token }).then(function (j) {
   NS.toast(j.msg || (j.ok ? 'Cambio deshecho.' : 'No se pudo deshacer.'), { bad: !j.ok });
   if (j.ok) { D.dispatchEvent(new CustomEvent('ns:undone', { detail: j })); counters(); }
   return j;
  });
 };
 /* Un aviso que sobrevive a una recarga (acciones desde la ficha). */
 NS.reloadWith = function (msg, undo) { try { sessionStorage.setItem('ns-toast', JSON.stringify({ msg: msg, undo: undo || null })); } catch (e) {} location.reload(); };
 try { var pend = sessionStorage.getItem('ns-toast'); if (pend) { sessionStorage.removeItem('ns-toast'); var pj = JSON.parse(pend); NS.toast(pj.msg, { undo: pj.undo }); } } catch (e) {}
 /* El aviso del servidor con «Deshacer» pasa a ser un toast de 6 s (sin JS queda el aviso con su botón). */
 $$('[data-flash]').forEach(function (f) {
  var tok = $('input[name=token]', f); if (!tok) return;
  f.hidden = true; NS.toast($('.flash__t', f).textContent, { undo: tok.value });
 });

 /* ---------- Etapa: mover con los datos que pide el servidor ---------- */
 var mvd = $('#mv-dlg'), mvPending = null;
 NS.ask = function (o) {
  return new Promise(function (resolve) {
   if (!mvd || !mvd.showModal) { NS.toast(o.msg, { bad: true }); resolve(null); return; }
   var f = $('[data-mv-form]', mvd), prev = o.prev || {}, needs = o.needs, to = o.to;
   var needReason = needs === 'reason' || needs === 'note' || to === 'lost', needOverride = needs === 'override' || !!prev.override;
   $('.btn__t', mvd).textContent = to === 'lost' ? 'Marcar como perdido' : 'Mover';
   $('#mv-t', mvd).textContent = o.title || 'Mover lead';
   $('[data-mv-msg]', mvd).textContent = (o.name ? o.name + ': ' : '') + o.msg;
   $('[data-mv-reason]', mvd).hidden = !needReason; f.elements.reason.required = needReason; f.elements.reason.value = prev.reason || '';
   $('[data-mv-note]', mvd).hidden = !(needReason || needs === 'note'); f.elements.note.value = prev.note || '';
   $('[data-mv-note-hint]', mvd).textContent = needs === 'note' ? '(obligatoria con «Otro»)' : '(opcional)';
   $('[data-mv-override]', mvd).hidden = !needOverride; f.elements.override.required = needOverride; f.elements.override.checked = false;
   mvPending = { resolve: resolve, done: false };
   mvd.showModal();
   var first = needReason ? f.elements.reason : needOverride ? f.elements.override : f.elements.note; if (first) first.focus();
  });
 };
 if (mvd) {
  var mf = $('[data-mv-form]', mvd);
  mf.addEventListener('submit', function (e) {
   e.preventDefault(); if (!mvPending) { mvd.close(); return; }
   var o = { reason: mf.elements.reason.value, note: mf.elements.note.value.trim(), override: mf.elements.override.checked };
   if (o.reason === 'other' && o.note.length < 3) { mf.elements.note.focus(); $('[data-mv-msg]', mvd).textContent = 'Con el motivo «Otro» escribe una nota que lo explique.'; return; }
   var p = mvPending; p.done = true; mvPending = null; mvd.close(); p.resolve(o);
  });
  mvd.addEventListener('close', function () { if (mvPending && !mvPending.done) { var p = mvPending; mvPending = null; p.resolve(null); } });
 }
 /* Cambio de etapa por el servidor (mismas reglas que la ficha). Resuelve con la respuesta; si faltan datos, los pide. */
 NS.move = function (id, to, o) {
  o = o || {};
  return api('/ajax/pipeline/move', { lead_id: id, to: to, qs: o.qs || '', reason: o.reason || '', note: o.note || '', override: !!o.override }).then(function (j) {
   if (j.ok) { if (!o.silent) NS.toast((o.name ? o.name + ': ' : '') + j.msg, { undo: j.undo }); D.dispatchEvent(new CustomEvent('ns:moved', { detail: { id: id, from: j.from, to: to, res: j } })); counters(); return j; }
   if (j.status === 409 && j.needs) {
    return NS.ask({ name: o.name, to: to, needs: j.needs, msg: j.msg, prev: o, title: o.stageLabel ? 'Mover a «' + o.stageLabel + '»' : null }).then(function (extra) { return extra ? NS.move(id, to, Object.assign({}, o, extra)) : j; });
   }
   NS.toast(j.msg || (j.status === 401 ? 'La sesión caducó. Recarga la página.' : 'No se pudo mover el lead.'), { bad: true });
   return j;
  });
 };
 NS.act = function (id, act, fields) {
  return api('/ajax/lead/act', Object.assign({ id: id, act: act }, fields || {})).then(function (j) {
   NS.toast(j.msg || (j.ok ? 'Hecho.' : 'No se pudo completar la acción.'), { bad: !j.ok, undo: j.undo || null });
   if (j.ok) { D.dispatchEvent(new CustomEvent('ns:acted', { detail: { id: id, act: act, res: j } })); counters(); }
   return j;
  });
 };

 /* ---------- Alta manual rápida ---------- */
 var qcd = $('#qc-dlg');
 NS.openQC = function (preset) {
  if (!qcd || !qcd.showModal) return; var f = $('[data-qc-form]', qcd); f.reset(); $('[data-qc-err]', qcd).hidden = true;
  if (preset && preset.profile) f.elements.profile.value = preset.profile;
  qcd.showModal(); f.elements.name.focus();
 };
 if (qcd) {
  var qf = $('[data-qc-form]', qcd), qe = $('[data-qc-err]', qcd), qbusy = false;
  qf.addEventListener('submit', function (e) {
   e.preventDefault(); if (qbusy) return;
   var b = { name: qf.elements.name.value.trim(), phone: qf.elements.phone.value.trim(), profile: qf.elements.profile.value };
   var bad = !b.name ? ['name', 'Escribe el nombre del lead.'] : !b.phone ? ['phone', 'Escribe el teléfono del lead.'] : !b.profile ? ['profile', 'Elige el perfil del lead.'] : null;
   if (bad) { qe.textContent = bad[1]; qe.hidden = false; qf.elements[bad[0]].focus(); return; }
   qbusy = true; qe.hidden = true;
   api('/ajax/lead/create', b).then(function (j) {
    qbusy = false;
    if (!j.ok) { qe.textContent = j.msg || 'No se pudo crear el lead.'; qe.hidden = false; return; }
    qcd.close(); NS.toast(j.msg);
    D.dispatchEvent(new CustomEvent('ns:created', { detail: j }));
    if (!NS.stayOnCreate) setTimeout(function () { location.href = j.url; }, 650);
   });
  });
 }

 /* ---------- Contexto de lead (ficha o vistazo) y acciones sobre él ---------- */
 NS.pageCtx = null; NS.ctx = null;
 var lead = $('.lead[data-lead]');
 if (lead) { try { NS.pageCtx = { leadId: parseInt(lead.getAttribute('data-lead'), 10), stage: lead.getAttribute('data-stage'), name: lead.getAttribute('data-name'), stages: JSON.parse(lead.getAttribute('data-stages') || '{}') }; } catch (e) {} NS.ctx = NS.pageCtx; }
 NS.leadAction = function (act) {
  var c = NS.ctx; if (!c) return;
  var ev = new CustomEvent('ns:lead-action', { detail: { act: act, leadId: c.leadId }, cancelable: true });
  if (!D.dispatchEvent(ev)) return;
  if (act === 'call') { var b = $('[data-open="#call-dlg"]'); if (b) b.click(); }
  else if (act === 'note') { var n = $('#note-body'); if (n) { n.scrollIntoView({ block: 'center' }); n.focus(); } }
  else if (act === 'task') { var t = $('.addrow input[name=title]'); if (t) { t.scrollIntoView({ block: 'center' }); t.focus(); } }
  else if (act === 'responded') NS.act(c.leadId, 'responded');
 };
 D.addEventListener('ns:moved', function (e) { if (NS.pageCtx && e.detail.id === NS.pageCtx.leadId) NS.reloadWith(e.detail.res.msg, e.detail.res.undo); });
 D.addEventListener('ns:undone', function () { if (NS.pageCtx) NS.reloadWith('Cambio deshecho.'); });

 /* ---------- Barra lateral: contraer, tooltips, vistas con contador en vivo, bandeja ---------- */
 var fold = $('[data-side-toggle]');
 function setSide(v, save) {
  H.setAttribute('data-side', v);
  if (fold) { fold.setAttribute('aria-pressed', v === 'collapsed' ? 'true' : 'false'); var t = v === 'collapsed' ? 'Expandir barra lateral' : 'Contraer barra lateral'; fold.setAttribute('data-tip', t); $('.nav-i__t', fold).textContent = t; }
  if (save) api('/ajax/prefs', { key: 'side', value: v });
 }
 NS.toggleSide = function () { setSide(H.getAttribute('data-side') === 'collapsed' ? 'open' : 'collapsed', true); };
 if (fold) { fold.addEventListener('click', NS.toggleSide); setSide(H.getAttribute('data-side') || 'open', false); }
 var tip = null;
 function showTip(t) {
  if (H.getAttribute('data-side') !== 'collapsed' || !W.matchMedia('(min-width:1024px)').matches) return;
  hideTip(); tip = el('div', 'tip', t.getAttribute('data-tip')); tip.setAttribute('role', 'tooltip'); D.body.appendChild(tip);
  var r = t.getBoundingClientRect(); tip.style.left = (r.right + 10) + 'px'; tip.style.top = (r.top + r.height / 2 - tip.offsetHeight / 2) + 'px';
 }
 function hideTip() { if (tip) { tip.remove(); tip = null; } }
 $$('.side [data-tip]').forEach(function (t) { t.addEventListener('mouseenter', function () { showTip(t); }); t.addEventListener('focus', function () { showTip(t); }); t.addEventListener('mouseleave', hideTip); t.addEventListener('blur', hideTip); });
 var inbox = $('[data-inbox]'), bellBtn = $('[data-notes] > button');
 if (inbox && bellBtn) inbox.addEventListener('click', function () { D.body.classList.remove('nav-open'); setTimeout(function () { bellBtn.click(); }, 0); });
 function counters() {
  if (D.hidden) return;
  api('/ajax/counters', {}).then(function (j) {
   if (!j.ok) return;
   Object.keys(j.views || {}).forEach(function (k) { $$('[data-view-count="' + k + '"]').forEach(function (n) { n.textContent = j.views[k]; }); });
   var ib = $('[data-inbox-n]'); if (ib) { ib.textContent = j.inbox; ib.hidden = !(j.inbox > 0); }
   var u = $('[data-unread]'); if (u) { u.textContent = j.unread; u.hidden = !(j.unread > 0); }
  });
 }
 NS.counters = counters;
 setInterval(counters, 60000);

 /* ---------- Bandeja de avisos: filtro, leído/no leído, marcar todo ---------- */
 var panel = $('#dd-notes');
 if (panel) {
  var filter = 'all', items = $$('.note-li', panel), none = $('[data-notes-none]', panel);
  var applyF = function () {
   var n = 0; items.forEach(function (li) { var ok = filter === 'all' || (filter === 'unread' ? li.getAttribute('data-read') === '0' : li.getAttribute('data-g') === filter); li.hidden = !ok; if (ok) n++; });
   if (none) none.hidden = n > 0 || !items.length;
  };
  $$('[data-nf]', panel).forEach(function (b) { b.addEventListener('click', function () { filter = b.getAttribute('data-nf'); $$('[data-nf]', panel).forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); }); applyF(); }); });
  var markLi = function (li) { li.setAttribute('data-read', '1'); li.classList.remove('is-new'); var a = $('.note', li); if (a) a.classList.remove('is-new'); var t = $('[data-note-toggle]', li); if (t) t.hidden = true; };
  var allBtn = $('[data-notes-read]', panel);
  if (allBtn) allBtn.addEventListener('click', function () { api('/ajax/notifications/read', {}).then(function (j) { if (!j.ok) return; items.forEach(markLi); allBtn.hidden = true; applyF(); counters(); }); });
  $$('[data-note-toggle]', panel).forEach(function (b) {
   b.addEventListener('click', function () { api('/ajax/notifications/read', { keys: [b.getAttribute('data-note-toggle')] }).then(function (j) { if (!j.ok) return; markLi(b.closest('.note-li')); if (!items.some(function (li) { return li.getAttribute('data-read') === '0'; }) && allBtn) allBtn.hidden = true; applyF(); counters(); }); });
  });
 }

 /* ---------- Paleta de comandos (Ctrl/⌘ K, «/»): acciones, leads, pantallas y vistas ---------- */
 var sd = $('#search'), sq = $('#search-q'), sl = $('#search-list'), sm = $('#search-msg'), kd = $('#keys'), CM = {};
 try { CM = JSON.parse(sd ? sd.getAttribute('data-cmds') || '{}' : '{}'); } catch (e) { CM = {}; }
 var leadRows = [], rows = [], sel = -1, sTimer = 0, sCtl = null, recent = null;
 function themeAct(v) { return function () { NS.setTheme(v); NS.toast('Tema ' + { light: 'claro', dark: 'oscuro', auto: 'automático' }[v] + '.'); }; }
 var ACT = {
  new_lead: function () { NS.openQC(); }, new_task: function () { location.href = '/tareas?new=1'; }, keys: function () { openKeys(); }, side: function () { NS.toggleSide(); },
  theme_light: themeAct('light'), theme_dark: themeAct('dark'), theme_auto: themeAct('auto')
 };
 var ACT_ICON = { new_lead: 'user-plus', new_task: 'list-check', keys: 'keyboard', side: 'panel-left', theme_light: 'sun', theme_dark: 'moon', theme_auto: 'monitor', call: 'phone', note: 'note', task: 'calendar', responded: 'check-circle' };
 function allItems(q) {
  var out = [], c = NS.ctx, nq = norm(q), toks = nq.split(/\s+/).filter(Boolean);
  var hit = function (t, k) { var h = norm(t + ' ' + (k || '')); return toks.every(function (x) { return h.indexOf(x) >= 0; }); };
  if (c) {
   Object.keys(c.stages || {}).forEach(function (st) { if (st === c.stage) return; var t = 'Mover a ' + c.stages[st]; if (hit(t, 'etapa estado cambiar')) out.push({ g: 'Acciones', t: t, s: c.name ? 'Lead: ' + c.name : '', ic: 'layers', run: function () { NS.move(c.leadId, st, { name: c.name, stageLabel: c.stages[st] }); } }); });
   (CM.leadActions || []).forEach(function (a) { if (hit(a[1], 'lead')) out.push({ g: 'Acciones', t: a[1], s: c.name ? 'Lead: ' + c.name : '', ic: ACT_ICON[a[0]] || 'zap', run: function () { NS.leadAction(a[0]); } }); });
  }
  (CM.actions || []).forEach(function (a) { if (ACT[a[0]] && hit(a[1])) out.push({ g: 'Acciones', t: a[1], ic: ACT_ICON[a[0]] || 'zap', run: ACT[a[0]] }); });
  if (nq) leadRows.forEach(function (r) { out.push(r); }); else if (recent) recent.forEach(function (r) { out.push(r); });
  (CM.screens || []).forEach(function (a) { if (hit(a[0])) out.push({ g: 'Pantallas', t: a[0], ic: 'arrow-right', run: function () { location.href = a[1]; } }); });
  (CM.views || []).forEach(function (a) { if (hit(a[0], 'vista')) out.push({ g: 'Vistas', t: a[0], s: 'Vista de leads', ic: 'bookmark', run: function () { location.href = a[1]; } }); });
  return out;
 }
 function leadItem(r, g) {
  return { g: g, lead: r, t: r.name, s: [r.profile, r.state, r.phone !== '—' ? r.phone : '', r.usdot ? 'USDOT ' + r.usdot : '', r.stage].filter(Boolean).join(' · '), run: function () { location.href = r.url; } };
 }
 function render() {
  var q = sq.value.trim(), list = allItems(q), order = ['Acciones', 'Leads', 'Recientes', 'Pantallas', 'Vistas'], last = '', idx = 0;
  sl.textContent = ''; rows = [];
  order.forEach(function (g) {
   var its = list.filter(function (i) { return i.g === g; }); if (!q && g === 'Pantallas') its = its.slice(0, 6); if (!q && g === 'Vistas') its = its.slice(0, 4); if (!its.length) return;
   var gh = el('li', 'srch__g', g); gh.setAttribute('role', 'presentation'); sl.appendChild(gh);
   its.forEach(function (it) {
    var li = el('li'), d = el('div', 'res'); d.id = 'res-' + idx; d.setAttribute('role', 'option'); d.setAttribute('aria-selected', 'false'); d.setAttribute('data-i', idx);
    if (it.lead) { var r = it.lead, av = el('span', 'av av-' + r.av, r.initials); av.setAttribute('aria-hidden', 'true'); d.appendChild(av); } else { var ic = el('span', 'res__ic'); ic.appendChild(icon(it.ic)); d.appendChild(ic); }
    var t = el('span', 'res__t'); t.appendChild(el('b', null, it.t)); if (it.s) t.appendChild(el('small', null, it.s)); d.appendChild(t);
    if (it.lead) { var c = el('span', 'chip chip--' + (it.lead.temp === 'disqualified' ? 'dq' : it.lead.temp)); c.appendChild(el('i', 'dot')); c.appendChild(D.createTextNode(it.lead.temp_label)); d.appendChild(c); }
    li.appendChild(d); sl.appendChild(li); rows.push(it); idx++;
   });
  });
  sq.setAttribute('aria-expanded', rows.length ? 'true' : 'false');
  sm.textContent = rows.length ? '' : (q ? 'Nada coincide con «' + q + '».' : 'Escribe para buscar un lead o ejecutar una acción.');
  sel = -1; if (rows.length) pick(0);
 }
 function pick(i) { var o = $$('.res', sl); if (!o.length) return; sel = (i + o.length) % o.length; o.forEach(function (x, k) { x.setAttribute('aria-selected', k === sel ? 'true' : 'false'); }); sq.setAttribute('aria-activedescendant', o[sel].id); o[sel].scrollIntoView({ block: 'nearest' }); }
 function runSel() { var it = rows[sel]; if (!it) return; sd.close(); it.run(); }
 function fetchLeads(q) {
  if (sCtl) sCtl.abort(); sCtl = W.AbortController ? new AbortController() : null;
  fetch('/ajax/search', { method: 'POST', credentials: 'same-origin', signal: sCtl ? sCtl.signal : undefined, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ q: q }) })
   .then(function (r) { return r.json(); }).then(function (j) {
    if (sq.value.trim() !== q) return;
    if (!j.ok) { leadRows = []; render(); sm.textContent = j.error === 'rate_limited' ? 'Demasiadas búsquedas seguidas. Espera un momento.' : 'No se pudo buscar. Recarga la página e inténtalo de nuevo.'; return; }
    if (q === '') { recent = j.items.map(function (r) { return leadItem(r, 'Recientes'); }); } else { leadRows = j.items.map(function (r) { return leadItem(r, 'Leads'); }); }
    render(); if (q !== '' && j.total > j.items.length) sm.textContent = 'Mostrando ' + j.items.length + ' de ' + j.total + ' leads. Afina la búsqueda o usa Leads.';
   }).catch(function (e) { if (e && e.name === 'AbortError') return; leadRows = []; render(); sm.textContent = 'No se pudo buscar. Recarga la página e inténtalo de nuevo.'; });
 }
 NS.palette = function (q) {
  if (!sd || !sd.showModal || sd.open) return; D.body.classList.remove('nav-open'); $$('[data-dd] [aria-expanded=true]').forEach(function (b) { b.click(); });
  leadRows = []; sd.showModal(); sq.value = q || ''; sq.focus(); render(); fetchLeads(''); if (sq.value.trim().length >= 2) fetchLeads(sq.value.trim());
 };
 $$('[data-search]').forEach(function (b) { b.addEventListener('click', function () { NS.palette(''); }); });
 if (sq) {
  sq.addEventListener('input', function () {
   clearTimeout(sTimer); var q = sq.value.trim(); render();
   if (q.length >= 2) { sTimer = setTimeout(function () { fetchLeads(q); }, 170); } else { leadRows = []; }
  });
  sq.addEventListener('keydown', function (e) {
   if (e.key === 'ArrowDown') { e.preventDefault(); pick(sel + 1); } else if (e.key === 'ArrowUp') { e.preventDefault(); pick(sel - 1); } else if (e.key === 'Enter') { e.preventDefault(); runSel(); }
  });
  sl.addEventListener('click', function (e) { var d = e.target.closest('.res'); if (!d) return; sel = parseInt(d.getAttribute('data-i'), 10); runSel(); });
  sd.addEventListener('close', function () { sq.value = ''; sl.textContent = ''; leadRows = []; });
 }

 /* ---------- Atajos ---------- */
 function openKeys() { if (kd && kd.showModal && !kd.open) kd.showModal(); }
 $$('[data-keys]').forEach(function (b) { b.addEventListener('click', function () { $$('[data-dd] [aria-expanded=true]').forEach(function (x) { x.click(); }); openKeys(); }); });
 var gPending = 0;
 D.addEventListener('keydown', function (e) {
  var t = e.target, typing = t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable);
  if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey) { e.preventDefault(); if (sd && sd.open) sd.close(); else if (!D.querySelector('dialog[open]')) NS.palette(''); return; }
  if (typing || e.ctrlKey || e.metaKey || e.altKey || D.querySelector('dialog[open]')) return;
  var k = e.key;
  if (gPending && Date.now() - gPending < 1300) {
   gPending = 0; var to = { d: '/', l: '/leads', p: '/pipeline', t: '/tareas' }[k.toLowerCase()];
   if (to) { e.preventDefault(); location.href = to; return; }
  }
  if (k === '/') { e.preventDefault(); NS.palette(''); }
  else if (k === '?') { e.preventDefault(); openKeys(); }
  else if (k === 'g') { gPending = Date.now(); }
  else if (NS.ctx && k === 'n') { e.preventDefault(); NS.leadAction('note'); }
  else if (NS.ctx && k === 'c') { e.preventDefault(); NS.leadAction('call'); }
  else if (NS.ctx && k === 'm') { e.preventDefault(); NS.palette('Mover a '); }
 });
 $$('[data-mod]').forEach(function (k) { if (mac) k.textContent = '⌘'; });
})();
