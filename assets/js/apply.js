/* NorthStar Freight — apply.js: solicitud multipaso de /careers/apply/. Mejora progresiva: sin JS los cuatro <fieldset> van apilados y se envía de una vez.
   Con JS: un paso a la vez, barra de progreso, validación por paso (mismos mensajes que el servidor, leídos de data-msg), foco gestionado, aria-live para el progreso
   y sin pérdida de datos al volver atrás (los campos no salen del DOM; el botón Atrás del navegador también retrocede un paso). El servidor valida todo de nuevo. */
(function () {
  'use strict';
  var d = document;
  var form = d.querySelector('[data-apply]');
  if (!form) return;
  var t = function (s) { var i = window.NS_I18N; return (i && i[s]) || s; };
  var $ = function (s, r) { return (r || form).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || form).querySelectorAll(s)); };
  var steps = $$('[data-ap-step]');
  var total = steps.length;
  var names = [t('Who you are'), t('Your truck and experience'), t('Eligibility'), t('How to reach you')];
  var prog = $('[data-ap-prog]'), count = $('[data-ap-count]'), bar = $('[data-ap-bar]'), live = $('[data-ap-live]');
  var back = $('[data-ap-back]'), next = $('[data-ap-next]'), submit = $('[data-ap-submit]');
  var cur = 1;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  form.setAttribute('novalidate', '');
  form.classList.add('ap--js');
  d.documentElement.classList.remove('js-apply');   /* el CSS previo (html.js-apply) ya mostraba el paso 1; desde aquí manda .ap--js */

  /* Campos que no aplican a company drivers (data-ap-only="perfiles separados por espacio") */
  function applyProfile() {
    var p = $('input[name="profile"]:checked');
    var val = p ? p.value : '';
    $$('[data-ap-only]').forEach(function (el) {
      el.hidden = !!val && el.getAttribute('data-ap-only').split(' ').indexOf(val) === -1;
    });
  }

  function errBox(ctl, name) {
    var id = 'ap-err-' + name, box = d.getElementById(id);
    if (!box) {
      box = d.createElement('span');
      box.className = 'ap__err';
      box.id = id;
      (ctl.closest('.field') || ctl.closest('.ap__group') || ctl.parentNode).appendChild(box);
    }
    return box;
  }
  function setErr(ctl, name, msg) {
    var box = errBox(ctl, name);
    box.textContent = msg;
    box.hidden = false;
    (ctl.classList.contains('ap__group') ? $$('input', ctl) : [ctl]).forEach(function (i) { i.setAttribute('aria-invalid', 'true'); });
    ctl.setAttribute('aria-describedby', box.id);
  }
  function clearErr(ctl, name) {
    var box = d.getElementById('ap-err-' + name);
    if (box) { box.textContent = ''; box.hidden = true; }
    (ctl.classList.contains('ap__group') ? $$('input', ctl) : [ctl]).forEach(function (i) { i.removeAttribute('aria-invalid'); });
    ctl.removeAttribute('aria-describedby');
  }

  /* Valida el paso n: marca todos los errores del paso y devuelve el primer control inválido (o null) */
  function validate(n) {
    var first = null;
    $$('[data-ap-req], [data-ap-kind], [name="trucks"]', steps[n - 1]).forEach(function (ctl) {
      if (ctl.closest('[data-ap-only][hidden]')) return;
      var group = ctl.classList.contains('ap__group');
      var name = group ? ctl.getAttribute('data-ap-group') : ctl.name;
      var msg = ctl.getAttribute('data-msg') || t('Please check this answer.');
      var bad = false;
      if (group) {
        bad = !ctl.querySelector('input:checked');
      } else {
        var v = (ctl.value || '').trim();
        var kind = ctl.getAttribute('data-ap-kind');
        if (ctl.hasAttribute('data-ap-req') && v === '') bad = true;
        else if (v !== '' && kind === 'phone') { var dg = v.replace(/\D/g, ''); bad = dg.length < 10 || dg.length > 15; }
        else if (v !== '' && kind === 'email') bad = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
        else if (v !== '' && ctl.type === 'number') {
          var num = Number(v);
          bad = !/^\d+$/.test(v) || (ctl.min !== '' && num < Number(ctl.min)) || (ctl.max !== '' && num > Number(ctl.max));
        }
      }
      if (bad) { setErr(ctl, name, msg); if (!first) first = group ? ctl.querySelector('input') : ctl; }
      else clearErr(ctl, name);
    });
    return first;
  }

  function show(n, opts) {
    opts = opts || {};
    cur = Math.max(1, Math.min(total, n));
    steps.forEach(function (s, i) { s.hidden = i !== cur - 1; });
    count.textContent = t('Step ') + cur + t(' of ') + total + ': ' + names[cur - 1];
    bar.style.transform = 'scaleX(' + (cur / total) + ')';
    $$('[data-ap-dot]').forEach(function (li) {
      var k = Number(li.getAttribute('data-ap-dot'));
      if (k === cur) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
      li.classList.toggle('is-done', k < cur);
    });
    back.hidden = cur === 1;
    next.hidden = cur === total;
    submit.hidden = cur !== total;
    if (opts.focus !== false) {
      var lg = $('.ap__legend', steps[cur - 1]);
      if (lg) lg.focus({ preventScroll: true });
      form.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
    }
    if (opts.push) {
      try { history.pushState({ ap: cur }, '', '#step-' + cur); } catch (e) { /* sin historial: el botón Atrás del formulario sigue funcionando */ }
    }
  }

  function go(n) {
    if (n > cur) {
      var bad = validate(cur);
      if (bad) {
        live.textContent = t('Please check the highlighted answers.');
        bad.focus();
        return;
      }
      live.textContent = '';
    }
    show(n, { push: true });
  }

  next.addEventListener('click', function () { go(cur + 1); });
  back.addEventListener('click', function () { go(cur - 1); });
  window.addEventListener('popstate', function (e) { show(e.state && e.state.ap ? e.state.ap : 1); });

  /* Enter dentro de un campo avanza de paso (no envía el formulario hasta el último) */
  form.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || cur === total) return;
    var t = e.target;
    if (!t || t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.tagName === 'A') return;
    e.preventDefault();
    go(cur + 1);
  });

  /* Un error se limpia en cuanto el usuario corrige el campo */
  form.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    var group = t.closest('.ap__group');
    var ctl = group && group.hasAttribute('data-ap-req') ? group : t;
    var name = group ? group.getAttribute('data-ap-group') : t.name;
    if (d.getElementById('ap-err-' + name) && (t.getAttribute('aria-invalid') === 'true' || (group && group.querySelector('[aria-invalid="true"]')))) clearErr(ctl, name);
  });
  form.addEventListener('change', function (e) { if (e.target && e.target.name === 'profile') applyProfile(); });

  /* Al enviar: valida todos los pasos desde el primero; si falla uno, vuelve a ese paso y enfoca el campo */
  form.addEventListener('submit', function (e) {
    for (var n = 1; n <= total; n++) {
      var bad = validate(n);
      if (bad) {
        e.preventDefault();
        show(n, { focus: false });
        live.textContent = t('Please check the highlighted answers.');
        bad.focus();
        return;
      }
    }
  });

  /* Estado inicial: el paso del primer error del servidor, o el primero. El aviso del servidor recibe el foco. */
  applyProfile();
  var firstErr = $('.ap__err');
  var startAt = 1;
  if (firstErr) {
    var st = firstErr.closest('[data-ap-step]');
    if (st) startAt = steps.indexOf(st) + 1;
  }
  show(startAt, { focus: false });
  var alertBox = d.getElementById('ap-alert');
  if (alertBox) alertBox.focus();
  try { history.replaceState({ ap: startAt }, '', location.pathname + location.search + (startAt > 1 ? '#step-' + startAt : '')); } catch (e) { /* nada */ }
})();
