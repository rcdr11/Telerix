/* Telerix – kleine Helfer ohne Inline-JavaScript (erlaubt eine strikte CSP). */
document.addEventListener('DOMContentLoaded', function () {
  // Formulare mit data-confirm erst nach Rückfrage absenden. Am Dokument statt
  // am Formular, damit es auch für nachgeladene Inhalte (Startseite) gilt.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
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

  // Schieberegler mit data-anzeige zeigen ihren Wert im angegebenen Element
  document.querySelectorAll('input[type=range][data-anzeige]').forEach(function (el) {
    var out = document.getElementById(el.getAttribute('data-anzeige'));
    var show = function () {
      var v = parseInt(el.value, 10);
      out.textContent = v === 0 ? '0 dB (unverändert)' : (v > 0 ? '+' : '') + v + ' dB';
    };
    el.addEventListener('input', show);
    show();
  });

  // Hörprobe im Hintergrund aufnehmen, mit Ladebalken statt Neuladen der
  // Seite. data-hoerprobe = erwartete Dauer in Sekunden.
  document.querySelectorAll('button[data-hoerprobe]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var form = btn.form;
      var bar = document.getElementById('hoerprobe-fortschritt');
      var fill = bar.firstElementChild;
      var msg = document.getElementById('hoerprobe-meldung');
      var dauer = parseInt(btn.getAttribute('data-hoerprobe'), 10) * 1000;
      var start = Date.now();
      var buttons = form.querySelectorAll('button, input[type=submit]');
      buttons.forEach(function (b) { b.disabled = true; });
      bar.hidden = false;
      fill.style.width = '0%';
      msg.textContent = 'Hörprobe wird aufgenommen …';
      // Bis 95 % nach der erwarteten Dauer, der Rest kommt mit der Antwort
      var timer = setInterval(function () {
        fill.style.width = Math.min(95, (Date.now() - start) / dauer * 95) + '%';
      }, 250);
      var done = function (text) {
        clearInterval(timer);
        buttons.forEach(function (b) { b.disabled = false; });
        msg.textContent = text;
      };
      fetch(btn.formAction, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (r) {
        if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('application/json') !== 0) {
          throw new Error('HTTP ' + r.status);
        }
        return r.json();
      }).then(function (data) {
        fill.style.width = '100%';
        if (data.ok) {
          document.getElementById('hoerprobe-ergebnis').outerHTML = data.html;
        }
        done(data.message);
        setTimeout(function () { bar.hidden = true; }, 800);
      }).catch(function () {
        bar.hidden = true;
        done('Die Hörprobe ist fehlgeschlagen. Bitte die Seite neu laden und erneut versuchen.');
      });
    });
  });

  // Bereiche mit data-refresh regelmäßig neu laden (Startseite: Anrufer und
  // Icecast-Status), nur solange der Tab sichtbar ist. Ist die Anmeldung
  // abgelaufen, leitet der Server zum Login weiter: dann die Seite neu laden.
  document.querySelectorAll('[data-refresh]').forEach(function (el) {
    var url = el.getAttribute('data-refresh');
    var ms = parseInt(el.getAttribute('data-refresh-seconds'), 10) * 1000;
    var busy = false;
    var refresh = function () {
      if (busy || document.hidden) {
        return;
      }
      busy = true;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        if (r.redirected || r.status === 403) {
          window.location.reload();
          return null;
        }
        return r.ok ? r.text() : null;
      }).then(function (html) {
        if (html !== null) {
          el.innerHTML = html;
        }
      }).catch(function () {
        // Server kurz nicht erreichbar: beim nächsten Mal wieder versuchen
      }).finally(function () {
        busy = false;
      });
    };
    setInterval(refresh, ms);
    document.addEventListener('visibilitychange', refresh);
  });
});
