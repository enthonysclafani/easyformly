<?php
global $wpdb;
wp_schedule_event( time(), 'daily', 'easyformly_daily_cleanup' );
$sql = 'CREATE TABLE wp_easyformly_entries ( id INT )';
echo '<p style="color: red">x</p>';
add_action( 'wp_enqueue_scripts', 'easyformly_fixture_assets' );
