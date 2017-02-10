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
		if ( $plugin->compat ) {
			$this->front_param = 'customize_snapshot_uuid';
		} else {
			$this->front_param = 'changeset_uuid';
		}
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
	 * Tests is_previewing_settings().
	 *
	 * @see is_previewing_settings()
	 */
	public function test_is_previewing_settings() {
		if ( ! get_plugin_instance()->compat ) {
			$this->markTestIncomplete( 'WordPress Version 4.6.x or below is required for this test-case.' );
		}
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
		$uuid = '65aee1ff-af47-47df-9e14-9c69b3017cd3';
		$_REQUEST[ $this->front_param ] = $uuid;
		$customize_snapshots_plugin = new Plugin();
		$customize_snapshots_plugin->init();
		$this->assertEquals( $uuid, current_snapshot_uuid() );
	}
}
