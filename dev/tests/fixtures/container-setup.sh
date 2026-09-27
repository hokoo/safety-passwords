#!/bin/sh
set -eu

wp() {
  command wp --allow-root "$@"
}

run_schedule_recovery() {
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-missing
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-missing-verify-duplicate
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-duplicate-verify-wrong
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-wrong-verify-stable
  sleep 2
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-stable-verify
  wp "$@" eval-file /test-fixtures/lifecycle-migration.php schedule-failure-retry
}

wp_version=$1
installed_version=$(wp core version)
if [ "$installed_version" != "$wp_version" ]; then
  echo 'Unexpected WordPress version in isolated volume.' >&2
  exit 1
fi
printf 'Verified runtime: WordPress %s; PHP %s\n' "$installed_version" "$(php -r 'echo PHP_VERSION;')"
mkdir -p wp-content/mu-plugins wp-content/plugins/safety-passwords
cp /test-fixtures/mail-guard.php wp-content/mu-plugins/00-safety-passwords-test-mail-guard.php
cp -R /plugin-source/. wp-content/plugins/safety-passwords/

wp config create --dbname=safety_passwords_integration --dbuser=root --dbpass='' --dbhost=db --quiet
wp config set DISABLE_WP_CRON true --raw --quiet

# WP-CLI reads the generated value on stdin. It never appears in arguments or output.
if ! php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;' 2>/dev/null |
  wp core install --url=http://integration.invalid --title=Integration \
    --admin_user=integration-admin --admin_email=integration@example.invalid \
    --skip-email --prompt=admin_password --quiet >/dev/null 2>&1; then
  echo 'WordPress installation failed.' >&2
  exit 1
fi

wp option add safety_passwords_integration_target isolated --quiet
wp plugin activate safety-passwords --quiet
wp --user=integration-admin eval-file /test-fixtures/activation.php initial
wp --user=integration-admin eval-file /test-fixtures/activation.php followup
wp --user=integration-admin eval-file /test-fixtures/activation.php repair
wp --user=integration-admin eval-file /test-fixtures/activation.php lease
wp --user=integration-admin eval-file /test-fixtures/lifecycle-migration.php prepare
wp --user=integration-admin eval-file /test-fixtures/lifecycle-migration.php verify
run_schedule_recovery --user=integration-admin
wp --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php status
wp --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php admin
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw --quiet
wp config set WP_ACCESSIBLE_HOSTS '127.0.0.1' --quiet
wp eval-file /test-fixtures/http-admin-post.php single
wp config delete WP_ACCESSIBLE_HOSTS --quiet
wp config delete WP_HTTP_BLOCK_EXTERNAL --quiet
wp safety init
wp safety init
wp eval-file /test-fixtures/lifecycle-controls.php cli-verify
wp eval-file /test-fixtures/lifecycle-controls.php cli-lock
if wp safety init >/dev/null 2>&1; then
  echo 'Safety init unexpectedly succeeded with a held lock.' >&2
  exit 1
fi
wp eval-file /test-fixtures/lifecycle-controls.php cli-unlock
wp --user=integration-admin eval-file /test-fixtures/cron.php
wp --user=integration-admin eval-file /test-fixtures/expiry-notices.php ordinary
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php first
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php second
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php cleanup
wp safety check-users
. /test-fixtures/cli-password-phase.sh
run_cli_password_phase ordinary '' 1

# Each WP-CLI request defines one constant set before WordPress loads the plugin.
for constant_case in false_bool false_string true_bool true_string zero_int zero_string one_int one_string decimal_min; do
  SP_TEST_CONSTANT_CASE=$constant_case
  export SP_TEST_CONSTANT_CASE
  wp --require=/test-fixtures/constant-bootstrap.php --user=integration-admin eval-file /test-fixtures/string-constants.php
done
unset SP_TEST_CONSTANT_CASE

# Check interrupted MU startup and ordinary-to-MU transition.
wp plugin deactivate safety-passwords --quiet
wp eval-file /test-fixtures/deactivation.php
wp plugin activate safety-passwords --quiet
wp plugin deactivate safety-passwords --quiet
wp eval-file /test-fixtures/activation.php pending
wp eval-file /test-fixtures/mu-lifecycle.php prepare
cp /test-fixtures/mu-loader.php wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-lifecycle.php held
cp /test-fixtures/mu-history-blocker.php wp-content/mu-plugins/05-safety-passwords-test-history-blocker.php
wp eval-file /test-fixtures/mu-lifecycle.php failed
rm wp-content/mu-plugins/05-safety-passwords-test-history-blocker.php
wp eval-file /test-fixtures/mu-lifecycle.php cooldown
wp eval-file /test-fixtures/mu-lifecycle.php initial
wp eval-file /test-fixtures/mu-lifecycle.php repeat
wp eval-file /test-fixtures/lifecycle-migration.php prepare
wp eval-file /test-fixtures/lifecycle-migration.php verify
run_schedule_recovery
wp --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php status
wp --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php admin
wp safety init
wp eval-file /test-fixtures/lifecycle-controls.php cli-verify
wp --user=integration-admin eval-file /test-fixtures/expiry-notices.php mu
run_cli_password_phase mu '' 30

