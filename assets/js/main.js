/* NorthStar Freight — main.js: relojes de la ops bar, megas, pop-up móvil. Mejora progresiva: sin JS el sitio funciona. */
(function () {
  'use strict';
  var d = document;
  var mq = window.matchMedia ? window.matchMedia('(max-width: 1024px)') : { matches: false };
  var onMq = function (fn) {
    if (!mq.addEventListener) { if (mq.addListener) mq.addListener(fn); return; }
    mq.addEventListener('change', fn);
  };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  /* ---- Relojes (ops bar) ---- */
  (function () {
    var els = $$('.opsbar [data-t]');
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
          fmt[k] = new Intl.DateTimeFormat('en-US', { timeZone: zones[k], hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
        });
      } catch (e) { return; }
      d.addEventListener('visibilitychange', next);
      next();
    }
    if (window.requestIdleCallback) window.requestIdleCallback(init, { timeout: 1000 }); else setTimeout(init, 200);
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
  /* ---- Header: .is-scrolled en <html> al pasar ~80 px (IntersectionObserver sobre un centinela; el CSS lo estila) ---- */
  (function () {
    if (!('IntersectionObserver' in window)) return;
    var s = d.createElement('div');
    s.setAttribute('aria-hidden', 'true');
    s.style.cssText = 'position:absolute;top:80px;left:0;width:1px;height:1px;pointer-events:none;visibility:hidden';
    d.body.appendChild(s);
    new IntersectionObserver(function (en) {
      var e = en[en.length - 1];
      d.documentElement.classList.toggle('is-scrolled', !e.isIntersecting && e.boundingClientRect.top < 0);
    }).observe(s);
  })();

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Progreso de lectura (artículos): barra de 2 px bajo el header; --p 0..1 (animation-timeline si existe) ---- */
  (function () {
    var art = d.querySelector('.blog-prose'), header = d.getElementById('site-header');
    if (!art || !header || reduce) return;
    var bar = d.createElement('div');
    bar.className = 'read-progress';
    bar.setAttribute('aria-hidden', 'true');
    header.appendChild(bar);
    if (window.CSS && CSS.supports && CSS.supports('animation-timeline', 'scroll()')) { bar.setAttribute('data-native', ''); return; }
    var raf = 0;
    function upd() {
      raf = 0;
      var r = art.getBoundingClientRect(), span = r.height - window.innerHeight * 0.5;
      bar.style.setProperty('--p', Math.max(0, Math.min(1, span > 0 ? -r.top / span : 0)).toFixed(3));
    }
    function sched() { if (!raf) raf = requestAnimationFrame(upd); }
    window.addEventListener('scroll', sched, { passive: true });
    window.addEventListener('resize', sched);
    sched();
  })();

  /* ---- Calculadoras: .is-tick ~400 ms en el resultado que cambia (observa las regiones aria-live) ---- */
  (function () {
    if (!window.MutationObserver || reduce) return;
    var live = $$('[data-calc] [aria-live]'), ready = false, timers = new WeakMap();
    if (!live.length) return;
    setTimeout(function () { ready = true; }, 700);
    var mo = new MutationObserver(function (ms) {
      if (!ready) return;
      ms.forEach(function (m) {
        var n = m.target.nodeType === 1 ? m.target : m.target.parentElement, o = n && n.closest ? n.closest('[data-out]') : null;
        if (!o) return;
        o.classList.remove('is-tick');
        void o.offsetWidth;
        o.classList.add('is-tick');
        clearTimeout(timers.get(o));
        timers.set(o, setTimeout(function () { o.classList.remove('is-tick'); }, 420));
      });
    });
    live.forEach(function (el) { mo.observe(el, { childList: true, characterData: true, subtree: true }); });
  })();

  /* ---- Chips del blog: --cx/--cy/--cw/--ch del chip activo (el CSS desliza el fondo; sin Flip) ---- */
  (function () {
    var box = d.querySelector('[data-blog-filter]');
    if (!box) return;
    function pos() {
      var on = box.querySelector('[aria-pressed="true"]');
      if (!on) return;
      box.style.setProperty('--cx', on.offsetLeft + 'px');
      box.style.setProperty('--cy', on.offsetTop + 'px');
      box.style.setProperty('--cw', on.offsetWidth + 'px');
      box.style.setProperty('--ch', on.offsetHeight + 'px');
      box.classList.add('has-slide');
    }
    if (window.MutationObserver) new MutationObserver(pos).observe(box, { attributes: true, attributeFilter: ['aria-pressed'], subtree: true });
    window.addEventListener('resize', pos);
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(pos); else setTimeout(pos, 0); // sin lectura de layout síncrona en la carga
  })();
})();
