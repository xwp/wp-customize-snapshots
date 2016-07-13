<?php

namespace CustomizeSnapshots;

/**
 * Test_Customize_Snapshot class.
 *
 * @group snapshot
 */
class Test_Customize_Snapshot extends \WP_UnitTestCase {

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
	 * Post type.
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
	 * @var Customize_Snapshot_Manager
	 */
	protected $snapshot_manager;

	/**
	 * @var \WP_Customize_Setting
	 */
	protected $foo;

	/**
	 * @var \WP_Customize_Setting
	 */
	protected $bar;

	/**
	 * Boostrap the customizer.
	 */
	public static function setUpBeforeClass() {
		$args = array(
			'labels' => array(
				'name' => __( 'Customize Snapshots' ),
				'singular_name' => __( 'Customize Snapshot' ),
			),
			'public' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'rewrite' => false,
			'delete_with_user' => false,
			'supports' => array( 'title', 'author', 'revisions' ),
		);
		register_post_type( self::POST_TYPE, $args );
	}

	public static function tearDownAfterClass() {
		_unregister_post_type( self::POST_TYPE );
	}
	
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->wp_customize = $GLOBALS['wp_customize'];
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );
		$this->foo = $this->wp_customize->get_setting( 'foo' );
		$this->bar = $this->wp_customize->get_setting( 'bar' );
	}

	function tearDown() {
		$this->wp_customize = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		parent::tearDown();
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->snapshot()->uuid() );
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid_throws_exception() {
		try {
			new Customize_Snapshot( $this->snapshot_manager, '1234-invalid-UUID' );
		} catch ( \Exception $e ) {
			$this->assertContains( 'You\'ve entered an invalid snapshot UUID.', $e->getMessage() );
			return;
		}

		$this->fail( 'An expected exception has not been raised.' );
	}

	/**
	 * @see Customize_Snapshot::data()
	 */
	function test_data() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$manager->snapshot()->set( array( 'foo' => 'foo_default' ) );
		$this->assertNotEmpty( $manager->snapshot()->data() );
		$manager->snapshot()->set( array( 'foo' => 'foo_custom' ) );
		$expected = array(
			'foo' => array(
				'value' => 'foo_custom',
			),
		);
		$this->assertEquals( $expected, $manager->snapshot()->data() );
	}

	/**
	 * @see Customize_Snapshot::settings()
	 */
	function test_settings() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$this->assertEmpty( $manager->snapshot()->settings() );
		$manager->snapshot()->set( array( 'foo' => 'foo_default' ) );
		$this->assertNotEmpty( $manager->snapshot()->settings() );
	}
}
