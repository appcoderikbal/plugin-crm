<?php
/**
 * Admin UI controller: menus, assets, notices and form handlers.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up every wp-admin screen the plugin owns.
 *
 * Form submissions use the Post/Redirect/Get pattern via admin-post.php so a
 * browser refresh can never replay an import or a send.
 */
class TZ_Admin {

	/** Capability required for every screen and action. */
	const CAP = 'manage_options';

	/** Top level menu slug. */
	const SLUG = 'tz-mailer';

	/** Transient key prefix for cross-redirect admin notices. */
	const NOTICE_KEY = 'tz_admin_notice_';

	/**
	 * Attach admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// The breaker banner must appear on every admin screen, not just ours.
		add_action( 'admin_notices', array( $this, 'render_circuit_breaker_banner' ) );
		add_action( 'admin_notices', array( $this, 'render_flash_notice' ) );

		// Form handlers.
		add_action( 'admin_post_tz_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_tz_upload_csv', array( $this, 'handle_upload_csv' ) );
		add_action( 'admin_post_tz_save_campaign', array( $this, 'handle_save_campaign' ) );
		add_action( 'admin_post_tz_campaign_action', array( $this, 'handle_campaign_action' ) );
		add_action( 'admin_post_tz_send_test', array( $this, 'handle_send_test' ) );
		add_action( 'admin_post_tz_run_queue', array( $this, 'handle_run_queue' ) );
		add_action( 'admin_post_tz_requeue', array( $this, 'handle_requeue' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu and assets
	 * ------------------------------------------------------------------ */

	/**
	 * Register the top level menu and its sub-pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$queued = TZ_DB::status_counts();
		$count  = (int) $queued['queued'];

		// Surface the pending queue size as a bubble, the way core does for
		// pending comments.
		$title = __( 'TZ Mailer', 'tz-mailer' );

		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>';
		}

		add_menu_page(
			__( 'Techzapp Mailer', 'tz-mailer' ),
			$title,
			self::CAP,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt',
			26
		);

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'tz-mailer' ),
			__( 'Dashboard', 'tz-mailer' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Upload CSV', 'tz-mailer' ),
			__( 'Upload CSV', 'tz-mailer' ),
			self::CAP,
			self::SLUG . '-upload',
			array( $this, 'render_upload' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Campaign Composer', 'tz-mailer' ),
			__( 'Campaign Composer', 'tz-mailer' ),
			self::CAP,
			self::SLUG . '-composer',
			array( $this, 'render_composer' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Delivery Logs', 'tz-mailer' ),
			__( 'Delivery Logs', 'tz-mailer' ),
			self::CAP,
			self::SLUG . '-logs',
			array( $this, 'render_logs' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'tz-mailer' ),
			__( 'Settings', 'tz-mailer' ),
			self::CAP,
			self::SLUG . '-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue the admin stylesheet.
	 *
	 * Loaded on every admin screen because the circuit breaker banner is
	 * global; the file is tiny and has no JavaScript payload.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		unset( $hook );

		wp_enqueue_style(
			'tz-mailer-admin',
			TZ_MAILER_URL . 'assets/admin.css',
			array(),
			TZ_MAILER_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Render the emergency circuit breaker banner on every admin screen.
	 *
	 * Deliberately loud and not dismissible: while this is showing, no email
	 * is going out, and an operator needs to know that immediately.
	 *
	 * @return void
	 */
	public function render_circuit_breaker_banner() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( ! TZ_Circuit_Breaker::is_halted() ) {
			return;
		}

		$meta      = TZ_Circuit_Breaker::meta();
		$rate      = isset( $meta['rate'] ) ? (float) $meta['rate'] : TZ_Circuit_Breaker::bounce_rate();
		$bounced   = isset( $meta['bounced'] ) ? (int) $meta['bounced'] : 0;
		$processed = isset( $meta['processed'] ) ? (int) $meta['processed'] : 0;
		$when      = isset( $meta['tripped_at'] ) ? $meta['tripped_at'] : '';

