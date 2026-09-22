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

# Characterize current MU startup after ordinary plugin deactivation.
wp plugin deactivate safety-passwords --quiet
wp eval-file /test-fixtures/deactivation.php
wp plugin activate safety-passwords --quiet
wp plugin deactivate safety-passwords --quiet
wp eval-file /test-fixtures/activation.php pending
cp /test-fixtures/mu-loader.php wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp eval-file /test-fixtures/mu-characterization.php
wp --user=integration-admin eval-file /test-fixtures/expiry-notices.php mu

# Convert only this disposable installation after the ordinary and MU characterization phases.
rm wp-content/mu-plugins/10-safety-passwords-test-loader.php
wp core multisite-convert --subdomains=false --quiet
wp site create --slug=subsite --title=Subsite --email=integration@example.invalid --quiet >/dev/null
wp plugin activate safety-passwords --network --quiet
wp --url=http://integration.invalid --user=integration-admin eval-file /test-fixtures/network-policy.php active
wp plugin deactivate safety-passwords --network --quiet
wp --url=http://integration.invalid eval-file /test-fixtures/network-policy.php inactive
