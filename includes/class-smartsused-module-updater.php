<?php
/**
 * Private update integration for SmartSused Core and add-ons.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks licensed SmartSused updates and runs authorized WordPress upgrades.
 */
final class SmartSused_Module_Updater {

	const SERVER_URL = 'https://licencia.chudy.online/wp-json/smartsused-license/v1';

	/**
	 * Supported add-on slugs mapped to their plugin entry files and license modules.
	 *
	 * @var array
	 */
	const MODULES = array(
		'sprava-bytov'                 => array( 'plugin' => 'sprava-bytov/smartsused-core.php', 'license' => null ),
		'smartsused-notices'            => array( 'plugin' => 'smartsused-notices/smartsused-notices.php', 'license' => 'notices' ),
		'smartsused-helpdesk'           => array( 'plugin' => 'smartsused-helpdesk/smartsused-helpdesk.php', 'license' => 'helpdesk' ),
		'smartsused-technical-passport' => array( 'plugin' => 'smartsused-technical-passport/smartsused-technical-passport.php', 'license' => 'revision' ),
		'smartsused-polls'              => array( 'plugin' => 'smartsused-polls/smartsused-polls.php', 'license' => 'polls' ),
	);

	/**
	 * Register native update integration and the privileged React-facing REST route.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_plugin_updates' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Check all installed SmartSused add-ons during the native plugin update check.
	 *
	 * @param object $transient Plugin update transient.
	 * @return object
	 */
	public function filter_plugin_updates( $transient ) {
		if ( ! is_object( $transient ) || ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		foreach ( self::MODULES as $slug => $module ) {
			$plugin_file = $module['plugin'];
			if ( ! isset( $transient->checked[ $plugin_file ] ) || ! is_file( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				continue;
			}

			$update = $this->request_update_info( $slug, $transient->checked[ $plugin_file ] );
			if ( is_wp_error( $update ) ) {
				error_log( 'SmartSused updater: update check failed for ' . $slug . ' (' . $update->get_error_code() . ').' );
				continue;
			}

			if ( ! $update || version_compare( $update['version'], $transient->checked[ $plugin_file ], '<=' ) ) {
				unset( $transient->response[ $plugin_file ] );
				continue;
			}

			$transient->response[ $plugin_file ] = (object) array(
				'slug'        => $slug,
				'plugin'      => $plugin_file,
				'new_version' => $update['version'],
				'url'         => untrailingslashit( self::SERVER_URL ),
				'package'     => $update['package'],
			);
		}

		return $transient;
	}

	/**
	 * Supply the changelog in WordPress's plugin-information dialog.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $args   Plugin information arguments.
	 * @return mixed
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		if (
			'plugin_information' !== $action
			|| ! is_object( $args )
			|| ! isset( $args->slug )
			|| ! is_string( $args->slug )
			|| ! isset( self::MODULES[ $args->slug ] )
		) {
			return $result;
		}

		$plugin_file = self::MODULES[ $args->slug ]['plugin'];
		$version     = $this->get_installed_version( $plugin_file );
		if ( '' === $version ) {
			return $result;
		}

		$update = $this->request_update_info( $args->slug, $version );
		if ( is_wp_error( $update ) ) {
			error_log( 'SmartSused updater: plugin details failed for ' . $args->slug . ' (' . $update->get_error_code() . ').' );
			return $result;
		}
		if ( ! $update ) {
			return $result;
		}

		return (object) array(
			'name'          => $args->slug,
			'slug'          => $args->slug,
			'version'       => $update['version'],
			'author'        => '<a href="https://chudy.online/">SmartSused</a>',
			'homepage'      => untrailingslashit( self::SERVER_URL ),
			'sections'      => array( 'changelog' => nl2br( esc_html( $update['changelog'] ) ) ),
			'download_link' => $update['package'],
		);
	}

	/**
	 * Register the administrator-only on-demand upgrade route for the React client.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/modules/core-update',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_core_update' ),
				'permission_callback' => array( $this, 'can_update_modules' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/modules/versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_module_versions' ),
				'permission_callback' => array( $this, 'can_update_modules' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/modules/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_module' ),
				'permission_callback' => array( $this, 'can_update_modules' ),
				'args'                => array(
					'slug' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array_keys( self::MODULES ),
					),
				),
			)
		);
	}

	/**
	 * Return installed versions for supported add-ons.
	 *
	 * @return WP_REST_Response
	 */
	public function get_module_versions() {
		$versions = array();
		foreach ( self::MODULES as $slug => $module ) {
			if ( 'sprava-bytov' === $slug ) {
				continue;
			}

			$version            = $this->get_installed_version( $module['plugin'] );
			$versions[ $slug ] = '' !== $version ? $version : null;
		}

		return new WP_REST_Response( array( 'versions' => $versions ), 200 );
	}

	/**
	 * Return the installed and latest Core versions for the administrator dashboard.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_core_update() {
		$plugin_file = self::MODULES['sprava-bytov']['plugin'];
		$current     = $this->get_installed_version( $plugin_file );

		if ( '' === $current ) {
			return new WP_Error(
				'smartsused_core_not_installed',
				__( 'Súbor SmartSused Core sa v očakávanom umiestnení nenašiel.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		$update = $this->request_update_info( 'sprava-bytov', $current, true );
		if ( is_wp_error( $update ) ) {
			return $update;
		}

		return new WP_REST_Response(
			array(
				'current_version'  => $current,
				'latest_version'   => $update ? $update['version'] : $current,
				'update_available' => (bool) $update && version_compare( $update['version'], $current, '>' ),
				'changelog'        => $update ? $update['changelog'] : '',
			),
			200
		);
	}

	/**
	 * Require a WordPress administrator and verify a supplied SmartSused JWT.
	 *
	 * Cookie-authenticated WordPress REST requests are nonce-checked by core.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function can_update_modules( $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error(
				'smartsused_module_update_forbidden',
				__( 'Aktualizácie modulov môže spustiť iba administrátor WordPressu.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$authorization = $request->get_header( 'authorization' );
		if ( is_string( $authorization ) && preg_match( '/^Bearer\s+\S+$/i', $authorization ) ) {
			$user        = wp_get_current_user();
			$verified_id = apply_filters( 'smartsused_verified_jwt_user_id', 0, $request );
			if ( ! is_int( $verified_id ) || ! ( $user instanceof WP_User ) || $verified_id !== (int) $user->ID ) {
				return new WP_Error(
					'smartsused_module_update_unauthorized',
					__( 'Prihlásenie sa nepodarilo overiť.', 'smartsused-core' ),
					array( 'status' => 401 )
				);
			}
		}

		return true;
	}

	/**
	 * Run the native WordPress Plugin_Upgrader for one allowlisted add-on.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_module( $request ) {
		$slug = $request->get_param( 'slug' );
		if ( ! is_string( $slug ) || ! isset( self::MODULES[ $slug ] ) ) {
			return new WP_Error( 'smartsused_module_update_invalid', __( 'Požadovaný modul nie je podporovaný.', 'smartsused-core' ), array( 'status' => 400 ) );
		}

		$plugin_file = self::MODULES[ $slug ]['plugin'];
		$version     = $this->get_installed_version( $plugin_file );
		if ( '' === $version ) {
			return new WP_Error( 'smartsused_module_not_installed', __( 'Modul nie je v tejto inštalácii nainštalovaný.', 'smartsused-core' ), array( 'status' => 404 ) );
		}
		$was_active         = is_plugin_active( $plugin_file );
		$was_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

		$update = $this->request_update_info( $slug, $version );
		if ( is_wp_error( $update ) ) {
			return $update;
		}
		if ( ! $update || version_compare( $update['version'], $version, '<=' ) ) {
			return new WP_REST_Response(
				array( 'updated' => false, 'version' => $version, 'message' => __( 'Modul je už aktuálny.', 'smartsused-core' ) ),
				200
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $plugin_file ] = (object) array(
			'slug'        => $slug,
			'plugin'      => $plugin_file,
			'new_version' => $update['version'],
			'url'         => untrailingslashit( self::SERVER_URL ),
			'package'     => $update['package'],
		);
		set_site_transient( 'update_plugins', $transient );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin_file );
		if ( is_wp_error( $result ) ) {
			$activation = $this->restore_plugin_activation( $plugin_file, $was_active, $was_network_active );
			$message    = sprintf(
				/* translators: %s: WordPress upgrader error. */
				__( 'Aktualizácia modulu zlyhala: %s', 'smartsused-core' ),
				$result->get_error_message()
			);
			if ( is_wp_error( $activation ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: WordPress activation error. */
					__( 'Obnovenie aktivácie zlyhalo: %s', 'smartsused-core' ),
					$activation->get_error_message()
				);
			}

			return new WP_Error(
				'smartsused_module_update_failed',
				$message,
				array( 'status' => 500 )
			);
		}
		if ( true !== $result ) {
			$activation = $this->restore_plugin_activation( $plugin_file, $was_active, $was_network_active );
			$message    = __( 'WordPress nedokončil aktualizáciu modulu.', 'smartsused-core' );
			if ( is_wp_error( $activation ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: WordPress activation error. */
					__( 'Obnovenie aktivácie zlyhalo: %s', 'smartsused-core' ),
					$activation->get_error_message()
				);
			}

			return new WP_Error(
				'smartsused_module_update_failed',
				$message,
				array( 'status' => 500 )
			);
		}

