/* NorthStar Captación · calendario (Q5c-C). Mejora un calendario que ya funciona sin JS: crear una tarea con un clic en el día, arrastrar tareas abiertas a otro día
   (o Alt + flechas) con «Deshacer» de 6 s, y navegación por teclado entre días. Sin librerías; estilos solo por clases (CSP). Todo lo que se hace aquí tiene su enlace o formulario en la página. */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS, root = D.querySelector('[data-cal]');
 if (!NS || !NS.api || !root) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var mode = root.getAttribute('data-mode'), start = root.getAttribute('data-start') || '09:00', back = root.getAttribute('data-back') || '/calendario';
 var WD = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'], MO = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

 /* ---------- Fechas (solo calendario, sin zonas: la hora del día la conserva el servidor) ---------- */
 function parse(ymd) { var p = ymd.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
 function fmt(d) { return d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2); }
 function addDays(ymd, n) { var d = parse(ymd); d.setUTCDate(d.getUTCDate() + n); return fmt(d); }
 function longDay(ymd) { var d = parse(ymd); return WD[d.getUTCDay()] + ' ' + d.getUTCDate() + ' de ' + MO[d.getUTCMonth()]; }

 /* ---------- Nueva tarea con un clic en el día ---------- */
 var dlg = $('#cal-new'), canWrite = !!dlg;
 function openNew(ymd) {
  if (!dlg || !dlg.showModal) return false;
  var f = $('form', dlg); f.elements.due.value = ymd + 'T' + start; f.elements.title.value = '';
  $('[data-cal-new-day]', dlg).textContent = 'Para el ' + longDay(ymd) + '.';
  var lp = $('[data-lp]', dlg); if (lp && lp.lpSet) lp.lpSet('', '');
  dlg.showModal(); f.elements.title.focus(); return true;
 }
 D.addEventListener('click', function (e) {
  var t = e.target; if (!t.closest) return;
  var b = t.closest('[data-cal-new]');
  if (b && !(e.ctrlKey || e.metaKey || e.shiftKey || e.button)) { if (openNew(b.getAttribute('data-cal-new'))) e.preventDefault(); return; }
  /* Clic en el hueco de un día (no en un enlace ni botón): crea una tarea para ese día. */
  var cell = t.closest('.cal__d[data-ymd]');
  if (cell && canWrite && !t.closest('a,button,summary,details,input,select,textarea,label,form') && root.contains(cell)) openNew(cell.getAttribute('data-ymd'));
 });
 if (location.hash === '#nueva' && canWrite) { var nf = $('#nueva'); if (nf && /[?&]nueva=/.test(location.search)) { var m = /[?&]nueva=(\d{4}-\d{2}-\d{2})/.exec(location.search); if (m && openNew(m[1])) { nf.hidden = true; } } }

 /* ---------- Mover una tarea de día (arrastrar, Alt + flechas) ---------- */
 function listOf(cell) {
  var l = $('.cal__e, .cal__c', cell);
  if (!l) { l = D.createElement('ul'); l.className = mode === 'mes' ? 'cal__e' : 'cal__c'; var none = $('.cal__e0', cell); if (none) none.remove(); cell.appendChild(l); }
  return l;
 }
 function moved(el, cell, j) {
  var li = el.closest('li'), fromList = li.parentNode, from = el.closest('.cal__d');
  listOf(cell).appendChild(li); el.setAttribute('data-time', j.time);
  var t = $('.ev__t, time', li); if (t) t.textContent = j.time;
  if (fromList && !fromList.children.length) { fromList.parentNode.removeChild(fromList); if (mode !== 'mes' && from && !$('.cal__e0', from)) { var p = D.createElement('p'); p.className = 'cal__e0'; p.textContent = 'Sin eventos'; from.appendChild(p); } }
  cell.classList.remove('is-drop');
 }
 function move(el, ymd) {
  var id = parseInt(el.getAttribute('data-task'), 10), cell = $('.cal__d[data-ymd="' + ymd + '"]', root);
  if (!id || ymd === (el.closest('.cal__d') || {}).getAttribute('data-ymd')) return;
  el.classList.add('is-busy');
  NS.api('/ajax/calendar/move', { task_id: id, ymd: ymd }).then(function (j) {
   el.classList.remove('is-busy');
   if (!j.ok) { NS.toast(j.msg || 'No se pudo mover la tarea.', { bad: true }); return; }
   if (cell) { moved(el, cell, j); NS.toast(j.msg, { undo: j.undo }); el.focus({ preventScroll: true }); }
   else NS.reloadWith(j.msg, j.undo);
  });
 }
 var drag = null;
 root.addEventListener('dragstart', function (e) {
  var el = e.target.closest ? e.target.closest('[data-task]') : null; if (!el || !canWrite) { if (e.target.closest && e.target.closest('a[draggable=false]')) e.preventDefault(); return; }
  drag = el; el.classList.add('is-drag'); if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', 'task:' + el.getAttribute('data-task')); } catch (x) {} }
 });
 root.addEventListener('dragend', function () { if (drag) drag.classList.remove('is-drag'); drag = null; $$('.is-drop', root).forEach(function (c) { c.classList.remove('is-drop'); }); });
 root.addEventListener('dragover', function (e) { var c = e.target.closest ? e.target.closest('.cal__d[data-ymd]') : null; if (drag && c) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'; $$('.is-drop', root).forEach(function (x) { if (x !== c) x.classList.remove('is-drop'); }); c.classList.add('is-drop'); } });
 root.addEventListener('dragleave', function (e) { var c = e.target.closest ? e.target.closest('.cal__d[data-ymd]') : null; if (c && !c.contains(e.relatedTarget)) c.classList.remove('is-drop'); });
 root.addEventListener('drop', function (e) { var c = e.target.closest ? e.target.closest('.cal__d[data-ymd]') : null; if (!drag || !c) return; e.preventDefault(); var el = drag; drag = null; el.classList.remove('is-drag'); move(el, c.getAttribute('data-ymd')); });

 /* «Posponer» y «Mover a otro día» sin recargar la página: mismas rutas del servidor, con Deshacer. Sin JS son formularios normales. */
 root.addEventListener('submit', function (e) {
  var f = e.target; if (!f.matches) return;
  var sn = f.matches('[data-cal-snooze]') ? f.getAttribute('data-cal-snooze') : null, mv = f.matches('[data-cal-move]');
  if (!sn && !mv) return;
  e.preventDefault(); var id = parseInt(f.elements.task_id.value, 10), body = sn ? { task_id: id, mode: sn } : { task_id: id, ymd: f.elements.ymd.value };
  NS.api('/ajax/calendar/move', body).then(function (j) { if (j.ok) NS.reloadWith(j.msg, j.undo); else NS.toast(j.msg || 'No se pudo mover la tarea.', { bad: true }); });
 });
 D.addEventListener('ns:undone', function () { location.reload(); });
 D.addEventListener('click', function (e) { $$('details[data-evm][open]', root).forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
 D.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('details[data-evm][open]', root).forEach(function (d) { d.removeAttribute('open'); var s = $('summary', d); if (s) s.focus(); }); });

 /* ---------- Teclado: moverse entre días y mover la tarea enfocada ---------- */
 var days = $$('[data-day]', root).map(function (a) { return a.getAttribute('data-day'); });
 function goDay(ymd) {
  var a = $('[data-day="' + ymd + '"]', root);
  if (a) { a.focus(); return; }
  var u = new URL(location.href); u.searchParams.set('d', ymd); u.searchParams.delete('nueva'); u.hash = 'f=' + ymd; location.href = u.toString();
 }
 var hm = /^#f=(\d{4}-\d{2}-\d{2})$/.exec(location.hash); if (hm) { var fa = $('[data-day="' + hm[1] + '"]', root); if (fa) { fa.focus(); try { history.replaceState(null, '', location.pathname + location.search); } catch (x) {} } }
 root.addEventListener('keydown', function (e) {
  if (e.ctrlKey || e.metaKey || e.shiftKey) return;
  var t = e.target, task = t.closest ? t.closest('[data-task]') : null, day = t.closest ? t.closest('[data-day]') : null, k = e.key;
  if (task && e.altKey && /^Arrow/.test(k) && canWrite) {
   var cell = task.closest('.cal__d'); if (!cell) return;
   var n = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[k]; if (mode !== 'mes' && Math.abs(n) === 7) n = n < 0 ? -1 : 1;
   e.preventDefault(); move(task, addDays(cell.getAttribute('data-ymd'), n)); return;
  }
  if (!day || e.altKey) return;
  var ymd = day.getAttribute('data-day'), nx = null;
  if (k === 'ArrowLeft') nx = addDays(ymd, -1); else if (k === 'ArrowRight') nx = addDays(ymd, 1);
  else if (k === 'ArrowUp') nx = addDays(ymd, mode === 'mes' ? -7 : -1); else if (k === 'ArrowDown') nx = addDays(ymd, mode === 'mes' ? 7 : 1);
  else if (k === 'Home') { var d0 = parse(ymd); nx = addDays(ymd, -((d0.getUTCDay() + 6) % 7)); } else if (k === 'End') { var d1 = parse(ymd); nx = addDays(ymd, 6 - ((d1.getUTCDay() + 6) % 7)); }
  else if (k === 'PageUp' || k === 'PageDown') { e.preventDefault(); var l = $(k === 'PageUp' ? '[data-cal-prev]' : '[data-cal-next]'); if (l) { var u = new URL(l.href, location.href); var d2 = parse(ymd), s = k === 'PageUp' ? -1 : 1; if (mode === 'mes') { d2.setUTCMonth(d2.getUTCMonth() + s); u.searchParams.set('d', fmt(d2)); u.hash = 'f=' + fmt(d2); } else { var tgt = addDays(ymd, s * (mode === 'semana' ? 7 : 1)); u.searchParams.set('d', tgt); u.hash = 'f=' + tgt; } location.href = u.toString(); } return; }
  if (nx) { e.preventDefault(); goDay(nx); }
 });
})();
