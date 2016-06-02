<?php
/**
 * Tests for Plugin_Base.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Tests for Plugin_Base.
 */
class Test_Plugin_Base extends \WP_UnitTestCase {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Setup.
	 *
	 * @inheritdoc
	 */
	public function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
	}

	/**
	 * Test locate_plugin.
	 *
	 * @see Plugin_Base::locate_plugin()
	 */
	public function test_locate_plugin() {
		$location = $this->plugin->locate_plugin();
		$this->assertEquals( 'customize-snapshots', $location['dir_basename'] );
		$this->assertContains( 'plugins/customize-snapshots', $location['dir_path'] );
		$this->assertContains( 'plugins/customize-snapshots', $location['dir_url'] );
	}

	/**
	 * Test relative_path.
	 *
	 * @see Plugin_Base::relative_path()
	 */
	public function test_relative_path() {
		$this->assertEquals( 'plugins/customize-snapshots', $this->plugin->relative_path( '/srv/www/wordpress-develop/src/wp-content/plugins/customize-snapshots', 'wp-content', '/' ) );
		$this->assertEquals( 'themes/twentysixteen/plugins/customize-snapshots', $this->plugin->relative_path( '/srv/www/wordpress-develop/src/wp-content/themes/twentysixteen/plugins/customize-snapshots', 'wp-content', '/' ) );
	}

	/**
	 * Tests for trigger_warning().
	 *
	 * @see Plugin_Base::trigger_warning()
	 */
	public function test_trigger_warning() {
		$obj = $this;
		set_error_handler( function ( $errno, $errstr ) use ( $obj ) {
			$obj->assertEquals( 'CustomizeSnapshots\Plugin: Param is 0!', $errstr );
			$obj->assertEquals( \E_USER_WARNING, $errno );
		} );
		$this->plugin->trigger_warning( 'Param is 0!', \E_USER_WARNING );
		restore_error_handler();
	}

	/**
	 * Test is_wpcom_vip_prod().
	 *
	 * @see Plugin_Base::is_wpcom_vip_prod()
	 */
	public function test_is_wpcom_vip_prod() {
		if ( ! defined( 'WPCOM_IS_VIP_ENV' ) ) {
			$this->assertFalse( $this->plugin->is_wpcom_vip_prod() );
			define( 'WPCOM_IS_VIP_ENV', true );
		}
		$this->assertEquals( WPCOM_IS_VIP_ENV, $this->plugin->is_wpcom_vip_prod() );
	}
}
