<?php
/**
 * Test Test_Snapshot_Ajax.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Snapshot_Ajax
 */
class Test_Snapshot_Ajax extends \WP_Ajax_UnitTestCase {

	/**
	 * Plugin instace.
	 *
	 * @var Plugin
	 */
	var $plugin;

	/**
	 * Setup.
	 */
	public function setUp() {
		$this->plugin = get_plugin_instance();
		if ( $this->plugin->compat ) {
			$this->markTestSkipped( 'This unit-test require WP version 4.7 or up.' );
		}
		parent::setUp();
	}
	/**
	 * Test handle_migrate_changeset_request.
	 *
	 * @see Migrate::handle_migrate_changeset_request()
	 */
	function test_handle_migrate_changeset_request() {
		remove_all_actions( 'wp_ajax_customize_snapshot_migration' );
		delete_option( Migrate::KEY );
		$migrate_obj = $this->getMockBuilder( 'CustomizeSnapshots\Migrate' )
			->setMethods( array( 'changeset_migrate' ) )
			->setConstructorArgs( array( $this->plugin ) )
			->getMock();
		$migrate_obj->expects( $this->any() )
			->method( 'changeset_migrate' )
			->will( $this->returnValue( 92 ) );
		$migrate_obj->maybe_migrate();
		$this->set_input_vars(array(
			'nonce' => wp_create_nonce( 'customize-snapshot-migration' ),
			'limit' => 1,
		));
		$this->make_ajax_call( 'customize_snapshot_migration' );
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'remaining_posts', $response['data'] );
		$this->assertEquals( 91, $response['data']['remaining_posts'] );
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
	 * Set input vars.
	 *
	 * @param array  $vars   Input vars.
	 * @param string $method Request method.
	 */
	public function set_input_vars( array $vars = array(), $method = 'POST' ) {
		$_GET = $_POST = $_REQUEST = wp_slash( $vars );
		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Test ajax handle conflict snapshots request
	 *
	 * @see Customize_Snapshot_Manager::handle_conflicts_snapshot_request()
	 */
	function test_ajax_handle_conflicts_snapshot_request() {
		unset( $GLOBALS['wp_customize'] );
		$tomorrow = date( 'Y-m-d H:i:s', time() + 86400 );
		remove_all_actions( 'wp_ajax_customize_snapshot_conflict_check' );
		$this->set_current_user( 'administrator' );
		$uuid = Customize_Snapshot_Manager::generate_uuid();
		$this->set_input_vars( array(
			'action' => 'customize_snapshot_conflict_check',
			'nonce' => wp_create_nonce( $this->plugin->customize_snapshot_manager->get_post_type() . '_conflict' ),
			$this->plugin->customize_snapshot_manager->get_customize_uuid_param() => $uuid,
			'setting_ids' => array( 'foo' ),
		) );

		$plugin = new Plugin();
		$plugin->init();
		$post_type = $this->plugin->customize_snapshot_manager->post_type;
		$post_type->save( array(
			'uuid' => $uuid,
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
			'status' => 'future',
			'date_gmt' => $tomorrow,
		) );
		$post_id = $post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => array( 'foo' => array( 'value' => 'baz' ) ),
			'status' => 'future',
			'date_gmt' => $tomorrow,
		) );
		$post = get_post( $post_id );
		$this->make_ajax_call( 'customize_snapshot_conflict_check' );
		$response = json_decode( $this->_last_response, true );
		$this->assertNotEmpty( $response['data']['foo'][0] );
		unset( $response['data']['foo'][0] );
		$this->assertSame( array(
			'success' => true,
			'data' => array(
				'foo' => array(
					1 => array(
						'id' => (string) $post->ID,
						'value' => $post_type->get_printable_setting_value( 'baz', 'foo' ),
						'name' => $post->post_title === $post->post_name ? '' : $post->post_title,
						'uuid' => $post->post_name,
						'edit_link' => get_edit_post_link( $post, 'raw' ),
					),
				),
			),
		), $response );
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
		return $user_id;
	}

	/**
	 * Get plugin instance accoding to WP version.
	 *
	 * @param Customize_Snapshot_Manager|Customize_Snapshot_Manager_Back_Compat $manager Manager.
	 *
	 * @return Post_Type|Post_Type_Back_Compat Post type object.
	 */
	public function get_new_post_type_instance( $manager ) {
		if ( $this->plugin->compat ) {
			return new Post_Type_Back_Compat( $manager );
		} else {
			return new Post_Type( $manager );
		}
	}

}
