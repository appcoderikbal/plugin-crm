<?php
/**
 * Activation, deactivation and schema management.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the three custom tables and manages the cron registration.
 */
class TZ_Activator {

	/**
	 * Fired by register_activation_hook().
	 *
	 * Creates tables, seeds default settings and schedules the queue worker.
	 * Multisite-aware: when network activated, every existing site gets its
	 * own set of tables.
	 *
	 * @param bool $network_wide True when the plugin was network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_single_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_single_site();
	}

	/**
	 * Per-site activation routine.
	 *
	 * @return void
	 */
	private static function activate_single_site() {
		self::create_tables();

		// Seed defaults without clobbering an existing configuration.
		if ( false === get_option( TZ_MAILER_OPT_SETTINGS, false ) ) {
			add_option( TZ_MAILER_OPT_SETTINGS, TZ_Settings::defaults(), '', 'yes' );
		}

		// The breaker starts open (i.e. sending is allowed).
		add_option( TZ_MAILER_OPT_BREAKER, 0, '', 'yes' );
		add_option( TZ_MAILER_OPT_BREAKER_META, array(), '', 'yes' );

		update_option( TZ_MAILER_OPT_DB_VERSION, TZ_MAILER_DB_VERSION );

		self::schedule_cron();

		TZ_Logger::log( '', 'System', 'Techzapp Mailer activated (v' . TZ_MAILER_VERSION . ').' );
	}

	/**
	 * Fired by register_deactivation_hook().
	 *
	 * Only unschedules cron. Tables and data survive deactivation so an
	 * accidental toggle never destroys a subscriber list; real cleanup lives in
	 * uninstall.php behind an explicit opt-in setting.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_cron();
	}

	/**
	 * Register the recurring queue worker event.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( TZ_MAILER_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, TZ_MAILER_CRON_SCHEDULE, TZ_MAILER_CRON_HOOK );
		}
	}

	/**
	 * Clear every scheduled queue worker event.
	 *
	 * @return void
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( TZ_MAILER_CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, TZ_MAILER_CRON_HOOK );
			$timestamp = wp_next_scheduled( TZ_MAILER_CRON_HOOK );
		}
	}

	/**
	 * Re-run dbDelta when the stored schema version falls behind the code.
	 *
	 * Also self-heals the cron registration, which some hosts drop when the
	 * cron array is flushed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( TZ_MAILER_OPT_DB_VERSION, '0' );

		if ( version_compare( $installed, TZ_MAILER_DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( TZ_MAILER_OPT_DB_VERSION, TZ_MAILER_DB_VERSION );
		}

		self::schedule_cron();
	}

	/**
	 * Create or migrate the three custom tables via dbDelta().
	 *
	 * dbDelta is fussy: keys must be on their own lines, the PRIMARY KEY needs
	 * two spaces before the parenthesis, and field types must be lowercase.
	 * The formatting below is deliberate - do not "tidy" it.
	 *
	 * Note on column widths: email is varchar(191) so a UNIQUE index still fits
	 * inside the 767 byte InnoDB prefix limit on utf8mb4 installs.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$subscribers = TZ_DB::subscribers();
		$logs        = TZ_DB::logs();
		$campaigns   = TZ_DB::campaigns();

		/*
		 * Subscribers.
		 *
		 * campaign_id / attempts / last_error / sent_at are operational columns
		 * the queue worker needs in order to retry transient SES failures
		 * without ever re-sending a message that already left the building.
		 */
		$sql_subscribers = "CREATE TABLE {$subscribers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'queued',
			unsub_token varchar(64) NOT NULL,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY unsub_token (unsub_token),
			KEY status (status),
			KEY status_attempts (status,attempts),
			KEY created_at (created_at)
		) {$charset_collate};";

		/*
		 * Delivery logs: append-only audit trail for every SES and SNS event.
		 */
		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL DEFAULT '',
			event_type varchar(40) NOT NULL DEFAULT '',
			details text NULL,
			logged_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY email (email),
			KEY event_type (event_type),
			KEY logged_at (logged_at)
		) {$charset_collate};";

		/*
		 * Campaigns. Exactly one row may carry is_active = 1 at a time; the
		 * queue worker sends whatever that row holds.
		 */
		$sql_campaigns = "CREATE TABLE {$campaigns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject varchar(255) NOT NULL DEFAULT '',
			body_html longtext NULL,
			is_active tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) {$charset_collate};";

		dbDelta( $sql_subscribers );
		dbDelta( $sql_logs );
		dbDelta( $sql_campaigns );
	}
}
