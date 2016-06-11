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

	/**
	 * @var int
	 */
	protected $css_concat_init_priority;

	/**
	 * @var int
	 */
	protected $js_concat_init_priority;

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
		remove_action( 'after_setup_theme', 'twentysixteen_setup' );
		remove_all_actions( 'send_headers' ); // prevent X-hacker header in VIP Quickstart

		// For why these hooks have to be removed, see https://github.com/Automattic/nginx-http-concat/issues/5
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
	}

	function tearDown() {
		$this->wp_customize = null;
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		unset( $_REQUEST['customize_snapshot_uuid'] );
		unset( $GLOBALS['screen'] );
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
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertInstanceOf( 'WP_Customize_Manager', $GLOBALS['wp_customize'] );
	}

	/**
	 * @see Customize_Snapshot_Manager::set_return_url()
	 */
	public function test_set_return_url() {
		global $wp_version;
		if ( version_compare( $wp_version, '4.4-beta', '>=' ) ) {
			$this->assertNotContains( 'customize_snapshot_uuid', $this->manager->customize_manager->get_return_url() );
			$this->manager->snapshot()->set_uuid( self::UUID );
			$this->manager->snapshot()->is_preview = true;
			$this->manager->set_return_url();
			$this->assertContains( 'customize_snapshot_uuid', $this->manager->customize_manager->get_return_url() );
		}
	}

	/**
	 * @see Customize_Snapshot_Manager::clean_current_url()
	 */
	function test_clean_current_url() {
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID ) );
		ob_start();
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertContains( 'customize_snapshot_uuid', $manager->current_url() );
		echo $manager->clean_current_url();
		$buffer = ob_get_clean();
		$this->assertEquals( home_url( '/' ), $buffer );
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
	 * @see Customize_Snapshot_Manager::filter_bulk_actions()
	 */
	function test_filter_bulk_actions() {
		$this->assertEquals( array(), $this->manager->filter_bulk_actions( array( 'edit' => 'link markup' ) ) );
	}

	/**
	 * @see Customize_Snapshot_Manager::filter_post_row_actions()
	 */
	function test_filter_post_row_actions_return() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$actions = array( 'inline hide-if-no-js' );
		$args = array(
			'post_title' => 'Some Title',
			'post_status' => 'publish',
		);
		$post = get_post( self::factory()->post->create( $args ) );
		$filtered = $manager->filter_post_row_actions( $actions, $post );

		$this->assertEquals( $actions, $filtered );
	}

	/**
	 * @see Customize_Snapshot_Manager::filter_post_row_actions()
	 */
	function test_filter_post_row_actions_draft() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":true},"bar":{"value":"bar_default","dirty":true}}',
		);

		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->capture_unsanitized_snapshot_post_data();
		$foo = $manager->customize_manager->get_setting( 'foo' );
		$manager->snapshot()->set( $foo, 'foo_custom', true );
		$manager->snapshot()->save();
		$actions = array(
			'inline hide-if-no-js' => true,
		);
		$post = $manager->snapshot()->post();
		$filtered = $manager->filter_post_row_actions( $actions, $post );
		$extected = array(
			'customize' => '<a href="http://example.org/wp-admin/customize.php?customize_snapshot_uuid=' . $post->post_name . '">Customize</a>',
		);
		$this->assertEquals( $extected, $filtered );
	}

	/**
	 * @see Customize_Snapshot_Manager::filter_post_row_actions()
	 */
	function test_filter_post_row_actions_publish() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":true},"bar":{"value":"bar_default","dirty":true}}',
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->set_snapshot_uuid();
		$manager->save_snapshot();
		$actions = array(
			'inline hide-if-no-js' => true,
			'edit' => '',
		);
		$post = $manager->snapshot()->post();
		$filtered = $manager->filter_post_row_actions( $actions, $post );
		$extected = array(
			'edit' => '<a href="http://example.org/wp-admin/post.php?post=' . $post->ID . '&amp;action=edit" aria-label="View &#8220;' . $post->post_name . '&#8221;">View</a>',
		);
		$this->assertEquals( $extected, $filtered );
	}

	/**
	 * @see Customize_Snapshot_Manager::setup_metaboxes()
	 */
	function test_setup_metaboxes() {
		global $wp_meta_boxes;
		$screen = Customize_Snapshot_Manager::POST_TYPE;
		set_current_screen( $screen );
		$this->manager->setup_metaboxes();
		$this->assertArrayHasKey( $screen, $wp_meta_boxes[ $screen ]['normal']['high'] );
	}

	/**
	 * @see Customize_Snapshot_Manager::render_data_metabox()
	 */
	function test_render_data_metabox() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$snapshot_json = '{"foo":{"value":"foo_value","dirty":true,"sanitized":false}}';
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => $snapshot_json,
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->capture_unsanitized_snapshot_post_data();
		$foo = $manager->customize_manager->get_setting( 'foo' );
		$manager->snapshot()->set( $foo, 'foo_value', true );
		$manager->snapshot()->save();
		$post = $manager->snapshot()->post();
		ob_start();
		$manager->render_data_metabox( $post );
		$metabox = ob_get_clean();
		$this->assertContains( $post->post_name, $metabox );
		$this->assertContains( 'Edit in Customizer', $metabox );
		$this->assertContains( 'foo_value', $metabox );
	}

	/**
	 * @see Customize_Snapshot_Manager::get_post_content()
	 */
	function test_get_post_content() {
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$snapshot_json = '{"foo":{"value":"foo_value","dirty":true,"sanitized":false}}';
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => $snapshot_json,
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->capture_unsanitized_snapshot_post_data();
		$foo = $manager->customize_manager->get_setting( 'foo' );
		$manager->snapshot()->set( $foo, 'foo_value', true );
		$manager->snapshot()->save();
		$post = $manager->snapshot()->post();
		$snapshot_content = Customize_Snapshot_Manager::get_post_content( $post );
		$this->assertEquals( json_decode( $snapshot_json, true ), $snapshot_content );

		// Get the revision post.
		$manager->snapshot()->set( $foo, 'foo_revision_value', true );
		$manager->snapshot()->save();
		$revisions = wp_get_post_revisions( $post->ID );
		$revision = array_shift( $revisions );
		$revision_content = Customize_Snapshot_Manager::get_post_content( $revision );
		$this->assertEquals( 'foo_revision_value', $revision_content['foo']['value'] );
	}

	/**
	 * @see Customize_Snapshot_Manager::get_post_content()
	 */
	function test_get_post_content_empty() {
		$args = array(
			'post_name' => self::UUID,
			'post_type' => Customize_Snapshot_Manager::POST_TYPE,
		);
		$post = get_post( self::factory()->post->create( $args ) );
		$snapshot_content = Customize_Snapshot_Manager::get_post_content( $post );
		$this->assertEquals( array(), $snapshot_content );
	}

	/**
	 * @see Customize_Snapshot_Manager::encode_json()
	 */
	function test_encode_json() {
		$array = array(
			'foo' => array(
				'value' => 'foo_value',
				'dirty' => true,
				'sanitized' => false,
			),
		);
		$json = '{"foo":{"value":"foo_value","dirty":true,"sanitized":false}}';
		$this->assertEquals( $json, preg_replace( '/\s+/', '', Customize_Snapshot_Manager::encode_json( $array ) ) );
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
		$this->assertTrue( wp_script_is( $this->plugin->slug, 'enqueued' ) );
		$this->assertTrue( wp_style_is( $this->plugin->slug, 'enqueued' ) );
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
	function test_save_error() {
		$obj = $this;
		set_error_handler( function ( $errno, $errstr ) use( $obj ) {
			$obj->assertEquals( 'CustomizeSnapshots\Plugin: Unable to snapshot settings for: baz', $errstr );
			$obj->assertEquals( \E_USER_WARNING, $errno );
		} );
		wp_set_current_user( $this->user_id );
		$this->do_customize_boot_actions( true );
		$_POST = array(
			'nonce' => wp_create_nonce( 'save-customize_' . $this->wp_customize->get_stylesheet() ),
			'snapshot_uuid' => self::UUID,
			'snapshot_customized' => '{"baz":{"value":"","dirty":true}}',
		);
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->save();
		restore_error_handler();
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
		set_current_screen( 'front' );
		$customize_url = admin_url( 'customize.php' ) . '?customize_snapshot_uuid=' . self::UUID . '&url=' . urlencode( esc_url( home_url( '/' ) ) );

		require_once( ABSPATH . WPINC . '/class-wp-admin-bar.php' );
		$wp_admin_bar = new \WP_Admin_Bar;
		$this->assertInstanceOf( 'WP_Admin_Bar', $wp_admin_bar );

		wp_set_current_user( $this->user_id );
		$this->go_to( home_url( '?customize_snapshot_uuid=' . self::UUID  ) );

		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );
		$this->assertEquals( $customize_url, $wp_admin_bar->get_node( 'customize' )->href );
	}
	
	/**
	 * @see Customize_Snapshot_Manager::customize_menu()
	 */
	public function test_customize_menu_return() {
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
		$this->assertContains( 'tmpl-snapshot-save', $templates );
		$this->assertContains( 'tmpl-snapshot-dialog-error', $templates );
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

	/**
	 * @see Customize_Snapshot_Manager::filter_snapshot_excerpt()
	 */
	public function test_excerpt() {
		global $post;
		wp_set_current_user( $this->user_id );
		$this->manager->customize_manager = $this->wp_customize;
		$this->manager->snapshot = new Customize_Snapshot( $this->manager, null );
		$foo = $this->manager->customize_manager->get_setting( 'foo' );
		$bar = $this->manager->customize_manager->get_setting( 'bar' );
		$this->manager->snapshot()->set( $foo, 'foo_custom', true );
		$this->manager->snapshot()->set( $bar, 'bar_custom', true );
		$this->manager->snapshot()->save();

		$post = $this->manager->snapshot()->post();
		$excerpt = get_the_excerpt( $post );
		$this->assertContains( '<ol>', $excerpt );
		$this->assertContains( '<code>foo</code>', $excerpt );
		$this->assertContains( '<code>bar</code>', $excerpt );
	}
}
