<?php
/**
 * Local WordPress REST smoke test for SS-01.
 *
 * Run only against a disposable local WordPress database with:
 * $env:SMARTSUSED_SS01_ALLOW_DB_FIXTURES = '1'
 * php -r "require '.../wp-load.php'; require '.../tests/ss-01-cpt-rest-smoke.php';"
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit( 'Load WordPress before running this smoke test.' );

if ( 'cli' !== PHP_SAPI ) {
	exit( 'Run this smoke test from the command line.' );
}

$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
if (
	'1' !== getenv( 'SMARTSUSED_SS01_ALLOW_DB_FIXTURES' )
	|| ! in_array( $site_host, array( 'localhost', '127.0.0.1', '::1' ), true )
) {
	exit( 'This smoke test requires explicit opt-in and a localhost WordPress site.' );
}

require_once dirname( __DIR__ ) . '/includes/class-smartsused-core.php';
require_once dirname( __DIR__ ) . '/includes/class-smartsused-manager-scope.php';
require_once dirname( __DIR__ ) . '/api/class-smartsused-tenant-invites.php';
require_once dirname( __DIR__ ) . '/api/class-smartsused-owner-import.php';
require_once dirname( __DIR__ ) . '/api/class-smartsused-unit-import.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function ss01_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ss01_dispatch( $role, $method, $route, $params = array() ) {
	$user_ids = $GLOBALS['ss01_user_ids'];
	$user_id  = isset( $user_ids[ $role ] ) ? (int) $user_ids[ $role ] : 0;
	$GLOBALS['ss01_user_id'] = $user_id;
	$request = new WP_REST_Request( $method, $route );

	if ( $user_id > 0 && 'administrator' !== $role ) {
		$request->set_header( 'authorization', 'Bearer ss01-test' );
	}

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request );
}

function ss01_expect_status( $response, $expected, $description ) {
	ss01_assert(
		$expected === $response->get_status(),
		sprintf( '%s: expected HTTP %d, got %d.', $description, $expected, $response->get_status() )
	);
}

$core = SmartSused_Core::instance();
$core->register_post_types();
new SmartSused_Manager_Scope();
$tenant_api = new SmartSused_Tenant_Invites();
$owner_import = new SmartSused_Owner_Import();
$unit_import = new SmartSused_Unit_Import();

$GLOBALS['ss01_user_id']  = 0;
$GLOBALS['ss01_user_ids'] = array();

add_filter(
	'rest_authentication_errors',
	static function ( $result ) {
		$user_id = (int) $GLOBALS['ss01_user_id'];
		wp_set_current_user( $user_id );
		return $user_id > 0 ? true : $result;
	},
	PHP_INT_MAX
);
add_filter(
	'rest_pre_dispatch',
	static function ( $result ) {
		wp_set_current_user( (int) $GLOBALS['ss01_user_id'] );
		return $result;
	},
	5
);
add_filter(
	'smartsused_verified_jwt_user_id',
	static function ( $verified_user_id, $request ) {
		return 'Bearer ss01-test' === $request->get_header( 'authorization' )
			? (int) $GLOBALS['ss01_user_id']
			: $verified_user_id;
	},
	20,
	2
);

$created_roles = array();
$added_caps    = array();
$created_users = array();
$created_posts = array();
$created_table = false;
$relationship_table = $GLOBALS['wpdb']->prefix . 'smartsused_unit_relationships';
$role_names = array( 'smartsused_manager', 'smartsused_owner', 'smartsused_tenant' );

try {
	foreach ( $role_names as $role_name ) {
		$role = get_role( $role_name );

		if ( ! $role ) {
			add_role( $role_name, $role_name, array( 'read' => true ) );
			$created_roles[] = $role_name;
			$role = get_role( $role_name );
		}

		if ( 'smartsused_manager' !== $role_name ) {
			continue;
		}

		foreach ( array( 'smartsused_property', 'smartsused_unit' ) as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			$meta_capabilities = array(
				$post_type_object->cap->read_post,
				$post_type_object->cap->edit_post,
				$post_type_object->cap->delete_post,
			);

			foreach ( get_object_vars( $post_type_object->cap ) as $capability ) {
				if (
					is_string( $capability )
					&& ! in_array( $capability, $meta_capabilities, true )
					&& empty( $role->capabilities[ $capability ] )
				) {
					$role->add_cap( $capability );
					$added_caps[ $role_name ][] = $capability;
				}
			}
		}
	}

	$suffix = 'ss01_' . wp_generate_password( 8, false, false );

	foreach ( array( 'administrator', 'smartsused_manager', 'smartsused_owner', 'smartsused_tenant' ) as $role_name ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => $suffix . '_' . $role_name,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $suffix . '_' . $role_name . '@example.invalid',
				'role'       => $role_name,
			)
		);

		ss01_assert( ! is_wp_error( $user_id ), 'Could not create a temporary role fixture.' );
		$GLOBALS['ss01_user_ids'][ $role_name ] = (int) $user_id;
		$created_users[] = (int) $user_id;
	}

	$property_ids = array();
	foreach ( array( 'assigned', 'outside' ) as $label ) {
		$property_id = wp_insert_post(
			array(
				'post_type'   => 'smartsused_property',
				'post_status' => 'publish',
				'post_title'  => 'SS-01 temporary ' . $label . ' property',
			),
			true
		);
		ss01_assert( ! is_wp_error( $property_id ), 'Could not create a temporary property fixture.' );
		$property_ids[] = (int) $property_id;
		$created_posts[] = (int) $property_id;
	}

	$unit_ids = array();
	foreach ( $property_ids as $index => $property_id ) {
		$unit_id = wp_insert_post(
			array(
				'post_type'   => 'smartsused_unit',
				'post_status' => 'publish',
				'post_title'  => 'SS-01 temporary unit ' . $index,
				'post_parent' => $property_id,
			),
			true
		);
		ss01_assert( ! is_wp_error( $unit_id ), 'Could not create a temporary unit fixture.' );
		$unit_ids[] = (int) $unit_id;
		$created_posts[] = (int) $unit_id;
	}

	update_user_meta(
		$GLOBALS['ss01_user_ids']['smartsused_manager'],
		SmartSused_Manager_Scope::META_KEY,
		array( $property_ids[0] )
	);

	global $wpdb;
	$existing_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $relationship_table ) ) );

	if ( $existing_table !== $relationship_table ) {
		$wpdb->query(
			"CREATE TABLE {$relationship_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				unit_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				resident_type varchar(20) NOT NULL,
				PRIMARY KEY (id)
			) ENGINE=InnoDB"
		);
		$created_table = true;
	}

	foreach (
		array(
			array( $unit_ids[0], $GLOBALS['ss01_user_ids']['smartsused_owner'], 'owner' ),
			array( $unit_ids[1], $GLOBALS['ss01_user_ids']['smartsused_tenant'], 'tenant' ),
		) as $relationship
	) {
		ss01_assert(
			false !== $wpdb->insert(
				$relationship_table,
				array(
					'unit_id'       => $relationship[0],
					'user_id'       => $relationship[1],
					'resident_type' => $relationship[2],
				),
				array( '%d', '%d', '%s' )
			),
			'Could not create a temporary resident/unit relationship.'
		);
	}

	$server = rest_get_server();
	$routes = $server->get_routes();
	ss01_assert(
		isset( $routes['/smartsused/v1/import/units'] )
		&& isset( $routes['/smartsused/v1/import/owners'] )
		&& isset( $routes['/smartsused/v1/import/residents'] ),
		'Existing CSV import routes were not registered.'
	);
	unset( $tenant_api );

	foreach (
		array(
			'smartsused_property' => $property_ids[0],
			'smartsused_unit'     => $unit_ids[0],
		) as $post_type => $post_id
	) {
		foreach ( array( "/wp/v2/{$post_type}", "/wp/v2/{$post_type}/{$post_id}" ) as $route ) {
			ss01_expect_status( ss01_dispatch( 'anonymous', 'GET', $route ), 401, 'Anonymous ' . $route );
			ss01_expect_status( ss01_dispatch( 'smartsused_owner', 'GET', $route ), 403, 'Owner ' . $route );
			ss01_expect_status( ss01_dispatch( 'smartsused_tenant', 'GET', $route ), 403, 'Tenant ' . $route );
		}
	}
	echo "Anonymous collection/item 401 and owner/tenant 403: PASS\n";

	foreach (
		array(
			'smartsused_property' => $property_ids,
			'smartsused_unit'     => $unit_ids,
		) as $post_type => $post_ids
	) {
		$collection = ss01_dispatch(
			'smartsused_manager',
			'GET',
			'/wp/v2/' . $post_type,
			array( 'context' => 'edit', 'per_page' => 100 )
		);
		ss01_expect_status( $collection, 200, 'Manager ' . $post_type . ' collection' );
		$visible_ids = array_map(
			static function ( $post ) {
				return (int) $post['id'];
			},
			$collection->get_data()
		);
		ss01_assert( in_array( $post_ids[0], $visible_ids, true ), 'Manager cannot see the assigned ' . $post_type . '.' );
		ss01_assert( ! in_array( $post_ids[1], $visible_ids, true ), 'Manager can see an unassigned ' . $post_type . '.' );
		ss01_expect_status(
			ss01_dispatch( 'smartsused_manager', 'GET', '/wp/v2/' . $post_type . '/' . $post_ids[0], array( 'context' => 'edit' ) ),
			200,
			'Manager assigned ' . $post_type . ' item'
		);
		ss01_expect_status(
			ss01_dispatch( 'smartsused_manager', 'GET', '/wp/v2/' . $post_type . '/' . $post_ids[1], array( 'context' => 'edit' ) ),
			404,
			'Manager unassigned ' . $post_type . ' item'
		);
	}
	echo "Manager collection and item scope: PASS\n";
	ss01_expect_status(
		ss01_dispatch(
			'smartsused_manager',
			'PUT',
			'/wp/v2/smartsused_property/' . $property_ids[0],
			array( 'title' => 'SS-01 temporary manager-edited property' )
		),
		200,
		'Manager edit of assigned property'
	);
	$manager_unit = ss01_dispatch(
		'smartsused_manager',
		'POST',
		'/wp/v2/smartsused_unit',
		array(
			'title'       => 'SS-01 temporary manager-created unit',
			'status'      => 'publish',
			'property_id' => $property_ids[0],
			'floor'       => '1',
			'entrance'    => 'M',
		)
	);
	ss01_expect_status( $manager_unit, 201, 'Manager create unit in assigned property' );
	$manager_unit_id = (int) $manager_unit->get_data()['id'];
	ss01_assert(
		(int) $manager_unit->get_data()['property_id'] === $property_ids[0]
		&& '1' === $manager_unit->get_data()['floor']
		&& 'M' === $manager_unit->get_data()['entrance'],
		'Authorized manager unit REST fields were not included.'
	);
	$created_posts[] = $manager_unit_id;
	ss01_expect_status(
		ss01_dispatch(
			'smartsused_manager',
			'PUT',
			'/wp/v2/smartsused_unit/' . $manager_unit_id,
			array( 'title' => 'SS-01 temporary manager-updated unit' )
		),
		200,
		'Manager update unit in assigned property'
	);
	$GLOBALS['ss01_user_id'] = $GLOBALS['ss01_user_ids']['smartsused_manager'];
	$manager_without_jwt = new WP_REST_Request( 'GET', '/wp/v2/smartsused_property' );
	ss01_expect_status(
		rest_do_request( $manager_without_jwt ),
		403,
		'Manager request without a verified JWT'
	);
	echo "Manager SPA edit house and create/update assigned unit: PASS\n";

	foreach (
		array(
			'smartsused_property' => $property_ids,
			'smartsused_unit'     => $unit_ids,
		) as $post_type => $post_ids
	) {
		$collection = ss01_dispatch(
			'administrator',
			'GET',
			'/wp/v2/' . $post_type,
			array( 'context' => 'edit', 'per_page' => 100 )
		);
		ss01_expect_status( $collection, 200, 'Administrator ' . $post_type . ' collection' );
		$visible_ids = array_map(
			static function ( $post ) {
				return (int) $post['id'];
			},
			$collection->get_data()
		);
		ss01_assert( in_array( $post_ids[0], $visible_ids, true ) && in_array( $post_ids[1], $visible_ids, true ), 'Administrator cannot see all ' . $post_type . ' fixtures.' );
	}
	echo "Administrator sees all fixture properties and units: PASS\n";

	$created_property = ss01_dispatch(
		'administrator',
		'POST',
		'/wp/v2/smartsused_property',
		array( 'title' => 'SS-01 temporary CRUD property', 'status' => 'publish' )
	);
	ss01_expect_status( $created_property, 201, 'Administrator property create' );
	$property_id = (int) $created_property->get_data()['id'];
	$created_posts[] = $property_id;
	ss01_expect_status(
		ss01_dispatch( 'administrator', 'PUT', '/wp/v2/smartsused_property/' . $property_id, array( 'title' => 'SS-01 temporary updated property' ) ),
		200,
		'Administrator property update'
	);

	$created_unit = ss01_dispatch(
		'administrator',
		'POST',
		'/wp/v2/smartsused_unit',
		array(
			'title'       => 'SS-01 temporary CRUD unit',
			'status'      => 'publish',
			'property_id' => $property_ids[0],
			'floor'       => '2',
			'entrance'    => 'A',
		)
	);
	ss01_expect_status( $created_unit, 201, 'Administrator unit create' );
	$unit_id = (int) $created_unit->get_data()['id'];
	$created_posts[] = $unit_id;
	ss01_assert(
		(int) $created_unit->get_data()['property_id'] === $property_ids[0]
		&& '2' === $created_unit->get_data()['floor']
		&& 'A' === $created_unit->get_data()['entrance'],
		'Authorized unit REST fields were not included in the create response.'
	);
	ss01_expect_status(
		ss01_dispatch( 'administrator', 'PUT', '/wp/v2/smartsused_unit/' . $unit_id, array( 'title' => 'SS-01 temporary updated unit', 'entrance' => 'B' ) ),
		200,
		'Administrator unit update'
	);
	$updated_unit = ss01_dispatch(
		'administrator',
		'GET',
		'/wp/v2/smartsused_unit/' . $unit_id,
		array( 'context' => 'edit' )
	);
	ss01_expect_status( $updated_unit, 200, 'Administrator updated unit fields' );
	ss01_assert( 'B' === $updated_unit->get_data()['entrance'], 'Authorized entrance field did not persist.' );
	echo "Administrator CPT REST property/unit create and update: PASS\n";

	$tenant_units = ss01_dispatch( 'smartsused_tenant', 'GET', '/smartsused/v1/tenant/units' );
	ss01_expect_status( $tenant_units, 200, 'Tenant existing unit endpoint' );
	$tenant_data = $tenant_units->get_data();
	ss01_assert(
		isset( $tenant_data['units'] )
		&& 1 === count( $tenant_data['units'] )
		&& (int) $tenant_data['units'][0]['id'] === $unit_ids[1]
		&& empty( $tenant_data['units'][0]['tenants'] ),
		'Tenant endpoint did not return only its own unit or exposed peer tenant data.'
	);
	ss01_expect_status(
		ss01_dispatch(
			'smartsused_tenant',
			'GET',
			'/smartsused/v1/tenant/units',
			array( 'unit_id' => $unit_ids[0] )
		),
		403,
		'Tenant request for another resident unit'
	);
	$owner_units = ss01_dispatch( 'smartsused_owner', 'GET', '/smartsused/v1/tenant/units' );
	ss01_expect_status( $owner_units, 200, 'Owner existing unit endpoint' );
	$owner_data = $owner_units->get_data();
	ss01_assert(
		isset( $owner_data['units'][0]['id'] )
		&& (int) $owner_data['units'][0]['id'] === $unit_ids[0],
		'Existing owner unit list changed during tenant support.'
	);
	echo "Existing /tenant/units returns only the tenant's unit: PASS\n";

	foreach ( array( 'smartsused_property', 'smartsused_unit' ) as $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		ss01_assert(
			! $post_type_object->public
			&& ! $post_type_object->publicly_queryable
			&& $post_type_object->show_ui
			&& $post_type_object->show_in_rest
			&& ! $post_type_object->has_archive
			&& $post_type_object->exclude_from_search
			&& false === $post_type_object->rewrite,
			'Incorrect visibility flags for ' . $post_type . '.'
		);
		ss01_assert( false === get_post_type_archive_link( $post_type ), 'Public archive still exists for ' . $post_type . '.' );
	}

	$sitemap = wp_sitemaps_get_server();
	$provider = $sitemap->registry->get_provider( 'posts' );
	$subtypes = $provider ? $provider->get_object_subtypes() : array();
	ss01_assert(
		! isset( $subtypes['smartsused_property'] )
		&& ! isset( $subtypes['smartsused_unit'] ),
		'A private CPT remains in the WordPress posts sitemap.'
	);
	echo "Archives and WordPress posts sitemap exclude both CPTs: PASS\n";

	$import_request = new WP_REST_Request( 'POST', '/smartsused/v1/import/units' );
	$import_request->set_header( 'authorization', 'Bearer ss01-test' );
	$GLOBALS['ss01_user_id'] = $GLOBALS['ss01_user_ids']['smartsused_manager'];
	wp_set_current_user( $GLOBALS['ss01_user_id'] );
	ss01_assert( true === $unit_import->permission_to_import( $import_request ), 'Assigned manager lost access to the existing unit CSV import.' );
	ss01_assert( true === $owner_import->permission_to_import( $import_request ), 'Assigned manager lost access to the existing resident CSV import.' );
	echo "Assigned manager retains existing unit and resident CSV import permissions: PASS\n";
} finally {
	foreach ( $created_users as $user_id ) {
		$wpdb->delete( $relationship_table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
	foreach ( $created_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $created_users as $user_id ) {
		wp_delete_user( $user_id );
	}
	foreach ( $added_caps as $role_name => $capabilities ) {
		$role = get_role( $role_name );
		foreach ( $capabilities as $capability ) {
			if ( $role ) {
				$role->remove_cap( $capability );
			}
		}
	}
	foreach ( $created_roles as $role_name ) {
		remove_role( $role_name );
	}
	if ( $created_table ) {
		$wpdb->query( "DROP TABLE {$relationship_table}" );
	}
	wp_set_current_user( 0 );
}
