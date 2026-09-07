<?php
/**
 * Merge-tag (shortcode) replacement engine for campaign bodies.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces {name}, {email} and {unsubscribe_url} inside subject and body.
 *
 * Two escaping contexts are handled separately: values injected into the HTML
 * body run through esc_html()/esc_url(), values injected into the plain text
 * alternative are left raw. Getting this wrong is how merge tags become an
 * HTML injection vector, so the two paths never share code.
 */
class TZ_Shortcodes {

	/**
	 * Supported tags with human descriptions, for the composer UI.
	 *
	 * @return array<string,string>
	 */
	public static function available_tags() {
		return array(
			'{name}'            => __( 'Subscriber first name. Falls back to the default name in Settings when blank.', 'tz-mailer' ),
			'{email}'           => __( 'Subscriber email address.', 'tz-mailer' ),
			'{unsubscribe_url}' => __( 'Unique one-click unsubscribe URL. REQUIRED in every campaign.', 'tz-mailer' ),
		);
	}

	/**
	 * Build the replacement map for one subscriber.
	 *
	 * @param array $subscriber Row from tz_subscribers (needs email, name, unsub_token).
	 * @return array<string,string> Raw (unescaped) replacement values.
	 */
	public static function build_context( array $subscriber ) {
		$default_name = (string) TZ_Settings::get( 'default_name', 'there' );

		$name = isset( $subscriber['name'] ) ? trim( (string) $subscriber['name'] ) : '';

		if ( '' === $name ) {
			$name = $default_name;
		}

		$email = isset( $subscriber['email'] ) ? (string) $subscriber['email'] : '';
		$token = isset( $subscriber['unsub_token'] ) ? (string) $subscriber['unsub_token'] : '';

		return array(
			'{name}'            => $name,
			'{email}'           => $email,
			'{unsubscribe_url}' => TZ_Settings::unsubscribe_url( $token ),
		);
	}

	/**
	 * Render a template for the HTML body.
	 *
	 * Subscriber-supplied values are escaped for HTML; the unsubscribe URL is
	 * escaped as a URL so it survives use in both href attributes and text.
	 *
	 * @param string $template Raw campaign HTML containing merge tags.
	 * @param array  $context  Output of build_context().
	 * @return string
	 */
	public static function render_html( $template, array $context ) {
		$search  = array();
		$replace = array();

		foreach ( $context as $tag => $value ) {
			$search[] = $tag;

			if ( '{unsubscribe_url}' === $tag ) {
				$replace[] = esc_url( $value );
			} else {
				$replace[] = esc_html( $value );
			}
		}

		return str_replace( $search, $replace, (string) $template );
	}

	/**
	 * Render a template for a plain text context (subject line, text part).
	 *
	 * @param string $template Raw template containing merge tags.
	 * @param array  $context  Output of build_context().
	 * @return string
	 */
	public static function render_text( $template, array $context ) {
		$out = str_replace( array_keys( $context ), array_values( $context ), (string) $template );

		// Subject lines must never contain CR/LF: that is a header injection.
		return str_replace( array( "\r", "\n" ), ' ', $out );
	}

	/**
	 * Derive a readable plain-text alternative from the rendered HTML body.
	 *
	 * Anchors are expanded to "label <url>" so the unsubscribe link stays
	 * usable for recipients reading the text part.
	 *
	 * @param string $html Rendered HTML body.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$text = (string) $html;

		// Drop non-content elements entirely, including their contents.
		$text = preg_replace( '#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $text );

		// Preserve link targets.
		$text = preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$label = trim( wp_strip_all_tags( $m[2] ) );
				$url   = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );

				if ( '' === $label || $label === $url ) {
					return $url;
				}

				/*
				 * Parentheses, not angle brackets: the wp_strip_all_tags() call
				 * further down would treat "<https://...>" as a tag and delete
				 * the URL, silently stripping the unsubscribe link out of the
				 * plain text alternative.
				 */
				return $label . ' (' . $url . ')';
			},
			$text
		);

		// Turn block level breaks into newlines before stripping the rest.
		$text = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Collapse the run of blank lines the stripping leaves behind.
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Whether a campaign body contains the mandatory unsubscribe tag.
	 *
	 * @param string $body Campaign HTML.
	 * @return bool
	 */
	public static function has_unsubscribe_tag( $body ) {
		return ( false !== strpos( (string) $body, '{unsubscribe_url}' ) );
	}

	/**
	 * Render a preview using a fake subscriber, for the composer screen.
	 *
	 * @param string $body Campaign HTML.
	 * @return string
	 */
	public static function preview( $body ) {
		$context = self::build_context(
			array(
				'name'        => __( 'Jane', 'tz-mailer' ),
				'email'       => 'jane.doe@example.com',
				'unsub_token' => str_repeat( 'a1b2', 8 ),
			)
		);

		return self::render_html( $body, $context );
	}
}
