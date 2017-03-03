<?php
/**
 * Customize Snapshot Manager Back Compt.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Back Compt
 *
 * Extends snapshot manager for 4.6.x and early WP version for full snapshot support.
 *
 * @package CustomizeSnapshots
 */
class Customize_Snapshot_Manager_Back_Compat extends Customize_Snapshot_Manager {

	/**
	 * Init.
	 */
	function init() {
		$this->post_type = new Post_Type_Back_Compat( $this );

		add_filter( 'customize_refresh_nonces', array( $this, 'filter_customize_refresh_nonces' ) );
		add_action( 'template_redirect', array( $this, 'show_theme_switch_error' ) );
		add_action( 'customize_save_after', array( $this, 'publish_snapshot_with_customize_save_after' ) );
		add_action( 'transition_post_status', array( $this, 'save_settings_with_publish_snapshot' ), 10, 3 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_update_snapshot_request' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );
		add_action( 'customize_save', array( $this, 'check_customize_publish_authorization' ), 10, 0 );
		$this->hooks();
		if ( $this->read_current_snapshot_uuid() ) {
			$this->load_snapshot();
		} elseif ( is_customize_preview() && isset( $_REQUEST['wp_customize_preview_ajax'] ) && 'true' === $_REQUEST['wp_customize_preview_ajax'] ) { // WPCS: input var ok; CSRF ok.
			add_action( 'wp_loaded', array( $this, 'setup_preview_ajax_requests' ), 12 );
		}
	}

	/**
	 * Get the Customize_Snapshot instance.
	 *
	 * @return Customize_Snapshot_Back_Compat
	 */
	public function snapshot() {
		return $this->snapshot;
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
		wp_enqueue_script( 'customize-snapshots-compat' );

		if ( $this->snapshot ) {
			$post = $this->snapshot->post();
			$this->override_post_date_default_data( $post );
		}

		// Script data array.
		$exports = apply_filters( 'customize_snapshots_export_data', array(
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot ? $this->snapshot->uuid() : self::generate_uuid(),
			'editLink' => isset( $post ) ? get_edit_post_link( $post, 'raw' ) : '',
			'publishDate' => isset( $post->post_date ) ? $post->post_date : '',
			'title' => isset( $post->post_title ) ? $post->post_title : '',
			'postStatus' => isset( $post->post_status ) ? $post->post_status : '',
			'currentUserCanPublish' => current_user_can( 'customize_publish' ),
			'initialServerDate' => current_time( 'mysql', false ),
			'initialServerTimestamp' => floor( microtime( true ) * 1000 ),
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-snapshots' ),
				'updateButton' => __( 'Update', 'customize-snapshots' ),
				'scheduleButton' => __( 'Schedule', 'customize-snapshots' ),
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
				'collapseSnapshotScheduling' => __( 'Collapse snapshot scheduling', 'customize-snapshots' ),
				'expandSnapshotScheduling' => __( 'Expand snapshot scheduling', 'customize-snapshots' ),
			),
			'snapshotExists' => ( $this->snapshot && $this->snapshot->saved() ),
		) );

		wp_localize_script( 'customize-snapshots-compat', '_customizeSnapshotsCompatSettings', $exports );
	}

