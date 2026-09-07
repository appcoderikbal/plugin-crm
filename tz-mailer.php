<?php
/**
 * Plugin Name:       Techzapp Mailer
 * Plugin URI:        https://techzapp.com/
 * Description:       Production-grade bulk mailer for WordPress built directly on the AWS SES v2 API (native SigV4, no AWS SDK). Includes CSV import, HTML campaign composer, throttled cron queue worker, SNS bounce/complaint webhook, RFC 8058 one-click unsubscribe and a hard 2.0% bounce-rate circuit breaker.
 * Version:           1.0.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Techzapp
 * Author URI:        https://techzapp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/appcoderikbal/plugin-crm
 * Text Domain:       tz-mailer
 * Domain Path:       /languages
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */

define( 'TZ_MAILER_VERSION', '1.0.4' );
define( 'TZ_MAILER_FILE', __FILE__ );
define( 'TZ_MAILER_PATH', plugin_dir_path( __FILE__ ) );
define( 'TZ_MAILER_URL', plugin_dir_url( __FILE__ ) );
define( 'TZ_MAILER_BASENAME', plugin_basename( __FILE__ ) );

/** Schema version. Bump to force a dbDelta re-run on upgrade. */
define( 'TZ_MAILER_DB_VERSION', '1.0.0' );

/** Name of the custom cron schedule used by the queue worker. */
define( 'TZ_MAILER_CRON_HOOK', 'tz_process_queue_event' );
define( 'TZ_MAILER_CRON_SCHEDULE', 'tz_every_five_minutes' );

/** Option keys. */
define( 'TZ_MAILER_OPT_SETTINGS', 'tz_mailer_settings' );
define( 'TZ_MAILER_OPT_BREAKER', 'tz_circuit_breaker_halted' );
define( 'TZ_MAILER_OPT_BREAKER_META', 'tz_circuit_breaker_meta' );
define( 'TZ_MAILER_OPT_DB_VERSION', 'tz_mailer_db_version' );

/** REST namespace. */
define( 'TZ_MAILER_REST_NS', 'tz/v1' );

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

require_once TZ_MAILER_PATH . 'includes/class-tz-db.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-logger.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-settings.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-activator.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-shortcodes.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-circuit-breaker.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-ses.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-subscribers.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-csv.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-campaigns.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-queue.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-rest.php';
require_once TZ_MAILER_PATH . 'includes/class-tz-updater.php';
require_once TZ_MAILER_PATH . 'admin/class-tz-admin.php';

/**
 * Main plugin container.
 *
 * Wires every subsystem onto WordPress hooks. Kept deliberately thin: all real
 * behaviour lives in the dedicated classes so each piece stays unit-testable.
 */
final class TZ_Mailer {

	/** @var TZ_Mailer|null Singleton instance. */
	private static $instance = null;

	/** @var TZ_Queue Queue worker. */
	public $queue;

	/** @var TZ_REST REST controllers (SNS webhook + unsubscribe). */
	public $rest;

	/** @var TZ_Admin Admin UI controller. */
	public $admin;

	/** @var TZ_Updater GitHub release updater. */
	public $updater;

	/**
	 * Retrieve the singleton.
	 *
	 * @return TZ_Mailer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Private-by-convention: use TZ_Mailer::instance().
	 */
	private function __construct() {
		$this->queue = new TZ_Queue();
		$this->rest  = new TZ_REST();
		$this->admin   = new TZ_Admin();
		$this->updater = new TZ_Updater();

		$this->register_hooks();
	}

	/**
	 * Attach every subsystem to WordPress.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Translations.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Run pending schema upgrades when the plugin file version moves ahead.
		add_action( 'plugins_loaded', array( 'TZ_Activator', 'maybe_upgrade' ), 5 );

		// Custom five minute cron schedule + the worker itself.
		add_filter( 'cron_schedules', array( $this->queue, 'register_cron_schedule' ) );
		add_action( TZ_MAILER_CRON_HOOK, array( $this->queue, 'run' ) );

		// REST endpoints: /tz/v1/sns-events and /tz/v1/unsub.
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		// Admin menus, screens, assets, notices and POST handlers.
		$this->admin->init();

		// GitHub release checks feeding the core update pipeline.
		$this->updater->init();

		// Convenience "Settings" link on the Plugins screen.
		add_filter( 'plugin_action_links_' . TZ_MAILER_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'tz-mailer', false, dirname( TZ_MAILER_BASENAME ) . '/languages' );
	}

	/**
	 * Add a Settings shortcut to the plugin row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=tz-mailer-settings' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tz-mailer' ) . '</a>'
		);

		return $links;
	}
}

/* -------------------------------------------------------------------------
 * Lifecycle hooks
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, array( 'TZ_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TZ_Activator', 'deactivate' ) );

/**
 * Global accessor.
 *
 * @return TZ_Mailer
 */
function tz_mailer() {
	return TZ_Mailer::instance();
}

// Boot.
tz_mailer();
