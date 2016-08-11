<?php
/**
 * Test Customize_Snapshot_Manager.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Customize_Snapshot_Manager
 */
class Test_Customize_Snapshot_Manager extends \WP_UnitTestCase {

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
	 * @var Customize_Snapshot_Manager
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
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager(); // WPCS: global override ok.
		$this->wp_customize = $GLOBALS['wp_customize'];

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * Test constructor.
	 *
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_without_customize() {
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Manager', $this->manager );
		$this->assertInstanceOf( 'CustomizeSnapshots\Plugin', $this->manager->plugin );
		$this->assertNull( $this->manager->current_snapshot_uuid );
	}

	/**
	 * Test constructor with Customizer.
	 *
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_with_customize() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( $manager->current_snapshot_uuid, self::UUID );
		$this->assertInstanceOf( 'CustomizeSnapshots\Post_Type', $manager->post_type );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
		$this->assertEquals( 0, has_action( 'init', array( $manager, 'create_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_controls_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_update_snapshot', array( $manager, 'handle_update_snapshot_request' ) ) );
	}

	/**
	 * Test constructor with customizer bootstrapped.
	 *
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_with_customize_bootstrapped() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		unset( $GLOBALS['wp_customize'] );
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->ensure_customize_manager();
		$this->assertInstanceOf( 'WP_Customize_Manager', $GLOBALS['wp_customize'] );
	}

	/**
	 * Tests init hooks.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 */
	public function test_init_hooks() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$this->assertInstanceOf( __NAMESPACE__ . '\Post_Type', $manager->post_type );
		$this->assertEquals( 10, has_action( 'template_redirect', array( $manager, 'show_theme_switch_error' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_controls_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'customize_preview_init', array( $manager, 'customize_preview_init' ) ) );
		$this->assertEquals( 10, has_action( 'wp_enqueue_scripts', array( $manager, 'enqueue_frontend_scripts' ) ) );

		$this->assertEquals( 10, has_action( 'customize_controls_init', array( $manager, 'add_snapshot_uuid_to_return_url' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_print_footer_scripts', array( $manager, 'render_templates' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save', array( $manager, 'check_customize_publish_authorization' ) ) );
		$this->assertEquals( 10, has_filter( 'customize_refresh_nonces', array( $manager, 'filter_customize_refresh_nonces' ) ) );
		$this->assertEquals( 41, has_action( 'admin_bar_menu', array( $manager, 'customize_menu' ) ) );
		$this->assertEquals( 100000, has_action( 'admin_bar_menu', array( $manager, 'remove_all_non_snapshot_admin_bar_links' ) ) );
		$this->assertEquals( 10, has_action( 'wp_before_admin_bar_render', array( $manager, 'print_admin_bar_styles' ) ) );

		$this->assertEquals( 10, has_filter( 'wp_insert_post_data', array( $manager, 'prepare_snapshot_post_content_for_publish' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save_after', array( $manager, 'publish_snapshot_with_customize_save_after' ) ) );
		$this->assertEquals( 10, has_action( 'transition_post_status', array( $manager, 'save_settings_with_publish_snapshot' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_update_snapshot', array( $manager, 'handle_update_snapshot_request' ) ) );
	}

	/**
	 * Tests init hooks.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::read_current_snapshot_uuid()
	 */
	public function test_read_current_snapshot_uuid() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertFalse( $manager->read_current_snapshot_uuid() );
		$this->assertNull( $manager->current_snapshot_uuid );

		$_REQUEST['customize_snapshot_uuid'] = 'bad';
		$this->assertFalse( $manager->read_current_snapshot_uuid() );
		$this->assertNull( $manager->current_snapshot_uuid );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->assertTrue( $manager->read_current_snapshot_uuid() );
		$this->assertEquals( self::UUID, $manager->current_snapshot_uuid );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->current_snapshot_uuid );
	}

	/**
	 * Tests load_snapshot.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::load_snapshot()
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * Tests setup_preview_ajax_requests.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::setup_preview_ajax_requests()
	 */
	public function test_setup_preview_ajax_requests() {
		wp_set_current_user( $this->user_id );
		$_REQUEST['wp_customize_preview_ajax'] = 'true';
		$_POST['customized'] = wp_slash( wp_json_encode( array( 'blogname' => 'Foo' ) ) );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::setup_preview_ajax_requests()
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::override_request_method()
	 */
	public function test_override_request_method() {
		global $wp;

		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * Tests doing_customize_save_ajax.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::doing_customize_save_ajax()
	 */
	public function test_doing_customize_save_ajax() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertFalse( $manager->doing_customize_save_ajax() );

		$_REQUEST['action'] = 'foo';
		$this->assertFalse( $manager->doing_customize_save_ajax() );

		$_REQUEST['action'] = 'customize_save';
		$this->assertTrue( $manager->doing_customize_save_ajax() );
	}

	/**
	 * Tests ensure_customize_manager.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::ensure_customize_manager()
	 */
	public function test_ensure_customize_manager() {
		global $wp_customize;
		$wp_customize = null; // WPCS: global override ok.
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertEmpty( $manager->customize_manager );
		$manager->ensure_customize_manager();
		$this->assertInstanceOf( 'WP_Customize_Manager', $manager->customize_manager );
		$this->assertInstanceOf( 'WP_Customize_Manager', $wp_customize );
	}

	/**
	 * Tests is_theme_active.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::is_theme_active()
	 */
	public function test_is_theme_active() {
		global $wp_customize;
		$wp_customize = null; // WPCS: global override ok.
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertTrue( $manager->is_theme_active() );

		$manager->ensure_customize_manager();
		$this->assertTrue( $manager->is_theme_active() );
	}

	/**
	 * Tests should_import_and_preview_snapshot.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::should_import_and_preview_snapshot()
	 */
	public function test_should_import_and_preview_snapshot() {
		global $pagenow, $wp_customize;
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = $this->plugin->customize_snapshot_manager;
		$post_id = $manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'blogname' => array( 'value' => 'Foo' ) ),
		) );
		$snapshot = new Customize_Snapshot( $manager, self::UUID );

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
	 * Tests is_previewing_settings.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::is_previewing_settings()
	 */
	public function test_is_previewing_settings() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$this->plugin->customize_snapshot_manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'blogname' => array( 'value' => 'Foo' ) ),
		) );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$manager->preview_snapshot_settings();
		$this->assertTrue( $manager->is_previewing_settings() );
	}

