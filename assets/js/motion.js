/* NorthStar Freight: motion.js. Escenas de scroll con GSAP 3.12.5 + ScrollTrigger (profundidad y parallax, estilo "Fourlines").
   Todo va ligado al scroll (scrub) y solo anima transform y opacity. La única excepción son las entradas del onboarding (una vez, .7 s).
   Mejora progresiva: arranca tras `load` + idle (no toca LCP ni TBT). html.js-motion solo existe si hay GSAP, ScrollTrigger y no hay
   prefers-reduced-motion; si algo falla se revierte todo y la página queda completa y estática.
   Escenas: 1 hero de la home fijado que se encoge · 2 carril horizontal fijado de servicios · 3 parallax de fotos (.px) ·
   4 bandas (CTA final y Amazon Relay) · 5 entradas del onboarding y de las cifras. Estilos que necesitan: assets/css/motion.css. */
(function () {
  'use strict';
  var g = window.gsap, ST = window.ScrollTrigger, d = document, root = d.documentElement;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || !window.matchMedia || (rm && rm.matches)) return;
  try { g.registerPlugin(ST); } catch (e) { return; }
  ST.config({ ignoreMobileResize: true });

  var A = Array.prototype;
  var $ = function (s, r) { return A.slice.call((r || d).querySelectorAll(s)); };
  var one = function (s, r) { return (r || d).querySelector(s); };
  var vh = function () { return window.innerHeight || root.clientHeight; };
  var mm = null, dead = false;

  function fail() { // todo visible y estático, sin rastro
    if (dead) return; dead = true;
    try { if (mm) mm.revert(); ST.getAll().forEach(function (t) { t.kill(); }); } catch (e) { /* nada */ }
    root.classList.remove('js-motion');
  }

  /* ---- Escena 1: hero de la home. Fijado ~100 % del alto de la ventana; la foto se encoge, el texto sube y se desvanece ---- */
  function heroScene() {
    var hero = one('.hero--home'), bg = hero && one('.hero__bg', hero), img = bg && one('img', bg);
    var main = hero && one('.hero__main', hero);
    if (!hero || !bg || !img || !main) return;
    var l1 = one('.home-hero__l1', hero), l2 = one('.home-hero__l2', hero);
    var tags = one('.tags', hero), ctas = one('.hero__ctas', hero);
    var tl = g.timeline({
      defaults: { ease: 'none', duration: 1 },  // duración 1 = todo el recorrido del pin
      scrollTrigger: { trigger: hero, start: 'top top', end: function () { return '+=' + Math.round(vh()); }, pin: true, scrub: 0.6, anticipatePin: 1, invalidateOnRefresh: true }
    });
    tl.fromTo(bg, { scale: 1, y: 0 }, { scale: 0.86, y: 36, transformOrigin: '50% 50%' }, 0)   // y: baja el borde superior de la foto por debajo de la píldora del menú
      .fromTo(img, { yPercent: 0, scale: 1.12 }, { yPercent: -6, scale: 1.04, transformOrigin: '0% 62%' }, 0)
      .fromTo(main, { y: 0 }, { y: -140 }, 0)
      .fromTo(main, { opacity: 1 }, { opacity: 0, duration: 0.6 }, 0);
    if (l1) tl.fromTo(l1, { x: 0 }, { x: -48 }, 0);
    if (l2) tl.fromTo(l2, { x: 0 }, { x: 48 }, 0);
    var fade = [tags, ctas].filter(Boolean);
    if (fade.length) tl.fromTo(fade, { opacity: 1 }, { opacity: 0, duration: 0.33 }, 0);
  }

  /* ---- Escena 2: carril horizontal de servicios. La sección se fija y las tarjetas se desplazan en horizontal ---- */
  function railScene() {
    var pin = one('.home-services__pin'), rail = pin && one('.home-rail', pin), track = rail && one('.home-cards', rail);
    var cards = track ? $('.card-wrap', track) : [];
    if (!pin || !track || cards.length < 2) return;
    var dist = function () { return Math.max(0, track.offsetWidth - rail.clientWidth); };
    var move = g.to(track, {
      x: function () { return -dist(); }, ease: 'none',
      scrollTrigger: { trigger: pin, start: 'top top', end: function () { return '+=' + Math.round(Math.max(dist() * 1.15, 400)); }, pin: true, scrub: 1, anticipatePin: 1, invalidateOnRefresh: true }
    });
    cards.forEach(function (card, i) {
      var frame = one('.card__photo', card), photo = one('img', frame);
      g.set(card, { rotation: i % 2 ? 2 : -2, y: i * 28, transformOrigin: '50% 50%' });
      // la opacidad .6 a 1 va en la foto y no en toda la tarjeta: el texto conserva su contraste AA también en las tarjetas que aún no están en el centro
      g.fromTo(card, { scale: 0.92 }, {
        scale: 1, ease: 'none',
        scrollTrigger: { trigger: card, containerAnimation: move, start: 'left 92%', end: 'left 52%', scrub: true }
      });
      g.fromTo(frame, { opacity: 0.6 }, {
        opacity: 1, ease: 'none',
        scrollTrigger: { trigger: card, containerAnimation: move, start: 'left 92%', end: 'left 52%', scrub: true }
      });
      if (photo) {
        g.fromTo(photo, { xPercent: -13 }, {
          xPercent: -2, ease: 'none',
          scrollTrigger: { trigger: card, containerAnimation: move, start: 'left right', end: 'right left', scrub: true }
        });
      }
    });

    // Teclado: al enfocar un enlace de una tarjeta fuera de pantalla, el scroll lleva el carril hasta esa tarjeta (el marco no se desplaza solo)
    function onFocus(e) {
      var card = e.target && e.target.closest ? e.target.closest('.card-wrap') : null;
      if (!card || cards.indexOf(card) < 0) return;
      rail.scrollLeft = 0;
      var st = move.scrollTrigger, dd = dist();
      if (!st || !dd) return;
      var x = Math.min(dd, Math.max(0, card.offsetLeft + card.offsetWidth / 2 - rail.clientWidth / 2));
      var y = st.start + (x / dd) * (st.end - st.start);
      window.scrollTo(0, Math.round(y));
    }
    pin.addEventListener('focusin', onFocus);
    return function () { pin.removeEventListener('focusin', onFocus); };
  }

  /* ---- Escena 3: parallax de fotos. La imagen (120 % del alto del marco) se desplaza mientras el marco cruza la ventana ---- */
  function photoScene(rail) {
    $('.px').forEach(function (px) {
      var img = one('img', px);
      if (!img || (rail && px.closest('.home-cards'))) return; // las del carril se mueven en horizontal (escena 2)
      g.fromTo(img, { y: 0, yPercent: -0.4 }, {
        yPercent: -16.2, ease: 'none',
        scrollTrigger: { trigger: px, start: 'top bottom', end: 'bottom top', scrub: true }
      });
    });
  }

  /* ---- Escena 4: bandas (CTA final y Amazon Relay). Entran escalando de .94 a 1 y subiendo 60 px; solo escritorio ---- */
  function bandScene() {
    $('.home-relay, .cta').forEach(function (el) {
      g.fromTo(el, { scale: 0.94, y: 60 }, {
        scale: 1, y: 0, ease: 'none', transformOrigin: '50% 50%',
        scrollTrigger: { trigger: el, start: 'top 85%', end: 'top 40%', scrub: 0.5 }
      });
    });
  }

  /* ---- Escena 5: entradas (una sola vez, no ligadas al scroll): pasos del onboarding y fila 4/1/0 ---- */
  function enterScene() {
    ['.home-steps', '.home-figures'].forEach(function (sel) {
      var list = one(sel);
      var items = list ? $(':scope > li', list) : [];
      if (!items.length) return;
      g.from(items, {
        y: 40, opacity: 0, duration: 0.7, ease: 'power3.out', stagger: 0.08, clearProps: 'opacity,transform',
        scrollTrigger: { trigger: list, start: 'top 85%', once: true }
      });
    });
  }

  function build(ctx) {
    var c = ctx.conditions, undo = [];
    if (c.desk) {
      heroScene();
      var off = c.rail ? railScene() : null;
      if (off) undo.push(off);
    }
    photoScene(!!c.rail);
    if (c.desk) bandScene();
    enterScene();
    return function () { undo.forEach(function (f) { f(); }); };
  }

  function start() {
    if (dead || root.classList.contains('js-motion')) return;
    try {
      root.classList.add('js-motion');
      mm = g.matchMedia();
      mm.add({ desk: '(min-width: 1025px)', rail: '(min-width: 1025px) and (min-height: 700px)' }, build);
      ST.refresh();
      if (d.fonts && d.fonts.ready) d.fonts.ready.then(function () { if (!dead) ST.refresh(); });
      if (rm && rm.addEventListener) rm.addEventListener('change', function () { if (rm.matches) fail(); });
    } catch (e) { fail(); }
  }

  function boot() {
    if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 1200 });
    else setTimeout(start, 120);
  }
  if (d.readyState === 'complete') boot(); else window.addEventListener('load', boot);
})();
