/* NorthStar Captación · panel. JS mínimo, sin dependencias (CSP: script externo con nonce; los estilos se ponen por CSSOM, no por atributo style). */
(function () {
 'use strict';
 var D = document, $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var CSRF = (D.currentScript && D.currentScript.getAttribute('data-csrf')) || '';
 var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
 $$('[data-mod]').forEach(function (k) { if (mac) k.textContent = '⌘'; });
 var openDd = null;
 function closeDd(focus) { if (!openDd) return; var b = $('button[aria-expanded]', openDd); b.setAttribute('aria-expanded', 'false'); $('.dd__panel', openDd).hidden = true; if (focus) b.focus(); openDd = null; }
 $$('[data-dd]').forEach(function (dd) {
 var b = $('button[aria-expanded]', dd), p = $('.dd__panel', dd);
 b.addEventListener('click', function () { var was = openDd === dd; closeDd(); if (!was) { p.hidden = false; b.setAttribute('aria-expanded', 'true'); openDd = dd; var f = $('a,button.dd__item', p); if (f && b.getAttribute('aria-haspopup') === 'menu') f.focus(); } });
 dd.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openDd === dd) { e.stopPropagation(); closeDd(true); } });
 });
 D.addEventListener('click', function (e) { if (openDd && !openDd.contains(e.target)) closeDd(); });

 function post(url, body) {
 return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
 }
 var H = D.documentElement;
 function applyTheme(p) {
 H.setAttribute('data-theme-pref', p);
 var dark = p === 'dark' || (p === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
 H.setAttribute('data-theme', dark ? 'dark' : 'light');
 try { localStorage.setItem('ns-theme', p); } catch (e) {}
 $$('[data-theme-set]').forEach(function (b) { var on = b.getAttribute('data-theme-set') === p; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
 var c = $('[data-theme-cycle]'); if (c) c.setAttribute('aria-label', 'Tema de color: ' + { light: 'claro', dark: 'oscuro', auto: 'automático' }[p] + '. Cambiar');
 }
 function setTheme(p) { applyTheme(p); if (CSRF) post('/ajax/prefs', { key: 'theme', value: p }); }
 window.NS = window.NS || {}; window.NS.post = post; window.NS.setTheme = setTheme; window.NS.csrf = CSRF; window.NS.$ = $; window.NS.$$ = $$;
 $$('[data-theme-set]').forEach(function (b) { b.addEventListener('click', function () { setTheme(b.getAttribute('data-theme-set')); }); });
 $$('[data-theme-cycle]').forEach(function (b) { b.addEventListener('click', function () { var cur = H.getAttribute('data-theme-pref') || 'auto'; setTheme({ auto: 'light', light: 'dark', dark: 'auto' }[cur]); }); });
 applyTheme(H.getAttribute('data-theme-pref') || 'auto');
 var side = $('#side'), lastOpener = null;
 function navOpen(btn) { lastOpener = btn; D.body.classList.add('nav-open'); $$('[data-nav-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); }); var f = $('a', side); if (f) f.focus(); }
 function navClose(back) { if (!D.body.classList.contains('nav-open')) return; D.body.classList.remove('nav-open'); $$('[data-nav-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); }); if (back && lastOpener) lastOpener.focus(); }
 $$('[data-nav-open]').forEach(function (b) { b.addEventListener('click', function () { navOpen(b); }); });
 $$('[data-nav-close]').forEach(function (b) { b.addEventListener('click', function () { navClose(true); }); });
 D.addEventListener('keydown', function (e) { if (e.key === 'Escape') navClose(true); });
 if (side) side.addEventListener('keydown', function (e) {
 if (e.key !== 'Tab' || !D.body.classList.contains('nav-open')) return;
 var f = $$('a,button', side).filter(function (x) { return x.offsetParent !== null; }), a = f[0], z = f[f.length - 1];
 if (e.shiftKey && D.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && D.activeElement === z) { e.preventDefault(); a.focus(); }
 });
 $$('[data-open]').forEach(function (b) { b.addEventListener('click', function () { var d = $(b.getAttribute('data-open')); if (d && d.showModal) { d.showModal(); var f = $('select,input:not([type=hidden]),textarea', d); if (f) f.focus(); } }); });
 $$('dialog').forEach(function (d) {
 $$('[data-close]', d).forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
 d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
 });
 var cd = $('#confirm'), pending = null;
 D.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-confirm]') : null; if (!b || !cd || !cd.showModal || b.disabled) return; e.preventDefault(); pending = b; $('#confirm-m').textContent = b.getAttribute('data-confirm'); cd.showModal(); $('#confirm-y').focus(); });
 if (cd) cd.addEventListener('close', function () { if (cd.returnValue === 'yes' && pending) { var f = pending.form; pending.removeAttribute('data-confirm'); if (f.requestSubmit) f.requestSubmit(pending); else f.submit(); } cd.returnValue = ''; pending = null; });
 $$('[data-toggle]').forEach(function (b) { b.addEventListener('click', function () { var t = $(b.getAttribute('data-toggle')); if (!t) return; var show = t.hidden || (t.classList.contains('filters') && !t.classList.contains('is-open')); if (t.classList.contains('filters')) t.classList.toggle('is-open', show); else t.hidden = !show; b.setAttribute('aria-expanded', show ? 'true' : 'false'); if (show) { var f = $('textarea,input:not([type=hidden])', t); if (f) f.focus(); } }); });
 $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
 $$('[data-tabs]').forEach(function (root) {
 var tabs = $$('[role=tab]', root);
 tabs.forEach(function (t) {
 t.addEventListener('click', function () {
 tabs.forEach(function (x) { var on = x === t; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); var p = D.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on; });
 var tt = $('[data-chart-total]', root), dl = $('[data-chart-delta]', root), sub = $('.chart__top .muted', root);
 if (tt) tt.textContent = t.getAttribute('data-total'); if (sub) sub.textContent = 'Total ' + t.getAttribute('data-sub'); if (dl) dl.hidden = t.getAttribute('data-delta') !== '1';
 });
 t.addEventListener('keydown', function (e) { var i = tabs.indexOf(t), n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : -1; if (n >= 0 && tabs[n]) { e.preventDefault(); tabs[n].focus(); tabs[n].click(); } });
 });
 });
 $$('.lc').forEach(function (lc) {
 var cfg; try { cfg = JSON.parse(lc.getAttribute('data-chart')); } catch (e) { return; }
 var plot = $('.lc__plot', lc), band = $('.lc__band', lc), tip = $('.lc__tip', lc), dots = $$('.lc__dot', lc), n = cfg.labels.length, cur = -1;
 function show(i) {
 i = Math.max(0, Math.min(n - 1, i)); cur = i; var w = plot.clientWidth, h = plot.clientHeight, x = (i + 0.5) / n * w;
 band.hidden = tip.hidden = false; band.style.width = (w / n) + 'px'; band.style.left = (i / n * w) + 'px';
 cfg.series.forEach(function (s, k) { var y = h - (h - 6) * (s.v[i] / cfg.max) - 1; dots[k].hidden = false; dots[k].style.left = x + 'px'; dots[k].style.top = y + 'px'; });
 tip.textContent = ''; var b = D.createElement('b'); b.textContent = cfg.labels[i]; tip.appendChild(b);
 cfg.series.forEach(function (s) { var r = D.createElement('span'), sw = D.createElement('i'); sw.className = 'sw sw--' + s.c; r.appendChild(sw); r.appendChild(D.createTextNode(s.n)); var v = D.createElement('strong'); v.textContent = s.v[i]; r.appendChild(v); tip.appendChild(r); });
 var tw = tip.offsetWidth; var left = x + 14; if (left + tw > w) left = x - tw - 14; tip.style.left = Math.max(0, left) + 'px'; tip.style.top = '8px';
 }
 function hide() { cur = -1; band.hidden = tip.hidden = true; dots.forEach(function (d) { d.hidden = true; }); }
 plot.addEventListener('pointermove', function (e) { var r = plot.getBoundingClientRect(); show(Math.floor((e.clientX - r.left) / r.width * n)); });
 plot.addEventListener('pointerleave', function () { if (D.activeElement !== plot) hide(); });
 plot.addEventListener('pointerdown', function (e) { var r = plot.getBoundingClientRect(); show(Math.floor((e.clientX - r.left) / r.width * n)); });
 plot.addEventListener('focus', function () { show(n - 1); });
 plot.addEventListener('blur', hide);
 plot.addEventListener('keydown', function (e) { if (e.key === 'ArrowRight') { e.preventDefault(); show(cur + 1); } else if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur - 1); } else if (e.key === 'Home') { e.preventDefault(); show(0); } else if (e.key === 'End') { e.preventDefault(); show(n - 1); } });
 });
 $$('[data-heat]').forEach(function (h) {
 var cap = $('[data-heat-cap]', h), def = cap.textContent;
 $$('rect', h).forEach(function (r) {
 var set = function () { var n = r.getAttribute('data-n'); cap.textContent = r.getAttribute('data-d') + ' · ' + n + (n === '1' ? ' lead' : ' leads'); };
 r.addEventListener('pointerenter', set); r.addEventListener('pointerdown', set); r.addEventListener('pointerleave', function () { cap.textContent = def; });
 });
 });
 var bulk = $('[data-bulk]');
 if (bulk && $('[data-bulkbar]', bulk)) {
 var boxes = $$('input[name="ids[]"]', bulk), all = $('[data-all]', bulk), bar = $('[data-bulkbar]', bulk), cnt = $('[data-count]', bulk), bs = $('[data-bulk-stage]', bulk), br = $('[data-bulk-reason]', bulk);
 var upd = function () { var n = boxes.filter(function (b) { return b.checked; }).length; bar.hidden = n === 0; cnt.textContent = n; if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; } };
 boxes.forEach(function (b) { b.addEventListener('change', upd); });
 if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); upd(); });
 $('[data-clear]', bulk).addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); upd(); });
 bs.addEventListener('change', function () { br.hidden = bs.value !== 'lost'; br.required = bs.value === 'lost'; });
 var per = $('[data-per]');
 if (per) per.addEventListener('change', function () { var u = new URL(location.href); u.searchParams.set('per', per.value); u.searchParams.delete('page'); location.href = u.toString(); });
 }
 var sf = $('[data-stage-form]');
 if (sf) {
 var ov = {}; try { ov = JSON.parse(sf.getAttribute('data-override')); } catch (e) {}
 var st = $('[data-stage]', sf), rs = $('[data-reason]', sf), ob = $('[data-override-box]', sf);
 var sync = function () { var lost = st.value === 'lost'; rs.hidden = !lost; $('select', rs).required = lost; ob.hidden = !ov[st.value]; $('input', ob).required = !!ov[st.value]; };
 st.addEventListener('change', sync); sync();
 }
 $$('[data-tl]').forEach(function (tl) {
 var bs = $$('[data-g]', $('.seg', tl)), items = $$('.tl__i', tl);
 bs.forEach(function (b) { b.addEventListener('click', function () { var g = b.getAttribute('data-g'); bs.forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); }); items.forEach(function (i) { i.hidden = g !== 'all' && i.getAttribute('data-g') !== g; }); }); });
 });
 var ck = $('[data-clock]');
 if (ck && window.Intl) { var f = new Intl.DateTimeFormat('en-GB', { timeZone: ck.getAttribute('data-tz') || 'America/New_York', hour: '2-digit', minute: '2-digit', hour12: false }); var tick = function () { ck.textContent = f.format(new Date()); }; tick(); setInterval(tick, 20000); }
})();
