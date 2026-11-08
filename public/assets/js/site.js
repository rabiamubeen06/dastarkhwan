// Dastarkhwan site effects. Plain JavaScript, no libraries. Everything here is optional:
// the site works without it (CSP does not allow inline scripts, so it lives in this file).
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* 1. Mobile menu */
  var header = document.getElementById('siteHeader');
  var toggle = document.getElementById('navToggle');
  if (header && toggle) {
    function setOpen(open) {
      header.classList.toggle('nav-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    toggle.addEventListener('click', function () { setOpen(!header.classList.contains('nav-open')); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });
    document.querySelectorAll('#siteNav a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
  }

  /* 2. Photos: if a product photo cannot load, remove it so the illustrated artwork underneath shows instead */
  document.querySelectorAll('img[data-photo]').forEach(function (img) {
    function drop() { if (img.parentNode) { img.parentNode.removeChild(img); } }
    img.addEventListener('error', drop);
    if (img.complete && img.naturalWidth === 0) { drop(); }
  });

  /* 3. Reveal sections as they scroll into view */
  var reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    if (reduce || !('IntersectionObserver' in window)) {
      reveals.forEach(function (el) { el.classList.add('in'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      reveals.forEach(function (el) { io.observe(el); });
    }
  }

  /* 4. Gold dust: a few slow, softly twinkling specks on a canvas (hero sections only) */
  document.querySelectorAll('canvas[data-gold-dust]').forEach(function (cv) {
    var ctx = cv.getContext && cv.getContext('2d');
    if (!ctx) { return; }
    var dots = [], w = 0, h = 0, dpr = Math.min(window.devicePixelRatio || 1, 2), running = false, raf = 0;

    function make(initial) {
      return {
        x: Math.random() * w,
        y: initial ? Math.random() * h : h + 10,
        r: 0.6 + Math.random() * 1.6,
        vy: 0.08 + Math.random() * 0.22,
        vx: (Math.random() - 0.5) * 0.08,
        t: Math.random() * Math.PI * 2,
        ts: 0.01 + Math.random() * 0.02
      };
    }
    function size() {
      var b = cv.getBoundingClientRect();
      w = Math.max(1, Math.round(b.width)); h = Math.max(1, Math.round(b.height));
      cv.width = w * dpr; cv.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var count = Math.round(Math.min(46, Math.max(16, w / 28)));
      dots = [];
      for (var i = 0; i < count; i++) { dots.push(make(true)); }
      draw();
    }
    function draw() {
      ctx.clearRect(0, 0, w, h);
      for (var i = 0; i < dots.length; i++) {
        var d = dots[i];
        var a = 0.25 + 0.55 * (0.5 + 0.5 * Math.sin(d.t));
        ctx.beginPath();
        ctx.fillStyle = 'rgba(232,204,145,' + a.toFixed(3) + ')';
        ctx.shadowColor = 'rgba(212,175,112,0.8)';
        ctx.shadowBlur = 6;
        ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
        ctx.fill();
      }
    }
    function step() {
      for (var i = 0; i < dots.length; i++) {
        var d = dots[i];
        d.y -= d.vy; d.x += d.vx; d.t += d.ts;
        if (d.y < -10) { dots[i] = make(false); }
      }
      draw();
      raf = running ? requestAnimationFrame(step) : 0;
    }
    function start() { if (!running && !reduce) { running = true; raf = requestAnimationFrame(step); } }
    function stop() { running = false; if (raf) { cancelAnimationFrame(raf); raf = 0; } }

    size();
    if ('ResizeObserver' in window) { new ResizeObserver(size).observe(cv); } else { window.addEventListener('resize', size); }
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { en[0].isIntersecting ? start() : stop(); }).observe(cv);
    } else { start(); }
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
  });
})();
