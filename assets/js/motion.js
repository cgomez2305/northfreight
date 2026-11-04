/* NorthStar Freight: motion.js. Escenas de scroll con GSAP 3.12.5 + ScrollTrigger (profundidad y parallax, estilo "Fourlines") en TODAS las rutas.
   Todo va ligado al scroll (scrub) y solo anima transform y opacity. Las únicas excepciones son las entradas de cifras (una vez, .7 s).
   Mejora progresiva: arranca tras `load` + idle (no toca LCP ni TBT). html.js-motion solo existe si hay GSAP, ScrollTrigger y no hay
   prefers-reduced-motion; si algo falla se revierte todo y la página queda completa y estática.
   Sistema común (se activa por clases que ya existen en los partials, nada por página):
     hero interno (.hero) · bandas y paneles (.section--surface, .section--dark, .home-relay, .cta, .footer, .co-band) · rejillas 3D por filas
     (.g2 a .g6, .blog-grid; las .g4 entran en ola) · titulares de sección (.t-h2, .t-h2-s) · marcos de foto (.px) con parallax y escala · cifras (entradas una vez) ·
     patrón B (foto ancha con tarjeta solapada: la tarjeta sube con desfase respecto a la foto) · patrón C (filas apiladas con sticky: la fila cubierta se escala a .955 y su foto se atenúa) ·
     cortina fotográfica (.curtain: foto sticky que la sección .curtain-next cubre; la imagen interior se desplaza con scrub) · relleno de texto ([data-textfill] .w: opacidad .5 a 1 por palabra, >=1025px).
   Solo home: hero fijado (también se desvanece el widget de WhatsApp y sigue el video de fondo), carril horizontal fijado de servicios, sticky "Why a desk" (pin ~2,1 alturas: numeral, foto con crossfade e ítem activo),
   líneas de progreso del onboarding (scaleX con scrub) y parallax de la banda de video. Móvil (<1025 px): parallax de fotos, líneas, cifras y entrada de bandas, sin pines ni 3D.
   Nunca se anima ningún input, select, textarea ni resultado aria-live. Estilos que necesitan: assets/css/motion.css. */
