<?php
/**
 * Class Test_Customize_Snapshots
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Customize_Snapshots
 */
class Test_Customize_Snapshots extends \WP_UnitTestCase {

	/**
	 * Frontend UUID
	 *
	 * @var string
	 */
	public $front_param;

	/**
	 * Set up.
	 */
	function setUp() {
		$plugin = get_plugin_instance();
		$this->front_param = 'changeset_uuid';
	}

	/**
	 * Clean up global scope.
	 */
	function clean_up_global_scope() {
		global $customize_snapshots_plugin;
		unset( $_REQUEST[ $this->front_param ] );
		parent::clean_up_global_scope();
		$customize_snapshots_plugin = new Plugin();
		$customize_snapshots_plugin->init();
	}

	/**
	 * Test customize_snapshots_php_version_error.
	 *
	 * @see customize_snapshots_php_version_error()
	 */
	function test_customize_snapshots_php_version_error() {
		ob_start();
		customize_snapshots_php_version_error();
		$buffer = ob_get_clean();
		$this->assertContains( '<div class="error">', $buffer );
	}

	/**
	 * Test customize_snapshots_php_version_text.
	 *
	 * @see customize_snapshots_php_version_text()
	 */
	function test_customize_snapshots_php_version_text() {
		$this->assertContains( 'Customize Snapshots plugin error:', customize_snapshots_php_version_text() );
	}

	/**
	 * Tests current_snapshot_uuid().
	 *
	 * @see current_snapshot_uuid()
	 */
	public function test_current_snapshot_uuid() {
		global $wp_customize;
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$changeset_uuid = '65aee1ff-af47-47df-9e14-9c69b3017cd3';
		$wp_customize = new \WP_Customize_Manager( compact( 'changeset_uuid' ) );
		$this->assertEquals( $changeset_uuid, current_snapshot_uuid() );
	}
}