	/**
	 * Tests is_previewing_settings.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::is_previewing_settings()
	 */
	public function test_is_previewing_settings_via_preview_init() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertFalse( $manager->is_previewing_settings() );
		do_action( 'customize_preview_init' );
		$this->assertTrue( $manager->is_previewing_settings() );
	}

	/**
	 * Tests preview_snapshot_settings.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::preview_snapshot_settings()
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

		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * Tests import_snapshot_data.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::import_snapshot_data()
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

		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * Tests add_widget_setting_preview_filters.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::add_widget_setting_preview_filters()
	 */
	public function test_add_widget_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests add_nav_menu_setting_preview_filters.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::add_nav_menu_setting_preview_filters()
	 */
	public function test_add_nav_menu_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests preview_early_nav_menus_in_customizer.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::preview_early_nav_menus_in_customizer()
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

		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		do_action( 'customize_register', $manager->customize_manager );

		$setting = $manager->customize_manager->get_setting( $setting_id );
		$this->assertInstanceOf( 'WP_Customize_Nav_Menu_Setting', $setting );
		$nav_menu = wp_get_nav_menu_object( $menu_id );
		$this->assertEquals( 'Bar', $nav_menu->name );

		$this->assertInstanceOf( 'WP_Customize_Nav_Menu_Section', $manager->customize_manager->get_section( $setting_id ) );
	}

	/**
	 * Test add snapshot uuid to return url.
	 *
	 * @see Customize_Snapshot_Manager::add_snapshot_uuid_to_return_url()
	 */
	public function test_add_snapshot_uuid_to_return_url() {
		global $wp_version;
		if ( version_compare( $wp_version, '4.4-beta', '>=' ) ) {
			$_REQUEST['customize_snapshot_uuid'] = self::UUID;
			$manager = new Customize_Snapshot_Manager( $this->plugin );
			$manager->init();
			$manager->ensure_customize_manager();
			$this->assertNotContains( 'customize_snapshot_uuid', $manager->customize_manager->get_return_url() );
			$manager->add_snapshot_uuid_to_return_url();
			$this->assertContains( 'customize_snapshot_uuid', $manager->customize_manager->get_return_url() );
		}
	}

	/**
	 * Tests show_theme_switch_error.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::show_theme_switch_error()
	 */
	function test_show_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests get_theme_switch_error.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::get_theme_switch_error()
	 */
	function test_get_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests check_customize_publish_authorization.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::check_customize_publish_authorization()
	 */
	function test_check_customize_publish_authorization() {
		$this->markTestIncomplete();
	}

	/**
	 * Test encode JSON.
	 *
	 * @see Customize_Snapshot_Manager::encode_json()
	 */
	function test_encode_json() {
		$array = array(
			'foo' => 'foo_value',
		);
		$json = '{"foo":"foo_value"}';
		$this->assertEquals( $json, preg_replace( '/\s+/', '', Customize_Snapshot_Manager::encode_json( $array ) ) );
	}

	/**
	 * Test enqueue controls scripts.
	 *
	 * @see Customize_Snapshot_Manager::enqueue_controls_scripts()
	 */
	function test_enqueue_controls_scripts() {
		$this->plugin->register_scripts( wp_scripts() );
		$this->plugin->register_styles( wp_styles() );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$manager->enqueue_controls_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'customize-snapshots', 'enqueued' ) );
	}

	/**
	 * Test customize preview init.
	 *
	 * @see Customize_Snapshot_Manager::customize_preview_init()
	 */
	function test_customize_preview_init() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
		$manager->enqueue_frontend_scripts();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertFalse( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
		$manager->enqueue_frontend_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots-frontend', 'enqueued' ) );
	}

	/**
	 * Test filter_customize_refresh_nonces.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::filter_customize_refresh_nonces()
	 */
	function test_filter_customize_refresh_nonces() {
		$this->markTestIncomplete();
	}

	/**
	 * Test snapshot method.
	 *
	 * @see Customize_Snapshot_Manager::snapshot()
	 */
	function test_snapshot() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
	}

	/**
	 * Test publish snapshot with customize_save_after.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::publish_snapshot_with_customize_save_after()
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEmpty( $manager->snapshot()->post() );
		$manager->publish_snapshot_with_customize_save_after();
		$this->assertNotEmpty( $manager->snapshot()->post() );

		$this->markTestIncomplete( 'Need to test when snapshot->save() returns errors, and when snapshot post save fails.' );
	}

	/**
	 * Test prepare_snapshot_post_content_for_publish.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::prepare_snapshot_post_content_for_publish()
	 */
	public function test_prepare_snapshot_post_content_for_publish() {
		$snapshot_manager = get_plugin_instance()->customize_snapshot_manager;
		$data = array(
			'blogdescription' => array( 'value' => 'Snapshot blog' ),
			'foo' => array(
				'value' => 'bar',
				'publish_error' => 'unrecognized_setting',
			),
			'baz' => array(
				'value' => null,
				'publish_error' => 'null_value',
			),
		);
		$validate_data = array(
			'blogdescription' => array( 'value' => 'Snapshot blog' ),
			'foo' => array( 'value' => 'bar' ),
			'baz' => array( 'value' => null ),
		);
		$data_without_errors = $this->manager->prepare_snapshot_post_content_for_publish( array(
			'post_type' => Post_Type::SLUG,
			'post_content' => Customize_Snapshot_Manager::encode_json( $data ),
			'post_status' => 'publish',
		) );
		$this->assertEquals( $validate_data, json_decode( wp_unslash( $data_without_errors['post_content'] ), true ) );
	}

	/**
	 * Test save_settings_with_publish_snapshot.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::save_settings_with_publish_snapshot()
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
			$validate_data['foo']['publish_error'] = 'invalid_value';
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
	 * For Customize_Snapshot_Manager::handle_update_snapshot_request(), see Test_Ajax_Customize_Snapshot_Manager.
	 */

	/**
	 * Test prepare_errors_for_response.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::prepare_errors_for_response()
	 */
	public function test_prepare_errors_for_response() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests generate_uuid.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::generate_uuid()
	 */
	public function test_generate_uuid() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests is_valid_uuid.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::is_valid_uuid()
	 */
	public function test_is_valid_uuid() {
		$this->markTestIncomplete();
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
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

	/**
	 * Test customize menu return.
	 *
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu_return() {
		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( admin_url() );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertNull( $wp_admin_bar->get_node( 'customize' ) );
	}

	/**
	 * Tests print_admin_bar_styles.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::print_admin_bar_styles()
	 */
	public function test_print_admin_bar_styles() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		ob_start();
		$manager->print_admin_bar_styles();
		$contents = ob_get_clean();
		$this->assertContains( '<style', $contents );
	}

	/**
	 * Test replace_customize_link.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::replace_customize_link()
	 */
	public function test_replace_customize_link() {
		global $wp_admin_bar;
		set_current_screen( 'front' );

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		remove_all_actions( 'admin_bar_menu' );
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;

		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		// Ensure customize link remains unknown if user lacks cap.
		wp_set_current_user( 0 );
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'customize' ) );

		// Ensure customize link modified.
		wp_set_current_user( $this->user_id );
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$node = $wp_admin_bar->get_node( 'customize' );
		$this->assertTrue( is_object( $node ) );
		$parsed_url = wp_parse_url( $node->href );
		$query_params = array();
		parse_str( $parsed_url['query'], $query_params );
		$this->assertArrayHasKey( 'customize_snapshot_uuid', $query_params );
		$this->assertEquals( self::UUID, $query_params['customize_snapshot_uuid'] );
		$this->assertArrayHasKey( 'url', $query_params );
		$parsed_preview_url = wp_parse_url( $query_params['url'] );
		$this->assertArrayNotHasKey( 'query', $parsed_preview_url );
	}

	/**
	 * Test misc admin bar extensions.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::add_post_edit_screen_link()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::add_snapshot_exit_link()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::add_resume_snapshot_link()
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::remove_all_non_snapshot_admin_bar_links()
	 */
	public function test_add_post_edit_and_exit_links() {
		global $wp_admin_bar;
		set_current_screen( 'front' );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$this->manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'blogname' => array(
					'value' => 'Hello',
				),
			),
			'status' => 'draft',
		) );

		remove_all_actions( 'admin_bar_menu' );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'inspect-customize-snapshot' ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'exit-customize-snapshot' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'wporg' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'resume-customize-snapshot' ) );

		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		remove_all_actions( 'admin_bar_menu' );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'inspect-customize-snapshot' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'exit-customize-snapshot' ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'wporg' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'resume-customize-snapshot' ) );
	}

	/**
	 * Test render templates.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::render_templates()
	 */
	public function test_render_templates() {
		ob_start();
		$this->manager->render_templates();
		$templates = ob_get_contents();
		ob_end_clean();
		$this->assertContains( 'tmpl-snapshot-save', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-error', $templates );
		$this->assertContains( 'tmpl-snapshot-preview-link', $templates );
		$this->assertContains( 'tmpl-snapshot-schedule-button', $templates );
		$this->assertContains( 'tmpl-snapshot-schedule', $templates );
		$this->assertContains( 'tmpl-snapshot-scheduled-countdown', $templates );
		$this->assertContains( 'tmpl-snapshot-submit', $templates );
	}

	/**
	 * Test format_gmt_offset
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::format_gmt_offset()
	 */
	public function test_format_gmt_offset() {
		$offset = $this->manager->format_gmt_offset( 7.0 );
		$this->assertEquals( '+7', $offset );
	}

	/**
	 * Test month choices
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::get_month_choices()
	 */
	public function test_get_month_choices() {
		$data = $this->manager->get_month_choices();
		$this->assertArrayHasKey( 'month_choices', $data );
		$this->assertCount( 12, $data['month_choices'] );
	}

	/**
	 * Test override post date if empty.
	 *
	 * @covers CustomizeSnapshots\Customize_Snapshot_Manager::override_post_date_default_data()
	 */
	public function test_override_post_date_default_data() {
		$post_id = $this->factory()->post->create();
		$post = get_post( $post_id );
		$post->post_date = $post->post_date_gmt = $post->post_modified = $post->post_modified_gmt = '0000-00-00 00:00:00';
		$this->manager->override_post_date_default_data( $post );
		$this->assertNotEquals( $post->post_date, '0000-00-00 00:00:00' );
		$this->assertNotEquals( $post->post_date_gmt, '0000-00-00 00:00:00' );
		$this->assertNotEquals( $post->post_modified, '0000-00-00 00:00:00' );
		$this->assertNotEquals( $post->post_modified_gmt, '0000-00-00 00:00:00' );
	}
}
