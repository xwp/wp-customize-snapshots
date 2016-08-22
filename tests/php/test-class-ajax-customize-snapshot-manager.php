<?php
/**
 * Test Test_Ajax_Customize_Snapshot_Manager.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Ajax_Customize_Snapshot_Manager
 */
class Test_Ajax_Customize_Snapshot_Manager extends \WP_Ajax_UnitTestCase {

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
	 * Instance of WP_Customize_Manager which is reset for each test.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * Manager.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	protected $manager;

	/**
	 * Set up before class.
	 */
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
	}

	/**
	 * Set up the test fixture.
	 */
	public function setUp() {
		parent::setUp();

		remove_all_actions( 'wp_ajax_customize_save' );
		remove_all_actions( 'wp_ajax_customize_update_snapshot' );
		$this->plugin = new Plugin();
		$this->set_input_vars();
		$this->plugin->init();
	}

	/**
	 * Grant Customize to all.
	 *
	 * @param array $allcaps All caps.
	 * @param array $caps    Caps.
	 * @param array $args    Args.
	 * @return array Caps.
	 */
	public function filter_grant_customize_to_all( $allcaps, $caps, $args ) {
		if ( ! empty( $args ) && 'customize' === $args[0] ) {
			$allcaps = array_merge( $allcaps, array_fill_keys( $caps, true ) );
		}
		return $allcaps;
	}

	/**
	 * Set input vars.
	 *
	 * @param array  $vars   Input vars.
	 * @param string $method Request method.
	 */
	public function set_input_vars( array $vars = array(), $method = 'POST' ) {
		$vars = array_merge(
			array(
				'customized' => wp_json_encode( array( 'anyonecanedit' => 'Hello' ) ),
				'wp_customize' => 'on',
				'customize_snapshot_uuid' => self::UUID,
				'nonce' => wp_create_nonce( 'save-customize_' . get_stylesheet() ),
			),
			$vars
		);
		$_GET = $_POST = $_REQUEST = wp_slash( $vars );
		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Set current user.
	 *
	 * @param string $role Role.
	 * @return int User Id.
	 */
	function set_current_user( $role ) {
		$user_id = $this->factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		$_GET['nonce'] = $_REQUEST['nonce'] = $_POST['nonce'] = wp_create_nonce( 'save-customize_' . get_stylesheet() );
		return $user_id;
	}

	/**
	 * Add anyonecanedit Customize setting.
	 */
	function add_setting() {
		$this->plugin->customize_snapshot_manager->customize_manager->add_setting( 'anyonecanedit', array(
			'capability' => 'exist',
		) );
	}

	/**
	 * Tear down.
	 */
	function tearDown() {
		$this->plugin->customize_snapshot_manager->customize_manager = null;
		$this->manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		unset( $_SERVER['REQUEST_METHOD'] );
		unset( $_REQUEST['wp_customize'] );
		unset( $_REQUEST['customize_snapshot_uuid'] );
		unset( $_REQUEST['preview'] );
		parent::tearDown();
	}

	/**
	 * Helper to keep it DRY
	 *
	 * @param string $action Action.
	 */
	protected function make_ajax_call( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
	}

	/**
	 * Testing passing Customize save for a user who has customize_publish capability.
	 */
	function test_ajax_customize_save_passing_customize_publish() {
		$this->set_current_user( 'administrator' );
		$this->plugin->customize_snapshot_manager->customize_manager->setup_theme();
		$this->add_setting();

		$snapshot_uuid = $this->plugin->customize_snapshot_manager->current_snapshot_uuid;
		$snapshot_post_id = $this->plugin->customize_snapshot_manager->post_type->find_post( $snapshot_uuid );
		$this->assertNull( $snapshot_post_id );

		// Get the results.
		$this->make_ajax_call( 'customize_save' );
		$response = json_decode( $this->_last_response, true );

		$this->assertTrue( $response['success'] );
		if ( method_exists( 'WP_Customize_Manager', 'prepare_setting_validity_for_js' ) ) {
			$this->assertArrayHasKey( 'setting_validities', $response['data'] );
			$this->assertArrayHasKey( 'anyonecanedit', $response['data']['setting_validities'] );
			$this->assertTrue( $response['data']['setting_validities']['anyonecanedit'] );
		}
		$this->assertArrayHasKey( 'new_customize_snapshot_uuid', $response['data'] );
		$this->assertTrue( Customize_Snapshot_Manager::is_valid_uuid( $response['data']['new_customize_snapshot_uuid'] ) );

		$snapshot_post_id = $this->plugin->customize_snapshot_manager->post_type->find_post( $snapshot_uuid );
		$this->assertNotNull( $snapshot_post_id );
		$snapshot_post = get_post( $snapshot_post_id );
		$this->assertSame(
			$this->plugin->customize_snapshot_manager->customize_manager->unsanitized_post_values(),
			wp_list_pluck( json_decode( $snapshot_post->post_content, true ), 'value' )
		);
	}

	/**
	 * Testing failing a user who lacks customize_publish capability.
	 */
	function test_ajax_customize_save_failing_customize_publish() {

		add_filter( 'user_has_cap', array( $this, 'filter_grant_customize_to_all' ), 10, 4 );
		$this->set_current_user( 'editor' );
		$this->plugin->customize_snapshot_manager->customize_manager->setup_theme();

		// Get the results.
		$this->make_ajax_call( 'customize_save' );
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data' => array(
				'error' => 'customize_publish_unauthorized',
			),
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * Testing capabilities check for the update_snapshot method.
	 */
	function test_ajax_update_snapshot_nonce_check() {
		$this->set_current_user( 'administrator' );
		$this->set_input_vars( array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => 'bad-nonce-12345',
		) );

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
	 * Testing REQUEST_METHOD for the update_snapshot method.
	 */
	function test_ajax_update_snapshot_post_check() {
		$this->set_current_user( 'administrator' );
		$this->set_input_vars(
			array(
				'action' => Customize_Snapshot_Manager::AJAX_ACTION,
				'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			),
			'GET'
		);
		$this->plugin->customize_snapshot_manager->customize_manager->setup_theme();
		$this->add_setting();

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
		$this->set_current_user( $role );
		$this->set_input_vars(
			array(
				'action' => Customize_Snapshot_Manager::AJAX_ACTION,
				'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			)
		);
		$this->add_setting();

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );

		if ( $response['success'] ) {
			$this->assertNotEmpty( $response['data']['edit_link'] );
			$this->assertNotEmpty( $response['data']['snapshot_publish_date'] );
			unset( $response['data']['edit_link'] );
			unset( $response['data']['snapshot_publish_date'] );
		}
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
		$data = array(
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
		);

		$success_data = array(
			'administrator',
			array(
				'success' => true,
				'data' => array(
					'errors' => null,
					'setting_validities' => array(
						'anyonecanedit' => true,
					),
				),
			),
		);

		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		if ( ! method_exists( 'WP_Customize_Manager', 'prepare_setting_validity_for_js' ) ) {
			$success_data[1]['data'] = array( 'errors' => null );
		}
		$data[] = $success_data;

		return $data;
	}

	/**
	 * Testing post_data for the update_snapshot method
	 */
	function test_ajax_update_snapshot_post_data_check() {
		unset( $GLOBALS['wp_customize'] );
		remove_all_actions( 'wp_ajax_' . Customize_Snapshot_Manager::AJAX_ACTION );

		$this->set_current_user( 'administrator' );
		$this->set_input_vars( array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'customized' => null,
		) );

		$this->plugin = new Plugin();
		$this->plugin->init();
		$this->add_setting();

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
	 * Testing a successful response for the update_snapshot method
	 */
	function test_ajax_update_snapshot_success() {
		$this->set_current_user( 'administrator' );
		$this->set_input_vars( array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
		) );
		$this->add_setting();

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$this->assertNull( $response['data']['errors'] );
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
			$manager->publish_snapshot_with_customize_save_after();
			$buffer = ob_get_clean();
			if ( ! empty( $buffer ) ) {
				$this->_last_response = $buffer;
			}
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
	}

	/**
	 * Testing schedule Snapshot
	 */
	function test_ajax_update_snapshot_schedule() {
		unset( $GLOBALS['wp_customize'] );
		remove_all_actions( 'wp_ajax_' . Customize_Snapshot_Manager::AJAX_ACTION );

		$post_type_obj = get_post_type_object( Post_Type::SLUG );
		$setting_key = 'anyonecanedit';
		$tomorrow = date( 'Y-m-d H:i:s', time() + 86400 );
		$this->set_current_user( 'administrator' );
		$this->assertTrue( current_user_can( $post_type_obj->cap->publish_posts ) );
		$this->set_input_vars( array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'customized' => wp_json_encode( array( $setting_key => 'Hello' ) ),
			'status' => 'future',
			'publish_date' => $tomorrow, // Tomorrow.
		) );

		$this->plugin = new Plugin();
		$this->plugin->init();
		$this->add_setting();

		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );
		$post_id = get_plugin_instance()->customize_snapshot_manager->post_type->find_post( self::UUID );
		$expected_results = array(
			'success' => true,
			'data' => array(
				'errors' => null,
				'setting_validities' => array( $setting_key => true ),
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
				'snapshot_publish_date' => $tomorrow,
			),
		);
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		if ( ! method_exists( 'WP_Customize_Manager', 'prepare_setting_validity_for_js' ) ) {
			unset( $expected_results['data']['setting_validities'] );
		}
		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$this->assertSame( $expected_results, $response );
		$this->assertEquals( 'future', get_post_status( $post_id ) );
	}

	/**
	 * Test updating a snapshot when the user does not have the customize_publish capability.
	 *
	 * @covers \CustomizeSnapshots\Customize_Snapshot_Manager::handle_update_snapshot_request()
	 */
	function test_ajax_update_snapshot_ok_for_draft_and_pending_but_not_future() {
		unset( $GLOBALS['wp_customize'] );
		remove_all_actions( 'wp_ajax_' . Customize_Snapshot_Manager::AJAX_ACTION );

		$post_type_obj = get_post_type_object( Post_Type::SLUG );
		$setting_key = 'anyonecanedit';
		add_filter( 'user_has_cap', function( $allcaps, $caps, $args ) {
			$allcaps['customize'] = true;
			if ( ! empty( $allcaps['edit_posts'] ) && ! empty( $args ) && 'customize' === $args[0] ) {
				$allcaps = array_merge( $allcaps, array_fill_keys( $caps, true ) );
			}
			return $allcaps;
		}, 10, 3 );
		$tomorrow = date( 'Y-m-d H:i:s', time() + 86400 );
		$this->set_current_user( 'contributor' );
		$this->assertFalse( current_user_can( $post_type_obj->cap->publish_posts ) );
		$post_vars = array(
			'action' => Customize_Snapshot_Manager::AJAX_ACTION,
			'nonce' => wp_create_nonce( Customize_Snapshot_Manager::AJAX_ACTION ),
			'customize_snapshot_uuid' => self::UUID,
			'customized' => wp_json_encode( array( $setting_key => 'Hello' ) ),
			'publish_date' => $tomorrow, // Tomorrow.
		);

		$this->plugin = new Plugin();
		$this->plugin->init();
		$this->add_setting();

		// Draft pass.
		$post_vars['status'] = 'draft';
		$this->set_input_vars( $post_vars );
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );
		$response = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		$this->assertTrue( $response['success'] );

		// Pending pass.
		$post_vars['status'] = 'pending';
		$this->set_input_vars( $post_vars );
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );
		$response = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		$this->assertTrue( $response['success'] );

		// Future fail.
		$post_vars['status'] = 'future';
		$this->set_input_vars( $post_vars );
		$this->make_ajax_call( Customize_Snapshot_Manager::AJAX_ACTION );
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data' => 'customize_not_allowed',
		);
		$this->assertSame( $expected_results, $response );
	}
}
