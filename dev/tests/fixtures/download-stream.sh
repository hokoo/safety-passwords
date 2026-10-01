#!/bin/sh
set -eu

# Runs only in the disposable downloader container and writes only to its site volume.
archive=$(mktemp /tmp/safety-passwords-stream.XXXXXX)
trap 'rm -f "$archive"' 0
wget -q -O "$archive" https://downloads.wordpress.org/plugin/stream.4.0.0.zip
unzip -q "$archive" -d /var/www/html/wp-content/plugins
test -f /var/www/html/wp-content/plugins/stream/stream.php