(function () {
  'use strict';
  /* GSAP y ScrollTrigger NO bloquean ni compiten con el LCP: se descargan tras `load` + idle, desde cdnjs (versión fija con SRI en los data-* de
     este <script>), y solo entonces arrancan las escenas. Si la descarga falla, la página queda completa y estática. */
  var cur = document.currentScript, cfg = cur && cur.dataset ? cur.dataset : {};
  /* html.hero-pre (la pone un script inline del home) reserva el espacio del pin-spacer del hero para que la página no salte cuando GSAP lo inserta; si GSAP no va a correr, se libera ya. */
  function unpre() { document.documentElement.classList.remove('hero-pre'); }
  function inject(src, sri, cb) {
    var s = document.createElement('script');
    s.src = src; s.integrity = sri; s.crossOrigin = 'anonymous'; s.referrerPolicy = 'no-referrer'; s.async = false;
    s.onload = cb; s.onerror = unpre;
    document.head.appendChild(s);
  }
  function load() {
    if (window.gsap && window.ScrollTrigger) return main();
    if (!cfg.gsap || !cfg.st) return unpre();
    inject(cfg.gsap, cfg.gsapSri, function () { inject(cfg.st, cfg.stSri, function () { if (window.gsap && window.ScrollTrigger) main(); else unpre(); }); });
  }
  function later() {
    if (window.requestIdleCallback) window.requestIdleCallback(load, { timeout: 1500 }); else setTimeout(load, 200);
  }
  if (document.readyState === 'complete') later(); else window.addEventListener('load', later);

  function main() {
  var g = window.gsap, ST = window.ScrollTrigger, d = document, root = d.documentElement;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || !window.matchMedia || (rm && rm.matches)) return unpre();
  try { g.registerPlugin(ST); } catch (e) { return unpre(); }
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
    unpre();
  }

  /* ---- Escena 1: hero de la home. Fijado ~100 % del alto de la ventana; la foto se encoge, el texto sube y se desvanece ---- */
  function heroScene() {
    var hero = one('.hero--home'), bg = hero && one('.hero__bg', hero), img = bg && one('img', bg);
    var main = hero && one('.hero__main', hero);
    if (!hero || !bg || !img || !main) return;
    var l1 = one('.home-hero__l1', hero), l2 = one('.home-hero__l2', hero);
    var tags = one('.tags', hero), ctas = one('.hero__ctas', hero), wa = one('.hero-wa', hero), vid = one('video', bg);
    var tl = g.timeline({
      defaults: { ease: 'none', duration: 1 },  // duración 1 = todo el recorrido del pin
      scrollTrigger: { trigger: hero, start: 'top top', end: function () { return '+=' + Math.round(vh()); }, pin: true, scrub: 0.6, anticipatePin: 1, invalidateOnRefresh: true }
    });
    tl.fromTo(bg, { scale: 1, y: 0 }, { scale: 0.86, y: 36, transformOrigin: '50% 50%' }, 0)   // y: baja el borde superior de la foto por debajo de la píldora del menú
      .fromTo(img, { yPercent: 0 }, { yPercent: -2 }, 0)   // sin escala: el fotograma 1920×1080 ya se amplía ~0,8× en 1440×900
      .fromTo(main, { y: 0 }, { y: -140 }, 0)
      .fromTo(main, { opacity: 1 }, { opacity: 0, duration: 0.6 }, 0);
    if (vid) tl.fromTo(vid, { yPercent: 0 }, { yPercent: -2 }, 0);   // video de fondo opcional: sigue a la foto
    if (wa) tl.fromTo(wa, { y: 0, opacity: 1 }, { y: -90, opacity: 0, duration: 0.5 }, 0);   // el widget de WhatsApp se desvanece con el texto
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
        g.fromTo(photo, { xPercent: -10 }, {
          xPercent: -1, ease: 'none',
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

  /* ---- Escena 2b: sticky "Why a desk" (home, >=1025px y >=700px de alto). La sección se fija ~2,1 alturas de ventana; mientras el scroll recorre la lista cambian
     el numeral grande, la foto fija (crossfade: solo opacity y transform) y el ítem resaltado (el resto queda a opacidad .65: el texto sigue pasando AA, ver motion.css) ---- */
  var DIM = 0.65;
  function whyScene() {
    var pin = one('.why__pin'), items = pin ? $('.why__item', pin) : [], nums = pin ? $('.why__nums > span', pin) : [];
    var n = items.length;
    if (!pin || n < 2 || nums.length !== n) return;
    var photos = items.map(function (it) { return one('.why__photo', it); }), imgs = photos.map(function (f) { return f && one('img', f); });
    if (photos.some(function (f) { return !f; })) return;
    var total = n - 0.25, cur = -1;   // cada estado dura ~.5 de "tiempo" y la transición .5 centrada en cada frontera; el último estado se sostiene hasta el final
    function setActive(i) {
      if (i === cur) return; cur = i;
      items.forEach(function (it, k) { it.classList.toggle('is-on', k === i); if (k === i) it.setAttribute('aria-current', 'true'); else it.removeAttribute('aria-current'); });
    }
    var tl = g.timeline({
      defaults: { ease: 'none' },
      scrollTrigger: { trigger: pin, start: 'top top', end: function () { return '+=' + Math.round(vh() * 2.1); }, pin: true, scrub: 0.5, anticipatePin: 1, invalidateOnRefresh: true,
        onUpdate: function (self) { setActive(Math.min(n - 1, Math.floor(self.progress * total + 1e-6))); } }
    });
    items.forEach(function (it, i) { if (i) g.set(it, { opacity: DIM }); });
    for (var i = 1; i < n; i++) {
      var t = i - 0.25;
      tl.fromTo(photos[i], { opacity: 0 }, { opacity: 1, duration: 0.5 }, t);    // la foto nueva entra por encima de la anterior
      if (imgs[i]) tl.fromTo(imgs[i], { scale: 1.03 }, { scale: 1, duration: 0.5 }, t);
      tl.fromTo(nums[i], { opacity: 0, yPercent: 24 }, { opacity: 1, yPercent: 0, duration: 0.5 }, t);
      tl.to(nums[i - 1], { opacity: 0, yPercent: -24, duration: 0.5 }, t);
      tl.to(items[i - 1], { opacity: DIM, duration: 0.5 }, t);
      tl.fromTo(items[i], { opacity: DIM }, { opacity: 1, duration: 0.5 }, t);
    }
    tl.to({}, { duration: 0.001 }, total - 0.001);
    setActive(0);

    // Teclado: al enfocar el enlace de un ítem, el scroll lleva la sección al punto donde ese ítem y su foto están activos
    function onFocus(e) {
      var it = e.target && e.target.closest ? e.target.closest('.why__item') : null, k = items.indexOf(it);
      if (k < 0 || k === cur) return;
      var st = tl.scrollTrigger; if (!st) return;
      window.scrollTo(0, Math.round(st.start + ((k === 0 ? 0.2 : k + 0.5) / total) * (st.end - st.start)));
    }
    pin.addEventListener('focusin', onFocus);
    return function () {
      pin.removeEventListener('focusin', onFocus);
      items.forEach(function (it) { it.classList.remove('is-on'); it.removeAttribute('aria-current'); });
      items[0].classList.add('is-on');
    };
  }

  /* ---- Escena 3: parallax de fotos. La imagen (110 % del alto del marco) se desplaza mientras el marco cruza la ventana ---- */
  function photoScene(rail) {
    $('.px').forEach(function (px) {
      var img = $('img', px);   // un marco puede llevar varias fotos apiladas (FAQ por tema): se mueven juntas con un solo trigger
      if (!img.length || (rail && px.closest('.home-cards')) || !sees(px)) return; // las del carril se mueven en horizontal (escena 2); las ocultas (foto del FAQ en <1025px) no necesitan trigger
      g.fromTo(img, { y: 0, yPercent: -0.3 }, {
        yPercent: -8.8, ease: 'none',
        scrollTrigger: { trigger: px, start: 'top bottom', end: 'bottom top', scrub: true }
      });
    });
  }

  /* ---- Utilidades del sistema común ---- */
  // Posición vertical SIN transformaciones (suma de offsetTop): estable aunque el elemento o sus padres estén en su estado inicial de la escena
  function absTop(el) { var y = 0; while (el) { y += el.offsetTop; el = el.offsetParent; } return y; }
  // Agrupa elementos en filas visuales (misma posición vertical) para crear un trigger por fila y no uno por tarjeta
  function rowsOf(els, tol) {
    var out = [];
    els.forEach(function (el) {
      var t = absTop(el), r = out[out.length - 1];
      if (r && Math.abs(r.top - t) < (tol || 24)) r.els.push(el); else out.push({ top: t, els: [el] });
    });
    return out;
  }
  /* Opacidad inicial 0.95 (no 0.5/0.25): con menos, el cian pequeño sobre --panel-2 baja de 4,5:1 (0.9 => 4,4:1) y Lighthouse lo mide en las tarjetas aún sin llegar. El efecto lo dan la rotación y el desplazamiento. */
  // will-change solo mientras la escena está activa (no se dejan capas fijas en bloques grandes)
  function wc(el) { return function (self) { el.style.willChange = self.isActive ? 'transform, opacity' : ''; }; }
  function sees(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length) && !el.hidden; }
  var hasLive = function (el) { return !!el.querySelector('[data-calc], [aria-live]:not(.sr-only)'); };
  var hasField = function (el) { return !!el.querySelector('form, input, select, textarea'); };

  /* ---- Hero interno (todas las páginas internas, sin pin, solo escritorio): mientras sale de la pantalla el panel se aleja ---- */
  function heroInt() {
    $('.hero:not(.hero--home)').forEach(function (hero) {
      var main = one('.hero__main', hero), video = one('.video:not(.hero__pic)', hero);   // el marco del hero fotográfico (Our desk) no se escala: crearía un contexto de apilamiento y dejaría el play bajo el degradado
      // herramientas con la calculadora dentro del hero: nada de escalar el panel (arrastraría los campos); solo sube y se atenúa el bloque de texto
      if (hasField(hero) || hasLive(hero)) {
        if (main && !main.querySelector('input, select, textarea, [aria-live]')) {
          g.fromTo(main, { y: 0, opacity: 1 }, { y: -60, opacity: 0.15, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: 0.6 } });
        }
        return;
      }
      var tl = g.timeline({
        defaults: { ease: 'none', duration: 1 },
        scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: 0.6, onToggle: wc(hero) }
      });
      tl.fromTo(hero, { scale: 1, y: 0 }, { scale: 0.95, y: 40, transformOrigin: '50% 50%' }, 0);
      if (main) tl.fromTo(main, { y: 0, opacity: 1 }, { y: -60, opacity: 0.15 }, 0);
      if (video) tl.fromTo(video, { scale: 1 }, { scale: 0.92, transformOrigin: '50% 50%' }, 0);
    });
  }

  /* ---- Bandas y paneles grandes (CTA final, footer, paneles de sección, banda de Relay): entran subiendo; en móvil solo y + opacidad ---- */
  function bandScene(desk, curtain) {
    var all = $('.section--surface, .section--dark, .home-relay, .cta, .footer, .co-band');
    all.forEach(function (el) {
      if (hasLive(el) || (curtain && el.classList.contains('curtain-next')) || (el.parentElement && el.parentElement.closest('.section--surface, .section--dark, .home-relay, .cta, .footer, .co-band'))) return;
      g.fromTo(el, desk ? { scale: 0.94, y: 60 } : { y: 40, opacity: 0.95 }, {
        scale: 1, y: 0, opacity: 1, ease: 'none', transformOrigin: '50% 50%',
        scrollTrigger: desk
          ? { trigger: el, start: 'clamp(top 90%)', end: 'clamp(top 45%)', scrub: 0.5, onToggle: wc(el) }
          : { trigger: el, start: 'clamp(top 92%)', end: 'clamp(top 70%)', scrub: 0.5 }
      });
    });
  }

  /* ---- Rejillas de tarjetas con profundidad 3D (solo escritorio): un trigger por FILA, cada tarjeta escalonada ---- */
  function gridScene(rail) {
    var undo = [];
    $('.g2, .g3, .g4, .g5, .g6, .blog-grid').forEach(function (grid) {
      if (grid.closest('.hero, .footer, [data-calc], form') || (rail && grid.matches('.home-cards')) || hasField(grid) || grid.matches('.g4')) return;   // las .g4 entran en ola (waveScene)
      var items = A.slice.call(grid.children).filter(sees);
      if (!items.length) return;
      var prev = grid.style.perspective; grid.style.perspective = '1200px';
      undo.push(function () { grid.style.perspective = prev; });
      rowsOf(items).forEach(function (row) {
        var off = function () { return Math.max(0, absTop(row.els[0]) - absTop(grid)); };
        var tl = g.timeline({
          defaults: { ease: 'none' },
          scrollTrigger: { trigger: grid, start: function () { return 'top+=' + off() + ' 95%'; }, end: function () { return 'top+=' + off() + ' 60%'; }, scrub: true, invalidateOnRefresh: true }
        });
        row.els.forEach(function (el, i) {
          tl.fromTo(el, { rotateX: 10, y: 48, opacity: 0.95, transformOrigin: '50% 100%' }, { rotateX: 0, y: 0, opacity: 1, duration: 1 }, i * 0.12);
        });
      });
    });
    return function () { undo.forEach(function (f) { f(); }); };
  }

  /* ---- Rejillas de cuatro columnas (.g4, solo escritorio): entrada en ola. Un trigger por rejilla; cada columna recorre más distancia que la anterior (y 32/64/96/128 a 0)
     y llega a la vez, así el borde superior dibuja una diagonal que se nivela. Solo transform y opacity; opacidad inicial .95 (contraste AA, igual que las rejillas 3D) ---- */
  function waveScene() {
    $('.g4').forEach(function (grid) {
      if (grid.closest('.hero, .footer, [data-calc], form') || hasField(grid)) return;
      var items = A.slice.call(grid.children).filter(sees);
      if (items.length < 2) return;
      g.fromTo(items, { y: function (i) { return 32 * (i + 1); }, opacity: 0.95 }, {
        y: 0, opacity: 1, ease: 'none',
        scrollTrigger: { trigger: grid, start: 'top 92%', end: 'top 45%', scrub: true, invalidateOnRefresh: true,
          onToggle: function (self) { items.forEach(function (el) { el.style.willChange = self.isActive ? 'transform, opacity' : ''; }); } }
      });
    });
  }

  /* ---- Titulares de sección (solo escritorio): la cabecera entera sube 40 px (titular, eyebrow y lead juntos: no se solapan) ---- */
  function headScene(rail) {
    var done = [];
    $('.t-h2, .t-h2-s').forEach(function (h) {
      if (h.closest('.hero, .cta, .blog-feat, .home-cards, .pcard__card, .pband') || (rail && h.closest('.home-services, .home-why'))) return;
      var par = h.parentElement, box = h.closest('.section-head') || (par && !par.matches('.container, .section, section, main') ? par : h);
      if (done.indexOf(box) >= 0 || box.querySelector('input, select, textarea')) return;
      done.push(box);
      g.fromTo(box, { y: 40 }, { y: 0, ease: 'none', scrollTrigger: { trigger: box, start: 'top 95%', end: 'top 72%', scrub: 0.4 } });
    });
  }

  /* ---- Marcos de foto grandes fuera de rejillas y héroes (video, medios de servicio, destacado del blog): entran escalando ---- */
  function mediaScene() {
    $('.px').forEach(function (px) {
      if (px.closest('.hero, .card, .home-cards, .home-steps, .g2, .g3, .g4, .g5, .g6, .blog-grid, .faq__media, .cta') || !sees(px)) return;   // el CTA, la foto sticky del FAQ y las fotos del onboarding solo llevan el parallax interno
      g.fromTo(px, { scale: 0.92 }, { scale: 1, ease: 'none', transformOrigin: '50% 50%',
        scrollTrigger: { trigger: px, start: 'top 95%', end: 'top 55%', scrub: true } });
    });
  }

  /* ---- Patrón B (foto ancha con tarjeta solapada, también el destacado del blog): la tarjeta sube 80 px con scrub y un recorrido más largo que el de la foto (que entra escalando, ver mediaScene):
     el desfase entre las dos da la profundidad. Solo transform ---- */
  function pcardScene() {
    $('.pcard__in, .blog-feat').forEach(function (box) {
      var card = one('.pcard__card, .blog-feat__body', box);
      if (!card || !sees(box)) return;
      g.fromTo(card, { y: 80 }, { y: 0, ease: 'none', scrollTrigger: { trigger: box, start: 'top 92%', end: 'top 22%', scrub: 0.6, onToggle: wc(card) } });
    });
  }

  /* ---- Patrón C (filas apiladas, >=1025px y >=700px de alto): las filas se apilan con position: sticky (motion.css) y la que queda cubierta por la siguiente se escala a .955 y su foto se atenúa a .45
     mientras la siguiente sube hasta su posición de parada. Un trigger por fila cubierta. Solo transform y opacity ---- */
  function rowsScene() {
    var hh = parseFloat(getComputedStyle(root).getPropertyValue('--header-h')) || 96;
    var stop = function (i) { return Math.round(hh + 8 + i * 14); };
    $('.prows').forEach(function (ol) {
      var rows = $('.prow', ol);
      if (rows.length < 2 || !sees(ol)) return;
      rows.forEach(function (row, i) {
        if (i === rows.length - 1) return;
        var inner = one('.prow__in', row), photo = inner && one('.prow__photo', inner);
        if (!inner) return;
        // la fila sigue opaca (si no, las de debajo se transparentan): se atenúa solo la foto, que deja ver el fondo --bg de la fila
        var tl = g.timeline({ defaults: { ease: 'none', duration: 1 },
          scrollTrigger: { trigger: rows[i + 1], start: function () { return 'top ' + (stop(i) + inner.offsetHeight); }, end: function () { return 'top ' + stop(i + 1); }, scrub: true, invalidateOnRefresh: true, onToggle: wc(inner) } });
        tl.fromTo(inner, { scale: 1 }, { scale: 0.955, transformOrigin: '50% 0' }, 0);
        if (photo) tl.fromTo(photo, { opacity: 1 }, { opacity: 0.45 }, 0);
      });
    });
  }

  /* ---- Pila de documentos que se archiva (.fshow en la sección Evidence del home, >=1025px): UN trigger con un timeline. Las tres hojas parten elevadas, inclinadas hacia atrás (rotateX) y a opacidad .9
     y bajan a la bandeja una tras otra (escalonadas): la bandeja (CSS, z-index) tapa su parte baja y quedan archivadas con el rótulo a la vista. Solo transform y opacity ---- */
  function fileScene() {
    $('.fshow').forEach(function (box) {
      var sheets = $('.fsheet', box);
      if (sheets.length < 2 || !sees(box)) return;
      var tl = g.timeline({ defaults: { ease: 'power1.out' },
        scrollTrigger: { trigger: box, start: 'top 90%', end: 'top 30%', scrub: 0.5, invalidateOnRefresh: true,
          onToggle: function (self) { sheets.forEach(function (el) { el.style.willChange = self.isActive ? 'transform, opacity' : ''; }); } } });
      sheets.forEach(function (el, i) {
        tl.fromTo(el, { y: -110 - i * 30, rotateX: 14, scale: 1.03, opacity: 0.9, transformOrigin: '50% 100%' },
                      { y: 0, rotateX: 0, scale: 1, opacity: 1, duration: 0.55 }, i * 0.22);
      });
    });
  }

  /* ---- Riel de etapas (photo-rows con rail, /how-it-works/, >=1025px y >=700px de alto): UN trigger. Recorre las filas apiladas desde que la primera para en su sitio hasta que la última para en el suyo
     (la fila k para en progreso k/(n-1): cada una avanza un alto de fila + hueco menos los 14 px del escalón sticky). La línea cian crece (scaleY 0 a 1) y cada marcador sube de opacidad y escala justo antes de
     llegar su fila. Solo transform y opacity ---- */
  function railStages() {
    var hh = parseFloat(getComputedStyle(root).getPropertyValue('--header-h')) || 96;
    $('[data-prail]').forEach(function (wrap) {
      var ol = one('.prows', wrap), fill = one('.prail__fill', wrap), ons = $('.prail__on', wrap), rows = ol ? $('.prow', ol) : [], n = rows.length;
      if (!ol || !fill || n < 2 || ons.length !== n || !sees(wrap)) return;
      var span = function () { return (n - 1) * (rows[0].offsetHeight + (parseFloat(getComputedStyle(ol).rowGap) || 0) - 14); };
      var tl = g.timeline({ defaults: { ease: 'none' },
        scrollTrigger: { trigger: ol, start: function () { return 'top ' + Math.round(hh + 8); }, end: function () { return '+=' + Math.round(span()); }, scrub: 0.3, invalidateOnRefresh: true } });
      tl.fromTo(fill, { scaleY: 0 }, { scaleY: 1, duration: 1 }, 0);
      ons.forEach(function (on, k) {
        if (k) tl.fromTo(on, { opacity: 0, scale: 0.82 }, { opacity: 1, scale: 1, duration: 0.1 }, k / (n - 1) - 0.1);
      });
    });
  }

  /* ---- Cortina fotográfica (>=1025px y >=700px de alto): la foto queda fijada por CSS (sticky, motion.css) mientras la sección siguiente (.curtain-next) sube y la tapa. Un solo trigger por cortina:
     la imagen interior (110 % del marco) se desplaza de -0,3 % a -8,8 % de su alto (yPercent, igual que .px) a lo largo de todo el recorrido del wrapper. Solo transform ---- */
  function curtainScene() {
    $('[data-curtain]').forEach(function (wrap) {
      var img = one('.curtain__frame img', wrap);
      if (!img || !sees(wrap)) return;
      g.fromTo(img, { yPercent: -0.3 }, { yPercent: -8.8, ease: 'none',
        scrollTrigger: { trigger: wrap, start: 'top bottom', end: 'bottom bottom', scrub: true, onToggle: wc(img) } });
    });
  }

  /* ---- Relleno de texto (>=1025px): cada palabra ([data-textfill] .w, la parte el helper PHP ns_words) sube de opacidad .5 a 1 con el scroll, una tras otra. Un solo trigger por bloque; solo opacity.
     Opacidad inicial .5 (no .3): --text a .5 sobre --bg mantiene AA. Sin esta escena el texto se ve completo (el CSS solo lo atenúa bajo html.js-motion) ---- */
  function textScene() {
    $('[data-textfill]').forEach(function (el) {
      var words = $('.w', el);
      if (words.length < 2 || !sees(el)) return;
      g.fromTo(words, { opacity: 0.5 }, { opacity: 1, ease: 'none', stagger: 0.12,
        scrollTrigger: { trigger: el, start: 'top 80%', end: 'bottom 58%', scrub: true } });
    });
  }

  /* ---- Cifras y pasos (home: onboarding y 4/1/0): entrada una vez, .7 s, escalonada por fila ---- */
  function enterScene() {
    $('.home-steps, .home-figures').forEach(function (ul) {
      var list = A.slice.call(ul.children).filter(function (el) { return sees(el) && el.getBoundingClientRect().top > vh() * 0.9; });
      rowsOf(list, ul.matches('.home-steps') ? 80 : 24).forEach(function (row) {   // las columnas del onboarding llevan desfase vertical (0/20/40/60 px) y cuentan como una fila
        var par = row.els[0].parentElement;
        var start = function () { return 'top+=' + Math.max(0, absTop(row.els[0]) - absTop(par)) + ' 88%'; };
        g.from(row.els, {
          y: 40, opacity: 0, duration: 0.7, ease: 'power3.out', stagger: 0.08, clearProps: 'opacity,transform',
          scrollTrigger: { trigger: par, start: start, once: true, invalidateOnRefresh: true }
        });
      });
    });
  }

  /* ---- Onboarding (home): la línea cian de cada columna se rellena (scaleX 0 a 1, scrub) escalonada de izquierda a derecha; en móvil, una por paso ---- */
  function lineScene() {
    var ol = one('.home-steps');
    if (!ol) return;
    var cols = $('.home-step', ol).filter(sees);
    rowsOf(cols, 80).forEach(function (row) {
      var off = function () { return Math.max(0, absTop(row.els[0]) - absTop(ol)); };
      var tl = g.timeline({
        defaults: { ease: 'none' },
        scrollTrigger: { trigger: ol, start: function () { return 'top+=' + off() + ' 88%'; }, end: function () { return 'top+=' + off() + ' 38%'; }, scrub: 0.5, invalidateOnRefresh: true }
      });
      row.els.forEach(function (li, i) {
        var bar = one('.home-step__line i', li);
        if (bar) tl.fromTo(bar, { scaleX: 0 }, { scaleX: 1, duration: 0.6 }, i * 0.2);
      });
    });
  }

  /* ---- Banda de video (home): la capa con póster y video se desplaza ±4 % de su alto (110 % del marco) mientras la banda cruza la ventana; no toca el bucle ---- */
  function vbandScene() {
    $('.vband__media').forEach(function (m) {
      var layer = one('.vband__layer', m);
      if (!layer || !sees(m)) return;
      g.fromTo(layer, { yPercent: -4 }, { yPercent: 4, ease: 'none', scrollTrigger: { trigger: m, start: 'top bottom', end: 'bottom top', scrub: true } });
    });
  }

  function build(ctx) {
    var c = ctx.conditions, undo = [];
    if (c.desk) {   // los pines primero: así el resto de triggers mide ya con los espaciadores de pin
      heroScene();
      var off = c.rail ? railScene() : null;
      if (off) undo.push(off);
      var offWhy = c.rail ? whyScene() : null;
      if (offWhy) undo.push(offWhy);
    }
    photoScene(!!c.rail);
    lineScene();
    if (c.desk) vbandScene();
    if (c.desk) {
      heroInt();
      undo.push(gridScene(!!c.rail));
      waveScene();
      headScene(!!c.rail);
      mediaScene();
      pcardScene();
      if (c.rail) { rowsScene(); railStages(); curtainScene(); }
      fileScene();
      textScene();
    }
    enterScene();
    bandScene(!!c.desk, !!c.rail);
    return function () { undo.forEach(function (f) { f(); }); };
  }

  // Si cambia la altura de la página (FAQ abierta, filas nuevas del IFTA, filtro del blog) las posiciones guardadas se recalculan
  function watch() {
    var last = d.body.offsetHeight, t = 0;
    function again() { clearTimeout(t); t = setTimeout(function () { if (!dead) ST.refresh(); last = d.body.offsetHeight; }, 220); }
    d.addEventListener('toggle', again, true);
    d.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-blog-filter], [data-calc]')) again(); });
    if (window.ResizeObserver) new window.ResizeObserver(function () { if (Math.abs(d.body.offsetHeight - last) > 2) again(); }).observe(d.body);
  }

  function start() {
    if (dead || root.classList.contains('js-motion')) return;
    try {
      root.classList.add('js-motion');
      unpre();   // la reserva se retira en este mismo turno en que ScrollTrigger inserta el pin-spacer: ningún pintado entre medias, así que la página no se mueve
      mm = g.matchMedia();
      mm.add({ all: '(min-width: 1px)', desk: '(min-width: 1025px)', rail: '(min-width: 1025px) and (min-height: 700px)' }, build);
      ST.refresh();
      watch();
      if (d.fonts && d.fonts.ready) d.fonts.ready.then(function () { if (!dead) ST.refresh(); });
      if (rm && rm.addEventListener) rm.addEventListener('change', function () { if (rm.matches) fail(); });
    } catch (e) { fail(); }
  }

  function boot() {
    if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 1200 });
    else setTimeout(start, 120);
  }
  if (d.readyState === 'complete') boot(); else window.addEventListener('load', boot);
  }
})();
