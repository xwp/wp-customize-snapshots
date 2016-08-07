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
	 * Tests init.
	 *
	 * @covers Customize_Snapshot_Manager::init()
	 */
	public function test_init() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests doing_customize_save_ajax.
	 *
	 * @covers Customize_Snapshot_Manager::doing_customize_save_ajax()
	 */
	public function test_doing_customize_save_ajax() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests ensure_customize_manager.
	 *
	 * @covers Customize_Snapshot_Manager::ensure_customize_manager()
	 */
	public function test_ensure_customize_manager() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests is_theme_active.
	 *
	 * @covers Customize_Snapshot_Manager::is_theme_active()
	 */
	public function test_is_theme_active() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests should_import_and_preview_snapshot.
	 *
	 * @covers Customize_Snapshot_Manager::should_import_and_preview_snapshot()
	 */
	public function test_should_import_and_preview_snapshot() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests preview_snapshot_settings.
	 *
	 * @covers Customize_Snapshot_Manager::preview_snapshot_settings()
	 */
	public function test_preview_snapshot_settings() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests import_snapshot_data.
	 *
	 * @covers Customize_Snapshot_Manager::import_snapshot_data()
	 */
	public function test_import_snapshot_data() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests add_widget_setting_preview_filters.
	 *
	 * @covers Customize_Snapshot_Manager::add_widget_setting_preview_filters()
	 */
	public function test_add_widget_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests add_nav_menu_setting_preview_filters.
	 *
	 * @covers Customize_Snapshot_Manager::add_nav_menu_setting_preview_filters()
	 */
	public function test_add_nav_menu_setting_preview_filters() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests preview_early_nav_menus_in_customizer.
	 *
	 * @covers Customize_Snapshot_Manager::preview_early_nav_menus_in_customizer()
	 */
	public function test_preview_early_nav_menus_in_customizer() {
		$this->markTestIncomplete();
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
	 * Test remove snapshot uuid from current url.
	 *
	 * @covers Customize_Snapshot_Manager::remove_snapshot_uuid_from_current_url()
	 * @covers Customize_Snapshot_Manager::current_url()
	 */
	function test_remove_snapshot_uuid_from_current_url() {
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );
		ob_start();
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertContains( 'customize_snapshot_uuid', $manager->current_url() );
		echo $manager->remove_snapshot_uuid_from_current_url(); // WPCS: xss ok.
		$buffer = ob_get_clean();
		$this->assertEquals( home_url( '/' ), $buffer );
	}

	/**
	 * Tests show_theme_switch_error.
	 *
	 * @covers Customize_Snapshot_Manager::show_theme_switch_error()
	 */
	function test_show_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests get_theme_switch_error.
	 *
	 * @covers Customize_Snapshot_Manager::get_theme_switch_error()
	 */
	function test_get_theme_switch_error() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests check_customize_publish_authorization.
	 *
	 * @covers Customize_Snapshot_Manager::check_customize_publish_authorization()
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
	 * @covers Customize_Snapshot_Manager::filter_customize_refresh_nonces()
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
	 * @covers Customize_Snapshot_Manager::publish_snapshot_with_customize_save_after()
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
	 * @covers Customize_Snapshot_Manager::prepare_snapshot_post_content_for_publish()
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
	 * @covers Customize_Snapshot_Manager::save_settings_with_publish_snapshot()
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
	 * @covers Customize_Snapshot_Manager::prepare_errors_for_response()
	 */
	public function test_prepare_errors_for_response() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests generate_uuid.
	 *
	 * @covers Customize_Snapshot_Manager::generate_uuid()
	 */
	public function test_generate_uuid() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests is_valid_uuid.
	 *
	 * @covers Customize_Snapshot_Manager::is_valid_uuid()
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
		$customize_url = admin_url( 'customize.php' ) . '?customize_snapshot_uuid=' . self::UUID . '&url=' . urlencode( esc_url( home_url( '/' ) ) );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar();
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->user_id );
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEquals( $customize_url, $wp_admin_bar->get_node( 'customize' )->href );
	}

	/**
	 * Test customize menu return.
	 *
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu_return() {
		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar;
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( admin_url() );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertNull( $wp_admin_bar->get_node( 'customize' ) );
	}

	/**
	 * Test add_post_edit_screen_link.
	 *
	 * @covers Customize_Snapshot_Manager::add_post_edit_screen_link()
	 */
	public function test_add_post_edit_screen_link() {
		$this->markTestIncomplete();
	}

	/**
	 * Test replace_customize_link.
	 *
	 * @covers Customize_Snapshot_Manager::replace_customize_link()
	 */
	public function test_replace_customize_link() {
		$this->markTestIncomplete();
	}

	/**
	 * Test render templates.
	 *
	 * @see Customize_Snapshot_Manager::render_templates()
	 */
	public function test_render_templates() {
		ob_start();
		$this->manager->render_templates();
		$templates = ob_get_contents();
		ob_end_clean();
		$this->assertContains( 'tmpl-snapshot-save', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-error', $templates );
	}
}