		$installed_version = $this->get_installed_version( $plugin_file );
		$activation        = $this->restore_plugin_activation( $plugin_file, $was_active, $was_network_active );
		if ( is_wp_error( $activation ) ) {
			return new WP_Error(
				'smartsused_module_reactivation_failed',
				sprintf(
					/* translators: 1: installed version, 2: WordPress activation error. */
					__( 'Modul bol aktualizovaný na verziu %1$s, ale jeho opätovná aktivácia zlyhala: %2$s', 'smartsused-core' ),
					'' !== $installed_version ? $installed_version : __( 'neznáma', 'smartsused-core' ),
					$activation->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		if ( $update['version'] !== $installed_version ) {
			return new WP_Error(
				'smartsused_module_update_version_mismatch',
				sprintf(
					/* translators: 1: expected version, 2: installed version. */
					__( 'Balík uvádzal verziu %1$s, ale po aktualizácii je nainštalovaná verzia %2$s. Skontrolujte hlavičku pluginu v ZIP balíku.', 'smartsused-core' ),
					$update['version'],
					'' !== $installed_version ? $installed_version : __( 'neznáma', 'smartsused-core' )
				),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'updated' => true,
				'slug'    => $slug,
				'version' => $installed_version,
				'message' => __( 'Modul bol úspešne aktualizovaný.', 'smartsused-core' ),
			),
			200
		);
	}

	/**
	 * Restore the previous plugin activation state after an upgrade attempt.
	 *
	 * @param string $plugin_file      Plugin basename.
	 * @param bool   $was_active       Whether the plugin was active before the update.
	 * @param bool   $was_network_active Whether the plugin was network-active before the update.
	 * @return true|WP_Error
	 */
	private function restore_plugin_activation( $plugin_file, $was_active, $was_network_active ) {
		if ( ! $was_active || is_plugin_active( $plugin_file ) ) {
			return true;
		}

		$activation = activate_plugin( $plugin_file, '', $was_network_active );
		return is_wp_error( $activation ) ? $activation : true;
	}

	/**
	 * Request signed-in-license update metadata and a one-time HTTPS package URL.
	 *
	 * @param string $slug            Module slug.
	 * @param string $current_version Installed version.
	 * @param bool   $metadata_only   Whether to avoid issuing a download token.
	 * @return array|false|WP_Error
	 */
	private function request_update_info( $slug, $current_version, $metadata_only = false ) {
		$license_key = get_option( 'smartsused_license_key', '' );
		if ( ! is_string( $license_key ) || '' === trim( $license_key ) || strlen( $license_key ) > 256 ) {
			return new WP_Error( 'smartsused_update_license_missing', __( 'Licenčný kľúč nie je nakonfigurovaný.', 'smartsused-core' ) );
		}

		$license = smartsused_get_jwt_secret();
		if ( is_wp_error( $license ) ) {
			return $license;
		}
		$license_data = get_option( 'smartsused_license_data', array() );
		$module       = self::MODULES[ $slug ]['license'];
		if (
			null !== $module
			&& (
				! is_array( $license_data )
				|| ! isset( $license_data['modules'][ $module ]['enabled'] )
				|| true !== $license_data['modules'][ $module ]['enabled']
			)
		) {
			return new WP_Error( 'smartsused_update_module_unlicensed', __( 'Tento modul nie je povolený v licencii.', 'smartsused-core' ), array( 'status' => 403 ) );
		}

		$domain = wp_parse_url( site_url(), PHP_URL_HOST );
		if ( ! is_string( $domain ) || '' === $domain ) {
			return new WP_Error( 'smartsused_update_domain_invalid', __( 'Doménu webu sa nepodarilo určiť.', 'smartsused-core' ) );
		}
		$domain = strtolower( $domain );
		if ( 0 === strpos( $domain, 'www.' ) ) {
			$domain = substr( $domain, 4 );
		}

		$query_args = array(
			'domain'          => $domain,
			'slug'            => $slug,
			'current_version' => $current_version,
		);
		if ( $metadata_only ) {
			$query_args['metadata_only'] = true;
		}
		$url = add_query_arg( $query_args, self::SERVER_URL . '/update-check' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 32768,
				'headers'             => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . trim( $license_key ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'smartsused_update_server_unavailable', __( 'Licenčný server aktualizácií nie je dostupný.', 'smartsused-core' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'smartsused_update_check_failed', __( 'Licenčný server odmietol kontrolu aktualizácie.', 'smartsused-core' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['update_available'] ) || ! is_bool( $data['update_available'] ) ) {
			return new WP_Error( 'smartsused_update_response_invalid', __( 'Licenčný server vrátil neplatné údaje aktualizácie.', 'smartsused-core' ) );
		}
		if ( false === $data['update_available'] ) {
			return false;
		}

		if (
			! isset( $data['version'], $data['changelog'] )
			|| ! is_string( $data['version'] )
			|| ! is_string( $data['changelog'] )
			|| (
				! $metadata_only
				&& (
					! isset( $data['package'] )
					|| ! is_string( $data['package'] )
					|| ! $this->is_trusted_package_url( $data['package'] )
				)
			)
		) {
			return new WP_Error( 'smartsused_update_response_invalid', __( 'Licenčný server vrátil neplatný balík aktualizácie.', 'smartsused-core' ) );
		}

		return array(
			'version'   => $data['version'],
			'package'   => isset( $data['package'] ) && is_string( $data['package'] ) ? $data['package'] : '',
			'changelog' => $data['changelog'],
		);
	}

	/**
	 * Permit only the expected HTTPS download endpoint on the configured server.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	private function is_trusted_package_url( $url ) {
		$parts = wp_parse_url( $url );
		$server_host = wp_parse_url( self::SERVER_URL, PHP_URL_HOST );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'], $parts['path'], $parts['query'] )
			&& is_string( $server_host )
			&& 'https' === strtolower( $parts['scheme'] )
			&& strtolower( $server_host ) === strtolower( $parts['host'] )
			&& '/wp-json/smartsused-license/v1/download-package' === $parts['path']
			&& 1 === preg_match( '/(?:^|&)token=[a-f0-9]{64}(?:&|$)/', $parts['query'] )
			&& ! isset( $parts['user'], $parts['pass'], $parts['fragment'] );
	}

	/**
	 * Read an installed add-on version from its plugin header.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	private function get_installed_version( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );
		return isset( $data['Version'] ) && is_string( $data['Version'] ) ? $data['Version'] : '';
	}
}
