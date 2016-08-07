<?php
/**
 * Customize Snapshot Manager.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Manager Class
 *
 * Implements a snapshot manager for Customizer settings
 *
 * @package CustomizeSnapshots
 */
class Customize_Snapshot_Manager {

	/**
	 * Action nonce.
	 *
	 * @type string
	 */
	const AJAX_ACTION = 'customize_update_snapshot';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Post type.
	 *
	 * @var Post_Type
	 */
	public $post_type;

	/**
	 * Customize_Snapshot instance.
	 *
	 * @todo Rename `Customize_Snapshot` to just `Snapshot`.
	 *
	 * @var Customize_Snapshot
	 */
	public $snapshot;

	/**
	 * Customize manager.
	 *
	 * @var \WP_Customize_Manager
	 */
	public $customize_manager;

	/**
	 * Snapshot UUID for the current request.
	 *
	 * @access public
	 * @var string
	 */
	public $current_snapshot_uuid;

	/**
	 * The originally active theme.
	 *
	 * @access public
	 * @var string
	 */
	public $original_stylesheet;

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->original_stylesheet = get_stylesheet();
	}

	/**
	 * Init.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	function init() {
		$this->post_type = new Post_Type( $this );
		add_action( 'init', array( $this->post_type, 'register' ) );

		add_action( 'template_redirect', array( $this, 'show_theme_switch_error' ) );

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_controls_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

		add_action( 'customize_controls_init', array( $this, 'add_snapshot_uuid_to_return_url' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );
		add_action( 'customize_save', array( $this, 'check_customize_publish_authorization' ), 10, 0 );
		add_filter( 'customize_refresh_nonces', array( $this, 'filter_customize_refresh_nonces' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_menu' ), 41 );

		add_filter( 'wp_insert_post_data', array( $this, 'prepare_snapshot_post_content_for_publish' ) );
		add_action( 'customize_save_after', array( $this, 'publish_snapshot_with_customize_save_after' ) );
		add_action( 'transition_post_status', array( $this, 'save_settings_with_publish_snapshot' ), 10, 3 );

		if ( isset( $_REQUEST['customize_snapshot_uuid'] ) ) { // WPCS: input var ok.
			$uuid = sanitize_key( wp_unslash( $_REQUEST['customize_snapshot_uuid'] ) ); // WPCS: input var ok.
			if ( static::is_valid_uuid( $uuid ) ) {
				$this->current_snapshot_uuid = $uuid;
			}
		}

		if ( $this->current_snapshot_uuid ) {
			$this->ensure_customize_manager();

			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_update_snapshot_request' ) );

			$this->snapshot = new Customize_Snapshot( $this, $this->current_snapshot_uuid );

			if ( true === $this->should_import_and_preview_snapshot( $this->snapshot ) ) {

				$this->add_widget_setting_preview_filters();
				$this->add_nav_menu_setting_preview_filters();

				/*
				 * Populate post values.
				 *
				 * Note we have to defer until setup_theme since the transaction
				 * can be set beforehand, and wp_magic_quotes() would not have
				 * been called yet, resulting in a $_POST['customized'] that is
				 * double-escaped. Note that this happens at priority 1, which
				 * is immediately after Customize_Snapshot_Manager::store_customized_post_data
				 * which happens at setup_theme priority 0, so that the initial
				 * POST data can be preserved.
				 */
				if ( did_action( 'setup_theme' ) ) {
					$this->import_snapshot_data();
				} else {
					add_action( 'setup_theme', array( $this, 'import_snapshot_data' ) );
				}

				// Block the robots.
				add_action( 'wp_head', 'wp_no_robots' );

				// Preview post values.
				if ( did_action( 'wp_loaded' ) ) {
					$this->preview_snapshot_settings();
				} else {
					add_action( 'wp_loaded', array( $this, 'preview_snapshot_settings' ), 11 );
				}
			}
		}
	}

	/**
	 * Return true if it's a customize_save Ajax request.
	 *
	 * @return bool True if it's an Ajax request, false otherwise.
	 */
	public function doing_customize_save_ajax() {
		return isset( $_REQUEST['action'] ) && wp_unslash( $_REQUEST['action'] ) === 'customize_save';
	}

	/**
	 * Ensure Customizer manager is instantiated.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function ensure_customize_manager() {
		global $wp_customize;
		if ( empty( $wp_customize ) || ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$wp_customize = new \WP_Customize_Manager(); // WPCS: override ok.
		}

		$this->customize_manager = $wp_customize;
	}

	/**
	 * Is previewing another theme.
	 *
	 * @return bool Whether theme is active.
	 */
	public function is_theme_active() {
		if ( empty( $this->customize_manager ) ) {
			return true;
		}
		return $this->customize_manager->get_stylesheet() === $this->original_stylesheet;
	}

	/**
	 * Determine whether the current snapshot can be previewed.
	 *
	 * @param Customize_Snapshot $snapshot Snapshot to check.
	 * @return true|\WP_Error Returns true if previewable, or `WP_Error` if cannot.
	 */
	public function should_import_and_preview_snapshot( Customize_Snapshot $snapshot ) {

		if ( is_wp_error( $this->get_theme_switch_error( $snapshot ) ) ) {
			return false;
		}

		// Abort if doing customize_save.
		if ( $this->doing_customize_save_ajax() ) {
			return false;
		}

		// Abort if the snapshot was already published.
		if ( $snapshot->saved() && 'publish' === get_post_status( $snapshot->post() ) ) {
			return false;
		}

		/*
		 * Prevent clobbering existing values (or previewing non-snapshotted values on frontend).
		 * Note that wp.customize.Snapshots.extendPreviewerQuery() will extend the
		 * previewer data to include the current snapshot UUID.
		 */
		if ( count( $this->customize_manager->unsanitized_post_values() ) > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Populate post values and $_POST['customized'] wth the snapshot's data.
	 *
	 * Plugins used to have to dynamically register settings by inspecting the
	 * $_POST['customized'] var and manually re-parse and inspect to see if it
	 * contains settings that wouldn't be registered otherwise. This ensures
	 * that these plugins will continue to work.
	 *
	 * Note that this can't be called prior to the setup_theme action or else
	 * magic quotes may end up getting added twice.
	 *
	 * @see Customize_Snapshot_Manager::should_import_and_preview_snapshot()
	 */
	public function import_snapshot_data() {
		/*
		 * We don't merge the snapshot data with any existing existing unsanitized
		 * post values since should_import_and_preview_snapshot returns false if
		 * there is any existing data in the Customizer state. This is to prevent
		 * clobbering existing values (or previewing non-snapshotted values on frontend).
		 * Note that wp.customize.Snapshots.extendPreviewerQuery() will extend the
		 * previewer data to include the current snapshot UUID.
		 */
		$snapshot_values = array_filter(
			wp_list_pluck( $this->snapshot->data(), 'value' ),
			function( $value ) {
				return ! is_null( $value );
			}
		);

		// Populate input vars for back-compat.
		$_POST['customized'] = wp_slash( wp_json_encode( $snapshot_values ) );
		// @codingStandardsIgnoreStart
		$_REQUEST['customized'] = $_POST['customized'];
		// @codingStandardsIgnoreEnd

		foreach ( $snapshot_values as $setting_id => $value ) {
			$this->customize_manager->set_post_value( $setting_id, $value );
		}
	}

	/**
	 * Preview the snapshot settings.
	 *
	 * Note that this happens at `wp_loaded` action with priority 11 so that we
	 * can look at whether the `customize_preview_init` action was done.
	 */
	public function preview_snapshot_settings() {

		// Short-circuit because if customize_preview_init happened, then all settings have been previewed.
		if ( did_action( 'customize_preview_init' ) ) {
			return;
		}

		/*
		 * Note that we need to preview the settings outside the Customizer preview
		 * and in the Customizer pane itself so we can load a previous snapshot
		 * into the Customizer. We have to prevent the previews from being added
		 * in the case of a customize_save action because then update_option()
		 * may short-circuit because it will detect that there are no changes to
		 * make.
		 */
		foreach ( $this->snapshot->settings() as $setting ) {
			$setting->preview();
			$setting->dirty = true;
		}
	}

	/**
	 * Add filters for previewing widgets on the frontend.
	 */
	function add_widget_setting_preview_filters() {
		/*
		 * Add WP_Customize_Widget component hooks which were short-circuited in 4.5 (r36611 for #35895).
		 * See https://core.trac.wordpress.org/ticket/35895
		 */
		if ( isset( $this->customize_manager->widgets ) && ! current_user_can( 'edit_theme_options' ) ) {
			$hooks = array(
				'customize_dynamic_setting_args' => array(
					'callback' => array( $this->customize_manager->widgets, 'filter_customize_dynamic_setting_args' ),
					'priority' => 10,
				),
				'widgets_init' => array(
					'callback' => array( $this->customize_manager->widgets, 'register_settings' ),
					'priority' => 95,
				),
				'customize_register' => array(
					'callback' => array( $this->customize_manager->widgets, 'schedule_customize_register' ),
					'priority' => 1,
				),
			);
			foreach ( $hooks as $hook_name => $hook_args ) {
				// Note that add_action()/has_action() are just aliases for add_filter()/has_filter().
				if ( ! has_filter( $hook_name, $hook_args['callback'] ) ) {
					add_filter( $hook_name, $hook_args['callback'], $hook_args['priority'], PHP_INT_MAX );
				}
			}
		}

		/*
		 * Disable routine which fails because \WP_Customize_Manager::setup_theme() is
		 * never called in a frontend preview context, whereby the original_stylesheet
		 * is never set and so \WP_Customize_Manager::is_theme_active() will thus
		 * always return true because get_stylesheet() !== null.
		 *
		 * The action being removed is responsible for adding an option_sidebar_widgets
		 * filter \WP_Customize_Widgets::filter_option_sidebars_widgets_for_theme_switch()
		 * which causes the sidebars_widgets to be overridden with a global variable.
		 */
		if ( ! is_admin() ) {
			remove_action( 'wp_loaded', array( $this->customize_manager->widgets, 'override_sidebars_widgets_for_theme_switch' ) );
		}
	}

	/**
	 * Add filters for previewing nav menus on the frontend.
	 */
	public function add_nav_menu_setting_preview_filters() {
		if ( isset( $this->customize_manager->nav_menus ) && ! current_user_can( 'edit_theme_options' ) ) {
			$hooks = array(
				'customize_register' => array(
					'callback' => array( $this->customize_manager->nav_menus, 'customize_register' ),
					'priority' => 11,
				),
				'customize_dynamic_setting_args' => array(
					'callback' => array( $this->customize_manager->nav_menus, 'filter_dynamic_setting_args' ),
					'priority' => 10,
				),
				'customize_dynamic_setting_class' => array(
					'callback' => array( $this->customize_manager->nav_menus, 'filter_dynamic_setting_class' ),
					'priority' => 10,
				),
				'wp_nav_menu_args' => array(
					'callback' => array( $this->customize_manager->nav_menus, 'filter_wp_nav_menu_args' ),
					'priority' => 1000,
				),
				'wp_nav_menu' => array(
					'callback' => array( $this->customize_manager->nav_menus, 'filter_wp_nav_menu' ),
					'priority' => 10,
				),
			);
			foreach ( $hooks as $hook_name => $hook_args ) {
				// Note that add_action()/has_action() are just aliases for add_filter()/has_filter().
				if ( ! has_filter( $hook_name, $hook_args['callback'] ) ) {
					add_filter( $hook_name, $hook_args['callback'], $hook_args['priority'], PHP_INT_MAX );
				}
			}
		}

		if ( isset( $this->customize_manager->nav_menus ) ) {
			add_action( 'customize_register', array( $this, 'preview_early_nav_menus_in_customizer' ), 9 );
		}
	}

	/**
	 * Preview nav menu settings early so that the sections and controls for snapshot values will be added properly.
	 *
	 * This must happen at `customize_register` priority prior to 11 which is when `WP_Customize_Nav_Menus::customize_register()` runs.
	 * This is only relevant when accessing the Customizer app (customize.php), as this is where sections/controls matter.
	 *
	 * @see \WP_Customize_Nav_Menus::customize_register()
	 */
	public function preview_early_nav_menus_in_customizer() {
		if ( ! is_admin() ) {
			return;
		}
		$this->customize_manager->add_dynamic_settings( array_keys( $this->snapshot()->data() ) );
		foreach ( $this->snapshot->settings() as $setting ) {
			$is_nav_menu_setting = (
				$setting instanceof \WP_Customize_Nav_Menu_Setting
				||
				$setting instanceof \WP_Customize_Nav_Menu_Item_Setting
				||
				preg_match( '/^nav_menu_locations\[/', $setting->id )
			);
			if ( $is_nav_menu_setting ) {
				$setting->preview();
				$setting->dirty = true;
			}
		}
	}

	/**
	 * Get the current URL.
	 *
	 * @return string
	 */
	public function current_url() {
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : parse_url( home_url(), PHP_URL_HOST ); // WPCS: input var ok; sanitization ok.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // WPCS: input var ok; sanitization ok.
		return ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;
	}

	/**
	 * Add snapshot UUID the Customizer return URL.
	 *
	 * If the Customizer was loaded with a snapshot UUID, let the return URL include this snapshot.
	 */
	public function add_snapshot_uuid_to_return_url() {
		$should_add_snapshot_uuid = (
			$this->current_snapshot_uuid
			&&
			$this->is_theme_active()
			&&
			false === strpos( $this->customize_manager->get_return_url(), '/wp-admin/' )
		);
		if ( $should_add_snapshot_uuid ) {
			$args = array(
				'customize_snapshot_uuid' => $this->current_snapshot_uuid,
			);
			$return_url = add_query_arg( array_map( 'rawurlencode', $args ), $this->customize_manager->get_return_url() );
			$this->customize_manager->set_return_url( $return_url );
		}
	}

	/**
	 * Get the clean version of current URL.
	 *
	 * @return string
	 */
	public function remove_snapshot_uuid_from_current_url() {
		return remove_query_arg( array( 'customize_snapshot_uuid' ), $this->current_url() );
	}

	/**
	 * Show the theme switch error if there is one.
	 */
	public function show_theme_switch_error() {
		if ( empty( $this->snapshot ) ) {
			return;
		}
		$error = $this->get_theme_switch_error( $this->snapshot );
		if ( is_wp_error( $error ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}
	}

	/**
	 * Redirect when preview is not allowed for the current theme.
	 *
	 * @param Customize_Snapshot $snapshot Snapshot to check.
	 * @return \WP_Error|null
	 */
	public function get_theme_switch_error( Customize_Snapshot $snapshot ) {

		// Loading a snapshot into the context of a theme switch is not supported.
		if ( ! $this->is_theme_active() ) {
			return new \WP_Error( 'snapshot_theme_switch', __( 'Snapshot cannot be previewed when previewing a theme switch.', 'customize-snapshots' ) );
		}

		$snapshot_post = $snapshot->post();
		if ( ! $snapshot_post ) {
			return null;
		}

		$snapshot_theme = get_post_meta( $snapshot_post->ID, '_snapshot_theme', true );
		if ( ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme ) {
			return new \WP_Error( 'snapshot_theme_switched', __( 'Snapshot requested was made for a different theme and cannot be previewed with the current theme.', 'customize-snapshots' ) );
		}

		return null;
	}

	/**
	 * Check whether customize_publish capability is granted in customize_save.
	 */
	public function check_customize_publish_authorization() {
		if ( $this->doing_customize_save_ajax() && ! current_user_can( 'customize_publish' ) ) {
			wp_send_json_error( array(
				'error' => 'customize_publish_unauthorized',
			) );
		}
	}

	/**
	 * Encode JSON with pretty formatting.
	 *
	 * @param array $value The snapshot value.
	 * @return string
	 */
	static public function encode_json( $value ) {
		$flags = 0;
		if ( defined( '\JSON_PRETTY_PRINT' ) ) {
			$flags |= \JSON_PRETTY_PRINT;
		}
		if ( defined( '\JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= \JSON_UNESCAPED_SLASHES;
		}
		return wp_json_encode( $value, $flags );
	}

	/**
	 * Enqueue styles & scripts for the Customizer.
	 *
	 * @action customize_controls_enqueue_scripts
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function enqueue_controls_scripts() {

		// Prevent loading the Snapshot interface if the theme is not active.
		if ( ! $this->is_theme_active() ) {
			return;
		}

		wp_enqueue_style( 'customize-snapshots' );
		wp_enqueue_script( 'customize-snapshots' );

		// Script data array.
		$exports = apply_filters( 'customize_snapshots_export_data', array(
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot ? $this->snapshot->uuid() : self::generate_uuid(),
			'editLink' => $this->snapshot ? get_edit_post_link( $this->snapshot->post(), 'raw' ) : '',
			'currentUserCanPublish' => current_user_can( 'customize_publish' ),
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-snapshots' ),
				'updateButton' => __( 'Update', 'customize-snapshots' ),
				'submit' => __( 'Submit', 'customize-snapshots' ),
				'submitted' => __( 'Submitted', 'customize-snapshots' ),
				'publish' => __( 'Publish', 'customize-snapshots' ),
				'published' => __( 'Published', 'customize-snapshots' ),
				'permsMsg' => array(
					'save' => __( 'You do not have permission to publish changes, but you can create a snapshot by clicking the "Save" button.', 'customize-snapshots' ),
					'update' => __( 'You do not have permission to publish changes, but you can modify this snapshot by clicking the "Update" button.', 'customize-snapshots' ),
				),
				'errorMsg' => __( 'The snapshot could not be saved.', 'customize-snapshots' ),
				'errorTitle' => __( 'Error', 'customize-snapshots' ),
			),
			'snapshotExists' => ( $this->snapshot && $this->snapshot->saved() ),
		) );

		// Export data to JS.
		wp_scripts()->add_data(
			$this->plugin->slug,
			'data',
			sprintf( 'var _customizeSnapshots = %s;', wp_json_encode( $exports ) )
		);
	}

	/**
	 * Enqueue Customizer frontend scripts.
	 */
	public function enqueue_frontend_scripts() {
		if ( $this->snapshot ) {
			$handle = 'customize-snapshots-frontend';
			wp_enqueue_script( $handle );

			$exports = array(
				'uuid' => $this->snapshot->uuid(),
				'home_url' => wp_parse_url( home_url( '/' ) ),
			);
			wp_add_inline_script(
				$handle,
				sprintf( 'CustomizeSnapshotsFrontend.init( %s )', wp_json_encode( $exports ) ),
				'after'
			);
		}
	}

	/**
	 * Include the snapshot nonce in the Customizer nonces.
	 *
	 * @param array $nonces Nonces.
	 * @return array Nonces.
	 */
	public function filter_customize_refresh_nonces( $nonces ) {
		$nonces['snapshot'] = wp_create_nonce( self::AJAX_ACTION );
		return $nonces;
	}

	/**
	 * Get the Customize_Snapshot instance.
	 *
	 * @return Customize_Snapshot
	 */
	public function snapshot() {
		return $this->snapshot;
	}

	/**
	 * Publish the snapshot snapshots via AJAX.
	 *
	 * Fires at `customize_save_after` to update and publish the snapshot.
	 * The logic in here is the inverse of save_settings_with_publish_snapshot.
	 *
	 * @see Customize_Snapshot_Manager::save_settings_with_publish_snapshot()
	 *
	 * @return bool Whether the snapshot was saved successfully.
	 */
	public function publish_snapshot_with_customize_save_after() {
		$that = $this;

		if ( ! $this->snapshot || ! $this->doing_customize_save_ajax() ) {
			return false;
		}

		// This should never be reached due to Customize_Snapshot_Manager::check_customize_publish_authorization().
		if ( ! current_user_can( 'customize_publish' ) ) {
			return false;
		}

		$settings_data = array_map(
			function( $value ) {
				return compact( 'value' );
			},
			$this->customize_manager->unsanitized_post_values()
		);
		$result = $this->snapshot->set( $settings_data );
		if ( ! empty( $result['errors'] ) ) {
			add_filter( 'customize_save_response', function( $response ) use ( $result, $that ) {
				$response['snapshot_errors'] = $that->prepare_errors_for_response( $result['errors'] );
				return $response;
			} );
			return false;
		}

		if ( ! $this->snapshot->post() || 'publish' !== $this->snapshot->post()->post_status ) {
			$r = $this->snapshot->save( array(
				'status' => 'publish',
			) );
			if ( is_wp_error( $r ) ) {
				add_filter( 'customize_save_response', function( $response ) use ( $r, $that ) {
					$response['snapshot_errors'] = $that->prepare_errors_for_response( $r );
					return $response;
				} );
				return false;
			}
		}

		// Send the new UUID to the client for the next snapshot.
		$class = __CLASS__; // For PHP 5.3.
		add_filter( 'customize_save_response', function( $data ) use ( $class ) {
			$data['new_customize_snapshot_uuid'] = $class::generate_uuid();
			return $data;
		} );
		return true;
	}

	/**
	 * Prepare snapshot post content for publishing.
	 *
	 * Strips out publish_error from content, with it potentially being re-added
	 * in a secondary wp_update_post() call if any of the settings in the post
	 * were not able to be saved.
	 *
	 * @param array $data    An array of slashed post data.
	 * @return array Post data.
	 */
	public function prepare_snapshot_post_content_for_publish( $data ) {
		$is_publishing_snapshot = (
			isset( $data['post_type'] )
			&&
			Post_Type::SLUG === $data['post_type']
			&&
			'publish' === $data['post_status']
			&&
			(
				empty( $data['ID'] )
				||
				'publish' !== get_post_status( $data['ID'] )
			)
		);
		if ( ! $is_publishing_snapshot ) {
			return $data;
		}

		$post_content = json_decode( wp_unslash( $data['post_content'] ), true );
		if ( ! is_array( $post_content ) ) {
			return $data;
		}

		// Remove publish_error from post_content.
		foreach ( $post_content as $setting_id => &$setting_params ) {
			if ( is_array( $setting_params ) ) {
				unset( $setting_params['publish_error'] );
			}
		}

		$data['post_content'] = wp_slash( self::encode_json( $post_content ) );

		// @todo We could incorporate more of the logic from save_settings_with_publish_snapshot here to pre-emptively set the pending status.
		return $data;
	}

	/**
	 * Publish snapshot changes when snapshot post is being published.
	 *
	 * The logic in here is the inverse of to publish_snapshot_with_customize_save_after.
	 *
	 * The meat of the logic that manipulates the post_content and validates the settings
	 * needs to be done in wp_insert_post_data filter in like a
	 * filter_insert_post_data_to_validate_published_snapshot method? This would
	 * have the benefit of reducing one wp_insert_post() call.
	 *
	 * @todo Consider using wp_insert_post_data to prevent double calls to wp_insert_post().
	 * @see Customize_Snapshot_Manager::publish_snapshot_with_customize_save_after()
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return bool Whether the settings were saved.
	 */
	public function save_settings_with_publish_snapshot( $new_status, $old_status, $post ) {

		// Abort if not transitioning a snapshot post to publish from a non-publish status.
		if ( Post_Type::SLUG !== $post->post_type || 'publish' !== $new_status || $new_status === $old_status ) {
			return false;
		}

		$this->ensure_customize_manager();

		if ( $this->doing_customize_save_ajax() ) {
			// Short circuit because customize_save ajax call is changing status.
			return false;
		}

		if ( ! did_action( 'customize_register' ) ) {
			/*
			 * When running from CLI or Cron, we have to remove the action because
			 * it will get added with a default priority of 10, after themes and plugins
			 * have already done add_action( 'customize_register' ), resulting in them
			 * being called first at the priority 10. So we manually call the
			 * prerequisite function WP_Customize_Manager::register_controls() and
			 * remove it from being called when the customize_register action fires.
			 */
			remove_action( 'customize_register', array( $this->customize_manager, 'register_controls' ) );
			$this->customize_manager->register_controls();

			/*
			 * Unfortunate hack to prevent \WP_Customize_Widgets::customize_register()
			 * from calling preview() on settings. This needs to be cleaned up in core.
			 * It is important for previewing to be prevented because if an option has
			 * a filter it will short-circuit when an update is attempted since it
			 * detects that there is no change to be put into the DB.
			 * See: https://github.com/xwp/wordpress-develop/blob/e8c58c47db1421a1d0b2afa9ad4b9eb9e1e338e0/src/wp-includes/class-wp-customize-widgets.php#L208-L217
			 */
			if ( ! defined( 'DOING_AJAX' ) ) {
				define( 'DOING_AJAX', true );
			}
			$_REQUEST['action'] = 'customize_save';

			/** This action is documented in wp-includes/class-wp-customize-manager.php */
			do_action( 'customize_register', $this->customize_manager );

			// undefine( 'DOING_AJAX' )... just kidding. This is the end of the unfortunate hack and it should be fixed in Core.
			unset( $_REQUEST['action'] );
		}
		$snapshot_content = $this->post_type->get_post_content( $post );

		if ( method_exists( $this->customize_manager, 'validate_setting_values' ) ) {
			/** This action is documented in wp-includes/class-wp-customize-manager.php */
			do_action( 'customize_save_validation_before', $this->customize_manager );
		}

		$setting_ids = array_keys( $snapshot_content );
		$this->customize_manager->add_dynamic_settings( $setting_ids );

		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save', $this->customize_manager );

		/**
		 * Settings to save.
		 *
		 * @var \WP_Customize_Setting[]
		 */
		$settings = array();

		$publish_error_count = 0;
		foreach ( $snapshot_content as $setting_id => &$setting_params ) {

			// Missing value error.
			if ( ! isset( $setting_params['value'] ) || is_null( $setting_params['value'] ) ) {
				if ( ! is_array( $setting_params ) ) {
					if ( ! empty( $setting_params ) ) {
						$setting_params = array( 'value' => $setting_params );
					} else {
						$setting_params = array();
					}
				}
				$setting_params['publish_error'] = 'null_value';
				$publish_error_count += 1;
				continue;
			}

			// Unrecognized setting error.
			$this->customize_manager->set_post_value( $setting_id, $setting_params['value'] );
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! ( $setting instanceof \WP_Customize_Setting ) ) {
				$setting_params['publish_error'] = 'unrecognized_setting';
				$publish_error_count += 1;
				continue;
			}

			// Validate setting value.
			if ( method_exists( $setting, 'validate' ) && is_wp_error( $setting->validate( $setting_params['value'] ) ) ) {
				$setting_params['publish_error'] = 'invalid_value';
				$publish_error_count += 1;
				continue;
			}

			// Validate sanitized setting value.
			$sanitized_value = $setting->sanitize( $setting_params['value'] );
			if ( is_null( $sanitized_value ) || is_wp_error( $sanitized_value ) ) {
				$setting_params['publish_error'] = 'invalid_value';
				$publish_error_count += 1;
				continue;
			}

			$settings[] = $setting;
			unset( $setting_params['publish_error'] );
		}

		// Handle error scenarios.
		if ( $publish_error_count > 0 ) {
			$update_setting_args = array(
				'ID' => $post->ID,
				'post_content' => Customize_Snapshot_Manager::encode_json( $snapshot_content ),
				'post_status' => 'pending',
			);
			wp_update_post( wp_slash( $update_setting_args ) );
			update_post_meta( $post->ID, 'snapshot_error_on_publish', $publish_error_count );
			return false;
		}

		/*
		 * Change all setting capabilities temporarily to 'exist' to allow them to
		 * be saved regardless of current user, such as when WP-Cron is publishing
		 * the snapshot post if it was scheduled. It is safe to do this because
		 * a setting can only be written into a snapshot by users who have the
		 * capability, so after it has been added to a snapshot it is good to commit.
		 */
		$existing_caps = wp_list_pluck( $settings, 'capability' );
		foreach ( $settings as $setting ) {
			$setting->capability = 'exist';
		}

		// Persist the settings in the DB.
		foreach ( $settings as $setting ) {
			$setting->save();
		}

		// Restore setting capabilities.
		foreach ( $existing_caps as $setting_id => $existing_cap ) {
			$settings[ $setting_id ]->capability = $existing_cap;
		}

		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save_after', $this->customize_manager );

		// Remove any previous error on setting.
		delete_post_meta( $post->ID, 'snapshot_error_on_publish' );

		return true;
	}

	/**
	 * Update snapshots via AJAX.
	 */
	public function handle_update_snapshot_request() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		} elseif ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		} elseif ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // WPCS: input var ok.
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		} elseif ( empty( $this->current_snapshot_uuid ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_uuid' );
		} elseif ( 0 === count( $this->customize_manager->unsanitized_post_values() ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_snapshot_customized' );
		}

		if ( isset( $_POST['status'] ) ) { // WPCS: input var ok.
			$status = sanitize_key( $_POST['status'] );
		} else {
			$status = 'draft';
		}
		if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_status' );
		}

		// Prevent attempting to modify a "locked" snapshot (a published one).
		$post = $this->snapshot->post();
		if ( $post && 'publish' === $post->post_status ) {
			wp_send_json_error( array(
				'errors' => array(
					'already_published' => array(
						'message' => __( 'The snapshot has already published so it is locked.', 'customize-snapshots' ),
					),
				),
			) );
		}

		// Set the snapshot UUID.
		$post_type = get_post_type_object( Post_Type::SLUG );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID ) :
			current_user_can( 'customize' )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$data = array(
			'errors' => null,
		);
		$settings_data = array_map(
			function( $value ) {
				return compact( 'value' );
			},
			$this->customize_manager->unsanitized_post_values()
		);
		$r = $this->snapshot->set( $settings_data );
		if ( method_exists( $this->customize_manager, 'prepare_setting_validity_for_js' ) ) {
			$data['setting_validities'] = array_map(
				array( $this->customize_manager, 'prepare_setting_validity_for_js' ),
				$r['validities']
			);
		}

		if ( $r['errors'] ) {
			$data['errors'] = $this->prepare_errors_for_response( $r['errors'] );
			wp_send_json_error( $data );
		}

		$r = $this->snapshot->save( array(
			'status' => $status,
		) );

		$post = $this->snapshot->post();
		if ( $post ) {
			$data['edit_link'] = get_edit_post_link( $post, 'raw' );
		}

		if ( is_wp_error( $r ) ) {
			$data['errors'] = $this->prepare_errors_for_response( $r );
			wp_send_json_error( $data );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Prepare a WP_Error for sending to JS.
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	public function prepare_errors_for_response( \WP_Error $error ) {
		$exported_errors = array();
		foreach ( $error->errors as $code => $messages ) {
			$exported_errors[ $code ] = array(
				'message' => join( ' ', $messages ),
				'data' => $error->get_error_data( $code ),
			);
		}
		return $exported_errors;
	}

	/**
	 * Generate a snapshot uuid.
	 *
	 * @return string
	 */
	static public function generate_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}

	/**
	 * Determine whether the supplied UUID is in the right format.
	 *
	 * @param string $uuid Snapshot UUID.
	 *
	 * @return bool
	 */
	static public function is_valid_uuid( $uuid ) {
		return 0 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid );
	}

	/**
	 * Toolbar modifications for Customize Snapshot
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function customize_menu( $wp_admin_bar ) {
		$this->replace_customize_link( $wp_admin_bar );
		$this->add_post_edit_screen_link( $wp_admin_bar );
		add_action( 'wp_before_admin_bar_render', 'wp_customize_support_script' );
	}

	/**
	 * Adds a "Snapshot in Dashboard" link to the Toolbar when in Snapshot mode.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_post_edit_screen_link( $wp_admin_bar ) {
		if ( ! $this->snapshot ) {
			return;
		}
		$post = $this->snapshot->post();
		if ( ! $post ) {
			return;
		}
		$wp_admin_bar->add_node( array(
			'parent' => 'customize',
			'id' => 'snapshot-view-link',
			'title' => __( 'Inspect Snapshot', 'customize-snapshots' ),
			'href' => get_edit_post_link( $post->ID, 'raw' ),
		) );
	}

	/**
	 * Replaces the "Customize" link in the Toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function replace_customize_link( $wp_admin_bar ) {
		// Don't show for users who can't access the customizer or when in the admin.
		if ( ! current_user_can( 'customize' ) || is_admin() ) {
			return;
		}

		$args = array();
		if ( $this->current_snapshot_uuid ) {
			$args['customize_snapshot_uuid'] = $this->current_snapshot_uuid;
		}

		$args['url'] = esc_url_raw( $this->remove_snapshot_uuid_from_current_url() );
		$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );

		$wp_admin_bar->add_menu(
			array(
				'id'     => 'customize',
				'title'  => __( 'Customize', 'customize-snapshots' ),
				'href'   => $customize_url,
				'meta'   => array(
					'class' => 'hide-if-no-customize',
				),
			)
		);
	}

	/**
	 * Underscore (JS) templates for dialog windows.
	 */
	public function render_templates() {
		?>
		<script type="text/html" id="tmpl-snapshot-preview-link">
			<a href="#" target="frontend-preview" id="snapshot-preview-link" class="dashicons dashicons-welcome-view-site" title="<?php esc_attr_e( 'View on frontend', 'customize-snapshots' ) ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'View on frontend', 'customize-snapshots' ) ?></span>
			</a>
		</script>

		<script type="text/html" id="tmpl-snapshot-edit-link">
			<a href="{{ data.editLink }}" id="snapshot-edit-link" class="dashicons dashicons-calendar-alt" title="<?php esc_attr_e( 'Edit Snapshot','customize-snapshots' ); ?>"></a>
		</script>

		<script type="text/html" id="tmpl-snapshot-save">
			<button id="snapshot-save" class="button button-secondary">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-submit">
			<button id="snapshot-submit" class="button button-primary">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-dialog-error">
			<div id="snapshot-dialog-error" title="{{ data.title }}">
				<p>{{ data.message }}</p>
			</div>
		</script>
		<?php
	}
}
