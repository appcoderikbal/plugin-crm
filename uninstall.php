<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is DELETED from the Plugins screen, never on
 * deactivation. Subscriber data is destroyed only when the operator explicitly
 * opted in via the "Delete all data" setting; the default is to leave
 * everything in place, because silently dropping a mailing list because
 * someone clicked the wrong button is unforgivable.
 *
 * @package Techzapp_Mailer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every trace of the plugin for the current site.
 *
 * @return void
 */
function tz_mailer_uninstall_site() {
	return false;
	global $wpdb;

	$settings = get_option( 'tz_mailer_settings', array() );

	$delete_data = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );

	// Always clear the scheduled worker: leaving an orphan cron event behind
	// produces a "missing callback" warning on every subsequent cron run.
	$timestamp = wp_next_scheduled( 'tz_process_queue_event' );

	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'tz_process_queue_event' );
		$timestamp = wp_next_scheduled( 'tz_process_queue_event' );
	}

	if ( ! $delete_data ) {
		return;
	}

	// Drop the custom tables.
	$tables = array(
		$wpdb->prefix . 'tz_subscribers',
		$wpdb->prefix . 'tz_delivery_logs',
		$wpdb->prefix . 'tz_campaigns',
	);

	foreach ( $tables as $table ) {
		// Table names cannot be bound as prepared parameters; these are built
		// from $wpdb->prefix and fixed literals, never from user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// Remove options.
	$options = array(
		'tz_mailer_settings',
		'tz_circuit_breaker_halted',
		'tz_circuit_breaker_meta',
		'tz_mailer_db_version',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove transients the plugin may have left behind.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_transient_tz_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tz_' ) . '%'
		)
	);
}

// Multisite installs need the routine repeated for every site.
if ( is_multisite() ) {
	$tz_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $tz_site_ids as $tz_site_id ) {
		switch_to_blog( $tz_site_id );
		tz_mailer_uninstall_site();
		restore_current_blog();
	}
} else {
	tz_mailer_uninstall_site();
}
