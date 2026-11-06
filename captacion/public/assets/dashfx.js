/* NorthStar Captación · piezas visuales y de movimiento compartidas (Q6): las usan el dashboard (dashboard.js, administrador) y /resultados (resultados.js, lector de NorthStar).
   Sin librerías y SIN red: este archivo no hace ninguna petición a la API (el refresco del dashboard vive solo en dashboard.js). CSP: script externo con nonce; los estilos van por CSSOM o por Web Animations
   (nunca por atributo style). Solo anima transform, opacity y stroke-dashoffset. Sin él, ambas pantallas son idénticas y completas (todo viene ya en el HTML).
   Expone window.NS.fx. */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS = W.NS || {};
 if (NS.fx) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var reduce = !!(W.matchMedia && W.matchMedia('(prefers-reduced-motion: reduce)').matches);
 var canAnim = !reduce && typeof Element.prototype.animate === 'function';
 var EASE = 'cubic-bezier(.2,.8,.2,1)';

 /* ---------- hojas diferidas: la de ux.js si existe (administrador); si no, la propia (el lector no carga ux.js) ---------- */
 var cssP = {};
 function css(name, v) {
  if (NS.css) return NS.css(name, v);
  if (cssP[name]) return cssP[name];
  return (cssP[name] = new Promise(function (res) { var l = D.createElement('link'); l.rel = 'stylesheet'; l.href = '/assets/' + name + '.css' + (v ? '?v=' + v : ''); l.onload = l.onerror = function () { res(); }; D.head.appendChild(l); }));
 }

 /* ---------- animación ---------- */
 function anim(el, kf, o) { if (!canAnim || !el) return null; o = o || {}; o.fill = o.fill || 'backwards'; o.easing = o.easing || EASE; try { return el.animate(kf, o); } catch (e) { return null; } }
 function fadeUp(el, delay, dy) { anim(el, [{ opacity: 0, transform: 'translateY(' + (dy || 8) + 'px)' }, { opacity: 1, transform: 'none' }], { duration: 300, delay: delay || 0 }); }
 /* longitud en pantalla de un trazo con vector-effect: non-scaling-stroke dentro de un viewBox deformado (el guion se mide en píxeles de pantalla) */
 function screenLen(p, w, h) {
  var vb = p.ownerSVGElement.viewBox.baseVal, sx = w / vb.width, sy = h / vb.height, L = p.getTotalLength(), n = 80, len = 0, a = p.getPointAtLength(0);
  for (var i = 1; i <= n; i++) { var b = p.getPointAtLength(L * i / n); len += Math.hypot((b.x - a.x) * sx, (b.y - a.y) * sy); a = b; }
  return Math.ceil(len) + 2;
 }
 function draw(p, len, dur, delay) {
  if (!canAnim || !p) return;
  p.style.strokeDasharray = len;
  var a = anim(p, [{ strokeDashoffset: len }, { strokeDashoffset: 0 }], { duration: dur, delay: delay || 0 });
  if (a) a.onfinish = a.oncancel = function () { p.style.strokeDasharray = ''; };
 }
 function ticks(scope, delay) {
  $$('.tk', scope).forEach(function (tk) { $$('i.on', tk).forEach(function (t, k) { anim(t, [{ opacity: .15, transform: 'scaleY(.35)', transformOrigin: '50% 100%' }, { opacity: 1, transform: 'none', transformOrigin: '50% 100%' }], { duration: 220, delay: (delay || 0) + k * 15 }); }); });
 }
 function spark(scope, delay) {
  var l = $('.spark__line', scope); if (!l) return;
  draw(l, Math.ceil(l.getTotalLength()) + 2, 900, delay);
  anim($('.spark__area', scope), [{ opacity: 0 }, { opacity: 1 }], { duration: 500, delay: (delay || 0) + 200 });
  anim($('.spark__dot', scope), [{ opacity: 0 }, { opacity: 1 }], { duration: 200, delay: (delay || 0) + 850 });
 }
 /* conteo: la parte entera grande y los decimales y la unidad atenuados; al terminar se restituye el HTML del servidor (el texto accesible, en .sr, nunca cambia) */
 function parts(v, dec, suf) { var s = v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }), k = s.indexOf('.'); return [k >= 0 ? s.slice(0, k) : s, (k >= 0 ? s.slice(k) : '') + suf]; }
 function paint(n, v, dec, suf, pre) { var pr = parts(v, dec, suf); n.textContent = ''; n.appendChild(D.createTextNode((pre || '') + pr[0])); if (pr[1]) { var sm = D.createElement('small'); sm.textContent = pr[1]; n.appendChild(sm); } }
 function countTo(p, from, to, dur, delay) {
  var n = $('.kpi__n', p); if (!n || !canAnim || from === to) return;
  var dec = +p.getAttribute('data-dec') || 0, suf = p.getAttribute('data-suf') || '', pre = p.getAttribute('data-pre') || '', final = n.innerHTML, t0 = performance.now() + (delay || 0);
  paint(n, from, dec, suf, pre);
  (function tick(now) {
   var t = Math.max(0, Math.min((now - t0) / dur, 1)), e = 1 - Math.pow(1 - t, 3);
   if (t < 1) { paint(n, from + (to - from) * e, dec, suf, pre); requestAnimationFrame(tick); } else n.innerHTML = final;
  })(performance.now());
 }
 function flash(el) { if (!el || reduce) return; el.classList.remove('is-flash'); void el.offsetWidth; el.classList.add('is-flash'); setTimeout(function () { el.classList.remove('is-flash'); }, 700); }
 /* tarjeta KPI: entrada con fundido y 8 px, conteo, rayas y microlínea */
 function kpiIn(k, d) {
  fadeUp(k, d); var c = $('[data-count]', k); if (c) countTo(c, 0, +c.getAttribute('data-count'), 800, d + 100);
  ticks(k, d + 250); spark(k, d + 150);
 }
 /* barras horizontales (hbar) y segmentos que crecen desde la izquierda */
 function grow(els, base, step) {
  els.forEach(function (r, i) { anim(r, [{ transform: 'scaleX(0)', transformOrigin: '0 50%', transformBox: 'fill-box' }, { transform: 'none', transformOrigin: '0 50%', transformBox: 'fill-box' }], { duration: 600, delay: (base || 0) + i * (step == null ? 60 : step) }); });
 }
 /* filas con fundido y 4 px (como máximo 10) */
 function rowsIn(rows, base) {
  rows.slice(0, 10).forEach(function (r, i) { anim(r, [{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 280, delay: (base || 0) + i * 30 }); });
 }
 function once(el, fn) {
  if (!el) return;
  if (!('IntersectionObserver' in W)) return;
  var io = new IntersectionObserver(function (es) { if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); fn(); } }, { threshold: .12 });
  io.observe(el);
 }
 /* gráfico de líneas: las curvas se dibujan, el área aparece 200 ms después y las anotaciones y etiquetas al final */
 function drawChart(panel) {
  var lc = panel && $('.lc', panel); if (!lc || lc.getAttribute('data-drawn')) return; lc.setAttribute('data-drawn', '1');
  var plot = $('.lc__plot', lc), w = plot.clientWidth, h = plot.clientHeight;
  $$('.lc__line', lc).forEach(function (p, k) { draw(p, screenLen(p, w, h), 900, k * 120); });
  anim($('.lc__area', lc), [{ opacity: 0 }, { opacity: 1 }], { duration: 500, delay: 1100 });
  anim($('.lc__ov', lc), [{ opacity: 0 }, { opacity: 1 }], { duration: 400, delay: 800 });
 }

 /* ---------- pastilla deslizante de los segmentados ---------- */
 function pill(seg) {
  var el = D.createElement('i'); el.className = 'seg__pill'; el.setAttribute('aria-hidden', 'true'); seg.insertBefore(el, seg.firstChild); seg.classList.add('seg--pill');
  var place = function () { var on = $('.is-on', seg); if (!on) return; el.style.width = on.offsetWidth + 'px'; el.style.transform = 'translateX(' + on.offsetLeft + 'px)'; };
  el.style.transition = 'none'; place(); void el.offsetWidth; el.style.transition = '';
  seg.addEventListener('click', function () { requestAnimationFrame(place); });
  W.addEventListener('resize', place);
  if (D.fonts && D.fonts.ready) D.fonts.ready.then(function () { el.style.transition = 'none'; place(); void el.offsetWidth; el.style.transition = ''; });
  seg.__place = place;
 }

 /* ---------- gráfico: cruce, tooltip oscuro que sigue al cursor con suavizado y teclado ---------- */
 function chart(lc) {
  var cfg; try { cfg = JSON.parse(lc.getAttribute('data-chart')); } catch (e) { return; }
  var plot = $('.lc__plot', lc), tip = $('.lc__tip', lc), cross = $('.lc__cross', lc), dots = $$('.lc__dot', lc), n = cfg.labels.length, cur = -1, tx = 0, tgt = 0, raf = 0, shown = false;
  if (!plot || !tip || !cross || !n) return;
  tip.style.left = '0'; tip.style.top = '0'; tip.setAttribute('role', 'presentation');
  /* la variación del tooltip es la de la serie cfg.dk si existe; si no, la de la suma de todas */
  var total = function (i) { return cfg.dk != null && cfg.series[cfg.dk] ? (cfg.series[cfg.dk].v[i] || 0) : cfg.series.reduce(function (a, s) { return a + (s.v[i] || 0); }, 0); };
  function fill(i) {
   tip.textContent = ''; var b = D.createElement('b'); b.textContent = cfg.labels[i]; tip.appendChild(b);
   cfg.series.forEach(function (s) { var r = D.createElement('span'), sw = D.createElement('i'); sw.className = 'sw sw--' + s.c; r.appendChild(sw); r.appendChild(D.createTextNode(s.n)); var v = D.createElement('strong'); v.textContent = s.v[i]; r.appendChild(v); tip.appendChild(r); });
   var em = D.createElement('em'), d = i > 0 ? total(i) - total(i - 1) : null;
   em.textContent = d === null ? 'Primer periodo mostrado' : (d === 0 ? 'Sin cambio' : (d > 0 ? '+' : '−') + Math.abs(d) + (Math.abs(d) === 1 ? ' lead' : ' leads')) + (d === null ? '' : ' ' + (cfg.pv || 'frente al periodo anterior'));
   tip.appendChild(em);
  }
  function step() {
   raf = 0; var w = plot.clientWidth; tx += (tgt - tx) * (reduce ? 1 : .35);
   cross.style.transform = 'translateX(' + tx.toFixed(1) + 'px)';
   var tw = tip.offsetWidth, left = tx + 14; if (left + tw > w) left = tx - tw - 14;
   tip.style.transform = 'translate(' + Math.max(0, left).toFixed(1) + 'px,8px)';
   if (Math.abs(tgt - tx) > .4) raf = requestAnimationFrame(step); else tx = tgt;
  }
  function show(i, kb) {
   i = Math.max(0, Math.min(n - 1, i)); var w = plot.clientWidth, h = plot.clientHeight; cur = i; tgt = (i + .5) / n * w;
   cfg.series.forEach(function (s, k) { var y = h - (h - 6) * (s.v[i] / cfg.max) - 1; dots[k].hidden = false; dots[k].style.left = tgt + 'px'; dots[k].style.top = y + 'px'; });
   if (!shown) { tx = tgt; shown = true; cross.hidden = tip.hidden = false; }
   if (kb) tip.setAttribute('role', 'status');
   fill(i); if (!raf) raf = requestAnimationFrame(step);
  }
  function hide() { cur = -1; shown = false; cross.hidden = tip.hidden = true; dots.forEach(function (d) { d.hidden = true; }); tip.setAttribute('role', 'presentation'); }
  var at = function (e) { var r = plot.getBoundingClientRect(); return Math.floor((e.clientX - r.left) / r.width * n); };
  plot.addEventListener('pointermove', function (e) { show(at(e)); });
  plot.addEventListener('pointerdown', function (e) { show(at(e)); });
  plot.addEventListener('pointerleave', function () { if (D.activeElement !== plot) hide(); });
  plot.addEventListener('focus', function () { show(n - 1, true); });
  plot.addEventListener('blur', hide);
  plot.addEventListener('keydown', function (e) {
   var k = e.key, t = k === 'ArrowRight' ? cur + 1 : k === 'ArrowLeft' ? cur - 1 : k === 'Home' ? 0 : k === 'End' ? n - 1 : null;
   if (t === null) return; e.preventDefault(); show(t, true);
  });
 }

 NS.fx = { $: $, $$: $$, reduce: reduce, canAnim: canAnim, css: css, anim: anim, fadeUp: fadeUp, draw: draw, screenLen: screenLen, ticks: ticks, spark: spark, countTo: countTo, flash: flash, kpiIn: kpiIn, grow: grow, rowsIn: rowsIn, once: once, drawChart: drawChart, pill: pill, chart: chart };
})();
