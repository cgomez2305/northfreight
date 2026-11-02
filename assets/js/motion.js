/* NorthStar Freight — motion.js: coreografía GSAP 3.12 + ScrollTrigger (solo transform, opacity, clip-path y variables CSS).
   html.js-motion se añade solo con GSAP + ScrollTrigger y sin reduced-motion; ante un fallo se restaura todo. Líneas: split.js. */
(function () {
  'use strict';
  var g = window.gsap, ST = window.ScrollTrigger, S = window.NSSplit, d = document, root = d.documentElement;
  var T0 = window.performance && performance.now ? performance.now() : 0;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || (rm && rm.matches)) return;
  try { g.registerPlugin(ST); } catch (e) { return; }
  root.classList.add('js-motion');

  var A = Array.prototype, CP = 'opacity,transform,clipPath,visibility', GRID = /(^|\s)(g[2-6]h?|blog-grid|cards)(\s|$)/;
  var $ = function (s, r) { return A.slice.call((r || d).querySelectorAll(s)); };
  var show = function (e) { return e.getClientRects().length > 0; };
  var vh = function () { return window.innerHeight || root.clientHeight; };
  var tch = [], hTch = [], spl = [], hSpl = [], mm, heroDone = false, W = window.innerWidth, dead = false;
  var mark = function (l) { A.push.apply(tch, l); return l; };
  var unsplit = function (e) { if (S) S.revert(e); };
  var DRAW0 = 'inset(0 100% 0 0)', DRAW1 = 'inset(0 0% 0 0)';

  function lines(h, store) { // máscara por líneas solo en titulares cortos
    if (!S || !h || h.textContent.length > 90) return null;
    try { var l = S.split(h); if (l.length > 6) { S.revert(h); return null; } store.push(h); return l; } catch (e) { return null; }
  }
  function fail() { // todo visible, sin rastro
    if (dead) return; dead = true;
    try { if (mm) mm.revert(); ST.getAll().forEach(function (t) { t.kill(); }); var all = tch.concat(hTch); g.killTweensOf(all); g.set(all, { clearProps: 'all' }); } catch (e) { /* nada */ }
    spl.concat(hSpl).forEach(unsplit);
    root.classList.remove('js-motion');
  }

  /* a) Hero al cargar (≤1.3 s). El lead solo se desliza (candidato a LCP). Si GSAP tarda >1 s no se anima. */
  function hero(k) {
    var h = d.querySelector('[data-motion="hero"]') || d.querySelector('.hero');
    if (!h || heroDone || T0 > 1000) return;
    heroDone = true;
    var q = function (s) { return $(s, h); };
    var title = q('.hero__title, .home-hero__title')[0], sw = q('.hero__main .swoosh')[0], lead = q('.hero__lead');
    var fades = q('.hero__eyebrow, .t-tagline, .hero__ctas, .tags, .hero__aside > :not(.hero__media)');
    var pic = q('.hero__clip picture')[0], img = pic && pic.querySelector('img'), stars = q('.hero__star');
    var rows = q('.log__row'), times = q('.log__time'), finals = times.map(function (t) { return t.textContent; });
    var all = fades.concat(lead, sw || [], rows, img || [], title || []);
    A.push.apply(hTch, all.concat(pic || [], stars));
    try {
      if (fades.length) g.set(fades, { opacity: 0, y: 16 * k });
      var big = fades.filter(function (e) { return e.matches('.data-card, .video, [class*="tools-"]'); });
      if (big.length) g.set(big, { y: 28 * k });
      if (lead.length) g.set(lead, { y: 14 * k });
      if (sw) g.set(sw, { clipPath: DRAW0 });
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
        var ln = title ? lines(title, hSpl) : null;
        if (title) { if (ln) g.set(ln, { yPercent: 130 }); g.set(title, { opacity: 1 }); }
        var tl = g.timeline({
          defaults: { ease: 'expo.out' },
          onComplete: function () { // el titular conserva .ln: restaurar el HTML crearía nodos nuevos y retrasaría el LCP
            g.set(all, { clearProps: CP });
            if (ln) g.set(ln, { clearProps: 'transform' });
            if (pic) g.set(pic, { clearProps: 'clipPath' });
            if (stars.length) g.set(stars, { clearProps: 'opacity' });
            stars.forEach(function (s) { s.style.removeProperty('--sc'); });
            times.forEach(function (t, i) { t.textContent = finals[i]; });
          }
        });
        if (ln) tl.to(ln, { yPercent: 0, duration: 0.85 * k, stagger: 0.08 }, 0.05);
        else if (title) tl.fromTo(title, { opacity: 0, y: 20 * k }, { opacity: 1, y: 0, duration: 0.8 * k }, 0.05);
        if (sw) tl.to(sw, { clipPath: DRAW1, duration: 0.6 * k, ease: 'power3.inOut' }, 0.4 * k);
        if (pic) tl.to(pic, { clipPath: 'inset(0% 0% 0% 0%)', duration: k }, 0.1).to(img, { scale: 1, duration: 1.1 * k }, 0.1);
        if (fades.length) tl.to(fades, { opacity: 1, y: 0, duration: 0.7 * k, stagger: 0.08 }, 0.1);
        if (lead.length) tl.to(lead, { y: 0, duration: 0.8 * k }, 0.35);
        if (stars.length) tl.to(stars, { opacity: 1, '--sc': 1, duration: 0.7 * k }, 0.7 * k);
        rows.forEach(function (r, i) { // i) desk log: las horas se "teclean"
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

  /* b) Secciones: una orquestación por bloque (cabeceras con máscara de líneas, bloques y rejillas escalonadas por columna/fila) */
  function up(tl, els, y, at, dur, st) {
    if (!els.length) return;
    g.set(els, { opacity: 0, y: y });
    tl.to(els, { opacity: 1, y: 0, duration: dur, stagger: st || 0 }, at);
  }
  function sections(k) {
    $('main > section, body > .cta').forEach(function (sec) {
      if (sec.matches('.hero, [data-no-motion]') || sec.getBoundingClientRect().top < vh() * 0.9) return;
      var heads = [], blocks = [], items = [], clean = [], txs = [];
      (function col(n, dp) {
        A.slice.call(n.children).forEach(function (c) {
          if (c.matches('script, style, [data-no-motion], [data-motion="steps"]') || !show(c)) return;
          if (c.matches('.section-head') || c.querySelector(':scope > h1, :scope > h2, :scope > h3')) heads.push(c);
          else if (GRID.test(c.className || '') && c.children.length > 1) A.push.apply(items, A.filter.call(c.children, show));
          else if (dp < 2 && c.matches('.split, .stack')) col(c, dp + 1);
          else if (c.offsetHeight < vh() * 1.3) blocks.push(c);
        });
      })(sec.querySelector('.container') || sec, 0);
      var tl = g.timeline({
        paused: true, defaults: { ease: 'expo.out' },
        onComplete: function () { if (clean.length) g.set(clean, { clearProps: CP }); txs.forEach(unsplit); }
      });
      heads.forEach(function (hd, hi) {
        var h = hd.querySelector('h1, h2, h3'), eb = hd.querySelector('.t-eyebrow'), sw = hd.querySelector('.swoosh'), at = 0.05 + hi * 0.1, rest = [];
        A.slice.call(hd.children).forEach(function (c) { A.push.apply(rest, c.matches('.section-head__main') ? A.slice.call(c.children) : [c]); });
        rest = rest.filter(function (c) { return c !== eb && c !== h && c !== sw && !c.contains(h) && show(c); });
        var ln = h ? lines(h, spl) : null;
        if (ln) txs.push(h);
        A.push.apply(clean, mark(rest.concat(eb || [], sw || [], h && !ln ? [h] : [])));
        if (eb) up(tl, [eb], 10 * k, at, 0.6 * k);
        if (ln) { g.set(ln, { yPercent: 130 }); tl.to(ln, { yPercent: 0, duration: 0.8 * k, stagger: 0.07 }, at + 0.05); }
        else if (h) up(tl, [h], 20 * k, at + 0.05, 0.7 * k);
        if (sw) { g.set(sw, { clipPath: DRAW0 }); tl.to(sw, { clipPath: DRAW1, duration: 0.6 * k, ease: 'power3.inOut' }, at + 0.35 * k); }
        up(tl, rest, 16 * k, at + 0.25 * k, 0.7 * k, 0.07);
      });
      A.push.apply(clean, mark(blocks)); up(tl, blocks, 24 * k, 0.2, 0.8 * k, 0.07);
      if (items.length) {
        var xs = [], ys = [], pos = function (e) { return [Math.round(e.offsetLeft), Math.round(e.offsetTop)]; };
        items.forEach(function (e) { var p = pos(e); if (xs.indexOf(p[0]) < 0) xs.push(p[0]); if (ys.indexOf(p[1]) < 0) ys.push(p[1]); });
        xs.sort(function (a, b) { return a - b; }); ys.sort(function (a, b) { return a - b; });
        A.push.apply(clean, mark(items));
        items.forEach(function (e) { // escalonado por columna y fila
          var p = pos(e);
          tl.fromTo(e, { opacity: 0, y: 24 * k }, { opacity: 1, y: 0, duration: 0.8 * k }, 0.25 + Math.min(0.5, xs.indexOf(p[0]) * 0.08 + ys.indexOf(p[1]) * 0.06));
        });
      }
      if (tl.getChildren().length) ST.create({ trigger: sec, start: 'top 85%', once: true, onEnter: function () { tl.play(); } });
    });
  }

  /* c) Pasos: el trazo se dibuja con scrub (--p) y cada número se enciende (.is-on) */
  function steps() {
    var undo = [];
    $('[data-motion="steps"]').forEach(function (ol) {
      var li = A.slice.call(ol.children), n = li.length, vert = ol.classList.contains('sb-hw-steps'), thr = [];
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
            var v = Math.min(1, Math.max(0, p * n - i));
            if (!vert) e.style.setProperty('--p', v.toFixed(3));
            e.classList.toggle('is-on', vert ? p > 0 && p >= (thr[i] || 0) : v > 0.02);
          });
        }
      });
    });
    return undo;
  }

  /* f) Ticker: marquesina GSAP (38 s, como el CSS) que acelera hasta ×3 con la velocidad de scroll */
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

  /* g) Hero con scroll: estrella gira ~30°; rejilla 0.3× e imagen 6 % solo en escritorio */
  function parallax(desk) {
    var h = d.querySelector('[data-motion="hero"]') || d.querySelector('.hero');
    if (!h) return;
    var tr = { trigger: h, start: 'top top', end: 'bottom top', scrub: true }, stars = $('.hero__star, .hero__bigstar, .star-hero', h);
    // --p solo en .hero--stack (su ::after gira con él); en otros heros recalcularía todo el subárbol
    if (h.classList.contains('hero--stack')) g.fromTo(h, { '--p': 0 }, { '--p': 1, ease: 'none', scrollTrigger: tr });
    if (stars.length) g.fromTo(stars, { '--sr': '0deg' }, { '--sr': '30deg', ease: 'none', scrollTrigger: tr });
    if (!desk) return;
    var deco = h.querySelector('.hero__deco'), pic = h.querySelector('.hero__clip picture');
    if (deco) g.fromTo(deco, { y: 0 }, { y: function () { return h.offsetHeight * 0.3; }, ease: 'none', scrollTrigger: tr });
    if (pic) g.fromTo(pic, { scale: 1, yPercent: 0 }, { scale: 1.06, yPercent: -2.5, ease: 'none', scrollTrigger: tr });
  }

  /* h) Spotlight (--mx/--my) en tarjetas oscuras y botones magnéticos (≤6 px, quickTo): solo puntero fino */
  function pointerFx() {
    var SPOT = '.card--dark, .home-rcard, .card--glass, [data-spot]', raf = 0, ev = null, off = [];
    function move() {
      raf = 0;
      var t = ev.target.closest ? ev.target.closest(SPOT) : null;
      if (!t) return;
      var r = t.getBoundingClientRect();
      t.style.setProperty('--mx', (ev.clientX - r.left).toFixed(0) + 'px');
      t.style.setProperty('--my', (ev.clientY - r.top).toFixed(0) + 'px');
    }
    var onMove = function (e) { if (e.pointerType === 'mouse') { ev = e; if (!raf) raf = requestAnimationFrame(move); } };
    d.addEventListener('pointermove', onMove, { passive: true });
    off.push(function () { d.removeEventListener('pointermove', onMove); });
    $('.hero__ctas .btn, .cta .btn, .btn--lg, .btn--contact').forEach(function (b) {
      var qx, qy, tm, lim = function (v) { return Math.max(-6, Math.min(6, v * 0.12)); };
      var rst = function () { b.classList.remove('is-mag'); b.style.removeProperty('--tx'); b.style.removeProperty('--ty'); };
      function enter(e) {
        if (e.pointerType !== 'mouse') return;
        clearTimeout(tm);
        if (!qx) { qx = g.quickTo(b, '--tx', { duration: 0.4, ease: 'power3' }); qy = g.quickTo(b, '--ty', { duration: 0.4, ease: 'power3' }); }
        b.classList.add('is-mag');
      }
      function mv(e) {
        if (e.pointerType !== 'mouse' || !qx) return;
        var r = b.getBoundingClientRect();
        qx(lim(e.clientX - r.left - r.width / 2)); qy(lim(e.clientY - r.top - r.height / 2));
      }
      function lv() { if (qx) { qx(0); qy(0); tm = setTimeout(rst, 600); } }
      b.addEventListener('pointerenter', enter); b.addEventListener('pointermove', mv); b.addEventListener('pointerleave', lv);
      off.push(function () { b.removeEventListener('pointerenter', enter); b.removeEventListener('pointermove', mv); b.removeEventListener('pointerleave', lv); rst(); });
    });
    return function () { off.forEach(function (f) { f(); }); };
  }

  /* Contextos: escritorio completo; móvil ×0.7 sin parallax ni magnéticos */
  function build() {
    mm = g.matchMedia();
    mm.add({ desk: '(min-width: 1025px)', mob: '(max-width: 1024px)', fine: '(hover: hover) and (pointer: fine)' }, function (ctx) {
      var c = ctx.conditions, undo = [];
      try {
        sections(c.mob ? 0.7 : 1);
        undo = steps();
        ticker();
        parallax(c.desk);
        if (c.desk && c.fine) undo.push(pointerFx());
      } catch (e) { fail(); }
      return function () { undo.forEach(function (f) { try { f(); } catch (e) { /* nada */ } }); };
    });
  }
  function rebuild() { // tras fuentes o cambio de ancho (el hero no se repite)
    if (dead) return;
    try { mm.revert(); spl.forEach(unsplit); spl.length = 0; if (tch.length) g.set(tch, { clearProps: CP }); tch.length = 0; build(); ST.refresh(); } catch (e) { fail(); }
  }

  try {
    hero(W < 1025 ? 0.7 : 1);
    build();
    var tmr, rt;
    window.addEventListener('resize', function () {
      if (window.innerWidth === W) return; // ignora los cambios de alto (barra del navegador móvil)
      W = window.innerWidth;
      clearTimeout(tmr);
      tmr = setTimeout(function () { hSpl.forEach(function (h) { if (h.__ns) { S.revert(h); S.split(h); } }); rebuild(); }, 250);
    });
    if (d.fonts && d.fonts.status !== 'loaded' && d.fonts.ready) d.fonts.ready.then(rebuild);
    $('img[loading="lazy"]').forEach(function (i) { if (!i.complete) i.addEventListener('load', function () { clearTimeout(rt); rt = setTimeout(function () { ST.refresh(); }, 150); }); });
  } catch (e) { fail(); return; }
  if (rm && rm.addEventListener) rm.addEventListener('change', function () { if (rm.matches) fail(); });
})();
