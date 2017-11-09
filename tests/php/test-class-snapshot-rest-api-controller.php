<?php
/**
 * Test Post Type.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Post_type
 */
class Test_Snapshot_REST_API_Controller extends \WP_Test_REST_TestCase {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Server.
	 *
	 * @var \WP_REST_Server
	 */
	public $server;

	/**
	 * Snapshot post IDs s by status.
	 *
	 * @var array
	 */
	public $snapshot_by_status = array();

	/**
	 * End point.
	 *
	 * @var string
	 */
	public $end_point;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();

		$this->end_point = 'customize_changesets';

		$this->plugin->customize_snapshot_manager->post_type->init();

		$snapshot_data = array(
			array(
				'status' => 'draft',
				'date_gmt' => '2010-01-01 00:00:00',
			),
			array(
				'status' => 'pending',
				'date_gmt' => '2010-01-01 00:00:00',
			),
			array(
				'status' => 'publish',
				'date_gmt' => '2010-01-01 00:00:00',
			),
			array(
				'status' => 'future',
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 24 * 3600 ),
			),
		);
		foreach ( $snapshot_data as $i => $snapshot_params ) {
			$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
			$post_id = $this->plugin->customize_snapshot_manager->post_type->save( array_merge(
				$snapshot_params,
				array(
					'uuid' => wp_generate_uuid4(),
					'author' => $user_id,
					'data' => array( 'blogname' => array( 'value' => "Snapshot $i" ) ),
				)
			) );
			$this->snapshot_by_status[ $snapshot_params['status'] ] = $post_id;
		}

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server = rest_get_server();
	}

	/**
	 * Test unauthenticated requests for /wp/v2/$end_point
	 */
	function test_get_collection_unauthenticated() {
		wp_set_current_user( 0 );
		$this->assertFalse( current_user_can( 'customize' ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'rest_customize_unauthorized', $response );
	}

	/**
	 * Test unauthorized requests for /wp/v2/customize_snapshots
	 */
	function test_get_collection_unauthorized() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertFalse( current_user_can( 'customize' ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'rest_customize_unauthorized', $response );
	}

	/**
	 * Test unauthorized requests for /wp/v2/$end_point
	 */
	function test_get_collection_authorized() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'customize' ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test getting published items.
	 */
	function test_get_collection_published() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$items = $response->get_data();
		$this->assertCount( 1, $items );
		$this->assertEquals( 'publish', $items[0]['status'] );
		$this->assertArrayHasKey( 'content', $items[0] );
		$this->assertArrayHasKey( 'blogname', $items[0]['content'] );
		$this->assertArrayHasKey( 'value', $items[0]['content']['blogname'] );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_url_params( array( 'status' => 'publish' ) );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( $items, $response->get_data() );
	}

	/**
	 * Test getting any items.
	 */
	function test_get_collection_any() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'any' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$items = $response->get_data();
		$this->assertCount( 4, $items );
		$item_statuses = wp_list_pluck( $items, 'status' );
		$this->assertContains( 'draft', $item_statuses );
		$this->assertContains( 'pending', $item_statuses );
		$this->assertContains( 'publish', $item_statuses );
		$this->assertContains( 'future', $item_statuses );
	}

	/**
	 * Test getting items by author.
	 */
	function test_get_collection_by_author() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'any' );
		$response = $this->server->dispatch( $request );
		$items = $response->get_data();
		$item_authors = array_values( wp_list_pluck( $items, 'author' ) );

		$item_author_mapping = array();
		foreach ( $items as $item ) {
			$item_author_mapping[ $item['author'] ] = $item['slug'];
		}

		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'any' );
		$request->set_param( 'author', $item_authors[0] );
		$response = $this->server->dispatch( $request );
		$items = $response->get_data();
		$this->assertCount( 1, $items );
		$this->assertEquals( $items[0]['slug'], $item_author_mapping[ $item_authors[0] ] );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'any' );
		$request->set_param( 'author', get_user_by( 'id', $item_authors[0] )->user_nicename );
		$response = $this->server->dispatch( $request );
		$items = $response->get_data();
		$this->assertCount( 1, $items );
		$this->assertEquals( $items[0]['slug'], $item_author_mapping[ $item_authors[0] ] );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'any' );
		$request->set_param( 'author', join( ',', $item_authors ) );
		$response = $this->server->dispatch( $request );
		$items = $response->get_data();
		$this->assertCount( 4, $items );
	}

	/**
	 * Test getting published items.
	 */
	function test_get_item_published() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = get_post( $this->snapshot_by_status['publish'] );
		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $this->end_point . '/' . $post->ID );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$item = $response->get_data();
		$this->assertArrayHasKey( 'content', $item );
		$this->assertArrayHasKey( 'blogname', $item['content'] );
		$this->assertArrayHasKey( 'value', $item['content']['blogname'] );
		$this->assertEquals( 'publish', $item['status'] );
	}

	/**
	 * Test create item.
	 */
	function test_create_item() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/' . $this->end_point );
		$request->set_param( 'content', array( 'blogname' => array( 'value' => 'test' ) ) );
		$request->set_param( 'slug', wp_generate_uuid4() );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_create', $response );
	}

	/**
	 * Test update item.
	 */
	function test_update_item() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = get_post( $this->snapshot_by_status['publish'] );
		$request = new \WP_REST_Request( 'PUT', '/wp/v2/' . $this->end_point . '/' . $post->ID );
		$request->set_param( 'content', array( 'blogname' => array( 'value' => 'test' ) ) );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'invalid-method', $response );
	}

	/**
	 * Test update item.
	 */
	function test_delete_item() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = get_post( $this->snapshot_by_status['publish'] );
		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/' . $this->end_point . '/' . $post->ID );
		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'invalid-method', $response );
	}
}
