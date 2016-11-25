<?php
/**
 * Test Customize_Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Test_Customize_Snapshot class.
 *
 * @group snapshot
 */
class Test_Customize_Snapshot extends \WP_UnitTestCase {

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
	 * Post type.
	 *
	 * @type string
	 */
	const POST_TYPE = 'customize_snapshot';

	/**
	 * Instance of WP_Customize_Manager which is reset for each test.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * Snapshot manager.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	protected $snapshot_manager;

	/**
	 * Foo setting
	 *
	 * @var \WP_Customize_Setting
	 */
	protected $foo;

	/**
	 * Bar setting.
	 *
	 * @var \WP_Customize_Setting
	 */
	protected $bar;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		$this->mark_incompatible();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager( array( 'changeset_uuid' => self::UUID ) ); // WPCS: override ok.
		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->snapshot_manager->post_type = new Post_Type( $this->snapshot_manager );
		$this->wp_customize = $GLOBALS['wp_customize'];
	}

	/**
	 * Tear down.
	 */
	function tearDown() {
		$this->wp_customize = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		parent::tearDown();
	}

	/**
	 * Test UUID.
	 *
	 * @see CustomizeSnapshots\Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$_REQUEST['customize_changeset_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->snapshot()->uuid() );
	}

	/**
	 * Test get_edit_link.
	 *
	 * @see CustomizeSnapshots\Customize_Snapshot::get_edit_link()
	 */
	function test_get_edit_link() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->snapshot_manager->post_type->save( array(
			'uuid' => self::UUID,
			'status' => 'draft',
			'data' => array(),
		) );
		$has_filter = has_filter( 'get_edit_post_link', '__return_empty_string' );
		if ( ! $has_filter ) {
			add_filter( 'get_edit_post_link', '__return_empty_string' );
		}
		$snapshot = new Customize_Snapshot( $this->snapshot_manager );
		$link = $snapshot->get_edit_link( $post_id );
		$this->assertContains( 'post=' . $post_id, $link );
	}

	/**
	 * Test post
	 *
	 * @see CustomizeSnapshot\Customize_Snapshot::post()
	 */
	function test_post() {
		$post_id = $this->snapshot_manager->post_type->save( array(
			'uuid' => self::UUID,
			'status' => 'draft',
			'data' => array(),
		) );
		$this->snapshot_manager->customize_manager = new \WP_Customize_Manager( array(
			'changeset_uuid' => self::UUID,
		) );
		$snapshot = new Customize_Snapshot( $this->snapshot_manager );
		$snapshot_post = $snapshot->post();
		$this->assertEquals( $post_id, $snapshot_post->ID );
		$this->snapshot_manager->customize_manager = new \WP_Customize_Manager( array(
			'changeset_uuid' => wp_generate_uuid4(),
		) );
		$snapshot_post = $snapshot->post();
		$this->assertNull( $snapshot_post );
	}

	/**
	 * Mark test incomplete as it is only for new versions.
	 */
	public function mark_incompatible() {
		if ( $this->plugin->compat ) {
			$this->markTestIncomplete( 'This unit-test require WP version 4.7 or up.' );
		}
	}
}
