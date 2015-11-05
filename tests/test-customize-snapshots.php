<?php

class Test_Customize_Snapshots extends \WP_UnitTestCase {

	/**
	 * @see customize_snapshots_php_version_error()
	 */
	function test_customize_snapshots_php_version_error() {
		ob_start();
		customize_snapshots_php_version_error();
		$buffer = ob_get_clean();
		$this->assertContains( '<div class="error">', $buffer );
	}

	/**
	 * @see customize_snapshots_php_version_text()
	 */
	function test_customize_snapshots_php_version_text() {
		$this->assertContains( 'Customize Snapshots plugin error:', customize_snapshots_php_version_text() );
	}
}
