#!/bin/sh
set -eu

run_cli_password_phase() {
  cli_mode=$1
  cli_url=$2
  cli_restore_interval=$3
  cli_subsite_id=${4:-}
  cli_subsite_url=${5:-}
  cli_primary="sp-cli-$cli_mode-primary"
  cli_secondary="sp-cli-$cli_mode-secondary"
  cli_fixture=/test-fixtures/cli-password-changes.php

  cli_wp() {
    if [ -n "$cli_url" ]; then
      wp --url="$cli_url" "$@"
    else
      wp "$@"
    fi
  }

  cli_assert_warning() {
    cli_output=$1
    cli_count=$(printf '%s\n' "$cli_output" | grep -c 'Safety Passwords: WP-CLI password changes bypass strength and reuse checks; password history and expiry were updated.' || true)
    if [ "$cli_count" -ne 1 ]; then
      echo 'CLI password disclaimer count or content mismatch.' >&2
      exit 1
    fi
  }

  cli_assert_silent() {
    if printf '%s\n' "$1" | grep -q 'Safety Passwords:'; then
      echo 'Unexpected CLI password warning for unchanged account.' >&2
      exit 1
    fi
  }

  cli_wp eval-file "$cli_fixture" prepare "$cli_mode"
  cli_weak=$(php -r 'echo strtolower(bin2hex(random_bytes(3)));')
  cli_output=$(printf '%s\n' "$cli_weak" | cli_wp user update "$cli_primary" --prompt=user_pass --skip-email 2>&1 >/dev/null)
  cli_assert_warning "$cli_output"
  printf '%s\n' "$cli_weak" | cli_wp eval-file "$cli_fixture" weak "$cli_mode"
  unset cli_weak

  cli_wp eval-file "$cli_fixture" arm "$cli_mode"
  cli_output=$(cli_wp user update "$cli_primary" --display_name=Changed --skip-email 2>&1 >/dev/null)
  cli_assert_silent "$cli_output"
  cli_failed=$(php -r 'echo bin2hex(random_bytes(20)), "aA1!";')
  if cli_output=$(printf '%s\n' "$cli_failed" | cli_wp user update "$cli_primary" --user_email="sp-cli-$cli_mode-secondary@example.invalid" --prompt=user_pass --skip-email 2>&1 >/dev/null); then
    :
  fi
  if ! printf '%s\n' "$cli_output" | grep -Eq '(^|[[:space:]])(Error|Warning):'; then
    echo 'Rejected CLI password update did not report a failure.' >&2
    exit 1
  fi
  cli_assert_silent "$cli_output"
  printf '%s\n' "$cli_failed" | cli_wp eval-file "$cli_fixture" unchanged "$cli_mode"
  unset cli_failed

  cli_strong=$(php -r 'echo bin2hex(random_bytes(20)), "aA1!";')
  cli_output=$(printf '%s\n' "$cli_strong" | cli_wp user update "$cli_primary" --prompt=user_pass --skip-email 2>&1 >/dev/null)
  cli_assert_warning "$cli_output"
  cli_wp eval-file "$cli_fixture" arm "$cli_mode"
  cli_output=$(printf '%s\n' "$cli_strong" | cli_wp user update "$cli_primary" --prompt=user_pass --skip-email 2>&1 >/dev/null)
  cli_assert_warning "$cli_output"
  printf '%s\n' "$cli_strong" | cli_wp eval-file "$cli_fixture" reused "$cli_mode"
  unset cli_strong

  cli_wp eval-file "$cli_fixture" arm "$cli_mode"
  cli_output=$(cli_wp user reset-password "$cli_primary" --skip-email 2>&1 >/dev/null)
  cli_assert_warning "$cli_output"
  cli_wp eval-file "$cli_fixture" reset "$cli_mode"

  cli_wp eval-file "$cli_fixture" arm "$cli_mode"
  cli_batch=$(php -r 'echo bin2hex(random_bytes(20)), "aA1!";')
  if cli_output=$(printf '%s\n' "$cli_batch" | cli_wp user update "$cli_primary" "$cli_secondary" --user_email="sp-cli-shared-$cli_mode@example.invalid" --prompt=user_pass --skip-email 2>&1 >/dev/null); then
    :
  fi
  cli_assert_warning "$cli_output"
  unset cli_batch
  cli_wp eval-file "$cli_fixture" partial "$cli_mode"
  cli_output=$(cli_wp user reset-password "$cli_primary" "$cli_secondary" --skip-email 2>&1 >/dev/null)
  cli_assert_warning "$cli_output"
  cli_wp eval-file "$cli_fixture" multi "$cli_mode"
  if [ "$cli_mode" = network ]; then
    wp --url="$cli_subsite_url" eval-file "$cli_fixture" network-observe "$cli_mode" "$cli_subsite_id"
  fi
  cli_wp eval-file "$cli_fixture" finish "$cli_mode" "$cli_restore_interval"
}
