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
	 * Frontend UUID
	 *
	 * @var string
	 */
	public $front_param;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		if ( $this->plugin->compat ) {
			$this->front_param = 'customize_snapshot_uuid';
		} else {
			$this->front_param = 'customize_changeset_uuid';
		}
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager(); // WPCS: global override ok.
		$this->wp_customize = $GLOBALS['wp_customize'];

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager = $this->get_snapshot_manager_instance( $this->plugin );
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
		unset( $_REQUEST[ $this->front_param ] );
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
	 * Get snapshot manager instance according to WP version.
	 *
	 * @param Plugin $plugin Plugin object.
	 *
	 * @return Customize_Snapshot_Manager|Customize_Snapshot_Manager_Back_Compat Manager new instace.
	 */
	function get_snapshot_manager_instance( $plugin ) {
		if ( $this->plugin->compat ) {
			return new Customize_Snapshot_Manager_Back_Compat( $plugin );
		} else {
			return new Customize_Snapshot_Manager( $plugin );
		}
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
	 * Test constructor.
	 *
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_without_customize() {
		$this->mark_incompatible();
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
		$this->mark_incompatible();
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$_REQUEST[ $this->front_param ] = self::UUID;
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$this->assertEquals( $manager->current_snapshot_uuid, self::UUID );
		$this->assertInstanceOf( 'CustomizeSnapshots\Post_Type', $manager->post_type );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
		$this->assertEquals( 0, has_action( 'init', array( $manager, 'create_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_controls_scripts' ) ) );
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
		$_REQUEST[ $this->front_param ] = self::UUID;
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->ensure_customize_manager();
		$this->assertInstanceOf( 'WP_Customize_Manager', $GLOBALS['wp_customize'] );
	}

	/**
	 * Test common hooks.
	 *
	 * @see \CustomizeSnapshots\Customize_Snapshot_Manager::hooks()
	 */
	function test_hooks() {
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$this->assertEquals( 10, has_action( 'init', array( $manager->post_type, 'init' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_controls_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'admin_enqueue_scripts', array( $manager, 'enqueue_admin_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_init', array( $manager, 'add_snapshot_uuid_to_return_url' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_print_footer_scripts', array( $manager, 'render_templates' ) ) );
		$this->assertEquals( 41, has_action( 'admin_bar_menu', array( $manager, 'customize_menu' ) ) );
		$this->assertEquals( 100000, has_action( 'admin_bar_menu', array( $manager, 'remove_all_non_snapshot_admin_bar_links' ) ) );
		$this->assertEquals( 10, has_action( 'wp_before_admin_bar_render', array( $manager, 'print_admin_bar_styles' ) ) );
		$this->assertEquals( 10, has_filter( 'removable_query_args', array( $manager, 'filter_removable_query_args' ) ) );
		$this->assertEquals( 10, has_action( 'save_post_' . $manager->get_post_type(), array( $manager, 'create_initial_changeset_revision' ) ) );
		$this->assertEquals( 10, has_action( 'save_post_' . $manager->get_post_type(), array( $manager, 'save_customizer_state_query_vars' ) ) );
		$this->assertEquals( 10, has_filter( 'wp_insert_post_data', array( $manager, 'prepare_snapshot_post_content_for_publish' ) ) );
		$this->assertEquals( 10, has_action( 'delete_post', array( $manager, 'clean_up_nav_menus_created_auto_drafts' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_snapshot_conflict_check', array( $manager, 'handle_conflicts_snapshot_request' ) ) );
	}

	/**
	 * Tests init hooks.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 */
	public function test_init_hooks() {
		$this->mark_incompatible();
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$this->assertEquals( 10, has_filter( 'customize_save_response', array( $manager, 'add_snapshot_var_to_customize_save' ) ) );
		$this->assertInstanceOf( __NAMESPACE__ . '\Post_Type', $manager->post_type );
	}

	/**
	 * Tests load_snapshot.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::load_snapshot()
	 */
	public function test_load_snapshot() {
		$this->mark_incompatible();
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertNull( $manager->snapshot );
		$this->assertNull( $manager->customize_manager );
		$manager->load_snapshot();
		$this->assertInstanceOf( __NAMESPACE__ . '\\Customize_Snapshot', $manager->snapshot );
		$this->assertInstanceOf( '\WP_Customize_Manager', $manager->customize_manager );
	}
	/**
	 * Tests add_snapshot_var_to_customize_save.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_snapshot_var_to_customize_save()
	 */
	public function test_add_snapshot_var_to_customize_save() {
		$this->mark_incompatible();
		global $wp_customize;
		$uuid = wp_generate_uuid4();
		get_plugin_instance()->customize_snapshot_manager->post_type->save( array(
			'uuid' => $uuid,
			'data' => array(),
			'status' => 'draft',
		) );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$wp_customize = null; // WPCS: global override ok.
		$manager->current_snapshot_uuid = $uuid;
		$manager->load_snapshot();
		$data = $manager->add_snapshot_var_to_customize_save( array(), $manager->customize_manager );
		$this->assertArrayHasKey( 'edit_link', $data );
		$this->assertArrayHasKey( 'publish_date', $data );
		$this->assertArrayHasKey( 'title', $data );
	}

	/**
	 * Tests enqueue_admin_scripts.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::enqueue_admin_scripts()
	 */
	public function test_enqueue_admin_scripts() {
		$this->markTestIncomplete();
	}

	/**
	 * Tests init hooks.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::init()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::read_current_snapshot_uuid()
	 */
	public function test_read_current_snapshot_uuid() {
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();

		$this->assertFalse( $manager->read_current_snapshot_uuid() );
		$this->assertNull( $manager->current_snapshot_uuid );

		$_REQUEST[ $manager->get_customize_uuid_param() ] = 'bad';
		$this->assertFalse( $manager->read_current_snapshot_uuid() );
		$this->assertNull( $manager->current_snapshot_uuid );

		$_REQUEST[ $manager->get_customize_uuid_param() ] = self::UUID;
		$this->assertTrue( $manager->read_current_snapshot_uuid() );
		$this->assertEquals( self::UUID, $manager->current_snapshot_uuid );

		$_REQUEST[ $manager->get_customize_uuid_param() ] = self::UUID;
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->current_snapshot_uuid );
	}

	/**
	 * Tests doing_customize_save_ajax.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::doing_customize_save_ajax()
	 */
	public function test_doing_customize_save_ajax() {
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$this->assertFalse( $manager->doing_customize_save_ajax() );

		$_REQUEST['action'] = 'foo';
		$this->assertFalse( $manager->doing_customize_save_ajax() );

		$_REQUEST['action'] = 'customize_save';
		$this->assertTrue( $manager->doing_customize_save_ajax() );
	}

	/**
	 * Tests ensure_customize_manager.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::ensure_customize_manager()
	 */
	public function test_ensure_customize_manager() {
		global $wp_customize;
		$wp_customize = null; // WPCS: global override ok.
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$this->assertEmpty( $manager->customize_manager );
		$manager->ensure_customize_manager();
		$this->assertInstanceOf( 'WP_Customize_Manager', $manager->customize_manager );
		$this->assertInstanceOf( 'WP_Customize_Manager', $wp_customize );
	}

	/**
	 * Tests is_theme_active.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::is_theme_active()
	 */
	public function test_is_theme_active() {
		global $wp_customize;
		$wp_customize = null; // WPCS: global override ok.
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$this->assertTrue( $manager->is_theme_active() );

		$manager->ensure_customize_manager();
		$this->assertTrue( $manager->is_theme_active() );
	}

	/**
	 * Test add snapshot uuid to return url.
	 *
	 * @see Customize_Snapshot_Manager::add_snapshot_uuid_to_return_url()
	 */
	public function test_add_snapshot_uuid_to_return_url() {
		global $wp_version;
		if ( version_compare( $wp_version, '4.4-beta', '>=' ) ) {
			wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
			$_GET[ $this->front_param ] = self::UUID;
			$_REQUEST[ $this->front_param ] = self::UUID;
			$manager = $this->get_snapshot_manager_instance( $this->plugin );
			$manager->init();
			$manager->ensure_customize_manager();
			do_action( 'setup_theme' );
			$this->assertNotContains( $this->front_param, $manager->customize_manager->get_return_url() );
			$manager->add_snapshot_uuid_to_return_url();
			$this->assertContains( $this->front_param, $manager->customize_manager->get_return_url() );
		}
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
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$manager->enqueue_controls_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'customize-snapshots', 'enqueued' ) );
	}

	/**
	 * Test snapshot method.
	 *
	 * @see Customize_Snapshot_Manager::snapshot()
	 */
	function test_snapshot() {
		$_REQUEST[ $this->front_param ] = self::UUID;
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
	}

	/**
	 * Test prepare_snapshot_post_content_for_publish.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::prepare_snapshot_post_content_for_publish()
	 */
	public function test_prepare_snapshot_post_content_for_publish() {
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
			'post_type' => $this->manager->get_post_type(),
			'post_content' => Customize_Snapshot_Manager::encode_json( $data ),
			'post_status' => 'publish',
		) );
		$this->assertEquals( $validate_data, json_decode( wp_unslash( $data_without_errors['post_content'] ), true ) );
	}

	/**
	 * Test adding snapshot_error_on_publish to removable_query_args.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::filter_removable_query_args()
	 */
	public function test_filter_removable_query_args() {
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$this->assertContains( 'snapshot_error_on_publish', $manager->filter_removable_query_args( array() ) );
	}

	/**
	 * Test prepare_errors_for_response.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::prepare_errors_for_response()
	 */
	public function test_prepare_errors_for_response() {
		$error = new \WP_Error();
		$error->add( 'foo', 'Foo message', array( 'foo_data' ) );
		$error->add( 'bar', 'Bar message', array( 'bar_data' ) );
		$data = $this->manager->prepare_errors_for_response( $error );
		$validate = array(
			'foo' => array(
				'message' => 'Foo message',
				'data' => array( 'foo_data' ),
			),
			'bar' => array(
				'message' => 'Bar message',
				'data' => array( 'bar_data' ),
			),
		);
		$this->assertSame( $validate, $data );
	}

	/**
	 * Tests generate_uuid.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::generate_uuid()
	 */
	public function test_generate_uuid() {
		$uuid = Customize_Snapshot_Manager::generate_uuid();
		$this->assertTrue( 0 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) );
		// Above line's logic is copied from : CustomizeSnapshots\Customize_Snapshot_Manager::is_valid_uuid().
	}

	/**
	 * Tests is_valid_uuid.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::is_valid_uuid()
	 */
	public function test_is_valid_uuid() {
		$this->assertTrue( Customize_Snapshot_Manager::is_valid_uuid( self::UUID ) );
		$this->assertFalse( Customize_Snapshot_Manager::is_valid_uuid( '65aee1ffd-af47d-47dfd-9e14d-9c69b3017cd3d' ) ); // Every Last char d is extra and should not be acceptable.
		$this->assertFalse( Customize_Snapshot_Manager::is_valid_uuid( '65aee1fg-af47-47dg-9e1g-9c69b3017cdg' ) ); // Every last char g should not be acceptable.
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
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::print_admin_bar_styles()
	 */
	public function test_print_admin_bar_styles() {
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		ob_start();
		$manager->print_admin_bar_styles();
		$contents = ob_get_clean();
		$this->assertContains( '<style', $contents );
	}

	/**
	 * Test misc admin bar extensions.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_post_edit_screen_link()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_changesets_admin_bar_link()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_snapshot_exit_link()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::add_resume_snapshot_link()
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::remove_all_non_snapshot_admin_bar_links()
	 */
	public function test_add_post_edit_and_exit_links() {
		global $wp_admin_bar;
		set_current_screen( 'front' );
		wp_set_current_user( $this->user_id );
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
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$manager->init();
		$wp_admin_bar = new \WP_Admin_Bar(); // WPCS: Override OK.
		$wp_admin_bar->initialize();
		$wp_admin_bar->add_menus();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'inspect-customize-snapshot' ) );
		$this->assertEmpty( $wp_admin_bar->get_node( 'exit-customize-snapshot' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'wporg' ) );
		$this->assertNotEmpty( $wp_admin_bar->get_node( 'resume-customize-snapshot' ) );
		$changesets_node = $wp_admin_bar->get_node( 'customize-changesets' );
		$this->assertNotEmpty( $changesets_node );
		$this->assertEquals( 'customize', $changesets_node->parent );

		$this->go_to( home_url( '?' . $this->front_param . '=' . self::UUID ) );
		remove_all_actions( 'admin_bar_menu' );
		$manager = $this->get_snapshot_manager_instance( $this->plugin );
		$_REQUEST[ $this->front_param ] = self::UUID;
		if ( ! $this->plugin->compat ) {
			global $wp_customize;
			$wp_customize = null; // WPCS: Override OK.
		}
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
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::render_templates()
	 */
	public function test_render_templates() {
		ob_start();
		$this->manager->render_templates();
		$templates = ob_get_contents();
		ob_end_clean();
		$this->assertContains( 'tmpl-snapshot-dialog-error', $templates );
		$this->assertContains( 'tmpl-snapshot-preview-link', $templates );
		$this->assertContains( 'tmpl-snapshot-expand-button', $templates );
		$this->assertContains( 'tmpl-snapshot-edit-container', $templates );
		$this->assertContains( 'tmpl-snapshot-scheduled-countdown', $templates );
		$this->assertContains( 'tmpl-snapshot-submit', $templates );
		$this->assertContains( 'tmpl-snapshot-conflict-button', $templates );
		$this->assertContains( 'tmpl-snapshot-conflict', $templates );
	}

	/**
	 * Test format_gmt_offset
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::format_gmt_offset()
	 */
	public function test_format_gmt_offset() {
		$offset = $this->manager->format_gmt_offset( 7.0 );
		$this->assertEquals( '+7', $offset );
	}

	/**
	 * Test month choices
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::get_month_choices()
	 */
	public function test_get_month_choices() {
		$data = $this->manager->get_month_choices();
		$this->assertArrayHasKey( 'month_choices', $data );
		$this->assertCount( 12, $data['month_choices'] );
	}

	/**
	 * Test override post date if empty.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::override_post_date_default_data()
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

	/**
	 * Tests get_post_type.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::get_post_type()
	 */
	public function test_get_post_type() {
		$plugin = get_plugin_instance();
		if ( $plugin->compat ) {
			$this->assertEquals( $this->manager->get_post_type(), Post_Type_Back_Compat::SLUG );
		} else {
			$this->assertEquals( $this->manager->get_post_type(), Post_Type::SLUG );
		}
	}

	/**
	 * Tests get_front_uuid_param.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::get_front_uuid_param()
	 */
	public function test_get_front_uuid_param() {
		$plugin = get_plugin_instance();
		if ( $plugin->compat ) {
			$this->assertEquals( $this->manager->get_front_uuid_param(), Post_Type_Back_Compat::FRONT_UUID_PARAM_NAME );
		} else {
			$this->assertEquals( $this->manager->get_front_uuid_param(), Post_Type::FRONT_UUID_PARAM_NAME );
		}
	}

	/**
	 * Tests get_customize_uuid_param.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::get_customize_uuid_param()
	 */
	public function test_get_customize_uuid_param() {
		$plugin = get_plugin_instance();
		if ( $plugin->compat ) {
			$this->assertEquals( $this->manager->get_customize_uuid_param(), Post_Type_Back_Compat::CUSTOMIZE_UUID_PARAM_NAME );
		} else {
			$this->assertEquals( $this->manager->get_customize_uuid_param(), Post_Type::CUSTOMIZE_UUID_PARAM_NAME );
		}
	}

	/**
	 * Test replace_customize_link.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::replace_customize_link()
	 */
	public function test_replace_customize_link() {
		global $wp_admin_bar;
		set_current_screen( 'front' );

		$front_param = $this->manager->get_front_uuid_param();
		$customize_param = $this->manager->get_customize_uuid_param();

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		remove_all_actions( 'admin_bar_menu' );
		$this->go_to( home_url( '?' . $front_param . '=' . self::UUID ) );
		$_REQUEST[ $front_param ] = self::UUID;

		$manager = $this->get_snapshot_manager_instance( $this->plugin );
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
		$this->assertArrayHasKey( $customize_param, $query_params );
		$this->assertEquals( self::UUID, $query_params[ $customize_param ] );
		$this->assertArrayHasKey( 'url', $query_params );
		$parsed_preview_url = wp_parse_url( $query_params['url'] );
		$this->assertArrayNotHasKey( 'query', $parsed_preview_url );
	}

	/**
	 * Test save_customizer_state_query_vars.
	 *
	 * @convers \CustomizeSnapshots\Customize_Snapshot_Manager::save_customizer_state_query_vars()
	 * @convers \CustomizeSnapshots\Post_Type::get_frontend_view_link()
	 * @convers \CustomizeSnapshots\Post_Type::get_customizer_state_query_vars()
	 * @convers \CustomizeSnapshots\Post_Type::set_customizer_state_query_vars()
	 */
	public function test_save_customizer_state_query_vars() {
		$post_id = $this->manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'blogname' => array(
					'value' => 'Hello',
				),
			),
			'status' => 'draft',
		) );

		$original_query_vars = array(
			'scroll' => 123,
			'device' => 'mobile',
			'url' => home_url( 'about/' ),
			'autofocus[panel]' => 'widgets',
			'autofocus[section]' => 'sidebar-widgets-sidebar-1',
			'autofocus[control]' => 'widget_test[123]',
		);

		$this->assertContains( sprintf( '?%s=%s', $this->front_param, self::UUID ), get_permalink( $post_id ) );
		$this->assertEmpty( $this->manager->post_type->get_customizer_state_query_vars( $post_id ) );
		$this->manager->save_customizer_state_query_vars( $post_id );
		$this->assertEmpty( $this->manager->post_type->get_customizer_state_query_vars( $post_id ) );

		$_POST['customizer_state_query_vars'] = wp_slash( wp_json_encode( $original_query_vars ) );
		$this->manager->save_customizer_state_query_vars( $post_id );
		$this->assertContains( sprintf( 'about/?%s=%s', $this->front_param, self::UUID ), get_permalink( $post_id ) );
		$this->assertEquals( $this->manager->post_type->get_customizer_state_query_vars( $post_id ), $original_query_vars );
		$this->assertEquals( $this->manager->post_type->get_frontend_view_link( $post_id ), get_permalink( $post_id ) );

		$this->manager->post_type->set_customizer_state_query_vars( $post_id, array(
			'scroll' => 'bad',
			'device' => 'bad',
			'url' => 'http://bogus.example.com/',
			'autofocus[panel]' => 'badid!',
			'autofocus[section]' => '#sobad',
			'autofocus[control]' => '*horrible',
			'unrecognized' => 'yes',
		) );
		$this->assertEmpty( $this->manager->post_type->get_customizer_state_query_vars( $post_id ) );
	}

	/**
	 * Test clean_up_nav_menus_created_auto_drafts
	 *
	 * @convers \CustomizeSnapshots\Customize_Snapshot_Manager::clean_up_nav_menus_created_auto_drafts()
	 */
	public function test_clean_up_nav_menus_created_auto_drafts() {
		$nav_created_post_ids = $this->factory()->post->create_many( 2, array(
			'post_status' => 'auto-draft',
		) );
		$data = array(
			'nav_menus_created_posts' => array(
				'value' => $nav_created_post_ids,
			),
		);
		wp_set_current_user( self::factory()->user->create( array(
			'role' => 'administrator',
		) ) );
		$post_id = $this->manager->post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		$copy_post_id = $this->manager->post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		$this->assertInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[0] ) );
		$this->assertInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[1] ) );
		wp_delete_post( $post_id, true );
		$this->assertInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[0] ) );
		$this->assertInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[1] ) );
		wp_delete_post( $copy_post_id, true );
		$this->assertNotInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[0] ) );
		$this->assertNotInstanceOf( 'WP_Post', get_post( $nav_created_post_ids[1] ) );
	}
}
