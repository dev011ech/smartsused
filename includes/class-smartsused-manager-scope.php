<?php
/**
 * Restricts property-manager access to assigned properties.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds administrator-only manager assignments and enforces manager scope.
 */
final class SmartSused_Manager_Scope {

	const META_KEY     = '_smartsused_manager_property_id';
	const PAGE_SLUG    = 'smartsused-manager-properties';
	const FRONTEND_URL = 'https://sb.chudy.online/';

	/**
	 * Register manager assignment, data-scope, and admin lockout hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'redirect_non_admin_roles' ), 0 );
		add_action( 'login_init', array( $this, 'redirect_non_admin_roles' ), 0 );
		add_action( 'pre_get_posts', array( $this, 'scope_post_queries' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest_objects' ), 10, 3 );
	}

	/**
	 * Return the published properties assigned to a manager.
	 *
	 * @param int|null $user_id User ID; defaults to current user.
	 * @return int[] Published property IDs.
	 */
	public static function get_property_ids( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );

		if ( $user_id < 1 ) {
			return array();
		}

		$assignments = get_user_meta( $user_id, self::META_KEY, true );
		$assignments = is_array( $assignments ) ? $assignments : array( $assignments );
		$property_ids = array();

		foreach ( $assignments as $assignment ) {
			$property_id = absint( $assignment );

			if (
				$property_id > 0
				&& 'smartsused_property' === get_post_type( $property_id )
				&& 'publish' === get_post_status( $property_id )
			) {
				$property_ids[] = $property_id;
			}
		}

