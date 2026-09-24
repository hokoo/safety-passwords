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
SP_STREAM_FAILURE_PRIVACY=1
export SP_STREAM_FAILURE_PRIVACY
wp eval-file /test-fixtures/stream-failure-privacy.php
unset SP_STREAM_FAILURE_PRIVACY
wp eval-file /test-fixtures/stream-cli-privacy.php prepare
SP_STREAM_CLI_PRIVACY=1
export SP_STREAM_CLI_PRIVACY
cli_output=$(wp safety check-users)
[ "$cli_output" = 'Success: Done.' ] || { echo 'FAIL: CLI success output changed' >&2; exit 1; }
unset cli_output
unset SP_STREAM_CLI_PRIVACY
wp eval-file /test-fixtures/stream-cli-privacy.php check
