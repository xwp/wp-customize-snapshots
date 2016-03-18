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

		/*
		 * Priority is 8 because \CustomizeWidgetsPlus\Widget_Posts::init() happens
		 * at priority 9, and this calls \CustomizeWidgetsPlus\Widget_Posts::add_customize_hooks().
		 * So we need priority 8 so that the Customizer will be initialized before Widget Posts
		 * initializes.
		 */
		$priority = 8;
		add_action( 'after_setup_theme', array( $this, 'init' ), $priority );
	}

	/**
	 * Initiate the plugin resources.
	 *
	 * @action after_setup_theme
	 */
	public function init() {
		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 11 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 11 );
		add_action( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10 );

		$this->customize_snapshot_manager = new Customize_Snapshot_Manager( $this );
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts Instance of \WP_Scripts.
	 * @action wp_default_scripts
	 */
	public function register_scripts( \WP_Scripts $wp_scripts ) {
		$min = ( SCRIPT_DEBUG ? '' : '.min' );
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
	public function register_styles( \WP_Styles $wp_styles ) {
		$min = ( SCRIPT_DEBUG ? '' : '.min' );
		$src = $this->dir_url . 'css/customize-snapshots' . $min . '.css';
		$deps = array( 'wp-jquery-ui-dialog' );
		$wp_styles->add( $this->slug, $src, $deps );
	}

	/**
	 * Add the customize_publish capability to users who can edit_theme_options by default.
	 *
	 * @param array $allcaps An array of all the user's capabilities.
	 * @return array All caps.
	 */
	public function filter_user_has_cap( $allcaps ) {
		if ( ! empty( $allcaps['edit_theme_options'] ) ) {
			$allcaps['customize_publish'] = true;
		}

		return $allcaps;
	}
}
