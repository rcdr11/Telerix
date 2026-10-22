"""Browser-Test der Oberfläche: Schieberegler, Hörprobe ohne Neuladen,
Aktualisierung der Startseite. Läuft im Playwright-Container im Compose-Netz.
"""
import os, sys, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get('BASE', 'http://telerix:8080')
PASS = os.environ['PASS']
STREAM = os.environ['STREAM']
fails = 0

def check(name, ok):
    global fails
    print(('  OK   ' if ok else '  FAIL ') + name)
    if not ok:
        fails += 1

with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: m.type == 'error' and errors.append(m.text))

    page.goto(BASE + '/index.php')
    page.fill('#tuser', 'admin')
    page.fill('#tpass', PASS)
    page.click('input[type=submit]')
    page.wait_for_url('**/start.php')

    # Stream-Adresse setzen (Test-Stream)
    page.goto(BASE + '/configuration.php')
    page.fill('#streamadress', STREAM)
    page.click('form[action="set_stream.php"] input[type=submit]')
    page.wait_for_load_state()

    print('== Schieberegler')
    page.goto(BASE + '/configuration.php#ton')
    slider = page.locator('#audio_gain_db')
    check('Lautstärke als Schieberegler -24 bis +24',
          slider.get_attribute('type') == 'range' and slider.get_attribute('min') == '-24'
          and slider.get_attribute('max') == '24')
    v0 = int(slider.input_value())
    soll = '0 dB (unverändert)' if v0 == 0 else ('+' if v0 > 0 else '') + str(v0) + ' dB'
    check(f'Anzeige beim Laden passt zum Regler ({soll})', page.text_content('#audio_gain_wert') == soll)
    slider.evaluate("el => { el.value = -6; el.dispatchEvent(new Event('input')); }")
    check('Anzeige folgt dem Regler (-6 dB)', page.text_content('#audio_gain_wert') == '-6 dB')
    slider.evaluate("el => { el.value = 9; el.dispatchEvent(new Event('input')); }")
    check('Anzeige folgt dem Regler (+9 dB)', page.text_content('#audio_gain_wert') == '+9 dB')

    print('== Hörprobe ohne Neuladen')
    slider.evaluate("el => { el.value = -6; el.dispatchEvent(new Event('input')); }")
    page.select_option('#audio_profile', 'musik')
    page.evaluate("window.__ohneNeuladen = 1")
    page.click('button[data-hoerprobe]')
    time.sleep(1.5)
    bar = page.locator('#hoerprobe-fortschritt')
    check('Ladebalken erscheint', bar.is_visible())
    check('Knöpfe während der Aufnahme gesperrt', page.locator('button[data-hoerprobe]').is_disabled())
    w1 = page.evaluate("parseFloat(document.querySelector('#hoerprobe-fortschritt div').style.width)")
    time.sleep(5)
    w2 = page.evaluate("parseFloat(document.querySelector('#hoerprobe-fortschritt div').style.width)")
    check(f'Ladebalken wächst ({w1:.0f} % -> {w2:.0f} %)', w2 > w1 > 0)
    # Warten in Python: die CSP von Telerix verbietet eval (wait_for_function mit Text)
    for _ in range(120):
        if page.text_content('#hoerprobe-meldung').startswith('Hörprobe erstellt'):
            break
        time.sleep(0.5)
    print('  INFO Meldung: ' + page.text_content('#hoerprobe-meldung'))
    check('Seite wurde nicht neu geladen', page.evaluate("window.__ohneNeuladen === 1"))
    check('Ergebnis eingesetzt (zwei Player, Werte der Probe)',
          page.locator('#hoerprobe-ergebnis audio').count() == 2
          and 'Lautstärke -6 dB, Sprache und Musik' in ' '.join(page.text_content('#hoerprobe-ergebnis').split()))
    check('Formular unverändert (Regler steht noch auf -6)', page.text_content('#audio_gain_wert') == '-6 dB')
    check('Knöpfe wieder freigegeben', not page.locator('button[data-hoerprobe]').is_disabled())
    time.sleep(1.5)
    check('Ladebalken wieder ausgeblendet', not bar.is_visible())
    dur = page.evaluate("""() => new Promise(r => { const a = document.querySelector('#hoerprobe-ergebnis audio');
        a.preload = 'metadata'; a.addEventListener('loadedmetadata', () => r(a.duration)); a.load(); })""")
    check(f'Hörprobe abspielbar ({dur:.1f} s)', 19 <= dur <= 21)

    print('== Aktualisierung der Startseite')
    reqs = []
    page.on('request', lambda r: 'start_status.php' in r.url and reqs.append(time.time()))
    page.goto(BASE + '/start.php')
    page.evaluate("document.querySelector('#live').firstElementChild.setAttribute('data-marke', '1')")
    # Alle 60 s; time.sleep würde die Ereignisse (request) zurückhalten
    page.wait_for_timeout(65000)
    check(f'Regelmäßig nachgeladen ({len(reqs)} Abruf(e) in 65 s, jede Minute)', len(reqs) >= 1)
    check('Inhalt ersetzt (Markierung verschwunden)', page.locator('#live [data-marke]').count() == 0)
    check('Anrufer-Bereich nach dem Nachladen vorhanden', 'Aktuelle Zuhörer' in page.text_content('#live'))

    check('Keine JavaScript-Fehler' + (': ' + '; '.join(errors) if errors else ''), not errors)
    b.close()

print('Alle Browser-Tests bestanden.' if fails == 0 else f'{fails} Browser-Test(s) fehlgeschlagen.')
sys.exit(1 if fails else 0)
