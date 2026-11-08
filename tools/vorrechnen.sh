#!/usr/bin/env bash
# Rechnet nach einem Sammler-Lauf oder Deploy die Bewertung je Sportart auf dem
# Server vor (server/web/vorrechnen.php, seit 8.11.2026). Fuer die Actions.
#
#   VORRECHNEN_SCHLUESSEL=... SEITE_URL=https://wohinfahrma.de tools/vorrechnen.sh
#
# Ohne Schluessel: nichts tun. Ein Fehler macht den Lauf nicht rot, die Seite
# rechnet dann beim ersten Aufruf selbst (wie vorher).
set -uo pipefail
if [ -z "${VORRECHNEN_SCHLUESSEL:-}" ]; then
  echo "Secret VORRECHNEN_SCHLUESSEL fehlt, Vorrechnen übersprungen."
  exit 0
fi
seite="${SEITE_URL:-https://wohinfahrma.de}"
seite="${seite%/}"
sportarten=$(php -r 'require "server/web/lib/bewertung.php"; echo implode(" ", array_keys(SPORTARTEN));')
fehler=0
for s in $sportarten; do
  antwort=$(curl --silent --show-error --fail --max-time 150 \
                 --data-urlencode "schluessel=${VORRECHNEN_SCHLUESSEL}" --data-urlencode "sport=${s}" \
                 "${seite}/vorrechnen.php" 2>&1) || { echo "::warning::Vorrechnen ${s} fehlgeschlagen: ${antwort}"; fehler=$((fehler + 1)); continue; }
  echo "${s}: ${antwort}"
done
echo "Vorrechnen fertig, ${fehler} Fehler."
exit 0
