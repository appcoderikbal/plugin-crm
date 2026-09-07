<?php
/**
 * Throttled WP-Cron queue worker.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drains the subscriber queue in small, rate-limited batches.
 *
 * The design goal is "never surprise AWS". A cold domain that suddenly emits
 * thousands of messages an hour gets throttled or blocklisted regardless of
 * authentication, so the worker sends a small batch every five minutes with a
 * deliberate pause between individual messages.
 */
class TZ_Queue {

	/** Transient key guarding against overlapping cron runs. */
	const LOCK_KEY = 'tz_queue_lock';

	/** How long the lock survives if a run dies mid-batch, in seconds. */
	const LOCK_TTL = 600;

	/** Attempts allowed per contact before it is abandoned. */
	const MAX_ATTEMPTS = 3;

	/**
	 * Safety margin: stop the batch this many seconds before PHP would be
	 * killed by max_execution_time.
	 */
	const TIME_MARGIN = 15;

	/**
	 * Register the five minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_cron_schedule( $schedules ) {
		$schedules[ TZ_MAILER_CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Techzapp Mailer)', 'tz-mailer' ),
		);

		return $schedules;
	}

	/**
	 * Cron callback: process one micro-batch.
	 *
	 * @return array Run report (also returned for manual "Send now" triggers).
	 */
	public function run() {
		$report = array(
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'halted'    => false,
			'reason'    => '',
		);

		// 1. The circuit breaker outranks everything else.
		if ( TZ_Circuit_Breaker::is_halted() ) {
			$report['halted'] = true;
			$report['reason'] = __( 'Circuit breaker is halted. Sending is disabled until it is manually reset in Settings.', 'tz-mailer' );

			return $report;
		}

		// 2. Refuse to start without working credentials.
		if ( ! TZ_Settings::is_configured() ) {
			$report['reason'] = __( 'AWS SES credentials are incomplete. Configure them in Settings.', 'tz-mailer' );

			return $report;
		}

		// 3. Only one worker at a time. add_option() is atomic on a proper
		// object cache and good enough on the DB backend; a stale lock expires
		// on its own after LOCK_TTL.
		if ( get_transient( self::LOCK_KEY ) ) {
			$report['reason'] = __( 'Another queue run is already in progress.', 'tz-mailer' );

			return $report;
		}

		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		$this->maybe_prune_logs();

		try {
			$report = $this->process_batch( $report );
		} catch ( Exception $e ) {
			// A worker crash must never leave the lock held.
			TZ_Logger::log( '', 'API_Error', 'Queue worker exception: ' . $e->getMessage() );
			$report['reason'] = $e->getMessage();
		}

		delete_transient( self::LOCK_KEY );

		return $report;
	}

	/**
	 * Trim the audit log to the configured retention window, once per day.
	 *
	 * Runs inside the queue worker rather than on its own schedule so there is
	 * only one recurring event to keep alive, and guarded by a transient so a
	 * DELETE never runs twelve times an hour.
	 *
	 * @return void
	 */
	private function maybe_prune_logs() {
		$days = (int) TZ_Settings::get( 'log_retention', 90 );

		if ( $days < 1 ) {
			return;
		}

		if ( get_transient( 'tz_logs_pruned' ) ) {
			return;
		}

		set_transient( 'tz_logs_pruned', 1, DAY_IN_SECONDS );

		$removed = TZ_Logger::prune( $days );

		if ( $removed > 0 ) {
			TZ_Logger::log( '', 'System', sprintf( 'Pruned %d delivery log entries older than %d days.', $removed, $days ) );
		}
	}

