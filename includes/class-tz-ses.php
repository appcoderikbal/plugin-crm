<?php
/**
 * Direct AWS SES v2 API client with native Signature Version 4 signing.
 *
 * Deliberately avoids the AWS SDK for PHP: the SDK plus its Guzzle/PSR
 * dependency tree is roughly 10 MB and frequently collides with other plugins
 * that bundle their own copy. Everything needed for SendEmail is about 200
 * lines of hashing, so we sign the request ourselves and post it through
 * wp_remote_post() (which uses cURL under the hood on virtually every host).
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal SES v2 SendEmail client.
 */
class TZ_SES {

	/** AWS service namespace used in the SigV4 credential scope. */
	const SERVICE = 'ses';

	/** SigV4 algorithm identifier. */
	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** Request path for the SendEmail operation. */
	const PATH = '/v2/email/outbound-emails';

	/** Per-request network timeout, in seconds. */
	const TIMEOUT = 20;

	/**
	 * Regional API host.
	 *
	 * @param string $region AWS region code.
	 * @return string
	 */
	public static function host( $region ) {
		return 'email.' . $region . '.amazonaws.com';
	}

	/**
	 * Full endpoint URL for SendEmail.
	 *
	 * @param string $region AWS region code.
	 * @return string
	 */
	public static function endpoint( $region ) {
		return 'https://' . self::host( $region ) . self::PATH;
	}

	/**
	 * Send one message to one recipient.
	 *
	 * @param array $args {
	 *     Message definition.
	 *
	 *     @type string $to               Recipient email address. Required.
	 *     @type string $subject          Rendered subject line. Required.
	 *     @type string $html             Rendered HTML body. Required.
	 *     @type string $text             Rendered plain text alternative. Optional.
	 *     @type string $unsubscribe_url  Per-recipient unsubscribe URL. Required for bulk.
	 * }
	 * @return array {
	 *     Result descriptor. Never throws.
	 *
	 *     @type bool   $success    Whether SES accepted the message.
	 *     @type string $message_id SES MessageId on success.
	 *     @type string $error      Human readable failure reason.
	 *     @type string $error_code AWS exception type, when available.
	 *     @type int    $status     HTTP status code, 0 for transport failures.
	 *     @type bool   $retryable  Whether a later retry is worth attempting.
	 * }
	 */
	public static function send( array $args ) {
		$settings = TZ_Settings::all();

		$to = isset( $args['to'] ) ? sanitize_email( $args['to'] ) : '';

		if ( ! is_email( $to ) ) {
			return self::result( false, '', __( 'Recipient address failed validation before sending.', 'tz-mailer' ), 'LocalValidation', 0, false );
		}

		if ( ! TZ_Settings::is_configured() ) {
			return self::result( false, '', __( 'AWS credentials or From address are not configured.', 'tz-mailer' ), 'NotConfigured', 0, false );
		}

		$payload = self::build_payload( $args, $settings );

		if ( is_wp_error( $payload ) ) {
			return self::result( false, '', $payload->get_error_message(), 'PayloadError', 0, false );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return self::result( false, '', __( 'Failed to JSON encode the SES request payload.', 'tz-mailer' ), 'PayloadError', 0, false );
		}

		return self::request( $body, $settings );
	}

	/**
	 * Normalize a result array.
	 *
	 * @param bool   $success    Outcome.
	 * @param string $message_id SES MessageId.
	 * @param string $error      Failure description.
	 * @param string $error_code AWS exception type.
	 * @param int    $status     HTTP status.
	 * @param bool   $retryable  Retry hint.
	 * @return array
	 */
	private static function result( $success, $message_id = '', $error = '', $error_code = '', $status = 0, $retryable = false ) {
		return array(
			'success'    => (bool) $success,
			'message_id' => (string) $message_id,
			'error'      => (string) $error,
			'error_code' => (string) $error_code,
			'status'     => (int) $status,
			'retryable'  => (bool) $retryable,
		);
	}

