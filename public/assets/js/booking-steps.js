// Admin booking page: shows one action panel at a time and keeps it beside the booking details.
// Without JavaScript every panel is simply listed, so nothing is lost. (CSP does not allow inline scripts.)
(function () {
  var center = document.getElementById('actCenter');
  if (!center) { return; }
  var panels = Array.prototype.slice.call(center.querySelectorAll('.act-panel'));
  var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-act]')).filter(function (el) {
    return !el.classList.contains('act-panel');
  });
  var names = panels.map(function (p) { return p.getAttribute('data-act'); });

  function show(name) {
    if (names.indexOf(name) === -1) { name = names[0]; }
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-act') !== name; });
    triggers.forEach(function (t) {
      var on = t.getAttribute('data-act') === name;
      t.classList.toggle('is-active', on);
      if (t.getAttribute('role') === 'tab') { t.setAttribute('aria-selected', on ? 'true' : 'false'); }
    });
    center.scrollTop = 0;
  }

  center.classList.add('is-tabs');

  triggers.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      show(t.getAttribute('data-act'));
      // On a phone the panel sits above the details; make sure it is visible.
      var r = center.getBoundingClientRect();
      if (r.top < 0 || r.top > window.innerHeight * 0.6) { center.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  });

  var fromHash = (window.location.hash || '').replace('#act-', '');
  show(names.indexOf(fromHash) !== -1 ? fromHash : (center.getAttribute('data-default') || names[0]));
})();