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
	 * Bootstrap the customizer.
	 */
	public static function setUpBeforeClass() {
		$args = array(
			'labels' => array(
				'name' => __( 'Customize Snapshots', 'customize-snapshots' ),
				'singular_name' => __( 'Customize Snapshot', 'customize-snapshots' ),
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

	/**
	 * Tear down after class.
	 */
	public static function tearDownAfterClass() {
		_unregister_post_type( self::POST_TYPE );
	}

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager(); // WPCS: override ok.
		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->wp_customize = $GLOBALS['wp_customize'];
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );
		$this->foo = $this->wp_customize->get_setting( 'foo' );
		$this->bar = $this->wp_customize->get_setting( 'bar' );
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
	 * Test constructor.
	 *
	 * @see Customize_Snapshot::__construct()
	 */
	function test_construct() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$data = array( 'foo' => array( 'value' => 'bar' ) );
		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
		) );
		$snapshot = new Customize_Snapshot( $manager, self::UUID );
		$this->assertEquals( $data, $snapshot->data() );
	}

	/**
	 * Test UUID.
	 *
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$this->assertEquals( self::UUID, $manager->snapshot()->uuid() );
	}

	/**
	 * Test bad UUID.
	 *
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
	 * Test data.
	 *
	 * @see Customize_Snapshot::data()
	 */
	function test_data() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$manager->snapshot()->set( array( 'foo' => array( 'value' => 'foo_default' ) ) );
		$this->assertNotEmpty( $manager->snapshot()->data() );
		$manager->snapshot()->set( array( 'foo' => array( 'value' => 'foo_custom' ) ) );
		$expected = array(
			'foo' => array(
				'value' => 'foo_custom',
			),
		);
		$this->assertEquals( $expected, $manager->snapshot()->data() );
	}

	/**
	 * Test snapshot settings.
	 *
	 * @see Customize_Snapshot::settings()
	 */
	function test_settings() {
		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$this->assertEmpty( $manager->snapshot()->settings() );
		$manager->snapshot()->set( array( 'foo' => array( 'value' => 'foo_default' ) ) );
		$this->assertNotEmpty( $manager->snapshot()->settings() );
	}

	/**
	 * Test status.
	 *
	 * @see Customize_Snapshot::settings()
	 */
	function test_status() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$snapshot = new Customize_Snapshot( $manager, self::UUID );
		$this->assertNull( $snapshot->status() );

		$data = array( 'foo' => array( 'value' => 'bar' ) );
		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
		) );

		$this->assertEquals( 'draft', $snapshot->status() );
		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'status' => 'publish',
		) );
		$this->assertEquals( 'publish', $snapshot->status() );
	}

	/**
	 * Test set.
	 *
	 * @see Customize_Snapshot::set()
	 */
	function test_set() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$this->bar->capability = 'do_not_allow';
		add_filter( 'customize_sanitize_foo', 'strtoupper' );

		$snapshot = new Customize_Snapshot( $manager, self::UUID );
		$result = $snapshot->set( array(
			'foo' => array( 'value' => 'ok' ),
			'bar' => array( 'value' => 'unauthorized' ),
			'baz' => array( 'value' => 'unrecognized' ),
		) );

		$this->assertArrayHasKey( 'errors', $result );
		$this->assertInstanceOf( 'WP_Error', $result['errors'] );
		$wp_error = $result['errors'];
		$this->assertArrayHasKey( 'unauthorized_settings', $wp_error->errors );
		$this->assertArrayHasKey( 'unrecognized_settings', $wp_error->errors );

		$this->assertArrayHasKey( 'sanitized', $result );
		$this->assertArrayHasKey( 'foo', $result['sanitized'] );
		$this->assertArrayNotHasKey( 'bar', $result['sanitized'] );
		$this->assertArrayNotHasKey( 'baz', $result['sanitized'] );
		$this->assertEquals( 'OK', $result['sanitized']['foo'] );

		$this->assertArrayHasKey( 'validities', $result );
		$this->assertArrayHasKey( 'foo', $result['validities'] );
		$this->assertTrue( $result['validities']['foo'] );

		$this->assertEmpty( $snapshot->data() );

		// Success with populated value.
		$result = $snapshot->set( array( 'foo' => array( 'value' => 'ok' ) ) );
		$this->assertNull( $result['errors'] );
		$resultant_data = $snapshot->data();
		$this->assertEquals( 'ok', $resultant_data['foo']['value'] );
	}

	/**
	 * Test set with varying setting params.
	 *
	 * @see Customize_Snapshot::set()
	 */
	function test_set_with_varying_setting_params() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();
		$snapshot = new Customize_Snapshot( $manager, self::UUID );

		$result = $snapshot->set( array( 'foo' => array( 'value' => 'ok' ) ) );
		$this->assertNull( $result['errors'] );
		$resultant_data = $snapshot->data();
		$this->assertEquals( 'ok', $resultant_data['foo']['value'] );

		// Check setting a param without a value, ensuring that foo still remains but snapshot is amended.
		$result = $snapshot->set( array( 'bar' => array( 'extra' => 'ok' ) ) );
		$this->assertNull( $result['errors'] );
		$resultant_data = $snapshot->data();
		$this->assertEquals( 'ok', $resultant_data['foo']['value'] );
		$this->assertArrayHasKey( 'extra', $resultant_data['bar'] );
		$this->assertNull( $resultant_data['bar']['value'] );
	}

	/**
	 * Test set with a non-array param.
	 *
	 * @see Customize_Snapshot::set()
	 * @expectedException Exception
	 */
	function test_set_with_non_array_params() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->ensure_customize_manager();
		$manager->init();
		$snapshot = new Customize_Snapshot( $manager, self::UUID );
		$snapshot->set( array( 'foo' => 'bad' ) );
	}

	/**
	 * Test saved.
	 *
	 * @see Customize_Snapshot::saved()
	 */
	function test_saved() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$snapshot = new Customize_Snapshot( $manager, self::UUID );
		$this->assertFalse( $snapshot->saved() );

		$manager->post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
		) );
	}

	/**
	 * Test that the snapshot object is passed as the second filter param.
	 *
	 * @see Customize_Snapshot::save()
	 */
	function test_filter_customize_snapshot_save() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$manager->init();

		$snapshot = new Customize_Snapshot( $manager, self::UUID );

		add_filter( 'customize_snapshot_save', function( $data, $test_snapshot ) use ( $snapshot ) {
			$this->assertEquals( $test_snapshot, $snapshot );
		}, 10, 2 );

		$snapshot->save( array(
			'uuid' => self::UUID,
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
		) );
	}
}