	/**
	 * Assemble the SES v2 SendEmail request body.
	 *
	 * Two content modes are supported:
	 *
	 * - "simple" uses Content.Simple and passes List-Unsubscribe /
	 *   List-Unsubscribe-Post through the Headers array. This is the modern,
	 *   recommended path and lets SES handle MIME assembly.
	 * - "raw" hand-builds the MIME document and base64s it into Content.Raw.
	 *   Kept as a fallback for regions or account configurations where the
	 *   Headers array is rejected.
	 *
	 * @param array $args     Message definition passed to send().
	 * @param array $settings Resolved plugin settings.
	 * @return array|WP_Error
	 */
	private static function build_payload( array $args, array $settings ) {
		$to      = sanitize_email( $args['to'] );
		$subject = isset( $args['subject'] ) ? (string) $args['subject'] : '';
		$html    = isset( $args['html'] ) ? (string) $args['html'] : '';
		$text    = isset( $args['text'] ) && '' !== $args['text']
			? (string) $args['text']
			: TZ_Shortcodes::html_to_text( $html );

		$unsub_url = isset( $args['unsubscribe_url'] ) ? (string) $args['unsubscribe_url'] : '';

		if ( '' === trim( $subject ) ) {
			return new WP_Error( 'tz_empty_subject', __( 'Refusing to send a message with an empty subject line.', 'tz-mailer' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'tz_empty_body', __( 'Refusing to send a message with an empty body.', 'tz-mailer' ) );
		}

		// Strip CR/LF from the subject: header injection guard.
		$subject = trim( str_replace( array( "\r", "\n" ), ' ', $subject ) );

		$from_email = $settings['from_email'];
		$from_name  = $settings['from_name'];

		// Reply-To falls back to the From address so replies never black-hole.
		$reply_to = is_email( $settings['reply_to_email'] ) ? $settings['reply_to_email'] : $from_email;

		$payload = array(
			'FromEmailAddress'  => self::format_address( $from_name, $from_email ),
			'Destination'       => array(
				'ToAddresses' => array( $to ),
			),
			'ReplyToAddresses'  => array( $reply_to ),
		);

		if ( '' !== $settings['configuration_set'] ) {
			$payload['ConfigurationSetName'] = $settings['configuration_set'];
		}

		if ( 'raw' === $settings['content_mode'] ) {
			$mime = self::build_raw_mime(
				array(
					'from'      => self::format_address( $from_name, $from_email ),
					'reply_to'  => $reply_to,
					'to'        => $to,
					'subject'   => $subject,
					'html'      => $html,
					'text'      => $text,
					'unsub_url' => $unsub_url,
				)
			);

			$payload['Content'] = array(
				'Raw' => array(
					'Data' => base64_encode( $mime ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			);

			return $payload;
		}

		$simple = array(
			'Subject' => array(
				'Data'    => $subject,
				'Charset' => 'UTF-8',
			),
			'Body'    => array(
				'Html' => array(
					'Data'    => $html,
					'Charset' => 'UTF-8',
				),
				'Text' => array(
					'Data'    => $text,
					'Charset' => 'UTF-8',
				),
			),
		);

		/*
		 * RFC 8058 one-click unsubscribe. Gmail and Yahoo have required this of
		 * bulk senders since February 2024; without it, mail from a high volume
		 * domain gets throttled or binned regardless of SPF/DKIM/DMARC status.
		 */
		if ( '' !== $unsub_url ) {
			$simple['Headers'] = array(
				array(
					'Name'  => 'List-Unsubscribe',
					'Value' => '<' . $unsub_url . '>',
				),
				array(
					'Name'  => 'List-Unsubscribe-Post',
					'Value' => 'List-Unsubscribe=One-Click',
				),
			);
		}

		$payload['Content'] = array( 'Simple' => $simple );

		return $payload;
	}


	/**
	 * Format a "Display Name <address>" header value.
	 *
	 * Non-ASCII display names are RFC 2047 encoded; names containing RFC 5322
	 * "specials" are quoted. Bare addresses are returned untouched.
	 *
	 * chr(34)/chr(92) are used in place of literal quote/backslash escapes to
	 * keep the escaping unambiguous.
	 *
	 * @param string $name  Display name, may be empty.
	 * @param string $email Address.
	 * @return string
	 */
	public static function format_address( $name, $email ) {
		$name  = trim( (string) $name );
		$email = trim( (string) $email );

		if ( '' === $name ) {
			return $email;
		}

		// Never let a display name smuggle in a header break.
		$name = str_replace( array( "\r", "\n" ), '', $name );

		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?= <' . $email . '>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$specials = '()<>@,;:' . chr( 34 ) . '.[]' . chr( 92 );

		if ( false !== strpbrk( $name, $specials ) ) {
			return chr( 34 ) . addcslashes( $name, chr( 34 ) . chr( 92 ) ) . chr( 34 ) . ' <' . $email . '>';
		}

		return $name . ' <' . $email . '>';
	}

	/**
	 * Hand-build a MIME document for Content.Raw mode.
	 *
	 * Produces a multipart/alternative message with quoted-printable-free
	 * base64 parts, which sidesteps every line-length and encoding pitfall of
	 * assembling MIME by hand.
	 *
	 * @param array $p Message parts (from, reply_to, to, subject, html, text, unsub_url).
	 * @return string Complete MIME document.
	 */
	private static function build_raw_mime( array $p ) {
		$eol      = "\r\n";
		$boundary = 'tzmailer_' . bin2hex( random_bytes( 12 ) );

		// RFC 2047 encode the subject so non-ASCII survives transport.
		$subject = $p['subject'];
		if ( preg_match( '/[^\x20-\x7E]/', $subject ) ) {
			$subject = '=?UTF-8?B?' . base64_encode( $subject ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$headers = array(
			'From: ' . $p['from'],
			'To: ' . $p['to'],
			'Reply-To: ' . $p['reply_to'],
			'Subject: ' . $subject,
			'MIME-Version: 1.0',
		);

		if ( ! empty( $p['unsub_url'] ) ) {
			$headers[] = 'List-Unsubscribe: <' . $p['unsub_url'] . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

		$mime  = implode( $eol, $headers ) . $eol . $eol;
		$mime .= '--' . $boundary . $eol;
		$mime .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
		$mime .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
		$mime .= chunk_split( base64_encode( $p['text'] ), 76, $eol ) . $eol; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$mime .= '--' . $boundary . $eol;
		$mime .= 'Content-Type: text/html; charset=UTF-8' . $eol;
		$mime .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
		$mime .= chunk_split( base64_encode( $p['html'] ), 76, $eol ) . $eol; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$mime .= '--' . $boundary . '--' . $eol;

		return $mime;
	}

	/**
	 * Sign the request with SigV4 and POST it to the SES endpoint.
	 *
	 * @param string $body     JSON request body.
	 * @param array  $settings Resolved plugin settings.
	 * @return array Result descriptor from self::result().
	 */
	private static function request( $body, array $settings ) {
		$region = $settings['aws_region'];
		$host   = self::host( $region );

		// SigV4 timestamps must be UTC and must match between the header and
		// the credential scope, so both come from the same call.
		$timestamp = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $timestamp );
		$date_only = gmdate( 'Ymd', $timestamp );

		$payload_hash = hash( 'sha256', $body );

		/*
		 * Step 1: canonical request.
		 *
		 * Headers must be lowercase, sorted, whitespace-trimmed, and the block
		 * terminates with a blank line. The query string is empty for this
		 * operation. Getting a single byte wrong here yields an opaque
		 * "SignatureDoesNotMatch" from AWS, so keep this literal.
		 */
		$canonical_headers = 'content-type:application/json' . "\n"
			. 'host:' . $host . "\n"
			. 'x-amz-content-sha256:' . $payload_hash . "\n"
			. 'x-amz-date:' . $amz_date . "\n";

		$signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

		$canonical_request = "POST\n"
			. self::PATH . "\n"
			. "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		// Step 2: string to sign.
		$credential_scope = $date_only . '/' . $region . '/' . self::SERVICE . '/aws4_request';

		$string_to_sign = self::ALGORITHM . "\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		// Step 3: derive the signing key (one HMAC per scope component).
		$k_date    = hash_hmac( 'sha256', $date_only, 'AWS4' . $settings['aws_secret_key'], true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', self::SERVICE, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		// Step 4: sign.
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$authorization = self::ALGORITHM
			. ' Credential=' . $settings['aws_access_key'] . '/' . $credential_scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		$response = wp_remote_post(
			self::endpoint( $region ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'httpversion' => '1.1',
				'sslverify'   => true,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'         => 'application/json',
					'Host'                 => $host,
					'X-Amz-Content-Sha256' => $payload_hash,
					'X-Amz-Date'           => $amz_date,
					'Authorization'        => $authorization,
					'Accept'               => 'application/json',
				),
				'body'        => $body,
				'user-agent'  => 'TechzappMailer/' . TZ_MAILER_VERSION . '; ' . home_url( '/' ),
			)
		);

		return self::parse_response( $response );
	}

	/**
	 * Turn a wp_remote_post() return value into a result descriptor.
	 *
	 * Retryability matters: the queue worker leaves retryable failures in the
	 * queue for the next cron tick but marks permanent failures as 'failed' so
	 * a malformed address cannot loop forever.
	 *
	 * @param array|WP_Error $response Raw wp_remote_post() return.
	 * @return array
	 */
	private static function parse_response( $response ) {
		// Transport-level failure: DNS, TLS, timeout. Always worth a retry.
		if ( is_wp_error( $response ) ) {
			return self::result(
				false,
				'',
				sprintf(
					/* translators: %s: transport error message */
					__( 'HTTP transport failure: %s', 'tz-mailer' ),
					$response->get_error_message()
				),
				'TransportError',
				0,
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			$message_id = is_array( $json ) && isset( $json['MessageId'] ) ? (string) $json['MessageId'] : '';

			return self::result( true, $message_id, '', '', $status, false );
		}

		// AWS returns the exception type either in the __type field or the
		// x-amzn-ErrorType header, depending on the error class.
		$error_code = '';

		if ( is_array( $json ) ) {
			if ( isset( $json['__type'] ) ) {
				$error_code = (string) $json['__type'];
			} elseif ( isset( $json['code'] ) ) {
				$error_code = (string) $json['code'];
			}
		}

		if ( '' === $error_code ) {
			$header = wp_remote_retrieve_header( $response, 'x-amzn-errortype' );

			if ( ! empty( $header ) ) {
				$error_code = (string) $header;
			}
		}

		// "com.amazonaws.ses#MessageRejected" -> "MessageRejected".
		if ( false !== strpos( $error_code, '#' ) ) {
			$parts      = explode( '#', $error_code );
			$error_code = end( $parts );
		}

		$message = '';

		if ( is_array( $json ) ) {
			foreach ( array( 'message', 'Message', 'errorMessage' ) as $key ) {
				if ( isset( $json[ $key ] ) && '' !== $json[ $key ] ) {
					$message = (string) $json[ $key ];
					break;
				}
			}
		}

		if ( '' === $message ) {
			$message = '' !== trim( $raw ) ? substr( $raw, 0, 500 ) : __( 'Empty error response from SES.', 'tz-mailer' );
		}

		return self::result(
			false,
			'',
			$message,
			'' !== $error_code ? $error_code : 'HTTP_' . $status,
			$status,
			self::is_retryable( $status, $error_code )
		);
	}

	/**
	 * Decide whether a failed send should stay in the queue for another try.
	 *
	 * @param int    $status     HTTP status code.
	 * @param string $error_code AWS exception type.
	 * @return bool
	 */
	private static function is_retryable( $status, $error_code ) {
		// Throttling and server-side faults are transient by definition.
		if ( 429 === $status || $status >= 500 ) {
			return true;
		}

		$transient = array(
			'TooManyRequestsException',
			'Throttling',
			'ThrottlingException',
			'ServiceUnavailableException',
			'InternalFailure',
			'RequestTimeout',
			'RequestExpired',
		);

		if ( in_array( $error_code, $transient, true ) ) {
			return true;
		}

		/*
		 * Everything else - MessageRejected, MailFromDomainNotVerified,
		 * AccountSuspendedException, SendingPausedException, bad signatures,
		 * malformed addresses - will fail identically on every retry. Marking
		 * them permanent keeps the queue moving.
		 */
		return false;
	}

	/**
	 * Whether an error code means the whole account can no longer send.
	 *
	 * The queue worker treats these as a reason to stop the entire batch
	 * rather than burn through the queue collecting identical failures.
	 *
	 * @param string $error_code AWS exception type.
	 * @return bool
	 */
	public static function is_account_level_failure( $error_code ) {
		$fatal = array(
			'AccountSuspendedException',
			'SendingPausedException',
			'NotConfigured',
			'InvalidClientTokenId',
			'SignatureDoesNotMatch',
			'UnrecognizedClientException',
			'AccessDeniedException',
			'MailFromDomainNotVerifiedException',
		);

		return in_array( (string) $error_code, $fatal, true );
	}

	/**
	 * Send a one-off test message, bypassing the queue and the campaign table.
	 *
	 * Used by the "Send test email" control on the composer and settings
	 * screens. Still writes to the audit log so tests are traceable.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject line.
	 * @param string $html    HTML body (merge tags already rendered).
	 * @return array Result descriptor.
	 */
	public static function send_test( $to, $subject, $html ) {
		$to = sanitize_email( $to );

		if ( ! is_email( $to ) ) {
			return self::result( false, '', __( 'Please provide a valid test recipient address.', 'tz-mailer' ), 'LocalValidation', 0, false );
		}

		// Test sends get a real, working unsubscribe link tied to the existing
		// subscriber record when one exists, so the full header set is
		// exercised exactly as a production send would be.
		$row   = TZ_DB::get_subscriber_by_email( $to );
		$token = $row ? $row['unsub_token'] : TZ_DB::generate_token();

		$context = TZ_Shortcodes::build_context(
			array(
				'name'        => $row ? $row['name'] : '',
				'email'       => $to,
				'unsub_token' => $token,
			)
		);

		$result = self::send(
			array(
				'to'              => $to,
				'subject'         => TZ_Shortcodes::render_text( $subject, $context ),
				'html'            => TZ_Shortcodes::render_html( $html, $context ),
				'unsubscribe_url' => $context['{unsubscribe_url}'],
			)
		);

		if ( $result['success'] ) {
			TZ_Logger::log( $to, 'Sent_Success', 'TEST SEND. SES MessageId: ' . $result['message_id'] );
		} else {
			TZ_Logger::log( $to, 'API_Error', 'TEST SEND FAILED [' . $result['error_code'] . '] ' . $result['error'] );
		}

		return $result;
	}
}
