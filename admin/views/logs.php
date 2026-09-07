<?php
/**
 * Delivery log screen: searchable, filterable, paginated audit table.
 *
 * @package Techzapp_Mailer
 *
 * @var array  $rows       Log rows for this page.
 * @var string $search     Active search term.
 * @var string $event_type Active event filter.
 * @var int    $paged      Current page number.
 * @var int    $pages      Total pages.
 * @var int    $total      Total matching rows.
 * @var int    $per_page   Rows per page.
 */

defined( 'ABSPATH' ) || exit;

$tz_labels = TZ_Logger::event_types();
$tz_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$tz_base   = TZ_Admin::url( '-logs' );
?>
<div class="wrap tz-wrap">

	<h1 class="tz-title"><?php esc_html_e( 'Delivery Logs', 'tz-mailer' ); ?></h1>

	<p class="tz-subtitle">
		<?php esc_html_e( 'Every send attempt, bounce, complaint, delivery confirmation and opt-out, with the diagnostic message returned by the receiving server.', 'tz-mailer' ); ?>
	</p>

	<!-- Filters -->
	<form method="get" class="tz-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( TZ_Admin::SLUG . '-logs' ); ?>" />

		<label class="screen-reader-text" for="tz-log-search"><?php esc_html_e( 'Search logs', 'tz-mailer' ); ?></label>
		<input type="search"
			id="tz-log-search"
			name="s"
			value="<?php echo esc_attr( $search ); ?>"
			placeholder="<?php esc_attr_e( 'Search email or diagnostic text', 'tz-mailer' ); ?>"
			class="tz-filters__search" />

		<label class="screen-reader-text" for="tz-log-event"><?php esc_html_e( 'Filter by event type', 'tz-mailer' ); ?></label>
		<select name="event_type" id="tz-log-event">
			<option value=""><?php esc_html_e( 'All event types', 'tz-mailer' ); ?></option>
			<?php foreach ( $tz_labels as $tz_key => $tz_label ) : ?>
				<option value="<?php echo esc_attr( $tz_key ); ?>" <?php selected( $event_type, $tz_key ); ?>>
					<?php echo esc_html( $tz_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'tz-mailer' ); ?></button>

		<?php if ( '' !== $search || '' !== $event_type ) : ?>
			<a class="button button-link" href="<?php echo esc_url( $tz_base ); ?>">
				<?php esc_html_e( 'Clear', 'tz-mailer' ); ?>
			</a>
		<?php endif; ?>

		<span class="tz-filters__count">
			<?php
			printf(
				/* translators: %s: number of log entries */
				esc_html( _n( '%s entry', '%s entries', $total, 'tz-mailer' ) ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</form>

	<!-- Log table -->
	<table class="widefat striped tz-table tz-table--logs">
		<thead>
			<tr>
				<th scope="col" class="tz-col-time"><?php esc_html_e( 'Timestamp', 'tz-mailer' ); ?></th>
				<th scope="col" class="tz-col-email"><?php esc_html_e( 'Recipient', 'tz-mailer' ); ?></th>
				<th scope="col" class="tz-col-event"><?php esc_html_e( 'Event Type', 'tz-mailer' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Server Diagnostic Message', 'tz-mailer' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="4" class="tz-empty">
						<?php
						if ( '' !== $search || '' !== $event_type ) {
							esc_html_e( 'No log entries match those filters.', 'tz-mailer' );
						} else {
							esc_html_e( 'No delivery events recorded yet. Entries appear here as soon as the queue worker sends its first batch.', 'tz-mailer' );
						}
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $rows as $tz_row ) :
					$tz_event = $tz_row['event_type'];
					$tz_label = isset( $tz_labels[ $tz_event ] ) ? $tz_labels[ $tz_event ] : $tz_event;
					?>
					<tr>
						<td class="tz-col-time">
							<?php echo esc_html( mysql2date( $tz_format, $tz_row['logged_at'] ) ); ?>
						</td>
						<td class="tz-col-email">
							<?php if ( '' !== $tz_row['email'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $tz_row['email'] ), $tz_base ) ); ?>">
									<?php echo esc_html( $tz_row['email'] ); ?>
								</a>
							<?php else : ?>
								<span class="tz-muted"><?php esc_html_e( 'System', 'tz-mailer' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="tz-col-event">
							<?php TZ_Admin::badge( $tz_label, TZ_Logger::badge_class( $tz_event ) ); ?>
						</td>
						<td class="tz-detail">
							<?php echo esc_html( (string) $tz_row['details'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Pagination -->
	<?php if ( $pages > 1 ) : ?>
		<div class="tz-pagination tablenav">
			<div class="tablenav-pages">
				<?php
				$tz_args = array();

				if ( '' !== $search ) {
					$tz_args['s'] = $search;
				}

				if ( '' !== $event_type ) {
					$tz_args['event_type'] = $event_type;
				}

				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( array_merge( $tz_args, array( 'paged' => '%#%' ) ), $tz_base ),
							'format'    => '',
							'prev_text' => __( '&laquo; Previous', 'tz-mailer' ),
							'next_text' => __( 'Next &raquo;', 'tz-mailer' ),
							'total'     => $pages,
							'current'   => $paged,
							'end_size'  => 1,
							'mid_size'  => 2,
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<!-- Legend -->
	<div class="tz-panel">
		<h2 class="tz-panel__title"><?php esc_html_e( 'Event Reference', 'tz-mailer' ); ?></h2>

		<?php
		$tz_legend = array(
			'Sent_Success'         => __( 'The SES API accepted the message. This is not yet proof of delivery.', 'tz-mailer' ),
			'Delivery_Confirmed'   => __( 'The receiving mail server accepted the message. Reported over SNS.', 'tz-mailer' ),
			'Hard_Bounce'          => __( 'Permanent failure: the mailbox does not exist or refuses all mail. The address is suppressed and counts toward the circuit breaker.', 'tz-mailer' ),
			'Soft_Bounce'          => __( 'Transient failure such as a full mailbox. The address stays on the list and is not counted against the bounce rate.', 'tz-mailer' ),
			'Spam_Complaint'       => __( 'The recipient pressed report spam. The address is suppressed permanently. This is the most damaging signal to sender reputation.', 'tz-mailer' ),
			'API_Error'            => __( 'The SES API rejected the request. The diagnostic column carries the AWS exception type.', 'tz-mailer' ),
			'Circuit_Breaker_Halt' => __( 'The bounce rate reached the hard limit and all sending was stopped automatically.', 'tz-mailer' ),
			'Unsubscribed'         => __( 'The recipient opted out through the one-click unsubscribe link.', 'tz-mailer' ),
			'System'               => __( 'Plugin lifecycle events: imports, activations, breaker resets.', 'tz-mailer' ),
		);
		?>

		<table class="widefat striped tz-table">
			<tbody>
				<?php foreach ( $tz_legend as $tz_key => $tz_desc ) : ?>
					<tr>
						<td class="tz-col-event">
							<?php TZ_Admin::badge( $tz_labels[ $tz_key ], TZ_Logger::badge_class( $tz_key ) ); ?>
						</td>
						<td class="tz-detail"><?php echo esc_html( $tz_desc ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</div>
