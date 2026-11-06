/* NorthStar Captación · vistas, densidad y columnas de la bandeja y el tablero (Q5a-C). Las vistas se guardan con formularios (funcionan sin JS); aquí solo lo que mejora la experiencia. */
(function () {
 'use strict';
 var D = document, NS = window.NS;
 if (!NS || !NS.api) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 NS.stayOnCreate = true;
 D.addEventListener('ns:created', function (e) { NS.reloadWith(e.detail.msg); });
 $$('[data-new-lead]').forEach(function (b) { b.addEventListener('click', function () { NS.openQC({ profile: b.getAttribute('data-profile') || '' }); }); });
 $$('[data-density-set]').forEach(function (b) {
  b.addEventListener('click', function () {
   var v = b.getAttribute('data-density-set'); D.documentElement.setAttribute('data-density', v);
   $$('[data-density-set]').forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
   var kb = $('[data-board]'); if (kb) kb.classList.toggle('kb--compact', v === 'compact');
   NS.api('/ajax/prefs', { key: 'density', value: v });
  });
 });
 var table = $('[data-leads-table]'), toggles = $$('[data-col-toggle]');
 toggles.forEach(function (c) {
  c.addEventListener('change', function () {
   var on = toggles.filter(function (x) { return x.checked; }).map(function (x) { return x.getAttribute('data-col-toggle'); });
   if (!on.length) { c.checked = true; return; }
   if (table) table.setAttribute('data-hide', toggles.filter(function (x) { return !x.checked; }).map(function (x) { return x.getAttribute('data-col-toggle'); }).join(' '));
   NS.api('/ajax/prefs', { key: 'cols', value: on.join(',') });
  });
 });
 var rd = $('#view-rename'), rf = rd && $('[data-rename-form]', rd);
 $$('[data-view-rename]').forEach(function (b) {
  b.addEventListener('click', function () { rf.action = '/vistas/' + b.getAttribute('data-view-rename') + '/rename'; rf.elements.name.value = b.getAttribute('data-name'); rd.showModal(); rf.elements.name.focus(); rf.elements.name.select(); });
 });
 /* Menú de columnas de la Tabla (<details>, funciona sin JS): se cierra al hacer clic fuera o con Esc. */
 var tcm = $('details[data-tcm]');
 if (tcm) {
  D.addEventListener('click', function (e) { if (tcm.open && !tcm.contains(e.target)) tcm.removeAttribute('open'); });
  tcm.addEventListener('keydown', function (e) { if (e.key === 'Escape' && tcm.open) { e.stopPropagation(); tcm.removeAttribute('open'); $('summary', tcm).focus(); } });
 }
})();
