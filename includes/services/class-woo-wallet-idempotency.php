<?php
/**
 * Idempotency helper for state-changing REST endpoints.
 *
 * Stores (user_id, key) → (status_code, body, created_at) so a retry of the
 * same logical action returns the original response verbatim instead of
 * re-executing the side-effect. Differs from the form-side single-use claim
 * in Woo_Wallet_Frontend (`wwxfer_*` transients) — that one consumes the key
 * on first use; this one preserves the result for the TTL window.
 *
 * @package StandaleneTech
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooWallet_Idempotency' ) ) {

	/**
	 * Idempotency cache for REST POST handlers.
	 */
	class WooWallet_Idempotency {

		const TRANSIENT_PREFIX = 'wwidem_';
		const TTL              = DAY_IN_SECONDS;
		// Must exceed the host's hard request ceiling, or a still-running request
		// outlives its own claim and a retry executes alongside it. PHP-FPM's
		// `request_terminate_timeout` is commonly 300s, so 5 minutes sits exactly
		// on the boundary; 15 clears it with room to spare.
		const IN_FLIGHT_TTL    = 15 * MINUTE_IN_SECONDS;

		/**
		 * Run $callback once for ($user_id, $key); replay the stored response on retries.
		 *
		 * The key is *claimed* before the callback runs, not after it returns. The
		 * side-effect (a committed ledger row) becomes durable the moment the insert
		 * lands, but the stored response is only written once the callback returns —
		 * so a request that dies in between (client timeout under PHP-FPM, fatal in a
		 * post-insert hook) used to leave the money moved and no replay record, and the
		 * retry would re-execute. Nothing in the ledger dedupes, so that retry could
		 * charge twice. The pre-claim closes that window: a retry that arrives while
		 * the original is unaccounted for gets a 409 "in progress" instead of a second
		 * charge or a misleading failure.
		 *
		 * The claim is read and written exclusively through raw wp_options rows
		 * (add_option()/get_option()/update_option()/delete_option()) — deliberately
		 * never through get_transient()/set_transient()/delete_transient(). Those two
		 * APIs are NOT interchangeable views of the same data once a persistent
		 * external object cache (Redis, Memcached) is active: the Transients API then
		 * reads and writes the cache, not wp_options, while the atomic claim below can
		 * only ever target wp_options (add_option()'s all-or-nothing guarantee, via
		 * the UNIQUE constraint on option_name, has no equivalent across every cache
		 * backend). Mixing the two here used to mean a cache flush or restart could
		 * wipe a completed result that only ever lived in the cache while the
		 * in-progress claim — written straight to the DB — survived untouched,
		 * permanently wedging that key at a 409 with no stored `at` timestamp for the
		 * in-flight-timeout check to ever see and take over. Keeping every read and
		 * write on this one raw option means a cache flush can never desync them.
		 *
		 * The `_transient_`/`_transient_timeout_` naming is kept anyway (not because
		 * anything here reads it as a transient) so WP-Cron's built-in expired-
		 * transient sweep — a direct wp_options query, unaffected by any object cache —
		 * still garbage-collects abandoned claims and expired replay records.
		 *
		 * The callback must return a `WP_REST_Response` or `WP_Error`. Anything else
		 * is passed through to the caller without caching, so callers cannot accidentally
		 * cache transient errors.
		 *
		 * @param int      $user_id  Owning user.
		 * @param string   $key      Idempotency-Key header value (any client-chosen string).
		 * @param callable $callback Zero-arg producer that runs the side-effect.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function run( $user_id, $key, callable $callback ) {
			$user_id = (int) $user_id;
			$key     = sanitize_text_field( (string) $key );

			if ( ! $user_id || '' === $key ) {
				return $callback();
			}

			// A disconnecting client must not abort a money-moving request midway —
			// that is precisely how the stored response went missing while the ledger
			// row survived. Let it run to completion and record its outcome.
			ignore_user_abort( true );

			$transient    = self::TRANSIENT_PREFIX . $user_id . '_' . md5( $key );
			$option_name  = '_transient_' . $transient;
			$timeout_name = '_transient_timeout_' . $transient;

			$claim = self::claim( $option_name );
			if ( ! is_string( $claim ) ) {
				// A completed result to replay (WP_REST_Response), or a 409
				// because someone else's claim is still fresh (WP_Error).
				return $claim;
			}
			$token = $claim;

			// Short timeout while genuinely in-flight — extended to the full TTL
			// once the callback finishes below, so a completed replay record
			// actually survives for the window the class docblock promises, and an
			// abandoned in-progress claim is swept up quickly instead of lingering.
			update_option( $timeout_name, time() + self::IN_FLIGHT_TTL, 'no' );

			$result = $callback();

			if ( $result instanceof WP_REST_Response ) {
				update_option(
					$option_name,
					array(
						'status' => $result->get_status(),
						'body'   => $result->get_data(),
						'at'     => time(),
					),
					'no'
				);
				update_option( $timeout_name, time() + self::TTL, 'no' );
			} else {
				// A genuine failure stays retryable — releasing the claim rather than
				// leaving the key wedged behind an error the client can fix. Release
				// only OUR claim: if this request outlived its own in-flight window,
				// what is stored now belongs to a later request and deleting it would
				// re-open that request to a duplicate execution.
				$current = self::read_claim( $option_name );
				if ( is_array( $current ) && isset( $current['token'] ) && $token === $current['token'] ) {
					delete_option( $option_name );
					delete_option( $timeout_name );
				}
			}

			return $result;
		}

		/**
		 * Claim $option_name for a fresh call, or explain why we can't.
		 *
		 * A prior version checked "is an in-progress claim sitting here?" and
		 * "is it stale enough to take over?" in two different places — the
		 * first at the top of run() (before ever attempting add_option()),
		 * the second only inside the branch that runs after add_option()
		 * itself fails. Every in-progress row that already exists is caught
		 * by the first check, so it always returns the 409 there and never
		 * reaches the second — the staleness takeover was dead code, and an
		 * abandoned claim from a crashed request stayed wedged at 409 forever
		 * instead of ever being taken over. One staleness check, applied
		 * wherever an existing in-progress row is found, replaces both.
		 *
		 * @param string $option_name Raw `_transient_*` option name.
		 * @return string|WP_REST_Response|WP_Error Token for a freshly (re-)acquired
		 *         claim; a completed result to replay; or a 409 because someone
		 *         else's claim is still fresh.
		 */
		private static function claim( $option_name ) {
			$existing = self::read_claim( $option_name );

			if ( self::is_completed( $existing ) ) {
				return self::replay( $existing );
			}

			if ( self::is_in_progress( $existing ) ) {
				if ( ! self::is_stale( $existing ) ) {
					return self::in_progress_error();
				}
				// Stale: take it over. Nobody can be mid-claim on a row that
				// both already exists (so the atomic add_option() below isn't
				// in play) and is old enough to have crossed IN_FLIGHT_TTL —
				// the request that owned it is long gone one way or another.
				return self::write_claim( $option_name );
			}

			// No row yet — attempt the atomic first claim. Because option_name
			// has a UNIQUE constraint in wp_options, at most one of any number
			// of simultaneous callers can win this.
			$token      = uniqid( '', true );
			$claim_data = array(
				'state' => 'in_progress',
				'at'    => time(),
				'token' => $token,
			);
			if ( add_option( $option_name, $claim_data, '', 'no' ) ) {
				return $token;
			}

			// Lost the race — re-read what the winner (or an older leftover
			// row we didn't see a moment ago) actually wrote.
			$current = self::read_claim( $option_name );
			if ( self::is_completed( $current ) ) {
				return self::replay( $current );
			}
			if ( self::is_in_progress( $current ) && self::is_stale( $current ) ) {
				return self::write_claim( $option_name );
			}
			return self::in_progress_error();
		}

		/**
		 * Overwrite $option_name with a fresh in-progress claim.
		 *
		 * @param string $option_name Raw `_transient_*` option name.
		 * @return string The new claim's token.
		 */
		private static function write_claim( $option_name ) {
			$token = uniqid( '', true );
			update_option(
				$option_name,
				array(
					'state' => 'in_progress',
					'at'    => time(),
					'token' => $token,
				),
				'no'
			);
			return $token;
		}

		/**
		 * Read a claim/result row. Always a plain get_option() — see run()'s
		 * docblock for why this must never be get_transient().
		 *
		 * @param string $option_name Raw `_transient_*` option name.
		 * @return array|false
		 */
		private static function read_claim( $option_name ) {
			$value = get_option( $option_name, false );
			return is_array( $value ) ? $value : false;
		}

		/**
		 * @param array|false $state
		 * @return bool
		 */
		private static function is_completed( $state ) {
			return is_array( $state ) && isset( $state['status'], $state['body'] );
		}

		/**
		 * @param array|false $state
		 * @return bool
		 */
		private static function is_in_progress( $state ) {
			return is_array( $state ) && isset( $state['state'] ) && 'in_progress' === $state['state'];
		}

		/**
		 * Whether an in-progress claim is old enough that its owning request
		 * must be presumed dead (crashed, or outlived PHP-FPM's hard ceiling).
		 *
		 * @param array $state In-progress claim row.
		 * @return bool
		 */
		private static function is_stale( $state ) {
			return isset( $state['at'] ) && ( time() - (int) $state['at'] > self::IN_FLIGHT_TTL );
		}

		/**
		 * @param array $state Completed claim row (status + body).
		 * @return WP_REST_Response
		 */
		private static function replay( $state ) {
			$response = new WP_REST_Response( $state['body'], (int) $state['status'] );
			$response->header( 'Idempotent-Replay', 'true' );
			return $response;
		}

		/**
		 * @return WP_Error
		 */
		private static function in_progress_error() {
			return new WP_Error(
				'terawallet_rest_idempotency_in_progress',
				__( 'A request with this Idempotency-Key is already being processed. Its outcome is not yet known — it may well have succeeded. Do not resubmit it as a new request; check the wallet transaction list, or retry this same key shortly.', 'woo-wallet' ),
				array( 'status' => 409 )
			);
		}
	}
}
