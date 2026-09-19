<?php
/**
 * PHPUnit bootstrap for Axfit Wallet: boots a real (throwaway) WordPress +
 * WooCommerce install via wp-phpunit, then loads this plugin, so tests run
 * against real $wpdb/wallet-ledger behavior instead of mocks.
 */

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$wp_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

function _woo_wallet_manually_load_plugin() {
	require dirname( __DIR__ ) . '/tests/wp-content/plugins/woocommerce/woocommerce.php';
	require dirname( __DIR__ ) . '/woo-wallet.php';
}
tests_add_filter( 'muplugins_loaded', '_woo_wallet_manually_load_plugin' );

require $wp_tests_dir . '/includes/bootstrap.php';

/*
 * Plain `require`ing the plugin file (above) never fires
 * register_activation_hook(), so the base schema (woo_wallet_transactions,
 * etc.) is never created — only the `plugins_loaded` version-check
 * (Woo_Wallet_Install::update()) runs automatically, which only applies
 * incremental $db_updates migrations on top of an assumed-existing base
 * schema (each guarded by a `SHOW TABLES LIKE` check, so they cleanly no-op
 * here rather than error). Call the real activation routine once, here, so
 * every table the plugin expects to exist actually does before any test
 * runs.
 *
 * This has to happen *after* the full WP+WooCommerce bootstrap above (not
 * hooked earlier into `plugins_loaded`) because it also creates a demo
 * product via wc_get_product(), which isn't safe to call until
 * WooCommerce's post types are registered.
 */
Woo_Wallet_Install::install();
