<?php
/**
 * Real-time bounce-rate circuit breaker.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hard 2.0% bounce-rate shutoff protecting the AWS SES account.
 *
 * AWS places a sending account under review at 5% and may suspend it at 10%.
 * Tripping at 2% leaves a wide margin to investigate before Amazon acts.
 */
class TZ_Circuit_Breaker {

	/** Bounce rate, in percent, at or above which sending halts. */
	const THRESHOLD = 2.0;

	/** Minimum processed contacts before the rate is meaningful. */
	const MIN_SAMPLE = 50;

	/**
	 * Current rolling deliverability numbers.
	 *
	 * processed = delivered + sent + bounced, matching the specified formula.
	 * A contact still sitting in the queue has no delivery outcome yet and so
	 * cannot move the rate in either direction.
	 *
	 * @return array{delivered:int,sent:int,bounced:int,complaint:int,unsubscribed:int,queued:int,failed:int,processed:int,rate:float}
	 */
	public static function metrics() {
		$counts = TZ_DB::status_counts();

		$delivered = (int) $counts['delivered'];
		$sent      = (int) $counts['sent'];
		$bounced   = (int) $counts['bounced'];

		$processed = $delivered + $sent + $bounced;
		$rate      = ( $processed > 0 ) ? ( ( $bounced / $processed ) * 100 ) : 0.0;

		return array(
			'delivered'    => $delivered,
			'sent'         => $sent,
			'bounced'      => $bounced,
			'complaint'    => (int) $counts['complaint'],
			'unsubscribed' => (int) $counts['unsubscribed'],
			'queued'       => (int) $counts['queued'],
			'failed'       => (int) $counts['failed'],
			'processed'    => $processed,
			'rate'         => round( $rate, 4 ),
		);
	}

	/**
	 * Current bounce rate as a percentage.
	 *
	 * @return float
	 */
	public static function bounce_rate() {
		$m = self::metrics();
		return (float) $m['rate'];
	}

	/**
	 * Whether the breaker is currently latched open (sending blocked).
	 *
	 * @return bool
	 */
	public static function is_halted() {
		return ( 1 === (int) get_option( TZ_MAILER_OPT_BREAKER, 0 ) );
	}

	/**
	 * Details of the trip: when, at what rate, on what sample.
	 *
	 * @return array
	 */
	public static function meta() {
		$meta = get_option( TZ_MAILER_OPT_BREAKER_META, array() );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Evaluate the live bounce rate and trip the breaker if it is at or over
	 * the threshold.
	 *
	 * Call this before every single send, not once per batch: the whole point
	 * is that the halt lands on the very next message after the threshold is
	 * crossed.
	 *
	 * @param string $trigger Short label describing what invoked the check.
	 * @return bool True when sending must stop (already halted, or just tripped).
	 */
	public static function check_and_maybe_trip( $trigger = 'queue_worker' ) {
		if ( self::is_halted() ) {
			return true;
		}

		$m = self::metrics();

		// Below the sample floor the rate is statistically meaningless: a
		// single bounce out of 3 sends is 33% and would halt a healthy account.
		if ( $m['processed'] < self::MIN_SAMPLE ) {
			return false;
		}

		if ( $m['rate'] >= self::THRESHOLD ) {
			self::trip( $m, $trigger );
			return true;
		}

		return false;
	}

	/**
	 * Latch the breaker open and write the high-priority audit entry.
	 *
	 * @param array  $metrics Metrics snapshot at the moment of the trip.
	 * @param string $trigger What invoked the check.
	 * @return void
	 */
	public static function trip( array $metrics, $trigger = 'queue_worker' ) {
		update_option( TZ_MAILER_OPT_BREAKER, 1, true );

		$meta = array(
			'tripped_at' => TZ_DB::now(),
			'rate'       => $metrics['rate'],
			'bounced'    => $metrics['bounced'],
			'processed'  => $metrics['processed'],
			'threshold'  => self::THRESHOLD,
			'trigger'    => sanitize_text_field( $trigger ),
		);

		update_option( TZ_MAILER_OPT_BREAKER_META, $meta, true );

		$details = sprintf(
			/* translators: 1: bounce rate, 2: threshold, 3: bounced count, 4: processed count, 5: trigger */
			__( 'EMERGENCY SHUTOFF. Rolling bounce rate %1$s%% reached the hard limit of %2$s%% (%3$d bounced of %4$d processed). All queued sending halted to protect the AWS SES account reputation. Trigger: %5$s.', 'tz-mailer' ),
			number_format_i18n( $metrics['rate'], 2 ),
			number_format_i18n( self::THRESHOLD, 1 ),
			$metrics['bounced'],
			$metrics['processed'],
			sanitize_text_field( $trigger )
		);

		TZ_Logger::log( '', 'Circuit_Breaker_Halt', $details );

		/**
		 * Fires immediately after the circuit breaker halts sending.
		 *
		 * Hook this to page an on-call engineer, post to Slack, etc.
		 *
		 * @param array $meta Trip metadata.
		 */
		do_action( 'tz_mailer_circuit_breaker_tripped', $meta );
	}

	/**
	 * Manually clear the breaker after an operator has investigated.
	 *
	 * Deliberately requires an explicit confirmation flag from the Settings
	 * checkbox: no code path resets this automatically.
	 *
	 * @param bool $confirmed Whether the operator ticked the confirmation box.
	 * @return bool True when the breaker was cleared.
	 */
	public static function reset( $confirmed ) {
		if ( ! $confirmed ) {
			return false;
		}

		if ( ! self::is_halted() ) {
			return false;
		}

		$previous = self::meta();

		update_option( TZ_MAILER_OPT_BREAKER, 0, true );
		update_option( TZ_MAILER_OPT_BREAKER_META, array(), true );

		$user    = wp_get_current_user();
		$who     = ( $user && $user->exists() ) ? $user->user_login : 'system';
		$was     = isset( $previous['rate'] ) ? $previous['rate'] : 'n/a';
		$details = sprintf(
			/* translators: 1: username, 2: bounce rate at trip time */
			__( 'Circuit breaker manually reset by %1$s. Rate at time of halt was %2$s%%. Sending re-enabled.', 'tz-mailer' ),
			$who,
			$was
		);

		TZ_Logger::log( '', 'System', $details );

		return true;
	}

	/**
	 * Status badge label + CSS modifier for the dashboard.
	 *
	 * @return array{label:string,state:string}
	 */
	public static function badge() {
		if ( self::is_halted() ) {
			return array(
				'label' => __( 'HALTED', 'tz-mailer' ),
				'state' => 'critical',
			);
		}

		$m = self::metrics();

		// Warn while still sending once the rate passes half the threshold.
		if ( $m['processed'] >= self::MIN_SAMPLE && $m['rate'] >= ( self::THRESHOLD / 2 ) ) {
			return array(
				'label' => __( 'ACTIVE - ELEVATED', 'tz-mailer' ),
				'state' => 'warning',
			);
		}

		return array(
			'label' => __( 'ACTIVE', 'tz-mailer' ),
			'state' => 'success',
		);
	}
}
