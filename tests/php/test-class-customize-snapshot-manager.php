<?php

namespace CustomizeSnapshots;

class Test_Customize_Snapshot_Manager extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * A valid UUID.
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * @var Customize_Snapshot_Manager
	 */
	protected $manager;

	/**
	 * @var int
	 */
	protected $user_id;

	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->wp_customize = $GLOBALS['wp_customize'];

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		remove_action( 'after_setup_theme', 'twentyfifteen_setup' );
	}

	function tearDown() {
		$this->wp_customize = null;
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		unset( $_REQUEST['customize_snapshot_uuid'] );
		unset( $_REQUEST['scope'] );
		parent::tearDown();
	}

	function do_customize_on() {
		$_REQUEST['wp_customize'] = 'on';
	}

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
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_without_customize() {
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Manager', $this->manager );
		$this->assertNull( $this->manager->plugin );
	}

	/**
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_with_customize() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$this->assertTrue( is_customize_preview() );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertInstanceOf( 'CustomizeSnapshots\Plugin', $manager->plugin );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
		$this->assertEquals( 0, has_action( 'init', array( $manager, 'create_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $manager, 'enqueue_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_customize_update_snapshot', array( $manager, 'update_snapshot' ) ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct_with_customize_bootstrapped() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		unset( $GLOBALS['wp_customize'] );
		$_GET['customize_snapshot_uuid'] = self::UUID;
		$_GET['scope'] = 'full';
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertInstanceOf( 'WP_Customize_Manager', $GLOBALS['wp_customize'] );
	}

	/**
	 * @see Customize_Snapshot_Manager::create_post_type()
	 */
	function test_create_post_type() {
		$pobj = get_post_type_object( Customize_Snapshot_Manager::POST_TYPE );
		$this->assertInstanceOf( 'stdClass', $pobj );
		$this->assertEquals( Customize_Snapshot_Manager::POST_TYPE, $pobj->name );

		// Test some defaults
		$this->assertFalse( is_post_type_hierarchical( Customize_Snapshot_Manager::POST_TYPE ) );
		$this->assertEquals( array(), get_object_taxonomies( Customize_Snapshot_Manager::POST_TYPE ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::enqueue_scripts()
	 */
	function test_enqueue_scripts() {
		wp_set_current_user( $this->user_id );
		$this->plugin->register_scripts( wp_scripts() );
		$this->plugin->register_styles( wp_styles() );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":false},"bar":{"value":"bar_default","dirty":false}}',
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->set_snapshot_uuid();
		$manager->save_snapshot();
		$manager->enqueue_scripts();
		$this->assertTrue( wp_script_is( 'customize-snapshots-base', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'customize-snapshots-base', 'enqueued' ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::snapshot()
	 */
	function test_snapshot() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot', $manager->snapshot() );
	}

	/**
	 * @see Customize_Snapshot_Manager::save_snapshot()
	 */
	function test_save_snapshot() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":false},"bar":{"value":"bar_default","dirty":false}}',
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->set_snapshot_uuid();
		$this->assertEmpty( $manager->snapshot()->post() );
		$manager->save_snapshot();
		$this->assertNotEmpty( $manager->snapshot()->post() );
	}

	/**
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu() {
		$customize_url = admin_url( 'customize.php' ) . '?customize_snapshot_uuid=' . self::UUID . '&scope=dirty&url=http%3A%2F%2Fexample.org%2F%3Fcustomize_snapshot_uuid%3D' . self::UUID . '%26scope%3Ddirty';

		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar;
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->user_id );
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID . '&scope=dirty' ) );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEquals( $customize_url, $wp_admin_bar->get_node( 'customize' )->href );
	}
	
	/**
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu_return() {
		$customize_url = admin_url( 'customize.php' ) . '?customize_snapshot_uuid=' . self::UUID . '&scope=dirty&url=http%3A%2F%2Fexample.org%2F%3Fcustomize_snapshot_uuid%3D' . self::UUID . '%26scope%3Ddirty';

		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar;
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( admin_url() );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertNull( $wp_admin_bar->get_node( 'customize' ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::render_templates()
	 */
	public function test_render_templates() {
		ob_start();
		$this->manager->render_templates();
		$templates = ob_get_contents();
		ob_end_clean();
		$this->assertContains( 'tmpl-snapshot-button', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-share-link', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-share-error', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-form', $templates );
	}

	/**
	 * @see Customize_Snapshot_Manager::can_preview()
	 */
	public function test_can_preview() {
		wp_set_current_user( $this->user_id );
		$_POST = wp_slash( array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"foo":{"value":"foo_custom","dirty":true},"bar":{"value":"bar_default","dirty":false}}',
		) );
		$this->do_customize_boot_actions( true );
		$foo = $this->wp_customize->get_setting( 'foo' );
		$this->assertEquals( 'foo_default', $foo->value() );

		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->set_snapshot_uuid();
		$manager->save_snapshot();

		$foo = $this->wp_customize->get_setting( 'foo' );
		$this->assertEquals( 'foo_custom', $foo->value() );
		$this->assertTrue( $manager->can_preview( $foo, $manager->snapshot()->values() ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::can_preview()
	 */
	public function test_can_preview_instanceof() {
		$this->assertFalse( $this->manager->can_preview( 'foo', array() ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::can_preview()
	 */
	public function test_can_preview_cap_check() {
		$this->markTestIncomplete( 'This test has not been implemented yet.' );
	}

	/**
	 * @see Customize_Snapshot_Manager::can_preview()
	 */
	public function test_can_preview_array_key_exists() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"bar":{"value":"bar_default","dirty":false}}',
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->save_snapshot();

		$foo = $this->wp_customize->get_setting( 'foo' );
		$this->assertEquals( 'foo_default', $foo->value() );

		$this->assertFalse( $manager->can_preview( $foo, $manager->snapshot()->values() ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::set_post_values()
	 */
	public function test_set_post_values() {
		wp_set_current_user( $this->user_id );
		$foo = $this->manager->customize_manager->get_setting( 'foo' );
		$this->manager->snapshot()->set( $foo, 'foo_custom', true );
		$this->manager->snapshot()->save();
		$this->manager->snapshot()->is_preview = true;
		$this->manager->set_post_values();
		$this->assertEquals( array( 'foo' => 'foo_custom' ), $this->manager->customize_manager->unsanitized_post_values() );
	}

	/**
	 * @see Customize_Snapshot_Manager::preview()
	 */
	public function test_preview() {
		wp_set_current_user( $this->user_id );
		$foo = $this->manager->customize_manager->get_setting( 'foo' );
		$this->manager->snapshot()->set( $foo, 'foo_custom', true );
		$this->assertFalse( $foo->dirty );
		$this->manager->snapshot()->save();
		$this->manager->snapshot()->is_preview = true;
		$this->manager->preview();
		$this->assertTrue( $foo->dirty );
	}
}
