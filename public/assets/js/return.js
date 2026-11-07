// Return checklist: shows the automatic charge as a hint and asks before saving (CSP does not allow inline scripts).
(function () {
  function num(el) { var n = parseInt(el ? el.value : '0', 10); return isNaN(n) || n < 0 ? 0 : n; }

  function hint(row) {
    var chg = row.querySelector('[data-chg]');
    if (!chg) { return; }
    var cost = parseFloat(row.getAttribute('data-cost')) || 0;
    var units = num(row.querySelector('[data-dmg]')) + num(row.querySelector('[data-mis]'));
    var total = Math.round(units * cost * 100) / 100;
    chg.placeholder = 'Auto: PKR ' + total.toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  document.querySelectorAll('tr[data-cost]').forEach(function (row) {
    hint(row);
    row.addEventListener('input', function () { hint(row); });
  });

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });
})();