	/**
	 * Send one micro-batch.
	 *
	 * @param array $report Running report.
	 * @return array Updated report.
	 */
	private function process_batch( array $report ) {
		$campaign = TZ_Campaigns::get_active();

		if ( ! $campaign ) {
			$report['reason'] = __( 'No active campaign. Activate one in the Campaign Composer to start sending.', 'tz-mailer' );

			return $report;
		}

		if ( ! TZ_Shortcodes::has_unsubscribe_tag( $campaign['body_html'] ) ) {
			// Refusing to send is the correct behaviour: a bulk message with no
			// unsubscribe link is a CAN-SPAM violation and a Gmail bulk sender
			// policy violation.
			TZ_Campaigns::deactivate_all();

			$reason = __( 'Active campaign is missing the {unsubscribe_url} merge tag. Sending stopped and the campaign was deactivated.', 'tz-mailer' );

			TZ_Logger::log( '', 'System', $reason );

			$report['reason'] = $reason;

			return $report;
		}

		$batch_size = (int) TZ_Settings::get( 'batch_size', 15 );
		$delay      = (int) TZ_Settings::get( 'send_delay', 3 );

		$queue = TZ_Subscribers::claim_batch( $batch_size, self::MAX_ATTEMPTS );

		if ( empty( $queue ) ) {
			$report['reason'] = __( 'Queue is empty. Nothing to send.', 'tz-mailer' );

			return $report;
		}

		$started    = microtime( true );
		$deadline   = $this->deadline();
		$last_index = count( $queue ) - 1;

		foreach ( $queue as $index => $subscriber ) {
			/*
			 * Re-evaluate the breaker before EVERY message, not once per batch.
			 * Bounce notifications arrive asynchronously over SNS, so the rate
			 * can cross the threshold in the middle of a batch; checking here
			 * means the halt lands on the very next message.
			 */
			if ( TZ_Circuit_Breaker::check_and_maybe_trip( 'queue_worker' ) ) {
				$report['halted'] = true;
				$report['reason'] = __( 'Circuit breaker tripped mid-batch. Remaining messages were not sent.', 'tz-mailer' );
				break;
			}

			// Bail out before PHP is killed, leaving the rest queued for the
			// next tick. Partial progress is already committed per message.
			if ( microtime( true ) > $deadline ) {
				$report['reason'] = __( 'Batch stopped early to stay inside the PHP execution time limit.', 'tz-mailer' );
				break;
			}

			$outcome = $this->send_one( $subscriber, $campaign );

			$report['processed']++;

			if ( 'sent' === $outcome['result'] ) {
				$report['sent']++;
			} elseif ( 'skipped' === $outcome['result'] ) {
				$report['skipped']++;
			} else {
				$report['failed']++;
			}

			// An account-level failure will repeat identically for every
			// remaining message, so stop and let an operator intervene.
			if ( ! empty( $outcome['fatal'] ) ) {
				$report['reason'] = __( 'Sending stopped: AWS rejected the account or credentials. Check Settings and the delivery log.', 'tz-mailer' );
				break;
			}

			// Deliberate pacing between individual sends. Skipped after the
			// final message so the worker does not idle for no reason.
			if ( $delay > 0 && $index < $last_index ) {
				sleep( $delay );
			}
		}

		$report['duration'] = round( microtime( true ) - $started, 2 );

		return $report;
	}

