<?php
/**
 * GitHub-powered plugin updater.
 *
 * Makes this plugin behave like a wordpress.org plugin without being hosted
 * there: WordPress polls the GitHub Releases API, and when a release carries a
 * tag newer than the installed version the usual "update available" notice,
 * changelog modal and one-click update all work exactly as users expect.
 *
 * Publishing a new version is therefore just: bump the version header, commit,
 * tag, and create a GitHub release.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the GitHub Releases API into the core plugin update pipeline.
 */
class TZ_Updater {

	/** GitHub repository in owner/name form. */
	const REPO = 'appcoderikbal/plugin-crm';

	/** Transient holding the cached release payload. */
	const CACHE_KEY = 'tz_updater_release';

	/** How long a successful lookup is cached. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is cached.
	 *
	 * Failures are cached too, deliberately: the unauthenticated GitHub API
	 * allows 60 requests per hour per IP, and a site that retried on every
	 * admin page load would exhaust that in under a minute and then show
	 * spurious "no update" results for the rest of the hour.
	 */
	const FAILURE_TTL = 30 * MINUTE_IN_SECONDS;

	/** @var string Plugin basename, e.g. "tz-mailer/tz-mailer.php". */
	private $basename;

	/** @var string Directory slug, e.g. "tz-mailer". */
	private $slug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->basename = TZ_MAILER_BASENAME;
		$this->slug     = dirname( $this->basename );
	}

	/**
	 * Register every hook the update pipeline needs.
	 *
	 * @return void
	 */
	public function init() {
		// Inject our update into the list core builds when it polls for updates.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );

		// Populate the "View version details" modal.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		// GitHub archives extract to a differently named folder; fix that.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

		// Attach credentials to GitHub requests when a token is configured.
		add_filter( 'http_request_args', array( $this, 'authorize_request' ), 10, 2 );

		// Drop the cache after an update so the new version is picked up at once.
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );

		// "Check for updates" link on the Plugins screen.
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_tz_check_update', array( $this, 'handle_force_check' ) );
	}

	/* ---------------------------------------------------------------------
	 * Core update pipeline
	 * ------------------------------------------------------------------ */

	/**
	 * Add this plugin to the set of available updates.
	 *
	 * @param object $transient The update_plugins site transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Core sets `checked` only on a real update poll. Bailing when it is
		// empty avoids doing network work during unrelated transient writes.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $release['version'];

		if ( version_compare( $remote_version, TZ_MAILER_VERSION, '<=' ) ) {
			/*
			 * Up to date. Registering in no_update is what stops core from
			 * re-offering the update immediately after a successful upgrade,
			 * and is also what makes "View details" keep working.
			 */
			$transient->no_update[ $this->basename ] = $this->build_response( $release );

			return $transient;
		}

		$transient->response[ $this->basename ] = $this->build_response( $release );

		return $transient;
	}

	/**
	 * Build the object core expects for one plugin update entry.
	 *
	 * @param array $release Normalized release data.
	 * @return object
	 */
	private function build_response( array $release ) {
		$item = new stdClass();

		$item->id            = 'github.com/' . self::REPO;
		$item->slug          = $this->slug;
		$item->plugin        = $this->basename;
		$item->new_version   = $release['version'];
		$item->url           = 'https://github.com/' . self::REPO;
		$item->package       = $this->resolve_package( $release );
		$item->tested        = $release['tested'];
		$item->requires_php  = $release['requires_php'];
		$item->requires      = $release['requires'];
		$item->icons         = array();
		$item->banners       = array();
		$item->banners_rtl   = array();
		$item->compatibility = new stdClass();

		return $item;
	}

	/**
	 * Supply the data behind the "View details" modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		$info = new stdClass();

		$info->name           = 'Techzapp Mailer';
		$info->slug           = $this->slug;
		$info->version        = $release['version'];
		$info->author         = '<a href="https://techzapp.com/">Techzapp</a>';
		$info->homepage       = 'https://github.com/' . self::REPO;
		$package              = $this->resolve_package( $release );
		$info->download_link  = $package;
		$info->trunk          = $package;
		$info->requires       = $release['requires'];
		$info->requires_php   = $release['requires_php'];
		$info->tested         = $release['tested'];
		$info->last_updated   = $release['published_at'];
		$info->active_installs = false;

		$info->sections = array(
			'description' => $this->description_html(),
			'changelog'   => $this->changelog_html( $release ),
		);

		return $info;
	}

	/**
	 * Rename the extracted archive folder to the installed plugin folder.
	 *
	 * A GitHub source archive extracts to "owner-repo-<sha>", and a release
	 * asset may extract to anything at all. Without this, WordPress would
	 * install the update as a brand new plugin sitting alongside the old one
	 * and silently deactivate it.
	 *
	 * @param string      $source        Path to the extracted files.
	 * @param string      $remote_source Path to the downloaded archive.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments, identifying the plugin.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		// Only touch our own update.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		// Already correctly named: nothing to do.
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		/*
		 * A release asset built by the CI workflow already contains the plugin
		 * inside a correctly named folder, so the archive root holds a single
		 * directory. Descend into it before renaming.
		 */
		if ( $wp_filesystem->is_dir( $source ) ) {
			$entries = (array) $wp_filesystem->dirlist( $source );

			if ( 1 === count( $entries ) ) {
				$only = reset( $entries );

				if ( isset( $only['type'] ) && 'd' === $only['type'] && $this->slug === $only['name'] ) {
					return trailingslashit( $source ) . $only['name'];
				}
			}
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( $source, $desired ) ) {
			return new WP_Error(
				'tz_rename_failed',
				__( 'Could not rename the downloaded update folder. The update was aborted so your current installation stays intact.', 'tz-mailer' )
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * Attach an Authorization header to GitHub requests when a token is set.
	 *
	 * Required for private repositories, and it lifts the anonymous API rate
	 * limit from 60 to 5,000 requests an hour on busy multisite networks.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorize_request( $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		/*
		 * Scoped deliberately to api.github.com alone. When an asset download
		 * redirects to objects.githubusercontent.com the target is a pre-signed
		 * URL that carries its own credentials, and sending an Authorization
		 * header alongside it makes the request fail outright. cURL already
		 * drops the header on cross-host redirects, so the correct thing is to
		 * never attach it beyond the API host in the first place.
		 */
		if ( 'api.github.com' !== $host ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// An asset URL must ask for the binary; the default returns JSON metadata.
		if ( false !== strpos( $path, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		$token = trim( (string) TZ_Settings::get( 'github_token', '' ) );

		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $args;
	}

	/**
	 * Clear the cached release once an update finishes.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $data     Update context.
	 * @return void
	 */
	public function after_update( $upgrader, $data ) {
		unset( $upgrader );

		if ( ! isset( $data['action'], $data['type'] ) || 'update' !== $data['action'] || 'plugin' !== $data['type'] ) {
			return;
		}

		if ( isset( $data['plugins'] ) && is_array( $data['plugins'] ) && ! in_array( $this->basename, $data['plugins'], true ) ) {
			return;
		}

		$this->clear_cache();
	}

	/* ---------------------------------------------------------------------
	 * GitHub API
	 * ------------------------------------------------------------------ */

	/**
	 * Fetch the latest release, normalized and cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|false Normalized release data, or false on failure.
	 */
	public function get_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			// A cached failure is stored as a scalar so it is distinguishable
			// from "never looked".
			if ( false !== $cached ) {
				return false;
			}
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'TechzappMailer/' . TZ_MAILER_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'error', self::FAILURE_TTL );

			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'error', self::FAILURE_TTL );

			return false;
		}

		// Drafts and pre-releases must never be offered as an update.
		if ( ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			set_transient( self::CACHE_KEY, 'error', self::FAILURE_TTL );

			return false;
		}

		$release = $this->normalize( $body );

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Reduce a GitHub release payload to just what the updater needs.
	 *
	 * @param array $body Decoded API response.
	 * @return array
	 */
	private function normalize( array $body ) {
		$tag = (string) $body['tag_name'];

		// Tags are conventionally "v1.2.3"; versions are not.
		$version = ltrim( $tag, 'vV' );

		return array(
			'version'      => $version,
			'tag'          => $tag,
			'name'         => isset( $body['name'] ) ? (string) $body['name'] : $tag,
			'body'         => isset( $body['body'] ) ? (string) $body['body'] : '',
			'html_url'     => isset( $body['html_url'] ) ? esc_url_raw( $body['html_url'] ) : '',
			'published_at' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'packages'     => $this->collect_packages( $body ),
			'requires'     => '5.8',
			'requires_php' => '7.4',
			'tested'       => '6.6',
		);
	}

	/**
	 * Collect every candidate download URL from a release.
	 *
	 * All three are cached rather than pre-resolving one, because which URL is
	 * correct depends on whether a token is configured, and that can change
	 * after the release has already been cached.
	 *
	 * @param array $body Decoded API response.
	 * @return array{asset_api:string,asset_browser:string,zipball:string}
	 */
	private function collect_packages( array $body ) {
		$wanted = $this->slug . '.zip';

		$out = array(
			'asset_api'     => '',
			'asset_browser' => '',
			'zipball'       => isset( $body['zipball_url'] ) ? esc_url_raw( $body['zipball_url'] ) : '',
		);

		if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return $out;
		}

		foreach ( $body['assets'] as $asset ) {
			if ( ! isset( $asset['name'] ) || strtolower( $asset['name'] ) !== strtolower( $wanted ) ) {
				continue;
			}

			if ( isset( $asset['url'] ) ) {
				$out['asset_api'] = esc_url_raw( $asset['url'] );
			}

			if ( isset( $asset['browser_download_url'] ) ) {
				$out['asset_browser'] = esc_url_raw( $asset['browser_download_url'] );
			}

			break;
		}

		return $out;
	}

	/**
	 * Decide which URL WordPress should actually download.
	 *
	 * On a private repository the public browser_download_url returns 404 no
	 * matter what credentials are supplied; only the API asset endpoint works,
	 * and only with Accept: application/octet-stream. On a public repository
	 * the browser URL is preferable because it needs no credentials at all.
	 *
	 * @param array $release Normalized release data.
	 * @return string
	 */
	private function resolve_package( array $release ) {
		$packages = isset( $release['packages'] ) ? $release['packages'] : array();

		$has_token = ( '' !== trim( (string) TZ_Settings::get( 'github_token', '' ) ) );

		if ( $has_token && ! empty( $packages['asset_api'] ) ) {
			return $packages['asset_api'];
		}

		if ( ! empty( $packages['asset_browser'] ) ) {
			return $packages['asset_browser'];
		}

		return isset( $packages['zipball'] ) ? $packages['zipball'] : '';
	}

	/**
	 * Delete the cached release and force core to re-poll.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
	}

	/* ---------------------------------------------------------------------
	 * Admin surface
	 * ------------------------------------------------------------------ */

	/**
	 * Add a "Check for updates" link to the plugin row.
	 *
	 * @param array  $links Existing row meta links.
	 * @param string $file  Plugin file the row belongs to.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=tz_check_update' ),
			'tz_check_update'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'tz-mailer' ) . '</a>';

		return $links;
	}

	/**
	 * Handle the manual update check.
	 *
	 * @return void
	 */
	public function handle_force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to check for updates.', 'tz-mailer' ),
				esc_html__( 'Permission denied', 'tz-mailer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'tz_check_update' );

		$this->clear_cache();
		$release = $this->get_release( true );

		if ( ! $release ) {
			$message = __( 'Could not reach GitHub to check for updates. Try again shortly.', 'tz-mailer' );
			$type    = 'error';
		} elseif ( version_compare( $release['version'], TZ_MAILER_VERSION, '>' ) ) {
			$message = sprintf(
				/* translators: 1: available version, 2: installed version */
				__( 'Version %1$s is available. You are running %2$s. Update from the Plugins screen.', 'tz-mailer' ),
				$release['version'],
				TZ_MAILER_VERSION
			);
			$type = 'success';
		} else {
			$message = sprintf(
				/* translators: %s: installed version */
				__( 'Techzapp Mailer is up to date (version %s).', 'tz-mailer' ),
				TZ_MAILER_VERSION
			);
			$type = 'success';
		}

		set_transient(
			'tz_admin_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => esc_html( $message ),
			),
			60
		);

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Current update status, for the Settings screen.
	 *
	 * @return array{current:string,latest:string,available:bool,url:string,checked:bool,has_token:bool,hint:string}
	 */
	public function status() {
		$release = $this->get_release();

		$has_token = ( '' !== trim( (string) TZ_Settings::get( 'github_token', '' ) ) );

		$hint = '';

		if ( ! $release ) {
			$hint = $has_token
				? __( 'GitHub returned an error. Check that the token is valid and still has read access to the repository.', 'tz-mailer' )
				: __( 'No release was found. If the repository is private, update checks require a GitHub token with read access below.', 'tz-mailer' );
		}

		return array(
			'current'   => TZ_MAILER_VERSION,
			'latest'    => $release ? $release['version'] : '',
			'available' => (bool) ( $release && version_compare( $release['version'], TZ_MAILER_VERSION, '>' ) ),
			'url'       => $release ? $release['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'checked'   => (bool) $release,
			'has_token' => $has_token,
			'hint'      => $hint,
		);
	}

	/**
	 * Static description shown in the details modal.
	 *
	 * @return string
	 */
	private function description_html() {
		return '<p>' . esc_html__( 'Production-grade bulk mailer built directly on the AWS SES v2 API, with CSV import, a throttled cron queue worker, SNS bounce and complaint handling, RFC 8058 one-click unsubscribe, and a hard 2.0% bounce-rate circuit breaker that protects your SES account automatically.', 'tz-mailer' ) . '</p>';
	}

	/**
	 * Render the release notes as HTML for the changelog tab.
	 *
	 * The release body is Markdown authored on GitHub. It is escaped first and
	 * only then given a small, fixed set of formatting conversions, so nothing
	 * in a release note can inject markup into wp-admin.
	 *
	 * @param array $release Normalized release data.
	 * @return string
	 */
	private function changelog_html( array $release ) {
		$out = '<h4>' . esc_html( $release['name'] ) . '</h4>';

		$body = trim( $release['body'] );

		if ( '' === $body ) {
			$out .= '<p>' . esc_html__( 'No release notes were provided.', 'tz-mailer' ) . '</p>';
		} else {
			$safe = esc_html( $body );

			// Headings.
			$safe = preg_replace( '/^#{1,6}\s*(.+)$/m', '<strong>$1</strong>', $safe );
			// Bullets.
			$safe = preg_replace( '/^\s*[-*]\s+(.+)$/m', '<li>$1</li>', $safe );
			$safe = preg_replace( '#(<li>.*</li>)#s', '<ul>$1</ul>', $safe );
			// Inline code.
			$safe = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $safe );
			// Remaining line breaks.
			$safe = nl2br( $safe );

			$out .= '<div>' . $safe . '</div>';
		}

		if ( '' !== $release['html_url'] ) {
			$out .= '<p><a href="' . esc_url( $release['html_url'] ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'View this release on GitHub', 'tz-mailer' ) . '</a></p>';
		}

		return $out;
	}
}