		return array_values( array_unique( $property_ids ) );
	}

	/**
	 * Return the first valid assigned property for legacy single-property callers.
	 *
	 * @param int|null $user_id User ID; defaults to current user.
	 * @return int Zero when no valid property is assigned.
	 */
	public static function get_property_id( $user_id = null ) {
		$property_ids = self::get_property_ids( $user_id );
		return empty( $property_ids ) ? 0 : (int) $property_ids[0];
	}

	/**
	 * Check whether a property belongs to the manager's assignment.
	 *
	 * @param int      $property_id Property ID.
	 * @param int|null $user_id     User ID; defaults to current user.
	 * @return bool
	 */
	public static function is_property_assigned( $property_id, $user_id = null ) {
		return in_array( absint( $property_id ), self::get_property_ids( $user_id ), true );
	}

	/**
	 * Determine whether a user is a scoped manager and not an administrator.
	 *
	 * @param int|null $user_id User ID; defaults to current user.
	 * @return bool
	 */
	public static function is_scoped_manager( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof WP_User
			&& in_array( 'smartsused_manager', $user->roles, true )
			&& ! in_array( 'administrator', $user->roles, true );
	}

	/**
	 * Add an assignment screen visible only to WordPress administrators.
	 */
	public function register_assignment_page() {
		add_submenu_page(
			'smartsused-admin',
			__( 'Priradenie správcov k domu', 'smartsused-core' ),
			__( 'Priradenie správcov', 'smartsused-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_assignment_page' )
		);
	}

	/**
	 * Render administrator-only assignments for manager accounts.
	 */
	public function render_assignment_page() {
		if ( ! $this->current_user_is_administrator() ) {
			wp_die(
				esc_html__( 'Na správu priradení správcov nemáte oprávnenie.', 'smartsused-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		$managers = get_users(
			array(
				'role'    => 'smartsused_manager',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		$properties = get_posts(
			array(
				'post_type'      => 'smartsused_property',
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Priradenie správcov k bytovým domom', 'smartsused-core' ); ?></h1>
			<p><?php esc_html_e( 'Administrátori WordPressu môžu každému správcovi priradiť jeden alebo viac publikovaných bytových domov.', 'smartsused-core' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Priradenie správcu bolo uložené.', 'smartsused-core' ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $managers ) ) : ?>
				<p><?php esc_html_e( 'V systéme zatiaľ nie sú účty správcov.', 'smartsused-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Správca', 'smartsused-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Bytový dom', 'smartsused-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Akcia', 'smartsused-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $managers as $manager ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $manager->display_name ); ?></strong><br>
									<?php echo esc_html( $manager->user_email ); ?>
								</td>
								<td colspan="2">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smartsused-manager-assignment">
										<input type="hidden" name="action" value="smartsused_assign_manager_property">
										<input type="hidden" name="manager_id" value="<?php echo esc_attr( (string) $manager->ID ); ?>">
										<?php wp_nonce_field( 'smartsused_assign_manager_property_' . $manager->ID ); ?>
										<label class="screen-reader-text" for="smartsused-property-<?php echo esc_attr( (string) $manager->ID ); ?>">
											<?php esc_html_e( 'Priradený bytový dom', 'smartsused-core' ); ?>
										</label>
										<select id="smartsused-property-<?php echo esc_attr( (string) $manager->ID ); ?>" name="property_ids[]" multiple size="5">
											<?php foreach ( $properties as $property ) : ?>
												<option
													value="<?php echo esc_attr( (string) $property->ID ); ?>"
													<?php selected( in_array( (int) $property->ID, self::get_property_ids( $manager->ID ), true ) ); ?>
												>
													<?php echo esc_html( get_the_title( $property ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Podržte Ctrl (Windows) alebo Command (Mac), aby ste vybrali viac domov. Bez výberu nebude mať správca prístup k domu.', 'smartsused-core' ); ?></p>
										<?php submit_button( __( 'Uložiť', 'smartsused-core' ), 'secondary', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save one manager's property assignment.
	 */
	public function save_assignment() {
		if ( ! $this->current_user_is_administrator() ) {
			wp_die(
				esc_html__( 'Na zmenu priradenia správcu nemáte oprávnenie.', 'smartsused-core' ),
				'',
				array( 'response' => 403 )
			);
		}

		$manager_id = isset( $_POST['manager_id'] ) ? absint( wp_unslash( $_POST['manager_id'] ) ) : 0;
		$property_ids = array();
		if ( isset( $_POST['property_ids'] ) && is_array( $_POST['property_ids'] ) ) {
			foreach ( wp_unslash( $_POST['property_ids'] ) as $submitted_property_id ) {
				if ( is_scalar( $submitted_property_id ) ) {
					$property_id = absint( $submitted_property_id );
					if ( $property_id > 0 ) {
						$property_ids[] = $property_id;
					}
				}
			}
			$property_ids = array_values( array_unique( $property_ids ) );
		}
		if ( empty( $property_ids ) && isset( $_POST['property_id'] ) ) {
			$legacy_property_id = absint( wp_unslash( $_POST['property_id'] ) );
			$property_ids        = $legacy_property_id > 0 ? array( $legacy_property_id ) : array();
		}

		check_admin_referer( 'smartsused_assign_manager_property_' . $manager_id );

		$manager = $manager_id > 0 ? get_userdata( $manager_id ) : false;

		if ( ! ( $manager instanceof WP_User ) || ! in_array( 'smartsused_manager', $manager->roles, true ) ) {
			wp_die(
				esc_html__( 'Vybraný účet nie je platný správca.', 'smartsused-core' ),
				'',
				array( 'response' => 400 )
			);
		}

		foreach ( $property_ids as $property_id ) {
			if ( 'smartsused_property' !== get_post_type( $property_id ) || 'publish' !== get_post_status( $property_id ) ) {
				wp_die(
					esc_html__( 'Jeden z vybraných bytových domov neexistuje alebo nie je publikovaný.', 'smartsused-core' ),
					'',
					array( 'response' => 400 )
				);
			}
		}

		if ( ! empty( $property_ids ) ) {
			update_user_meta( $manager_id, self::META_KEY, $property_ids );
		} else {
			delete_user_meta( $manager_id, self::META_KEY );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Restrict relevant WordPress queries to the manager's one property.
	 *
	 * @param WP_Query $query WordPress query.
	 */
	public function scope_post_queries( $query ) {
		if ( ! self::is_scoped_manager() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_types = is_array( $post_type ) ? $post_type : array( $post_type );
		$property_ids = self::get_property_ids();

		if ( in_array( 'smartsused_property', $post_types, true ) ) {
			$existing = $query->get( 'post__in' );
			$query->set( 'post__in', ! empty( $property_ids ) ? self::intersect_ids( $existing, $property_ids ) : array( 0 ) );
		}

		if ( in_array( 'smartsused_unit', $post_types, true ) ) {
			$requested_parent = $query->get( 'post_parent' );
			if ( null !== $requested_parent && '' !== $requested_parent && ! self::is_property_assigned( $requested_parent ) ) {
				$query->set( 'post__in', array( 0 ) );
				return;
			}

			if ( null !== $requested_parent && '' !== $requested_parent ) {
				$query->set( 'post_parent', absint( $requested_parent ) );
			} else {
				$query->set( 'post_parent__in', ! empty( $property_ids ) ? $property_ids : array( 0 ) );
			}
		}

		if ( in_array( 'smartsused_poll', $post_types, true ) ) {
			$meta_query = $query->get( 'meta_query' );
			$meta_query = is_array( $meta_query ) ? $meta_query : array();
			$meta_query['relation'] = 'AND';
			$meta_query[] = array(
				'key'     => '_property_id',
				'value'   => ! empty( $property_ids ) ? $property_ids : array( 0 ),
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Reject direct REST access and writes outside the manager's property.
	 *
	 * @param mixed           $result  Existing REST response.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed|WP_Error
	 */
	public function guard_rest_objects( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! self::is_scoped_manager() ) {
			return $result;
		}

		$route = $request->get_route();
		$method = strtoupper( $request->get_method() );
		$property_ids = self::get_property_ids();

		if ( '/smartsused/v1/polls/results' === $route ) {
			$poll_id = absint( $request->get_param( 'id' ) );
			if ( ! self::post_belongs_to_property( 'smartsused_poll', $poll_id, $property_ids ) ) {
				return self::not_found_error();
			}
		}

		if ( preg_match( '#^/wp/v2/(smartsused_property|smartsused_unit|smartsused_poll)(?:/(\d+)(?:/.*)?)?$#', $route, $matches ) ) {
			$post_type = $matches[1];

			if ( 'smartsused_property' === $post_type && 'POST' === $method && empty( $matches[2] ) ) {
				return self::forbidden_error();
			}
			if ( 'smartsused_property' === $post_type && 'DELETE' === $method ) {
				return self::forbidden_error();
			}

			if ( 'smartsused_poll' === $post_type && 'POST' === $method && empty( $matches[2] ) ) {
				return self::forbidden_error();
			}

			if ( 'smartsused_unit' === $post_type && 'POST' === $method && empty( $matches[2] ) ) {
				$submitted_property_id = $request->get_param( 'property_id' );
				$submitted_parent_id   = $request->get_param( 'parent' );
				if (
					empty( $property_ids )
					|| ( null === $submitted_property_id && null === $submitted_parent_id )
					|| ( null !== $submitted_property_id && ! self::is_property_assigned( $submitted_property_id ) )
					|| ( null !== $submitted_parent_id && ! self::is_property_assigned( $submitted_parent_id ) )
					|| ( null !== $submitted_property_id && null !== $submitted_parent_id && absint( $submitted_property_id ) !== absint( $submitted_parent_id ) )
				) {
					return self::forbidden_error();
				}
			}

			if ( isset( $matches[2] ) && ( 'smartsused_unit' === $post_type || 'smartsused_property' === $post_type || 'smartsused_poll' === $post_type ) ) {
				$post_id = absint( $matches[2] );
				if ( ! self::post_belongs_to_property( $post_type, $post_id, $property_ids ) ) {
					return self::not_found_error();
				}

				if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && 'smartsused_unit' === $post_type ) {
					$submitted_property_id = $request->get_param( 'property_id' );
					$submitted_parent_id   = $request->get_param( 'parent' );
					if (
						( null !== $submitted_property_id && ! self::is_property_assigned( $submitted_property_id ) )
						|| ( null !== $submitted_parent_id && ! self::is_property_assigned( $submitted_parent_id ) )
						|| ( null !== $submitted_property_id && null !== $submitted_parent_id && absint( $submitted_property_id ) !== absint( $submitted_parent_id ) )
					) {
						return self::forbidden_error();
					}
				}

				if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && 'smartsused_poll' === $post_type ) {
					$submitted_property_id = $request->get_param( 'property_id' );
					$submitted_meta = $request->get_param( 'meta' );
					$submitted_meta_property_id = is_array( $submitted_meta ) && array_key_exists( '_property_id', $submitted_meta )
						? $submitted_meta['_property_id']
						: null;

					if (
						( null !== $submitted_property_id && ! self::is_property_assigned( $submitted_property_id ) )
						|| ( null !== $submitted_meta_property_id && ! self::is_property_assigned( $submitted_meta_property_id ) )
						|| ( null !== $submitted_property_id && null !== $submitted_meta_property_id && absint( $submitted_property_id ) !== absint( $submitted_meta_property_id ) )
					) {
						return self::forbidden_error();
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Redirect logged-in resident and manager roles away from WordPress screens.
	 */
	public function redirect_non_admin_roles() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if (
			$user instanceof WP_User
			&& ! in_array( 'administrator', $user->roles, true )
			&& array_intersect( array( 'smartsused_manager', 'smartsused_owner', 'smartsused_tenant' ), $user->roles )
		) {
			wp_logout();
			wp_redirect( self::FRONTEND_URL, 302, 'SmartSused Core' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect
			exit;
		}
	}

	/**
	 * Return whether the current account has the administrator role and capability.
	 *
	 * @return bool
	 */
	private function current_user_is_administrator() {
		$user = wp_get_current_user();

		return current_user_can( 'manage_options' )
			&& $user instanceof WP_User
			&& in_array( 'administrator', $user->roles, true );
	}

	/**
	 * Intersect an optional existing post ID filter with assigned properties.
	 *
	 * @param mixed $existing     Existing post__in query value.
	 * @param int[] $property_ids Assigned property IDs.
	 * @return int[]
	 */
	private static function intersect_ids( $existing, $property_ids ) {
		if ( empty( $existing ) ) {
			return $property_ids;
		}

		$ids = wp_parse_id_list( $existing );
		$matched = array_values( array_intersect( $property_ids, $ids ) );
		return empty( $matched ) ? array( 0 ) : $matched;
	}

	/**
	 * Check that a property, unit, or poll belongs to the manager's property.
	 *
	 * @param string $post_type   Post type.
	 * @param int    $post_id     Post ID.
	 * @param int[]  $property_ids Assigned property IDs.
	 * @return bool
	 */
	private static function post_belongs_to_property( $post_type, $post_id, $property_ids ) {
		if ( empty( $property_ids ) ) {
			return false;
		}

		if ( 'smartsused_property' === $post_type ) {
			return in_array( $post_id, $property_ids, true )
				&& 'publish' === get_post_status( $post_id );
		}

		if ( 'smartsused_unit' === $post_type ) {
			return 'smartsused_unit' === get_post_type( $post_id )
				&& in_array( (int) wp_get_post_parent_id( $post_id ), $property_ids, true );
		}

		return 'smartsused_poll' === $post_type
			&& 'smartsused_poll' === get_post_type( $post_id )
			&& in_array( absint( get_post_meta( $post_id, '_property_id', true ) ), $property_ids, true );
	}

	/**
	 * Create an error that does not disclose whether another property's record exists.
	 *
	 * @return WP_Error
	 */
	private static function not_found_error() {
		return new WP_Error(
			'smartsused_scoped_resource_not_found',
			__( 'Požadovaný záznam sa nenašiel.', 'smartsused-core' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Create a standard forbidden error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_manager_scope_forbidden',
			__( 'K tejto operácii nemáte oprávnenie pre priradené bytové domy.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
