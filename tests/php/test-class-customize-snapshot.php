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
		if ( $this->plugin->compat ) {
			$this->markTestIncomplete( 'WordPress Version 4.7 or up is required for this test-case.' );
		}
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager( array( 'changeset_uuid' => self::UUID ) ); // WPCS: override ok.
		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
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
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$_REQUEST['customize_changeset_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->snapshot()->uuid() );
	}
}
