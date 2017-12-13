<?php
/**
 * Test Customize_Snapshot_Manager_Compat.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Customize_Snapshot_Manager_Compat
 */
class Test_Customize_Snapshot_Manager_Compat extends \WP_UnitTestCase {

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
	 * @var Customize_Snapshot_Manager_Compat
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
		$this->front_param = 'customize_changeset_uuid';
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager( array(
			'changeset_uuid' => self::UUID,
		) ); // WPCS: global override ok.
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
	 * @return Customize_Snapshot_Manager_Compat Manager new instance.
	 */
	function get_snapshot_manager_instance( $plugin ) {
		return new Customize_Snapshot_Manager_Compat( $plugin );
	}

	/**
	 * Test constructor.
	 *
	 * @see Customize_Snapshot_Manager_Compat::__construct()
	 */
	function test_construct_without_customize() {
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Manager_Compat', $this->manager );
		$this->assertInstanceOf( 'CustomizeSnapshots\Plugin', $this->manager->plugin );
	}

	/**
	 * Test constructor with customizer bootstrapped.
	 *
	 * @see Customize_Snapshot_Manager_Compat::__construct()
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
	 * Test enqueue controls scripts.
	 *
	 * @see Customize_Snapshot_Manager_Compat::enqueue_controls_scripts()
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
	 * Test render templates.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Compat::render_templates()
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
	}

	/**
	 * Test format_gmt_offset
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Compat::format_gmt_offset()
	 */
	public function test_format_gmt_offset() {
		$offset = $this->manager->format_gmt_offset( 7.0 );
		$this->assertEquals( '+7', $offset );
	}

	/**
	 * Test month choices
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Compat::get_month_choices()
	 */
	public function test_get_month_choices() {
		$data = Customize_Snapshot_Manager_Compat::get_month_choices();
		$this->assertArrayHasKey( 'month_choices', $data );
		$this->assertCount( 12, $data['month_choices'] );
	}

	/**
	 * Test override post date if empty.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager_Compat::override_post_date_default_data()
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
