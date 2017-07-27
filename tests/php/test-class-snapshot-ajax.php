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

}
