<?php
/**
 * Campaign composer screen.
 *
 * @package Techzapp_Mailer
 *
 * @var array|null $campaign  Campaign being edited, or null for a new one.
 * @var array      $campaigns All campaigns for the sidebar list.
 * @var array      $counts    Subscriber status counts.
 */

defined( 'ABSPATH' ) || exit;

$tz_id      = $campaign ? (int) $campaign['id'] : 0;
$tz_subject = $campaign ? $campaign['subject'] : '';
$tz_body    = $campaign ? $campaign['body_html'] : TZ_Campaigns::starter_html();
$tz_active  = $campaign ? (bool) $campaign['is_active'] : false;
$tz_has_tag = TZ_Shortcodes::has_unsubscribe_tag( $tz_body );
$tz_post    = admin_url( 'admin-post.php' );
$tz_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap tz-wrap">

	<h1 class="tz-title">
		<?php echo $tz_id ? esc_html__( 'Edit Campaign', 'tz-mailer' ) : esc_html__( 'Campaign Composer', 'tz-mailer' ); ?>
		<?php if ( $tz_active ) : ?>
			<?php TZ_Admin::badge( __( 'LIVE', 'tz-mailer' ), 'success' ); ?>
		<?php endif; ?>
		<?php if ( $tz_id ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( TZ_Admin::url( '-composer', array( 'campaign' => 0 ) ) ); ?>">
				<?php esc_html_e( 'New campaign', 'tz-mailer' ); ?>
			</a>
		<?php endif; ?>
	</h1>

	<!-- Compliance requirement: shown before the editor, not buried under it. -->
	<div class="tz-callout tz-callout--<?php echo $tz_has_tag ? 'ok' : 'danger'; ?>">
		<p class="tz-callout__title">
			<?php if ( $tz_has_tag ) : ?>
				<?php esc_html_e( 'Unsubscribe tag present.', 'tz-mailer' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'REQUIRED: this campaign has no unsubscribe link.', 'tz-mailer' ); ?>
			<?php endif; ?>
		</p>
		<p>
			<?php
			printf(
				/* translators: %s: the unsubscribe merge tag, wrapped in code tags */
				esc_html__( 'Every campaign must include %s and a physical mailing address in the footer. This is a legal requirement under CAN-SPAM and a technical requirement of the Gmail and Yahoo bulk sender rules that took effect in February 2024. The queue worker refuses to send a campaign without the tag.', 'tz-mailer' ),
				'<code>{unsubscribe_url}</code>'
			);
			?>
		</p>
	</div>

	<div class="tz-composer">

		<!-- Editor -->
		<div class="tz-composer__main">
			<form method="post" action="<?php echo esc_url( $tz_post ); ?>">
				<?php wp_nonce_field( 'tz_save_campaign' ); ?>
				<input type="hidden" name="action" value="tz_save_campaign" />
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $tz_id ); ?>" />

				<p>
					<label class="tz-label" for="tz_subject"><?php esc_html_e( 'Subject line', 'tz-mailer' ); ?></label>
					<input type="text"
						id="tz_subject"
						name="tz_subject"
						class="large-text"
						maxlength="255"
						value="<?php echo esc_attr( $tz_subject ); ?>"
						placeholder="<?php esc_attr_e( 'A quick update for you, {name}', 'tz-mailer' ); ?>"
						required="required" />
					<span class="description"><?php esc_html_e( 'Merge tags work in the subject line too.', 'tz-mailer' ); ?></span>
				</p>

				<label class="tz-label" for="tz_body"><?php esc_html_e( 'Email body', 'tz-mailer' ); ?></label>

				<?php
				/*
				 * The full visual/HTML editor. media_buttons stays enabled so
				 * hosted images can be inserted; teeny is off because email
				 * HTML routinely needs the source view.
				 */
				wp_editor(
					$tz_body,
					'tz_body',
					array(
						'textarea_name' => 'tz_body',
						'textarea_rows' => 22,
						'media_buttons' => true,
						'teeny'         => false,
						'tinymce'       => array(
							'toolbar1' => 'formatselect,bold,italic,underline,forecolor,bullist,numlist,alignleft,aligncenter,alignright,link,unlink,removeformat,undo,redo',
							'toolbar2' => '',
						),
						'quicktags'     => array(
							'buttons' => 'strong,em,link,ul,ol,li,code,close',
						),
					)
				);
				?>

				<p class="tz-composer__save">
					<button type="submit" class="button button-primary button-hero">
						<?php echo $tz_id ? esc_html__( 'Save changes', 'tz-mailer' ) : esc_html__( 'Save campaign', 'tz-mailer' ); ?>
					</button>
				</p>
			</form>
		</div>

		<!-- Sidebar -->
		<div class="tz-composer__side">

			<div class="tz-panel">
				<h2 class="tz-panel__title"><?php esc_html_e( 'Merge Tags', 'tz-mailer' ); ?></h2>
				<dl class="tz-tags">
					<?php foreach ( TZ_Shortcodes::available_tags() as $tz_tag => $tz_desc ) : ?>
						<dt><code><?php echo esc_html( $tz_tag ); ?></code></dt>
						<dd><?php echo esc_html( $tz_desc ); ?></dd>
					<?php endforeach; ?>
				</dl>
				<p class="description">
					<?php
					printf(
						/* translators: %s: default name setting value */
						esc_html__( 'Contacts with no name receive "%s" in place of {name}.', 'tz-mailer' ),
						esc_html( TZ_Settings::get( 'default_name', 'there' ) )
					);
					?>
				</p>
			</div>

			<?php if ( $tz_id ) : ?>
				<div class="tz-panel">
					<h2 class="tz-panel__title"><?php esc_html_e( 'Sending', 'tz-mailer' ); ?></h2>

					<p class="tz-kv-inline">
						<span><?php esc_html_e( 'Queued contacts', 'tz-mailer' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $counts['queued'] ) ); ?></strong>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tz_campaign_action' ); ?>
						<input type="hidden" name="action" value="tz_campaign_action" />
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $tz_id ); ?>" />

						<?php if ( $tz_active ) : ?>
							<button type="submit" name="tz_action" value="deactivate" class="button button-secondary tz-button-block">
								<?php esc_html_e( 'Pause sending', 'tz-mailer' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'This campaign is live. The queue worker is sending it on every scheduled run.', 'tz-mailer' ); ?>
							</p>
						<?php else : ?>
							<button type="submit"
								name="tz_action"
								value="activate"
								class="button button-primary tz-button-block"
								<?php disabled( ! $tz_has_tag || TZ_Circuit_Breaker::is_halted() ); ?>>
								<?php esc_html_e( 'Activate and start sending', 'tz-mailer' ); ?>
							</button>

							<?php if ( TZ_Circuit_Breaker::is_halted() ) : ?>
								<p class="description tz-text-danger">
									<?php esc_html_e( 'The circuit breaker is halted. Clear it in Settings before activating a campaign.', 'tz-mailer' ); ?>
								</p>
							<?php elseif ( ! $tz_has_tag ) : ?>
								<p class="description tz-text-danger">
									<?php esc_html_e( 'Add {unsubscribe_url} to the body before this campaign can be activated.', 'tz-mailer' ); ?>
								</p>
							<?php else : ?>
								<p class="description">
									<?php esc_html_e( 'Activating deactivates any other live campaign. Only one campaign sends at a time.', 'tz-mailer' ); ?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</form>
				</div>

				<div class="tz-panel">
					<h2 class="tz-panel__title"><?php esc_html_e( 'Send a Test', 'tz-mailer' ); ?></h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tz_send_test' ); ?>
						<input type="hidden" name="action" value="tz_send_test" />
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $tz_id ); ?>" />

						<p>
							<input type="email"
								name="tz_test_email"
								class="large-text"
								placeholder="you@example.com"
								value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
								required="required" />
						</p>

						<button type="submit" class="button tz-button-block" <?php disabled( ! TZ_Settings::is_configured() ); ?>>
							<?php esc_html_e( 'Send test email', 'tz-mailer' ); ?>
						</button>

						<p class="description">
							<?php esc_html_e( 'Sends the saved version of this campaign with live merge tags and a working unsubscribe link. Save your changes first.', 'tz-mailer' ); ?>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<div class="tz-panel">
				<h2 class="tz-panel__title"><?php esc_html_e( 'All Campaigns', 'tz-mailer' ); ?></h2>

				<?php if ( empty( $campaigns ) ) : ?>
					<p class="tz-empty"><?php esc_html_e( 'No campaigns yet.', 'tz-mailer' ); ?></p>
				<?php else : ?>
					<ul class="tz-campaign-list">
						<?php foreach ( $campaigns as $tz_item ) : ?>
							<li class="<?php echo ( (int) $tz_item['id'] === $tz_id ) ? 'is-current' : ''; ?>">
								<a href="<?php echo esc_url( TZ_Admin::url( '-composer', array( 'campaign' => (int) $tz_item['id'] ) ) ); ?>">
									<?php echo esc_html( $tz_item['subject'] ); ?>
								</a>
								<?php if ( (int) $tz_item['is_active'] === 1 ) : ?>
									<?php TZ_Admin::badge( __( 'Live', 'tz-mailer' ), 'success' ); ?>
								<?php endif; ?>
								<span class="tz-muted"><?php echo esc_html( mysql2date( $tz_format, $tz_item['created_at'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<?php if ( $tz_id && ! $tz_active ) : ?>
				<div class="tz-panel tz-panel--danger">
					<h2 class="tz-panel__title"><?php esc_html_e( 'Delete', 'tz-mailer' ); ?></h2>
					<form method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Delete this campaign permanently?', 'tz-mailer' ) ); ?>');">
						<?php wp_nonce_field( 'tz_campaign_action' ); ?>
						<input type="hidden" name="action" value="tz_campaign_action" />
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $tz_id ); ?>" />
						<button type="submit" name="tz_action" value="delete" class="button button-link-delete">
							<?php esc_html_e( 'Delete campaign', 'tz-mailer' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

		</div>
	</div>
</div>
