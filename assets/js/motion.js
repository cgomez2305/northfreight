/* NorthStar Freight — motion.js: coreografía GSAP 3.12 + ScrollTrigger (solo transform, opacity, clip-path y variables CSS; sin parallax ni giros del hero).
   La entrada del hero es CSS puro (components.css): este archivo NO bloquea ni retrasa el primer pintado. Arranca tras `load`, en tareas
   cortas (idle), y prepara cada sección solo cuando se acerca a la pantalla (IntersectionObserver), de modo que el coste de la carga es casi cero.
   html.js-motion se añade solo con GSAP + ScrollTrigger y sin reduced-motion; ante un fallo se restaura todo. Líneas de titular: split.js. */
(function () {
  'use strict';
  var g = window.gsap, ST = window.ScrollTrigger, S = window.NSSplit, d = document, root = d.documentElement;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || (rm && rm.matches)) return;
  try { g.registerPlugin(ST); } catch (e) { return; }
  root.classList.add('js-motion');

  var A = Array.prototype, CP = 'opacity,transform,clipPath,visibility,--cota', GRID = /(^|\s)(g[2-6]h?|blog-grid|cards)(\s|$)/;
  var $ = function (s, r) { return A.slice.call((r || d).querySelectorAll(s)); };
  var show = function (e) { return e.getClientRects().length > 0; };
  var vh = function () { return window.innerHeight || root.clientHeight; };
  var recs = [], mm, W = window.innerWidth, dead = false, ioPrep, ioPlay;
  var unsplit = function (e) { if (S) S.revert(e); };
  var DRAW0 = 'inset(0 100% 0 0)', DRAW1 = 'inset(0 0% 0 0)';
  var idle = window.requestIdleCallback ? function (f) { window.requestIdleCallback(f, { timeout: 800 }); } : function (f) { setTimeout(f, 60); };
  var later = function (f) { setTimeout(f, 0); }; // tarea nueva: ninguna parte supera unos pocos ms

  function lines(h, store) { // máscara por líneas solo en titulares cortos
    if (!S || !h || h.textContent.length > 90) return null;
    try { var l = S.split(h); if (l.length > 6) { S.revert(h); return null; } store.push(h); return l; } catch (e) { return null; }
  }
  function fail() { // todo visible, sin rastro
    if (dead) return; dead = true;
    try {
      if (ioPrep) ioPrep.disconnect();
      if (ioPlay) ioPlay.disconnect();
      if (mm) mm.revert();
      ST.getAll().forEach(function (t) { t.kill(); });
      recs.forEach(function (r) { r.tl.kill(); g.killTweensOf(r.clean); g.set(r.clean, { clearProps: 'all' }); r.txs.forEach(unsplit); });
    } catch (e) { /* nada */ }
    root.classList.remove('js-motion');
  }

  /* b) Secciones: una orquestación por bloque (cabeceras con máscara de líneas, bloques y rejillas escalonadas por columna/fila) */
  function up(tl, els, y, at, dur, st) {
    if (!els.length) return;
    g.set(els, { opacity: 0, y: y });
    tl.to(els, { opacity: 1, y: 0, duration: dur, stagger: st || 0 }, at);
  }
  function prepare(sec) { // lecturas + estados iniciales + timeline (pausada) de UNA sección
    var k = window.innerWidth < 1025 ? 0.7 : 1, heads = [], blocks = [], items = [], clean = [], txs = [];
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
      var ln = h ? lines(h, txs) : null;
      A.push.apply(clean, rest.concat(eb || [], sw || [], h && !ln ? [h] : []));
      if (eb) {
        up(tl, [eb], 10 * k, at, 0.6 * k);
        if (eb.closest('.section-head')) { g.set(eb, { '--cota': 0 }); tl.to(eb, { '--cota': 1, duration: 0.7 * k, ease: 'power3.inOut' }, at + 0.1); } // cota: la línea de dimensión crece (scaleX por variable)
      }
      if (ln) { g.set(ln, { yPercent: 130 }); tl.to(ln, { yPercent: 0, duration: 0.8 * k, stagger: 0.07 }, at + 0.05); }
      else if (h) up(tl, [h], 20 * k, at + 0.05, 0.7 * k);
      if (sw) { g.set(sw, { clipPath: DRAW0 }); tl.to(sw, { clipPath: DRAW1, duration: 0.6 * k, ease: 'power3.inOut' }, at + 0.35 * k); }
      up(tl, rest, 16 * k, at + 0.25 * k, 0.7 * k, 0.07);
    });
    A.push.apply(clean, blocks); up(tl, blocks, 24 * k, 0.2, 0.8 * k, 0.07);
    if (items.length) {
      var xs = [], ys = [], pos = function (e) { return [Math.round(e.offsetLeft), Math.round(e.offsetTop)]; };
      items.forEach(function (e) { var p = pos(e); if (xs.indexOf(p[0]) < 0) xs.push(p[0]); if (ys.indexOf(p[1]) < 0) ys.push(p[1]); });
      xs.sort(function (a, b) { return a - b; }); ys.sort(function (a, b) { return a - b; });
      A.push.apply(clean, items);
      items.forEach(function (e) { // escalonado por columna y fila
        var p = pos(e);
        tl.fromTo(e, { opacity: 0, y: 24 * k }, { opacity: 1, y: 0, duration: 0.8 * k }, 0.25 + Math.min(0.5, xs.indexOf(p[0]) * 0.08 + ys.indexOf(p[1]) * 0.06));
      });
    }
    return tl.getChildren().length ? { sec: sec, tl: tl, clean: clean, txs: txs } : null;
  }
  function drop(r) { // deshace una sección preparada y aún no reproducida
    r.tl.kill(); g.killTweensOf(r.clean); g.set(r.clean, { clearProps: CP }); r.txs.forEach(unsplit);
    var i = recs.indexOf(r); if (i >= 0) recs.splice(i, 1);
  }
  function observeSections() {
    if (!('IntersectionObserver' in window)) return;
    var queue = [], busy = false;
    function pump() {
      if (busy || dead) return;
      var sec = queue.shift();
      if (!sec) return;
      busy = true;
      later(function () {
        busy = false;
        if (dead) return;
        try {
          // ya a la vista (carga, salto de ancla, scroll rápido): se queda visible, sin ocultarla para volver a mostrarla
          var r = sec.getBoundingClientRect().top < vh() * 0.9 ? null : prepare(sec);
          if (r) { recs.push(r); sec.__nsRec = r; ioPlay.observe(sec); }
        } catch (e) { fail(); return; }
        pump();
      });
    }
    // 1) preparar cuando la sección está a ≤ ~1,8 pantallas; 2) reproducir al cruzar el 85 % de la altura (como `top 85%`)
    ioPrep = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { ioPrep.unobserve(e.target); queue.push(e.target); } });
      pump();
    }, { rootMargin: '0px 0px 80% 0px' });
    ioPlay = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        ioPlay.unobserve(e.target);
        var r = e.target.__nsRec;
        if (r) { e.target.__nsRec = null; var i = recs.indexOf(r); if (i >= 0) recs.splice(i, 1); r.tl.play(); }
      });
    }, { rootMargin: '0px 0px -15% 0px' });
    $('main > section, body > .cta').forEach(function (sec) { if (!sec.matches('.hero, [data-no-motion]')) ioPrep.observe(sec); });
    observeSections.reset = function () { // cambio de ancho: lo preparado y sin reproducir se rehace con las medidas nuevas
      recs.slice().forEach(function (r) { ioPlay.unobserve(r.sec); r.sec.__nsRec = null; drop(r); ioPrep.observe(r.sec); });
    };
  }

  /* c) Pasos como ruta: cada tramo (de una parada numerada a la siguiente) se traza con scrub (--p por paso, 0-1) y un marcador (estrella) lo
     recorre; el número de cada parada se enciende (.is-on) al llegar. La ruta es vertical en /how-it-works/ y horizontal en el home (≥1025);
     en 2×2 y 1 columna cada paso conserva su trazo propio (--p = su cuarto del recorrido). Solo cambian variables CSS y clases. */
  function steps(desk) {
    var undo = [], clamp = function (v) { return Math.min(1, Math.max(0, v)); };
    $('[data-motion="steps"]').forEach(function (ol) {
      var li = A.slice.call(ol.children), n = li.length, vert = ol.classList.contains('sb-hw-steps'), route = vert || desk, st = [], tot = 1;
      ol.classList.add('has-line');
      li.forEach(function (e) { e.style.setProperty('--p', '0'); });
      undo.push(function () { ol.classList.remove('has-line'); li.forEach(function (e) { e.classList.remove('is-on'); e.style.removeProperty('--p'); }); });
      ST.create({
        trigger: ol, start: vert ? 'top 62%' : 'top 80%', end: vert ? 'bottom 62%' : 'bottom 50%', scrub: true,
        onRefresh: function () { // paradas: centro de cada número (px) en vertical, índice en horizontal
          if (!route) return;
          if (vert) { st = li.map(function (e) { return e.offsetTop + 32; }); tot = ol.offsetHeight || 1; }
          else { st = li.map(function (e, i) { return i; }); tot = Math.max(1, n - 1); }
        },
        onUpdate: function (s) {
          var p = s.progress, y = p * tot;
          li.forEach(function (e, i) {
            var v = !route ? clamp(p * n - i) : i < n - 1 && st.length ? clamp((y - st[i]) / ((st[i + 1] - st[i]) || 1)) : 0;
            e.style.setProperty('--p', v.toFixed(3));
            e.classList.toggle('is-on', !route ? v > 0.02 : i === 0 ? p > 0 : y >= (st[i] || 0));
          });
        }
      });
    });
    return undo;
  }

  /* f) Ticker: la marquesina sigue siendo la animación CSS (compositor); su playbackRate sube hasta ×3 con la velocidad de scroll y vuelve a 1 */
  function ticker() {
    var tr = d.querySelector('.ticker__track'), an = tr && tr.getAnimations ? tr.getAnimations()[0] : null;
    if (!an) return;
    ST.create({
      start: 0, end: 'max',
      onUpdate: function (s) {
        var v = Math.min(3, 1 + Math.abs(s.getVelocity()) / 1500);
        if (v > an.playbackRate + 0.05) { an.playbackRate = v; g.to(an, { playbackRate: 1, duration: 1.2, ease: 'power2.out', overwrite: true }); }
      }
    });
  }

  /* h) Spotlight (--mx/--my) en tarjetas oscuras y botones magnéticos (≤6 px, quickTo): solo puntero fino; se montan con el primer movimiento del ratón */
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

  /* Contextos (gsap.matchMedia): escritorio completo; móvil sin magnéticos. Cada pieza en su propia tarea. */
  function build() {
    mm = g.matchMedia();
    mm.add({ desk: '(min-width: 1025px)', fine: '(hover: hover) and (pointer: fine)' }, function (ctx) {
      var c = ctx.conditions, undo = [], gone = false;
      // ctx.add: los ScrollTrigger creados después (en otra tarea) también se revierten con el contexto
      later(function () { if (gone || dead) return; try { ctx.add(function () { undo.push.apply(undo, steps(c.desk)); }); } catch (e) { fail(); } });
      later(function () { if (gone || dead) return; try { ctx.add(function () { ticker(); }); } catch (e) { fail(); } });
      if (c.desk && c.fine) {
        var once = function (e) {
          if (e.pointerType !== 'mouse') return;
          d.removeEventListener('pointermove', once);
          if (!gone && !dead) { try { undo.push(pointerFx()); } catch (er) { /* nada */ } }
        };
        d.addEventListener('pointermove', once, { passive: true });
        undo.push(function () { d.removeEventListener('pointermove', once); });
      }
      return function () { gone = true; undo.forEach(function (f) { try { f(); } catch (e) { /* nada */ } }); };
    });
  }

  function start() {
    if (dead) return;
    try {
      observeSections();
      build();
      var tmr;
      window.addEventListener('resize', function () {
        if (window.innerWidth === W) return; // ignora los cambios de alto (barra del navegador móvil)
        W = window.innerWidth;
        clearTimeout(tmr);
        tmr = setTimeout(function () { if (!dead && observeSections.reset) observeSections.reset(); }, 250);
      });
    } catch (e) { fail(); }
  }
  // Arranca tras `load` + un tramo ocioso; si las fuentes aún cargan, tras ellas (el corte de líneas depende de su métrica)
  function boot() {
    idle(function () { if (d.fonts && d.fonts.status === 'loading' && d.fonts.ready) d.fonts.ready.then(start); else start(); });
  }
  if (d.readyState === 'complete') boot(); else window.addEventListener('load', boot, { once: true });
  if (rm && rm.addEventListener) rm.addEventListener('change', function () { if (rm.matches) fail(); });
})();