# With WP-Cron enabled, ordinary reactivation also completes on the next load.
rm wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-lifecycle.php removed
wp cron event delete safety_passwords_periodically_reset --url=http://integration.invalid --quiet
wp eval-file /test-fixtures/mu-lifecycle.php prepare-ordinary
wp config set DISABLE_WP_CRON false --raw --quiet
wp plugin activate safety-passwords --quiet
wp --user=integration-admin eval-file /test-fixtures/activation.php transition
wp config set DISABLE_WP_CRON true --raw --quiet
wp plugin deactivate safety-passwords --quiet
wp eval-file /test-fixtures/mu-lifecycle.php prepare-return
cp /test-fixtures/mu-loader.php wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-lifecycle.php return
wp eval-file /test-fixtures/mu-lifecycle.php return-repeat

# Convert only this disposable installation after the single-site lifecycle phases.
rm wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-lifecycle.php removed
wp cron event delete safety_passwords_periodically_reset --url=http://integration.invalid --quiet
wp core multisite-convert --subdomains=false --quiet
cli_subsite_id=$(wp site create --slug=subsite --title=Subsite --email=integration@example.invalid --porcelain)
case "$cli_subsite_id" in
  ''|*[!0-9]*) echo 'Created subsite returned an invalid ID.' >&2; exit 1 ;;
esac
cli_subsite_url=$(wp site list --site__in="$cli_subsite_id" --field=url)
if [ -z "$cli_subsite_url" ]; then
  echo 'Created subsite returned no URL.' >&2
  exit 1
fi
wp plugin activate safety-passwords --network --quiet
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-policy.php active
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php ordinary-network-prepare
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php ordinary-network-verify
run_schedule_recovery --url=http://integration.invalid
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php status
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy-verify
run_cli_password_phase network http://integration.invalid 1 "$cli_subsite_id" "$cli_subsite_url"
wp plugin deactivate safety-passwords --network --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/network-policy.php inactive
wp --url=http://integration.invalid eval-file /test-fixtures/network-mu-lifecycle.php prepare
wp plugin activate safety-passwords --network --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/network-mu-lifecycle.php ordinary-complete
cp /test-fixtures/mu-loader.php wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-mu-lifecycle.php initial
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-mu-lifecycle.php repeat
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php prepare
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php verify
run_schedule_recovery --url=http://integration.invalid
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php status
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy-verify
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/lifecycle-controls.php admin
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw --quiet
wp config set WP_ACCESSIBLE_HOSTS '127.0.0.1' --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/http-admin-post.php network
wp config delete WP_ACCESSIBLE_HOSTS --quiet
wp config delete WP_HTTP_BLOCK_EXTERNAL --quiet
wp safety init --url=http://integration.invalid
wp eval-file /test-fixtures/lifecycle-controls.php cli-verify --url=http://integration.invalid
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-boundaries.php prepare
wp safety init --url=http://integration.invalid
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-boundaries.php verify
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-controls.php network-capture
# WordPress pins every request to network 1 while both generated constants exist.
# Remove them only in this disposable config so each --url starts a real network bootstrap.
wp config delete DOMAIN_CURRENT_SITE --quiet
wp config delete PATH_CURRENT_SITE --quiet
wp --url=http://sp-second-network.example.invalid/ eval-file /test-fixtures/lifecycle-migration.php prepare
wp --url=http://sp-second-network.example.invalid/ eval-file /test-fixtures/lifecycle-migration.php verify
wp safety init --url=http://sp-second-network.example.invalid/
wp --url=http://sp-second-network.example.invalid/ eval-file /test-fixtures/lifecycle-controls.php cli-verify
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-controls.php network-verify
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy
wp --url=http://integration.invalid eval-file /test-fixtures/lifecycle-migration.php schedule-legacy-verify
wp --url=http://sp-second-network.example.invalid/ --user=integration-admin eval-file /test-fixtures/network-boundaries-reverse.php second
wp --url=http://sp-second-network.example.invalid/boundary-subsite/ eval-file /test-fixtures/network-boundaries-reverse.php subsite
wp --url=http://sp-second-network.example.invalid/ eval-file /test-fixtures/network-boundaries-reverse.php second-reset
wp --url=http://integration.invalid eval-file /test-fixtures/network-boundaries-reverse.php finish
