<?php
/**
 * Dashboard screen: KPI cards, breaker status and queue controls.
 *
 * @package Techzapp_Mailer
 *
 * @var array      $metrics    Deliverability metrics.
 * @var array      $badge      Circuit breaker badge descriptor.
 * @var array|null $campaign   Active campaign row.
 * @var array      $throughput Throughput estimate.
 * @var int|false  $next_run   Next cron timestamp.
 * @var int        $total      Total subscribers.
 * @var array      $recent     Recent log rows.
 */

defined( 'ABSPATH' ) || exit;

$tz_rate      = (float) $metrics['rate'];
$tz_halted    = TZ_Circuit_Breaker::is_halted();
$tz_threshold = TZ_Circuit_Breaker::THRESHOLD;

// Colour the bounce card by proximity to the shutoff threshold.
if ( $tz_rate >= $tz_threshold ) {
	$tz_bounce_state = 'critical';
} elseif ( $metrics['processed'] >= TZ_Circuit_Breaker::MIN_SAMPLE && $tz_rate >= ( $tz_threshold / 2 ) ) {
	$tz_bounce_state = 'warning';
} else {
	$tz_bounce_state = 'success';
}

$tz_datetime = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap tz-wrap">

	<h1 class="tz-title">
		<?php esc_html_e( 'Techzapp Mailer', 'tz-mailer' ); ?>
		<span class="tz-title__version">v<?php echo esc_html( TZ_MAILER_VERSION ); ?></span>
	</h1>

	<p class="tz-subtitle">
		<?php esc_html_e( 'Throttled bulk delivery through the AWS SES v2 API with automatic bounce protection.', 'tz-mailer' ); ?>
	</p>

	<!-- Circuit breaker status strip -->
	<div class="tz-status-strip tz-status-strip--<?php echo esc_attr( $badge['state'] ); ?>">
		<div class="tz-status-strip__main">
			<span class="tz-status-strip__label"><?php esc_html_e( 'Circuit Breaker', 'tz-mailer' ); ?></span>
			<?php TZ_Admin::badge( $badge['label'], $badge['state'] ); ?>
		</div>
		<div class="tz-status-strip__detail">
			<?php
			if ( $tz_halted ) {
				esc_html_e( 'Sending is stopped. Clear the breaker in Settings once the list has been cleaned.', 'tz-mailer' );
			} else {
				printf(
					/* translators: 1: current bounce rate, 2: threshold */
					esc_html__( 'Bounce rate %1$s%% of a %2$s%% hard limit. Sending halts automatically at the limit once %3$s contacts have been processed.', 'tz-mailer' ),
					esc_html( number_format_i18n( $tz_rate, 2 ) ),
					esc_html( number_format_i18n( $tz_threshold, 1 ) ),
					esc_html( number_format_i18n( TZ_Circuit_Breaker::MIN_SAMPLE ) )
				);
			}
			?>
		</div>
	</div>

	<!-- KPI cards -->
	<div class="tz-cards">
		<?php
		TZ_Admin::stat_card(
			array(
				'label' => __( 'Pending Queue', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['queued'] ),
				'sub'   => TZ_Queue::drain_estimate( $metrics['queued'] ),
				'state' => $metrics['queued'] > 0 ? 'info' : 'neutral',
			)
		);

		TZ_Admin::stat_card(
			array(
				'label' => __( 'Sent / Delivered', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['sent'] + $metrics['delivered'] ),
				'sub'   => sprintf(
					/* translators: 1: sent count, 2: delivery-confirmed count */
					__( '%1$s accepted by SES, %2$s confirmed delivered', 'tz-mailer' ),
					number_format_i18n( $metrics['sent'] ),
					number_format_i18n( $metrics['delivered'] )
				),
				'state' => 'success',
			)
		);

		TZ_Admin::stat_card(
			array(
				'label' => __( 'Hard Bounces', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['bounced'] ),
				'sub'   => sprintf(
					/* translators: 1: bounce rate, 2: threshold */
					__( '%1$s%% bounce rate (limit %2$s%%)', 'tz-mailer' ),
					number_format_i18n( $tz_rate, 2 ),
					number_format_i18n( $tz_threshold, 1 )
				),
				'state' => $tz_bounce_state,
				'href'  => TZ_Admin::url( '-logs', array( 'event_type' => 'Hard_Bounce' ) ),
			)
		);

		TZ_Admin::stat_card(
			array(
				'label' => __( 'Spam Complaints', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['complaint'] ),
				'sub'   => __( 'Suppressed permanently on receipt', 'tz-mailer' ),
				'state' => $metrics['complaint'] > 0 ? 'critical' : 'neutral',
				'href'  => TZ_Admin::url( '-logs', array( 'event_type' => 'Spam_Complaint' ) ),
			)
		);

		TZ_Admin::stat_card(
			array(
				'label' => __( 'Unsubscribes', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['unsubscribed'] ),
				'sub'   => __( 'One-click opt-outs honoured', 'tz-mailer' ),
				'state' => 'info',
				'href'  => TZ_Admin::url( '-logs', array( 'event_type' => 'Unsubscribed' ) ),
			)
		);

		TZ_Admin::stat_card(
			array(
				'label' => __( 'Send Failures', 'tz-mailer' ),
				'value' => number_format_i18n( $metrics['failed'] ),
				'sub'   => __( 'Rejected by the SES API', 'tz-mailer' ),
				'state' => $metrics['failed'] > 0 ? 'warning' : 'neutral',
				'href'  => TZ_Admin::url( '-logs', array( 'event_type' => 'API_Error' ) ),
			)
		);
		?>
	</div>

	<div class="tz-columns">

		<!-- Sending status -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Sending Status', 'tz-mailer' ); ?></h2>

			<table class="tz-kv">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Active campaign', 'tz-mailer' ); ?></th>
						<td>
							<?php if ( $campaign ) : ?>
								<strong><?php echo esc_html( $campaign['subject'] ); ?></strong>
								<?php TZ_Admin::badge( __( 'Live', 'tz-mailer' ), 'success' ); ?>
							<?php else : ?>
								<em><?php esc_html_e( 'None. Activate a campaign to begin sending.', 'tz-mailer' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Throttle', 'tz-mailer' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: batch size, 2: delay seconds, 3: per hour, 4: per day */
								esc_html__( '%1$s emails every 5 minutes with a %2$s second pause between sends. Roughly %3$s per hour, %4$s per day.', 'tz-mailer' ),
								esc_html( number_format_i18n( $throughput['batch'] ) ),
								esc_html( number_format_i18n( $throughput['delay'] ) ),
								esc_html( number_format_i18n( $throughput['per_hour'] ) ),
								esc_html( number_format_i18n( $throughput['per_day'] ) )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next scheduled run', 'tz-mailer' ); ?></th>
						<td>
							<?php
							if ( $next_run ) {
								printf(
									/* translators: 1: formatted date, 2: relative time */
									esc_html__( '%1$s (in %2$s)', 'tz-mailer' ),
									esc_html( wp_date( $tz_datetime, $next_run ) ),
									esc_html( human_time_diff( time(), $next_run ) )
								);
							} else {
								esc_html_e( 'Not scheduled. Deactivate and reactivate the plugin to restore the cron event.', 'tz-mailer' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AWS SES', 'tz-mailer' ); ?></th>
						<td>
							<?php if ( TZ_Settings::is_configured() ) : ?>
								<?php TZ_Admin::badge( __( 'Configured', 'tz-mailer' ), 'success' ); ?>
								<code><?php echo esc_html( TZ_Settings::get( 'aws_region' ) ); ?></code>
								<?php echo esc_html( TZ_Settings::get( 'from_email' ) ); ?>
							<?php else : ?>
								<?php TZ_Admin::badge( __( 'Not configured', 'tz-mailer' ), 'critical' ); ?>
								<a href="<?php echo esc_url( TZ_Admin::url( '-settings' ) ); ?>"><?php esc_html_e( 'Add credentials', 'tz-mailer' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total contacts', 'tz-mailer' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<div class="tz-panel__actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'tz_run_queue' ); ?>
					<input type="hidden" name="action" value="tz_run_queue" />
					<button type="submit" class="button button-primary" <?php disabled( $tz_halted || ! $campaign ); ?>>
						<?php esc_html_e( 'Run one batch now', 'tz-mailer' ); ?>
					</button>
					<span class="description">
						<?php esc_html_e( 'Processes a single micro-batch immediately instead of waiting for cron.', 'tz-mailer' ); ?>
					</span>
				</form>
			</div>
		</div>

		<!-- Requeue -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Requeue Contacts', 'tz-mailer' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Returns already-sent contacts to the queue so a new campaign can go out to the same list. Bounced, complained and unsubscribed addresses are always left suppressed and are never requeued.', 'tz-mailer' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'tz_requeue' ); ?>
				<input type="hidden" name="action" value="tz_requeue" />

				<p>
					<label>
						<input type="checkbox" name="tz_include_failed" value="1" checked="checked" />
						<?php esc_html_e( 'Also retry contacts whose send previously errored', 'tz-mailer' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="tz_requeue_confirm" value="1" required="required" />
						<strong><?php esc_html_e( 'I understand this will send to these contacts again.', 'tz-mailer' ); ?></strong>
					</label>
				</p>

				<button type="submit" class="button"><?php esc_html_e( 'Requeue contacts', 'tz-mailer' ); ?></button>
			</form>
		</div>

	</div>

	<!-- Recent activity -->
	<div class="tz-panel">
		<h2 class="tz-panel__title">
			<?php esc_html_e( 'Recent Activity', 'tz-mailer' ); ?>
			<a class="tz-panel__more" href="<?php echo esc_url( TZ_Admin::url( '-logs' ) ); ?>">
				<?php esc_html_e( 'View full delivery log', 'tz-mailer' ); ?>
			</a>
		</h2>

		<?php if ( empty( $recent ) ) : ?>
			<p class="tz-empty"><?php esc_html_e( 'No delivery events recorded yet.', 'tz-mailer' ); ?></p>
		<?php else : ?>
			<table class="widefat striped tz-table">
				<thead>
					<tr>
						<th scope="col" class="tz-col-time"><?php esc_html_e( 'Time', 'tz-mailer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recipient', 'tz-mailer' ); ?></th>
						<th scope="col" class="tz-col-event"><?php esc_html_e( 'Event', 'tz-mailer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Detail', 'tz-mailer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$tz_labels = TZ_Logger::event_types();

					foreach ( $recent as $tz_row ) :
						$tz_event = $tz_row['event_type'];
						$tz_label = isset( $tz_labels[ $tz_event ] ) ? $tz_labels[ $tz_event ] : $tz_event;
						?>
						<tr>
							<td class="tz-col-time">
								<?php echo esc_html( mysql2date( $tz_datetime, $tz_row['logged_at'] ) ); ?>
							</td>
							<td>
								<?php echo '' !== $tz_row['email'] ? esc_html( $tz_row['email'] ) : '<span class="tz-muted">' . esc_html__( 'System', 'tz-mailer' ) . '</span>'; ?>
							</td>
							<td class="tz-col-event">
								<?php TZ_Admin::badge( $tz_label, TZ_Logger::badge_class( $tz_event ) ); ?>
							</td>
							<td class="tz-detail">
								<?php echo esc_html( wp_trim_words( (string) $tz_row['details'], 22 ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Compliance reminder -->
	<div class="tz-panel tz-panel--compliance">
		<h2 class="tz-panel__title"><?php esc_html_e( 'Bulk Sender Compliance', 'tz-mailer' ); ?></h2>
		<ul class="tz-checklist">
			<li><?php esc_html_e( 'Every campaign must contain the {unsubscribe_url} merge tag. The plugin refuses to send without it.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Every campaign must show a physical mailing address in the footer (CAN-SPAM requirement).', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Your sending domain needs SPF, DKIM and a DMARC policy in DNS. Gmail and Yahoo have required all three from bulk senders since February 2024.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Keep spam complaints below 0.10%. Gmail treats 0.30% as a hard failure.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Only mail contacts who opted in. Purchased and scraped lists are what drive the bounce rate into the circuit breaker.', 'tz-mailer' ); ?></li>
		</ul>
	</div>

</div>