	/**
	 * Load snapshot.
	 */
	public function load_snapshot() {
		$this->ensure_customize_manager();
		$this->snapshot = new Customize_Snapshot_Back_Compat( $this, $this->current_snapshot_uuid );

		if ( ! $this->should_import_and_preview_snapshot( $this->snapshot ) ) {
			return;
		}

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
	 * Determine whether the current snapshot can be previewed.
	 *
	 * @param Customize_Snapshot_Back_Compat $snapshot Snapshot to check.
	 * @return true|\WP_Error Returns true if previewable, or `WP_Error` if cannot.
	 */
	public function should_import_and_preview_snapshot( Customize_Snapshot_Back_Compat $snapshot ) {
		global $pagenow;

		// Ignore if in the admin, but not Admin Ajax or Customizer.
		if ( is_admin() && ! in_array( $pagenow, array( 'admin-ajax.php', 'customize.php' ), true ) ) {
			return false;
		}

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
		if ( $this->customize_manager && count( $this->customize_manager->unsanitized_post_values() ) > 0 ) {
			return false;
		}

		return true;
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
	 * Is previewing settings.
	 *
	 * Plugins and themes may currently only use `is_customize_preview()` to
	 * decide whether or not they can store a value in the object cache. For
	 * example, see `Twenty_Eleven_Ephemera_Widget::widget()`. However, when
	 * viewing a snapshot on the frontend, the `is_customize_preview()` method
	 * will return `false`. Plugins and themes that store values in the object
	 * cache must either skip doing this when `$this->previewing` is `true`,
	 * or include the `$this->current_snapshot_uuid` (`current_snapshot_uuid()`)
	 * in the cache key when it is `true`. Note that if the `customize_preview_init` action
	 * was done, this means that the settings have been previewed in the regular
	 * Customizer preview.
	 *
	 * @see Twenty_Eleven_Ephemera_Widget::widget()
	 * @see WP_Customize_Manager::is_previewing_settings()
	 * @see is_previewing_settings()
	 * @see current_snapshot_uuid()()
	 * @see WP_Customize_Manager::customize_preview_init()
	 * @see Customize_Snapshot_Manager::$previewing_settings
	 *
	 * @return bool Whether previewing settings.
	 */
	public function is_previewing_settings() {
		return $this->previewing_settings || did_action( 'customize_preview_init' );
	}

	/**
	 * Preview the snapshot settings.
	 *
	 * Note that this happens at `wp_loaded` action with priority 11 so that we
	 * can look at whether the `customize_preview_init` action was done.
	 */
	public function preview_snapshot_settings() {
		if ( $this->is_previewing_settings() ) {
			return;
		}
		$this->previewing_settings = true;

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

				/*
				 * The following is redundant because it will be done later in
				 * Customize_Snapshot_Manager::preview_snapshot_settings().
				 * Also note that the $setting instance here will likely be
				 * blown away inside of WP_Customize_Nav_Menus::customize_register(),
				 * when add_setting is called there. What matters here is that
				 * preview() is called on the setting _before_ the logic inside
				 * WP_Customize_Nav_Menus::customize_register() runs, so that
				 * the nav menu sections will be created.
				 */
				$setting->dirty = true;
			}
		}
	}

	/**
	 * Setup previewing of Ajax requests in the Customizer preview.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function setup_preview_ajax_requests() {
		global $wp_customize, $pagenow;

		/*
		 * When making admin-ajax requests from the frontend, settings won't be
		 * previewed because is_admin() and the call to preview will be
		 * short-circuited in \WP_Customize_Manager::wp_loaded().
		 */
		if ( ! did_action( 'customize_preview_init' ) ) {
			$wp_customize->customize_preview_init();
		}

		// Note that using $pagenow is easier to test vs DOING_AJAX.
		if ( ! empty( $pagenow ) && 'admin-ajax.php' === $pagenow ) {
			$this->override_request_method();
		} else {
			add_action( 'parse_request', array( $this, 'override_request_method' ), 5 );
		}

