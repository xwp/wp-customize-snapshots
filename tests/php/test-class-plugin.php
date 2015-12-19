<?php

namespace CustomizeSnapshots;

class Test_Plugin extends \WP_UnitTestCase {

	/**
	 * @see Plugin::__construct()
	 */
	function test_construct() {
		$plugin = get_plugin_instance();
		$this->assertEquals( 8, has_action( 'after_setup_theme', array( $plugin, 'init' ) ) );
	}

	/**
	 * @see Plugin::init()
	 */
	function test_init() {
		$plugin = get_plugin_instance();
		$plugin->init();
		$this->assertEquals( 11, has_action( 'wp_default_scripts', array( $plugin, 'register_scripts' ) ) );
		$this->assertEquals( 11, has_action( 'wp_default_styles', array( $plugin, 'register_styles' ) ) );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Manager', $plugin->customize_snapshot_manager );
	}

	/**
	 * @see Plugin::filter_user_has_cap()
	 */
	function test_filter_user_has_cap() {
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $editor_user_id, 'customize_publish' ) );
		$this->assertTrue( user_can( $admin_user_id, 'customize_publish' ) );
	}
}
