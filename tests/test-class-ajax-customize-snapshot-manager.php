<?php

namespace CustomizeSnapshots;

class Test_Ajax_Customize_Snapshot_Manager extends \WP_Ajax_UnitTestCase {

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
	 * Instance of WP_Customize_Manager which is reset for each test.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * @var Customize_Snapshot_Manager
	 */
	protected $manager;

	/**
	 * Set up the test fixture.
	 */
	public function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->wp_customize = $GLOBALS['wp_customize'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['wp_customize'] = 'on';
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->manager = new Customize_Snapshot_Manager( $this->plugin );
	}

	function tearDown() {
		$this->wp_customize = null;
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		unset( $_SERVER['REQUEST_METHOD'] );
		unset( $_REQUEST['wp_customize'] );
		unset( $_REQUEST['customize_snapshot_uuid'] );
		unset( $_REQUEST['scope'] );
		unset( $_REQUEST['preview'] );
		parent::tearDown();
	}

	/**
	 * Helper to keep it DRY
	 *
	 * @param string $action Action.
	 */
	protected function make_ajax_call( $action ) {
		// Make the request.
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
	}

	/**
	 * Testing capabilities check for the update_snapshot method
	 */
	function test_ajax_update_snapshot_nonce_check() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => 'bad-nonce-12345',
		);

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => 'bad_nonce',
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing REQUEST_METHOD for the update_snapshot method
	 */
	function test_ajax_update_snapshot_post_check() {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
		);

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => 'bad_method',
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing capabilities check for the update_snapshot method
	 *
	 * @dataProvider data_update_snapshot_cap_check
	 *
	 * @param string $role              The role we're checking caps against.
	 * @param array  $expected_results  Expected results.
	 */
	function test_ajax_update_snapshot_cap_check( $role, $expected_results ) {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => $role ) ) );

		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
		);

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Data provider for test_ajax_update_snapshot_cap_check().
	 *
	 * Provides various post_args to induce error messages that can be
	 * compared to the expected_results.
	 *
	 * @return array {
	 *     @type array {
	 *         @string string $role             The role that will test caps for.
	 *         @array  array  $expected_results The expected results from the ajax call.
	 *     }
	 * }
	 */
	function data_update_snapshot_cap_check() {
		return array(
			array(
				'subscriber',
				array(
					'success' => false,
					'data'    => 'customize_not_allowed',
				),
			),
			array(
				'contributor',
				array(
					'success' => false,
					'data'    => 'customize_not_allowed',
				),
			),
			array(
				'author',
				array(
					'success' => false,
					'data'    => 'customize_not_allowed',
				),
			),
			array(
				'editor',
				array(
					'success' => false,
					'data'    => 'customize_not_allowed',
				),
			),
			array(
				'administrator',
				array(
					'success' => false,
					'data'    => 'invalid_customize_snapshot_uuid',
				),
			),
		);
	}

	/**
	 * Testing scope for the update_snapshot method
	 */
	function test_ajax_update_snapshot_scope_check() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
		);

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => 'invalid_customize_snapshot_scope',
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing post_data for the update_snapshot method
	 */
	function test_ajax_update_snapshot_post_data_check() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'scope' => 'dirty',
		);

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => 'missing_snapshot_customized',
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing preview for the update_snapshot method
	 */
	function test_ajax_update_snapshot_preview_check() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'scope' => 'dirty',
			'snapshot_customized' => '{"header_background_color":{"value":"#ffffff","dirty":false}}',
		);

		$this->manager->store_post_data();
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => 'missing_preview',
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing a successful response for the update_snapshot method
	 */
	function test_ajax_update_snapshot_success() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'scope' => 'full',
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":false},"bar":{"value":"bar_default","dirty":false}}',
			'preview' => 'off',
		);

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager->store_post_data();
		$this->manager->create_post_type();
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$this->assertSame( self::UUID, $response['data']['customize_snapshot_uuid'] );
		$this->assertNotSame( self::UUID, $response['data']['customize_snapshot_next_uuid'] );
		$settings = array(
			'foo' => 'foo_default',
			'bar' => 'bar_default',
		);
		$this->assertSame( $settings, $response['data']['customize_snapshot_settings'] );
	}

	/**
	 * Testing a successful response with preview for the update_snapshot method
	 */
	function test_ajax_update_snapshot_success_preview() {
		$_POST = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'scope' => 'dirty',
			'snapshot_customized' => '{"foo":{"value":"foo_default","dirty":false},"bar":{"value":"bar_default","dirty":false}}',
			'preview' => 'on',
		);

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->manager->store_post_data();
		$this->manager->create_post_type();
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$this->assertSame( self::UUID, $response['data']['customize_snapshot_uuid'] );
		$this->assertSame( self::UUID, $response['data']['customize_snapshot_next_uuid'] );
		$this->assertEmpty( $response['data']['customize_snapshot_settings'] );
	}

	/**
	 * Helper function to make the Ajax call directy to `Customize_Snapshot_Manager::save_snapshot`.
	 *
	 * @see Customize_Snapshot_Manager::save_snapshot()
	 */
	function make_save_snapshot_ajax_call() {
		try {
			ini_set( 'implicit_flush', false );
			ob_start();
			$manager = new Customize_Snapshot_Manager( $this->plugin );
			$manager->set_snapshot_uuid();
			$manager->save_snapshot();
			$buffer = ob_get_clean();
			if ( ! empty( $buffer ) ) {
				$this->_last_response = $buffer;
			}
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
	}

	/**
	 * Testing post_data for the save_snapshot method
	 */
	function test_ajax_save_snapshot_post_data_check() {
		$_POST = array(
			'snapshot_uuid' => self::UUID,
		);
		$this->make_save_snapshot_ajax_call();
		$response = apply_filters( 'customize_save_response', array(), $this->wp_customize );
		$this->assertEquals( array( 'missing_snapshot_customized' => 'The Snapshots customized data was missing from the request.' ), $response );
	}

}