		$wp_customize->remove_preview_signature();
	}

	/**
	 * Attempt to convert the current request environment into another environment.
	 *
	 * @global \WP $wp
	 *
	 * @return bool Whether the override was applied.
	 */
	public function override_request_method() {
		global $wp;

		// Skip of X-HTTP-Method-Override request header is not present.
		if ( ! isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) ) { // WPCS: input var ok.
			return false;
		}

		// Skip if REST API request since it has built-in support for overriding the request method.
		if ( ! empty( $wp ) && ! empty( $wp->query_vars['rest_route'] ) ) {
			return false;
		}

		// Skip if the request method is not GET or POST, or the override is the same as the original.
		$original_request_method = strtoupper( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ); // WPCS: input var ok.
		$override_request_method = strtoupper( sanitize_key( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ); // WPCS: input var ok.
		if ( ! in_array( $override_request_method, array( 'GET', 'POST' ), true ) || $original_request_method === $override_request_method ) {
			return false;
		}

		// Convert a POST request into a GET request.
		if ( 'GET' === $override_request_method && 'POST' === $original_request_method ) {
			$_SERVER['REQUEST_METHOD'] = $override_request_method;
			$_GET = array_merge( $_GET, $_POST ); // WPCS: input var ok; CSRF ok.
			$_SERVER['QUERY_STRING'] = build_query( array_map( 'rawurlencode', wp_unslash( $_GET ) ) ); // WPCS: input var ok. CSRF ok.
			return true;
		}

		return false;
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
		$result = $this->snapshot->set( $settings_data, array(
			'skip_validation' => true,
		) );
		if ( ! empty( $result['errors'] ) ) {
			add_filter( 'customize_save_response', function( $response ) use ( $result, $that ) {
				$response['snapshot_errors'] = $that->prepare_errors_for_response( $result['errors'] );
				return $response;
			} );
			return false;
		}

		if ( ! $this->snapshot->post() || 'publish' !== $this->snapshot->post()->post_status ) {
			$args = array(
				'status' => 'publish',
			);

			// Ensure a scheduled Snapshot is published.
			if ( $this->snapshot->post() && 'future' === $this->snapshot->post()->post_status ) {
				$args['edit_date'] = true;
				$args['date_gmt'] = current_time( 'mysql', true );
			}

			if ( isset( $_POST['title'] ) ) { // WPCS: input var ok. CSRF ok because customize_save_after happens after nonce check.
				$title = sanitize_text_field( wp_unslash( $_POST['title'] ) ); // WPCS: Input var ok. CSRF ok because customize_save_after happens after nonce check.
				if ( ! empty( $title ) ) {
					$args['post_title'] = $title;
				}
			}

			$r = $this->snapshot->save( $args );
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
		if ( $this->get_post_type() !== $post->post_type || 'publish' !== $new_status || $new_status === $old_status ) {
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
			unset( $_REQUEST['action'] ); // WPCS: Input var ok.
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
						$setting_params = array(
							'value' => $setting_params,
						);
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
			if ( method_exists( $setting, 'validate' ) ) {
				$validity = $setting->validate( $setting_params['value'] );
				if ( is_wp_error( $validity ) ) {
					$setting_params['publish_error'] = $validity->get_error_code();
					$publish_error_count += 1;
					continue;
				}
			}

			// Validate sanitized setting value.
			$sanitized_value = $setting->sanitize( $setting_params['value'] );
			if ( is_null( $sanitized_value ) || is_wp_error( $sanitized_value ) ) {
				$setting_params['publish_error'] = is_wp_error( $sanitized_value ) ? $sanitized_value->get_error_code() : 'invalid_value';
				$publish_error_count += 1;
				continue;
			}

			$settings[] = $setting;
			unset( $setting_params['publish_error'] );
		} // End foreach().

		// Handle error scenarios.
		if ( $publish_error_count > 0 ) {
			$update_setting_args = array(
				'ID' => $post->ID,
				'post_content' => Customize_Snapshot_Manager::encode_json( $snapshot_content ),
				'post_status' => 'pending',
			);
			wp_update_post( wp_slash( $update_setting_args ) );
			update_post_meta( $post->ID, 'snapshot_error_on_publish', $publish_error_count );

			add_filter( 'redirect_post_location', function( $location ) {
				$location = add_query_arg( 'snapshot_error_on_publish', '1', $location );
				return $location;
			} );
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
			$status = sanitize_key( $_POST['status'] ); // WPCS: input var ok.
		} else {
			$status = 'draft';
		}
		if ( ! in_array( $status, array( 'draft', 'pending', 'future' ), true ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_status' );
		}
		if ( 'future' === $status && ! current_user_can( 'customize_publish' ) ) {
			status_header( 400 );
			wp_send_json_error( 'customize_not_allowed' );
		}
		$publish_date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : ''; // WPCS: input var ok.
		if ( 'future' === $status ) {
			$publish_date_obj = new \DateTime( $publish_date );
			$current_date = new \DateTime( current_time( 'mysql' ) );
			if ( empty( $publish_date ) || ! $publish_date_obj || $current_date > $publish_date_obj ) {
				status_header( 400 );
				wp_send_json_error( 'bad_schedule_time' );
			}
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

		/**
		 * Add any additional checks before saving snapshot.
		 *
		 * @param Customize_Snapshot         $snapshot         Snapshot to be saved.
		 * @param Customize_Snapshot_Manager $snapshot_manager Snapshot manager.
		 */
		do_action( 'customize_snapshot_save_before', $this->snapshot, $this );

		// Set the snapshot UUID.
		$post_type = get_post_type_object( $this->get_post_type() );
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
		$args = array(
			'status' => $status,
		);
		if ( isset( $_POST['title'] ) ) { // WPCS: input var ok.
			$title = sanitize_text_field( wp_unslash( $_POST['title'] ) ); // WPCS: input var ok.
			if ( '' !== $title ) {
				$args['post_title'] = $title;
			}
		}

		if ( isset( $publish_date_obj ) && 'future' === $status ) {
			$args['date_gmt'] = get_gmt_from_date( $publish_date_obj->format( 'Y-m-d H:i:s' ) );
		}
		$r = $this->snapshot->save( $args );

		$post = $this->snapshot->post();
		if ( $post ) {
			$data['edit_link'] = get_edit_post_link( $post, 'raw' );
			$data['snapshot_publish_date'] = $post->post_date;
			$data['title'] = $post->post_title;
		}

		if ( is_wp_error( $r ) ) {
			$data['errors'] = $this->prepare_errors_for_response( $r );
			wp_send_json_error( $data );
		}

		/** This filter is documented in wp-includes/class-wp-customize-manager.php */
		$data = apply_filters( 'customize_save_response', $data, $this->customize_manager );
		wp_send_json_success( $data );
	}

	/**
	 * Set up Customizer preview.
	 */
	public function customize_preview_init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_preview_scripts' ) );
	}

	/**
	 * Enqueue Customizer preview scripts.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function enqueue_preview_scripts() {
		global $wp_customize;

		$handle = 'customize-snapshots-preview';
		wp_enqueue_script( $handle );
		wp_enqueue_style( $handle );

		$exports = array(
			'home_url' => wp_parse_url( home_url( '/' ) ),
			'rest_api_url' => wp_parse_url( rest_url( '/' ) ),
			'admin_ajax_url' => wp_parse_url( admin_url( 'admin-ajax.php' ) ),
			'initial_dirty_settings' => array_keys( $wp_customize->unsanitized_post_values() ),
		);
		wp_add_inline_script(
			$handle,
			sprintf( 'CustomizeSnapshotsPreview.init( %s )', wp_json_encode( $exports ) ),
			'after'
		);
	}

	/**
	 * Enqueue Customizer frontend scripts.
	 */
	public function enqueue_frontend_scripts() {
		if ( ! $this->snapshot || is_customize_preview() ) {
			return;
		}
		$handle = 'customize-snapshots-frontend';
		wp_enqueue_script( $handle );

		$exports = array(
			'uuid' => $this->snapshot ? $this->snapshot->uuid() : null,
			'home_url' => wp_parse_url( home_url( '/' ) ),
			'l10n' => array(
				'restoreSessionPrompt' => __( 'It seems you may have inadvertently navigated away from previewing a customized state. Would you like to restore the snapshot context?', 'customize-snapshots' ),
			),
		);
		wp_add_inline_script(
			$handle,
			sprintf( 'CustomizeSnapshotsFrontend.init( %s )', wp_json_encode( $exports ) ),
			'after'
		);
	}

	/**
	 * Underscore (JS) templates.
	 */
	public function render_templates() {
		$this->add_edit_box_template();
		?>
		<script type="text/html" id="tmpl-snapshot-save">
			<button id="snapshot-save" class="button button-secondary">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-preview-link">
			<a href="#" target="frontend-preview" id="snapshot-preview-link" class="dashicons dashicons-welcome-view-site" title="<?php esc_attr_e( 'View on frontend', 'customize-snapshots' ) ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'View on frontend', 'customize-snapshots' ) ?></span>
			</a>
		</script>

		<script type="text/html" id="tmpl-snapshot-expand-button">
			<a href="javascript:void(0)" id="snapshot-expand-button" role="button" aria-controls="snapshot-schedule" aria-pressed="false" class="dashicons dashicons-edit"></a>
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
