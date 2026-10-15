<?php
/**
 * Front-facing SmartSused application and license settings.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers license settings and renders the public SmartSused application.
 */
final class SmartSused_Admin {

	/**
	 * License settings page slug.
	 *
	 * @var string
	 */
	const LICENSE_SETTINGS_SLUG = 'smartsused-license-settings';

	/**
	 * Register admin hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'render_frontend_app' ), 0 );
	}

	/**
	 * Add the license settings page without embedding React in wp-admin.
	 */
	public function register_license_menu() {
		add_menu_page(
			__( 'Licencia SmartSused', 'smartsused-core' ),
			__( 'Licencia', 'smartsused-core' ),
			'manage_options',
			self::LICENSE_SETTINGS_SLUG,
			array( $this, 'render_license_settings' ),
			'dashicons-admin-network',
			4
		);
	}

	/**
	 * Save the client license key and activate it with the central server.
	 */
	public function save_license_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Na zmenu licencie nemáte dostatočné oprávnenia.', 'smartsused-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'smartsused_activate_license' );

		$license_key = isset( $_POST['smartsused_license_key'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['smartsused_license_key'] ) ) )
			: '';

		if ( '' === $license_key || strlen( $license_key ) > 256 ) {
			$this->set_license_notice(
				'error',
				__( 'Zadajte platný licenčný kľúč.', 'smartsused-core' )
			);
			$this->redirect_to_license_settings();
		}

		update_option( 'smartsused_license_key', $license_key, false );
		delete_option( 'smartsused_jwt_secret' );
		delete_option( 'smartsused_jwt_secret_expires_at' );
		delete_option( 'smartsused_license_data' );

		$secret = smartsused_get_jwt_secret( true );

		if ( is_wp_error( $secret ) ) {
			$this->set_license_notice( 'error', $secret->get_error_message() );
		} else {
			$this->set_license_notice(
				'success',
				__( 'Licencia bola úspešne overená a aktivovaná.', 'smartsused-core' )
			);
		}

		$this->redirect_to_license_settings();
	}

	/**
	 * Render the license settings form.
	 */
	public function render_license_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Na zobrazenie tejto stránky nemáte dostatočné oprávnenia.', 'smartsused-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		$license_key = get_option( 'smartsused_license_key', '' );
		$expires_at  = get_option( 'smartsused_jwt_secret_expires_at', '' );
		$notice      = get_transient( $this->get_license_notice_key() );

		if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) {
			delete_transient( $this->get_license_notice_key() );
			$notice_class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			?>
			<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licencia SmartSused', 'smartsused-core' ); ?></h1>
			<p><?php esc_html_e( 'Zadajte licenčný kľúč pridelený správcom SmartSused. Kľúč sa bezpečne uloží v databáze WordPressu a overí sa online.', 'smartsused-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="smartsused_activate_license">
				<?php wp_nonce_field( 'smartsused_activate_license' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="smartsused-license-key"><?php esc_html_e( 'Licenčný kľúč', 'smartsused-core' ); ?></label>
						</th>
						<td>
							<input
								id="smartsused-license-key"
								name="smartsused_license_key"
								type="password"
								class="regular-text"
								value=""
								autocomplete="new-password"
								required
								maxlength="256"
								placeholder="<?php echo esc_attr( '' !== $license_key ? __( 'Kľúč je uložený. Zadajte ho znova na opätovnú aktiváciu.', 'smartsused-core' ) : __( 'Napr. SS-ABCD-1234-XYZ', 'smartsused-core' ) ); ?>"
							>
						</td>
					</tr>
					<?php if ( is_string( $expires_at ) && '' !== $expires_at ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Platnosť licencie do', 'smartsused-core' ); ?></th>
							<td><?php echo esc_html( $expires_at ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Uložiť a aktivovať', 'smartsused-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Store a short-lived license activation notice for the current user.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	private function set_license_notice( $type, $message ) {
		set_transient(
			$this->get_license_notice_key(),
			array(
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Return the current user's notice transient key.
	 *
	 * @return string
	 */
	private function get_license_notice_key() {
		return 'smartsused_license_client_notice_' . get_current_user_id();
	}

	/**
	 * Redirect to the license settings page and stop execution.
	 */
	private function redirect_to_license_settings() {
		wp_safe_redirect(
			add_query_arg(
				'page',
				self::LICENSE_SETTINGS_SLUG,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the React shell instead of loading a public WordPress theme template.
	 */
	public function render_frontend_app() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$script_path = SMARTSUSED_CORE_PATH . 'assets/build/smartsused-admin.js';
		$style_path  = SMARTSUSED_CORE_PATH . 'assets/build/smartsused-admin.css';

		if ( ! is_readable( $script_path ) || ! is_readable( $style_path ) ) {
			status_header( 503 );
			wp_die(
				esc_html__( 'Aplikáciu SmartSused sa nepodarilo načítať, pretože chýbajú produkčné súbory. Zostavte frontend príkazom npm run build.', 'smartsused-core' ),
				esc_html__( 'SmartSused nie je dostupný', 'smartsused-core' ),
				array( 'response' => 503 )
			);
		}

		$script_handle = 'smartsused-frontend-app';
		$style_handle  = 'smartsused-frontend-app';

		wp_enqueue_style(
			$style_handle,
			SMARTSUSED_CORE_URL . 'assets/build/smartsused-admin.css',
			array(),
			(string) filemtime( $style_path )
		);
		wp_enqueue_script(
			$script_handle,
			SMARTSUSED_CORE_URL . 'assets/build/smartsused-admin.js',
			array(),
			(string) filemtime( $script_path ),
			false
		);
		wp_localize_script(
			$script_handle,
			'smartsusedConfig',
			array(
				'google_client_id' => SmartSused_Google_Config::get_client_id(),
				'rest_url'         => esc_url_raw( rest_url() ),
			)
		);

		status_header( 200 );
		nocache_headers();

		echo '<!doctype html><html ' . get_language_attributes() . '><head>';
		echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="theme-color" content="#f8fafc">';
		echo '<title>' . esc_html__( 'SmartSused', 'smartsused-core' ) . '</title>';
		wp_print_styles( array( $style_handle ) );
		echo '</head><body><div id="smartsused-root"></div>';
		wp_print_scripts( array( $script_handle ) );
		echo '</body></html>';

		exit;
	}
}
