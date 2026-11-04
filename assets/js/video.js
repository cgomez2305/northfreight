/* NorthStar Freight — video.js: fachada del video (home y Our desk) y capítulos. Sin URL real no inventa nada. */
(function () {
  'use strict';
  var d = document;
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  function seconds(t) {
    if (!/^\d+(:\d{1,2}){1,2}$/.test(t || '')) return NaN;
    return t.split(':').reduce(function (a, n) { return a * 60 + (+n); }, 0);
  }

  /* URL del reproductor según proveedor; devuelve {type:'iframe'|'video', url}. */
  function resolve(src, start) {
    var m, u;
    if ((m = src.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/))([\w-]{6,})/))) {
      u = 'https://www.youtube-nocookie.com/embed/' + m[1] + '?autoplay=1&rel=0&playsinline=1' + (start ? '&start=' + start : '');
      return { type: 'iframe', url: u };
    }
    if ((m = src.match(/vimeo\.com\/(?:video\/)?(\d+)/))) {
      return { type: 'iframe', url: 'https://player.vimeo.com/video/' + m[1] + '?autoplay=1' + (start ? '#t=' + start + 's' : '') };
    }
    if (/\.(mp4|webm|ogv)(\?|#|$)/i.test(src)) return { type: 'video', url: src };
    return { type: 'iframe', url: src };
  }

  var players = {};

  function Player(box) {
    var frame = box.querySelector('.video__frame');
    var btn = box.querySelector('[data-video-play]');
    if (!frame || !btn) return null;
    var src = box.getAttribute('data-video-src') || '';
    var title = (box.querySelector('.video__title') || {}).textContent || 'Video';
    var el = null;
    var self = { src: src, play: play };

    if (!src) { // video aún inexistente: botón inactivo y aviso accesible
      var note = d.createElement('span');
      note.className = 'sr-only';
      note.id = (box.id || 'video') + '-soon';
      note.textContent = 'Video coming soon';
      frame.appendChild(note);
      btn.setAttribute('aria-disabled', 'true');
      btn.setAttribute('aria-describedby', note.id);
      btn.style.cursor = 'default';
      btn.addEventListener('click', function (e) { e.preventDefault(); });
      return self;
    }

    function play(t) {
      var start = isNaN(t) ? 0 : Math.floor(t);
      if (el && el.tagName === 'VIDEO') {
        if (start) el.currentTime = start;
        el.play && el.play();
        return;
      }
      var r = resolve(src, start);
      if (el) { el.src = resolve(src, start).url; return; } // iframe ya montado: recarga en el minuto
      if (r.type === 'video') {
        el = d.createElement('video');
        el.controls = true; el.autoplay = true; el.playsInline = true; el.preload = 'metadata';
        el.src = r.url;
        if (start) el.addEventListener('loadedmetadata', function () { el.currentTime = start; }, { once: true });
      } else {
        el = d.createElement('iframe');
        el.src = r.url;
        el.title = title.trim();
        el.allow = 'autoplay; fullscreen; picture-in-picture';
        el.allowFullscreen = true;
        el.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      }
      el.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0;background:var(--navy-deep)';
      $$('picture, img, [data-video-play]', frame).forEach(function (n) { n.remove(); });
      frame.appendChild(el);
      var hero = box.closest && box.closest('.hero--photo');
      if (hero) hero.classList.add('is-playing');   // hero fotográfico: el video ocupa todo el marco y el texto se retira
      el.tabIndex = -1;
      el.focus({ preventScroll: true });
    }
    btn.addEventListener('click', function () { play(NaN); });
    return self;
  }

  $$('[data-video]').forEach(function (box) {
    if (box.querySelector('.video__frame')) players[box.id] = Player(box);
  });

  /* Capítulos: botones con data-t. Los enlaces (home) se dejan intactos. */
  $$('[data-video]').forEach(function (box) {
    var chapters = $$('.chapter[data-t]', box);
    if (!chapters.length) return;
    var target = box.getAttribute('data-video-for') || box.id;
    function activate(c) {
      chapters.forEach(function (x) {
        var on = x === c;
        x.classList.toggle('is-active', on);
        x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }
    activate(chapters[0]);
    chapters.forEach(function (c) {
      c.addEventListener('click', function () {
        activate(c);
        var p = players[target];
        if (p && p.src) p.play(seconds(c.getAttribute('data-t')));
      });
    });
  });
})();
