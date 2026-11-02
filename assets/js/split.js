/* NorthStar Freight — split.js: divide un titular en líneas (.ln > .ln__i) para la máscara de entrada.
   Respeta spans anidados (.hl) y <br>. split(el) => array de .ln__i; revert(el) devuelve el HTML original. Sin dependencias. */
(function () {
  'use strict';
  var d = document;
  function split(el) {
    if (el.__ns) return el.__ns.lines;
    var orig = el.innerHTML, ws = [];
    (function walk(n) {
      Array.prototype.slice.call(n.childNodes).forEach(function (c) {
        if (c.nodeType === 3) {
          var f = d.createDocumentFragment();
          c.nodeValue.split(/(\s+)/).forEach(function (t) {
            if (!t) return;
            if (/^\s+$/.test(t)) { f.appendChild(d.createTextNode(' ')); return; }
            var s = d.createElement('span');
            s.textContent = t;
            f.appendChild(s);
            ws.push(s);
          });
          n.replaceChild(f, c);
        } else if (c.nodeType === 1 && c.tagName !== 'BR') walk(c);
      });
    })(el);
    var rows = [], top = null, h = 0;
    ws.forEach(function (s) { // una sola lectura de layout por palabra, antes de tocar el DOM
      var r = s.getBoundingClientRect();
      if (top === null || Math.abs(r.top - top) > (h || r.height) / 2) { rows.push([]); top = r.top; h = r.height; }
      rows[rows.length - 1].push(s);
    });
    var chains = ws.map(function (s) { var c = []; for (var p = s.parentNode; p !== el; p = p.parentNode) c.unshift(p); return c; });
    var frag = d.createDocumentFragment(), lines = [];
    rows.forEach(function (row) {
      var ln = d.createElement('span'), li = d.createElement('span'), holder = li, prev = null;
      ln.className = 'ln'; li.className = 'ln__i'; ln.appendChild(li);
      row.forEach(function (s) {
        var ch = chains[ws.indexOf(s)], same = prev && prev.length === ch.length && ch.every(function (n, i) { return n === prev[i]; });
        if (!same) {
          if (prev) li.appendChild(d.createTextNode(' '));
          holder = li;
          ch.forEach(function (n) { var cl = n.cloneNode(false); holder.appendChild(cl); holder = cl; });
        } else holder.appendChild(d.createTextNode(' '));
        holder.appendChild(d.createTextNode(s.textContent));
        prev = ch;
      });
      frag.appendChild(ln);
      lines.push(li);
    });
    el.textContent = '';
    el.appendChild(frag);
    el.__ns = { orig: orig, lines: lines };
    return lines;
  }
  function revert(el) {
    if (!el || !el.__ns) return;
    el.innerHTML = el.__ns.orig;
    el.__ns = null;
  }
  window.NSSplit = { split: split, revert: revert };
})();
