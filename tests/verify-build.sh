#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PACKAGE="$PROJECT_ROOT/share-name-normalizer-2026.09.02b-noarch-1.txz"
PLUGIN="$PROJECT_ROOT/share-name-normalizer.plg"
EXTRACT="$(mktemp -d)"
PAYLOAD="$(mktemp)"
trap 'rm -rf "$EXTRACT"; rm -f "$PAYLOAD"' EXIT

test -s "$PACKAGE"
test -s "$PLUGIN"
tar -tJf "$PACKAGE" | grep -q './usr/local/emhttp/plugins/share-name-normalizer/share-name-normalizer.page'
tar -tJf "$PACKAGE" | grep -q './usr/local/emhttp/plugins/share-name-normalizer/api.php'
tar -tJf "$PACKAGE" | grep -q './usr/local/emhttp/plugins/share-name-normalizer/scripts/share-name-normalizer.php'

sed -n '/<FILE Name=.*Type="base64"/,/<\/INLINE>/p' "$PLUGIN" | sed '1,2d;$d' | base64 -d > "$PAYLOAD"
cmp -s "$PACKAGE" "$PAYLOAD"
test "$(grep -c '@PAYLOAD@' "$PLUGIN")" -eq 0
grep -q 'ENTITY author "Ray Munro"' "$PLUGIN"
grep -q 'min="6.12.0"' "$PLUGIN"

tar -C "$EXTRACT" -xJf "$PAYLOAD"
grep -q "str_replace(' ', '-', strtolower(\$name))" "$EXTRACT/usr/local/emhttp/plugins/share-name-normalizer/scripts/share-name-normalizer.php"
grep -q "record\['name'\]" "$EXTRACT/usr/local/emhttp/plugins/share-name-normalizer/scripts/share-name-normalizer.php"
grep -q 'Skipped: Unraid requires a renamed share to begin with a letter.' "$EXTRACT/usr/local/emhttp/plugins/share-name-normalizer/scripts/share-name-normalizer.php"
grep -q "processRunning(\['dockerd'\])" "$EXTRACT/usr/local/emhttp/plugins/share-name-normalizer/scripts/share-name-normalizer.php"
grep -q 'best-effort automatic rollback' "$PLUGIN"

echo "Share Name Normalizer build verification passed."
