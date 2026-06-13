<?php
/**
 * GitHub release updater for CSA WP User Tracker.
 *
 * @package CSA_WP_User_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds WordPress update data from GitHub releases.
 */
final class CSA_WP_User_Tracker_GitHub_Updater {
	const PLUGIN_SLUG = 'csa-wp-user-tracker';
	const ASSET_NAME  = 'csa-wp-user-tracker.zip';
	const REPO_URL    = 'https://github.com/ashburn2k/csa-wp-user-tracker';
	const REPO_API    = 'https://api.github.com/repos/ashburn2k/csa-wp-user-tracker';
	const CACHE_KEY   = 'csa_wp_user_tracker_github_release';

	/**
	 * Register update hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'add_update_data' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'add_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_private_package' ), 10, 4 );
	}

	/**
	 * Add a plugin update when GitHub has a newer release.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function add_update_data( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = (object) array();
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$plugin_file = self::plugin_file();
		if ( version_compare( $release['version'], CSA_WP_USER_TRACKER_VERSION, '>' ) ) {
			$transient->response[ $plugin_file ] = self::update_object( $release );
		} else {
			$transient->no_update[ $plugin_file ] = self::update_object( $release );
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * Return plugin details for the update modal.
	 *
	 * @param false|object|array $result Plugin API result.
	 * @param string             $action API action.
	 * @param object             $args API args.
	 * @return false|object|array
	 */
	public static function add_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'CSA WP User Tracker',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $release['version'],
			'author'        => 'Hui Zhang',
			'homepage'      => self::REPO_URL,
			'download_link' => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'tested'        => '6.8',
			'sections'      => array(
				'description' => '<p>Tracks activity for logged-in WordPress users whose roles are not limited to subscriber.</p>',
				'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>See the GitHub release for details.</p>',
			),
		);
	}

	/**
	 * Download private GitHub release assets with an optional token.
	 *
	 * @param false|WP_Error|string $reply Existing download result.
	 * @param string                $package Package URL.
	 * @param WP_Upgrader           $upgrader Upgrader.
	 * @param array                 $hook_extra Upgrade context.
	 * @return false|WP_Error|string
	 */
	public static function download_private_package( $reply, $package, $upgrader, $hook_extra = array() ) {
		unset( $upgrader, $hook_extra );

		$token = self::github_token();
		if ( false !== $reply || ! $token || 0 !== strpos( $package, self::REPO_API . '/releases/assets/' ) ) {
			return $reply;
		}

		$tmp_file = wp_tempnam( self::ASSET_NAME );
		if ( ! $tmp_file ) {
			return new WP_Error( 'csa_wp_user_tracker_temp_file_failed', 'Could not create a temporary file for the plugin update.' );
		}

		$response = wp_remote_get(
			$package,
			array(
				'headers'     => self::github_headers( true ),
				'timeout'     => 300,
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $tmp_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			@unlink( $tmp_file );
			return new WP_Error( 'csa_wp_user_tracker_download_failed', 'GitHub did not return the plugin update package.' );
		}

		return $tmp_file;
	}

	/**
	 * Get the latest GitHub release, cached briefly to avoid rate limits.
	 *
	 * @return array|null
	 */
	private static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) && ! empty( $cached ) ? $cached : null;
		}

		$release = self::fetch_latest_release();
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? 3 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

		return $release;
	}

	/**
	 * Fetch latest release metadata from GitHub.
	 *
	 * @return array|null
	 */
	private static function fetch_latest_release() {
		$response = wp_remote_get(
			self::REPO_API . '/releases/latest',
			array(
				'headers' => self::github_headers(),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['assets'] ) ) {
			return null;
		}

		$asset = self::release_asset( $data['assets'] );
		if ( ! $asset ) {
			return null;
		}

		$tag = sanitize_text_field( $data['tag_name'] );

		return array(
			'version' => ltrim( $tag, 'vV' ),
			'tag'     => $tag,
			'package' => self::package_url( $asset ),
			'url'     => self::REPO_URL . '/releases/tag/' . rawurlencode( $tag ),
			'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
		);
	}

	/**
	 * Find the release ZIP built by the workflow.
	 *
	 * @param array $assets Release assets.
	 * @return array|null
	 */
	private static function release_asset( $assets ) {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'] ) && self::ASSET_NAME === $asset['name'] ) {
				return $asset;
			}
		}

		return null;
	}

	/**
	 * Build the package URL.
	 *
	 * @param array $asset Release asset.
	 * @return string
	 */
	private static function package_url( $asset ) {
		if ( self::github_token() && ! empty( $asset['url'] ) ) {
			return esc_url_raw( $asset['url'] );
		}

		return ! empty( $asset['browser_download_url'] ) ? esc_url_raw( $asset['browser_download_url'] ) : '';
	}

	/**
	 * Build a WordPress plugin update object.
	 *
	 * @param array $release Release data.
	 * @return object
	 */
	private static function update_object( $release ) {
		return (object) array(
			'id'          => self::REPO_URL,
			'slug'        => self::PLUGIN_SLUG,
			'plugin'      => self::plugin_file(),
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => '6.8',
		);
	}

	/**
	 * Current plugin file path relative to the plugins directory.
	 *
	 * @return string
	 */
	private static function plugin_file() {
		return plugin_basename( CSA_WP_USER_TRACKER_FILE );
	}

	/**
	 * GitHub request headers.
	 *
	 * @param bool $download_asset Whether the request downloads a binary asset.
	 * @return array
	 */
	private static function github_headers( $download_asset = false ) {
		$headers = array(
			'Accept'               => $download_asset ? 'application/octet-stream' : 'application/vnd.github+json',
			'User-Agent'           => 'CSA-WP-User-Tracker/' . CSA_WP_USER_TRACKER_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = self::github_token();
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Optional token for private GitHub repositories.
	 *
	 * @return string
	 */
	private static function github_token() {
		$token_data = self::github_token_data();
		return $token_data['token'];
	}

	/**
	 * Optional token and its source, without exposing the token.
	 *
	 * @return array
	 */
	private static function github_token_data() {
		$token = '';
		$source = 'none';
		if ( defined( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN' ) && CSA_WP_USER_TRACKER_GITHUB_TOKEN ) {
			$token = CSA_WP_USER_TRACKER_GITHUB_TOKEN;
			$source = 'constant';
		} elseif ( function_exists( 'pantheon_get_secret' ) && pantheon_get_secret( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN' ) ) {
			$token = pantheon_get_secret( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN' );
			$source = 'pantheon_secret';
		} elseif ( getenv( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN' ) ) {
			$token = getenv( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN' );
			$source = 'environment';
		}

		$filtered_token = trim( (string) apply_filters( 'csa_wp_user_tracker_github_token', $token ) );
		if ( '' === $token && '' !== $filtered_token ) {
			$source = 'filter';
		}

		return array(
			'token'  => $filtered_token,
			'source' => $source,
		);
	}
}
