#!/bin/sh
set -eu

wp() {
	command wp --allow-root --url=http://integration.invalid "$@"
}

# Previous scenarios have finished. The temporary MU filter is installed before
# Stream activation and removed when this disposable Compose project exits.
rm wp-content/mu-plugins/10-safety-passwords-test-loader.php
cp /test-fixtures/stream-privacy.php wp-content/mu-plugins/00-safety-passwords-test-stream-privacy.php
wp eval-file /test-fixtures/stream-integration.php absent
wp plugin activate stream --network --quiet
SP_STREAM_EARLY=1
export SP_STREAM_EARLY
wp eval-file /test-fixtures/stream-integration.php write
unset SP_STREAM_EARLY
SP_STREAM_CUSTOM_LOGGER=1
export SP_STREAM_CUSTOM_LOGGER
wp eval-file /test-fixtures/stream-integration.php custom
unset SP_STREAM_CUSTOM_LOGGER
