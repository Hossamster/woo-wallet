<?php
/**
 * WooCommerce wallet Uninstall
 *
 * Uninstalling WooCommerce wallet product, tables, and options.
 *
 * @author      Subrata Mal
 * @version 1.0.1
 *
 * @package StandaleneTech
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb, $wp_version;

// Remove rechargable product.
wp_delete_post( get_option( '_woo_wallet_recharge_product' ), true );
delete_option( '_woo_wallet_recharge_product' );

// Dangling scheduled task, not user data — clear it regardless of
// WALLET_REMOVE_ALL_DATA, since the callback it points at won't exist once
// the plugin's files are gone either way.
wp_clear_scheduled_hook( 'woo_wallet_withdrawal_cleanup_receipts_cron' );
wp_clear_scheduled_hook( 'woo_wallet_withdrawal_check_receipt_protection_cron' );
wp_clear_scheduled_hook( 'woo_wallet_daily_digest_cron' );

/*
 * Only remove ALL plugins data if WALLET_REMOVE_ALL_DATA constant is set to true in user's
 * wp-config.php. This is to prevent data loss when deleting the plugin from the backend
 * and to ensure only the site owner can perform this action.
 */
if ( defined( 'WALLET_REMOVE_ALL_DATA' ) && true === WALLET_REMOVE_ALL_DATA ) {
	remove_role( 'wallet_support_agent' );
	remove_role( 'wallet_accountant' );
	delete_metadata( 'user', 0, '_woo_wallet_staff_credit_limit', '', true );
	delete_metadata( 'user', 0, '_woo_wallet_staff_daily_limit', '', true );
	delete_metadata( 'user', 0, '_woo_wallet_staff_level', '', true );
	delete_metadata( 'user', 0, '_woo_wallet_approval_emails_paused', '', true );
	delete_option( 'woo_wallet_staff_levels' );
	delete_option( 'woo_wallet_approval_email_recipients' );
	delete_option( 'woo_wallet_approval_email_excluded' );
	// Tables. Must stay in sync with every CREATE TABLE in Woo_Wallet_Install — the
	// "uninstall.php drops every table install creates" CI check enforces this.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_transactions" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_transaction_meta" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_referrals" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_withdrawals" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_withdrawal_notes" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_approval_requests" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}woo_wallet_audit_log" );

	// Delete the balance cache user meta — the ledger it mirrored no longer exists.
	$wpdb->query( "DELETE FROM $wpdb->usermeta WHERE meta_key = '_current_woo_wallet_balance'" );

	// Delete options.
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_wallet\_%';" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_woo_wallet\_%';" );

	// Email opt-in flags. They carry no leading underscore, so the LIKE
	// patterns above do not reach them.
	delete_option( 'woo_wallet_optin_done' );
	delete_option( 'woo_wallet_optin_dismissed' );

	// Clear any cached data that has been removed.
	wp_cache_flush();
}
