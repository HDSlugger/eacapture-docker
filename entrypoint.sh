#!/bin/sh
set -eu

SCRIPT=/usr/local/bin/eacapture.php

if [ "${EACAPTURE_CUSTOM_SCRIPT:-0}" = "1" ] && [ -f /data/eacapture.php ]; then
  SCRIPT=/data/eacapture.php
fi

echo "Using EACapture script: $SCRIPT"
echo "Script SHA-256: $(sha256sum "$SCRIPT" | cut -d' ' -f1)"

if [ -n "${HDHR_IP:-}" ]; then
  echo "HDHR_IP configured: $HDHR_IP"
  exec php -d "date.timezone=${TZ:-UTC}" \
    "$SCRIPT" --data-dir=/data --device-ip="$HDHR_IP"
else
  exec php -d "date.timezone=${TZ:-UTC}" \
    "$SCRIPT" --data-dir=/data
fi
