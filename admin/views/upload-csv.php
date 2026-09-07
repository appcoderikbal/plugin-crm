<?php
/**
 * CSV import screen.
 *
 * @package Techzapp_Mailer
 *
 * @var array $counts     Subscriber status counts.
 * @var int   $max_upload Server upload ceiling in bytes.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap tz-wrap">

	<h1 class="tz-title"><?php esc_html_e( 'Upload CSV', 'tz-mailer' ); ?></h1>

	<p class="tz-subtitle">
		<?php esc_html_e( 'Import contacts from a CSV file. Every row is validated, de-duplicated and given its own unsubscribe token before it reaches the database.', 'tz-mailer' ); ?>
	</p>

	<div class="tz-columns">

		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Choose a file', 'tz-mailer' ); ?></h2>

			<form method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				enctype="multipart/form-data">

				<?php wp_nonce_field( 'tz_upload_csv' ); ?>
				<input type="hidden" name="action" value="tz_upload_csv" />

				<?php
				// Belt and braces: PHP honours MAX_FILE_SIZE only as a hint,
				// but it lets the browser fail fast on obviously huge files.
				?>
				<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( $max_upload ); ?>" />

				<p>
					<label class="tz-label" for="tz_csv"><?php esc_html_e( 'CSV file', 'tz-mailer' ); ?></label>
					<input type="file"
						id="tz_csv"
						name="tz_csv"
						accept=".csv,text/csv,text/plain"
						required="required" />
				</p>

				<p class="description">
					<?php
					printf(
						/* translators: 1: max upload size, 2: row limit */
						esc_html__( 'Maximum upload size on this server: %1$s. Maximum %2$s rows per file.', 'tz-mailer' ),
						esc_html( size_format( $max_upload ) ),
						esc_html( number_format_i18n( TZ_CSV::MAX_ROWS ) )
					);
					?>
				</p>

				<p>
					<button type="submit" class="button button-primary button-hero">
						<?php esc_html_e( 'Import contacts', 'tz-mailer' ); ?>
					</button>
				</p>
			</form>
		</div>

		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Expected format', 'tz-mailer' ); ?></h2>

			<p><?php esc_html_e( 'Column 1 is the email address, column 2 is the name. A header row is detected and skipped automatically, so both of these work:', 'tz-mailer' ); ?></p>

			<pre class="tz-code">email,name
jane@example.com,Jane Doe
sam@example.org,Sam Patel</pre>

			<pre class="tz-code">jane@example.com,Jane Doe
sam@example.org,Sam Patel</pre>

			<h3 class="tz-subheading"><?php esc_html_e( 'What happens to each row', 'tz-mailer' ); ?></h3>

			<ul class="tz-checklist">
				<li><?php esc_html_e( 'The address is validated with is_email() before any database write. Invalid rows are counted and discarded.', 'tz-mailer' ); ?></li>
				<li><?php esc_html_e( 'Addresses are lowercased and de-duplicated, both within the file and against contacts already in the list.', 'tz-mailer' ); ?></li>
				<li><?php esc_html_e( 'Each new contact gets a unique 128-bit cryptographic token for one-click unsubscribe.', 'tz-mailer' ); ?></li>
				<li><?php esc_html_e( 'Valid contacts are inserted with the status "queued" and wait for an active campaign.', 'tz-mailer' ); ?></li>
				<li><?php esc_html_e( 'Comma, semicolon, tab and pipe delimiters are all detected automatically, as is a UTF-8 byte order mark.', 'tz-mailer' ); ?></li>
			</ul>

			<div class="tz-callout tz-callout--warning">
				<strong><?php esc_html_e( 'Import quality decides your sender reputation.', 'tz-mailer' ); ?></strong>
				<?php esc_html_e( 'Local validation catches malformed addresses, but it cannot tell whether a mailbox exists. Importing a stale or purchased list is the fastest way to push the bounce rate past 2% and trip the circuit breaker.', 'tz-mailer' ); ?>
			</div>
		</div>

	</div>

	<div class="tz-panel">
		<h2 class="tz-panel__title"><?php esc_html_e( 'Current List', 'tz-mailer' ); ?></h2>

		<table class="widefat striped tz-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Status', 'tz-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Contacts', 'tz-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meaning', 'tz-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$tz_status_meta = array(
					'queued'       => array( 'info', __( 'Waiting for the next send batch.', 'tz-mailer' ) ),
					'sent'         => array( 'success', __( 'Accepted by the SES API. Awaiting a delivery notification.', 'tz-mailer' ) ),
					'delivered'    => array( 'success', __( 'Confirmed delivered by the receiving mail server.', 'tz-mailer' ) ),
					'bounced'      => array( 'danger', __( 'Permanently rejected. Suppressed from all future sends.', 'tz-mailer' ) ),
					'complaint'    => array( 'critical', __( 'Marked as spam by the recipient. Suppressed permanently.', 'tz-mailer' ) ),
					'unsubscribed' => array( 'neutral', __( 'Opted out via the one-click unsubscribe link.', 'tz-mailer' ) ),
					'failed'       => array( 'warning', __( 'The SES API rejected the send after all retries.', 'tz-mailer' ) ),
				);

				foreach ( $tz_status_meta as $tz_key => $tz_meta ) :
					?>
					<tr>
						<td><?php TZ_Admin::badge( $tz_key, $tz_meta[0] ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( isset( $counts[ $tz_key ] ) ? $counts[ $tz_key ] : 0 ) ); ?></strong></td>
						<td class="tz-detail"><?php echo esc_html( $tz_meta[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</div>
