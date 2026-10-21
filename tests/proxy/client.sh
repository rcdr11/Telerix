#!/bin/sh
# Läuft in einem Client-Container: FAILS falsche Logins, dann ein richtiger.
# Ausgabe: "IP=<eigene IP> RESULT=OK|LOCKED"
# FAKE=1: jede Anfrage mit einem anderen, gefälschten X-Forwarded-For.
BASE="$1"; USER="$2"; PASS="$3"; FAILS="$4"; FAKE="${5:-0}"
J=/tmp/cookies
n=0
req() {
   n=$((n + 1))
   if [ "$FAKE" = 1 ]; then
      curl -s -b "$J" -c "$J" -H "X-Forwarded-For: 198.51.100.$n" "$@"
   else
      curl -s -b "$J" -c "$J" "$@"
   fi
}
token() { req "$BASE/$1" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//'; }
login() {
   t="$(token index.php)"
   req -o /dev/null --data-urlencode "csrf=$t" --data-urlencode "tuser=$USER" --data-urlencode "tpass=$1" "$BASE/login.php"
}
i=0
while [ "$i" -lt "$FAILS" ]; do
   login "falsch$i"
   i=$((i + 1))
done
login "$PASS"
if req "$BASE/start.php" | grep -q "Asterisk:"; then r=OK
elif req "$BASE/index.php" | grep -q "Zu viele Fehlversuche"; then r=LOCKED
else r=UNKLAR; fi
echo "IP=$(hostname -i | awk '{print $1}') RESULT=$r"
