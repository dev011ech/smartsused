<?php
/**
 * Registers SmartSused post types and user roles.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class SmartSused_Core {

	/**
	 * The plugin instance.
	 *
	 * @var SmartSused_Core|null
	 */
	private static $instance = null;

	/**
	 * Return the single plugin instance.
	 *
	 * @return SmartSused_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register plugin hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'add_meta_boxes_smartsused_unit', array( $this, 'register_unit_property_meta_box' ) );
		add_action( 'save_post_smartsused_unit', array( $this, 'save_unit_property' ), 10, 2 );
	}

	/**
	 * Set up plugin data when the plugin is activated.
	 */
	public static function activate() {
		self::register_roles();
		SmartSused_Secure_Files::activate();
		SmartSused_Tenant_Invites::activate();

		$core = self::instance();
		$core->register_post_types();

		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules after the plugin is deactivated.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Register the property and unit post types.
	 */
	public function register_post_types() {
		register_post_type(
			'smartsused_property',
			array(
				'labels'              => array(
					'name'                  => __( 'Bytové domy a objekty', 'smartsused-core' ),
					'singular_name'         => __( 'Bytový dom', 'smartsused-core' ),
					'menu_name'             => __( 'Objekty', 'smartsused-core' ),
					'name_admin_bar'        => __( 'Objekt', 'smartsused-core' ),
					'add_new'               => __( 'Pridať nový', 'smartsused-core' ),
					'add_new_item'          => __( 'Pridať objekt', 'smartsused-core' ),
					'new_item'              => __( 'Nový objekt', 'smartsused-core' ),
					'edit_item'             => __( 'Upraviť objekt', 'smartsused-core' ),
					'view_item'             => __( 'Zobraziť objekt', 'smartsused-core' ),
					'all_items'             => __( 'Všetky objekty', 'smartsused-core' ),
					'search_items'          => __( 'Hľadať objekty', 'smartsused-core' ),
					'not_found'             => __( 'Nenašli sa žiadne objekty.', 'smartsused-core' ),
					'not_found_in_trash'    => __( 'V koši sa nenašli žiadne objekty.', 'smartsused-core' ),
					'parent_item_colon'     => __( 'Nadradený objekt:', 'smartsused-core' ),
					'featured_image'        => __( 'Obrázok objektu', 'smartsused-core' ),
					'set_featured_image'    => __( 'Nastaviť obrázok objektu', 'smartsused-core' ),
					'remove_featured_image' => __( 'Odstrániť obrázok objektu', 'smartsused-core' ),
					'use_featured_image'    => __( 'Použiť ako obrázok objektu', 'smartsused-core' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'has_archive'         => true,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-building',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type'     => array( 'smartsused_property', 'smartsused_properties' ),
				'map_meta_cap'        => true,
			)
		);

		register_post_type(
			'smartsused_unit',
			array(
				'labels'              => array(
					'name'                  => __( 'Byty a nebytové priestory', 'smartsused-core' ),
					'singular_name'         => __( 'Byt / Nebytový priestor', 'smartsused-core' ),
					'menu_name'             => __( 'Jednotky', 'smartsused-core' ),
					'name_admin_bar'        => __( 'Jednotka', 'smartsused-core' ),
					'add_new'               => __( 'Pridať novú', 'smartsused-core' ),
					'add_new_item'          => __( 'Pridať jednotku', 'smartsused-core' ),
					'new_item'              => __( 'Nová jednotka', 'smartsused-core' ),
					'edit_item'             => __( 'Upraviť jednotku', 'smartsused-core' ),
					'view_item'             => __( 'Zobraziť jednotku', 'smartsused-core' ),
					'all_items'             => __( 'Všetky jednotky', 'smartsused-core' ),
					'search_items'          => __( 'Hľadať jednotky', 'smartsused-core' ),
					'not_found'             => __( 'Nenašli sa žiadne jednotky.', 'smartsused-core' ),
					'not_found_in_trash'    => __( 'V koši sa nenašli žiadne jednotky.', 'smartsused-core' ),
					'parent_item_colon'     => __( 'Nadradená jednotka:', 'smartsused-core' ),
					'featured_image'        => __( 'Obrázok jednotky', 'smartsused-core' ),
					'set_featured_image'    => __( 'Nastaviť obrázok jednotky', 'smartsused-core' ),
					'remove_featured_image' => __( 'Odstrániť obrázok jednotky', 'smartsused-core' ),
					'use_featured_image'    => __( 'Použiť ako obrázok jednotky', 'smartsused-core' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'has_archive'         => true,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-admin-home',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
				'capability_type'     => array( 'smartsused_unit', 'smartsused_units' ),
				'map_meta_cap'        => true,
			)
		);

		register_rest_field(
			'smartsused_unit',
			'property_id',
			array(
				'get_callback'    => array( $this, 'get_unit_property_id' ),
				'update_callback' => array( $this, 'update_unit_property_id' ),
				'schema'          => array(
					'description' => __( 'ID bytového domu priradeného k jednotke.', 'smartsused-core' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);

		$this->register_unit_meta_rest_field( 'floor', '_poschodie', __( 'Poschodie jednotky.', 'smartsused-core' ) );
		$this->register_unit_meta_rest_field( 'entrance', '_vchod', __( 'Vchod jednotky.', 'smartsused-core' ) );
	}

	/**
	 * Register a REST field that reads and writes a unit's private post meta.
	 *
	 * @param string $field_name REST field name.
	 * @param string $meta_key   WordPress post meta key.
	 * @param string $description Field description.
	 */
	private function register_unit_meta_rest_field( $field_name, $meta_key, $description ) {
		register_rest_field(
			'smartsused_unit',
			$field_name,
			array(
				'get_callback' => static function ( $object ) use ( $meta_key ) {
					return get_post_meta( (int) $object['id'], $meta_key, true );
				},
				'update_callback' => static function ( $value, $object ) use ( $meta_key ) {
					$post_id = isset( $object->ID ) ? (int) $object->ID : 0;

					if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
						return new WP_Error(
							'smartsused_unit_meta_forbidden',
							__( 'Nemáte oprávnenie upraviť túto bytovú jednotku.', 'smartsused-core' ),
							array( 'status' => 403 )
						);
					}

					update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $value ) );
					return true;
				},
				'schema' => array(
					'description' => $description,
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}

	/**
	 * Return the parent property ID for a unit REST response.
	 *
	 * @param array $object REST post object.
	 * @return int
	 */
	public function get_unit_property_id( $object ) {
		return isset( $object['id'] ) ? (int) wp_get_post_parent_id( (int) $object['id'] ) : 0;
	}

	/**
	 * Assign a unit to a published property through the REST API.
	 *
	 * @param mixed  $value  Submitted property ID.
	 * @param object $object REST post object.
	 * @return bool|WP_Error
	 */
	public function update_unit_property_id( $value, $object ) {
		$post_id     = isset( $object->ID ) ? (int) $object->ID : 0;
		$property_id = absint( $value );

		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'smartsused_unit_property_forbidden',
				__( 'Nemáte oprávnenie upraviť túto bytovú jednotku.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		if (
			$property_id > 0
			&& ( 'smartsused_property' !== get_post_type( $property_id ) || 'publish' !== get_post_status( $property_id ) )
		) {
			return new WP_Error(
				'smartsused_unit_invalid_property',
				__( 'Vybraný bytový dom neexistuje alebo nie je publikovaný.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_parent' => $property_id,
			),
			true
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Add a cross-post-type parent selector for unit-to-property assignment.
	 */
	public function register_unit_property_meta_box() {
		add_meta_box(
			'smartsused-unit-property',
			__( 'Bytový dom', 'smartsused-core' ),
			array( $this, 'render_unit_property_meta_box' ),
			'smartsused_unit',
			'side',
			'default'
		);
	}

	/**
	 * Render the unit's parent-property selector.
	 *
	 * @param WP_Post $post Current unit.
	 */
	public function render_unit_property_meta_box( $post ) {
		wp_nonce_field( 'smartsused_save_unit_property', 'smartsused_unit_property_nonce' );
		$properties = get_posts(
			array(
				'post_type'      => 'smartsused_property',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<p>
			<label for="smartsused-unit-parent"><?php esc_html_e( 'Bytový dom tejto jednotky', 'smartsused-core' ); ?></label>
			<select id="smartsused-unit-parent" name="smartsused_unit_property_id" class="widefat">
				<option value="0"><?php esc_html_e( 'Bez priradeného domu', 'smartsused-core' ); ?></option>
				<?php foreach ( $properties as $property ) : ?>
					<option value="<?php echo esc_attr( (string) $property->ID ); ?>" <?php selected( (int) $post->post_parent, $property->ID ); ?>>
						<?php echo esc_html( get_the_title( $property ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Save the selected property ID to the unit's post_parent field.
	 *
	 * @param int     $post_id Unit post ID.
	 * @param WP_Post $post    Unit post.
	 */
	public function save_unit_property( $post_id, $post ) {
		if (
			! isset( $_POST['smartsused_unit_property_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smartsused_unit_property_nonce'] ) ), 'smartsused_save_unit_property' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
			|| ! isset( $_POST['smartsused_unit_property_id'] )
		) {
			return;
		}

		$property_id = absint( wp_unslash( $_POST['smartsused_unit_property_id'] ) );

		if ( $property_id > 0 && ( 'smartsused_property' !== get_post_type( $property_id ) || 'publish' !== get_post_status( $property_id ) ) ) {
			return;
		}

		if ( (int) $post->post_parent === $property_id ) {
			return;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_parent' => $property_id,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			wp_die(
				esc_html__( 'Priradenie jednotky k bytovému domu sa nepodarilo uložiť.', 'smartsused-core' )
			);
		}
	}

	/**
	 * Create the plugin roles and grant the manager role CPT capabilities.
	 *
	 * Owner and tenant roles intentionally receive only the basic read
	 * capability. Record-level access rules are outside this starter plugin.
	 */
	private static function register_roles() {
		self::add_role_with_capabilities(
			'smartsused_manager',
			__( 'Správca', 'smartsused-core' ),
			array_merge(
				array( 'read' ),
				self::get_post_type_capabilities( 'smartsused_property', 'smartsused_properties' ),
				self::get_post_type_capabilities( 'smartsused_unit', 'smartsused_units' )
			)
		);

		self::add_role_with_capabilities(
			'smartsused_owner',
			__( 'Vlastník', 'smartsused-core' ),
			array( 'read' )
		);

		self::add_role_with_capabilities(
			'smartsused_tenant',
			__( 'Nájomca', 'smartsused-core' ),
			array( 'read' )
		);
	}

	/**
	 * Add a role if needed and ensure it has the requested capabilities.
	 *
	 * @param string   $role_name    Role slug.
	 * @param string   $display_name Translated role name.
	 * @param string[] $capabilities Capabilities to grant.
	 */
	private static function add_role_with_capabilities( $role_name, $display_name, $capabilities ) {
		$role = get_role( $role_name );

		if ( null === $role ) {
			add_role( $role_name, $display_name, array() );
			$role = get_role( $role_name );
		}

		if ( null === $role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Return the primitive capabilities for a custom post type.
	 *
	 * @param string $singular Singular post type capability base.
	 * @param string $plural   Plural post type capability base.
	 * @return string[]
	 */
	private static function get_post_type_capabilities( $singular, $plural ) {
		return array(
			'edit_' . $singular,
			'read_' . $singular,
			'delete_' . $singular,
			'edit_' . $plural,
			'edit_others_' . $plural,
			'publish_' . $plural,
			'read_private_' . $plural,
			'delete_' . $plural,
			'delete_private_' . $plural,
			'delete_published_' . $plural,
			'delete_others_' . $plural,
			'edit_private_' . $plural,
			'edit_published_' . $plural,
		);
	}
}
