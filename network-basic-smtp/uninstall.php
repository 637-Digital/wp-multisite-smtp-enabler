<?php
/**
 * Uninstall script for Network Basic SMTP plugin.
 * Removes all plugin data when the plugin is deleted.
 *
 * @package Network_Basic_SMTP
 * @author  637 Digital Solutions
 * @license MIT
 * @link    https://637digital.com
 *
 * Copyright (c) 2026 637 Digital Solutions
 * Licensed under the MIT License.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove network-wide settings
delete_site_option('nbsmtp_settings');
