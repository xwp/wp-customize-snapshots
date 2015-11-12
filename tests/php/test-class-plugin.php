<?php

namespace CustomizeSnapshots;

class Test_Plugin extends \WP_UnitTestCase {

	/**
	 * @see Plugin::__construct()
	 */
	function test_construct() {
		$plugin = get_plugin_instance();
		$this->assertEquals( 9, has_action( 'after_setup_theme', array( $plugin, 'init' ) ) );
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
	 * @see Plugin::add_cap()
	 */
	function test_add_cap() {
		$plugin = get_plugin_instance();
		$this->assertFalse( wp_roles()->get_role( 'editor' )->has_cap( 'customize_publish' ) );
		$this->assertTrue( wp_roles()->get_role( 'administrator' )->has_cap( 'customize_publish' ) );
	}
}
