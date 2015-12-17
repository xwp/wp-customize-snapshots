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
	 * Post type.
	 * @type string
	 */
	const POST_TYPE = 'customize_snapshot';

	/**
	 * Action nonce.
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
	 * Unsanitized JSON-decoded value of $_POST['snapshot_customized'] if present in request.
	 *
	 * @access protected
	 * @var array|null
	 */
	protected $unsanitized_snapshot_post_data;

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
	 * Unique identifier set in 'wp_ajax_customize_save'.
	 *
	 * @access protected
	 * @var string
	 */
	protected $snapshot_uuid;

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		// Bail if our conditions are not met.
		if ( ! ( ( isset( $_REQUEST['wp_customize'] ) && 'on' == $_REQUEST['wp_customize'] )
			|| ( is_admin() && 'customize.php' == basename( $_SERVER['PHP_SELF'] ) )
			|| ( isset( $_REQUEST['customize_snapshot_uuid'] ) )
		) ) {
			return;
		}

		$this->plugin = $plugin;

		if ( ! did_action( 'setup_theme' ) ) {
			// Note that Customize_Snapshot::populate_customized_post_var() happens next at priority 1.
			add_action( 'setup_theme', array( $this, 'capture_unsanitized_snapshot_post_data' ), 0 );
		} else {
			$this->capture_unsanitized_snapshot_post_data();
		}

		$uuid = isset( $_REQUEST['customize_snapshot_uuid'] ) ? $_REQUEST['customize_snapshot_uuid'] : null;
		$scope = isset( $_REQUEST['scope'] ) ? $_REQUEST['scope'] : 'dirty';
		$apply_dirty = ( 'dirty' === $scope );

		// Bootstrap the Customizer.
		if ( empty( $GLOBALS['wp_customize'] ) || ! ( $GLOBALS['wp_customize'] instanceof \WP_Customize_Manager ) && $uuid ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		}
		$this->customize_manager = $GLOBALS['wp_customize'];

		$this->snapshot = new Customize_Snapshot( $this, $uuid, $apply_dirty );

		add_action( 'customize_controls_init', array( $this, 'set_return_url' ) );
		add_action( 'init', array( $this, 'maybe_force_redirect' ), 0 );
		add_action( 'init', array( $this, 'create_post_type' ), 0 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_customize_save', array( $this, 'set_snapshot_uuid' ), 0 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'update_snapshot' ) );
		add_action( 'customize_save_after', array( $this, 'save_snapshot' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_menu' ), 41 );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );

		// Preview a Snapshot.
		add_action( 'after_setup_theme', array( $this, 'set_post_values' ), 1 );
		add_action( 'wp_loaded', array( $this, 'preview' ) );
	}

	/**
	 * Set the Customizer return URL.
	 */
	public function set_return_url() {
		global $wp_version;
		if (
			version_compare( $wp_version, '4.4-beta', '>=' )
			&& $this->snapshot->is_preview()
			&& $this->snapshot->uuid()
		) {
			$args = array(
				'customize_snapshot_uuid' => $this->snapshot->uuid(),
				'scope' => $this->snapshot->apply_dirty ? 'dirty' : 'full',
			);
			$return_url = add_query_arg( $args, $this->customize_manager->get_return_url() );
			$this->customize_manager->set_return_url( $return_url );
		}
	}

	/**
	 * Get the current URL.
	 *
	 * @return string
	 */
	public function current_url() {
		return ( is_ssl() ? 'https://' : 'http://' ) . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Get the clean version of current URL.
	 *
	 * @return string
	 */
	public function clean_current_url() {
		return esc_url( remove_query_arg( array( 'customize_snapshot_uuid', 'scope' ), $this->current_url() ) );
	}

	/**
	 * Redirect when preview is not allowed for the current theme.
	 */
	public function maybe_force_redirect() {
		if ( false === $this->snapshot->is_preview() && isset( $_GET['customize_snapshot_uuid'] ) ) {
			wp_safe_redirect( $this->clean_current_url() );
			exit;
		}
	}

	/**
	 * Decode and store any $_POST['snapshot_customized'] data.
	 *
	 * The value is used by Customize_Snapshot_Manager::save().
	 */
	public function capture_unsanitized_snapshot_post_data() {
		if ( current_user_can( 'customize' ) && isset( $_POST['snapshot_customized'] ) ) {
			$this->unsanitized_snapshot_post_data = json_decode( wp_unslash( $_POST['snapshot_customized'] ), true );
		}
	}

	/**
	 * Create the custom post type.
	 *
	 * @access public
	 */
	public function create_post_type() {
		$args = array(
			'labels' => array(
				'name' => __( 'Customize Snapshots', 'customize-snapshots' ),
				'singular_name' => __( 'Customize Snapshot', 'customize-snapshots' ),
			),
			'public' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'rewrite' => false,
			'delete_with_user' => false,
			'supports' => array( 'title', 'author', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Enqueue styles & scripts for the Customizer.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	public function enqueue_scripts() {
		// Enqueue styles.
		wp_enqueue_style( $this->plugin->slug );

		// Enqueue scripts.
		wp_enqueue_script( $this->plugin->slug );

		// Set the snapshot theme.
		$snapshot_theme = null;
		if ( isset( $this->snapshot->post()->ID ) ) {
			$snapshot_theme = get_post_meta( $this->snapshot->post()->ID, '_snapshot_theme', true );
		}

		// Script data array.
		$exports = array(
			'nonce' => wp_create_nonce( self::AJAX_ACTION ),
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot->uuid(),
			'is_preview' => $this->snapshot->is_preview(),
			'current_user_can_publish' => current_user_can( 'customize_publish' ),
			'snapshot_theme' => $snapshot_theme,
			'scope' => ( isset( $_GET['scope'] ) ? $_GET['scope'] : 'dirty' ),
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-snapshots' ),
				'saveDraftButton' => __( 'Save Draft', 'customize-snapshots' ),
				'cancelButton' => __( 'Cancel', 'customize-snapshots' ),
				'publish' => __( 'Publish', 'customize-snapshots' ),
				'published' => __( 'Published', 'customize-snapshots' ),
				'saveMsg' => ( $this->snapshot->is_preview() ?
					__( 'Clicking "Save" will update the current snapshot.', 'customize-snapshots' ) :
					__( 'Clicking "Save" will create a new snapshot.', 'customize-snapshots' )
				),
				'permsMsg' => __( 'You do not have permission to publish changes, but you can create a snapshot by clicking the "Save Draft" button.', 'customize-snapshots' ),
				'errorMsg' => __( 'The snapshot could not be saved.', 'customize-snapshots' ),
				'previewTitle' => __( 'Preview Permalink', 'customize-snapshots' ),
				'formTitle' => ( $this->snapshot->is_preview() ?
					__( 'Update', 'customize-snapshots' ) :
					__( 'Save', 'customize-snapshots' )
				),
				'scopeTitle' => __( 'Preview Scope', 'customize-snapshots' ),
				'dirtyLabel' => __( 'diff - Previews the dirty settings', 'customize-snapshots' ),
				'fullLabel' => __( 'full - Previews all the settings', 'customize-snapshots' ),
			),
		);

		// Export data to JS.
		wp_scripts()->add_data(
			$this->plugin->slug,
			'data',
			sprintf( 'var _customizeSnapshots = %s;', wp_json_encode( $exports ) )
		);
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
	 * Save a snapshot.
	 *
	 * @param string $status  The post status.
	 * @return null|\WP_Error Null if success, WP_Error on failure.
	 */
	public function save( $status = 'draft' ) {
		foreach ( $this->unsanitized_snapshot_post_data as $setting_id => $setting_info ) {
			$this->customize_manager->set_post_value( $setting_id, $setting_info['value'] );
		}

		$new_setting_ids = array_diff( array_keys( $this->unsanitized_snapshot_post_data ), array_keys( $this->customize_manager->settings() ) );
		$added_settings = $this->customize_manager->add_dynamic_settings( $new_setting_ids );
		if ( ! empty( $new_setting_ids ) && 0 === count( $added_settings ) ) {
			$this->plugin->trigger_warning( 'Unable to snapshot settings for: ' . join( ', ', $new_setting_ids ) );
		}

		foreach ( $this->customize_manager->settings() as $setting ) {
			if ( $this->can_preview( $setting, $this->unsanitized_snapshot_post_data ) ) {
				$post_data = $this->unsanitized_snapshot_post_data[ $setting->id ];
				$this->snapshot->set( $setting, $post_data['value'], $post_data['dirty'] );
			}
		}

		return $this->snapshot->save( $status );
	}

	/**
	 * Set the snapshots UUID during Ajax request.
	 *
	 * Fires at `wp_ajax_customize_save`.
	 */
	public function set_snapshot_uuid() {
		if ( ! current_user_can( 'customize_publish' ) ) {
			status_header( 403 );
			wp_send_json_error( 'publish_not_allowed' );
		}

		$uuid = ! empty( $_POST['snapshot_uuid'] ) ? $_POST['snapshot_uuid'] : null;
		if ( current_user_can( 'customize' ) && $uuid && $this->snapshot->is_valid_uuid( $uuid ) ) {
			$this->snapshot_uuid = $uuid;
		}
	}

	/**
	 * Save snapshots via AJAX.
	 *
	 * Fires at `customize_save_after` to update and publish the snapshot.
	 */
	public function save_snapshot() {
		if ( $this->snapshot_uuid ) {
			if ( empty( $this->unsanitized_snapshot_post_data ) ) {
				add_filter( 'customize_save_response', function( $response ) {
					$response['missing_snapshot_customized'] = __( 'The Snapshots customized data was missing from the request.', 'customize-snapshots' );
					return $response;
				} );
				return false;
			}

			$this->snapshot->set_uuid( $this->snapshot_uuid );
			$r = $this->save( 'publish' );
			if ( is_wp_error( $r ) ) {
				add_filter( 'customize_save_response', function( $response ) use ( $r ) {
					$response[ $r->get_error_code() ] = $r->get_error_message();
					return $response;
				} );
				return false;
			}
		}
	}

	/**
	 * Update snapshots via AJAX.
	 */
	public function update_snapshot() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		} else if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		} else if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		} else if ( empty( $_POST['customize_snapshot_uuid'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_uuid' );
		} else if ( empty( $_POST['scope'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_scope' );
		} else if ( empty( $this->unsanitized_snapshot_post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_snapshot_customized' );
		} else if ( empty( $_POST['preview'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_preview' );
		}

		// Set the snapshot UUID.
		$this->snapshot->set_uuid( $_POST['customize_snapshot_uuid'] );
		$uuid = $this->snapshot->uuid();
		$next_uuid = $uuid;

		$post = $this->snapshot->post();
		$post_type = get_post_type_object( self::POST_TYPE );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID ) :
			current_user_can( $post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$this->snapshot->apply_dirty = ( 'dirty' === $_POST['scope'] );
		$r = $this->save( 'draft' );
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		// Set a new UUID every time Share is clicked, when the user is not previewing a snapshot.
		if ( 'on' !== $_POST['preview'] ) {
			$next_uuid = $this->snapshot->reset_uuid();
		}

		$response = array(
			'customize_snapshot_uuid' => $uuid, // Current UUID.
			'customize_snapshot_next_uuid' => $next_uuid, // Next UUID if not previewing, else current UUID.
			'customize_snapshot_settings' => $this->snapshot->values(), // Send back sanitized settings values.
		);

		wp_send_json_success( $response );
	}

	/**
	 * Replaces the "Customize" link in the Toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function customize_menu( $wp_admin_bar ) {
		// Don't show for users who can't access the customizer or when in the admin.
		if ( ! current_user_can( 'customize' ) || is_admin() ) {
			return;
		}

		$current_url = remove_query_arg( array( 'customize_snapshot_uuid', 'scope' ), $this->current_url() );

		$args = array();
		$uuid = isset( $_GET['customize_snapshot_uuid'] ) ? wp_unslash( $_GET['customize_snapshot_uuid'] ) : null;
		$scope = isset( $_GET['scope'] ) ? wp_unslash( $_GET['scope'] ) : 'dirty';

		if ( $uuid && $this->snapshot->is_valid_uuid( $uuid ) ) {
			$args['customize_snapshot_uuid'] = $uuid;
			$args['scope'] = ( 'dirty' !== $scope ? 'full' : 'dirty' );
		}

		$args['url'] = urlencode( $current_url );
		$customize_url = add_query_arg( $args, wp_customize_url() );

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
			<button id="snapshot-save" class="button">
				{{ data.buttonText }}
			</button>
		</script>

		<script type="text/html" id="tmpl-snapshot-dialog-link">
			<div id="snapshot-dialog-link" title="{{ data.title }}">
				<a href="{{ data.url }}" target="_blank">{{ data.url }}</a>
			</div>
		</script>

		<script type="text/html" id="tmpl-snapshot-dialog-error">
			<div id="snapshot-dialog-error" title="{{ data.title }}">
				<p>{{ data.message }}</p>
			</div>
		</script>

		<script type="text/html" id="tmpl-snapshot-dialog-form">
			<div id="snapshot-dialog-form" title="{{ data.title }}">
				<form>
					<fieldset>
						<p>{{ data.message }}</p>
						<# if ( data.is_preview ) { #>
							<input type="hidden" value="{{ data.scope }}" name="scope">
						<# } else { #>
							<h4>{{ data.scopeTitle }}</h4>
							<label for="type-0">
								<input id="type-0" type="radio" checked="checked" value="dirty" name="scope">{{ data.dirtyLabel }}
							</label>
							<br>
							<label for="type-1">
								<input id="type-1" type="radio" value="full" name="scope">{{ data.fullLabel }}
							</label>
							<br>
						<# } #>
					</fieldset>
				</form>
			</div>
		</script>
		<?php
	}

	/**
	 * Check if the setting can be previewed.
	 *
	 * @param \WP_Customize_Setting $setting A WP_Customize_Setting derived object.
	 * @param array                 $values  All settings' values in the snapshot.
	 * @return bool
	 */
	public function can_preview( $setting, $values ) {
		if ( ! ( $setting instanceof \WP_Customize_Setting ) ) {
			return false;
		}
		if ( ! $setting->check_capabilities() && is_admin() ) {
			return false;
		}
		if ( ! array_key_exists( $setting->id, $values ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Set the snapshot settings post value.
	 */
	public function set_post_values() {

		if ( true === $this->snapshot->is_preview() ) {
			$values = $this->snapshot->values();

			// Register dynamic settings for settings in the snapshot.
			$this->customize_manager->add_dynamic_settings( array_keys( $values ) );

			foreach ( $this->snapshot->settings() as $setting ) {
				if ( $this->can_preview( $setting, $values ) ) {
					$this->customize_manager->set_post_value( $setting->id, $values[ $setting->id ] );
				}
			}
		}
	}

	/**
	 * Preview the snapshot settings.
	 */
	public function preview() {
		if ( true === $this->snapshot->is_preview() ) {

			// Block the robots.
			add_action( 'wp_head', 'wp_no_robots' );

			/*
			 * Note that we need to preview the settings outside the Customizer preview
			 * and in the Customizer pane itself so we can load a previous snapshot
			 * into the Customizer. We have to prevent the previews from being added
			 * in the case of a customize_save action because then update_option()
			 * may short-circuit because it will detect that there are no changes to
			 * make.
			 */
			if ( ! $this->customize_manager->doing_ajax( 'customize_save' ) ) {
				$values = $this->snapshot->values();

				foreach ( $this->snapshot->settings() as $setting ) {
					if ( $this->can_preview( $setting, $values ) ) {
						$setting->preview();
						$setting->dirty = true;
					}
				}
			}
		}
	}
}
