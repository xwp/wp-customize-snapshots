<?php
/**
 * Bootstraps the Customize Snapshots plugin.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * Snapshot manager instance.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	public $customize_snapshot_manager;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'after_setup_theme', array( $this, 'init' ) );
	}

	/**
	 * Initiate the plugin resources.
	 *
	 * @action after_setup_theme
	 */
	function init() {
		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 11 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 11 );

		$this->customize_snapshot_manager = new Customize_Snapshot_Manager( $this );
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts Instance of \WP_Scripts.
	 * @action wp_default_scripts
	 */
	function register_scripts( \WP_Scripts $wp_scripts ) {
		$min = ( WP_DEBUG ? '' : '.min' );
		$src = $this->dir_url . 'js/customize-snapshots' . $min . '.js';
		$deps = array( 'jquery', 'jquery-ui-dialog', 'wp-util', 'customize-widgets' );
		$wp_scripts->add( $this->slug, $src, $deps );
	}

	/**
	 * Register styles.
	 *
	 * @param \WP_Styles $wp_styles Instance of \WP_Styles.
	 * @action wp_default_styles
	 */
	function register_styles( \WP_Styles $wp_styles ) {
		$min = ( WP_DEBUG ? '' : '.min' );
		$src = $this->dir_url . 'css/customize-snapshots' . $min . '.css';
		$deps = array( 'wp-jquery-ui-dialog' );
		$wp_styles->add( $this->slug, $src, $deps );
	}
}