		$settings_url = admin_url( 'admin.php?page=' . self::SLUG . '-settings' );
		$logs_url     = admin_url( 'admin.php?page=' . self::SLUG . '-logs&event_type=Circuit_Breaker_Halt' );
		?>
		<div class="notice tz-breaker-banner">
			<p class="tz-breaker-title">
				<span class="tz-breaker-icon" aria-hidden="true">&#9888;</span>
				<?php esc_html_e( 'EMERGENCY SHUTOFF: Techzapp Mailer has halted all outbound email.', 'tz-mailer' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: bounce rate, 2: threshold, 3: bounced count, 4: processed count */
					esc_html__( 'The rolling bounce rate reached %1$s%% against a hard limit of %2$s%% (%3$s hard bounces across %4$s processed contacts). Sending stopped automatically to protect your AWS SES account from being placed under review or suspended.', 'tz-mailer' ),
					'<strong>' . esc_html( number_format_i18n( $rate, 2 ) ) . '</strong>',
					esc_html( number_format_i18n( TZ_Circuit_Breaker::THRESHOLD, 1 ) ),
					esc_html( number_format_i18n( $bounced ) ),
					esc_html( number_format_i18n( $processed ) )
				);
				?>
			</p>
			<?php if ( '' !== $when ) : ?>
				<p class="tz-breaker-meta">
					<?php
					printf(
						/* translators: %s: date and time */
						esc_html__( 'Halted at %s.', 'tz-mailer' ),
						esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) )
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<strong><?php esc_html_e( 'Before resetting:', 'tz-mailer' ); ?></strong>
				<?php esc_html_e( 'review the hard bounces in the delivery log, remove the source of the bad addresses from your list, and confirm your sending domain still passes SPF, DKIM and DMARC. Resetting without fixing the list will trip the breaker again within one batch.', 'tz-mailer' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $logs_url ); ?>"><?php esc_html_e( 'Review bounce log', 'tz-mailer' ); ?></a>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Go to Settings to reset', 'tz-mailer' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a one-shot notice stored before a redirect.
	 *
	 * @return void
	 */
	public function render_flash_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );

		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$type = isset( $notice['type'] ) ? $notice['type'] : 'info';
		$text = isset( $notice['text'] ) ? $notice['text'] : '';

		if ( '' === $text ) {
			return;
		}

		$class = 'notice notice-' . sanitize_html_class( $type ) . ' is-dismissible';

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			wp_kses( $text, self::allowed_notice_html() )
		);
	}

	/**
	 * Store a notice to display after the next redirect.
	 *
	 * @param string $type One of success, error, warning, info.
	 * @param string $text Message (limited HTML allowed).
	 * @return void
	 */
	private function flash( $type, $text ) {
		set_transient( self::NOTICE_KEY . get_current_user_id(), array(
			'type' => $type,
			'text' => $text,
		), 60 );
	}

	/**
	 * HTML permitted inside admin notices.
	 *
	 * @return array
	 */
	private static function allowed_notice_html() {
		return array(
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		);
	}

	/**
	 * Verify capability and nonce for a POST handler, or die.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Techzapp Mailer.', 'tz-mailer' ),
				esc_html__( 'Permission denied', 'tz-mailer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to one of the plugin screens and stop.
	 *
	 * @param string $page  Page slug suffix, e.g. '-settings'.
	 * @param array  $extra Extra query arguments.
	 * @return void
	 */
	private function redirect( $page = '', array $extra = array() ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . $page );

		if ( ! empty( $extra ) ) {
			$url = add_query_arg( $extra, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Persist the settings form.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->guard( 'tz_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$raw = wp_unslash( $_POST );

		$settings = TZ_Settings::sanitize( $raw );
		TZ_Settings::save( $settings );

		$messages = array( __( 'Settings saved.', 'tz-mailer' ) );

		// The breaker reset is intentionally a separate, explicit confirmation
		// rather than something a settings save can do by accident.
		if ( ! empty( $raw['tz_reset_breaker'] ) ) {
			if ( TZ_Circuit_Breaker::reset( true ) ) {
				$messages[] = __( '<strong>Circuit breaker cleared.</strong> Sending will resume on the next scheduled run.', 'tz-mailer' );
			} elseif ( ! TZ_Circuit_Breaker::is_halted() ) {
				$messages[] = __( 'The circuit breaker was already inactive.', 'tz-mailer' );
			}
		}

		$missing = TZ_Settings::missing_requirements();

		if ( ! empty( $missing ) ) {
			$this->flash(
				'warning',
				sprintf(
					/* translators: %s: comma separated list of missing fields */
					__( 'Settings saved, but sending is still disabled. Missing: %s.', 'tz-mailer' ),
					implode( ', ', array_map( 'esc_html', $missing ) )
				)
			);
		} else {
			$this->flash( 'success', implode( ' ', $messages ) );
		}

		$this->redirect( '-settings' );
	}

	/**
	 * Handle the CSV upload form.
	 *
	 * @return void
	 */
	public function handle_upload_csv() {
		$this->guard( 'tz_upload_csv' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		if ( empty( $_FILES['tz_csv'] ) || ! is_array( $_FILES['tz_csv'] ) ) {
			$this->flash( 'error', __( 'No file was received. Please choose a CSV file and try again.', 'tz-mailer' ) );
			$this->redirect( '-upload' );
		}

		// $_FILES values are paths and metadata from PHP itself, validated
		// inside TZ_CSV::import_upload() before anything is read.
		$file = array_map( 'wp_unslash', $_FILES['tz_csv'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = TZ_CSV::import_upload( $file );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', esc_html( $result->get_error_message() ) );
			$this->redirect( '-upload' );
		}

		$type = ( $result['imported'] > 0 ) ? 'success' : 'warning';

		$this->flash( $type, esc_html( TZ_CSV::summarize( $result ) ) );

		$this->redirect( '-upload' );
	}

	/**
	 * Create or update a campaign from the composer.
	 *
	 * @return void
	 */
	public function handle_save_campaign() {
		$this->guard( 'tz_save_campaign' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$post = wp_unslash( $_POST );

		$id      = isset( $post['campaign_id'] ) ? absint( $post['campaign_id'] ) : 0;
		$subject = isset( $post['tz_subject'] ) ? $post['tz_subject'] : '';
		$body    = isset( $post['tz_body'] ) ? $post['tz_body'] : '';

		if ( '' === trim( sanitize_text_field( $subject ) ) ) {
			$this->flash( 'error', __( 'A subject line is required.', 'tz-mailer' ) );
			$this->redirect( '-composer', $id ? array( 'campaign' => $id ) : array() );
		}

		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			$this->flash( 'error', __( 'The campaign body cannot be empty.', 'tz-mailer' ) );
			$this->redirect( '-composer', $id ? array( 'campaign' => $id ) : array() );
		}

		if ( $id > 0 ) {
			TZ_Campaigns::update( $id, $subject, $body );
			$message = __( 'Campaign updated.', 'tz-mailer' );
		} else {
			$id = TZ_Campaigns::create( $subject, $body );

			if ( ! $id ) {
				$this->flash( 'error', __( 'The campaign could not be saved. Check the database error log.', 'tz-mailer' ) );
				$this->redirect( '-composer' );
			}

			$message = __( 'Campaign created.', 'tz-mailer' );
		}

		// The compliance requirement is enforced here as well as in the
		// worker, so the operator finds out at save time rather than
		// discovering a stalled queue later.
		if ( ! TZ_Shortcodes::has_unsubscribe_tag( $body ) ) {
			$message .= ' ' . __( '<strong>Warning:</strong> this campaign does not contain the <code>{unsubscribe_url}</code> tag and cannot be sent until it does.', 'tz-mailer' );
			$this->flash( 'warning', $message );
		} else {
			$this->flash( 'success', $message );
		}

		$this->redirect( '-composer', array( 'campaign' => $id ) );
	}

	/**
	 * Activate, deactivate or delete a campaign.
	 *
	 * @return void
	 */
	public function handle_campaign_action() {
		$this->guard( 'tz_campaign_action' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$post = wp_unslash( $_POST );

		$id     = isset( $post['campaign_id'] ) ? absint( $post['campaign_id'] ) : 0;
		$action = isset( $post['tz_action'] ) ? sanitize_text_field( $post['tz_action'] ) : '';

		switch ( $action ) {
			case 'activate':
				$campaign = TZ_Campaigns::get( $id );

				if ( ! $campaign ) {
					$this->flash( 'error', __( 'That campaign no longer exists.', 'tz-mailer' ) );
					break;
				}

				if ( ! TZ_Shortcodes::has_unsubscribe_tag( $campaign['body_html'] ) ) {
					$this->flash( 'error', __( 'This campaign cannot be activated: the <code>{unsubscribe_url}</code> tag is missing. Bulk email without a working unsubscribe link violates CAN-SPAM and Gmail bulk sender policy.', 'tz-mailer' ) );
					break;
				}

				if ( ! TZ_Settings::is_configured() ) {
					$this->flash( 'error', __( 'Configure your AWS SES credentials in Settings before activating a campaign.', 'tz-mailer' ) );
					break;
				}

				TZ_Campaigns::activate( $id );

				$this->flash(
					'success',
					__( '<strong>Campaign is now live.</strong> The queue worker will begin sending on the next scheduled run.', 'tz-mailer' )
				);
				break;

			case 'deactivate':
				TZ_Campaigns::deactivate_all();
				$this->flash( 'success', __( 'Sending paused. No further messages will go out until a campaign is activated again.', 'tz-mailer' ) );
				break;

			case 'delete':
				if ( TZ_Campaigns::delete( $id ) ) {
					$this->flash( 'success', __( 'Campaign deleted.', 'tz-mailer' ) );
				} else {
					$this->flash( 'error', __( 'The campaign could not be deleted.', 'tz-mailer' ) );
				}

				$this->redirect( '-composer' );
				break;

			default:
				$this->flash( 'error', __( 'Unrecognised action.', 'tz-mailer' ) );
				break;
		}

		$this->redirect( '-composer', $id ? array( 'campaign' => $id ) : array() );
	}

	/**
	 * Send a single test message.
	 *
	 * @return void
	 */
	public function handle_send_test() {
		$this->guard( 'tz_send_test' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$post = wp_unslash( $_POST );

		$to = isset( $post['tz_test_email'] ) ? sanitize_email( $post['tz_test_email'] ) : '';
		$id = isset( $post['campaign_id'] ) ? absint( $post['campaign_id'] ) : 0;

		$back = $id ? array( 'campaign' => $id ) : array();

		if ( ! is_email( $to ) ) {
			$this->flash( 'error', __( 'Enter a valid email address to send the test to.', 'tz-mailer' ) );
			$this->redirect( '-composer', $back );
		}

		if ( ! TZ_Settings::is_configured() ) {
			$this->flash( 'error', __( 'AWS SES is not configured yet. Add your credentials in Settings first.', 'tz-mailer' ) );
			$this->redirect( '-composer', $back );
		}

		// Prefer the unsaved editor contents so the operator can test a draft
		// without committing it first.
		$subject = isset( $post['tz_subject'] ) && '' !== trim( (string) $post['tz_subject'] )
			? (string) $post['tz_subject']
			: '';
		$body = isset( $post['tz_body'] ) && '' !== trim( wp_strip_all_tags( (string) $post['tz_body'] ) )
			? (string) $post['tz_body']
			: '';

		if ( '' === $subject || '' === $body ) {
			$campaign = $id ? TZ_Campaigns::get( $id ) : TZ_Campaigns::get_active();

			if ( ! $campaign ) {
				$this->flash( 'error', __( 'Nothing to send. Write a subject and body first.', 'tz-mailer' ) );
				$this->redirect( '-composer', $back );
			}

			$subject = '' !== $subject ? $subject : $campaign['subject'];
			$body    = '' !== $body ? $body : $campaign['body_html'];
		}

		$result = TZ_SES::send_test( $to, '[TEST] ' . $subject, $body );

		if ( $result['success'] ) {
			$this->flash(
				'success',
				sprintf(
					/* translators: 1: recipient address, 2: SES message ID */
					__( 'Test message accepted by SES for %1$s. MessageId: <code>%2$s</code>', 'tz-mailer' ),
					esc_html( $to ),
					esc_html( $result['message_id'] )
				)
			);
		} else {
			$this->flash(
				'error',
				sprintf(
					/* translators: 1: AWS error code, 2: error message */
					__( 'SES rejected the test message. <strong>%1$s</strong>: %2$s', 'tz-mailer' ),
					esc_html( $result['error_code'] ),
					esc_html( $result['error'] )
				)
			);
		}

		$this->redirect( '-composer', $back );
	}

	/**
	 * Run one queue batch immediately, without waiting for cron.
	 *
	 * @return void
	 */
	public function handle_run_queue() {
		$this->guard( 'tz_run_queue' );

		// A manual run releases a lock left behind by a crashed worker,
		// otherwise the operator has no way to recover without WP-CLI.
		if ( TZ_Queue::is_locked() ) {
			TZ_Queue::release_lock();
		}

		$report = tz_mailer()->queue->run();

		if ( ! empty( $report['halted'] ) ) {
			$this->flash( 'error', esc_html( $report['reason'] ) );
		} elseif ( $report['sent'] > 0 ) {
			$this->flash(
				'success',
				sprintf(
					/* translators: 1: sent count, 2: failed count, 3: skipped count */
					__( 'Batch complete: %1$s sent, %2$s failed, %3$s skipped.', 'tz-mailer' ),
					esc_html( number_format_i18n( $report['sent'] ) ),
					esc_html( number_format_i18n( $report['failed'] ) ),
					esc_html( number_format_i18n( $report['skipped'] ) )
				)
			);
		} else {
			$reason = ! empty( $report['reason'] )
				? $report['reason']
				: __( 'Nothing was sent.', 'tz-mailer' );

			$this->flash( 'warning', esc_html( $reason ) );
		}

		$this->redirect();
	}

	/**
	 * Requeue previously sent contacts so a new campaign can go out.
	 *
	 * @return void
	 */
	public function handle_requeue() {
		$this->guard( 'tz_requeue' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer().
		$post = wp_unslash( $_POST );

		if ( empty( $post['tz_requeue_confirm'] ) ) {
			$this->flash( 'error', __( 'Tick the confirmation box to requeue contacts.', 'tz-mailer' ) );
			$this->redirect();
		}

		$include_failed = ! empty( $post['tz_include_failed'] );
		$count          = TZ_Subscribers::requeue_all( $include_failed );

		TZ_Logger::log( '', 'System', sprintf( '%d contacts requeued for sending by an administrator.', $count ) );

		$this->flash(
			'success',
			sprintf(
				/* translators: %s: number of contacts */
				_n(
					'%s contact returned to the queue. Bounced, complained and unsubscribed addresses were left suppressed.',
					'%s contacts returned to the queue. Bounced, complained and unsubscribed addresses were left suppressed.',
					$count,
					'tz-mailer'
				),
				esc_html( number_format_i18n( $count ) )
			)
		);

		$this->redirect();
	}

	/* ---------------------------------------------------------------------
	 * Screen renderers
	 * ------------------------------------------------------------------ */

	/**
	 * Load a view file with a capability check.
	 *
	 * @param string $view View file basename, without extension.
	 * @param array  $data Variables extracted into the view scope.
	 * @return void
	 */
	private function view( $view, array $data = array() ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'tz-mailer' ),
				esc_html__( 'Permission denied', 'tz-mailer' ),
				array( 'response' => 403 )
			);
		}

		$file = TZ_MAILER_PATH . 'admin/views/' . $view . '.php';

		if ( ! file_exists( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled, non-user data.
		extract( $data, EXTR_SKIP );

		require $file;
	}

	/**
	 * Dashboard screen.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->view(
			'dashboard',
			array(
				'metrics'    => TZ_Circuit_Breaker::metrics(),
				'badge'      => TZ_Circuit_Breaker::badge(),
				'campaign'   => TZ_Campaigns::get_active(),
				'throughput' => TZ_Queue::throughput(),
				'next_run'   => TZ_Queue::next_run(),
				'total'      => TZ_DB::total_subscribers(),
				'recent'     => TZ_Logger::query( '', '', 8, 0 ),
				'blocker'    => TZ_Queue::blocker(),
				'last_run'   => TZ_Queue::last_run(),
			)
		);
	}

	/**
	 * CSV upload screen.
	 *
	 * @return void
	 */
	public function render_upload() {
		$this->view(
			'upload-csv',
			array(
				'counts'     => TZ_DB::status_counts(),
				'max_upload' => wp_max_upload_size(),
			)
		);
	}

	/**
	 * Campaign composer screen.
	 *
	 * @return void
	 */
	public function render_composer() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selector.
		$requested = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;

		$campaign = $requested ? TZ_Campaigns::get( $requested ) : TZ_Campaigns::get_active();

		$this->view(
			'composer',
			array(
				'campaign'  => $campaign,
				'campaigns' => TZ_Campaigns::all( 50 ),
				'counts'    => TZ_DB::status_counts(),
			)
		);
	}

	/**
	 * Delivery log screen.
	 *
	 * @return void
	 */
	public function render_logs() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters on a GET screen.
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$total    = TZ_Logger::count( $search, $event_type );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $paged, $pages );
		$offset   = ( $paged - 1 ) * $per_page;

		$this->view(
			'logs',
			array(
				'rows'       => TZ_Logger::query( $search, $event_type, $per_page, $offset ),
				'search'     => $search,
				'event_type' => $event_type,
				'paged'      => $paged,
				'pages'      => $pages,
				'total'      => $total,
				'per_page'   => $per_page,
			)
		);
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->view(
			'settings',
			array(
				'settings'   => TZ_Settings::all( true ),
				'halted'     => TZ_Circuit_Breaker::is_halted(),
				'meta'       => TZ_Circuit_Breaker::meta(),
				'webhook'    => TZ_Settings::sns_webhook_url(),
				'unsub_base' => TZ_Settings::unsubscribe_base_url(),
				'throughput' => TZ_Queue::throughput(),
				'update'     => tz_mailer()->updater->status(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shared view helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Render a KPI stat card.
	 *
	 * @param array $args Card definition: label, value, sub, state, href.
	 * @return void
	 */
	public static function stat_card( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'label' => '',
				'value' => '0',
				'sub'   => '',
				'state' => 'neutral',
				'href'  => '',
			)
		);

		$classes = 'tz-card tz-card--' . sanitize_html_class( $args['state'] );

		echo '<div class="' . esc_attr( $classes ) . '">';
		echo '<span class="tz-card__label">' . esc_html( $args['label'] ) . '</span>';
		echo '<span class="tz-card__value">' . esc_html( $args['value'] ) . '</span>';

		if ( '' !== $args['sub'] ) {
			echo '<span class="tz-card__sub">' . esc_html( $args['sub'] ) . '</span>';
		}

		if ( '' !== $args['href'] ) {
			echo '<a class="tz-card__link" href="' . esc_url( $args['href'] ) . '">' . esc_html__( 'View', 'tz-mailer' ) . '</a>';
		}

		echo '</div>';
	}

	/**
	 * Render a coloured status badge.
	 *
	 * @param string $label Badge text.
	 * @param string $state Badge state modifier.
	 * @return void
	 */
	public static function badge( $label, $state = 'neutral' ) {
		printf(
			'<span class="tz-badge tz-badge--%1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $state ) ),
			esc_html( $label )
		);
	}

	/**
	 * Render a read-only field with the value pre-selected for copying.
	 *
	 * @param string $label Field label.
	 * @param string $value Value to display.
	 * @param string $help  Optional help text.
	 * @return void
	 */
	public static function copy_field( $label, $value, $help = '' ) {
		echo '<div class="tz-copy">';
		echo '<label class="tz-copy__label">' . esc_html( $label ) . '</label>';
		echo '<input type="text" class="tz-copy__input large-text code" readonly value="' . esc_attr( $value ) . '" onfocus="this.select();" />';

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * URL of one of the plugin screens.
	 *
	 * @param string $suffix Slug suffix.
	 * @param array  $args   Query arguments.
	 * @return string
	 */
	public static function url( $suffix = '', array $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . $suffix );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}
}
