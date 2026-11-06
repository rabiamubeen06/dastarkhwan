// Live cost summary on the cart page. Without JavaScript the "Apply" button does the same thing with a page reload.
(function () {
  var form = document.getElementById('delivery-form');
  if (!form || !window.fetch) { return; }

  var method = form.elements['method'];
  var zone = form.elements['zone_id'];
  var zoneField = document.getElementById('zone-field');
  var apply = document.getElementById('delivery-apply');
  var errorBox = document.querySelector('[data-q-error]');

  apply.hidden = true;

  function showZone() { zoneField.hidden = method.value !== 'delivery'; }

  function show(data, ok) {
    var message = data.error || '';
    if (errorBox) { errorBox.textContent = message; errorBox.hidden = message === ''; }
    if (!ok) { return; }
    document.querySelectorAll('[data-q]').forEach(function (el) {
      var key = el.getAttribute('data-q');
      if (data[key] !== undefined) { el.textContent = data[key]; }
    });
  }

  function refresh() {
    showZone();
    fetch(form.getAttribute('data-quote-url'), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) { show(res.d, res.ok); })
      .catch(function () { show({ error: 'Could not update the quote. Press Apply.' }, false); apply.hidden = false; });
  }

  method.addEventListener('change', refresh);
  zone.addEventListener('change', refresh);
  showZone();
})();