	/**
	 * Render and send one message.
	 *
	 * @param array $subscriber Row from claim_batch().
	 * @param array $campaign   Active campaign row.
	 * @return array{result:string,fatal:bool}
	 */
	private function send_one( array $subscriber, array $campaign ) {
		// A contact can be suppressed by an SNS webhook between being claimed
		// and being sent. Re-read the live status before spending an API call.
		$live = TZ_DB::get_subscriber_by_email( $subscriber['email'] );

		if ( ! $live || 'queued' !== $live['status'] ) {
			return array(
				'result' => 'skipped',
				'fatal'  => false,
			);
		}

		// Repair a missing token rather than mailing a broken unsubscribe link.
		if ( empty( $subscriber['unsub_token'] ) ) {
			TZ_Subscribers::backfill_tokens();
			$live = TZ_DB::get_subscriber_by_email( $subscriber['email'] );

			if ( ! $live || empty( $live['unsub_token'] ) ) {
				TZ_Subscribers::mark_attempt_failed( $subscriber['id'], 'Missing unsubscribe token.', false, $subscriber['attempts'], self::MAX_ATTEMPTS );

				return array(
					'result' => 'failed',
					'fatal'  => false,
				);
			}

			$subscriber['unsub_token'] = $live['unsub_token'];
		}

		$context = TZ_Shortcodes::build_context( $subscriber );

		$html    = TZ_Shortcodes::render_html( $campaign['body_html'], $context );
		$subject = TZ_Shortcodes::render_text( $campaign['subject'], $context );

		$result = TZ_SES::send(
			array(
				'to'              => $subscriber['email'],
				'subject'         => $subject,
				'html'            => $html,
				'text'            => TZ_Shortcodes::html_to_text( $html ),
				'unsubscribe_url' => $context['{unsubscribe_url}'],
			)
		);

		if ( $result['success'] ) {
			TZ_Subscribers::mark_sent( $subscriber['id'], $campaign['id'] );

			TZ_Logger::log(
				$subscriber['email'],
				'Sent_Success',
				sprintf(
					'Accepted by SES for campaign #%d. MessageId: %s',
					(int) $campaign['id'],
					$result['message_id']
				)
			);

			return array(
				'result' => 'sent',
				'fatal'  => false,
			);
		}

		TZ_Subscribers::mark_attempt_failed(
			$subscriber['id'],
			$result['error_code'] . ': ' . $result['error'],
			$result['retryable'],
			$subscriber['attempts'],
			self::MAX_ATTEMPTS
		);

		TZ_Logger::log(
			$subscriber['email'],
			'API_Error',
			sprintf(
				'[%s] HTTP %d. %s (attempt %d of %d, %s)',
				$result['error_code'],
				$result['status'],
				$result['error'],
				(int) $subscriber['attempts'] + 1,
				self::MAX_ATTEMPTS,
				$result['retryable'] ? 'will retry' : 'permanent'
			)
		);

		return array(
			'result' => 'failed',
			'fatal'  => TZ_SES::is_account_level_failure( $result['error_code'] ),
		);
	}

	/**
	 * Wall-clock timestamp after which the batch must stop.
	 *
	 * Shared hosts commonly enforce a 30 second max_execution_time, and the
	 * per-message sleep plus a 20 second SES timeout can blow through that
	 * quickly. When the limit is reported as 0 (unlimited, typical for CLI
	 * cron) a five minute ceiling is applied so one run cannot overlap the
	 * next scheduled tick.
	 *
	 * @return float Unix timestamp with microseconds.
	 */
	private function deadline() {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			$limit = 5 * MINUTE_IN_SECONDS;
		}

		$budget = max( 10, $limit - self::TIME_MARGIN );

		return microtime( true ) + $budget;
	}

	/**
	 * Timestamp of the next scheduled worker run.
	 *
	 * @return int|false
	 */
	public static function next_run() {
		return wp_next_scheduled( TZ_MAILER_CRON_HOOK );
	}

	/**
	 * Estimated throughput at the current settings.
	 *
	 * @return array{per_hour:int,per_day:int,batch:int,delay:int}
	 */
	public static function throughput() {
		$batch = (int) TZ_Settings::get( 'batch_size', 15 );
		$delay = (int) TZ_Settings::get( 'send_delay', 3 );

		// Twelve five-minute ticks per hour.
		$per_hour = $batch * 12;

		return array(
			'per_hour' => $per_hour,
			'per_day'  => $per_hour * 24,
			'batch'    => $batch,
			'delay'    => $delay,
		);
	}

	/**
	 * Human readable estimate of how long the current queue will take.
	 *
	 * @param int $queued Number of contacts still queued.
	 * @return string
	 */
	public static function drain_estimate( $queued ) {
		$queued = (int) $queued;

		if ( $queued < 1 ) {
			return __( 'Queue is empty.', 'tz-mailer' );
		}

		$rate = self::throughput();

		if ( $rate['per_hour'] < 1 ) {
			return __( 'Unknown.', 'tz-mailer' );
		}

		$seconds = (int) ceil( ( $queued / $rate['per_hour'] ) * HOUR_IN_SECONDS );

		return sprintf(
			/* translators: %s: human readable time difference, e.g. "2 hours" */
			__( 'about %s at the current rate', 'tz-mailer' ),
			human_time_diff( 0, $seconds )
		);
	}

	/**
	 * Whether the lock from a previous run is still held.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return (bool) get_transient( self::LOCK_KEY );
	}

	/**
	 * Force-release a stuck lock.
	 *
	 * @return void
	 */
	public static function release_lock() {
		delete_transient( self::LOCK_KEY );
	}
}
