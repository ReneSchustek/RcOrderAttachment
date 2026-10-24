#!/bin/bash
# Hochladezeit eines Fotos über den Endpunkt der Storefront, je Bildgröße: 5 warme Aufrufe, Median in
# Millisekunden. Läuft auf dem Testsystem gegen eine ddev-Instanz; die Testfotos legt make-images.php im
# var-Ordner der Instanz ab. Wenige Aufrufe, weil der Endpunkt je Sitzung gebremst wird.
#
# Ausgabe je Größe eine Zeile: Messung;Median_ms;HTTP-Status
# Aufruf: upload.sh <instanz>
set -u
INSTANCE=${1:?Aufruf: upload.sh <instanz>}
RUNS=5
BASE=https://$INSTANCE.ddev.site
IMAGES=/workspace/shopware/instances/$INSTANCE/var/rc-bench-images
JAR=$(mktemp)
BODY=$(mktemp)
trap 'rm -f "$JAR" "$BODY"' EXIT

# Der Endpunkt verlangt eine Sitzung mit Kunde, wenigstens als Gast; sonst leitet er zur Anmeldung um.
# Der Gast entsteht wie im Bestellvorgang: Artikel in den Warenkorb, dann registrieren.
cd "/workspace/shopware/instances/$INSTANCE" || exit 1
PRODUCT=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM product WHERE active = 1 AND child_count = 0 AND stock > 0 LIMIT 1" 2>/dev/null)
SALUTATION=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM salutation ORDER BY salutation_key LIMIT 1" 2>/dev/null)
COUNTRY=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM country WHERE iso='DE' LIMIT 1" 2>/dev/null)

curl -skL -c "$JAR" -b "$JAR" "$BASE/" -o /dev/null
curl -skL -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/checkout/line-item/add" \
  --data-urlencode "lineItems[$PRODUCT][id]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][type]=product" \
  --data-urlencode "lineItems[$PRODUCT][referencedId]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][quantity]=1"
curl -sk -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/account/register" \
  --data-urlencode "guest=1" --data-urlencode "redirectTo=frontend.checkout.confirm.page" --data-urlencode "salutationId=$SALUTATION" \
  --data-urlencode "firstName=Probe" --data-urlencode "lastName=Messung" --data-urlencode "email=messung-$(date +%s%N)@example.test" \
  --data-urlencode "accountType=private" --data-urlencode "billingAddress[street]=Teststraße" --data-urlencode "billingAddress[streetNumber]=1" \
  --data-urlencode "billingAddress[zipcode]=96450" --data-urlencode "billingAddress[city]=Coburg" \
  --data-urlencode "billingAddress[countryId]=$COUNTRY" --data-urlencode "billingAddress[phoneNumber]=0201123456"

# Ein Anhang je Aufruf: hochladen, Zeit und Status merken, gleich wieder löschen. Ohne das Löschen
# stieße die Reihe nach wenigen Aufrufen an die Höchstzahl der Anhänge und mäße Abweisungen (422).
upload_once() {
  local file=$1 result token
  result=$(curl -sk -c "$JAR" -b "$JAR" -o "$BODY" -w "%{time_total} %{http_code}" -F "file=@$file;type=image/jpeg" "$BASE/rc-order-attachment/upload")
  token=$(grep -o '"token":"[a-f0-9]*"' "$BODY" | cut -d'"' -f4)
  [ -n "$token" ] && curl -sk -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/rc-order-attachment/$token"
  echo "$result"
}

median_ms() { sort -n | awk '{v[NR]=$1} END {printf "%d", (v[int((NR+1)/2)] + v[int((NR+2)/2)]) / 2 * 1000}'; }

for size in 12mp 24mp 48mp; do
  file="$IMAGES/$size.jpg"
  if [ ! -f "$file" ]; then
    echo "Testfoto $file fehlt; Messung abgebrochen." >&2
    exit 1
  fi

  # Ein Aufruf vorab, auch als Gegenprobe: Ohne 200 mäße die Reihe eine Weiterleitung oder einen Fehler.
  status=$(upload_once "$file" | awk '{print $2}')
  if [ "$status" != "200" ]; then
    echo "Hochladen antwortet mit $status statt 200; Messung abgebrochen." >&2
    exit 1
  fi

  samples=$(for _ in $(seq $RUNS); do upload_once "$file"; echo; done | grep -v '^$')
  printf "Hochladen %s;%s;%s\n" "$size" "$(echo "$samples" | awk '{print $1}' | median_ms)" "$(echo "$samples" | awk '{print $2}' | sort -u | paste -sd/)"
done
