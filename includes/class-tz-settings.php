<?php
/**
 * Settings storage, defaults and sanitization.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every configurable value.
 *
 * All settings live in one autoloaded option array so a send only costs one
 * option read regardless of how many values the SES client needs.
 */
class TZ_Settings {

	/** @var array|null Request-level cache. */
	private static $cache = null;

	/**
	 * Default values for every supported key.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'aws_access_key'      => '',
			'aws_secret_key'      => '',
			'aws_region'          => 'us-east-1',
			'from_name'           => 'Techzapp',
			'from_email'          => 'updates@updates.techzapp.com',
			'reply_to_email'      => '',
			'default_name'        => 'there',
			'batch_size'          => 15,
			'send_delay'          => 3,
			'content_mode'        => 'simple',
			'configuration_set'   => '',
			'verify_sns'          => 1,
			'log_retention'       => 90,
			'delete_on_uninstall' => 0,
			'github_token'        => '',
		);
	}

	/**
	 * AWS regions where Amazon SES is available.
	 *
	 * Used to populate the suggestion list on the Settings screen. It is a
	 * convenience list, not a whitelist: AWS adds regions regularly, so the
	 * field accepts any correctly shaped region code and this list only drives
	 * autocomplete.
	 *
	 * @return array<string,string> Map of region code => human label.
	 */
	public static function regions() {
		return array(
			'us-east-1'      => 'US East (N. Virginia)',
			'us-east-2'      => 'US East (Ohio)',
			'us-west-1'      => 'US West (N. California)',
			'us-west-2'      => 'US West (Oregon)',
			'ca-central-1'   => 'Canada (Central)',
			'sa-east-1'      => 'South America (Sao Paulo)',
			'eu-west-1'      => 'Europe (Ireland)',
			'eu-west-2'      => 'Europe (London)',
			'eu-west-3'      => 'Europe (Paris)',
			'eu-central-1'   => 'Europe (Frankfurt)',
			'eu-central-2'   => 'Europe (Zurich)',
			'eu-north-1'     => 'Europe (Stockholm)',
			'eu-south-1'     => 'Europe (Milan)',
			'eu-south-2'     => 'Europe (Spain)',
			'il-central-1'   => 'Israel (Tel Aviv)',
			'me-south-1'     => 'Middle East (Bahrain)',
			'me-central-1'   => 'Middle East (UAE)',
			'af-south-1'     => 'Africa (Cape Town)',
			'ap-east-1'      => 'Asia Pacific (Hong Kong)',
			'ap-south-1'     => 'Asia Pacific (Mumbai)',
			'ap-south-2'     => 'Asia Pacific (Hyderabad)',
			'ap-northeast-1' => 'Asia Pacific (Tokyo)',
			'ap-northeast-2' => 'Asia Pacific (Seoul)',
			'ap-northeast-3' => 'Asia Pacific (Osaka)',
			'ap-southeast-1' => 'Asia Pacific (Singapore)',
			'ap-southeast-2' => 'Asia Pacific (Sydney)',
			'ap-southeast-3' => 'Asia Pacific (Jakarta)',
			'ap-southeast-4' => 'Asia Pacific (Melbourne)',
			'us-gov-east-1'  => 'AWS GovCloud (US-East)',
			'us-gov-west-1'  => 'AWS GovCloud (US-West)',
		);
	}

	/**
	 * Whether a string is shaped like an AWS region code.
	 *
	 * Deliberately validates the shape rather than membership of the list
	 * above, so a region AWS launches tomorrow works without a plugin update.
	 * Matches us-east-1, eu-north-1, ap-southeast-4, me-central-1 and
	 * us-gov-west-1 alike.
	 *
	 * @param string $region Candidate region code.
	 * @return bool
	 */
	public static function is_valid_region( $region ) {
		return (bool) preg_match( '/^[a-z]{2}(?:-[a-z]+){1,2}-[0-9]{1,2}$/', (string) $region );
	}

