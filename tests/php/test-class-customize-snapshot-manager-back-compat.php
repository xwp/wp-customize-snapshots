<?php
/**
 * Test Customize_Snapshot_Manager_Back_Compat.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Customize_Snapshot_Manager_Back_Compat
 */
class Test_Customize_Snapshot_Manager_Back_Compat extends \WP_UnitTestCase {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * A valid UUID.
	 *
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * Customize Manager.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * Snapshot Manager.
	 *
	 * @var Customize_Snapshot_Manager_Back_Compat
	 */
	protected $manager;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * CSS Concat Init Priority.
	 *
	 * @var int
	 */
	protected $css_concat_init_priority;

	/**
	 * JS Concat Init Priority.
	 *
	 * @var int
	 */
	protected $js_concat_init_priority;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		if ( ! $this->plugin->compat ) {
			$this->markTestSkipped( 'WordPress Version 4.6.x or below is required for this test-case.' );
		}
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager(); // WPCS: global override ok.
		$this->wp_customize = $GLOBALS['wp_customize'];

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->manager->init();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		remove_action( 'after_setup_theme', 'twentyfifteen_setup' );
		remove_action( 'after_setup_theme', 'twentysixteen_setup' );
		remove_all_actions( 'send_headers' ); // Prevent X-hacker header in VIP Quickstart.

