<?php
/**
 * Plugin Name: Multisite Network Basic SMTP enabler
 * Description: Minimal SMTP plugin for WordPress Multisite with network-admin-only UI, encrypted credential storage, a network-wide footer, and test email support.
 * Version: 0.1
 * Author: 637 Digital Solutions
 * Author URI: https://637digital.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Network: true
 * Requires at least: 5.5
 * Requires PHP: 7.2
 *
 * Copyright (c) 2026 637 Digital Solutions
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NBSMTP_VERSION', '0.1');
define('NBSMTP_PLUGIN_FILE', __FILE__);
define('NBSMTP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once NBSMTP_PLUGIN_DIR . 'includes/class-network-basic-smtp.php';

function nbsmtp_boot_plugin() {
	load_plugin_textdomain('network-basic-smtp', false, dirname(plugin_basename(NBSMTP_PLUGIN_FILE)) . '/languages');
	Network_Basic_SMTP::get_instance();
}
nbsmtp_boot_plugin();
