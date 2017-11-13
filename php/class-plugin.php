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
	 * @todo Rename this to just `$manager` and let the class be `Manager`.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	public $customize_snapshot_manager;

	/**
	 * Version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		// Parse plugin version.
		if ( preg_match( '/Version:\s*(\S+)/', file_get_contents( __DIR__ . '/../customize-snapshots.php' ), $matches ) ) { // @codingStandardsIgnoreLine because file_get_contents() is not requesting a URL.
			$this->version = $matches[1];
		}

		add_filter( 'customize_changeset_branching', '__return_true' );
		load_plugin_textdomain( 'customize-snapshots' );
		$this->param_back_compat();
		parent::__construct();
	}

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
		$this->customize_snapshot_manager = is_back_compat() ? new Customize_Snapshot_Manager_Compat( $this ) : new Customize_Snapshot_Manager( $this );
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
		$is_git_repo = file_exists( dirname( __DIR__ ) . '/.git' );
		$min = ( SCRIPT_DEBUG || $is_git_repo ? '' : '.min' );

		$handle = 'customize-snapshots';
		if ( version_compare( strtok( get_bloginfo( 'version' ), '-' ), '4.9', '>=' ) ) {
			$src = $this->dir_url . 'js/customize-snapshots' . $min . '.js';
		} else {
			$src = $this->dir_url . 'js/customize-snapshots-compat' . $min . '.js';
		}
		$deps = array( 'jquery', 'jquery-ui-dialog', 'jquery-ui-selectmenu', 'wp-util', 'customize-controls' );
		$wp_scripts->add( $handle, $src, $deps );

		$handle = 'customize-snapshots-frontend';
		$src = $this->dir_url . 'js/customize-snapshots-frontend' . $min . '.js';
		$deps = array( 'jquery', 'underscore' );
		$wp_scripts->add( $handle, $src, $deps );

		$handle = 'customize-snapshots-admin';
		$src = $this->dir_url . 'js/customize-snapshots-admin' . $min . '.js';
		$deps = array( 'jquery', 'underscore' );
		$wp_scripts->add( $handle, $src, $deps );
	}

	/**
	 * Register styles.
	 *
	 * @action wp_default_styles, 11
	 *
	 * @param \WP_Styles $wp_styles Instance of \WP_Styles.
	 */
	public function register_styles( \WP_Styles $wp_styles ) {
		$is_git_repo = file_exists( dirname( __DIR__ ) . '/.git' );
		$min = ( SCRIPT_DEBUG || $is_git_repo ? '' : '.min' );

		$handle = 'customize-snapshots';
		if ( version_compare( strtok( get_bloginfo( 'version' ), '-' ), '4.9', '>=' ) ) {
			$src = $this->dir_url . 'css/customize-snapshots' . $min . '.css';
		} else {
			$src = $this->dir_url . 'css/customize-snapshots-compat' . $min . '.css';
		}
		$deps = array( 'wp-jquery-ui-dialog' );
		$wp_styles->add( $handle, $src, $deps );

		$handle = 'customize-snapshots-preview';
		$src = $this->dir_url . 'css/customize-snapshots-preview' . $min . '.css';
		$deps = array( 'customize-preview' );
		$wp_styles->add( $handle, $src, $deps );

		$handle = 'customize-snapshots-admin';
		$src = $this->dir_url . 'css/customize-snapshots-admin' . $min . '.css';
		$wp_styles->add( $handle, $src );
	}

	/**
	 * Continue allowing support of param customize_snapshot_uuid in 4.7+.
	 */
	public function param_back_compat() {
		if ( isset( $_REQUEST['customize_snapshot_uuid'] ) ) { // WPCS: input var ok. CSRF ok.
			$_REQUEST['customize_changeset_uuid'] = $_REQUEST['customize_snapshot_uuid']; // WPCS: input var ok. CSRF ok. Sanitization ok.
			$_GET['customize_changeset_uuid'] = $_REQUEST['customize_snapshot_uuid']; // WPCS: input var ok. CSRF ok. Sanitization ok.
			$_POST['customize_changeset_uuid'] = $_REQUEST['customize_snapshot_uuid']; // WPCS: input var ok. CSRF ok. Sanitization ok.
		}
	}
}
