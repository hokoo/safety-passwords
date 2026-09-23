#!/bin/sh
set -eu

wp() {
  command wp --allow-root "$@"
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
wp --user=integration-admin eval-file /test-fixtures/cron.php
wp --user=integration-admin eval-file /test-fixtures/expiry-notices.php ordinary
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php first
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php second
wp --user=integration-admin eval-file /test-fixtures/cron-reset-lifecycle.php cleanup
wp safety check-users

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
wp eval-file /test-fixtures/mu-lifecycle.php initial
wp eval-file /test-fixtures/mu-lifecycle.php repeat
wp --user=integration-admin eval-file /test-fixtures/expiry-notices.php mu

# An ordinary activation still uses its deferred phase after MU removal.
rm wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-lifecycle.php removed
wp cron event delete safety_passwords_periodically_reset --url=http://integration.invalid --quiet
wp eval-file /test-fixtures/mu-lifecycle.php prepare-ordinary
wp plugin activate safety-passwords --quiet
wp --user=integration-admin eval-file /test-fixtures/activation.php transition
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
wp site create --slug=subsite --title=Subsite --email=integration@example.invalid --quiet >/dev/null
wp plugin activate safety-passwords --network --quiet
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-policy.php active
wp plugin deactivate safety-passwords --network --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/network-policy.php inactive
wp --url=http://integration.invalid eval-file /test-fixtures/network-mu-lifecycle.php prepare
wp plugin activate safety-passwords --network --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/network-mu-lifecycle.php ordinary-pending
cp /test-fixtures/mu-loader.php wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-mu-lifecycle.php initial
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-mu-lifecycle.php repeat
