#!/bin/bash
# Plugin-ZIP für die Installation bauen – ohne tests/ (gehört nicht auf den Server).
# Aufruf: tools/build-zip.sh <plugin-ordner> <ziel.zip>
set -e
cd "$(dirname "$0")/.."
rm -f "$2"
zip -qr "$2" "$1" -x "$1/tests/*" "$1/tests/" "*/.DS_Store"
unzip -l "$2" | grep -q "/tests/" && { echo "Fehler: tests/ im ZIP"; exit 1; }
echo "OK: $2"
