<?php
defined( 'ABSPATH' ) || exit;

eval( 'return 1;' );
file_put_contents( '/tmp/x', 'x' );
wp_remote_get( 'https://example.org' );
wp_enqueue_style( 'easyformly-extra', 'extra.css', array(), '1' );
unserialize( 'a:0:{}' );
add_action( 'init', 'easyformly_extra' );
