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
