/* NorthStar Freight — main.js: reloj del header, pastilla y compactación del header, mega menús y pop-up móvil. Mejora progresiva: sin JS el sitio funciona. */
(function () {
  'use strict';
  var d = document;
  var mq = window.matchMedia ? window.matchMedia('(max-width: 1024px)') : { matches: false };
  var onMq = function (fn) {
    if (!mq.addEventListener) { if (mq.addListener) mq.addListener(fn); return; }
    mq.addEventListener('change', fn);
  };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  /* ---- Reloj del chip del header (span[data-t="et"]; si hubiera más zonas con data-t, también las rellena) ---- */
  (function () {
    var els = $$('[data-t]');
    if (!els.length || typeof Intl === 'undefined' || !Intl.DateTimeFormat) return;
    var zones = { et: 'America/New_York', ct: 'America/Chicago', mt: 'America/Denver', pt: 'America/Los_Angeles' };
    var fmt = {}, timer;
    function tick() {
      var now = new Date();
      els.forEach(function (el) {
        var f = fmt[el.getAttribute('data-t')];
        if (f) { try { el.textContent = f.format(now); } catch (e) { /* conserva --:-- */ } }
      });
    }
    function next() {
      clearTimeout(timer);
      if (d.hidden) return;
      tick();
      timer = setTimeout(next, 20000 - (Date.now() % 20000) + 30); // alineado a múltiplos de 20 s (incluye inicio de minuto)
    }
    function init() { // crear los 4 Intl.DateTimeFormat es lo más caro de main.js: fuera del camino del primer pintado
      try {
        Object.keys(zones).forEach(function (k) {
          if (!els.some(function (el) { return el.getAttribute('data-t') === k; })) return;
          fmt[k] = new Intl.DateTimeFormat('en-US', { timeZone: zones[k], hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
        });
      } catch (e) { return; }
      d.addEventListener('visibilitychange', next);
      next();
    }
    if (window.requestIdleCallback) window.requestIdleCallback(init, { timeout: 1000 }); else setTimeout(init, 200);
  })();

  /* ---- Header: compactación al hacer scroll (.is-scrolled en el <header> pasados ~80 px; un centinela + IntersectionObserver, sin listener de scroll) ---- */
  (function () {
    var header = d.getElementById('site-header');
    if (!header) return;
    function set(on) { header.classList.toggle('is-scrolled', on); }
    if ('IntersectionObserver' in window) {
      var s = d.createElement('div');
      s.setAttribute('aria-hidden', 'true');
      s.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:80px;pointer-events:none;visibility:hidden';
      d.body.appendChild(s);
      new IntersectionObserver(function (es) { set(!es[es.length - 1].isIntersecting); }).observe(s);
    } else {
      var tick = false;
      window.addEventListener('scroll', function () {
        if (tick) return;
        tick = true;
        requestAnimationFrame(function () { tick = false; set((window.pageYOffset || 0) > 80); });
      }, { passive: true });
    }
  })();

  /* ---- Pastilla de la navegación: una sola pastilla (--cyan-tint) que se desliza (transform) al ítem con hover/foco, al abierto o al de la página actual ---- */
  (function () {
    var box = d.querySelector('.nav__links'), pill = box && box.querySelector('.nav__pill');
    if (!box || !pill) return;
    var items = $$('.nav__link, .nav__trigger', box), over = null;
    if (!items.length) return;
    function rest() {
      return items.filter(function (i) { return i.getAttribute('aria-expanded') === 'true'; })[0]
        || items.filter(function (i) { return i.getAttribute('aria-current') === 'page'; })[0] || null;
    }
    function put(el, snap) {
      if (!el || !el.offsetWidth) { pill.classList.remove('is-on'); return; }
      var first = !pill.classList.contains('is-on');
      if (snap || first) pill.classList.add('snap');
      pill.style.setProperty('--pill-x', el.offsetLeft + 'px');
      pill.style.setProperty('--pill-w', el.offsetWidth + 'px');
      pill.classList.add('is-on');
      if (snap || first) { void pill.offsetWidth; pill.classList.remove('snap'); }
    }
    function sync(snap) { put(over || rest(), snap); }
    box.classList.add('has-pill');
    items.forEach(function (i) {
      i.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') { over = i; sync(); } });
      i.addEventListener('focus', function () { over = i; sync(); });
      i.addEventListener('blur', function () { over = null; sync(); });
    });
    box.addEventListener('pointerleave', function () { over = null; sync(); });
    if (window.MutationObserver) {
      var mo = new MutationObserver(function () { sync(); });
      items.forEach(function (i) { mo.observe(i, { attributes: true, attributeFilter: ['aria-expanded'] }); });
    }
    window.addEventListener('resize', function () { sync(true); });
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(function () { sync(true); });
    onMq(function () { sync(true); });
    sync(true);
  })();

  /* ---- Widget "Start in WhatsApp" (hero del home): pestañas (quién eres) + chips (en qué ayudamos) => mensaje y enlace wa.me.
     No se guarda ni se envía ningún dato. Sin JS el botón conserva el enlace por defecto de la ruta y los controles siguen ocultos. ---- */
  (function () {
    var w = d.querySelector('[data-wa-widget]');
    if (!w) return;
    var ui = w.querySelector('[data-wa-ui]'), note = w.querySelector('[data-wa-note]'), msg = w.querySelector('[data-wa-msg]'), go = w.querySelector('[data-wa-open]');
    var panel = w.querySelector('[role="tabpanel"]'), base = w.getAttribute('data-wa-base');
    var tabs = $$('[role="tab"]', w), chips = $$('[data-svc]', w);
    if (!ui || !msg || !go || !base || !tabs.length) return;
    function text() {
      var who = (tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0]).getAttribute('data-who');
      var svc = chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; }).map(function (c) { return c.getAttribute('data-svc'); });
      var list = svc.length > 1 ? svc.slice(0, -1).join(', ') + ' and ' + svc[svc.length - 1] : (svc[0] || '');
      return 'Hi, ' + who + " and I'd like " + (list ? 'help with ' + list : 'to talk about dispatch') + ' with NorthStar.';
    }
    function sync() {
      var t = text();
      msg.textContent = t;
      go.setAttribute('href', base + '?text=' + encodeURIComponent(t).replace(/'/g, '%27'));
    }
    function pick(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
      });
      if (panel) panel.setAttribute('aria-labelledby', tab.id);
      if (focus) tab.focus();
      sync();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { pick(t, false); });
      t.addEventListener('keydown', function (e) {
        var n = null;
        if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
        else if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
        else if (e.key === 'Home') n = 0;
        else if (e.key === 'End') n = tabs.length - 1;
        if (n === null) return;
        e.preventDefault();
        pick(tabs[n], true);
      });
    });
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        sync();
      });
    });
    ui.hidden = false;
    if (note) note.hidden = true;
    sync();
  })();

  /* ---- Videos de fondo (hero del home y banda de video). Los <video> viajan vacíos en el HTML (preload none, sin src): aquí se les pone la fuente.
     Solo en >=1025px, sin prefers-reduced-motion ni saveData, tras `load` + idle (así la foto del hero sigue siendo el LCP). Son escenas breves SIN bucle:
     se reproducen UNA vez y se quedan en el último fotograma (no se reinician aunque la sección salga y vuelva a entrar). El hero arranca ya; la banda empieza
     al ser visible (y empieza a bajar el archivo cuando se acerca a la pantalla). Ambos se pausan al salir de ella y se reanudan donde iban si aún no terminaron.
     Si falla o no carga, queda la foto / el póster. Fundido de opacidad vía .has-video. ---- */
  (function () {
    var hero = d.querySelector('[data-hero-video]'), band = d.querySelector('[data-vband]');
    if ((!hero && !band) || !window.matchMedia) return;
    function allowed() {
      var conn = navigator.connection || {};
      return !(conn.saveData || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(min-width: 1025px)').matches);
    }
    // wrap: elemento que recibe .has-video (y lo observa IntersectionObserver); v: <video> ya en el HTML; src: mp4; webm: opcional; lazy: banda (precarga cerca, reproduce al verse)
    function mount(wrap, v, mp4, webm, lazy) {
      if (!v || !v.canPlayType || v.firstChild) return;
      [[webm, 'video/webm'], [mp4, 'video/mp4']].forEach(function (s) {
        if (!s[0] || !v.canPlayType(s[1])) return;
        var el = d.createElement('source'); el.src = s[0]; el.type = s[1]; v.appendChild(el);
      });
      if (!v.firstChild) return;
      var done = false, on = false, ended = false;
      function drop() { if (done) return; done = true; wrap.classList.remove('has-video'); while (v.firstChild) v.removeChild(v.firstChild); try { v.load(); } catch (e) { /* nada */ } }
      function play() { if (ended) return; try { var p = v.play(); if (p && p.catch) p.catch(drop); } catch (e) { drop(); } }
      v.muted = true; v.defaultMuted = true; v.loop = false; v.playsInline = true;
      v.addEventListener('error', drop, true);
      v.addEventListener('playing', function () { if (!done) wrap.classList.add('has-video'); });
      v.addEventListener('ended', function () { ended = true; });   // se queda en el último fotograma: no se vuelve a llamar a play() (reiniciaría)
      try { v.load(); } catch (e) { drop(); return; }
      function watch(vis) { if (done || ended || vis === on) return; on = vis; if (vis) play(); else v.pause(); }
      if ('IntersectionObserver' in window) {
        if (lazy) new IntersectionObserver(function (es, o) { if (es[es.length - 1].isIntersecting) { v.preload = 'auto'; o.disconnect(); } }, { rootMargin: '300px 0px' }).observe(wrap);
        new IntersectionObserver(function (es) { watch(es[es.length - 1].isIntersecting); }, { threshold: lazy ? 0.3 : 0 }).observe(wrap);
      } else if (!lazy) { on = true; play(); }
    }
    function start() {
      if (!allowed()) return;
      if (hero) mount(hero, hero.querySelector('video'), hero.getAttribute('data-mp4'), hero.getAttribute('data-webm'), false);
      if (band) mount(band.parentNode, band.querySelector('video'), band.getAttribute('data-mp4'), '', true);
    }
    function later() { if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 2500 }); else setTimeout(start, 400); }
    if (d.readyState === 'complete') later(); else window.addEventListener('load', later);
  })();

  /* ---- Índice con vista previa ([data-idx], partial index-list): el cursor o el foco en una fila la activa (.is-on) y muestra su foto (fundido de opacidad en CSS). .is-live atenúa las demás filas (solo >=1025px) ---- */
  (function () {
    $$('[data-idx]').forEach(function (box) {
      var items = $$('.idx__item', box), pvs = $$('.idx__pv', box);
      if (items.length < 2) return;
      var cur = 0;
      function set(i) {
        if (i === cur) return; cur = i;
        items.forEach(function (it, k) { it.classList.toggle('is-on', k === i); });
        pvs.forEach(function (p, k) { p.classList.toggle('is-on', k === i); });
      }
      items.forEach(function (it, i) {
        it.addEventListener('mouseenter', function () { set(i); });
        it.addEventListener('focusin', function () { set(i); });
      });
      box.classList.add('is-live');
    });
  })();

  /* ---- FAQ con foto por tema ([data-faq-photos], partial faq con image.topics): al abrir una pregunta (<details>, evento toggle) la foto de su tema (data-topic) pasa a .is-on (fundido de opacidad en CSS).
     Al cerrar la pregunta activa vuelve el tema de otra pregunta que siga abierta; si no queda ninguna se mantiene. Solo cambia opacidad y aria-hidden: el foco y el texto no se tocan. ---- */
  (function () {
    $$('[data-faq-photos]').forEach(function (fig) {
      var root = fig.closest('.faq'), layers = $$('.faq__layer', fig), cur = -1;
      if (!root || layers.length < 2) return;
      layers.forEach(function (l, k) { if (l.classList.contains('is-on')) cur = k; });
      function show(k) {
        if (k === cur || !layers[k]) return; cur = k;
        layers.forEach(function (l, i) { l.classList.toggle('is-on', i === k); if (i === k) l.removeAttribute('aria-hidden'); else l.setAttribute('aria-hidden', 'true'); });
      }
      function topicOf(det) { var t = parseInt(det.getAttribute('data-topic'), 10); return t >= 0 && t < layers.length ? t : 0; }
      root.addEventListener('toggle', function (e) {
        var det = e.target; if (!det || !det.matches || !det.matches('details.faq__item')) return;
        if (det.open) { show(topicOf(det)); return; }
        var open = $$('details.faq__item[open]', root);
        if (open.length && open.every(function (o) { return topicOf(o) !== cur; })) show(topicOf(open[open.length - 1]));
      }, true);
    });
  })();

  /* ---- Mega menús ---- */
  (function () {
    var header = d.getElementById('site-header');
    var triggers = $$('[data-mega-trigger]');
    if (!header || !triggers.length) return;
    var current = null, byHover = false, leaveTimer;

    function panelOf(t) { return d.getElementById(t.getAttribute('aria-controls')); }
    function links(p) { return $$('a[href]', p); }
    function setOpen(t, on) {
      var p = panelOf(t);
      if (!p) return;
      p.hidden = !on;
      t.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    function close(focusTrigger) {
      clearTimeout(leaveTimer);
      if (!current) return;
      var t = current, p = panelOf(t);
      current = null;
      byHover = false;
      var hadFocus = p && p.contains(d.activeElement);
      setOpen(t, false);
      if (focusTrigger || hadFocus) t.focus();
    }
    function open(t, viaHover) {
      if (mq.matches) return;
      clearTimeout(leaveTimer);
      if (current === t) return;
      if (current) setOpen(current, false);
      current = t;
      byHover = !!viaHover;
      setOpen(t, true);
    }

    triggers.forEach(function (t) {
      t.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') open(t, true); });
      t.addEventListener('click', function (e) {
        if (mq.matches) return;
        if (current === t) {
          if (byHover && e.detail > 0) { byHover = false; return; } // clic tras abrir por hover: lo deja fijo
          close(false);
        } else open(t, false);
      });
      t.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' || mq.matches) return;
        e.preventDefault();
        open(t, false);
        var l = links(panelOf(t));
        if (l[0]) l[0].focus();
      });
    });
    header.addEventListener('pointerenter', function () { clearTimeout(leaveTimer); });
    header.addEventListener('pointerleave', function (e) {
      if (e.pointerType !== 'mouse' || !current) return;
      clearTimeout(leaveTimer);
      leaveTimer = setTimeout(function () { close(false); }, 120);
    });
    header.addEventListener('keydown', function (e) {
      if (!current) return;
      if (e.key === 'Escape') { e.stopPropagation(); close(true); return; }
      var p = panelOf(current);
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && p.contains(e.target)) {
        var l = links(p), i = l.indexOf(d.activeElement);
        e.preventDefault();
        if (e.key === 'ArrowUp' && i <= 0) { current.focus(); return; }
        var n = e.key === 'ArrowDown' ? Math.min(i + 1, l.length - 1) : i - 1;
        if (l[n]) l[n].focus();
      }
    });
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current) close(false); });   // abierto solo por hover (sin foco dentro del header): Esc también lo cierra (WCAG 1.4.13)
    d.addEventListener('pointerdown', function (e) { if (current && !header.contains(e.target)) close(false); });
    d.addEventListener('focusin', function (e) {
      if (!current) return;
      var p = panelOf(current);
      if (e.target !== current && !(p && p.contains(e.target))) close(false);
    });
    onMq(function () { if (mq.matches) close(false); });
  })();

  /* ---- Pop-up móvil ---- */
  (function () {
    var menu = d.getElementById('mobile-menu');
    var burger = d.querySelector('[data-menu-open]');
    if (!menu || !burger) return;
    var body = d.body, root = d.documentElement, isOpen = false, lastFocus = null, y = 0;
    var closeBtn = menu.querySelector('.mobile-menu__close');
    var backdrop = menu.querySelector('.mobile-menu__backdrop');

    function focusables() {
      return $$('a[href], button:not([disabled]), summary, [tabindex]:not([tabindex="-1"])', menu).filter(function (el) {
        return el !== backdrop && el.getClientRects().length > 0;
      });
    }
    function lock() {
      y = window.pageYOffset || root.scrollTop || 0;
      var sb = window.innerWidth - root.clientWidth;
      body.style.position = 'fixed';
      body.style.top = -y + 'px';
      body.style.left = '0';
      body.style.right = '0';
      if (sb > 0) body.style.paddingRight = sb + 'px';
    }
    function unlock() {
      body.style.position = body.style.top = body.style.left = body.style.right = body.style.paddingRight = '';
      try { window.scrollTo({ top: y, left: 0, behavior: 'instant' }); } catch (e) { window.scrollTo(0, y); }
    }
    function open() {
      if (isOpen) return;
      isOpen = true;
      lastFocus = d.activeElement;
      lock();
      menu.hidden = false;
      body.classList.add('menu-open');
      burger.setAttribute('aria-expanded', 'true');
      burger.setAttribute('aria-label', 'Close menu');
      if (backdrop) backdrop.tabIndex = -1;
      (closeBtn || focusables()[0] || menu).focus();
    }
    function close(skipFocus) {
      if (!isOpen) return;
      isOpen = false;
      menu.hidden = true;
      body.classList.remove('menu-open');
      burger.setAttribute('aria-expanded', 'false');
      burger.setAttribute('aria-label', 'Open menu');
      unlock();
      var back = lastFocus && lastFocus.focus ? lastFocus : burger;
      if (!skipFocus && back.getClientRects().length) back.focus({ preventScroll: true });
    }

    burger.addEventListener('click', function () { isOpen ? close() : open(); });
    menu.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest) return;
      if (t.closest('[data-menu-close]') || t.closest('a[href]')) close();
    });
    d.addEventListener('keydown', function (e) {
      if (!isOpen) return;
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key !== 'Tab') return;
      var f = focusables();
      if (!f.length) { e.preventDefault(); return; }
      var first = f[0], last = f[f.length - 1], a = d.activeElement;
      if (!menu.contains(a)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && a === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && a === last) { e.preventDefault(); first.focus(); }
    });
    d.addEventListener('focusin', function (e) { if (isOpen && !menu.contains(e.target)) (closeBtn || menu).focus(); });
    onMq(function () { if (!mq.matches) close(); });
  })();

  /* ---- Captación de leads: atribución de primera parte y evento de analítica ----
     (1) Al aterrizar guarda utm_*, referrer (sin query) y landing en localStorage (30 días; sin cookies) y los vuelca al campo oculto [data-attr-field] de los formularios de solicitud ([data-attr-form]).
         Sin JS o sin localStorage el lead entra sin atribución (válido). La política de privacidad debe mencionarlo.
     (2) En la página de gracias ([data-lead-event]) emite generate_lead (gtag o dataLayer) y Lead (Meta) SOLO si esas herramientas ya existen en la página: no se carga ningún script de terceros. */
  (function () {
    var KEY = 'ns_attr', DAY = 864e5, keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var store = null, rec = null, now = Date.now();
    try { store = window.localStorage; } catch (e) { store = null; }
    try { rec = JSON.parse(store.getItem(KEY) || 'null'); } catch (e) { rec = null; }
    if (rec && (!rec.t || now - rec.t > 30 * DAY)) rec = null;
    var q = {};
    try {
      var sp = new URLSearchParams(location.search);
      keys.forEach(function (k) { var v = sp.get(k); if (v) q[k] = v.slice(0, 100); });
    } catch (e) { /* sin URLSearchParams */ }
    var hasUtm = Object.keys(q).length > 0;
    if (!rec || (hasUtm && !keys.some(function (k) { return rec[k]; }))) {
      rec = { t: now, first_seen: new Date(now).toISOString(), landing: (location.pathname || '/').slice(0, 300), referrer: (d.referrer || '').split('?')[0].split('#')[0].slice(0, 300) };
      keys.forEach(function (k) { rec[k] = q[k] || ''; });
      try { store.setItem(KEY, JSON.stringify(rec)); } catch (e) { /* sin almacenamiento: vale para esta página */ }
    }
    $$('[data-attr-field]').forEach(function (f) {
      var out = {};
      ['first_seen', 'landing', 'referrer'].concat(keys).forEach(function (k) { out[k] = rec[k] || ''; });
      f.value = JSON.stringify(out);
    });
    var ev = d.querySelector('[data-lead-event]');
    if (ev) {
      window.addEventListener('load', function () {
        var type = ev.getAttribute('data-lead-type') || '', mark = 'ns_lead_evt';
        try { if (sessionStorage.getItem(mark) === location.search) return; sessionStorage.setItem(mark, location.search); } catch (e) { /* se emite igualmente */ }
        if (typeof window.gtag === 'function') window.gtag('event', 'generate_lead', { lead_type: type });
        else if (window.dataLayer && typeof window.dataLayer.push === 'function') window.dataLayer.push({ event: 'generate_lead', lead_type: type });
        if (typeof window.fbq === 'function') window.fbq('track', 'Lead', { content_name: type });
      });
    }
  })();
})();
