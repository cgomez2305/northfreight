/* NorthStar Captación · dashboard vivo (Q6), solo administrador. Sin librerías. CSP: script externo con nonce; los estilos van por CSSOM o por Web Animations (nunca por atributo style). Solo anima transform, opacity y stroke-dashoffset.
   Las piezas visuales (conteo, rayas, microlínea, línea que se dibuja, cruce con tooltip, pastilla) viven en dashfx.js, compartido con /resultados; aquí queda la coreografía de ESTA pantalla y el refresco cada 60 s con agregados (POST /ajax/dashboard).
   Sin estos archivos el dashboard es idéntico y completo (todo viene ya en el HTML). */
(function () {
 'use strict';
 var D = document, W = window, NS = W.NS, fx = NS && NS.fx, root = D.querySelector('[data-dash]');
 if (!NS || !NS.api || !fx || !root) return;
 var $ = fx.$, $$ = fx.$$, reduce = fx.reduce, anim = fx.anim, fadeUp = fx.fadeUp, ticks = fx.ticks, spark = fx.spark, countTo = fx.countTo, flash = fx.flash, kpiIn = fx.kpiIn, once = fx.once, drawChart = fx.drawChart;
 var period = root.getAttribute('data-period') || 'today';
 var cssReady = fx.css('dashlive', root.getAttribute('data-live-v'));
 function actsIn(scope, base) { $$('.act,.act-ok', scope).forEach(function (a, i) { fadeUp(a, base + i * 60); }); ticks(scope, base + 150); }
 function enter() {
  $$('.kpi', kpisEl).forEach(function (k, i) { kpiIn(k, i * 50); });
  actsIn($('.acts'), 200);
  var chart = $('[aria-labelledby="h-chart"]');
  once(chart, function () { drawChart($('[role=tabpanel]:not([hidden])', chart)); var st = $('.strip', chart); ticks(st, 0); });
  once($('[aria-labelledby="h-src"]'), function () {
   fx.grow($$('.segbar rect'), 0);
   fx.grow($$('.srclist .hb__fg'), 150);
  });
  once($('[aria-labelledby="h-recent"]'), function () { fx.rowsIn($$('.tbl--dash tbody tr')); });
  once($('[aria-labelledby="h-heat"]'), function () { $$('.heat circle').forEach(function (c) { anim(c, [{ opacity: 0 }, { opacity: 1 }], { duration: 260, delay: Math.floor(+c.getAttribute('cx') / 24) * 25 }); }); });
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
 $$('.lc[data-lc2]').forEach(fx.chart);
 $$('[role=tab]', root.parentNode).forEach(function (t) { t.addEventListener('click', function () { setTimeout(function () { drawChart(D.getElementById(t.getAttribute('aria-controls'))); }, 30); }); });
 cssReady.then(function () { $$('.seg[data-pill]').forEach(fx.pill); });
 enter();
 schedule();
})();
