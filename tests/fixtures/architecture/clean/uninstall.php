<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
wp_unschedule_hook( 'easyformly_daily_cleanup' );
