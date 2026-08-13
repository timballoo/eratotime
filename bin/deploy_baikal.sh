#!/bin/bash
# bin/deploy_baikal.sh — (re)deploy a VANILLA Baïkal to the meertec.ltd doc root.
#
# Run on the Hostinger box (or over SSH):  bash bin/deploy_baikal.sh
#
# This gives a clean vanilla install (config + Specific are fresh). If you had
# a configured Baïkal, prefer RESTORING it from the nightly backup instead
# (see docs/OPERATIONS.md) so users/calendars/events survive.
set -e

BAIKAL_VERSION="${1:-0.12.1}"
DOCROOT="$HOME/domains/meertec.ltd/public_html"
ZIP="$HOME/baikal-$BAIKAL_VERSION.zip"

echo "Downloading Baïkal $BAIKAL_VERSION ..."
curl -fL -o "$ZIP" "https://github.com/sabre-io/Baikal/releases/download/$BAIKAL_VERSION/baikal-$BAIKAL_VERSION.zip"

cd "$DOCROOT"
rm -rf baikal
unzip -q "$ZIP"          # the zip extracts a baikal/ folder
rm -f "$ZIP"
chmod -R 755 baikal/Specific baikal/config

echo "Baïkal $BAIKAL_VERSION deployed to $DOCROOT/baikal"
echo "Next:"
echo "  1. Complete the web installer at https://www.meertec.ltd/baikal/html/"
echo "     (storage: SQLite; then create the admin account)."
echo "  2. In the Baïkal admin: Users -> Add user stephen@meertec.ltd + a calendar."
echo "  3. php bin/setup_caldav.php   (encrypts creds + activates the source)"
echo "  4. php cron/sync_calendars.php"
