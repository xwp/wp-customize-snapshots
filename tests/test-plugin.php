<?php

namespace CustomizeSnapshots;

class Test_Plugin extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
	}

	/**
	 * @see Plugin::__construct()
	 */
	function test_construct() {
		$this->assertEquals( 10, has_action( 'after_setup_theme', array( $this->plugin, 'init' ) ) );
	}
	
	/**
	 * @see Plugin::init()
	 */
	function test_init() {
		$this->plugin->init();
		$this->assertEquals( 11, has_action( 'wp_default_scripts', array( $this->plugin, 'register_scripts' ) ) );
		$this->assertEquals( 11, has_action( 'wp_default_styles', array( $this->plugin, 'register_styles' ) ) );
		$this->assertInstanceOf( 'CustomizeSnapshots\Customize_Snapshot_Manager', $this->plugin->customize_snapshot_manager );
	}
}
