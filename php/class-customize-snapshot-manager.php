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
	 * @var Post_Type|Post_Type_Back_Compat
	 */
	public $post_type;

	/**
	 * Customize_Snapshot instance.
	 *
	 * @todo Rename `Customize_Snapshot` to just `Snapshot`.
	 *
	 * @var Customize_Snapshot|Customize_Snapshot_Back_Compat
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
	 * Whether the snapshot settings are being previewed.
	 *
	 * @var bool
	 */
	protected $previewing_settings = false;

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
	 * Init common hooks.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	function hooks() {
		add_action( 'init', array( $this->post_type, 'init' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_controls_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'customize_controls_init', array( $this, 'add_snapshot_uuid_to_return_url' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_menu' ), 41 );
		add_action( 'admin_bar_menu', array( $this, 'remove_all_non_snapshot_admin_bar_links' ), 100000 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'print_admin_bar_styles' ) );
		add_filter( 'removable_query_args', array( $this, 'filter_removable_query_args' ) );
		add_action( 'save_post_' . $this->get_post_type(), array( $this, 'create_initial_changeset_revision' ) );
		add_action( 'save_post_' . $this->get_post_type(), array( $this, 'save_customizer_state_query_vars' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'prepare_snapshot_post_content_for_publish' ) );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 3 );
	}

	/**
	 * Init.
	 */
	function init() {
		$this->post_type = new Post_Type( $this );
		$this->hooks();
		add_filter( 'customize_save_response', array( $this, 'add_snapshot_var_to_customize_save' ), 10, 2 );
		if ( $this->read_current_snapshot_uuid() ) {
			$this->load_snapshot();
		}
	}

	/**
	 * Load Snapshot.
	 */
	public function load_snapshot() {
		$this->ensure_customize_manager();
		$this->snapshot = new Customize_Snapshot( $this );
	}

	/**
	 * Add extra changeset variable to publish date.
	 *
	 * @param array                 $response          Ajax response.
	 * @param \WP_Customize_Manager $customize_manager customize manager object.
	 *
	 * @return array Response.
	 */
	public function add_snapshot_var_to_customize_save( $response, $customize_manager ) {
		$changeset_post = get_post( $customize_manager->changeset_post_id() );
		$response['edit_link'] = $this->snapshot->get_edit_link( $changeset_post->ID );
		$response['publish_date'] = $changeset_post->post_date;
		$response['title'] = $changeset_post->post_title;
		return $response;
	}

	/**
	 * Read the current snapshot UUID from the request.
	 *
	 * @returns bool Whether a valid snapshot was read.
	 */
	public function read_current_snapshot_uuid() {
		$customize_arg = $this->get_customize_uuid_param();
		$frontend_arg = $this->get_front_uuid_param();
		$uuid = null;
		if ( isset( $_REQUEST[ $customize_arg ] ) ) { // WPCS: input var ok. CSRF ok.
			$uuid = sanitize_key( wp_unslash( $_REQUEST[ $customize_arg ] ) ); // WPCS: input var ok. CSRF ok.
		} elseif ( isset( $_REQUEST[ $frontend_arg ] ) ) { // WPCS: input var ok. CSRF ok.
			$uuid = sanitize_key( wp_unslash( $_REQUEST[ $frontend_arg ] ) ); // WPCS: input var ok. CSRF ok.
		}

		if ( $uuid && static::is_valid_uuid( $uuid ) ) {
			$this->current_snapshot_uuid = $uuid;
			return true;
		}
		$this->current_snapshot_uuid = null;
		return false;
	}

	/**
	 * Return true if it's a customize_save Ajax request.
	 *
	 * @return bool True if it's an Ajax request, false otherwise.
	 */
	public function doing_customize_save_ajax() {
		return isset( $_REQUEST['action'] ) && sanitize_key( wp_unslash( $_REQUEST['action'] ) ) === 'customize_save'; // WPCS: input var ok. CSRF ok.
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
			if ( null !== $this->current_snapshot_uuid ) {
				$wp_customize = new \WP_Customize_Manager( array(
					'changeset_uuid' => $this->current_snapshot_uuid,
				) ); // WPCS: override ok.
			} else {
				$wp_customize = new \WP_Customize_Manager(); // WPCS: override ok.
			}
		}

		$this->customize_manager = $wp_customize;
	}

	/**
	 * Is previewing another theme.
	 *
	 * @return bool Whether theme is active.
	 *
	 * @todo move to back compat?
	 */
	public function is_theme_active() {
		if ( empty( $this->customize_manager ) ) {
			return true;
		}
		return $this->customize_manager->get_stylesheet() === $this->original_stylesheet;
	}

	/**
	 * Add snapshot UUID the Customizer return URL.
	 *
	 * If the Customizer was loaded with a snapshot UUID, let the return URL include this snapshot.
	 *
	 * @todo move to back compat?
	 */
	public function add_snapshot_uuid_to_return_url() {
		$should_add_snapshot_uuid = (
			isset( $_GET[ $this->get_front_uuid_param() ] )
			&&
			$this->current_snapshot_uuid
			&&
			$this->is_theme_active()
			&&
			false === strpos( $this->customize_manager->get_return_url(), '/wp-admin/' )
		);
		if ( $should_add_snapshot_uuid ) {
			$args_name = $this->get_front_uuid_param();
			$args = array(
				$args_name => $this->current_snapshot_uuid,
			);
			$return_url = add_query_arg( array_map( 'rawurlencode', $args ), $this->customize_manager->get_return_url() );
			$this->customize_manager->set_return_url( $return_url );
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

		$flags |= \JSON_PRETTY_PRINT;

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

		$post = null;

		if ( $this->snapshot ) {
			$post_id = $this->customize_manager->changeset_post_id();
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$this->override_post_date_default_data( $post );
				$edit_link = $this->snapshot->get_edit_link( $post );
			}
		}

		// Script data array.
		$exports = apply_filters( 'customize_snapshots_export_data', array(
			'editLink' => isset( $edit_link ) ? $edit_link : '',
			'publishDate' => isset( $post->post_date ) ? $post->post_date : '',
			'title' => isset( $post->post_title ) ? $post->post_title : '',
			'postStatus' => isset( $post->post_status ) ? $post->post_status : '',
			'currentUserCanPublish' => current_user_can( 'customize_publish' ),
			'initialServerDate' => current_time( 'mysql', false ),
			'initialServerTimestamp' => floor( microtime( true ) * 1000 ),
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-snapshots' ),
				'updateButton' => __( 'Update', 'customize-snapshots' ),
				'submit' => __( 'Submit', 'customize-snapshots' ),
				'submitted' => __( 'Submitted', 'customize-snapshots' ),
				'permsMsg' => array(
					'save' => __( 'You do not have permission to publish changes, but you can create a snapshot by clicking the "Save" button.', 'customize-snapshots' ),
					'update' => __( 'You do not have permission to publish changes, but you can modify this snapshot by clicking the "Update" button.', 'customize-snapshots' ),
				),
				'aysMsg' => __( 'Changes that you made may not be saved.', 'customize-snapshots' ),
				'errorMsg' => __( 'The snapshot could not be saved.', 'customize-snapshots' ),
				'errorTitle' => __( 'Error', 'customize-snapshots' ),
				'collapseSnapshotScheduling' => __( 'Collapse snapshot scheduling', 'customize-snapshots' ),
				'expandSnapshotScheduling' => __( 'Expand snapshot scheduling', 'customize-snapshots' ),
			),
		) );

		wp_localize_script( 'customize-snapshots', '_customizeSnapshotsSettings', $exports );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * These files control the behavior and styling of links to remove settings.
	 * Published snapshots can't be edited, so these files are not needed on those pages.
	 *
	 * @param String $hook Current page in admin.
	 */
	public function enqueue_admin_scripts( $hook ) {
		global $post;
		$handle = 'customize-snapshots-admin';
		if ( ( 'post.php' === $hook ) && isset( $post->post_type ) && ( $this->get_post_type() === $post->post_type ) && ( 'publish' !== $post->post_status ) ) {
			wp_enqueue_script( $handle );
			wp_enqueue_style( $handle );
			$exports = array(
				'deleteInputName' => $this->get_post_type() . '_remove_settings[]',
			);
			wp_add_inline_script(
				$handle,
				sprintf( 'CustomizeSnapshotsAdmin.init( %s )', wp_json_encode( $exports ) ),
				'after'
			);
		}
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
	 * Create initial changeset revision.
	 *
	 * This should be removed once #30854 is resolved.
	 *
	 * @link https://core.trac.wordpress.org/ticket/30854
	 */
	public function create_initial_changeset_revision( $post_id ) {
		if ( 0 === count( wp_get_post_revisions( $post_id ) ) ) {
			wp_save_post_revision( $post_id );
		}
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
			$this->get_post_type() === $data['post_type']
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
	 * Add snapshot_error_on_publish to removable_query_args.
	 *
	 * @param array $query_args Query args.
	 * @return array Removable query args.
	 */
	public function filter_removable_query_args( $query_args ) {
		$query_args[] = 'snapshot_error_on_publish';
		return $query_args;
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
		add_action( 'wp_before_admin_bar_render', 'wp_customize_support_script' );
		$this->replace_customize_link( $wp_admin_bar );
		$this->add_resume_snapshot_link( $wp_admin_bar );
		$this->add_post_edit_screen_link( $wp_admin_bar );
		$this->add_snapshot_exit_link( $wp_admin_bar );
	}

	/**
	 * Print admin bar styles.
	 */
	public function print_admin_bar_styles() {
		// @codingStandardsIgnoreStart A WordPress-VIP sniff has false positive on admin bar being hidden.
		?>
		<style type="text/css">
			#wpadminbar #wp-admin-bar-resume-customize-snapshot {
				display: none;
			}
			#wpadminbar #wp-admin-bar-resume-customize-snapshot > .ab-item:before {
				content: "\f531";
				top: 2px;
			}
			#wpadminbar #wp-admin-bar-inspect-customize-snapshot > .ab-item:before {
				content: "\f179";
				top: 2px;
			}
			#wpadminbar #wp-admin-bar-exit-customize-snapshot > .ab-item:before {
				content: "\f158";
				top: 2px;
			}
		</style>
		<?php
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Replaces the "Customize" link in the Toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function replace_customize_link( $wp_admin_bar ) {
		if ( empty( $this->snapshot ) ) {
			return;
		}

		$customize_node = $wp_admin_bar->get_node( 'customize' );
		if ( empty( $customize_node ) ) {
			return;
		}

		// Remove customize_snapshot_uuid query param from url param to be previewed in Customizer.
		$preview_url_query_params = array();
		$preview_url_parsed = wp_parse_url( $customize_node->href );
		parse_str( $preview_url_parsed['query'], $preview_url_query_params );
		if ( ! empty( $preview_url_query_params['url'] ) ) {
			$preview_url_query_params['url'] = rawurlencode( remove_query_arg( array( $this->get_front_uuid_param() ), $preview_url_query_params['url'] ) );
			$customize_node->href = preg_replace(
				'/(?<=\?).*?(?=#|$)/',
				build_query( $preview_url_query_params ),
				$customize_node->href
			);
		}

		$args = array(
			$this->get_customize_uuid_param() => $this->current_snapshot_uuid,
		);

		$post = $this->snapshot->post();

		if ( $post ) {
			$customizer_state_query_vars = $this->post_type->get_customizer_state_query_vars( $post->ID );
			unset( $customizer_state_query_vars['url'] );
			$args = array_merge( $args, $customizer_state_query_vars );
		}

		// Add customize_snapshot_uuid and preview url params to customize.php itself.
		$customize_node->href = add_query_arg( $args, $customize_node->href );

		$customize_node->meta['class'] .= ' ab-customize-snapshots-item';
		$wp_admin_bar->add_menu( (array) $customize_node );
	}

	/**
	 * Adds a link to resume snapshot previewing.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_resume_snapshot_link( $wp_admin_bar ) {
		$wp_admin_bar->add_menu( array(
			'id' => 'resume-customize-snapshot',
			'title' => __( 'Resume Snapshot Preview', 'customize-snapshots' ),
			'href' => '#',
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
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
		$wp_admin_bar->add_menu( array(
			'id' => 'inspect-customize-snapshot',
			'title' => __( 'Inspect Snapshot', 'customize-snapshots' ),
			'href' => $this->snapshot->get_edit_link( $post ),
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
	}

	/**
	 * Adds an "Exit Snapshot" link to the Toolbar when in Snapshot mode.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_snapshot_exit_link( $wp_admin_bar ) {
		if ( ! $this->snapshot ) {
			return;
		}
		$wp_admin_bar->add_menu( array(
			'id' => 'exit-customize-snapshot',
			'title' => __( 'Exit Snapshot Preview', 'customize-snapshots' ),
			'href' => remove_query_arg( $this->get_front_uuid_param() ),
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
	}

	/**
	 * Remove all admin bar nodes that have links and which aren't for snapshots.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function remove_all_non_snapshot_admin_bar_links( $wp_admin_bar ) {
		if ( empty( $this->snapshot ) ) {
			return;
		}
		$snapshot_admin_bar_node_ids = array( 'customize', 'exit-customize-snapshot', 'inspect-customize-snapshot' );
		foreach ( $wp_admin_bar->get_nodes() as $node ) {
			if ( in_array( $node->id, $snapshot_admin_bar_node_ids, true ) || '#' === substr( $node->href, 0, 1 ) ) {
				continue;
			}

			$parsed_link_url = wp_parse_url( $node->href );
			$parsed_home_url = wp_parse_url( home_url( '/' ) );
			$is_external_link = (
				isset( $parsed_link_url['host'] ) && $parsed_link_url['host'] !== $parsed_home_url['host']
				||
				isset( $parsed_link_url['path'] ) && 0 !== strpos( $parsed_link_url['path'], $parsed_home_url['path'] )
				||
				( ! isset( $parsed_link_url['query'] ) || ! preg_match( '#(^|&)customize_snapshot_uuid=#', $parsed_link_url['query'] ) )
			);
			if ( $is_external_link ) {
				$wp_admin_bar->remove_node( $node->id );
			}
		}
	}

	/**
	 * Underscore (JS) templates for dialog windows.
	 */
	public function render_templates() {
		$this->add_edit_box_template();
		?>
		<script type="text/html" id="tmpl-snapshot-preview-link">
			<a href="#" target="frontend-preview" id="snapshot-preview-link" class="dashicons dashicons-welcome-view-site" title="<?php esc_attr_e( 'View on frontend', 'customize-snapshots' ) ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'View on frontend', 'customize-snapshots' ) ?></span>
			</a>
		</script>

		<script type="text/html" id="tmpl-snapshot-expand-button">
			<a href="javascript:void(0)" id="snapshot-expand-button" role="button" aria-controls="snapshot-schedule" aria-pressed="false" class="dashicons dashicons-edit"></a>
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

		<script type="text/html" id="tmpl-snapshot-status-button">
			<?php
				$data = array(
					'choices' => array(
						'publish' => array(
							'option_text' => __( 'Publish' , 'customize-snapshots' ),
							'alt_text' => __( 'Published' , 'customize-snapshots' ),
						),
						'draft' => array(
							'option_text' => __( 'Save Draft' , 'customize-snapshots' ),
							'alt_text' => __( 'Draft' , 'customize-snapshots' ),
						),
						'future' => array(
							'option_text' => __( 'Schedule' , 'customize-snapshots' ),
							'alt_text' => __( 'Scheduled' , 'customize-snapshots' ),
						),
						'pending' => array(
							'option_text' => __( 'Save Pending' , 'customize-snapshots' ),
							'alt_text' => __( 'Pending' , 'customize-snapshots' ),
						),
					),
					'selected' => 'publish',
					'confirm_publish_text' => __( 'Confirm Publish', 'customize-snapshots' ),
				);
			?>

			<# _.defaults( data, <?php echo wp_json_encode( $data ) ?> ); #>

			<div id="snapshot-status-button-wrapper">
				<label class="screen-reader-text" for="snapshot-status-button"><?php esc_attr_e( 'Snapshot Status', 'customize-snapshots' ); ?></label>
				<select id="snapshot-status-button">
					<# _.each( data.choices, function( buttonText, status ) { #>
							<option value="{{ status }}" data-alt-text="{{ buttonText.alt_text }}"
								<# if ( data.selected == status ) { #>
									selected="selected"
								<# } #>
								<# if ( 'publish' == status ) { #>
									data-confirm-text="{{ data.confirm_publish_text }}"
									data-publish-text="{{ data.choices.publish.option_text }}"
								<# } #>
								>{{ buttonText.option_text }}</option>
					<# } ); #>
				</select>
				<button class="snapshot-status-button-overlay button button-primary" data-button-text="{{ data.choices[ data.selected ].option_text }}" data-alt-text="{{ data.choices[ data.selected ].alt_text }}">
					{{ data.choices[ data.selected ].option_text }}
				</button>
			</div>
		</script>
		<?php
	}

	/**
	 * Add edit box template.
	 */
	public function add_edit_box_template() {
		$data = $this->get_month_choices();
		?>
		<script type="text/html" id="tmpl-snapshot-edit-container">
			<div id="customize-snapshot">
				<div class="snapshot-schedule-title">
					<h3>
						<?php esc_html_e( 'Edit Snapshot', 'customize-snapshots' ); ?>
					</h3>
					<?php $edit_snapshot_text = __( 'Edit Snapshot', 'customize-snapshots' ); ?>
					<a href="{{ data.editLink }}" class="dashicons dashicons-external snapshot-edit-link" target="_blank" title="<?php echo esc_attr( $edit_snapshot_text ); ?>" aria-expanded="false"><span class="screen-reader-text"><?php echo esc_html( $edit_snapshot_text ); ?></span></a>
				</div>

				<ul class="snapshot-controls">
					<li class="snapshot-control snapshot-control-title">
						<label for="snapshot-title" class="customize-control-title">
							<?php esc_html_e( 'Title', 'customize-snapshots' ); ?>
						</label>
						<input id="snapshot-title" type="text" value="{{data.title}}">
					</li>
					<# if ( data.currentUserCanPublish ) { #>
						<li class="snapshot-control snapshot-control-date">
							<label for="snapshot-date-month" class="customize-control-title">
								<?php esc_html_e( 'Scheduling', 'customize-snapshots' ); ?>
								<span class="reset-time">(<a href="#" title="<?php esc_attr_e( 'Reset scheduled date to original or current date', 'customize-snapshots' ); ?>"><?php esc_html_e( 'Reset', 'customize-snapshots' ) ?></a>)</span>
							</label>
							<p class="snapshot-schedule-description">
								<?php esc_html_e( 'Schedule changes to publish (go live) at a future date.', 'customize-snapshots' ); ?>
							</p>
							<div class="snapshot-future-date-notification notice notice-error ">
								<?php esc_html_e( 'Please select a future date.', 'customize-snapshots' ); ?>
							</div>
							<div class="snapshot-schedule-control date-inputs clear">
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Month', 'customize-snapshots' ); ?></span>
									<# _.defaults( data, <?php echo wp_json_encode( $data ) ?> ); #>
										<select id="snapshot-date-month" class="date-input month" data-date-input="month">
											<# _.each( data.month_choices, function( choice ) { #>
												<# if ( _.isObject( choice ) && ! _.isUndefined( choice.text ) && ! _.isUndefined( choice.value ) ) {
														text = choice.text;
														value = choice.value;
														} #>
													<option value="{{ value }}"
													<# if (choice.value == data.month) { #>
														selected="selected"
														<# } #>>
															{{ text }}
															</option>
															<# } ); #>
										</select>
								</label>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Day', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input day" data-date-input="day" min="1" max="31" value="{{ data.day }}" />
								</label>
								<span class="time-special-char">,</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Year', 'customize-snapshots' ); ?></span>
									<input type="number" size="4" maxlength="4" autocomplete="off" class="date-input year" data-date-input="year" min="<?php echo esc_attr( date( 'Y' ) ); ?>" value="{{ data.year }}" max="9999" />
								</label>
								<span class="time-special-char">@</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Hour', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input hour" data-date-input="hour" min="0" max="23" value="{{ data.hour }}" />
								</label>
								<span class="time-special-char">:</span>
								<label>
									<span class="screen-reader-text"><?php esc_html_e( 'Minute', 'customize-snapshots' ); ?></span>
									<input type="number" size="2" maxlength="2" autocomplete="off" class="date-input minute" data-date-input="minute" min="0" max="59" value="{{ data.minute }}" />
								</label>
							</div>
							<div class="timezone-info">
								<span class="snapshot-scheduled-countdown" role="timer"></span>
								<?php
								$tz_string = get_option( 'timezone_string' );
								if ( $tz_string ) {
									$tz = new \DateTimezone( $tz_string );
									$formatted_gmt_offset = $this->format_gmt_offset( $tz->getOffset( new \DateTime() ) / 3600 );
									$tz_name = str_replace( '_', ' ', $tz->getName() );

									/* translators: 1: timezone name, 2: gmt offset  */
									$timezone_description = sprintf( __( 'This site\'s dates are in the %1$s timezone (currently UTC%2$s).', 'customize-snapshots' ), $tz_name, $formatted_gmt_offset );
								} else {
									$formatted_gmt_offset = $this->format_gmt_offset( get_option( 'gmt_offset' ) );

									/* translators: %s: gmt offset  */
									$timezone_description = sprintf( __( 'Dates are in UTC%s.', 'customize-snapshots' ), $formatted_gmt_offset );
								}
								echo esc_html( $timezone_description );
								?>
							</div>
						</li>
						<# } #>
				</ul>
			</div>
		</script>

		<script id="tmpl-snapshot-scheduled-countdown" type="text/html">
			<# if ( data.remainingTime < 2 * 60 ) { #>
			<?php esc_html_e( 'This is scheduled for publishing in about a minute.', 'customize-snapshots' ); ?>

			<# } else if ( data.remainingTime < 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This snapshot is scheduled for publishing in about %s minutes.', 'customize-snapshots' ), '{{ Math.ceil( data.remainingTime / 60 ) }}' );
			?>

			<# } else if ( data.remainingTime < 24 * 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This snapshot is scheduled for publishing in about %s hours.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 * 10 ) / 10 }}' );
			?>

			<# } else { #>
				<?php
				/* translators: %s is a placeholder for the Underscore template var */
				echo sprintf( esc_html__( 'This snapshot is scheduled for publishing in about %s days.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 / 24 * 10 ) / 10 }}' );
				?>

				<# } #>
		</script>
		<?php
	}

	/**
	 * Format GMT Offset.
	 *
	 * @see wp_timezone_choice()
	 * @param float $offset Offset in hours.
	 * @return string Formatted offset.
	 */
	public function format_gmt_offset( $offset ) {
		if ( 0 <= $offset ) {
			$formatted_offset = '+' . (string) $offset;
		} else {
			$formatted_offset = (string) $offset;
		}
		$formatted_offset = str_replace(
			array( '.25', '.5', '.75' ),
			array( ':15', ':30', ':45' ),
			$formatted_offset
		);
		return $formatted_offset;
	}

	/**
	 * Generate options for the month Select.
	 *
	 * Based on touch_time().
	 *
	 * @see touch_time()
	 *
	 * @return array
	 */
	public function get_month_choices() {
		global $wp_locale;
		$months = array();
		for ( $i = 1; $i < 13; $i = $i + 1 ) {
			$month_number = zeroise( $i, 2 );
			$month_text = $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) );

			/* translators: 1: month number (01, 02, etc.), 2: month abbreviation */
			$months[ $i ]['text'] = sprintf( __( '%1$s-%2$s', 'customize-snapshots' ), $month_number, $month_text );
			$months[ $i ]['value'] = $month_number;
		}
		return array(
			'month_choices' => $months,
		);
	}

	/**
	 * Override default date values to a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Post Object if the post data did not apply.
	 */
	public function override_post_date_default_data( \WP_Post &$post ) {
		if ( ! is_array( $post ) ) {
			// Make sure that empty dates are not used in case of setting invalidity.
			$empty_date = '0000-00-00 00:00:00';
			if ( $empty_date === $post->post_date ) {
				$post->post_date = current_time( 'mysql', false );
			}
			if ( $empty_date === $post->post_date_gmt ) {
				$post->post_date_gmt = current_time( 'mysql', true );
			}
			if ( $empty_date === $post->post_modified ) {
				$post->post_modified = current_time( 'mysql', false );
			}
			if ( $empty_date === $post->post_modified_gmt ) {
				$post->post_modified_gmt = current_time( 'mysql', true );
			}
		}
		return $post;
	}

	/**
	 * Get Post_Type from dynamic class.
	 *
	 * @return string Post type.
	 */
	public function get_post_type() {
		return constant( get_class( $this->post_type ) . '::SLUG' );
	}

	/**
	 * Get Frontend UUID param.
	 *
	 * @return string param.
	 */
	public function get_front_uuid_param() {
		return constant( get_class( $this->post_type ) . '::FRONT_UUID_PARAM_NAME' );
	}

	/**
	 * Get customize uuid param name.
	 *
	 * @return string customize param name.
	 */
	public function get_customize_uuid_param() {
		return constant( get_class( $this->post_type ) . '::CUSTOMIZE_UUID_PARAM_NAME' );
	}

	/**
	 * Let unauthenticated users see posts published in a changeset.
	 *
	 * @param array $allcaps All capabilities.
	 * @param array $caps    Capabilities.
	 * @param array $args    Args.
	 * @return array All capabilities.
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args ) {
		if (
			! $this->current_snapshot_uuid
			||
			! isset( $args[2] )
			||
			(
					'read_post' !== $args[0]
					&&
					'read_page' !== $args[0]
			)
			||
			(
				isset( $allcaps[ $caps[0] ] )
				&&
				true === $allcaps[ $caps[0] ]
			)
		) {
			return $allcaps;
		}

		$post = get_post( $args[2] );
		if ( ! $post ) {
			return $allcaps;
		}

		// Check if the status of the post is 'published' within the changeset.
		$changeset_id = $this->post_type->find_post( $this->current_snapshot_uuid );
		if ( ! $changeset_id ) {
			return $allcaps;
		}

		$data = $this->post_type->get_post_content( get_post( absint( $changeset_id ) ) );

		$allow_cap = false;
		$key = 'post[' . $post->post_type . '][' . $post->ID . ']';

		if ( isset( $data[ $key ] ) ) {
			$changeset_post_values = $data[ $key ]['value'];
			if ( isset( $changeset_post_values['post_status'] ) ) {
				$allow_cap = 'publish' === $changeset_post_values['post_status'];
				if ( ! $allow_cap && isset( $allcaps['read_private_posts'] ) && true === $allcaps['read_private_posts'] ) {
					$allow_cap = 'private' === $changeset_post_values['post_status'];
				}
			}
		}

		$allcaps[ $caps[0] ] = $allow_cap;

		return $allcaps;

	}

	/**
	* Save the preview url query vars in changeset meta.
	*
	* @param int $post_id Post id.
	*/
	public function save_customizer_state_query_vars( $post_id ) {
		if ( ! isset( $_POST['customizer_state_query_vars'] ) ) {
			return;
		}

		$original_query_vars = json_decode( wp_unslash( $_POST['customizer_state_query_vars'] ), true );

		if ( empty( $original_query_vars ) || ! is_array( $original_query_vars ) ) {
			return;
		}

		$this->post_type->set_customizer_state_query_vars( $post_id, $original_query_vars );
	}
}
