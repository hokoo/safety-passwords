# Safety Passwords

Safety Passwords is a WordPress plugin that enforces password strength and reuse rules, keeps password history, and can require periodic password resets. It provides settings through Carbon Fields, a `wp safety` CLI command, and optional Stream logging. It supports ordinary and must-use (MU) installation and WordPress multisite.

The plugin requires PHP 7.4+ and WordPress 5.0+; version 1.5 is tested through WordPress 7.1.2. For installation, configuration, policy behavior, multisite and MU operation, and upgrade notes, see the [plugin readme](plugin-dir/readme.txt). The [WordPress.org page](https://wordpress.org/plugins/safety-passwords/) describes the published release.

## Developer quick start

To start working on the plugin, use the [isolated checks](#checks) or set up the [local WordPress environment](#local-wordpress-environment) for browser-based development.

Open an issue or pull request on [GitHub](https://github.com/hokoo/safety-passwords) with the behavior you are changing and the WordPress/PHP versions you checked. Keep changes focused and preserve PHP 7.4 syntax, WordPress 5.0 compatibility, multisite behavior, public hooks and constants, and existing stored data. Never put passwords, reset keys, password hashes, user data, or credentials in commits or test output.

The main code is in [`plugin-dir/safety-passwords.php`](plugin-dir/safety-passwords.php) and [`plugin-dir/src/`](plugin-dir/src/). The latter contains policy and reset handling (`Controller.php`), settings (`Settings.php`), activation and scheduling (`Activation.php`, `Cron.php`), and CLI and Stream integrations (`Integrations/`, `Loggers/`). Assets and translations are in [`plugin-dir/assets/`](plugin-dir/assets/) and [`plugin-dir/languages/`](plugin-dir/languages/). There are separate Composer manifests for the plugin (`plugin-dir/`) and local development (repository root).

### Checks

For isolated WordPress integration checks, use Linux or WSL2 with PHP and Composer, a **local** Docker daemon with Docker Compose v2 (`docker compose`), Python 3, and `iproute2` (`ip`). Network access is needed to download WordPress and the test Stream plugin. From the repository root, install plugin dependencies without running Composer scripts, then run the relevant target:

```bash
cd plugin-dir && composer install --no-scripts && cd ..
bash dev/tests/run.sh php82-wp712
```

Other targets are `php85-wp712`, `php74-wp50`, `php74-wp68`, and `php82-wp68`; the names identify the PHP and WordPress versions. The runner uses a disposable Compose project, intercepts mail, removes its resources on exit, and refuses a nonlocal Docker target. CI runs all five targets, lints PHP, and validates a release package in [WordPress integration](.github/workflows/test-integration.yml). There is no separate PHP unit test suite or PHPCS command. Run `php -l` on each changed PHP file and choose the relevant integration target(s) for behavior changes. After changing dependencies, run `composer validate --no-check-publish` in each affected manifest's directory.

### Local WordPress environment

The local development stack requires Linux or WSL2, Make, Docker, and the legacy `docker-compose` executable used by its [Make targets](makefile) (the isolated runner uses Compose v2). On a fresh, disposable environment, from the repository root:

```bash
bash dev/init.sh
```

[`dev/init.sh`](dev/init.sh) creates a local `.env` if needed. Review and edit its local settings before starting services; if you change the domain, update the generated nginx configuration too. Then continue:

```bash
make docker.up
make connect.php
composer install
```

Run the final `composer install` **inside the PHP container** opened by `make connect.php`. The root Composer `post-install-cmd` runs [`dev/setup.sh`](dev/setup.sh): it resets the local WordPress database and prints local admin credentials. Do not run it against a database you need to retain or capture its output in a shared log. Add `127.0.0.1 safetypasswords.local` to the host's hosts file, then open `https://safetypasswords.local`. The isolated integration runner does not use this environment.

## AI agent instructions

AI coding agents must read [AGENTS.md](AGENTS.md) before working in this repository. It defines agent-specific workflow, delegation, verification, and safety rules. Human contributors can follow the developer quick start above without using the agent workflow.

## Releases

[`dev/release/`](dev/release/) contains package and delivery helpers; [release automation notes](docs/plans/2026-09-23-release-automation.md) explain their checks and publication boundaries. [Process Update](.github/workflows/process-update.yml) runs after a GitHub Release is published. Before a stable release, the maintainer must confirm that the `wordpress-org` GitHub environment has valid `WPORG_USERNAME` and `WPORG_PASSWORD` secrets; the recorded placeholder values are not publication credentials. Publishing and WordPress.org delivery are maintainer actions.
