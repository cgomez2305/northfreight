/* NorthStar Captación · configuración (/config): cuenta atrás de la confirmación, suma de pesos en vivo, recálculo en curso y silencio. Sin librerías. */
(function () {
 'use strict';
 var D = document, $ = function (s, r) { return (r || D).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
 var on = $('.cfg-tabs .utab.is-on');
 if (on && on.parentNode.scrollWidth > on.parentNode.clientWidth) on.parentNode.scrollLeft = Math.max(0, on.getBoundingClientRect().left - on.parentNode.getBoundingClientRect().left + on.parentNode.scrollLeft - 24);
 var left = $('[data-left]');
 /* Fase AM: al expirar la confirmación NO se recarga (se perdería lo que se estaba editando). Se bloquean los campos, se conservan sus valores y se avisa. */
 var lockEdits = function () {
  var bar = $('.cfg-ok');
  $$('.cfg-body form').forEach(function (f) {
   var a = f.getAttribute('action') || '';
   if (/\/config\/(un)?lock$/.test(a)) return;
   $$('input:not([type=hidden]), select, textarea, button', f).forEach(function (c) { c.disabled = true; });
  });
  $$('.rp button, .rp input, .rp select, .rp textarea').forEach(function (c) { c.disabled = true; });
  var msg = D.createElement('div'); msg.className = 'flash flash--warn'; msg.setAttribute('role', 'alert');
  var t = D.createElement('span'); t.className = 'flash__t'; t.textContent = 'Edición bloqueada: vuelve a confirmar tu identidad. Lo que escribiste sigue en pantalla, pero no se guardará. Al confirmar se recarga la página.';
  var a = D.createElement('a'); a.className = 'link'; a.href = location.pathname + location.search; a.textContent = 'Confirmar identidad';
  msg.appendChild(t); msg.appendChild(a);
  if (bar) { bar.parentNode.replaceChild(msg, bar); } else { var body = $('.cfg-body'); if (body) body.insertBefore(msg, body.firstChild); }
 };
 if (left) {
  var secs = parseInt(left.getAttribute('data-left'), 10) || 0, t0 = Date.now(), timer = null;
  var tick = function () {
   var r = secs - Math.floor((Date.now() - t0) / 1000);
   if (r <= 0) { clearInterval(timer); lockEdits(); return; }
   left.textContent = r >= 90 ? Math.ceil(r / 60) + ' min' : r + ' s';
  };
  tick(); timer = setInterval(tick, 5000);
 }
 /* Recálculo en curso: se pide solo el estado (JSON), sin recargar la página. Al terminar se avisa y se ofrece actualizar. */
 var run = $('[data-running]');
 if (run && window.NS && NS.post) {
  var bar2 = $('progress', run), line = $('p b', run), poll = null;
  var check = function () {
   NS.post('/ajax/config/recalc', {}).then(function (j) {
    if (!j || !j.ok) return;
    if (bar2) { bar2.value = j.pct; bar2.textContent = j.pct + ' %'; }
    var p0 = line && line.parentNode;
    if (p0 && p0.lastChild && p0.lastChild.nodeType === 3) p0.lastChild.nodeValue = ' ' + j.processed + ' de ' + j.total + ' leads · ' + j.changed + ' cambiaron';
    if (j.status !== 'running') {
     clearInterval(poll);
     var n = D.createElement('p'); n.setAttribute('role', 'status'); n.textContent = 'Recálculo terminado: ' + j.processed + ' leads, ' + j.changed + ' cambiaron. ';
     var a = D.createElement('a'); a.className = 'link'; a.href = location.pathname + location.search + '#recalcular'; a.textContent = 'Actualizar para ver el antes y después';
     n.appendChild(a); run.appendChild(n);
     if (bar2) bar2.hidden = true;
     if (line) line.textContent = 'Recálculo terminado';
    }
   });
  };
  poll = setInterval(check, 6000);
 }
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
   var t = b.getAttribute('data-copy') || '', done = function () { if (window.NS && NS.toast) NS.toast('Copiado al portapapeles.'); };
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
