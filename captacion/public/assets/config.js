/* NorthStar Captación · configuración (/config): cuenta atrás de la confirmación, suma de pesos en vivo, recálculo en curso y silencio. Sin librerías. */
(function () {
 'use strict';
 var D = document, $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var on = $('.cfg-tabs .utab.is-on');
 if (on && on.parentNode.scrollWidth > on.parentNode.clientWidth) on.parentNode.scrollLeft = Math.max(0, on.offsetLeft - 24);
 var left = $('[data-left]');
 if (left) {
  var secs = parseInt(left.getAttribute('data-left'), 10) || 0, t0 = Date.now();
  var tick = function () {
   var r = secs - Math.floor((Date.now() - t0) / 1000);
   if (r <= 0) { location.reload(); return; }
   left.textContent = r >= 90 ? Math.ceil(r / 60) + ' min' : r + ' s';
  };
  tick(); setInterval(tick, 5000);
 }
 if ($('[data-running]')) setTimeout(function () { location.reload(); }, 6000);
 var sum = $('[data-sum="owner"]');
 if (sum) {
  var flat = $$('input[name^="owner["]').filter(function (i) { return /^owner\[(cdl_standard|english_yes|fmcsa_authority_active|fmcsa_insured|dispatched_equipment|experience|referral|complete)\]$/.test(i.name); });
  var tier = function (k) { return $$('input[name^="owner[' + k + ']"]').filter(function (i) { return /\[points\]$/.test(i.name); }); };
  var tiers = [tier('authority_age'), tier('trucks')];
  var num = function (i) { var v = parseInt(i.value, 10); return isNaN(v) ? 0 : v; };
  var calc = function () { var s = 0; flat.forEach(function (i) { s += num(i); }); tiers.forEach(function (g) { s += Math.max.apply(null, g.map(num).concat([0])); }); sum.textContent = s; };
  flat.concat(tiers[0], tiers[1]).forEach(function (i) { i.addEventListener('input', calc); });
 }
 $$('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
   var t = b.getAttribute('data-copy') || '', done = function () { var o = b.innerHTML; b.textContent = 'Copiado'; setTimeout(function () { b.innerHTML = o; }, 1600); };
   if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () {}); return; }
   var i = b.parentNode.querySelector('input'); if (i) { i.select(); try { document.execCommand('copy'); done(); } catch (e) { /* sin portapapeles */ } }
  });
 });
 var q = $('[data-quiet]');
 if (q) {
  var ti = $$('input[type=time]', q.closest('.card'));
  var sync = function () { ti.forEach(function (i) { i.disabled = !q.checked || !!i.closest('fieldset[disabled]'); }); };
  q.addEventListener('change', sync); sync();
 }
})();
