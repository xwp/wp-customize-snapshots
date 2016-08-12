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
	 * Clean up global scope.
	 */
	function clean_up_global_scope() {
		global $customize_snapshots_plugin;
		unset( $_REQUEST['customize_snapshot_uuid'] );
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
	 * Tests is_previewing_settings().
	 *
	 * @see is_previewing_settings()
	 */
	public function test_is_previewing_settings() {
		$this->assertFalse( is_previewing_settings() );
		do_action( 'customize_preview_init' );
		$this->assertTrue( is_previewing_settings() );
	}

	/**
	 * Tests current_snapshot_uuid().
	 *
	 * @see current_snapshot_uuid()
	 */
	public function test_current_snapshot_uuid() {
		global $customize_snapshots_plugin;
		$this->assertNull( current_snapshot_uuid() );
		$uuid = '65aee1ff-af47-47df-9e14-9c69b3017cd3';
		$_REQUEST['customize_snapshot_uuid'] = $uuid;
		$customize_snapshots_plugin = new Plugin();
		$customize_snapshots_plugin->init();
		$this->assertEquals( $uuid, current_snapshot_uuid() );
	}
}
