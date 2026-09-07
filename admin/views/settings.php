<?php
/**
 * Settings screen.
 *
 * @package Techzapp_Mailer
 *
 * @var array  $settings   Current settings.
 * @var bool   $halted     Whether the circuit breaker is latched.
 * @var array  $meta       Circuit breaker trip metadata.
 * @var string $webhook    SNS webhook URL.
 * @var string $unsub_base Unsubscribe endpoint base URL.
 * @var array  $throughput Throughput estimate.
 * @var array  $update     Update status from TZ_Updater::status().
 */

defined( 'ABSPATH' ) || exit;

$tz_has_secret = ( '' !== $settings['aws_secret_key'] );
$tz_has_token  = ( '' !== $settings['github_token'] );
$tz_regions    = TZ_Settings::regions();
?>
<div class="wrap tz-wrap">

	<h1 class="tz-title"><?php esc_html_e( 'Techzapp Mailer Settings', 'tz-mailer' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'tz_save_settings' ); ?>
		<input type="hidden" name="action" value="tz_save_settings" />

		<!-- AWS credentials -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'AWS SES Credentials', 'tz-mailer' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Use a dedicated IAM user whose only permission is ses:SendEmail. Never paste root account keys into a WordPress install.', 'tz-mailer' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="tz_aws_access_key"><?php esc_html_e( 'AWS Access Key ID', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="text"
								id="tz_aws_access_key"
								name="aws_access_key"
								class="regular-text code"
								autocomplete="off"
								value="<?php echo esc_attr( $settings['aws_access_key'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_aws_secret_key"><?php esc_html_e( 'AWS Secret Access Key', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="password"
								id="tz_aws_secret_key"
								name="aws_secret_key"
								class="regular-text code"
								autocomplete="new-password"
								value="<?php echo $tz_has_secret ? esc_attr( TZ_Settings::secret_mask() ) : ''; ?>" />
							<p class="description">
								<?php
								if ( $tz_has_secret ) {
									esc_html_e( 'A secret key is stored. Leave the masked value untouched to keep it, or type a new key to replace it.', 'tz-mailer' );
								} else {
									esc_html_e( 'Shown only once by AWS when the access key is created.', 'tz-mailer' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_aws_region"><?php esc_html_e( 'AWS Region', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="text"
								id="tz_aws_region"
								name="aws_region"
								class="regular-text code"
								list="tz_region_list"
								autocomplete="off"
								spellcheck="false"
								value="<?php echo esc_attr( $settings['aws_region'] ); ?>" />

							<datalist id="tz_region_list">
								<?php foreach ( $tz_regions as $tz_code => $tz_name ) : ?>
									<option value="<?php echo esc_attr( $tz_code ); ?>">
										<?php echo esc_attr( $tz_name ); ?>
									</option>
								<?php endforeach; ?>
							</datalist>
							<p class="description">
								<?php
								printf(
									/* translators: 1: region label, 2: SES endpoint URL */
									esc_html__( 'Start typing to search, or enter any region code. %1$s Currently sending through %2$s', 'tz-mailer' ),
									isset( $tz_regions[ $settings['aws_region'] ] )
										? '<strong>' . esc_html( $tz_regions[ $settings['aws_region'] ] ) . '</strong>.'
										: '',
									'<code>' . esc_html( TZ_SES::endpoint( $settings['aws_region'] ) ) . '</code>'
								);
								?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'SES verifies identities per region.', 'tz-mailer' ); ?></strong>
								<?php esc_html_e( 'A domain verified in one region cannot send from another. Your sending domain, SNS topic and configuration set must all live in the region selected here, or sends fail with MailFromDomainNotVerified and no bounce events ever reach the webhook.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_configuration_set"><?php esc_html_e( 'Configuration Set', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="text"
								id="tz_configuration_set"
								name="configuration_set"
								class="regular-text code"
								value="<?php echo esc_attr( $settings['configuration_set'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Optional but strongly recommended. A configuration set with an SNS event destination is what delivers bounce, complaint and delivery events to the webhook below.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Sender identity -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Sender Identity', 'tz-mailer' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="tz_from_name"><?php esc_html_e( 'From Name', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="text"
								id="tz_from_name"
								name="from_name"
								class="regular-text"
								value="<?php echo esc_attr( $settings['from_name'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The display name recipients see in their inbox.', 'tz-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_from_email"><?php esc_html_e( 'From Email', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="email"
								id="tz_from_email"
								name="from_email"
								class="regular-text code"
								value="<?php echo esc_attr( $settings['from_email'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Must be a verified identity in SES. Send bulk mail from a dedicated subdomain such as updates.techzapp.com so that a reputation problem on the bulk stream never contaminates your transactional or corporate mail.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_reply_to_email"><?php esc_html_e( 'Reply-To Email', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="email"
								id="tz_reply_to_email"
								name="reply_to_email"
								class="regular-text code"
								value="<?php echo esc_attr( $settings['reply_to_email'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'A real, monitored mailbox. Leave blank to use the From address. Never point this at a no-reply address: mailbox providers treat unmonitored reply paths as a negative signal.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_default_name"><?php esc_html_e( 'Default Name', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="text"
								id="tz_default_name"
								name="default_name"
								class="regular-text"
								value="<?php echo esc_attr( $settings['default_name'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: the name merge tag */
									esc_html__( 'Substituted for %s when a contact has no name on file.', 'tz-mailer' ),
									'<code>{name}</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Throttling -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Sending Rate', 'tz-mailer' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'The queue worker runs every five minutes. Ramp these numbers up gradually over two to four weeks: a cold sending domain that suddenly emits thousands of messages an hour gets throttled regardless of how well it authenticates.', 'tz-mailer' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="tz_batch_size"><?php esc_html_e( 'Batch Size', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="number"
								id="tz_batch_size"
								name="batch_size"
								class="small-text"
								min="1"
								max="200"
								step="1"
								value="<?php echo esc_attr( $settings['batch_size'] ); ?>" />
							<span class="description"><?php esc_html_e( 'emails per 5 minute run (1-200)', 'tz-mailer' ); ?></span>
							<p class="description">
								<?php
								printf(
									/* translators: 1: emails per hour, 2: emails per day */
									esc_html__( 'Current setting works out to roughly %1$s emails per hour, or %2$s per day.', 'tz-mailer' ),
									'<strong>' . esc_html( number_format_i18n( $throughput['per_hour'] ) ) . '</strong>',
									'<strong>' . esc_html( number_format_i18n( $throughput['per_day'] ) ) . '</strong>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_send_delay"><?php esc_html_e( 'Delay Between Sends', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="number"
								id="tz_send_delay"
								name="send_delay"
								class="small-text"
								min="0"
								max="30"
								step="1"
								value="<?php echo esc_attr( $settings['send_delay'] ); ?>" />
							<span class="description"><?php esc_html_e( 'seconds (0-30, recommended 2-3)', 'tz-mailer' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'A deliberate pause between individual messages so a batch arrives as a steady trickle rather than a burst.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content Mode', 'tz-mailer' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="content_mode" value="simple" <?php checked( $settings['content_mode'], 'simple' ); ?> />
									<?php esc_html_e( 'Simple (recommended) - SES assembles the MIME and applies the unsubscribe headers.', 'tz-mailer' ); ?>
								</label><br />
								<label>
									<input type="radio" name="content_mode" value="raw" <?php checked( $settings['content_mode'], 'raw' ); ?> />
									<?php esc_html_e( 'Raw MIME - the plugin builds the message itself. Use only if Simple mode rejects the custom headers.', 'tz-mailer' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Circuit breaker -->
		<div class="tz-panel <?php echo $halted ? 'tz-panel--danger' : ''; ?>">
			<h2 class="tz-panel__title">
				<?php esc_html_e( 'Circuit Breaker', 'tz-mailer' ); ?>
				<?php
				$tz_badge = TZ_Circuit_Breaker::badge();
				TZ_Admin::badge( $tz_badge['label'], $tz_badge['state'] );
				?>
			</h2>

			<p class="description">
				<?php
				printf(
					/* translators: 1: threshold percentage, 2: minimum sample size */
					esc_html__( 'Sending halts automatically the moment the rolling bounce rate reaches %1$s%%, once at least %2$s contacts have been processed. AWS places an account under review at 5%% and may suspend it at 10%%, so this limit leaves room to investigate before Amazon acts.', 'tz-mailer' ),
					esc_html( number_format_i18n( TZ_Circuit_Breaker::THRESHOLD, 1 ) ),
					esc_html( number_format_i18n( TZ_Circuit_Breaker::MIN_SAMPLE ) )
				);
				?>
			</p>

			<?php if ( $halted ) : ?>
				<div class="tz-callout tz-callout--danger">
					<p class="tz-callout__title"><?php esc_html_e( 'Sending is currently halted.', 'tz-mailer' ); ?></p>
					<table class="tz-kv">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Halted at', 'tz-mailer' ); ?></th>
								<td>
									<?php
									echo isset( $meta['tripped_at'] )
										? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meta['tripped_at'] ) )
										: esc_html__( 'Unknown', 'tz-mailer' );
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Bounce rate at halt', 'tz-mailer' ); ?></th>
								<td><strong><?php echo esc_html( number_format_i18n( isset( $meta['rate'] ) ? $meta['rate'] : 0, 2 ) ); ?>%</strong></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Sample', 'tz-mailer' ); ?></th>
								<td>
									<?php
									printf(
										/* translators: 1: bounced count, 2: processed count */
										esc_html__( '%1$s hard bounces across %2$s processed contacts', 'tz-mailer' ),
										esc_html( number_format_i18n( isset( $meta['bounced'] ) ? $meta['bounced'] : 0 ) ),
										esc_html( number_format_i18n( isset( $meta['processed'] ) ? $meta['processed'] : 0 ) )
									);
									?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Detected by', 'tz-mailer' ); ?></th>
								<td><code><?php echo esc_html( isset( $meta['trigger'] ) ? $meta['trigger'] : 'n/a' ); ?></code></td>
							</tr>
						</tbody>
					</table>
				</div>

				<p>
					<label class="tz-reset-label">
						<input type="checkbox" name="tz_reset_breaker" value="1" />
						<strong><?php esc_html_e( 'I have reviewed the bounce log, removed the bad addresses from my list, and confirmed my sending domain still authenticates. Clear the circuit breaker and resume sending.', 'tz-mailer' ); ?></strong>
					</label>
				</p>

				<p class="description tz-text-danger">
					<?php esc_html_e( 'Resetting without cleaning the list will trip the breaker again within a single batch, and every additional bounce moves your account closer to an AWS suspension.', 'tz-mailer' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php
					$tz_metrics = TZ_Circuit_Breaker::metrics();
					printf(
						/* translators: 1: bounce rate, 2: bounced count, 3: processed count */
						esc_html__( 'Current rolling bounce rate: %1$s%% (%2$s of %3$s processed).', 'tz-mailer' ),
						'<strong>' . esc_html( number_format_i18n( $tz_metrics['rate'], 2 ) ) . '</strong>',
						esc_html( number_format_i18n( $tz_metrics['bounced'] ) ),
						esc_html( number_format_i18n( $tz_metrics['processed'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Maintenance -->
		<div class="tz-panel">
			<h2 class="tz-panel__title"><?php esc_html_e( 'Security and Maintenance', 'tz-mailer' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'SNS Signature Verification', 'tz-mailer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="verify_sns" value="1" <?php checked( (int) $settings['verify_sns'], 1 ); ?> />
								<?php esc_html_e( 'Verify that inbound webhook messages are genuinely signed by Amazon', 'tz-mailer' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this on. The webhook URL is public, and without signature verification anyone who finds it could post fabricated bounce events to poison your list or deliberately trip the circuit breaker.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tz_log_retention"><?php esc_html_e( 'Log Retention', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="number"
								id="tz_log_retention"
								name="log_retention"
								class="small-text"
								min="0"
								max="3650"
								step="1"
								value="<?php echo esc_attr( $settings['log_retention'] ); ?>" />
							<span class="description"><?php esc_html_e( 'days (0 keeps everything forever)', 'tz-mailer' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall Behaviour', 'tz-mailer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( (int) $settings['delete_on_uninstall'], 1 ); ?> />
								<?php esc_html_e( 'Delete all subscribers, campaigns and logs when the plugin is deleted', 'tz-mailer' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Deactivating the plugin never touches your data; only deleting it does, and only with this box ticked.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Updates -->
		<div class="tz-panel">
			<h2 class="tz-panel__title">
				<?php esc_html_e( 'Plugin Updates', 'tz-mailer' ); ?>
				<?php
				if ( $update['available'] ) {
					TZ_Admin::badge( __( 'UPDATE AVAILABLE', 'tz-mailer' ), 'warning' );
				} elseif ( $update['checked'] ) {
					TZ_Admin::badge( __( 'UP TO DATE', 'tz-mailer' ), 'success' );
				} else {
					TZ_Admin::badge( __( 'CHECK FAILED', 'tz-mailer' ), 'neutral' );
				}
				?>
			</h2>

			<p class="description">
				<?php
				printf(
					/* translators: %s: repository link */
					esc_html__( 'Updates are delivered from GitHub releases at %s. When a newer release is published, WordPress shows the usual update notice on the Plugins and Dashboard screens and updates with one click.', 'tz-mailer' ),
					'<a href="https://github.com/appcoderikbal/plugin-crm" target="_blank" rel="noopener noreferrer">appcoderikbal/plugin-crm</a>'
				);
				?>
			</p>

			<table class="tz-kv">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Installed version', 'tz-mailer' ); ?></th>
						<td><code><?php echo esc_html( $update['current'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest release', 'tz-mailer' ); ?></th>
						<td>
							<?php if ( '' !== $update['latest'] ) : ?>
								<code><?php echo esc_html( $update['latest'] ); ?></code>
								<a href="<?php echo esc_url( $update['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'release notes', 'tz-mailer' ); ?>
								</a>
							<?php else : ?>
								<em><?php echo esc_html( $update['hint'] ); ?></em>
								<br /><span class="description"><?php esc_html_e( 'Failed checks are cached for 30 minutes to protect the API rate limit. Use "Check for updates" on the Plugins screen to retry immediately.', 'tz-mailer' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="tz_github_token"><?php esc_html_e( 'GitHub Token', 'tz-mailer' ); ?></label></th>
						<td>
							<input type="password"
								id="tz_github_token"
								name="github_token"
								class="regular-text code"
								autocomplete="new-password"
								value="<?php echo $tz_has_token ? esc_attr( TZ_Settings::secret_mask() ) : ''; ?>" />
							<p class="description">
								<?php esc_html_e( 'Optional. Only needed if the repository is private, or if an unauthenticated rate limit of 60 API calls per hour is a problem. A fine-grained token with read-only Contents access is enough.', 'tz-mailer' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'tz-mailer' ) ); ?>
	</form>

	<!-- Endpoints. Outside the settings form: these are read-only references. -->
	<div class="tz-panel">
		<h2 class="tz-panel__title"><?php esc_html_e( 'AWS SNS Webhook', 'tz-mailer' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'Paste this URL into an Amazon SNS topic subscription with protocol HTTPS. The plugin confirms the subscription automatically, so there is no token to copy back.', 'tz-mailer' ); ?>
		</p>

		<?php
		TZ_Admin::copy_field(
			__( 'SNS Webhook URL', 'tz-mailer' ),
			$webhook,
			__( 'Click the field to select the whole URL.', 'tz-mailer' )
		);

		TZ_Admin::copy_field(
			__( 'Unsubscribe Endpoint', 'tz-mailer' ),
			$unsub_base,
			__( 'Each contact receives this URL with their own unique token appended. Nothing to configure.', 'tz-mailer' )
		);
		?>

		<h3 class="tz-subheading"><?php esc_html_e( 'One-time AWS setup', 'tz-mailer' ); ?></h3>

		<ol class="tz-steps">
			<li><?php esc_html_e( 'Verify your sending domain in SES and publish the DKIM CNAME records it gives you.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Publish an SPF record authorising amazonses.com, and a DMARC policy for the domain.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Create an SNS topic, for example techzapp-ses-events.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Add a subscription to that topic: protocol HTTPS, endpoint set to the SNS Webhook URL above. It confirms itself within seconds.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Create an SES configuration set, add an SNS event destination pointing at the topic, and tick Bounce, Complaint and Delivery.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Enter that configuration set name in the field above, then send a test email to confirm events arrive in the delivery log.', 'tz-mailer' ); ?></li>
			<li><?php esc_html_e( 'Request production access in SES to leave the sandbox, which otherwise only permits sending to verified addresses.', 'tz-mailer' ); ?></li>
		</ol>

		<div class="tz-callout tz-callout--warning">
			<strong><?php esc_html_e( 'Without the SNS webhook the circuit breaker cannot protect you.', 'tz-mailer' ); ?></strong>
			<?php esc_html_e( 'Bounce data arrives only over SNS. If no topic is wired up, the bounce count stays at zero, the rate never rises, and sending continues straight into an AWS account review.', 'tz-mailer' ); ?>
		</div>
	</div>

	<!-- Diagnostics -->
	<div class="tz-panel">
		<h2 class="tz-panel__title"><?php esc_html_e( 'System Status', 'tz-mailer' ); ?></h2>

		<table class="tz-kv">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'SES configuration', 'tz-mailer' ); ?></th>
					<td>
						<?php
						if ( TZ_Settings::is_configured() ) {
							TZ_Admin::badge( __( 'Complete', 'tz-mailer' ), 'success' );
						} else {
							TZ_Admin::badge( __( 'Incomplete', 'tz-mailer' ), 'critical' );
							echo ' ' . esc_html( implode( ', ', TZ_Settings::missing_requirements() ) );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Queue worker', 'tz-mailer' ); ?></th>
					<td>
						<?php
						$tz_next = TZ_Queue::next_run();

						if ( $tz_next ) {
							TZ_Admin::badge( __( 'Scheduled', 'tz-mailer' ), 'success' );
							echo ' ' . esc_html(
								sprintf(
									/* translators: %s: relative time */
									__( 'next run in %s', 'tz-mailer' ),
									human_time_diff( time(), $tz_next )
								)
							);
						} else {
							TZ_Admin::badge( __( 'Not scheduled', 'tz-mailer' ), 'critical' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WP-Cron', 'tz-mailer' ); ?></th>
					<td>
						<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
							<?php TZ_Admin::badge( __( 'Disabled in wp-config', 'tz-mailer' ), 'warning' ); ?>
							<span class="description"><?php esc_html_e( 'A real system cron must be calling wp-cron.php, otherwise the queue will never drain.', 'tz-mailer' ); ?></span>
						<?php else : ?>
							<?php TZ_Admin::badge( __( 'Enabled', 'tz-mailer' ), 'success' ); ?>
							<span class="description"><?php esc_html_e( 'WP-Cron fires on page loads. On a low traffic site, a real system cron is far more reliable.', 'tz-mailer' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OpenSSL', 'tz-mailer' ); ?></th>
					<td>
						<?php if ( function_exists( 'openssl_verify' ) ) : ?>
							<?php TZ_Admin::badge( __( 'Available', 'tz-mailer' ), 'success' ); ?>
						<?php else : ?>
							<?php TZ_Admin::badge( __( 'Missing', 'tz-mailer' ), 'critical' ); ?>
							<span class="description"><?php esc_html_e( 'SNS signature verification cannot run without it and all webhook messages will be rejected.', 'tz-mailer' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP max execution time', 'tz-mailer' ); ?></th>
					<td><code><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin version', 'tz-mailer' ); ?></th>
					<td><code><?php echo esc_html( TZ_MAILER_VERSION ); ?></code></td>
				</tr>
			</tbody>
		</table>
	</div>

</div>
