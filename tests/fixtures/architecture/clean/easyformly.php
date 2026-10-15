<?php
defined( 'ABSPATH' ) || exit;

// Mentions in comments never count: $wpdb, eval, wp_schedule_event( ... ), CREATE TABLE.
add_action( 'init', 'easyformly_fixture_init' );
