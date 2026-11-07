
document.addEventListener('click', function (e) {
  if (e.target.closest && e.target.closest('[data-print]')) { window.print(); }
});