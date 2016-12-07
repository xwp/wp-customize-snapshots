<?php
/**
 * Test Migrate.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Migrate
 */
class Test_Migrate extends \WP_UnitTestCase {

	/**
	 * A valid UUID.
	 *
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Snapshot manager.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	public $snapshot_manager;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		$this->mark_incompatible();
		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->snapshot_manager->post_type = new Post_Type( $this->snapshot_manager );
	}

	/**
	 * Mark test incomplete as it is only for new versions.
	 */
	public function mark_incompatible() {
		if ( $this->plugin->compat ) {
			$this->markTestSkipped( 'This unit-test require WP version 4.7 or up.' );
		}
	}

	/**
	 * Tear down.
	 */
	function tearDown() {
		$this->wp_customize = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		update_option( Migrate::KEY, 0 );
		parent::tearDown();
	}

	/**
	 * Test Migrate constructor.
	 *
	 * @see Migrate::__construct()
	 */
	function test_construct() {
		$class_name = 'CustomizeSnapshots\Migrate';
		$mock = $this->getMockBuilder( $class_name )
		             ->disableOriginalConstructor()
		             ->getMock();
		$mock->expects( $this->once() )
		     ->method( 'maybe_migrate' );
		$reflected_class = new \ReflectionClass( $class_name );
		$constructor = $reflected_class->getConstructor();
		$constructor->invoke( $mock );
		set_current_screen( 'index' );
		$constructor->invoke( $mock );
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		$constructor->invoke( $mock );
	}

	/**
	 * Test is_migrated.
	 *
	 * @see Migrate::is_migrated()
	 */
	function test_is_migrated() {
		$migrate = new Migrate();
		update_option( Migrate::KEY, 0 );
		$this->assertFalse( $migrate->is_migrated() );
		update_option( Migrate::KEY, 1 );
		$this->assertTrue( $migrate->is_migrated() );
	}

	/**
	 * Test maybe_migrate.
	 *
	 * @see Migrate::maybe_migrate()
	 */
	function test_maybe_migrate() {
		$migrate = new Migrate();
		$migrate->maybe_migrate();
		$this->assertEquals( 10, has_action( 'admin_notices', array( $migrate, 'show_migration_notice' ) ) );
		$this->assertEquals( 10, has_action( 'admin_enqueue_scripts', array( $migrate, 'enqueue_script' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_snapshot_migration', array( $migrate, 'handle_migrate_changeset_request' ) ) );
	}

	/**
	 * Test show_migration_notice.
	 *
	 * @see Migrate::show_migration_notice()
	 */
	function test_show_migration_notice() {
		$migrate = new Migrate();
		ob_start();
		$migrate->show_migration_notice();
		$data = ob_get_clean();
		$this->assertContains( 'customize-snapshot-migration', $data );
		$this->assertContains( 'customize-snapshot-migration', $data );
		$this->assertContains( 'data-nonce', $data );
		$this->assertContains( 'data-migration-success', $data );
		$this->assertContains( 'customize-snapshot-spinner', $data );
	}

	/**
	 * Test changeset_migrate.
	 *
	 * @see Migrate::changeset_migrate()
	 */
	function test_changeset_migrate() {
		$old_post_type_obj = new Post_Type_Back_Compat( $this->snapshot_manager );
		$post_id = $old_post_type_obj->save( array(
			'uuid' => wp_generate_uuid4(),
			'status' => 'draft',
			'data' => array(),
		) );
		$migrate = new Migrate();
		$posts_count = $migrate->changeset_migrate( - 1, true );
		$this->assertEquals( $post_id, array_shift( $posts_count ) );

		$migrate_obj = $this->getMockBuilder( 'CustomizeSnapshots\Migrate' )
		                    ->setMethods( array( 'migrate_post' ) )
		                    ->getMock();
		$migrate_obj->expects( $this->once() )
		            ->method( 'migrate_post' )
		            ->will( $this->returnValue( null ) );
		$migrate_obj->changeset_migrate( - 1 );
	}

	/**
	 * Test migrate_post.
	 *
	 * @see Migrate::migrate_post()
	 */
	function test_migrate_post() {
		$admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$old_post_type_obj = new Post_Type_Back_Compat( $this->snapshot_manager );
		$snapshot_post_id = $old_post_type_obj->save( array(
			'uuid' => wp_generate_uuid4(),
			'status' => 'draft',
			'data' => array(
				'foo' => array(
					'value' => 'bar',
				),
			),
		) );
		add_post_meta( $snapshot_post_id, '_snapshot_theme', 'foo_theme' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$wp_customize = new \WP_Customize_Manager( array( 'changeset_uuid' => self::UUID ) );

		$wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->action_customize_register_for_dynamic_settings();

		$migrate = new Migrate();

		$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		if ( $has_kses ) {
			kses_remove_filters(); // Prevent KSES from corrupting JSON in post_content.
		}
		$migrate->migrate_post( $snapshot_post_id );
		if ( $has_kses ) {
			kses_init_filters();
		}

		$changeset_post = get_post( $snapshot_post_id );
		$this->assertEquals( Post_Type::SLUG, $changeset_post->post_type );
		$data = json_decode( $changeset_post->post_content, true );
		$expected = array(
			'foo_theme::foo' => array(
				'value' => 'bar',
				'user_id' => (string) $admin_user_id,
				'type' => 'theme_mod',
			),
		);
		$this->assertSame( $expected, $data );
	}

	/**
	 * Add filter for dynamic setting.
	 */
	function action_customize_register_for_dynamic_settings() {
		add_filter( 'customize_dynamic_setting_args', array( $this, 'filter_customize_dynamic_setting_args_for_test_dynamic_settings' ), 10, 2 );
	}

	/**
	 * To support dynamic setting.
	 *
	 * @param array  $setting_args Setting args.
	 * @param string $setting_id   Setting ID.
	 * @return array
	 */
	function filter_customize_dynamic_setting_args_for_test_dynamic_settings( $setting_args, $setting_id ) {
		if ( in_array( $setting_id, array( 'foo' ), true ) ) {
			$setting_args = array( 'default' => "dynamic_{$setting_id}_default" );
		}
		return $setting_args;
	}
}
