#!/usr/bin/env bash
set -Eeuo pipefail

case "${1:-}" in
  php85-wp712) SP_TEST_CLI_IMAGE=wordpress:cli-php8.5; wp_version=7.1.2 ;;
  php82-wp712) SP_TEST_CLI_IMAGE=wordpress:cli-php8.2; wp_version=7.1.2 ;;
  php74-wp50) SP_TEST_CLI_IMAGE=wordpress:cli-php7.4; wp_version=5.0 ;;
  php74-wp68) SP_TEST_CLI_IMAGE=wordpress:cli-php7.4; wp_version=6.8 ;;
  php82-wp68) SP_TEST_CLI_IMAGE=wordpress:cli-php8.2; wp_version=6.8 ;;
  *) echo 'Usage: bash dev/tests/run.sh {php85-wp712|php82-wp712|php74-wp50|php74-wp68|php82-wp68}' >&2; exit 2 ;;
esac

SP_TEST_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
export SP_TEST_ROOT SP_TEST_CLI_IMAGE
compose_file="$SP_TEST_ROOT/dev/tests/compose.yml"

# The runner must never use a remote Docker daemon or the development Compose project.
if [[ -n "${DOCKER_HOST:-}" && "${DOCKER_HOST}" != unix://* ]]; then
  echo 'Refusing a nonlocal Docker target before provisioning.' >&2
  exit 2
fi
context_host=$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null) || {
  echo 'Cannot inspect Docker context; refusing to provision.' >&2
  exit 2
}
if [[ "$context_host" != unix://* ]]; then
  echo 'Refusing a nonlocal Docker context before provisioning.' >&2
  exit 2
fi
if [[ ! -f "$SP_TEST_ROOT/plugin-dir/vendor/autoload.php" ]]; then
  echo 'Plugin dependencies are missing; install only plugin-dir dependencies first.' >&2
  exit 2
fi
python3 - "$SP_TEST_ROOT/composer.lock" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as lock_file:
    packages = json.load(lock_file)['packages']
stream = next((package for package in packages if package['name'] == 'wpackagist-plugin/stream'), None)
if stream is None or stream['version'] != '4.0.0' or stream['dist']['url'] != 'https://downloads.wordpress.org/plugin/stream.4.0.0.zip':
    raise SystemExit('Stream fixture does not match the root Composer lock; refusing to provision.')
PY

subnet_output=$(python3 "$SP_TEST_ROOT/dev/tests/select-subnets.py") || exit 2
mapfile -t subnets <<< "$subnet_output"
if [[ ${#subnets[@]} != 2 ]]; then
  echo 'Subnet selector returned an invalid result; refusing to provision.' >&2
  exit 2
fi
export SP_TEST_ISOLATED_SUBNET="${subnets[0]}"
export SP_TEST_DOWNLOAD_SUBNET="${subnets[1]}"

project="spint$(date +%s)$$"
compose=(docker compose --env-file /dev/null --project-name "$project" --file "$compose_file")
if [[ -n "$("${compose[@]}" ps --all --quiet)" ]] ||
  docker volume inspect "${project}_site" >/dev/null 2>&1 ||
  docker volume inspect "${project}_db_data" >/dev/null 2>&1 ||
  docker network inspect "${project}_isolated" >/dev/null 2>&1 ||
  docker network inspect "${project}_download" >/dev/null 2>&1; then
  echo 'Refusing to reuse an existing integration target.' >&2
  exit 2
fi

created=0
cleanup() {
  if [[ "$created" == 1 ]]; then
    "${compose[@]}" down --volumes --remove-orphans >/dev/null
  fi
}
trap cleanup EXIT

echo "Integration target: fresh Compose project; WordPress $wp_version; $SP_TEST_CLI_IMAGE"
created=1
"${compose[@]}" up --detach --wait db >/dev/null
"${compose[@]}" run --rm --no-TTY --entrypoint wp provision --allow-root core download --version="$wp_version" --quiet
"${compose[@]}" run --rm --no-TTY --entrypoint sh cli /test-fixtures/container-setup.sh "$wp_version"
"${compose[@]}" run --rm --no-TTY --entrypoint sh provision /test-fixtures/download-stream.sh
"${compose[@]}" run --rm --no-TTY --entrypoint sh cli /test-fixtures/stream-phase.sh
echo 'Integration scenario passed.'
