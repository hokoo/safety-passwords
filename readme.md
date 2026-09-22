# DEV Environment for Safety Passwords WordPress plugin

## Requirements
Linux or WSL2, Make, Docker Compose. The isolated integration runner also needs Python 3 and `iproute2` (`ip`).

## Notice
Call all commands from root project directory.

## Installation

```bash
bash ./dev/init.sh && make docker.up
make connect.php 
composer install
```

Don't forget update your hosts file
`127.0.0.1 safetypasswords.local`.

## Development
WP plugin directory `plugin-dir`.

The expiry reminders in the admin bar and the user's own profile use the same elapsed-time calculation. They show a countdown before the deadline, a change-password notice once it is due, and a separate reset-required notice after a reset starts. A zero reset interval hides both reminders. Rendering these notices does not update user metadata or initiate a reset.

The periodic check starts a mandatory reset and attempts one recovery email for an expired account. Later checks leave that reset pending, including after a failed email attempt. A successful reset or profile password change clears the pending state and renews the expiry date. This profile cleanup also applies on supported WordPress versions before 6.3.

On WordPress 5.0 through 5.6, background reset requests use the core reset-key and mail APIs because the login-page recovery function is not loaded during cron or WP-CLI checks. Newer versions use the core recovery function directly.

## Isolated WordPress integration checks

Install only the plugin dependencies first, then run the same command used by CI:

```bash
cd plugin-dir && composer install --no-scripts && cd ..
bash dev/tests/run.sh php74-wp50
bash dev/tests/run.sh php74-wp68
bash dev/tests/run.sh php82-wp68
```

Run a target twice to confirm repeatability. The targets cover PHP 7.4 with WordPress 5.0 and 6.8, and PHP 8.2 with WordPress 6.8. Each run creates its own Compose project, database and WordPress volume, then removes that project on exit. It does not use the development `.env`, `wp-config.php`, database, or `dev/setup.sh`. Only the core download container has internet access; the WordPress test container and database are on a private internal network. The test MU plugin intercepts all mail before WordPress loads its mail function. The runner rejects nonlocal Docker targets and refuses to reuse an existing test project. Do not run it against a real WordPress installation.

The runner selects two `/24` subnets from `10.254.0.0/16` after inspecting existing Docker networks and local IPv4 routes and interfaces. It refuses to create a target if that inspection fails or fewer than two free subnets remain.

The cron scenario checks plugin boot, one `twicedaily` event, repeat scheduling, removal, and the enabled and zero interval callback paths. The activation scenario checks the deferred setup of the periodic event and initial password history on the next normal request. It also prints a separate MU startup characterization; that observation does not establish a lifecycle fix. A remote CI result is available only after the workflow has run on GitHub.