	/**
	 * Fetch the full settings array, merged over defaults.
	 *
	 * @param bool $refresh Bypass the request cache.
	 * @return array
	 */
	public static function all( $refresh = false ) {
		if ( null === self::$cache || $refresh ) {
			$stored = get_option( TZ_MAILER_OPT_SETTINGS, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$cache = wp_parse_args( $stored, self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * Fetch one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Persist the full settings array.
	 *
	 * @param array $settings Already-sanitized values.
	 * @return void
	 */
	public static function save( array $settings ) {
		update_option( TZ_MAILER_OPT_SETTINGS, $settings, true );
		self::$cache = null;
	}

	/**
	 * Sanitize a raw $_POST payload into a storable settings array.
	 *
	 * Every value is coerced to its expected type and range. Unknown keys are
	 * dropped entirely.
	 *
	 * @param array $raw Raw, unslashed input.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$current = self::all();
		$out     = self::defaults();

		// --- AWS credentials ---
		$out['aws_access_key'] = isset( $raw['aws_access_key'] )
			? sanitize_text_field( $raw['aws_access_key'] )
			: '';

		// The secret is rendered as a password field pre-filled with a mask.
		// If the submitted value is still the mask, keep whatever is stored.
		$submitted_secret = isset( $raw['aws_secret_key'] ) ? trim( (string) $raw['aws_secret_key'] ) : '';

		if ( '' === $submitted_secret || self::secret_mask() === $submitted_secret ) {
			$out['aws_secret_key'] = $current['aws_secret_key'];
		} else {
			$out['aws_secret_key'] = sanitize_text_field( $submitted_secret );
		}

		/*
		 * Region codes are lowercase letters, digits and hyphens. Rather than
		 * stripping stray characters - which silently turns a typo into a
		 * plausible-looking but wrong endpoint - anything not shaped like a
		 * region is rejected and the previous value is kept.
		 */
		$region = isset( $raw['aws_region'] ) ? strtolower( trim( sanitize_text_field( $raw['aws_region'] ) ) ) : '';

		if ( self::is_valid_region( $region ) ) {
			$out['aws_region'] = $region;
		} else {
			$out['aws_region'] = self::is_valid_region( $current['aws_region'] ) ? $current['aws_region'] : 'us-east-1';
		}

		// --- Sender identity ---
		$out['from_name'] = isset( $raw['from_name'] )
			? sanitize_text_field( $raw['from_name'] )
			: '';

		$from_email        = isset( $raw['from_email'] ) ? sanitize_email( $raw['from_email'] ) : '';
		$out['from_email'] = is_email( $from_email ) ? $from_email : '';

		$reply_to              = isset( $raw['reply_to_email'] ) ? sanitize_email( $raw['reply_to_email'] ) : '';
		$out['reply_to_email'] = is_email( $reply_to ) ? $reply_to : '';

		$out['default_name'] = isset( $raw['default_name'] ) && '' !== trim( (string) $raw['default_name'] )
			? sanitize_text_field( $raw['default_name'] )
			: 'there';

		// --- Throttling ---
		// Batch size is clamped hard: a 5 minute cron tick that tries to push
		// more than 200 messages would hit PHP max_execution_time first.
		$out['batch_size'] = isset( $raw['batch_size'] ) ? absint( $raw['batch_size'] ) : 15;
		$out['batch_size'] = max( 1, min( 200, $out['batch_size'] ) );

		$out['send_delay'] = isset( $raw['send_delay'] ) ? absint( $raw['send_delay'] ) : 3;
		$out['send_delay'] = max( 0, min( 30, $out['send_delay'] ) );

		// --- SES transport ---
		$mode                = isset( $raw['content_mode'] ) ? sanitize_text_field( $raw['content_mode'] ) : 'simple';
		$out['content_mode'] = in_array( $mode, array( 'simple', 'raw' ), true ) ? $mode : 'simple';

		$out['configuration_set'] = isset( $raw['configuration_set'] )
			? sanitize_text_field( $raw['configuration_set'] )
			: '';

		$out['verify_sns'] = ! empty( $raw['verify_sns'] ) ? 1 : 0;

		$out['log_retention'] = isset( $raw['log_retention'] ) ? absint( $raw['log_retention'] ) : 90;
		$out['log_retention'] = min( 3650, $out['log_retention'] );

		$out['delete_on_uninstall'] = ! empty( $raw['delete_on_uninstall'] ) ? 1 : 0;

		// --- Updates ---
		// Same masking treatment as the AWS secret: never echo a live token back
		// into the settings page HTML.
		$submitted_token = isset( $raw['github_token'] ) ? trim( (string) $raw['github_token'] ) : '';

		if ( '' === $submitted_token || self::secret_mask() === $submitted_token ) {
			$out['github_token'] = $current['github_token'];
		} else {
			$out['github_token'] = sanitize_text_field( $submitted_token );
		}

		return $out;
	}

	/**
	 * Placeholder rendered in the secret key field when a secret is stored.
	 *
	 * Keeps the real secret out of the HTML source of the settings screen.
	 *
	 * @return string
	 */
	public static function secret_mask() {
		return '********************';
	}

	/**
	 * Whether the plugin has everything it needs to talk to SES.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = self::all();

		return (
			'' !== $s['aws_access_key']
			&& '' !== $s['aws_secret_key']
			&& '' !== $s['aws_region']
			&& is_email( $s['from_email'] )
		);
	}

	/**
	 * Human readable list of what is still missing from Settings.
	 *
	 * @return array<int,string>
	 */
	public static function missing_requirements() {
		$s       = self::all();
		$missing = array();

		if ( '' === $s['aws_access_key'] ) {
			$missing[] = __( 'AWS Access Key ID', 'tz-mailer' );
		}
		if ( '' === $s['aws_secret_key'] ) {
			$missing[] = __( 'AWS Secret Access Key', 'tz-mailer' );
		}
		if ( ! is_email( $s['from_email'] ) ) {
			$missing[] = __( 'From Email (must be a verified SES identity)', 'tz-mailer' );
		}

		return $missing;
	}

	/**
	 * Public URL to paste into the AWS SNS subscription form.
	 *
	 * @return string
	 */
	public static function sns_webhook_url() {
		return rest_url( TZ_MAILER_REST_NS . '/sns-events' );
	}

	/**
	 * Base URL of the one-click unsubscribe endpoint.
	 *
	 * @return string
	 */
	public static function unsubscribe_base_url() {
		return rest_url( TZ_MAILER_REST_NS . '/unsub' );
	}

	/**
	 * Build the per-subscriber unsubscribe URL.
	 *
	 * @param string $token Subscriber unsub_token.
	 * @return string
	 */
	public static function unsubscribe_url( $token ) {
		return add_query_arg( 'token', rawurlencode( (string) $token ), self::unsubscribe_base_url() );
	}
}
