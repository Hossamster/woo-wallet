<?php
/**
 * Self-hosted plugin update checker (GitHub-backed).
 *
 * Lets sites running this plugin see "Update available" in wp-admin and
 * one-click update, exactly like a WordPress.org-hosted plugin — but the
 * source of truth is this repo's GitHub Releases/Tags, not every push to
 * `main`. A regular commit/push has no effect on installed sites; only an
 * explicit tag or published Release does. See docs/RELEASING.md for the
 * release process.
 *
 * Uses the bundled Plugin Update Checker library (Yahnis Elsts,
 * MIT-licensed) rather than composer, so no `composer install` step is
 * required on a live site — see includes/libraries/plugin-update-checker/.
 *
 * @package StandaleneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'woo_wallet_init_update_checker' ) ) {
	/**
	 * Boot the update checker. wp-admin only — the update check itself only
	 * ever matters there, and there's no reason to load this on every
	 * front-end page request.
	 */
	function woo_wallet_init_update_checker() {
		if ( ! is_admin() ) {
			return;
		}

		require_once WOO_WALLET_ABSPATH . 'includes/libraries/plugin-update-checker/plugin-update-checker.php';

		$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/Hossamster/woo-wallet/',
			WOO_WALLET_PLUGIN_FILE,
			'woo-wallet'
		);

		$update_checker->setBranch( 'main' );

		// Prefer a Release's uploaded zip asset (see the release-build GitHub
		// Action) over GitHub's own auto-generated source archive, which would
		// otherwise ship tests/, .github/, composer.*, etc. to end users.
		$update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
	}
	add_action( 'plugins_loaded', 'woo_wallet_init_update_checker' );
}
