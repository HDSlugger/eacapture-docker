#!/bin/sh
# Fetches Silicondust's official script on first start (it is not bundled in
# this image) and runs it unmodified.
set -eu

SCRIPT_URL="${EACAPTURE_SCRIPT_URL:-https://download.silicondust.com/EACapture/eacapture.php.txt}"
SCRIPT=/data/eacapture.php

if [ ! -f "$SCRIPT" ] || [ "${EACAPTURE_UPDATE_SCRIPT:-0}" = "1" ]; then
  echo "Downloading $SCRIPT_URL"
  curl -fsSL "$SCRIPT_URL" -o "$SCRIPT.new"
  head -c 2 "$SCRIPT.new" | grep -q '#!' || { echo "Downloaded file does not look like the script"; exit 1; }
  mv "$SCRIPT.new" "$SCRIPT"
fi
echo "Script SHA-256: $(sha256sum "$SCRIPT" | cut -d' ' -f1)"

exec php -d "date.timezone=${TZ:-UTC}" \
  "$SCRIPT" --data-dir=/data
