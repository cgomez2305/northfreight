/* NorthStar Freight — motion.js: coreografía GSAP 3.12 + ScrollTrigger (solo transform / opacity / clip-path / variables CSS).
   Hero al cargar, una orquestación por sección, pasos con trazo scrub, ticker, estrella/parallax, spotlight y botones magnéticos.
   Mejora progresiva: html.js-motion se añade solo si GSAP y ScrollTrigger existen y no hay reduced-motion; ante cualquier fallo
   se restaura todo (la página queda visible). El CSS nunca oculta nada sin esa clase. Utilidad de líneas: split.js. */
(function () {
  'use strict';
  var g = window.gsap, ST = window.ScrollTrigger, S = window.NSSplit, d = document, root = d.documentElement;
  var T0 = window.performance && performance.now ? performance.now() : 0;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || (rm && rm.matches)) return;
  try { g.registerPlugin(ST); } catch (e) { return; }
  root.classList.add('js-motion');

  var $ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };
  var GRID = /(^|\s)(g[2-6]h?|blog-grid|cards)(\s|$)/;
  var touched = [], hTouched = [], splits = [], hSplits = [], mm, heroDone = false, W = window.innerWidth, dead = false;
  var vh = function () { return window.innerHeight || root.clientHeight; };
  var num = function (a, b) { return a - b; };
  var show = function (e) { return e.getClientRects().length > 0; };
  function mark(list) { Array.prototype.push.apply(touched, list); return list; }
  function unsplit(e) { if (S) S.revert(e); }
  function lines(h, store) { // líneas enmascaradas solo si el titular es corto y la utilidad existe
    if (!S || !h || h.textContent.length > 90) return null;
    try { var l = S.split(h); if (l.length > 6) { S.revert(h); return null; } store.push(h); return l; } catch (e) { return null; }
  }

  function fail() { // todo visible, sin rastro
    if (dead) return; dead = true;
    try { if (mm) mm.revert(); ST.getAll().forEach(function (t) { t.kill(); }); var all = touched.concat(hTouched); g.killTweensOf(all); g.set(all, { clearProps: 'all' }); } catch (e) { /* nada */ }
    splits.concat(hSplits).forEach(unsplit);
    root.classList.remove('js-motion');
  }

  /* ---- a) Hero: timeline al cargar (≤1.3 s). Sin GSAP en <1 s no se anima. ---- */
  function hero(k) {
    var h = d.querySelector('[data-motion="hero"]') || d.querySelector('.hero');
    if (!h || heroDone || T0 > 1000) return;
    heroDone = true;
    var q = function (s) { return $(s, h); };
    var title = q('.hero__title, .home-hero__title')[0];
    var sw = q('.hero__main .swoosh')[0];
    var fades = q('.hero__eyebrow, .t-tagline, .hero__lead, .hero__ctas, .tags, .hero__aside > :not(.hero__media)');
    var pic = q('.hero__clip picture')[0], img = pic && pic.querySelector('img');
    var stars = q('.hero__star');
    var rows = q('.log__row'), times = q('.log__time'), finals = times.map(function (t) { return t.textContent; });
    var all = fades.concat(sw || [], rows, img || [], title || []);
    var ws = fades.filter(function (e) { return e.matches('.data-card, .video, [class*="tools-"]'); });
    Array.prototype.push.apply(hTouched, all.concat(pic || [], stars));
    try {
      if (fades.length) g.set(fades, { opacity: 0, y: 16 * k });
      if (ws.length) g.set(ws, { y: 28 * k });
      if (sw) g.set(sw, { clipPath: 'inset(0 100% 0 0)' });
      if (pic) g.set(pic, { clipPath: 'inset(10% 0% 0% 10%)' });
      if (img) g.set(img, { scale: 1.1 });
      if (stars.length) g.set(stars, { opacity: 0, '--sc': 0.5 });
      if (rows.length) { g.set(rows, { opacity: 0, y: 8 }); times.forEach(function (t) { t.textContent = ''; }); }
      if (title) g.set(title, { opacity: 0 });
    } catch (e) { fail(); return; }

    var started = false;
    function run() {
      if (started || dead) return; started = true;
      try {
        var ln = title ? lines(title, hSplits) : null;
        if (title) { if (ln) g.set(ln, { yPercent: 115 }); g.set(title, { opacity: 1 }); }
        var tl = g.timeline({
          defaults: { ease: 'expo.out' },
          onComplete: function () {
            g.set(all, { clearProps: 'opacity,transform,clipPath,visibility' });
            if (pic) g.set(pic, { clearProps: 'clipPath' });
            if (stars.length) g.set(stars, { clearProps: 'opacity' });
            stars.forEach(function (s) { s.style.removeProperty('--sc'); });
            if (title) unsplit(title);
            times.forEach(function (t, i) { t.textContent = finals[i]; });
          }
        });
        if (ln) tl.to(ln, { yPercent: 0, duration: 0.85 * k, stagger: 0.08 }, 0.05);
        else if (title) tl.fromTo(title, { opacity: 0, y: 20 * k }, { opacity: 1, y: 0, duration: 0.8 * k }, 0.05);
        if (sw) tl.to(sw, { clipPath: 'inset(0 0% 0 0)', duration: 0.6 * k, ease: 'power3.inOut' }, 0.4 * k);
        if (pic) tl.to(pic, { clipPath: 'inset(0% 0% 0% 0%)', duration: 1 * k }, 0.1).to(img, { scale: 1, duration: 1.1 * k }, 0.1);
        if (fades.length) tl.to(fades, { opacity: 1, y: 0, duration: 0.7 * k, stagger: 0.08 }, 0.1);
        if (stars.length) tl.to(stars, { opacity: 1, '--sc': 1, duration: 0.7 * k }, 0.7 * k);
        rows.forEach(function (r, i) {
          var o = { n: 0 }, f = finals[i], at = 0.5 + i * 0.2;
          tl.to(r, { opacity: 1, y: 0, duration: 0.4, ease: 'power3.out' }, at)
            .to(o, { n: f.length, duration: 0.35, ease: 'none', onUpdate: function () { times[i].textContent = f.slice(0, Math.round(o.n)); } }, at);
        });
      } catch (e) { fail(); }
    }
    var go = function () { run(); };
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(go); else go();
    setTimeout(go, 450);
  }

  /* ---- b) Secciones: una orquestación por bloque ---- */
  function bandOpen(sec) { // abre el corte diagonal de bandas con clip-path poligonal
    var cp = getComputedStyle(sec).clipPath, m = /^polygon\((.*)\)$/.exec(cp || '');
    if (!m) return null;
    var p = m[1].split(/,\s*(?![^(]*\))/);
    if (p.length < 3) return null;
    var a = p[0].split(' '), b = p[1].split(' ');
    if (a[1] === b[1]) return null;
    b[1] = a[1]; p[1] = b.join(' ');
    return { from: 'polygon(' + p.join(', ') + ')', to: cp };
  }
  function sections(k, mob) {
    $('main > section, body > .cta').forEach(function (sec) {
      if (sec.matches('.hero, [data-no-motion]') || sec.getBoundingClientRect().top < vh() * 0.9) return;
      var box = sec.querySelector('.container') || sec, heads = [], blocks = [], items = [];
      (function col(n, dp) {
        Array.prototype.slice.call(n.children).forEach(function (c) {
          if (c.matches('script, style, [data-no-motion], [data-motion="steps"]') || !show(c)) return;
          if (c.matches('.section-head') || c.querySelector(':scope > h1, :scope > h2, :scope > h3')) heads.push(c);
          else if (GRID.test(c.className || '') && c.children.length > 1) Array.prototype.push.apply(items, Array.prototype.filter.call(c.children, show));
          else if (dp < 2 && c.matches('.split, .stack')) col(c, dp + 1);
          else if (c.offsetHeight < vh() * 1.3) blocks.push(c);
        });
      })(box, 0);
      var band = bandOpen(sec), rest = [], tl = g.timeline({ paused: true, defaults: { ease: 'expo.out' }, onComplete: done });
      var txs = [], clean = [];
      function done() {
        g.set(clean, { clearProps: 'opacity,transform,clipPath,visibility' });
        txs.forEach(unsplit);
      }
      if (band) { clean.push(sec); mark([sec]); g.set(sec, { clipPath: band.from }); tl.to(sec, { clipPath: band.to, duration: 0.8 * k, ease: 'power3.inOut' }, 0); }
      heads.forEach(function (hd, hi) {
        var h = hd.querySelector('h1, h2, h3'), eb = hd.querySelector('.t-eyebrow'), sw = hd.querySelector('.swoosh'), at = 0.05 + hi * 0.1;
        var others = [];
        Array.prototype.slice.call(hd.children).forEach(function (c) {
          if (c.matches('.section-head__main')) Array.prototype.push.apply(others, Array.prototype.slice.call(c.children));
          else others.push(c);
        });
        others = others.filter(function (c) { return c !== eb && c !== h && c !== sw && !c.contains(h) && show(c); });
        var ln = h ? lines(h, splits) : null, mine = others.concat(eb || [], sw || [], h && !ln ? [h] : []);
        if (ln) txs.push(h);
        clean.push.apply(clean, mark(mine));
        if (eb) { g.set(eb, { opacity: 0, y: 10 * k }); tl.to(eb, { opacity: 1, y: 0, duration: 0.6 * k }, at); }
        if (ln) { g.set(ln, { yPercent: 115 }); tl.to(ln, { yPercent: 0, duration: 0.8 * k, stagger: 0.07 }, at + 0.05); }
        else if (h) { g.set(h, { opacity: 0, y: 20 * k }); tl.to(h, { opacity: 1, y: 0, duration: 0.7 * k }, at + 0.05); }
        if (sw) { g.set(sw, { clipPath: 'inset(0 100% 0 0)' }); tl.to(sw, { clipPath: 'inset(0 0% 0 0)', duration: 0.6 * k, ease: 'power3.inOut' }, at + 0.35 * k); }
        if (others.length) { g.set(others, { opacity: 0, y: 16 * k }); tl.to(others, { opacity: 1, y: 0, duration: 0.7 * k, stagger: 0.07 }, at + 0.25 * k); }
      });
      if (blocks.length) { clean.push.apply(clean, mark(blocks)); g.set(blocks, { opacity: 0, y: 24 * k }); tl.to(blocks, { opacity: 1, y: 0, duration: 0.8 * k, stagger: 0.07 }, 0.2); }
      if (items.length) {
        var xs = [], ys = [];
        items.forEach(function (e) {
          var l = Math.round(e.offsetLeft), t = Math.round(e.offsetTop);
          if (xs.indexOf(l) < 0) xs.push(l);
          if (ys.indexOf(t) < 0) ys.push(t);
        });
        xs.sort(num); ys.sort(num);
        clean.push.apply(clean, mark(items));
        items.forEach(function (e) {
          var wipe = !mob && getComputedStyle(e).clipPath === 'none', from = { opacity: 0, y: 24 * k }, to = { opacity: 1, y: 0, duration: 0.8 * k, ease: 'expo.out' };
          if (wipe) { from.clipPath = 'inset(0px -40px 100% -40px)'; to.clipPath = 'inset(0px -40px -40px -40px)'; }
          tl.fromTo(e, from, to, 0.25 + Math.min(0.5, xs.indexOf(Math.round(e.offsetLeft)) * 0.08 + ys.indexOf(Math.round(e.offsetTop)) * 0.06));
        });
      }
      if (!tl.getChildren().length) return;
      ST.create({ trigger: sec, start: 'top 85%', once: true, onEnter: function () { tl.play(); } });
    });
  }

  /* ---- c) Pasos: el trazo se dibuja con scrub (--p) y cada número se enciende (.is-on) ---- */
  function steps() {
    var undo = [];
    $('[data-motion="steps"]').forEach(function (ol) {
      var li = Array.prototype.slice.call(ol.children), n = li.length, vert = ol.classList.contains('sb-hw-steps'), thr = [];
      ol.classList.add('has-line');
      ol.style.setProperty('--p', '0');
      undo.push(function () { ol.classList.remove('has-line'); ol.style.removeProperty('--p'); li.forEach(function (e) { e.classList.remove('is-on'); e.style.removeProperty('--p'); }); });
      ST.create({
        trigger: ol, start: 'top 62%', end: 'bottom 62%', scrub: true,
        onRefresh: function () { thr = li.map(function (e, i) { return vert ? Math.min(0.98, (e.offsetTop + 32) / (ol.offsetHeight || 1)) : i / n; }); },
        onUpdate: function (s) {
          var p = s.progress;
          if (vert) ol.style.setProperty('--p', p.toFixed(3));
          li.forEach(function (e, i) {
            if (vert) e.classList.toggle('is-on', p >= (thr[i] || 0) && p > 0);
            else { var qv = Math.min(1, Math.max(0, p * n - i)); e.style.setProperty('--p', qv.toFixed(3)); e.classList.toggle('is-on', qv > 0.02); }
          });
        }
      });
    });
    return undo;
  }

  /* ---- f) Ticker: marquesina GSAP (misma velocidad base que el CSS) que acelera con la velocidad de scroll ---- */
  function ticker() {
    var tr = d.querySelector('.ticker__track');
    if (!tr) return;
    var tw = g.to(tr, { xPercent: -50, duration: 38, ease: 'none', repeat: -1 });
    ST.create({ trigger: tr.parentNode, start: 'top bottom', end: 'bottom top', onToggle: function (s) { tw.paused(!s.isActive); } });
    ST.create({
      start: 0, end: 'max',
      onUpdate: function (s) {
        var v = Math.min(3, 1 + Math.abs(s.getVelocity()) / 1500);
        if (v > tw.timeScale() + 0.05) { tw.timeScale(v); g.to(tw, { timeScale: 1, duration: 1.2, ease: 'power2.out', overwrite: true }); }
      }
    });
  }

  /* ---- g) Hero: estrella gira con el scroll; rejilla 0.3× e imagen 6 % solo en escritorio ---- */
  function parallax(desk) {
    var h = d.querySelector('[data-motion="hero"]') || d.querySelector('.hero');
    if (!h) return;
    var tr = { trigger: h, start: 'top top', end: 'bottom top', scrub: true };
    var stars = $('.hero__star, .hero__bigstar, .star-hero', h);
    g.fromTo(h, { '--p': 0 }, { '--p': 1, ease: 'none', scrollTrigger: tr }); // el CSS gira la estrella de .hero--stack::after con --p
    if (stars.length) g.fromTo(stars, { '--sr': '0deg' }, { '--sr': '30deg', ease: 'none', scrollTrigger: tr });
    if (!desk) return;
    var deco = h.querySelector('.hero__deco'), pic = h.querySelector('.hero__clip picture');
    if (deco) g.fromTo(deco, { y: 0 }, { y: function () { return h.offsetHeight * 0.3; }, ease: 'none', scrollTrigger: tr });
    if (pic) g.fromTo(pic, { scale: 1, yPercent: 0 }, { scale: 1.06, yPercent: -2.5, ease: 'none', scrollTrigger: tr });
  }

  /* ---- h) Spotlight en tarjetas oscuras y botones magnéticos (solo puntero fino) ---- */
  function pointerFx() {
    var SPOT = '.card--dark, .home-rcard, .card--glass, [data-spot]', raf = 0, ev = null, off = [];
    function move() {
      raf = 0;
      var t = ev && ev.target.closest ? ev.target.closest(SPOT) : null;
      if (!t) return;
      var r = t.getBoundingClientRect();
      t.style.setProperty('--mx', (ev.clientX - r.left).toFixed(0) + 'px');
      t.style.setProperty('--my', (ev.clientY - r.top).toFixed(0) + 'px');
    }
    var onMove = function (e) { if (e.pointerType !== 'mouse') return; ev = e; if (!raf) raf = requestAnimationFrame(move); };
    d.addEventListener('pointermove', onMove, { passive: true });
    off.push(function () { d.removeEventListener('pointermove', onMove); });
    $('.hero__ctas .btn, .cta .btn, .btn--lg, .btn--contact').forEach(function (b) {
      var qx, qy, tm;
      function enter(e) {
        if (e.pointerType !== 'mouse') return;
        clearTimeout(tm);
        if (!qx) { qx = g.quickTo(b, '--tx', { duration: 0.4, ease: 'power3' }); qy = g.quickTo(b, '--ty', { duration: 0.4, ease: 'power3' }); }
        b.classList.add('is-mag');
      }
      function mv(e) {
        if (e.pointerType !== 'mouse' || !qx) return;
        var r = b.getBoundingClientRect();
        qx(Math.max(-6, Math.min(6, (e.clientX - r.left - r.width / 2) * 0.12)));
        qy(Math.max(-6, Math.min(6, (e.clientY - r.top - r.height / 2) * 0.12)));
      }
      function lv() {
        if (!qx) return;
        qx(0); qy(0);
        tm = setTimeout(function () { b.classList.remove('is-mag'); b.style.removeProperty('--tx'); b.style.removeProperty('--ty'); }, 600);
      }
      b.addEventListener('pointerenter', enter); b.addEventListener('pointermove', mv); b.addEventListener('pointerleave', lv);
      off.push(function () { b.removeEventListener('pointerenter', enter); b.removeEventListener('pointermove', mv); b.removeEventListener('pointerleave', lv); b.classList.remove('is-mag'); b.style.removeProperty('--tx'); b.style.removeProperty('--ty'); });
    });
    return function () { off.forEach(function (f) { f(); }); };
  }

  /* ---- Orquestación por contexto: escritorio completo, móvil ×0.7 sin parallax ni magnéticos ---- */
  function build() {
    mm = g.matchMedia();
    mm.add({ desk: '(min-width: 1025px)', mob: '(max-width: 1024px)', fine: '(hover: hover) and (pointer: fine)' }, function (ctx) {
      var c = ctx.conditions, k = c.mob ? 0.7 : 1, undo = [];
      try {
        sections(k, c.mob);
        undo = steps();
        ticker();
        parallax(c.desk);
        if (c.desk && c.fine) undo.push(pointerFx());
      } catch (e) { fail(); }
      return function () { undo.forEach(function (f) { try { f(); } catch (e) { /* nada */ } }); };
    });
  }
  function rebuild() { // tras fuentes o cambio de ancho: sin tocar el hero
    if (dead) return;
    try { mm.revert(); splits.forEach(unsplit); splits.length = 0; g.set(touched, { clearProps: 'opacity,transform,clipPath,visibility' }); touched.length = 0; build(); ST.refresh(); } catch (e) { fail(); }
  }

  try {
    hero(window.innerWidth < 1025 ? 0.7 : 1);
    build();
    var tmr;
    window.addEventListener('resize', function () {
      if (window.innerWidth === W) return;
      W = window.innerWidth;
      clearTimeout(tmr); tmr = setTimeout(rebuild, 250);
    });
    if (d.fonts && d.fonts.status !== 'loaded' && d.fonts.ready) d.fonts.ready.then(rebuild);
    var rt;
    $('img[loading="lazy"]').forEach(function (i) { if (!i.complete) i.addEventListener('load', function () { clearTimeout(rt); rt = setTimeout(function () { ST.refresh(); }, 150); }); });
  } catch (e) { fail(); return; }

  if (rm && rm.addEventListener) {
    rm.addEventListener('change', function () { if (rm.matches) fail(); });
  }
})();
