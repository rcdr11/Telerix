/* Telerix – kleine Helfer ohne Inline-JavaScript (erlaubt eine strikte CSP). */
document.addEventListener('DOMContentLoaded', function () {
  // Formulare mit data-confirm erst nach Rückfrage absenden
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  // Knöpfe mit data-busy zeigen beim Absenden, dass es dauert (z. B. Hörprobe).
  // Erst nach dem Absenden sperren, sonst würde der Browser nicht absenden.
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var btn = e.submitter;
      if (!e.defaultPrevented && btn && btn.hasAttribute('data-busy')) {
        btn.textContent = btn.getAttribute('data-busy');
        setTimeout(function () { btn.disabled = true; }, 0);
      }
    });
  });

  // Elemente mit data-toggle blenden das Element mit der angegebenen ID ein/aus
  document.querySelectorAll('[data-toggle]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.getElementById(el.getAttribute('data-toggle'));
      if (target) {
        target.classList.toggle('w3-show');
      }
    });
  });
});
