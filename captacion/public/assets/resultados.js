/* NorthStar Captación · /resultados (Q6, fase I), vista del lector de NorthStar. Sin librerías y SIN red: no hace ninguna petición (no hay refresco ni endpoint: es un informe por rango, no un tablero en vivo) ni lee nada que no esté ya
   en la página, que solo trae agregados. Reutiliza las piezas de dashfx.js (conteo, rayas, microlínea, línea que se dibuja, cruce con tooltip). CSP: script externo con nonce; estilos solo por CSSOM o Web Animations.
   Sin este archivo, o con prefers-reduced-motion, la pantalla es idéntica y completa (todo viene ya en el HTML). */
(function () {
 'use strict';
 var D = document, NS = window.NS, fx = NS && NS.fx, root = D.querySelector('[data-rs]');
 if (!fx || !root) return;
 var $ = fx.$, $$ = fx.$$, once = fx.once;
 fx.css('dashlive', root.getAttribute('data-live-v'));   // tooltips oscuros del gráfico, pulso y esqueletos: hoja diferida

 /* gráfico: cruce con tooltip (ratón, toque y teclado) */
 $$('.lc[data-lc2]').forEach(fx.chart);

 /* entrada coreografiada, una sola vez: tarjetas con 50 ms de desfase (conteo, rayas y microlínea), y cada bloque al entrar en pantalla */
 $$('.kpi', root.parentNode).forEach(function (k, i) { fx.kpiIn(k, i * 50); });
 var fn = $('[data-rs-fn]'), tr = $('[data-rs-tr]'), ch = $('[data-rs-ch]'), ls = $('[data-rs-ls]');
 once(fn, function () { fx.rowsIn($$('.fn__r', fn)); fx.grow($$('.hb__fg', fn), 100, 70); });
 once(tr, function () { fx.drawChart(tr); });
 once(ch, function () { fx.rowsIn($$('.tbl--rep tbody tr', ch)); });
 once(ls, function () { fx.rowsIn($$('.fn__r', ls)); fx.grow($$('.hb__fg', ls), 100, 70); });
})();
