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
	 * Constructor.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
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
		add_action( 'admin_bar_menu', array( $this, 'update_customize_admin_bar_link' ), 41 );

		add_action( 'customize_controls_init', array( $this, 'add_snapshot_uuid_to_return_url' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );
		add_action( 'customize_save', array( $this, 'check_customize_publish_authorization' ), 10, 0 );
		add_action( 'customize_save_after', array( $this, 'publish_snapshot_with_customize_save_after' ) );
		add_filter( 'customize_refresh_nonces', array( $this, 'filter_customize_refresh_nonces' ) );

		if ( isset( $_REQUEST['customize_snapshot_uuid'] ) ) { // WPCS: input var ok.
			$uuid = sanitize_key( wp_unslash( $_REQUEST['customize_snapshot_uuid'] ) ); // WPCS: input var ok.
			if ( static::is_valid_uuid( $uuid ) ) {
				$this->current_snapshot_uuid = $uuid;
			}
		}

		if ( $this->current_snapshot_uuid ) {
			$this->ensure_customize_manager();

			add_action( 'init', array( $this, 'show_theme_switch_error' ) );
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_update_snapshot_request' ) );

			$this->snapshot = new Customize_Snapshot( $this, $this->current_snapshot_uuid );

			if ( true === $this->should_import_and_preview_snapshot( $this->snapshot ) ) {

				$this->add_widget_setting_preview_filters();

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

		$this->customize_manager = $wp_customize;
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
		if ( $this->customize_manager->doing_ajax( 'customize_save' ) ) {
			return false;
		}

		// Prevent clobbering existing values (or previewing non-snapshotted values on frontend).
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
	 */
	public function import_snapshot_data() {

		// @todo $snapshot_values = array_merge( $this->customize_manager->unsanitized_post_values(), $this->snapshot->data() ); ?
		$snapshot_values = $this->snapshot->data();

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
		if ( ! $this->current_snapshot_uuid || ! $this->customize_manager->is_theme_active() ) {
			return;
		}
		$args = array(
			'customize_snapshot_uuid' => $this->current_snapshot_uuid,
		);
		$return_url = add_query_arg( array_map( 'rawurlencode', $args ), $this->customize_manager->get_return_url() );
		$this->customize_manager->set_return_url( $return_url );
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
		if ( ! $this->customize_manager->is_theme_active() ) {
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
		if ( ! current_user_can( 'customize_publish' ) ) {
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
	public function enqueue_scripts() {
		global $wp_customize;

		// Prevent loading the Snapshot interface if the theme is not active.
		if ( ! $wp_customize->is_theme_active() ) {
			return;
		}

		wp_enqueue_style( $this->plugin->slug );
		wp_enqueue_script( $this->plugin->slug );

		// Script data array.
		$exports = apply_filters( 'customize-snapshots-export-data', array(
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot ? $this->snapshot->uuid() : static::generate_uuid(),
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
		) );

		// Export data to JS.
		wp_scripts()->add_data(
			$this->plugin->slug,
			'data',
			sprintf( 'var _customizeSnapshots = %s;', wp_json_encode( $exports ) )
		);
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
	 *
	 * @return bool Whether the snapshot was saved successfully.
	 */
	public function publish_snapshot_with_customize_save_after() {
		$that = $this;

		if ( $this->snapshot && current_user_can( 'customize_publish' ) ) {
			$result = $this->snapshot->set( $this->customize_manager->unsanitized_post_values() );
			if ( $result['error'] ) {
				add_filter( 'customize_save_response', function( $response ) use ( $result, $that ) {
					$response['snapshot_errors'] = $that->prepare_errors_for_response( $result['error'] );
					return $response;
				} );
				return false;
			}

			$r = $this->snapshot->save( array(
				'status' => 'publish',
			) );

			if ( is_wp_error( $r ) ) {
				add_filter( 'customize_save_response', function( $response ) use ( $r, $that ) {
					$response['snapshot_errors'] = $that->prepare_errors_for_response( $r );
					return $response;
				} );
				return false;
			} else {

				// Send the new UUID to the client for the next snapshot.
				add_filter( 'customize_save_response', function( $data ) {
					$data['new_customize_snapshot_uuid'] = static::generate_uuid();
					return $data;
				} );
			}
			return true;
		}
		return false;
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

		// Set the snapshot UUID.
		$post = $this->snapshot->post();
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
		$r = $this->snapshot->set( $this->customize_manager->unsanitized_post_values() );
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
	 * Replaces the "Customize" link in the Toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function update_customize_admin_bar_link( $wp_admin_bar ) {
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
		add_action( 'wp_before_admin_bar_render', 'wp_customize_support_script' );
	}

	/**
	 * Underscore (JS) templates for dialog windows.
	 */
	public function render_templates() {
		?>
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