		// For why these hooks have to be removed, see <https://github.com/Automattic/nginx-http-concat/issues/5>.
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
	}

	/**
	 * Clean up global scope.
	 */
	function clean_up_global_scope() {
		unset( $GLOBALS['wp_scripts'] );
		unset( $GLOBALS['wp_styles'] );
		unset( $_REQUEST['customize_snapshot_uuid'] );
		unset( $_REQUEST['wp_customize_preview_ajax'] );
		parent::clean_up_global_scope();
	}

	/**
	 * Tear down.
	 */
	function tearDown() {
		$this->wp_customize = null;
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['screen'] );
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Set wp_customize query param.
	 */
	function do_customize_on() {
		$_REQUEST['wp_customize'] = 'on';
	}

	/**
	 * Do Customize boot actions.
	 *
	 * @param bool $on Whether to turn on Customizer.
	 */
	function do_customize_boot_actions( $on = false ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		do_action( 'setup_theme' );
		$_REQUEST['nonce'] = wp_create_nonce( 'preview-customize_' . $this->wp_customize->theme()->get_stylesheet() );
		do_action( 'after_setup_theme' );
		do_action( 'init' );
		do_action( 'wp_loaded' );
		do_action( 'wp', $GLOBALS['wp'] );
		if ( $on ) {
			$this->do_customize_on();
		}
	}

	/**
	 * Tests load_snapshot.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::load_snapshot()
	 */
	public function test_load_snapshot() {
		global $wp_actions;
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->plugin->customize_snapshot_manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'blogname' => array( 'value' => 'Hello' ),
			),
			'status' => 'draft',
		) );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		unset( $wp_actions['setup_theme'] );
		unset( $wp_actions['wp_loaded'] );
		$manager->init();
		$this->assertNotEmpty( $manager->customize_manager );
		$this->assertNotEmpty( $manager->snapshot );

		$this->assertEquals( 10, has_action( 'setup_theme', array( $manager, 'import_snapshot_data' ) ) );
		$this->assertEquals( 10, has_action( 'wp_head', 'wp_no_robots' ) );
		$this->assertEquals( 11, has_action( 'wp_loaded', array( $manager, 'preview_snapshot_settings' ) ) );
	}

	/**
	 * Test constructor with Customizer.
	 *
	 * @see Customize_Snapshot_Manager_Back_Compat::__construct()
	 */
	function test_construct_with_customize() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$this->assertEquals( $manager->current_snapshot_uuid, self::UUID );
		$this->assertInstanceOf( 'CustomizeSnapshots\Post_Type_Back_Compat', $manager->post_type );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Back_Compat', $manager->snapshot() );
		$this->assertEquals( 0, has_action( 'init', array( $manager, 'create_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_controls_scripts' ) ) );
	}

	/**
	 * Test init.
	 *
	 * @see Customize_Snapshot_Manager_Back_Compat::init()
	 */
	function test_init() {
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$this->assertEquals( 10, has_filter( 'customize_refresh_nonces', array( $manager, 'filter_customize_refresh_nonces' ) ) );
		$this->assertEquals( 10, has_action( 'template_redirect', array( $manager, 'show_theme_switch_error' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save_after', array( $manager, 'publish_snapshot_with_customize_save_after' ) ) );
		$this->assertEquals( 10, has_action( 'transition_post_status', array( $manager, 'save_settings_with_publish_snapshot' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_update_snapshot', array( $manager, 'handle_update_snapshot_request' ) ) );
		$this->assertEquals( 10, has_action( 'customize_preview_init', array( $manager, 'customize_preview_init' ) ) );
		$this->assertEquals( 10, has_action( 'wp_enqueue_scripts', array( $manager, 'enqueue_frontend_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save', array( $manager, 'check_customize_publish_authorization' ) ) );
	}

	/*
	 * For Customize_Snapshot_Manager_Back_Compat::Customize_Snapshot_Manager_Back_Compat(), see Test_Ajax_Customize_Snapshot_Manager_Back_Compat::test_ajax_update_snapshot_cap_check().
	 */

	/**
	 * Test customize preview init.
	 *
	 * @see Customize_Snapshot_Manager::customize_preview_init()
	 */
	function test_customize_preview_init() {
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $manager, 'enqueue_preview_scripts' ) ) );
		$manager->customize_preview_init();
		$this->assertEquals( 10, has_action( 'wp_enqueue_scripts', array( $manager, 'enqueue_preview_scripts' ) ) );
	}

	/**
	 * Test enqueue preview scripts.
	 *
	 * @see Customize_Snapshot_Manager::enqueue_preview_scripts()
	 */
	function test_enqueue_preview_scripts() {
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->ensure_customize_manager();
		$manager->init();
		$handle = 'customize-snapshots-preview';
		$this->assertFalse( wp_scripts()->query( $handle, 'enqueued' ) );
		$this->assertFalse( wp_styles()->query( $handle, 'enqueued' ) );
		$manager->enqueue_preview_scripts();
		$this->assertTrue( wp_scripts()->query( $handle, 'enqueued' ) );
		$this->assertTrue( wp_styles()->query( $handle, 'enqueued' ) );

		$after = wp_scripts()->get_data( $handle, 'after' );
		$this->assertNotEmpty( $after );
		$this->assertContains( 'CustomizeSnapshotsPreview', join( '', $after ) );
	}

	/**
	 * Test enqueue frontend scripts.
	 *
	 * @see Customize_Snapshot_Manager::enqueue_frontend_scripts()
	 */
	function test_enqueue_frontend_scripts() {
		$this->plugin->register_scripts( wp_scripts() );
		$this->plugin->register_styles( wp_styles() );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
		$manager->enqueue_frontend_scripts();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
		$manager->enqueue_frontend_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
	}

	/**
	 * Test filter_customize_refresh_nonces.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Back_Compat::filter_customize_refresh_nonces()
	 */
	function test_filter_customize_refresh_nonces() {
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->assertArrayHasKey( 'snapshot', $manager->filter_customize_refresh_nonces( array() ) );
	}

	/**
	 * Tests show_theme_switch_error.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Back_Compat::show_theme_switch_error()
	 */
	function test_show_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Test publish snapshot with customize_save_after.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Back_Compat::publish_snapshot_with_customize_save_after()
	 */
	function test_publish_snapshot_with_customize_save_after() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'customize_snapshot_uuid' => self::UUID,
			'customized' => '{"foo":"foo_default","bar":"bar_default"}',
		);
		$_REQUEST['action'] = 'customize_save';
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$this->assertEmpty( $manager->snapshot()->post() );
		$manager->publish_snapshot_with_customize_save_after();
		$this->assertNotEmpty( $manager->snapshot()->post() );

		$this->markTestIncomplete( 'Need to test when snapshot->save() returns errors, and when snapshot post save fails.' );
	}

	/**
	 * Test save_settings_with_publish_snapshot.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Back_Compat::save_settings_with_publish_snapshot()
	 */
	public function test_save_settings_with_publish_snapshot() {
		$post_type = $this->manager->post_type;
		$data = array(
			'blogdescription' => array( 'value' => 'Snapshot blog' ),
			'unknown_setting_foo' => array( 'value' => 'bar' ),
			'null_value_baz' => array( 'value' => null ),
			'foo' => array( 'value' => 'foo' ),
		);
		$validate_data = array(
			'blogdescription' => array( 'value' => 'Snapshot blog' ),
			'unknown_setting_foo' => array(
				'value' => 'bar',
				'publish_error' => 'unrecognized_setting',
			),
			'null_value_baz' => array(
				'value' => null,
				'publish_error' => 'null_value',
			),
			'foo' => array(
				'value' => 'foo',
			),
		);

		if ( method_exists( 'WP_Customize_Setting', 'validate' ) ) {
			$validate_data['foo']['publish_error'] = 'you_shell_not_pass';
			add_filter( 'customize_validate_foo', function( $validity ) {
				$validity->add( 'you_shell_not_pass', 'Testing invalid setting while publishing snapshot' );
				return $validity;
			}, 10, 1 );
		}

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
		) );

		// Test invalid settings.
		$post = get_post( $post_id );
		$this->manager->save_settings_with_publish_snapshot( 'publish', 'draft', $post );
		$post = get_post( $post_id );
		$this->assertEquals( $validate_data, json_decode( wp_unslash( $post->post_content ), true ) );
		$this->assertEquals( 'pending', $post->post_status );

		// Test valid settings.
		unset( $data['unknown_setting_foo'], $data['null_value_baz'], $data['foo'] );
		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );
		$this->assertEquals( 'publish', get_post_status( $post_id ) );
		$this->assertEquals( 'Snapshot blog', get_bloginfo( 'description' ) );
	}

	/*
	 * For Customize_Snapshot_Manager::handle_update_snapshot_request(), see Test_Ajax_Customize_Snapshot_Manager_Back_Compat.
	 */

	/**
	 * Tests ensure_customize_manager.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::ensure_customize_manager()
	 */
	public function test_ensure_customize_manager() {
		global $wp_customize;
		$wp_customize = null; // WPCS: global override ok.
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->assertEmpty( $manager->customize_manager );
		$manager->ensure_customize_manager();
		$this->assertInstanceOf( 'WP_Customize_Manager', $manager->customize_manager );
		$this->assertInstanceOf( 'WP_Customize_Manager', $wp_customize );
	}

	/**
	 * Test enqueue controls scripts.
	 *
	 * @see Customize_Snapshot_Manager::enqueue_controls_scripts()
	 */
	function test_enqueue_controls_scripts() {
		$this->plugin->register_scripts( wp_scripts() );
		$this->plugin->register_styles( wp_styles() );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$manager->enqueue_controls_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots-compat', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'customize-snapshots', 'enqueued' ) );
	}

	/**
	 * Tests import_snapshot_data.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::import_snapshot_data()
	 */
	public function test_import_snapshot_data() {
		global $wp_actions;
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'blogname' => array( 'value' => 'Hello' ),
				'blogdescription' => array( 'value' => null ),
			),
			'status' => 'draft',
		) );

		// Prevent init from calling import_snapshot_data straight away.
		unset( $wp_actions['setup_theme'] );

		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$manager->ensure_customize_manager();
		do_action( 'customize_register', $manager->customize_manager );

		$this->assertArrayNotHasKey( 'customized', $_POST );
		$this->assertArrayNotHasKey( 'customized', $_REQUEST );
		$this->assertArrayNotHasKey( 'blogname', $manager->customize_manager->unsanitized_post_values() );
		$this->assertArrayNotHasKey( 'blogdescription', $manager->customize_manager->unsanitized_post_values() );
		$manager->import_snapshot_data();
		$this->assertArrayHasKey( 'customized', $_POST );
		$this->assertArrayHasKey( 'customized', $_REQUEST );
		$this->assertArrayHasKey( 'blogname', $manager->customize_manager->unsanitized_post_values() );
		$this->assertArrayNotHasKey( 'blogdescription', $manager->customize_manager->unsanitized_post_values() );
	}

	/**
	 * Tests should_import_and_preview_snapshot.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::should_import_and_preview_snapshot()
	 */
	public function test_should_import_and_preview_snapshot() {
		global $pagenow, $wp_customize;
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = $this->plugin->customize_snapshot_manager;
		$post_id = $manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'blogname' => array( 'value' => 'Foo' ) ),
		) );
		$snapshot = new Customize_Snapshot_Back_Compat( $manager, self::UUID );

		// Not if admin.
		set_current_screen( 'posts' );
		$pagenow = 'posts.php'; // WPCS: global override ok.
		$this->assertTrue( is_admin() );
		$this->assertFalse( $manager->should_import_and_preview_snapshot( $snapshot ) );

		// Not if theme switch error.
		set_current_screen( 'customize' );
		$pagenow = 'customize.php'; // WPCS: global override ok.
		update_post_meta( $post_id, '_snapshot_theme', 'Foo' );
		$this->assertFalse( $manager->should_import_and_preview_snapshot( $snapshot ) );
		delete_post_meta( $post_id, '_snapshot_theme' );

		// Not if customize_save.
		$_REQUEST['action'] = 'customize_save';
		$this->assertFalse( $manager->should_import_and_preview_snapshot( $snapshot ) );
		unset( $_REQUEST['action'] );

		// Not if published snapshot.
		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'status' => 'publish',
		) );
		$this->assertFalse( $manager->should_import_and_preview_snapshot( $snapshot ) );
		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'status' => 'draft',
		) );

		// Not if unsanitized post values is not empty.
		$manager->customize_manager = new \WP_Customize_Manager();
		$wp_customize = $manager->customize_manager; // WPCS: global override ok.
		$wp_customize->set_post_value( 'name', 'value' );
		$this->assertNotEmpty( $manager->customize_manager->unsanitized_post_values() );
		$this->assertFalse( $manager->should_import_and_preview_snapshot( $snapshot ) );

		// OK.
		$manager->customize_manager = new \WP_Customize_Manager();
		$wp_customize = $manager->customize_manager; // WPCS: global override ok.
		$this->assertTrue( $manager->should_import_and_preview_snapshot( $snapshot ) );
	}

	/**
	 * Tests get_theme_switch_error.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::get_theme_switch_error()
	 */
	function test_get_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests is_previewing_settings.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::is_previewing_settings()
	 */
	public function test_is_previewing_settings() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->plugin->customize_snapshot_manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'blogname' => array( 'value' => 'Foo' ) ),
		) );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$manager->preview_snapshot_settings();
		$this->assertTrue( $manager->is_previewing_settings() );
	}

	/**
	 * Tests is_previewing_settings.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::is_previewing_settings()
	 */
	public function test_is_previewing_settings_via_preview_init() {
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->assertFalse( $manager->is_previewing_settings() );
		do_action( 'customize_preview_init' );
		$this->assertTrue( $manager->is_previewing_settings() );
	}

	/**
	 * Tests preview_snapshot_settings.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::preview_snapshot_settings()
	 */
	public function test_preview_snapshot_settings() {
		global $wp_actions;
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'blogname' => array( 'value' => 'Hello' ),
			),
			'status' => 'draft',
		) );

		// Prevent init from calling preview_snapshot_settings straight away.
		unset( $wp_actions['wp_loaded'] );

		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		$manager->ensure_customize_manager();
		do_action( 'customize_register', $manager->customize_manager );
		$this->assertFalse( $manager->is_previewing_settings() );
		$this->assertFalse( $manager->customize_manager->get_setting( 'blogname' )->dirty );
		$this->assertNotEquals( 'Hello', get_option( 'blogname' ) );
		$manager->preview_snapshot_settings();
		$this->assertEquals( 'Hello', get_option( 'blogname' ) );
		$this->assertTrue( $manager->customize_manager->get_setting( 'blogname' )->dirty );
	}

	/**
	 * Tests add_widget_setting_preview_filters.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_widget_setting_preview_filters()
	 */
	public function test_add_widget_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests add_nav_menu_setting_preview_filters.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_nav_menu_setting_preview_filters()
	 */
	public function test_add_nav_menu_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests preview_early_nav_menus_in_customizer.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::preview_early_nav_menus_in_customizer()
	 */
	public function test_preview_early_nav_menus_in_customizer() {
		global $pagenow;
		$pagenow = 'customize.php'; // WPCS: Global override ok.
		set_current_screen( 'customize' );

		$menu_id = -123;
		$setting_id = sprintf( 'nav_menu[%d]', $menu_id );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				$setting_id => array(
					'value' => array(
						'name' => 'Bar',
					),
				),
			),
			'status' => 'draft',
		) );

		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		do_action( 'customize_register', $manager->customize_manager );

		$setting = $manager->customize_manager->get_setting( $setting_id );
		$this->assertInstanceOf( 'WP_Customize_Nav_Menu_Setting', $setting );
		$nav_menu = wp_get_nav_menu_object( $menu_id );
		$this->assertEquals( 'Bar', $nav_menu->name );

		$this->assertInstanceOf( 'WP_Customize_Nav_Menu_Section', $manager->customize_manager->get_section( $setting_id ) );
	}

	/**
	 * Tests setup_preview_ajax_requests.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::setup_preview_ajax_requests()
	 */
	public function test_setup_preview_ajax_requests() {
		wp_set_current_user( $this->user_id );
		$_REQUEST['wp_customize_preview_ajax'] = 'true';
		$_POST['customized'] = wp_slash( wp_json_encode( array( 'blogname' => 'Foo' ) ) );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$manager->init();
		$this->assertEquals( 12, has_action( 'wp_loaded', array( $manager, 'setup_preview_ajax_requests' ) ) );
		do_action( 'wp_loaded' );

		$this->assertFalse( has_action( 'shutdown', array( $this->wp_customize, 'customize_preview_signature' ) ) );
		$this->assertEquals( 5, has_action( 'parse_request', array( $manager, 'override_request_method' ) ) );
	}


	/**
	 * Tests setup_preview_ajax_requests for admin_ajax.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::setup_preview_ajax_requests()
	 */
	public function test_setup_preview_ajax_requests_for_admin_ajax() {
		global $pagenow;
		wp_set_current_user( $this->user_id );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'GET';
		$pagenow = 'admin-ajax.php'; // WPCS: Global override ok.
		set_current_screen( 'admin-ajax' );
		$this->assertTrue( is_admin() );

		$_REQUEST['wp_customize_preview_ajax'] = 'true';
		$_POST['customized'] = wp_slash( wp_json_encode( array( 'blogname' => 'Foo' ) ) );
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();
		do_action( 'admin_init' );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$this->assertFalse( has_action( 'shutdown', array( $this->wp_customize, 'customize_preview_signature' ) ) );
		$this->assertFalse( has_action( 'parse_request', array( $manager, 'override_request_method' ) ) );
		$this->assertEquals( 'GET', $_SERVER['REQUEST_METHOD'] );
		$this->assertEquals( 'Foo', get_option( 'blogname' ) );
	}

	/**
	 * Tests override_request_method.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::override_request_method()
	 */
	public function test_override_request_method() {
		global $wp;

		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$this->assertFalse( $manager->override_request_method() );

		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'GET';
		$wp->query_vars['rest_route'] = '/wp/v1/foo';
		$this->assertFalse( $manager->override_request_method() );
		unset( $wp->query_vars['rest_route'] );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'GET';
		$this->assertFalse( $manager->override_request_method() );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'BAD';
		$this->assertFalse( $manager->override_request_method() );

		$_GET = wp_slash( array( 'foo' => '1' ) );
		$_POST = wp_slash( array( 'bar' => '2' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'GET';
		$this->assertTrue( $manager->override_request_method() );
		$this->assertEquals( 'GET', $_SERVER['REQUEST_METHOD'] );
		$this->assertEquals( 'foo=1&bar=2', $_SERVER['QUERY_STRING'] );
		$this->assertArrayHasKey( 'foo', $_GET );
		$this->assertArrayHasKey( 'bar', $_GET );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';
		$this->assertFalse( $manager->override_request_method() );
	}

	/**
	 * Test customize menu.
	 *
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu() {
		set_current_screen( 'front' );
		$preview_url = home_url( '/' );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager_Back_Compat( $this->plugin );
		$manager->init();

		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->user_id );
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$parsed_url = wp_parse_url( $wp_admin_bar->get_node( 'customize' )->href );
		$query_params = array();
		wp_parse_str( $parsed_url['query'], $query_params );
		$this->assertEquals( $preview_url, $query_params['url'] );
		$this->assertEquals( self::UUID, $query_params['customize_snapshot_uuid'] );
	}

}
