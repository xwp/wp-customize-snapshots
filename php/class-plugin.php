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
	 * Initiate the plugin resources.
	 *
	 * Priority is 8 because \CustomizeWidgetsPlus\Widget_Posts::init() happens
	 * at priority 9, and this calls \CustomizeWidgetsPlus\Widget_Posts::add_customize_hooks().
	 * So we need priority 8 so that the Customizer will be initialized before Widget Posts
	 * initializes.
	 *
	 * @action after_setup_theme, 8
	 */
	public function init() {
		$this->customize_snapshot_manager = new Customize_Snapshot_Manager( $this );
		$this->customize_snapshot_manager->init();
	}

	/**
	 * Register scripts.
	 *
	 * @action wp_default_scripts, 11
	 *
	 * @param \WP_Scripts $wp_scripts Instance of \WP_Scripts.
	 */
	public function register_scripts( \WP_Scripts $wp_scripts ) {
		$min = ( SCRIPT_DEBUG ? '' : '.min' );
		$src = $this->dir_url . 'js/customize-snapshots' . $min . '.js';
		$deps = array( 'jquery', 'jquery-ui-dialog', 'wp-util', 'customize-controls' );
		$wp_scripts->add( $this->slug, $src, $deps );
	}

	/**
	 * Register styles.
	 *
	 * @action wp_default_styles, 11
	 *
	 * @param \WP_Styles $wp_styles Instance of \WP_Styles.
	 */
	public function register_styles( \WP_Styles $wp_styles ) {
		$min = ( SCRIPT_DEBUG ? '' : '.min' );
		$src = $this->dir_url . 'css/customize-snapshots' . $min . '.css';
		$deps = array( 'wp-jquery-ui-dialog' );
		$wp_styles->add( $this->slug, $src, $deps );
	}
}
