<?php
/**
 * WP test suite config for the Axfit Wallet plugin's local dev test run.
 * Points at the MySQL test database created for this purpose only — never
 * run this against a real site's database.
 */

define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'woo_wallet_test' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'woo_wallet_test' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ?: 'woo_wallet_test' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Axfit Wallet Test Suite' );

define( 'WP_PHP_BINARY', PHP_BINARY );

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
