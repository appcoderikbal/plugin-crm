<?php
/**
 * Campaign CRUD.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create, update and activate rows in tz_campaigns.
 */
class TZ_Campaigns {

	/**
	 * Insert a new campaign.
	 *
	 * @param string $subject Subject line (may contain merge tags).
	 * @param string $body    HTML body from wp_editor().
	 * @return int New campaign ID, or 0 on failure.
	 */
	public static function create( $subject, $body ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			TZ_DB::campaigns(),
			array(
				'subject'    => self::sanitize_subject( $subject ),
				'body_html'  => self::sanitize_body( $body ),
				'is_active'  => 0,
				'created_at' => TZ_DB::now(),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an existing campaign.
	 *
	 * @param int    $id      Campaign ID.
	 * @param string $subject Subject line.
	 * @param string $body    HTML body.
	 * @return bool
	 */
	public static function update( $id, $subject, $body ) {
		global $wpdb;

		$updated = $wpdb->update(
			TZ_DB::campaigns(),
			array(
				'subject'   => self::sanitize_subject( $subject ),
				'body_html' => self::sanitize_body( $body ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return ( false !== $updated );
	}

	/**
	 * Fetch one campaign.
	 *
	 * @param int $id Campaign ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = TZ_DB::campaigns();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The campaign the queue worker will send.
	 *
	 * @return array|null
	 */
	public static function get_active() {
		global $wpdb;

		$table = TZ_DB::campaigns();

		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * All campaigns, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array>
	 */
	public static function all( $limit = 50 ) {
		global $wpdb;

		$table = TZ_DB::campaigns();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, subject, is_active, created_at FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Make one campaign the active one, deactivating every other row.
	 *
	 * Exactly one campaign may be active at a time: the worker has no concept
	 * of which contact belongs to which campaign, so two active campaigns
	 * would interleave unpredictably.
	 *
	 * @param int $id Campaign ID to activate.
	 * @return bool
	 */
	public static function activate( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( ! self::get( $id ) ) {
			return false;
		}

		$table = TZ_DB::campaigns();

		$wpdb->query( "UPDATE {$table} SET is_active = 0 WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->update(
			$table,
			array( 'is_active' => 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		TZ_Logger::log( '', 'System', 'Campaign #' . $id . ' activated for sending.' );

		return true;
	}

	/**
	 * Pause sending by deactivating every campaign.
	 *
	 * @return void
	 */
	public static function deactivate_all() {
		global $wpdb;

		$table = TZ_DB::campaigns();

		$wpdb->query( "UPDATE {$table} SET is_active = 0 WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		TZ_Logger::log( '', 'System', 'All campaigns deactivated. Queue sending paused.' );
	}

	/**
	 * Delete a campaign.
	 *
	 * @param int $id Campaign ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( TZ_DB::campaigns(), array( 'id' => (int) $id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/**
	 * Starter HTML for a brand new campaign.
	 *
	 * Ships with the two things a bulk message legally cannot go out without:
	 * the unsubscribe link and a physical mailing address. Pre-filling them is
	 * far more reliable than hoping the operator remembers.
	 *
	 * @return string
	 */
	public static function starter_html() {
		$company = get_bloginfo( 'name' );

		$html  = '<p>Hi {name},</p>' . "
";
		$html .= '<p>Write your message here.</p>' . "
";
		$html .= '<p>Best regards,<br />The ' . esc_html( $company ) . ' Team</p>' . "
";
		$html .= '<hr />' . "
";
		$html .= '<p style="font-size:12px;color:#666666;">' . "
";
		$html .= 'You are receiving this email at {email} because you subscribed to updates from ' . esc_html( $company ) . '.<br />' . "
";
		$html .= '<a href="{unsubscribe_url}">Unsubscribe instantly</a>' . "
";
		$html .= '</p>' . "
";
		$html .= '<p style="font-size:12px;color:#666666;">' . "
";
		$html .= '<!-- CAN-SPAM requires a real, physical postal address. Replace this line. -->' . "
";
		$html .= 'Techzapp, 123 Example Street, Suite 100, Your City, ST 00000, USA' . "
";
		$html .= '</p>' . "
";

		return $html;
	}

	/**
	 * Sanitize a subject line.
	 *
	 * @param string $subject Raw input.
	 * @return string
	 */
	private static function sanitize_subject( $subject ) {
		$subject = sanitize_text_field( (string) $subject );

		return substr( $subject, 0, 255 );
	}

	/**
	 * Sanitize a campaign body.
	 *
	 * wp_kses_post() is intentionally NOT used: email HTML legitimately needs
	 * table layout attributes and inline styles that the post allowlist strips,
	 * and only administrators (manage_options) can reach the composer. The
	 * capability check is the security boundary here.
	 *
	 * @param string $body Raw editor output.
	 * @return string
	 */
	private static function sanitize_body( $body ) {
		// wp_unslash is handled by the caller; strip only NUL bytes.
		return str_replace( chr( 0 ), '', (string) $body );
	}
}
