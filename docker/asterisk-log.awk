# Filter für die Ausgabe von Asterisk (und telerix-stream) auf dem Weg ins
# Container-Log, gestartet von docker/asterisk-run.sh. BusyBox-awk.
#
# SIP-Scanner aus dem Internet schicken laufend Anfragen an Port 5060. Jede
# abgewiesene Anfrage erzeugt eine Zeile (res_pjsip log_failed_request: "No
# matching endpoint found", "Failed to authenticate", ...), jedes ungültige
# Paket eine Warnung von pjproject ("Dropping ... packet"). Das waren
# Gigabytes in wenigen Tagen. Diese Zeilen werden hier gezählt und einmal je
# Stunde zusammengefasst: Anzahl, Gründe und die häufigsten Absender.
# Alle anderen Zeilen gehen unverändert durch, also auch Fehler bei der
# Registrierung beim Anbieter.
#
# Außerdem wird die harmlose Meldung "There are no local system nameservers
# configured" (unter Alpine/musl immer, Asterisk nutzt dann den Resolver
# des Systems) durch einen Hinweis ersetzt.
#
# Die Stunde ergibt sich aus dem Zeitstempel der Zeilen ("[JJJJ-MM-TT
# HH:MM:SS]", dateformat in logger.conf). Die Zusammenfassung erscheint mit
# der ersten Zeile der nächsten Stunde.

# Zeitstempel am Zeilenanfang oder ""
function zeitstempel(z) {
   if (z ~ /^\[[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]:[0-9][0-9]\]/)
      return substr(z, 2, 19)
   return ""
}

# Absender ohne Port: "1.2.3.4:5060" -> "1.2.3.4", "[2001:db8::1]:5060" -> "[2001:db8::1]"
function ohne_port(a) {
   if (a ~ /^\[/) {
      sub(/\]:[0-9]+$/, "]", a)
      return a
   }
   sub(/:[0-9]+$/, "", a)
   return a
}

function zaehle(quelle, grund) {
   gesamt++
   gruende[grund]++
   quellen[quelle]++
}

function zusammenfassen(   g, q, text, n, i, best, bestq, liste, k) {
   if (gesamt == 0)
      return
   text = ""
   for (g in gruende)
      text = text (text == "" ? "" : ", ") gruende[g] " " g
   # Die fünf häufigsten Absender (einfache Auswahl, kleine Mengen)
   liste = ""
   n = 0
   for (q in quellen)
      n++
   for (i = 1; i <= 5 && i <= n; i++) {
      best = -1
      for (q in quellen)
         if (!(q in genommen) && quellen[q] > best) {
            best = quellen[q]
            bestq = q
         }
      genommen[bestq] = 1
      liste = liste (liste == "" ? "" : ", ") bestq " (" best ")"
   }
   printf "[%s:59:59] NOTICE[telerix] asterisk-log.awk: SIP-Scanner %s:00-%s:59: %d abgewiesene Anfragen bzw. ungültige Pakete von %d Absendern (%s); häufigste Absender: %s\n", \
      stunde, stunde, substr(stunde, 12, 2), gesamt, n, text, liste
   fflush()
   gesamt = 0
   for (k in gruende) delete gruende[k]
   for (k in quellen) delete quellen[k]
   for (k in genommen) delete genommen[k]
}

{
   z = zeitstempel($0)
   if (z != "") {
      h = substr(z, 1, 13)
      if (stunde != "" && h != stunde)
         zusammenfassen()
      stunde = h
   }

   # res_pjsip: Request 'INVITE' from '<sip:...>' failed for '1.2.3.4:5060' (callid: ...) - No matching endpoint found
   if (index($0, "log_failed_request") && index($0, " failed for '")) {
      rest = substr($0, index($0, " failed for '") + 13)
      quelle = ohne_port(substr(rest, 1, index(rest, "'") - 1))
      grund = $0
      sub(/.* - /, "", grund)
      zaehle(quelle, grund)
      next
   }
   # pjproject: Dropping 123 bytes packet from UDP 1.2.3.4:5060 : PJSIP syntax error exception ...
   if ($0 ~ /Dropping [0-9]+ bytes packet from /) {
      rest = $0
      sub(/.*bytes packet from [^ ]+ /, "", rest)
      sub(/ .*/, "", rest)
      zaehle(ohne_port(rest), "ungültige Pakete")
      next
   }
   # res_pjsip_acl (TELERIX_SIP_ALLOW): Incoming SIP message from 1.2.3.4:5060 did not pass ACL test
   if (index($0, "Incoming SIP message from ") && index($0, " did not pass ACL test")) {
      rest = substr($0, index($0, "Incoming SIP message from ") + 26)
      sub(/ .*/, "", rest)
      zaehle(ohne_port(rest), "von der ACL abgewiesen")
      next
   }
   if (index($0, "There are no local system nameservers configured")) {
      # Asterisk meldet es mehrmals, der Hinweis genügt einmal
      if (dns_gemeldet++)
         next
      print (z != "" ? "[" z "] " : "") "INFO[telerix] Hinweis: Asterisk ermittelt die DNS-Server unter Alpine nicht selbst und nutzt den Resolver des Systems (normal, keine Störung)."
      fflush()
      next
   }
   print
   fflush()
}

END {
   zusammenfassen()
}
