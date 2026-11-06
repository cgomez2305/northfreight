/* NorthStar Captación · dashboard vivo (Q6). Sin librerías. CSP: script externo con nonce; los estilos van por CSSOM o por Web Animations (nunca por atributo style). Solo anima transform, opacity y stroke-dashoffset.
   Sin este archivo el dashboard es idéntico y completo (todo viene ya en el HTML): aquí solo se anima la entrada, se afina el gráfico (cruce con tooltip, teclado) y se refresca el dashboard cada 60 s con agregados (POST /ajax/dashboard). */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS, root = D.querySelector('[data-dash]');
 if (!NS || !NS.api || !root) return;
 var $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var reduce = !!(W.matchMedia && W.matchMedia('(prefers-reduced-motion: reduce)').matches);
 var canAnim = !reduce && typeof Element.prototype.animate === 'function';
 var EASE = 'cubic-bezier(.2,.8,.2,1)';
 var period = root.getAttribute('data-period') || 'today';
 var cssReady = NS.css ? NS.css('dashlive', root.getAttribute('data-live-v')) : Promise.resolve();

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
 function paint(n, v, dec, suf) { var pr = parts(v, dec, suf); n.textContent = ''; n.appendChild(D.createTextNode(pr[0])); if (pr[1]) { var sm = D.createElement('small'); sm.textContent = pr[1]; n.appendChild(sm); } }
 function countTo(p, from, to, dur, delay) {
  var n = $('.kpi__n', p); if (!n || !canAnim || from === to) return;
  var dec = +p.getAttribute('data-dec') || 0, suf = p.getAttribute('data-suf') || '', final = n.innerHTML, t0 = performance.now() + (delay || 0);
  paint(n, from, dec, suf);
  (function tick(now) {
   var t = Math.max(0, Math.min((now - t0) / dur, 1)), e = 1 - Math.pow(1 - t, 3);
   if (t < 1) { paint(n, from + (to - from) * e, dec, suf); requestAnimationFrame(tick); } else n.innerHTML = final;
  })(performance.now());
 }
 function flash(el) { if (!el || reduce) return; el.classList.remove('is-flash'); void el.offsetWidth; el.classList.add('is-flash'); setTimeout(function () { el.classList.remove('is-flash'); }, 700); }
 function kpiIn(k, d) {
  fadeUp(k, d); var c = $('[data-count]', k); if (c) countTo(c, 0, +c.getAttribute('data-count'), 800, d + 100);
  ticks(k, d + 250); spark(k, d + 150);
 }
 function actsIn(scope, base) { $$('.act,.act-ok', scope).forEach(function (a, i) { fadeUp(a, base + i * 60); }); ticks(scope, base + 150); }
 function once(el, fn) {
  if (!el) return;
  if (!('IntersectionObserver' in W)) return;
  var io = new IntersectionObserver(function (es) { if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); fn(); } }, { threshold: .12 });
  io.observe(el);
 }
 function drawChart(panel) {
  var lc = panel && $('.lc', panel); if (!lc || lc.getAttribute('data-drawn')) return; lc.setAttribute('data-drawn', '1');
  var plot = $('.lc__plot', lc), w = plot.clientWidth, h = plot.clientHeight;
  $$('.lc__line', lc).forEach(function (p, k) { draw(p, screenLen(p, w, h), 900, k * 120); });
  anim($('.lc__area', lc), [{ opacity: 0 }, { opacity: 1 }], { duration: 500, delay: 1100 });
  anim($('.lc__ov', lc), [{ opacity: 0 }, { opacity: 1 }], { duration: 400, delay: 800 });
 }
 function enter() {
  $$('.kpi', kpisEl).forEach(function (k, i) { kpiIn(k, i * 50); });
  actsIn($('.acts'), 200);
  var chart = $('[aria-labelledby="h-chart"]');
  once(chart, function () { drawChart($('[role=tabpanel]:not([hidden])', chart)); var st = $('.strip', chart); ticks(st, 0); });
  once($('[aria-labelledby="h-src"]'), function () {
   $$('.segbar rect').forEach(function (r, i) { anim(r, [{ transform: 'scaleX(0)', transformOrigin: '0 50%', transformBox: 'fill-box' }, { transform: 'none', transformOrigin: '0 50%', transformBox: 'fill-box' }], { duration: 600, delay: i * 60 }); });
   $$('.srclist .hb__fg').forEach(function (r, i) { anim(r, [{ transform: 'scaleX(0)', transformOrigin: '0 50%', transformBox: 'fill-box' }, { transform: 'none', transformOrigin: '0 50%', transformBox: 'fill-box' }], { duration: 600, delay: 150 + i * 60 }); });
  });
  once($('[aria-labelledby="h-recent"]'), function () { $$('.tbl--dash tbody tr').slice(0, 10).forEach(function (r, i) { anim(r, [{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 280, delay: i * 30 }); }); });
  once($('[aria-labelledby="h-heat"]'), function () { $$('.heat circle').forEach(function (c) { anim(c, [{ opacity: 0 }, { opacity: 1 }], { duration: 260, delay: Math.floor(+c.getAttribute('cx') / 24) * 25 }); }); });
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
  var total = function (i) { return cfg.series.reduce(function (a, s) { return a + (s.v[i] || 0); }, 0); };
  function fill(i) {
   tip.textContent = ''; var b = D.createElement('b'); b.textContent = cfg.labels[i]; tip.appendChild(b);
   cfg.series.forEach(function (s) { var r = D.createElement('span'), sw = D.createElement('i'); sw.className = 'sw sw--' + s.c; r.appendChild(sw); r.appendChild(D.createTextNode(s.n)); var v = D.createElement('strong'); v.textContent = s.v[i]; r.appendChild(v); tip.appendChild(r); });
   var em = D.createElement('em'), d = i > 0 ? total(i) - total(i - 1) : null;
   em.textContent = d === null ? 'Primer periodo mostrado' : (d === 0 ? 'Sin cambio' : (d > 0 ? '+' : '−') + Math.abs(d) + (Math.abs(d) === 1 ? ' lead' : ' leads')) + (d === null ? '' : ' frente al periodo anterior');
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

 /* ---------- refresco ligero cada 60 s (solo agregados) y cambio de periodo sin recargar ---------- */
 var kpisEl = $('[data-live="kpis"]'), actsEl = $('[data-live="actions"]'), stamp = $('[data-live="stamp"]'), liveEl = $('.live'), errEl = $('.live__err'), lastOk = Date.now(), timer = 0, fails = 0, busy = false, loadT = 0;
 function ago() {
  if (!stamp) return; var s = Math.max(0, Math.round((Date.now() - lastOk) / 1000));
  stamp.textContent = 'Actualizado ' + (s < 5 ? 'ahora' : s < 60 ? 'hace ' + s + ' s' : 'hace ' + Math.floor(s / 60) + ' min');
 }
 function tpl(html) { var t = D.createElement('template'); t.innerHTML = html; return t.content; }
 function applyKpis(html) {
  var fresh = $$('.kpi', tpl(html));
  fresh.forEach(function (neu) {
   var k = neu.getAttribute('data-k'), old = $('.kpi[data-k="' + k + '"]', kpisEl); if (!old) return;
   var oc = $('[data-count]', old), nc = $('[data-count]', neu), from = oc ? +oc.getAttribute('data-count') : null, to = nc ? +nc.getAttribute('data-count') : null;
   var od = $('.kpi__f .delta', old), nd = $('.kpi__f .delta', neu), changed = !od || !nd || od.textContent !== nd.textContent;
   var av = $('.avs', old), nf = $('.kpi__f', neu); if (av && nf && !$('.avs', neu) && $('.delta', nf) && +(nc ? nc.getAttribute('data-count') : 0) > 0) nf.appendChild(av);
   if (old.innerHTML === neu.innerHTML) return;
   old.className = neu.className; old.innerHTML = ''; while (neu.firstChild) old.appendChild(neu.firstChild);
   var c = $('[data-count]', old); if (c && from !== null && to !== null) countTo(c, from, to, 700, 0);
   if (changed) flash($('.kpi__f .delta', old));
   ticks(old, 100); spark(old, 0);
  });
 }
 function applyHeadline(html) {
  var neu = $('.sub--n', tpl(html)), old = $('.sub--n', root); if (!neu || !old || old.innerHTML === neu.innerHTML) return;
  old.innerHTML = neu.innerHTML; anim(old, [{ opacity: 0 }, { opacity: 1 }], { duration: 300 });
 }
 function applyActions(html) {
  if (!actsEl) return; var body = tpl(html), box = D.createElement('div'); box.appendChild(body.cloneNode(true));
  var norm = function (h) { return h.replace(/\s+/g, ''); }; if (norm(actsEl.innerHTML) === norm(box.innerHTML)) return;
  var titles = function (r) { return $$('.act__t', r).map(function (x) { return x.textContent; }).join('|'); }, changed = titles(actsEl) !== titles(box);
  actsEl.textContent = ''; actsEl.appendChild(body); if (changed) actsIn(actsEl, 0);
 }
 function syncLinks(p) {
  $$('.pg a[href]').forEach(function (a) { var u = new URL(a.getAttribute('href'), location.href); if (p === 'today') u.searchParams.delete('p'); else u.searchParams.set('p', p); a.setAttribute('href', u.pathname + u.search); });
  var f = $('.minisearch'), i = f && $('input[name=p]', f); if (f) { if (p === 'today') { if (i) i.remove(); } else if (i) i.value = p; else { i = D.createElement('input'); i.type = 'hidden'; i.name = 'p'; i.value = p; f.insertBefore(i, f.firstChild); } }
  var x = $('form[action="/leads/export"] input[name=period]'); if (x) x.value = p;
 }
 function setErr(on, msg) {
  if (liveEl) liveEl.classList.toggle('is-err', on);
  if (errEl) { errEl.hidden = !on; if (on && msg) { var t = errEl.firstChild; if (t && t.nodeType === 3) t.nodeValue = msg + ' '; } }
 }
 function load(p, manual) {
  if (busy) return Promise.resolve(false); busy = true;
  clearTimeout(loadT); loadT = setTimeout(function () { [kpisEl, actsEl].forEach(function (x) { if (x) x.classList.add('is-loading'); }); }, 350);
  var timeout = new Promise(function (res) { setTimeout(function () { res({ ok: false, status: 0, msg: 'El dashboard tardó demasiado en actualizarse.' }); }, 9000); });
  return Promise.race([NS.api('/ajax/dashboard', { p: p }), timeout]).then(function (j) {
   busy = false; clearTimeout(loadT); [kpisEl, actsEl].forEach(function (x) { if (x) x.classList.remove('is-loading'); });
   if (!j || !j.ok) { fails++; setErr(true, j && j.status === 401 ? 'Tu sesión caducó.' : (j && j.msg) || 'No se pudo actualizar el dashboard.'); return false; }
   fails = 0; setErr(false); lastOk = Date.now(); ago(); period = j.p; root.setAttribute('data-period', j.p);
   applyKpis(j.kpis); applyHeadline(j.headline); applyActions(j.actions); syncLinks(j.p);
   return true;
  });
 }
 function schedule() {
  clearTimeout(timer); if (D.hidden) return;
  timer = setTimeout(function () { load(period, false).then(schedule); }, Math.min(300000, 60000 * Math.pow(2, Math.min(fails, 3))));
 }
 function setActive(p) {
  $$('[data-p]', root).forEach(function (a) { var on = a.getAttribute('data-p') === p; a.classList.toggle('is-on', on); if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
  var seg = $('.seg[data-pill]', root); if (seg && seg.__place) requestAnimationFrame(seg.__place);
 }
 function url(p) { return p === 'today' ? location.pathname : location.pathname + '?p=' + p; }
 function switchTo(p, push) {
  if (p === period && push) return; var prev = period; setActive(p);
  load(p, true).then(function (ok) { if (!ok) { setActive(prev); if (push) location.href = url(p); return; } if (push) history.pushState({ p: p }, '', url(p) + location.hash); schedule(); });
 }
 $$('[data-p]', root).forEach(function (a) { a.addEventListener('click', function (e) { if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button) return; e.preventDefault(); switchTo(a.getAttribute('data-p'), true); }); });
 W.addEventListener('popstate', function () { var p = new URLSearchParams(location.search).get('p'); switchTo(['week', 'month'].indexOf(p) >= 0 ? p : 'today', false); });
 var retry = errEl && $('[data-retry]', errEl); if (retry) retry.addEventListener('click', function () { load(period, true).then(schedule); });
 D.addEventListener('visibilitychange', function () { if (D.hidden) { clearTimeout(timer); return; } if (Date.now() - lastOk > 60000) load(period, false).then(schedule); else schedule(); });
 setInterval(ago, 5000);

 /* ---------- arranque ---------- */
 $$('.lc[data-lc2]').forEach(chart);
 $$('[role=tab]', root.parentNode).forEach(function (t) { t.addEventListener('click', function () { setTimeout(function () { drawChart(D.getElementById(t.getAttribute('aria-controls'))); }, 30); }); });
 cssReady.then(function () { $$('.seg[data-pill]').forEach(pill); });
 enter();
 schedule();
})();
