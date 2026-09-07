<?php
/**
 * REST endpoints: AWS SNS event webhook and one-click unsubscribe.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles /wp-json/tz/v1/sns-events and /wp-json/tz/v1/unsub.
 */
class TZ_REST {

	/**
	 * Register both routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		/*
		 * SNS webhook. permission_callback is __return_true because AWS cannot
		 * authenticate with WordPress; authenticity is instead established by
		 * verifying the SNS message signature against Amazon's public
		 * certificate inside the handler.
		 */
		register_rest_route(
			TZ_MAILER_REST_NS,
			'/sns-events',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_sns' ),
				'permission_callback' => '__return_true',
			)
		);

		/*
		 * One-click unsubscribe. GET serves the human-facing confirmation page;
		 * POST is what RFC 8058 mail clients fire, and must succeed without any
		 * user interaction.
		 */
		register_rest_route(
			TZ_MAILER_REST_NS,
			'/unsub',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_unsubscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => static function ( $value ) {
							return preg_replace( '/[^a-f0-9]/i', '', (string) $value );
						},
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * SNS webhook
	 * ------------------------------------------------------------------ */

	/**
	 * Handle an inbound SNS POST.
	 *
	 * Supports both notification shapes SES can produce:
	 * - Legacy SES notifications, keyed on `notificationType`.
	 * - Configuration set event publishing, keyed on `eventType`.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_REST_Response
	 */
	public function handle_sns( WP_REST_Request $request ) {
		$raw = $request->get_body();

		if ( '' === trim( (string) $raw ) ) {
			return new WP_REST_Response( array( 'status' => 'empty' ), 400 );
		}

		$envelope = json_decode( $raw, true );

		if ( ! is_array( $envelope ) ) {
			TZ_Logger::log( '', 'API_Error', 'SNS webhook received a body that was not valid JSON.' );

			return new WP_REST_Response( array( 'status' => 'invalid_json' ), 400 );
		}

		// Verify the message really came from Amazon before acting on it.
		if ( TZ_Settings::get( 'verify_sns', 1 ) && ! $this->verify_sns_signature( $envelope ) ) {
			TZ_Logger::log( '', 'API_Error', 'SNS webhook rejected: message signature failed verification.' );

			return new WP_REST_Response( array( 'status' => 'signature_invalid' ), 403 );
		}

		$type = isset( $envelope['Type'] ) ? sanitize_text_field( $envelope['Type'] ) : '';

		// Some setups forward the header instead of setting Type in the body.
		if ( '' === $type ) {
			$type = sanitize_text_field( (string) $request->get_header( 'x-amz-sns-message-type' ) );
		}

		switch ( $type ) {
			case 'SubscriptionConfirmation':
				return $this->confirm_subscription( $envelope );

			case 'UnsubscribeConfirmation':
				TZ_Logger::log( '', 'System', 'SNS topic unsubscribe confirmation received. Bounce and complaint feedback has stopped for this topic.' );

				return new WP_REST_Response( array( 'status' => 'ok' ), 200 );

			case 'Notification':
				return $this->handle_notification( $envelope );
		}

		// An unrecognised envelope may still be a raw (non-SNS-wrapped)
		// notification posted directly, so try to process it before giving up.
		if ( isset( $envelope['notificationType'] ) || isset( $envelope['eventType'] ) ) {
			return $this->process_ses_event( $envelope );
		}

		return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
	}

	/**
	 * Auto-confirm a topic subscription by visiting the SubscribeURL.
	 *
	 * The URL is validated to be an HTTPS amazonaws.com host first: blindly
	 * fetching an attacker-supplied URL from inside the server would be a
	 * textbook SSRF.
	 *
	 * @param array $envelope Decoded SNS envelope.
	 * @return WP_REST_Response
	 */
	private function confirm_subscription( array $envelope ) {
		$url = isset( $envelope['SubscribeURL'] ) ? esc_url_raw( $envelope['SubscribeURL'] ) : '';

		if ( '' === $url || ! $this->is_amazon_url( $url ) ) {
			TZ_Logger::log( '', 'API_Error', 'SNS SubscriptionConfirmation carried a SubscribeURL that is not an Amazon HTTPS endpoint. Ignored.' );

			return new WP_REST_Response( array( 'status' => 'bad_subscribe_url' ), 400 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			TZ_Logger::log( '', 'API_Error', 'Failed to auto-confirm the SNS subscription: ' . $response->get_error_message() );

			return new WP_REST_Response( array( 'status' => 'confirm_failed' ), 200 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		TZ_Logger::log(
			'',
			'System',
			sprintf(
				'SNS subscription auto-confirmed for topic %s (HTTP %d). Bounce, complaint and delivery events will now flow into the delivery log.',
				isset( $envelope['TopicArn'] ) ? sanitize_text_field( $envelope['TopicArn'] ) : 'unknown',
				$code
			)
		);

		return new WP_REST_Response( array( 'status' => 'confirmed' ), 200 );
	}

	/**
	 * Unwrap an SNS Notification and dispatch the SES event inside it.
	 *
	 * @param array $envelope Decoded SNS envelope.
	 * @return WP_REST_Response
	 */
	private function handle_notification( array $envelope ) {
		$message = isset( $envelope['Message'] ) ? $envelope['Message'] : '';

		// SNS delivers the payload as a JSON string inside a JSON envelope.
		$event = is_string( $message ) ? json_decode( $message, true ) : $message;

		if ( ! is_array( $event ) ) {
			TZ_Logger::log( '', 'API_Error', 'SNS notification carried a Message that was not valid JSON.' );

			return new WP_REST_Response( array( 'status' => 'invalid_message' ), 200 );
		}

		return $this->process_ses_event( $event );
	}

	/**
	 * Apply one SES event to the subscriber list and the audit log.
	 *
	 * @param array $event Decoded SES notification.
	 * @return WP_REST_Response
	 */
	private function process_ses_event( array $event ) {
		// Legacy notifications use notificationType; event publishing uses
		// eventType. Both carry the same nested payload objects.
		$type = '';

		if ( isset( $event['notificationType'] ) ) {
			$type = sanitize_text_field( $event['notificationType'] );
		} elseif ( isset( $event['eventType'] ) ) {
			$type = sanitize_text_field( $event['eventType'] );
		}

		switch ( $type ) {
			case 'Bounce':
				$handled = $this->process_bounce( $event );
				break;

			case 'Complaint':
				$handled = $this->process_complaint( $event );
				break;

			case 'Delivery':
				$handled = $this->process_delivery( $event );
				break;

			default:
				// Open, Click, Send, Reject, Rendering Failure and so on are
				// recorded but need no status change.
				$handled = 0;

				if ( '' !== $type ) {
					TZ_Logger::log( '', 'System', 'SNS event received and ignored: ' . $type );
				}
				break;
		}

		/*
		 * A batch of bounces can push the rate over the limit without a single
		 * send happening, so re-evaluate the breaker as soon as new bounce data
		 * lands rather than waiting for the next cron tick.
		 */
		if ( in_array( $type, array( 'Bounce', 'Complaint' ), true ) ) {
			TZ_Circuit_Breaker::check_and_maybe_trip( 'sns_webhook' );
		}

		return new WP_REST_Response(
			array(
				'status'    => 'ok',
				'type'      => $type,
				'processed' => $handled,
			),
			200
		);
	}

	/**
	 * Handle a Bounce notification.
	 *
	 * Permanent bounces suppress the address. Transient (soft) bounces are
	 * logged but leave the contact sendable: a full mailbox today may well
	 * accept mail next week, and suppressing on a soft bounce needlessly
	 * shrinks the list.
	 *
	 * @param array $event Decoded notification.
	 * @return int Recipients processed.
	 */
	private function process_bounce( array $event ) {
		$bounce = isset( $event['bounce'] ) && is_array( $event['bounce'] ) ? $event['bounce'] : array();

		$bounce_type = isset( $bounce['bounceType'] ) ? sanitize_text_field( $bounce['bounceType'] ) : 'Unknown';
		$sub_type    = isset( $bounce['bounceSubType'] ) ? sanitize_text_field( $bounce['bounceSubType'] ) : 'Unknown';
		$recipients  = isset( $bounce['bouncedRecipients'] ) && is_array( $bounce['bouncedRecipients'] )
			? $bounce['bouncedRecipients']
			: array();

		$is_permanent = ( 'Permanent' === $bounce_type );
		$event_type   = $is_permanent ? 'Hard_Bounce' : 'Soft_Bounce';

		$count = 0;

		foreach ( $recipients as $recipient ) {
			$email = isset( $recipient['emailAddress'] ) ? sanitize_email( $recipient['emailAddress'] ) : '';

			if ( ! is_email( $email ) ) {
				continue;
			}

			$diagnostic = isset( $recipient['diagnosticCode'] )
				? sanitize_text_field( $recipient['diagnosticCode'] )
				: __( 'No diagnostic code supplied by the receiving server.', 'tz-mailer' );

			$status = isset( $recipient['status'] ) ? sanitize_text_field( $recipient['status'] ) : '';
			$action = isset( $recipient['action'] ) ? sanitize_text_field( $recipient['action'] ) : '';

			if ( $is_permanent ) {
				TZ_DB::update_status_by_email( $email, 'bounced' );
			}

			TZ_Logger::log(
				$email,
				$event_type,
				sprintf(
					'%s / %s. SMTP status %s, action %s. Diagnostic: %s%s',
					$bounce_type,
					$sub_type,
					'' !== $status ? $status : 'n/a',
					'' !== $action ? $action : 'n/a',
					$diagnostic,
					$is_permanent ? ' [address suppressed]' : ' [address kept, transient failure]'
				)
			);

			$count++;
		}

		return $count;
	}

	/**
	 * Handle a Complaint notification.
	 *
	 * A spam complaint is the most damaging signal a sender can receive, so the
	 * address is suppressed immediately and unconditionally.
	 *
	 * @param array $event Decoded notification.
	 * @return int Recipients processed.
	 */
	private function process_complaint( array $event ) {
		$complaint = isset( $event['complaint'] ) && is_array( $event['complaint'] ) ? $event['complaint'] : array();

		$feedback   = isset( $complaint['complaintFeedbackType'] )
			? sanitize_text_field( $complaint['complaintFeedbackType'] )
			: 'unspecified';
		$sub_type   = isset( $complaint['complaintSubType'] ) ? sanitize_text_field( $complaint['complaintSubType'] ) : '';
		$recipients = isset( $complaint['complainedRecipients'] ) && is_array( $complaint['complainedRecipients'] )
			? $complaint['complainedRecipients']
			: array();

		$count = 0;

		foreach ( $recipients as $recipient ) {
			$email = isset( $recipient['emailAddress'] ) ? sanitize_email( $recipient['emailAddress'] ) : '';

			if ( ! is_email( $email ) ) {
				continue;
			}

			TZ_DB::update_status_by_email( $email, 'complaint' );

			TZ_Logger::log(
				$email,
				'Spam_Complaint',
				sprintf(
					'Recipient reported this message as spam. Feedback type: %s%s. Address permanently suppressed.',
					$feedback,
					'' !== $sub_type ? ' (' . $sub_type . ')' : ''
				)
			);

			$count++;
		}

		return $count;
	}

	/**
	 * Handle a Delivery notification.
	 *
	 * @param array $event Decoded notification.
	 * @return int Recipients processed.
	 */
	private function process_delivery( array $event ) {
		$delivery = isset( $event['delivery'] ) && is_array( $event['delivery'] ) ? $event['delivery'] : array();

		$recipients = isset( $delivery['recipients'] ) && is_array( $delivery['recipients'] )
			? $delivery['recipients']
			: array();

		$smtp     = isset( $delivery['smtpResponse'] ) ? sanitize_text_field( $delivery['smtpResponse'] ) : '';
		$duration = isset( $delivery['processingTimeMillis'] ) ? (int) $delivery['processingTimeMillis'] : 0;

		$count = 0;

		foreach ( $recipients as $recipient ) {
			$email = sanitize_email( is_array( $recipient ) ? '' : $recipient );

			if ( ! is_email( $email ) ) {
				continue;
			}

			TZ_DB::update_status_by_email( $email, 'delivered' );

			TZ_Logger::log(
				$email,
				'Delivery_Confirmed',
				sprintf(
					'Accepted by the receiving mail server in %dms. SMTP response: %s',
					$duration,
					'' !== $smtp ? $smtp : 'n/a'
				)
			);

			$count++;
		}

		return $count;
	}

	/* ---------------------------------------------------------------------
	 * SNS authenticity
	 * ------------------------------------------------------------------ */

	/**
	 * Verify an SNS message signature against Amazon's signing certificate.
	 *
	 * Without this, anyone who knows the webhook URL could POST fabricated
	 * bounce events and either poison the subscriber list or deliberately trip
	 * the circuit breaker. The certificate is fetched once and cached.
	 *
	 * @param array $envelope Decoded SNS envelope.
	 * @return bool True when the signature is valid.
	 */
	private function verify_sns_signature( array $envelope ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			// Without OpenSSL the signature cannot be checked at all. Fail
			// closed rather than trusting an unverifiable message.
			TZ_Logger::log( '', 'API_Error', 'SNS signature verification is enabled but the OpenSSL extension is unavailable. Message rejected.' );

			return false;
		}

		if ( empty( $envelope['Signature'] ) || empty( $envelope['SigningCertURL'] ) || empty( $envelope['Type'] ) ) {
			return false;
		}

		$cert_url = esc_url_raw( $envelope['SigningCertURL'] );

		if ( ! $this->is_amazon_url( $cert_url ) ) {
			return false;
		}

		$certificate = $this->fetch_signing_certificate( $cert_url );

		if ( '' === $certificate ) {
			return false;
		}

		$plain = $this->build_string_to_sign( $envelope );

		if ( '' === $plain ) {
			return false;
		}

		$version   = isset( $envelope['SignatureVersion'] ) ? (string) $envelope['SignatureVersion'] : '1';
		$algorithm = ( '2' === $version ) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

		$signature = base64_decode( $envelope['Signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $signature ) {
			return false;
		}

		$public_key = openssl_pkey_get_public( $certificate );

		if ( false === $public_key ) {
			return false;
		}

		$result = openssl_verify( $plain, $signature, $public_key, $algorithm );

		// openssl_free_key is deprecated in PHP 8 and unnecessary there.
		if ( PHP_VERSION_ID < 80000 && is_resource( $public_key ) ) {
			openssl_free_key( $public_key ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
		}

		return ( 1 === $result );
	}

	/**
	 * Build the canonical string SNS signed.
	 *
	 * The field list and their order are fixed by the SNS specification and
	 * differ per message type. Absent optional fields are skipped.
	 *
	 * @param array $envelope Decoded SNS envelope.
	 * @return string
	 */
	private function build_string_to_sign( array $envelope ) {
		$type = isset( $envelope['Type'] ) ? $envelope['Type'] : '';

		if ( 'Notification' === $type ) {
			$fields = array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' );
		} elseif ( 'SubscriptionConfirmation' === $type || 'UnsubscribeConfirmation' === $type ) {
			$fields = array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		} else {
			return '';
		}

		$plain = '';

		foreach ( $fields as $field ) {
			// Subject is optional; it is omitted entirely when absent.
			if ( ! isset( $envelope[ $field ] ) ) {
				continue;
			}

			$plain .= $field . "\n" . $envelope[ $field ] . "\n";
		}

		return $plain;
	}

	/**
	 * Fetch and cache Amazon's SNS signing certificate.
	 *
	 * @param string $url Certificate URL, already host-validated.
	 * @return string PEM certificate, or '' on failure.
	 */
	private function fetch_signing_certificate( $url ) {
		$cache_key = 'tz_sns_cert_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( false === strpos( $body, 'BEGIN CERTIFICATE' ) ) {
			return '';
		}

		set_transient( $cache_key, $body, DAY_IN_SECONDS );

		return $body;
	}

	/**
	 * Whether a URL points at an HTTPS amazonaws.com host.
	 *
	 * Used for both SigningCertURL and SubscribeURL. Matching on the host
	 * suffix (with the leading dot) prevents "amazonaws.com.evil.tld" and
	 * "notamazonaws.com" from passing.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_amazon_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		return ( 'amazonaws.com' === $host || substr( $host, -14 ) === '.amazonaws.com' );
	}

	/* ---------------------------------------------------------------------
	 * One-click unsubscribe
	 * ------------------------------------------------------------------ */

	/**
	 * Handle an unsubscribe request.
	 *
	 * RFC 8058 requires that the POST issued by a mail client succeeds without
	 * any further interaction, so there is deliberately no confirmation step.
	 * The token is the authorisation: it is 128 bits of CSPRNG output, unique
	 * per subscriber, and only ever transmitted to that subscriber.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_REST_Response
	 */
	public function handle_unsubscribe( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$token = preg_replace( '/[^a-f0-9]/i', '', $token );

		$is_one_click_post = ( 'POST' === $request->get_method() );

		if ( '' === $token ) {
			return $this->unsubscribe_response(
				false,
				__( 'This unsubscribe link is incomplete.', 'tz-mailer' ),
				__( 'The link appears to have been truncated by your mail client. Please contact us directly and we will remove you right away.', 'tz-mailer' ),
				$is_one_click_post,
				400
			);
		}

		$subscriber = TZ_DB::get_subscriber_by_token( $token );

		if ( ! $subscriber ) {
			return $this->unsubscribe_response(
				false,
				__( 'Link not recognised.', 'tz-mailer' ),
				__( 'We could not match this link to a subscription. It may already have been removed.', 'tz-mailer' ),
				$is_one_click_post,
				404
			);
		}

		// Already unsubscribed: report success. Telling someone their opt-out
		// "failed" because they clicked twice is the wrong outcome.
		if ( 'unsubscribed' === $subscriber['status'] ) {
			return $this->unsubscribe_response(
				true,
				__( 'You are already unsubscribed.', 'tz-mailer' ),
				__( 'This address was removed from our mailing list previously. No further email will be sent.', 'tz-mailer' ),
				$is_one_click_post,
				200
			);
		}

		TZ_DB::update_status_by_email( $subscriber['email'], 'unsubscribed' );

		TZ_Logger::log(
			$subscriber['email'],
			'Unsubscribed',
			sprintf(
				'One-click unsubscribe honoured via %s. Address suppressed from all future sends.',
				$is_one_click_post ? 'RFC 8058 List-Unsubscribe-Post' : 'link click'
			)
		);

		/**
		 * Fires after a subscriber opts out.
		 *
		 * @param string $email      Address removed.
		 * @param array  $subscriber Full subscriber row as it was before the update.
		 */
		do_action( 'tz_mailer_unsubscribed', $subscriber['email'], $subscriber );

		return $this->unsubscribe_response(
			true,
			__( 'You have been unsubscribed.', 'tz-mailer' ),
			__( 'Your address has been removed from our mailing list. You will not receive any further messages from us.', 'tz-mailer' ),
			$is_one_click_post,
			200
		);
	}

	/**
	 * Build the response for an unsubscribe attempt.
	 *
	 * Mail-client POSTs receive a minimal JSON acknowledgement; humans clicking
	 * the link in a browser get a branded HTML confirmation page.
	 *
	 * @param bool   $success  Whether the opt-out was recorded.
	 * @param string $heading  Short headline.
	 * @param string $message  Explanatory sentence.
	 * @param bool   $is_post  Whether this was the RFC 8058 POST.
	 * @param int    $status   HTTP status code.
	 * @return WP_REST_Response
	 */
	private function unsubscribe_response( $success, $heading, $message, $is_post, $status ) {
		if ( $is_post ) {
			return new WP_REST_Response(
				array(
					'success' => (bool) $success,
					'message' => $heading,
				),
				$status
			);
		}

		$html = $this->render_unsubscribe_page( $success, $heading, $message );

		// Hand back raw HTML rather than a JSON envelope: this URL is opened
		// directly in a browser by recipients.
		$response = new WP_REST_Response( $html, $status );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_raw_html' ), 10, 4 );

		return $response;
	}

	/**
	 * Emit the unsubscribe page as raw HTML instead of a JSON string.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Response to serve.
	 * @param WP_REST_Request  $request Current request.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function serve_raw_html( $served, $result, $request, $server ) {
		unset( $server );

		// Only intervene for our own endpoint.
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . TZ_MAILER_REST_NS . '/unsub' ) ) {
			return $served;
		}

		$data = $result->get_data();

		if ( ! is_string( $data ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo $data; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Fully assembled and escaped in render_unsubscribe_page().

		return true;
	}

	/**
	 * Render the branded unsubscribe confirmation page.
	 *
	 * Self-contained: no theme, no external assets, so it renders identically
	 * regardless of what the active theme is doing.
	 *
	 * @param bool   $success Whether the opt-out succeeded.
	 * @param string $heading Headline.
	 * @param string $message Explanatory sentence.
	 * @return string Complete HTML document.
	 */
	private function render_unsubscribe_page( $success, $heading, $message ) {
		$site_name = get_bloginfo( 'name' );
		$home      = home_url( '/' );
		$accent    = $success ? '#0f8a4d' : '#b32d2e';
		$icon      = $success ? '&#10003;' : '&#33;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $heading ); ?></title>
	<style>
		:root { color-scheme: light dark; }
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			background: #f2f4f7;
			color: #1d2327;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			line-height: 1.6;
		}
		.tz-card {
			background: #fff;
			max-width: 520px;
			width: 100%;
			padding: 40px;
			border-radius: 12px;
			box-shadow: 0 2px 24px rgba(16, 24, 40, 0.08);
			text-align: center;
		}
		.tz-badge {
			width: 56px;
			height: 56px;
			margin: 0 auto 20px;
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 26px;
			font-weight: 700;
			color: #fff;
			background: <?php echo esc_attr( $accent ); ?>;
		}
		h1 { font-size: 22px; margin: 0 0 12px; color: #1d2327; }
		p { margin: 0 0 20px; color: #50575e; font-size: 15px; }
		.tz-site { font-size: 13px; color: #787c82; margin: 0; }
		.tz-link {
			display: inline-block;
			margin-top: 4px;
			color: #2271b1;
			text-decoration: none;
			font-size: 14px;
			font-weight: 500;
		}
		.tz-link:hover { text-decoration: underline; }
		@media (prefers-color-scheme: dark) {
			body { background: #16181d; color: #e6e8eb; }
			.tz-card { background: #1f2329; box-shadow: none; }
			h1 { color: #f0f2f4; }
			p { color: #a7adb5; }
			.tz-link { color: #6ca8e0; }
		}
	</style>
</head>
<body>
	<main class="tz-card">
		<div class="tz-badge" aria-hidden="true"><?php echo wp_kses_post( $icon ); ?></div>
		<h1><?php echo esc_html( $heading ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<p class="tz-site"><?php echo esc_html( $site_name ); ?></p>
		<a class="tz-link" href="<?php echo esc_url( $home ); ?>"><?php esc_html_e( 'Return to our website', 'tz-mailer' ); ?></a>
	</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
