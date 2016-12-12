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
		$migrate_obj->expects( $this->once() )
		            ->method( 'changeset_migrate' )
					->with( 1, false )
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
	 * Test snapshot fork ajax request.
	 *
	 * @covers \CustomizeSnapshots\Post_Type::handle_snapshot_fork()
	 */
	function test_handle_snapshot_fork() {
		unset( $GLOBALS['wp_customize'] );
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$data = array(
			'foo' => array(
				'value' => 'bar',
			),
		);
		$post_id = $post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		$post_vars = array(
			'action' => 'snapshot_fork',
			'nonce' => wp_create_nonce( 'snapshot-fork' ),
			'ID' => $post_id,
		);
		$_GET = $_POST = $_REQUEST = wp_slash( $post_vars );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->make_ajax_call( 'snapshot_fork' );
		$response = json_decode( $this->_last_response, true );
		$this->assertArrayHasKey( 'data', $response );
		$response = $response['data'];
		$this->assertEquals( $post_id, $response['post_parent'] );
		$this->assertEquals( $data, $post_type->get_post_content( get_post( $response['ID'] ) ) );
		$post = get_post( $post_id, ARRAY_A );
		$fork_post = get_post( $response['ID'], ARRAY_A );
		$key = array( 'ID', 'post_title', 'post_parent', 'post_name', 'guid', 'ancestors','tags_input','post_category', 'post_date', 'post_date_gmt', 'post_modified_gmt', 'post_modified' );
		foreach ( $key as $item ) {
			unset( $fork_post[ $item ], $post[ $item ] );
		}
		$this->assertSame( $fork_post, $post );
		$this->assertEquals( get_post_meta( $post_id ), get_post_meta( $response['ID'] ) );
	}

}
