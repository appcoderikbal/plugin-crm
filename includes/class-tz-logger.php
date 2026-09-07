<?php
/**
 * Delivery audit log writer / reader.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and queries rows in {$wpdb->prefix}tz_delivery_logs.
 */
class TZ_Logger {

	/**
	 * Canonical event type list.
	 *
	 * The first seven are the deliverability events required by the SES
	 * pipeline. `Unsubscribed` and `System` are audit-only additions so the log
	 * screen can tell the full story of a contact without a second table.
	 *
	 * @return array<string,string> Map of event key => human label.
	 */
	public static function event_types() {
		return array(
			'Sent_Success'         => __( 'Sent Success', 'tz-mailer' ),
			'Delivery_Confirmed'   => __( 'Delivery Confirmed', 'tz-mailer' ),
			'Hard_Bounce'          => __( 'Hard Bounce', 'tz-mailer' ),
			'Soft_Bounce'          => __( 'Soft Bounce', 'tz-mailer' ),
			'Spam_Complaint'       => __( 'Spam Complaint', 'tz-mailer' ),
			'API_Error'            => __( 'API Error', 'tz-mailer' ),
			'Circuit_Breaker_Halt' => __( 'Circuit Breaker Halt', 'tz-mailer' ),
			'Unsubscribed'         => __( 'Unsubscribed', 'tz-mailer' ),
			'System'               => __( 'System', 'tz-mailer' ),
		);
	}

	/**
	 * CSS modifier suffix used for the colour-coded badge on the log screen.
	 *
	 * @param string $event_type Event key.
	 * @return string One of: success, info, danger, warning, critical, neutral.
	 */
	public static function badge_class( $event_type ) {
		$map = array(
			'Sent_Success'         => 'success',
			'Delivery_Confirmed'   => 'success',
			'Hard_Bounce'          => 'danger',
			'Soft_Bounce'          => 'warning',
			'Spam_Complaint'       => 'critical',
			'API_Error'            => 'danger',
			'Circuit_Breaker_Halt' => 'critical',
			'Unsubscribed'         => 'info',
			'System'               => 'neutral',
		);

		return isset( $map[ $event_type ] ) ? $map[ $event_type ] : 'neutral';
	}

	/**
	 * Insert one audit row.
	 *
	 * Never throws: logging must not be able to take the send pipeline down.
	 *
	 * @param string       $email      Recipient address (may be empty for system events).
	 * @param string       $event_type One of self::event_types() keys.
	 * @param string|array $details    Diagnostic text, or an array which is JSON encoded.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function log( $email, $event_type, $details = '' ) {
		global $wpdb;

		$event_type = sanitize_text_field( $event_type );

		if ( ! array_key_exists( $event_type, self::event_types() ) ) {
			$event_type = 'System';
		}

		if ( is_array( $details ) || is_object( $details ) ) {
			$details = wp_json_encode( $details );
		}

		// Keep individual rows bounded so a runaway API response cannot bloat
		// the table; TEXT tops out at 65,535 bytes.
		$details = (string) $details;
		if ( strlen( $details ) > 60000 ) {
			$details = substr( $details, 0, 60000 ) . ' ...[truncated]';
		}

		$inserted = $wpdb->insert(
			TZ_DB::logs(),
			array(
				'email'      => sanitize_email( $email ),
				'event_type' => $event_type,
				'details'    => $details,
				'logged_at'  => TZ_DB::now(),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Count log rows matching an optional search term / event filter.
	 *
	 * @param string $search     Free text applied to email and details.
	 * @param string $event_type Exact event type filter, or '' for all.
	 * @return int
	 */
	public static function count( $search = '', $event_type = '' ) {
		global $wpdb;

		$table = TZ_DB::logs();

		list( $where, $params ) = self::build_where( $search, $event_type );

		$sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fetch a page of log rows, newest first.
	 *
	 * @param string $search     Free text applied to email and details.
	 * @param string $event_type Exact event type filter, or '' for all.
	 * @param int    $per_page   Rows per page.
	 * @param int    $offset     Row offset.
	 * @return array<int,array> Associative rows.
	 */
	public static function query( $search = '', $event_type = '', $per_page = 25, $offset = 0 ) {
		global $wpdb;

		$table = TZ_DB::logs();

		list( $where, $params ) = self::build_where( $search, $event_type );

		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = max( 0, (int) $offset );

		$params[] = $per_page;
		$params[] = $offset;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $rows ? $rows : array();
	}

	/**
	 * Build the shared WHERE fragment plus its bound parameters.
	 *
	 * Placeholders are emitted here and bound by the caller through
	 * $wpdb->prepare(), so no user value is ever concatenated into SQL.
	 *
	 * @param string $search     Free text.
	 * @param string $event_type Event filter.
	 * @return array{0:string,1:array} WHERE clause and ordered parameters.
	 */
	private static function build_where( $search, $event_type ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		$search = sanitize_text_field( (string) $search );

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '( email LIKE %s OR details LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
		}

		$event_type = sanitize_text_field( (string) $event_type );

		if ( '' !== $event_type && array_key_exists( $event_type, self::event_types() ) ) {
			$clauses[] = 'event_type = %s';
			$params[]  = $event_type;
		}

		$where = empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses );

		return array( $where, $params );
	}

	/**
	 * Delete log rows older than the retention window.
	 *
	 * @param int $days Retention window in days. 0 disables pruning.
	 * @return int Rows removed.
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days = (int) $days;

		if ( $days < 1 ) {
			return 0;
		}

		$table  = TZ_DB::logs();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE logged_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
