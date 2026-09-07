<?php
/**
 * Table name helpers and shared query utilities.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin data-access helper around the three custom tables.
 */
class TZ_DB {

	/**
	 * Fully qualified subscribers table name.
	 *
	 * @return string
	 */
	public static function subscribers() {
		global $wpdb;
		return $wpdb->prefix . 'tz_subscribers';
	}

	/**
	 * Fully qualified delivery log table name.
	 *
	 * @return string
	 */
	public static function logs() {
		global $wpdb;
		return $wpdb->prefix . 'tz_delivery_logs';
	}

	/**
	 * Fully qualified campaigns table name.
	 *
	 * @return string
	 */
	public static function campaigns() {
		global $wpdb;
		return $wpdb->prefix . 'tz_campaigns';
	}

	/**
	 * Current site time in MySQL DATETIME format.
	 *
	 * Uses current_time( 'mysql' ) so log rows line up with everything else
	 * WordPress renders in the admin.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Count subscribers grouped by status.
	 *
	 * Returns every known status key so callers can index without isset()
	 * guards even when a status has zero rows.
	 *
	 * @return array<string,int> Map of status => count.
	 */
	public static function status_counts() {
		global $wpdb;

		$counts = array(
			'queued'       => 0,
			'sent'         => 0,
			'delivered'    => 0,
			'bounced'      => 0,
			'complaint'    => 0,
			'unsubscribed' => 0,
			'failed'       => 0,
		);

		$table = self::subscribers();

		// Table name is built from $wpdb->prefix, never from user input.
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Total subscriber rows.
	 *
	 * @return int
	 */
	public static function total_subscribers() {
		global $wpdb;

		$table = self::subscribers();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Look up a single subscriber row by email address.
	 *
	 * @param string $email Email address.
	 * @return array|null Associative row or null when not found.
	 */
	public static function get_subscriber_by_email( $email ) {
		global $wpdb;

		$email = sanitize_email( $email );

		if ( empty( $email ) ) {
			return null;
		}

		$table = self::subscribers();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Look up a single subscriber row by unsubscribe token.
	 *
	 * @param string $token Raw token from the request.
	 * @return array|null Associative row or null when not found.
	 */
	public static function get_subscriber_by_token( $token ) {
		global $wpdb;

		// Tokens are hex only; strip anything else before it reaches the DB.
		$token = preg_replace( '/[^a-f0-9]/i', '', (string) $token );

		if ( empty( $token ) || strlen( $token ) > 64 ) {
			return null;
		}

		$table = self::subscribers();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE unsub_token = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Update a subscriber's status by email address.
	 *
	 * Terminal states are protected: once a contact is unsubscribed, bounced or
	 * flagged as a complaint we never silently downgrade them back to a
	 * sendable state, no matter what order the SNS notifications arrive in.
	 *
	 * @param string $email      Email address.
	 * @param string $new_status One of the allowed status keys.
	 * @return bool True when a row was actually updated.
	 */
	public static function update_status_by_email( $email, $new_status ) {
		global $wpdb;

		$email      = sanitize_email( $email );
		$new_status = sanitize_text_field( $new_status );

		$allowed = array( 'queued', 'sent', 'delivered', 'bounced', 'complaint', 'unsubscribed', 'failed' );

		if ( empty( $email ) || ! in_array( $new_status, $allowed, true ) ) {
			return false;
		}

		$table = self::subscribers();

		// Statuses that must never be overwritten by a later, weaker signal.
		$terminal = array( 'unsubscribed', 'bounced', 'complaint' );

		if ( in_array( $new_status, $terminal, true ) ) {
			// Suppression signals always win, except over each other in order:
			// unsubscribed > complaint > bounced is not meaningful, so simply
			// allow any terminal state to overwrite any non-terminal one.
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE email = %s AND status <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$new_status,
				$email,
				$new_status
			);
		} else {
			// Non-terminal updates (sent, delivered, failed) must not resurrect
			// a suppressed contact.
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE email = %s AND status NOT IN ( 'unsubscribed', 'bounced', 'complaint' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$new_status,
				$email
			);
		}

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return ( is_numeric( $updated ) && $updated > 0 );
	}

	/**
	 * Generate a cryptographically random unsubscribe token.
	 *
	 * Falls back to wp_generate_password() on the (very unlikely) systems where
	 * random_bytes() is unavailable, so activation never fatals.
	 *
	 * @return string 32 character hex string.
	 */
	public static function generate_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 32 );
		}
	}
}
