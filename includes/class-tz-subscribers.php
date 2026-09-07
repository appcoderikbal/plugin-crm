<?php
/**
 * Subscriber list operations.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insert, query and bulk-manage rows in tz_subscribers.
 */
class TZ_Subscribers {

	/**
	 * Insert many subscribers in one statement.
	 *
	 * Uses INSERT IGNORE against the UNIQUE email index so a duplicate inside
	 * the payload can never abort the whole batch. Every value is bound
	 * through $wpdb->prepare(); the placeholder string is generated from the
	 * row count, not from any user input.
	 *
	 * @param array<int,array{email:string,name:string}> $rows Pre-validated rows.
	 * @return int Number of rows actually inserted.
	 */
	public static function insert_batch( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$table = TZ_DB::subscribers();
		$now   = TZ_DB::now();

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(%s, %s, %s, %s, %s)';

			$values[] = $row['email'];
			$values[] = $row['name'];
			$values[] = 'queued';
			$values[] = TZ_DB::generate_token();
			$values[] = $now;
		}

		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (email, name, status, unsub_token, created_at) VALUES " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. implode( ', ', $placeholders ),
			$values
		);

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Which of the given emails already exist in the table.
	 *
	 * Chunked so a huge CSV never builds a multi-megabyte IN() clause.
	 *
	 * @param array<int,string> $emails Candidate addresses.
	 * @return array<string,bool> Map of existing email => true.
	 */
	public static function existing_emails( array $emails ) {
		global $wpdb;

		$found = array();

		if ( empty( $emails ) ) {
			return $found;
		}

		$table  = TZ_DB::subscribers();
		$chunks = array_chunk( array_values( array_unique( $emails ) ), 500 );

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			$sql = $wpdb->prepare(
				"SELECT email FROM {$table} WHERE email IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			);

			$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $email ) {
					$found[ strtolower( $email ) ] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Claim the next slice of the sending queue.
	 *
	 * Rows are ordered by id so the queue drains in import order, and rows
	 * that have already burned through their retry allowance are skipped.
	 *
	 * @param int $limit        Maximum rows to return.
	 * @param int $max_attempts Attempt ceiling before a row is abandoned.
	 * @return array<int,array> Subscriber rows.
	 */
	public static function claim_batch( $limit, $max_attempts = 3 ) {
		global $wpdb;

		$limit        = max( 1, (int) $limit );
		$max_attempts = max( 1, (int) $max_attempts );
		$table        = TZ_DB::subscribers();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, name, unsub_token, attempts FROM {$table} WHERE status = 'queued' AND attempts < %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$max_attempts,
				$limit
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Record a successful send against a subscriber row.
	 *
	 * @param int $id          Subscriber ID.
	 * @param int $campaign_id Campaign that was sent.
	 * @return void
	 */
	public static function mark_sent( $id, $campaign_id ) {
		global $wpdb;

		$wpdb->update(
			TZ_DB::subscribers(),
			array(
				'status'      => 'sent',
				'campaign_id' => (int) $campaign_id,
				'sent_at'     => TZ_DB::now(),
				'last_error'  => '',
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failed send attempt.
	 *
	 * A retryable failure stays 'queued' with an incremented attempt counter;
	 * a permanent failure (or one that exhausted its retries) moves to
	 * 'failed' and never blocks the queue again.
	 *
	 * @param int    $id           Subscriber ID.
	 * @param string $error        Failure description.
	 * @param bool   $retryable    Whether another attempt is worthwhile.
	 * @param int    $attempts     Attempt count before this failure.
	 * @param int    $max_attempts Attempt ceiling.
	 * @return void
	 */
	public static function mark_attempt_failed( $id, $error, $retryable, $attempts, $max_attempts = 3 ) {
		global $wpdb;

		$attempts = (int) $attempts + 1;
		$status   = ( $retryable && $attempts < (int) $max_attempts ) ? 'queued' : 'failed';

		$wpdb->update(
			TZ_DB::subscribers(),
			array(
				'status'     => $status,
				'attempts'   => $attempts,
				'last_error' => substr( sanitize_text_field( $error ), 0, 255 ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Requeue contacts so an existing list can be mailed again.
	 *
	 * Suppressed contacts (bounced, complaint, unsubscribed) are never
	 * requeued: re-mailing them is exactly what destroys a sender reputation.
	 *
	 * @param bool $include_failed Also retry rows that previously errored.
	 * @return int Rows requeued.
	 */
	public static function requeue_all( $include_failed = true ) {
		global $wpdb;

		$table    = TZ_DB::subscribers();
		$statuses = $include_failed ? array( 'sent', 'delivered', 'failed' ) : array( 'sent', 'delivered' );

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET status = 'queued', attempts = 0, last_error = '' WHERE status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$statuses
		);

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Ensure every row has an unsubscribe token.
	 *
	 * Defensive repair for lists imported by an older build or by direct SQL.
	 *
	 * @return int Rows repaired.
	 */
	public static function backfill_tokens() {
		global $wpdb;

		$table = TZ_DB::subscribers();

		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE unsub_token = '' OR unsub_token IS NULL LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $ids ) ) {
			return 0;
		}

		$fixed = 0;

		foreach ( $ids as $id ) {
			$updated = $wpdb->update(
				$table,
				array( 'unsub_token' => TZ_DB::generate_token() ),
				array( 'id' => (int) $id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( $updated ) {
				$fixed++;
			}
		}

		return $fixed;
	}
}
