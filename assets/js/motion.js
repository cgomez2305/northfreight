/* NorthStar Freight — motion.js: entradas de sección con GSAP + ScrollTrigger (solo transform/opacity).
   Una animación orquestada por sección (cabecera y tarjetas escalonadas). Sin GSAP o con reduced-motion: no hace nada. */
(function () {
  'use strict';
  var g = window.gsap, ST = window.ScrollTrigger;
  var rm = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  if (!g || !ST || (rm && rm.matches)) return;

  var GRID = /(^|\s)(g[2-6]h?|grid|cards|row)(\s|$)/;
  var touched = [];
  var vh = window.innerHeight || document.documentElement.clientHeight;

  try {
    g.registerPlugin(ST);
    Array.prototype.slice.call(document.querySelectorAll('main > section, body > .cta')).forEach(function (sec) {
      if (sec.matches('.hero, [data-no-motion]')) return;
      if (sec.getBoundingClientRect().top < vh * 0.9) return; // ya visible al cargar: sin animación (sin FOUC ni saltos)
      var box = sec.querySelector('.container') || sec;
      var targets = [];
      Array.prototype.slice.call(box.children).forEach(function (el) {
        if (el.matches('.anim-rise, script, style')) return;
        if (GRID.test(el.className || '') && el.children.length > 1) {
          Array.prototype.push.apply(targets, Array.prototype.slice.call(el.children));
        } else targets.push(el);
      });
      targets = targets.filter(function (el) { return el.getClientRects().length > 0; });
      if (!targets.length) return;
      // estado inicial solo para lo que aún está bajo el pliegue; el resto no se toca
      g.set(targets, { opacity: 0, y: 22 });
      touched.push.apply(touched, targets);
      g.timeline({ scrollTrigger: { trigger: sec, start: 'top 88%', once: true } })
        .to(targets, { opacity: 1, y: 0, duration: 0.5, ease: 'power2.out', stagger: { each: Math.min(0.07, 0.3 / targets.length) }, clearProps: 'opacity,transform' });
    });
  } catch (e) { // ante cualquier fallo: todo visible
    touched.forEach(function (el) { el.style.opacity = ''; el.style.transform = ''; });
    try { ST.getAll().forEach(function (t) { t.kill(); }); } catch (e2) { /* nada */ }
    return;
  }

  if (rm && rm.addEventListener) {
    rm.addEventListener('change', function () {
      if (!rm.matches) return;
      ST.getAll().forEach(function (t) { t.kill(); });
      g.killTweensOf(touched);
      g.set(touched, { clearProps: 'opacity,transform' });
    });
  }
})();
