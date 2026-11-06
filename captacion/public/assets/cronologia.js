/* NorthStar Captación · cronología (Q5c-D): «Cargar más» sin recargar la página. Sin JS el botón es un enlace a la siguiente página (?cur=). Sin librerías; sin estilos en línea (CSP). */
(function () {
 'use strict';
 var D = document, NS = window.NS, root = D.querySelector('[data-feed]');
 if (!NS || !NS.api || !root) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var days = $('[data-feed-days]', root), more = $('[data-feed-more]', root), live = $('[data-feed-live]', root), qs = root.getAttribute('data-qs') || '';
 if (!days || !more) return;
 var btn = $('[data-feed-next]', more);
 if (!btn) return;
 var label = btn.lastChild;
 btn.addEventListener('click', function (e) {
  if (e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
  e.preventDefault();
  var cursor = btn.getAttribute('data-feed-next');
  btn.setAttribute('aria-busy', 'true'); btn.classList.add('is-busy'); label.textContent = 'Cargando…';
  NS.api('/ajax/timeline', { qs: qs, cursor: cursor }).then(function (j) {
   btn.removeAttribute('aria-busy'); btn.classList.remove('is-busy'); label.textContent = 'Cargar más';
   if (!j.ok) { NS.toast(j.msg || 'No se pudo cargar más. Inténtalo de nuevo.', { bad: true }); return; }
   var t = D.createElement('template'); t.innerHTML = j.html;
   var first = null;
   $$('.feed__g', t.content).forEach(function (g) {
    var last = days.lastElementChild;
    if (last && last.getAttribute('data-day') === g.getAttribute('data-day')) {
     $$('.fe', g).forEach(function (li) { $('.feed__l', last).appendChild(li); if (!first) first = li; });
     var c = $('.feed__d small', last); if (c) c.textContent = String($$('.fe', last).length);
    } else { days.appendChild(g); if (!first) first = $('.fe', g); }
   });
   if (live) live.textContent = j.count + (j.count === 1 ? ' evento más cargado.' : ' eventos más cargados.');
   var l = first && $('a', first); if (l) l.focus();
   if (j.next) { btn.setAttribute('data-feed-next', j.next); btn.href = btn.href.replace(/([?&])cur=[^&]*/, '$1cur=' + encodeURIComponent(j.next)); }
   else { more.textContent = ''; var p = D.createElement('p'); p.className = 'muted'; p.textContent = 'Eso es todo lo que hay con estos filtros.'; more.appendChild(p); }
  });
 });
})();
