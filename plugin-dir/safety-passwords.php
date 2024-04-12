<?php
/*
Plugin Name: Enforce Strong User Passwords
Description: Forces all users to have a strong password when they're changing it on their profile page.
Version: 1.0
Author: iTRON
License: GPL2
*/

namespace Cointelegraph\Plugins;

use iTRON\SafetyPasswords\Settings;

require_once __DIR__ . '/vendor/autoload.php';

( new Settings() )->init();
