/* NorthStar Captación · tareas (/tareas): selector de lead, editar y elegir fecha de posposición. Sin librerías; estilos solo por CSS. */
(function () {
 'use strict';
 var D = document, $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var CSRF = (D.querySelector('script[data-csrf]') || { getAttribute: function () { return ''; } }).getAttribute('data-csrf');
 function closeDd(el) { var dd = el.closest('[data-dd]'); if (dd) { var b = $('button[aria-expanded="true"]', dd); if (b) b.click(); } }

 /* Selector de lead: busca con /ajax/search (mismo buscador del panel) y guarda el id en un campo oculto. */
 function picker(root) {
  var q = $('[data-lp-q]', root), id = $('[data-lp-id]', root), sel = $('[data-lp-sel]', root), nm = $('[data-lp-name]', root), list = $('[data-lp-list]', root), timer = 0, idx = -1, ctl = null;
  function setLead(i, name) { id.value = i || ''; sel.hidden = !i; nm.textContent = name || ''; q.value = ''; q.hidden = !!i; q.closest('.field').hidden = !!i; list.hidden = true; list.textContent = ''; q.setAttribute('aria-expanded', 'false'); }
  root.lpSet = setLead;
  function msg(t) { list.textContent = ''; var p = D.createElement('li'); p.className = 'lp__msg'; p.textContent = t; p.setAttribute('role', 'presentation'); list.appendChild(p); list.hidden = false; }
  function mark(i) { var o = $$('.lp__o', list); if (!o.length) return; idx = (i + o.length) % o.length; o.forEach(function (x, k) { x.setAttribute('aria-selected', k === idx ? 'true' : 'false'); }); o[idx].scrollIntoView({ block: 'nearest' }); }
  function render(j) {
   if (!j.ok) { msg('No se pudo buscar. Inténtalo de nuevo.'); return; }
   if (!j.items.length) { msg('Nada coincide.'); return; }
   list.textContent = ''; idx = -1;
   j.items.forEach(function (r) {
    var li = D.createElement('li'), b = D.createElement('button'); b.type = 'button'; b.className = 'lp__o'; b.setAttribute('role', 'option'); b.setAttribute('aria-selected', 'false');
    var av = D.createElement('span'); av.className = 'av av--sm'; av.textContent = r.initials; av.setAttribute('aria-hidden', 'true');
    var t = D.createElement('span'), n = D.createElement('b'), s = D.createElement('small'); n.textContent = r.name; s.textContent = [r.profile, r.state, r.phone !== '—' ? r.phone : '', r.stage].filter(Boolean).join(' · ');
    t.appendChild(n); t.appendChild(s); b.appendChild(av); b.appendChild(t);
    b.addEventListener('click', function () { setLead(r.id, r.name); });
    li.appendChild(b); list.appendChild(li);
   });
   list.hidden = false; q.setAttribute('aria-expanded', 'true'); mark(0);
  }
  q.addEventListener('input', function () {
   clearTimeout(timer); var v = q.value.trim();
   if (v.length < 2) { list.hidden = true; list.textContent = ''; q.setAttribute('aria-expanded', 'false'); return; }
   timer = setTimeout(function () {
    if (ctl) ctl.abort(); ctl = window.AbortController ? new AbortController() : null;
    fetch('/ajax/search', { method: 'POST', credentials: 'same-origin', signal: ctl ? ctl.signal : undefined, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ q: v }) })
     .then(function (r) { return r.json(); }).then(render).catch(function (e) { if (!e || e.name !== 'AbortError') msg('No se pudo buscar. Inténtalo de nuevo.'); });
   }, 200);
  });
  q.addEventListener('keydown', function (e) {
   if (e.key === 'ArrowDown') { e.preventDefault(); mark(idx + 1); } else if (e.key === 'ArrowUp') { e.preventDefault(); mark(idx - 1); }
   else if (e.key === 'Enter') { var o = $$('.lp__o', list)[idx]; if (o) { e.preventDefault(); o.click(); } }
   else if (e.key === 'Escape' && !list.hidden) { e.stopPropagation(); list.hidden = true; q.setAttribute('aria-expanded', 'false'); }
  });
  $('[data-lp-clear]', root).addEventListener('click', function () { setLead('', ''); q.focus(); });
 }
 $$('[data-lp]').forEach(picker);

 /* Editar */
 var ed = $('#task-edit');
 $$('[data-edit]').forEach(function (b) {
  b.addEventListener('click', function () {
   var t; try { t = JSON.parse(b.getAttribute('data-edit')); } catch (e) { return; }
   closeDd(b); var f = $('[data-edit-form]', ed);
   f.action = '/tareas/' + t.id + '/edit'; f.elements.title.value = t.title; f.elements.due.value = t.due || ''; f.elements.priority.value = String(t.priority);
   $('[data-lp]', ed).lpSet(t.lead_id || '', t.lead_name || ''); ed.showModal(); f.elements.title.focus();
  });
 });
 /* Posponer a una fecha */
 var sn = $('#task-snooze');
 $$('[data-snooze]').forEach(function (b) {
  b.addEventListener('click', function () {
   var t; try { t = JSON.parse(b.getAttribute('data-snooze')); } catch (e) { return; }
   closeDd(b); var f = $('[data-snooze-form]', sn); f.action = '/tareas/' + t.id + '/snooze'; f.elements.date.value = t.due || ''; $('[data-snooze-title]', sn).textContent = t.title; sn.showModal(); f.elements.date.focus();
  });
 });
})();

/* Q5a: «Nueva tarea» desde la paleta de comandos (/tareas?new=1) */
(function () { var d = document.querySelector("#task-new"); if (d && d.showModal && /[?&]new=1(&|$)/.test(location.search)) { d.showModal(); var f = d.querySelector("input:not([type=hidden]),textarea"); if (f) f.focus(); } })();
