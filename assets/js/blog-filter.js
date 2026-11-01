/* Filtro del blog: chips con aria-pressed que muestran/ocultan las tarjetas por data-cat.
   Sin JS (o si algo falla) todas las tarjetas quedan visibles. */
(function () {
  'use strict';
  var group = document.querySelector('[data-blog-filter]');
  if (!group) { return; }
  var chips = Array.prototype.slice.call(group.querySelectorAll('[data-filter]'));
  var items = Array.prototype.slice.call(document.querySelectorAll('.blog-item[data-cat]'));
  var status = document.querySelector('[data-filter-status]');
  if (!chips.length || !items.length) { return; }

  function apply(key, announce) {
    var shown = 0;
    items.forEach(function (it) {
      var on = key === 'all' || it.getAttribute('data-cat') === key;
      it.hidden = !on;
      if (on) { shown += 1; }
    });
    chips.forEach(function (c) {
      c.setAttribute('aria-pressed', c.getAttribute('data-filter') === key ? 'true' : 'false');
    });
    if (announce && status) {
      var active = chips.filter(function (c) { return c.getAttribute('data-filter') === key; })[0];
      var label = key === 'all' ? 'all topics' : (active ? active.textContent.trim() : key);
      status.textContent = 'Showing ' + shown + (shown === 1 ? ' article' : ' articles') + ' in ' + label + '.';
    }
  }

  chips.forEach(function (c) {
    c.addEventListener('click', function () { apply(c.getAttribute('data-filter'), true); });
  });
})();
