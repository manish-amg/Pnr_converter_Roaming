#!/usr/bin/env bash
# Download airline logos from Google Flights CDN.
# Note: Some logos may need to be downloaded manually if the CDN returns a 404
# or a placeholder image. Check each file after download.
#
# Usage: bash download_logos.sh
#        Run from the assets/images/airlines/ directory, or adjust DEST below.

CDN="https://www.gstatic.com/flights/airline_logos/70px"
DEST="$(cd "$(dirname "$0")" && pwd)"

CODES=(
  EK QR TG AI SQ CX BA LH AF KL EY WY GF TK MS ET KE NH
  CA MU CZ HX FZ OZ UL SV QF JL 6E G8 UK BI MH GA PR VN
  KU JU OS IB AZ AV CM LA AA UA DL CO WS
)

echo "Downloading airline logos to: $DEST"
echo ""

for CODE in "${CODES[@]}"; do
  FILE="$DEST/$CODE.png"
  if [ -f "$FILE" ]; then
    echo "  SKIP  $CODE.png (already exists)"
    continue
  fi
  HTTP=$(curl -s -o "$FILE" -w "%{http_code}" --max-time 10 "$CDN/$CODE.png")
  if [ "$HTTP" = "200" ]; then
    echo "  OK    $CODE.png"
  else
    rm -f "$FILE"
    echo "  FAIL  $CODE.png (HTTP $HTTP) — download manually"
  fi
done

echo ""
echo "Done. Check any FAIL entries above and download those logos manually."
