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
	 * Init common hooks.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function hooks() {
		add_action( 'init', array( $this->post_type, 'init' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_controls_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

		add_action( 'load-edit.php', array( $this, 'handle_frontend_changeset_publish' ) );

		add_action( 'customize_controls_init', array( $this, 'add_snapshot_uuid_to_return_url' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_menu' ), 41 );
		add_action( 'admin_bar_menu', array( $this, 'remove_all_non_snapshot_admin_bar_links' ), 100000 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'print_admin_bar_styles' ) );
		add_action( 'save_post_' . Post_Type::SLUG, array( $this, 'create_initial_changeset_revision' ) );
		add_action( 'save_post_' . Post_Type::SLUG, array( $this, 'save_customizer_state_query_vars' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'prepare_snapshot_post_content_for_publish' ) );
		remove_action( 'delete_post', '_wp_delete_customize_changeset_dependent_auto_drafts' );
		add_action( 'delete_post', array( $this, 'clean_up_nav_menus_created_auto_drafts' ) );
		add_filter( 'customize_save_response', array( $this, 'add_snapshot_var_to_customize_save' ), 10, 2 );
		add_action( 'wp_ajax_customize_snapshot_conflict_check', array( $this, 'handle_conflicts_snapshot_request' ) );
	}

	/**
	 * Init.
	 */
	public function init() {
		$this->post_type = new Post_Type( $this );
		$this->hooks();
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
		$response['edit_link'] = $this->get_edit_link( $changeset_post->ID );
		$response['publish_date'] = $changeset_post->post_date; // @todo Remove when drop support for < 4.9.
		$response['title'] = $changeset_post->post_title;
		return $response;
	}

	/**
	 * Get edit post link.
	 *
	 * @param int|\WP_Post $post_id Post.
	 *
	 * @return null|string Post edit link.
	 */
	public function get_edit_link( $post_id ) {
		$has_filter = has_filter( 'get_edit_post_link', '__return_empty_string' );
		if ( $has_filter ) {
			remove_filter( 'get_edit_post_link', '__return_empty_string' );
		}
		$link = get_edit_post_link( $post_id, 'raw' );
		if ( $has_filter ) {
			add_filter( 'get_edit_post_link', '__return_empty_string' );
		}
		return $link;
	}

	/**
	 * Get the customize manager.
	 *
	 * @return \WP_Customize_Manager Manager.
	 */
	public function get_customize_manager() {
		global $wp_customize;
		return $wp_customize;
	}

	/**
	 * Ensure Customizer manager is instantiated.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 * @return \WP_Customize_Manager Manager.
	 */
	public function ensure_customize_manager() {
		global $wp_customize;

		$args = array();
		if ( empty( $wp_customize ) || ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$wp_customize = new \WP_Customize_Manager( $args ); // WPCS: override ok.
		}

		return $wp_customize;
	}

	/**
	 * Add snapshot UUID the Customizer return URL.
	 *
	 * If the Customizer was loaded from a referring URL had a changeset UUID, then ensure the return URL also includes this param.
	 */
	public function add_snapshot_uuid_to_return_url() {
		$wp_customize = $this->get_customize_manager();
		$should_add_snapshot_uuid = (
			is_customize_preview()
			&&
			false !== strpos( parse_url( wp_get_referer(), PHP_URL_QUERY ), 'customize_changeset_uuid=' . $wp_customize->changeset_uuid() )
		);
		if ( $should_add_snapshot_uuid ) {
			$args_name = Post_Type::FRONT_UUID_PARAM_NAME;
			$args = array(
				$args_name => $wp_customize->changeset_uuid(),
			);
			$return_url = add_query_arg( array_map( 'rawurlencode', $args ), $wp_customize->get_return_url() );
			$this->get_customize_manager()->set_return_url( $return_url );
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
		wp_enqueue_style( 'customize-snapshots' );
		wp_enqueue_script( 'customize-snapshots' );
		add_thickbox();

		$post = null;
		$preview_url_query_vars = array();
		$post_id = $this->get_customize_manager()->changeset_post_id();

		if ( $post_id ) {
			$post = get_post( $post_id );
			$preview_url_query_vars = $this->post_type->get_customizer_state_query_vars( $post->ID );
			if ( $post instanceof \WP_Post ) {
				$edit_link = $this->get_edit_link( $post_id );
			}
		}

		// Script data array.
		$exports = apply_filters( 'customize_snapshots_export_data', array(
			'inspectLink' => isset( $edit_link ) ? $edit_link : '',
			'title' => isset( $post->post_title ) ? $post->post_title : '',
			'previewingTheme' => isset( $preview_url_query_vars['theme'] ) ? $preview_url_query_vars['theme'] : '',
			'conflictNonce' => wp_create_nonce( Post_Type::SLUG . '_conflict' ),
			'i18n' => array(
				'title' => __( 'Title', 'customize-snapshots' ),
				'savePending' => __( 'Save Pending', 'customize-snapshots' ),
				'pendingSaved' => __( 'Pending Saved', 'customize-snapshots' ),
				'conflictNotification' => __( 'Potential changeset conflicts', 'customize-snapshots' ),
			),
		) );

		wp_scripts()->add_inline_script(
			'customize-snapshots',
			sprintf( 'wp.customize.snapshots = new wp.customize.Snapshots( %s );', wp_json_encode( $exports ) ),
			'after'
		);
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
		if ( ( 'post.php' === $hook ) && isset( $post->post_type ) && ( Post_Type::SLUG === $post->post_type ) && ( 'publish' !== $post->post_status ) ) {
			add_thickbox();
			wp_enqueue_script( $handle );
			wp_enqueue_style( $handle );
			$exports = array(
				'deleteInputName' => Post_Type::SLUG . '_remove_settings[]',
			);
			wp_add_inline_script(
				$handle,
				sprintf( 'CustomizeSnapshotsAdmin.init( %s )', wp_json_encode( $exports ) ),
				'after'
			);
		}
	}

	/**
	 * Enqueue Customizer frontend scripts.
	 */
	public function enqueue_frontend_scripts() {
		if ( ! is_customize_preview() || ! current_user_can( 'customize' ) ) {
			return;
		}
		$handle = 'customize-snapshots-frontend';
		wp_enqueue_script( $handle );

		$exports = array(
			'uuid' => $this->get_customize_manager()->changeset_uuid(),
			'home_url' => wp_parse_url( home_url( '/' ) ),
			'l10n' => array(
				'restoreSessionPrompt' => __( 'It seems you may have inadvertently navigated away from previewing a customized state. Would you like to restore the changeset context?', 'customize-snapshots' ),
			),
			'confirmationMsg' => __( 'Are you sure that you want to publish the Changeset?', 'customize-snapshots' ),
		);
		wp_add_inline_script(
			$handle,
			sprintf( 'CustomizeSnapshotsFrontend.init( %s )', wp_json_encode( $exports ) ),
			'after'
		);
	}

	/**
	 * Create initial changeset revision.
	 *
	 * This should be removed once #30854 is resolved.
	 *
	 * @link https://core.trac.wordpress.org/ticket/30854
	 *
	 * @param int $post_id Post ID.
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
	 * Determine whether the supplied UUID is in the right format.
	 *
	 * @todo Use wp_is_uuid().
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
		$this->add_changesets_admin_bar_link( $wp_admin_bar );
		$this->add_resume_snapshot_link( $wp_admin_bar );
		$this->add_post_edit_screen_link( $wp_admin_bar );
		$this->add_publish_changeset_link( $wp_admin_bar );
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
			#wpadminbar #wp-admin-bar-publish-customize-changeset > .ab-item:before {
				content: "\f147";
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
		if ( ! is_customize_preview() ) {
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
			$preview_url_query_params['url'] = rawurlencode( remove_query_arg( array( Post_Type::FRONT_UUID_PARAM_NAME ), $preview_url_query_params['url'] ) );
			$customize_node->href = preg_replace(
				'/(?<=\?).*?(?=#|$)/',
				build_query( $preview_url_query_params ),
				$customize_node->href
			);
		}

		$wp_customize = $this->get_customize_manager();
		$args = array(
			Post_Type::CUSTOMIZE_UUID_PARAM_NAME => $wp_customize->changeset_uuid(),
		);

		$post_id = $wp_customize->changeset_post_id();

		if ( $post_id ) {
			$customizer_state_query_vars = $this->post_type->get_customizer_state_query_vars( $post_id );
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
	public function add_changesets_admin_bar_link( $wp_admin_bar ) {
		if ( ! $wp_admin_bar->get_node( 'customize' ) ) {
			return;
		}
		$wp_admin_bar->add_node( array(
			'id' => 'customize-changesets',
			'parent' => 'customize',
			'title' => __( 'Changesets', 'customize-snapshots' ),
			'href' => admin_url( 'edit.php?post_type=customize_changeset' ),
			'meta' => array(
				'class' => 'ab-item',
			),
		) );
	}

	/**
	 * Adds a link to resume snapshot previewing.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_resume_snapshot_link( $wp_admin_bar ) {
		$wp_admin_bar->add_menu( array(
			'id' => 'resume-customize-snapshot',
			'title' => __( 'Resume Changeset Preview', 'customize-snapshots' ),
			'href' => '#',
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
	}

	/**
	 * Adds a "Inspect Changeset" link to the Toolbar when previewing a changeset.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_post_edit_screen_link( $wp_admin_bar ) {
		if ( ! is_customize_preview() || ! current_user_can( get_post_type_object( $this->post_type->get_slug() )->cap->edit_posts ) ) {
			return;
		}
		$post_id = $this->get_customize_manager()->changeset_post_id();
		if ( ! $post_id ) {
			return;
		}
		$wp_admin_bar->add_menu( array(
			'id' => 'inspect-customize-snapshot',
			'title' => __( 'Inspect Changeset', 'customize-snapshots' ),
			'href' => $this->get_edit_link( $post_id ),
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
	}

	/**
	 * Adds a "Publish Changeset" link to the Toolbar when in Snapshot mode.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_publish_changeset_link( $wp_admin_bar ) {
		if ( ! is_customize_preview() || ! current_user_can( get_post_type_object( $this->post_type->get_slug() )->cap->publish_posts ) ) {
			return;
		}

		$wp_customize = $this->get_customize_manager();
		$post_id = $wp_customize->changeset_post_id();
		if ( ! $post_id ) {
			return;
		}

		$href = add_query_arg(
			array(
				'post_type' => $this->post_type->get_slug(),
				'action' => 'frontend_publish',
				'uuid' => $wp_customize->changeset_uuid(),
				'stylesheet' => get_stylesheet(),
			),
			admin_url( 'edit.php' )
		);
		$wp_admin_bar->add_menu( array(
			'id' => 'publish-customize-changeset',
			'title' => __( 'Publish Changeset', 'customize-snapshots' ),
			'href' => wp_nonce_url( $href, 'publish-changeset_' . $wp_customize->changeset_uuid() ),
			'meta' => array(
				'class' => 'ab-item ab-customize-snapshots-item',
			),
		) );
	}

	/**
	 * Adds an "Exit Changeset Preview" link to the Toolbar when previewing a changeset.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance.
	 */
	public function add_snapshot_exit_link( $wp_admin_bar ) {
		if ( ! is_customize_preview() ) {
			return;
		}
		$wp_admin_bar->add_menu( array(
			'id' => 'exit-customize-snapshot',
			'title' => __( 'Exit Changeset Preview', 'customize-snapshots' ),
			'href' => remove_query_arg( Post_Type::FRONT_UUID_PARAM_NAME ),
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
		if ( ! is_customize_preview() || ! current_user_can( 'customize' ) ) {
			return;
		}
		$snapshot_admin_bar_node_ids = array(
			'customize',
			'exit-customize-snapshot',
			'inspect-customize-snapshot',
			'publish-customize-changeset',
		);
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
		?>
		<script type="text/html" id="tmpl-snapshot-dialog-error">
			<div id="snapshot-dialog-error" title="{{ data.title }}">
				<p>{{ data.message }}</p>
			</div>
		</script>

		<script type="text/html" id="tmpl-snapshot-inspect-link-control">
			<a class="button-link" href="#" target="_blank"><span class="dashicons dashicons-external"></span><?php esc_html_e( 'Inspect', 'customize-snapshots' ); ?></a>
		</script>

		<script id="tmpl-snapshot-scheduled-countdown" type="text/html">
			<# if ( data.remainingTime < 2 * 60 ) { #>
			<?php esc_html_e( 'This is scheduled for publishing in about a minute.', 'customize-snapshots' ); ?>

			<# } else if ( data.remainingTime < 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s minutes.', 'customize-snapshots' ), '{{ Math.ceil( data.remainingTime / 60 ) }}' );
			?>

			<# } else if ( data.remainingTime < 24 * 60 * 60 ) { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s hours.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 * 10 ) / 10 }}' );
			?>

			<# } else { #>
			<?php
			/* translators: %s is a placeholder for the Underscore template var */
			echo sprintf( esc_html__( 'This changeset is scheduled for publishing in about %s days.', 'customize-snapshots' ), '{{ Math.round( data.remainingTime / 60 / 60 / 24 * 10 ) / 10 }}' );
			?>

			<# } #>
		</script>

		<script type="text/html" id="tmpl-snapshot-conflict-button">
			<?php $title_text = __( 'click to expand', 'customize-snapshots' ); ?>
			<# id= data.setting_id.replace( /\]/g, '\\]' ).replace( /\[/g, '\\[' ); #>
			<span>
				<?php esc_html_e( 'Conflicts', 'customize-snapshots' ); ?>
				<a href="<?php echo esc_url( '#TB_inline?width=600&height=550&inlineId=snapshot-conflicts-' ); ?>{{id}}" class="dashicons dashicons-warning thickbox snapshot-conflicts-button" title="<?php echo esc_attr( $title_text ); ?>"></a>
			</span>
		</script>

		<script type="text/html" id="tmpl-snapshot-notification-template">
			<ul>
				<# _.each( data.notifications, function( notification ) { #>
					<li class="notice notice-{{ notification.type || 'info' }} {{ data.altNotice ? 'notice-alt' : '' }}" data-code="{{ notification.code }}" data-type="{{ notification.type }}">{{{ notification.message || notification.code }}}</li>
				<# } ); #>
			</ul>
		</script>

		<script type="text/html" id="tmpl-snapshot-conflict">
			<div id="snapshot-conflicts-{{data.setting_id}}" class="snapshot-conflict-thickbox-content thickbox">
					<# _.each( data.conflicts, function( setting ) { #>
						<details>
							<summary>
								<code>{{setting.uuid}}
									<# if ( ! _.isEmpty( setting.name ) ) {
										if ( ! _.isEmpty( setting.uuid ) ){ #>
											-
										<# } #>
										{{setting.name}}
									<# } #>
								</code>
								<# if ( ! _.isEmpty( setting.edit_link ) ) { #>
									<a target="_blank" href="{{setting.edit_link}}" class="dashicons dashicons-external"></a>
								<# } #>
							</summary>
							<div class="snapshot-value">
								{{{setting.value}}}
							</div>
						</details>
					<# }); #>
			</div>
		</script>

		<script type="text/html" id="tmpl-snapshot-conflict-value">
			<# if ( _.isEmpty( data.value ) ) { #>
					<em><?php esc_html_e( '(Empty String)', 'customize-snapshots' ); ?></em>
			<# } else if ( _.isString( data.value ) ||  _.isNumber( data.value ) ) { #>
					<p>{{data.value}}</p>
			<# } else if ( _.isBoolean(data.value) ) {
				var temp = JSON.stringify(data.value); #>
					<p>{{temp}}</p>
			<# } else {
				var temp = JSON.stringify( data.value, null, 4 ); #>
				<pre class="pre">{{temp}}</pre>
			<# } #>
		</script>
		<?php
	}

	/**
	 * Handles request to publish changeset from frontend.
	 */
	public function handle_frontend_changeset_publish() {

		if ( ! isset( $_GET['uuid'] ) || ! isset( $_GET['action'] ) || 'frontend_publish' !== $_GET['action'] ) {
			return;
		}
		$uuid = sanitize_key( wp_unslash( $_GET['uuid'] ) );
		if ( ! static::is_valid_uuid( $uuid ) ) {
			return;
		}

		$is_user_authorized = (
			check_ajax_referer( 'publish-changeset_' . $uuid, false, false )
			&&
			current_user_can( get_post_type_object( $this->post_type->get_slug() )->cap->publish_posts )
		);
		if ( ! $is_user_authorized ) {
			wp_die(
				esc_html__( 'Oops. Unable to publish the changeset due to an expired user session. Please go back, reload the page, and try publishing again.', 'customize-snapshots' ),
				esc_html__( 'Changeset publishing failed', 'customize-snapshots' ),
				array(
					'back_link' => true,
					'response' => 401,
				)
			);
		}

		$stylesheet = null;
		if ( isset( $_GET['stylesheet'] ) ) {
			$theme = wp_get_theme( wp_unslash( $_GET['stylesheet'] ) );
			if ( $theme->errors() ) {
				$msg  = __( 'Oops. Unable to publish the changeset. The following error(s) occurred: ', 'customize-snapshots' );
				$msg .= join( '; ', array_keys( $theme->errors()->errors ) );
				wp_die(
					'<p>' . esc_html( $msg ) . '</p>',
					esc_html__( 'Changeset publishing failed', 'customize-snapshots' ),
					array(
						'back_link' => true,
						'response'  => 400,
					)
				);
			}
			$stylesheet = $theme->get_stylesheet();
		}

		$wp_customize = $this->get_customize_manager();
		$args         = array();
		if ( empty( $wp_customize ) || ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$args['changeset_uuid'] = $uuid;
			if ( $stylesheet ) {
				$args['theme'] = $stylesheet;
			}
			$wp_customize = new \WP_Customize_Manager( $args ); // WPCS: override ok.
		}
		$r = $wp_customize->save_changeset_post( array(
			'status' => 'publish',
		) );

		if ( is_wp_error( $r ) ) {
			$msg  = __( 'Oops. Unable to publish the changeset. The following error(s) occurred: ', 'customize-snapshots' );
			$msg .= join( '; ', array_keys( $r->errors ) );
			wp_die(
				'<p>' . esc_html( $msg ) . '</p>',
				esc_html__( 'Changeset publishing failed', 'customize-snapshots' ),
				array(
					'back_link' => true,
					'response' => 500,
				)
			);
		} else {
			$referer = wp_get_referer();

			// Ensure redirect is set to frontend.
			if ( empty( $referer ) || false !== strpos( parse_url( $referer, PHP_URL_PATH ), '/wp-admin/' ) ) {
				$referer = home_url();
			}

			$sendback = remove_query_arg( array(
				Post_Type::FRONT_UUID_PARAM_NAME,
				'customize_theme',
				Post_Type::CUSTOMIZE_UUID_PARAM_NAME,
			), $referer );

			wp_redirect( $sendback );
			exit();
		}
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

	/**
	 * Clean up auto-draft post created by Nav menus on changeset delete.
	 *
	 * @param int $changeset_post_id Deleting changeset post id.
	 */
	public function clean_up_nav_menus_created_auto_drafts( $changeset_post_id ) {
		global $wpdb;
		$changeset_post = get_post( $changeset_post_id );

		if ( ! ( $changeset_post instanceof \WP_Post ) || Post_Type::SLUG !== $changeset_post->post_type ) {
			return;
		}

		$data = json_decode( $changeset_post->post_content, true );
		if ( empty( $data['nav_menus_created_posts']['value'] ) ) {
			return;
		}
		remove_action( 'delete_post', array( $this, 'clean_up_nav_menus_created_auto_drafts' ) );
		foreach ( $data['nav_menus_created_posts']['value'] as $nav_menu_created_post_id ) {
			if ( 'auto-draft' !== get_post_status( $nav_menu_created_post_id ) && 'draft' !== get_post_status( $nav_menu_created_post_id ) ) {
				continue;
			}

			/**
			 * If we have Customize post plugin then it will take care of post delete see: https://github.com/xwp/wp-customize-posts/pull/348
			 * because it overrides nav_menus_created_posts data.
			 *
			 * @See WP_Customize_Posts:filter_out_nav_menus_created_posts_for_customized_posts()
			 */
			if ( ! class_exists( 'Customize_Posts_Plugin' ) ) {
				// If customize post plugin is not installed we search for nav_menus_created_posts and lookup for reference via php code.
				// Todo: Improve logic to find reference below.
				$query = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID != %d ", Post_Type::SLUG, $changeset_post_id );
				$query .= $wpdb->prepare( ' AND post_content LIKE %s AND post_content LIKE %s LIMIT 50', '%' . $wpdb->esc_like( '"nav_menus_created_posts":' ) . '%', '%' . $nav_menu_created_post_id . '%' );
				$post_ids = $wpdb->get_col( $query ); // WPCS: unprepared SQL ok.
				$should_delete = true;
				if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {
					foreach ( $post_ids as $p_id ) {
						$p = get_post( $p_id );
						if ( ! ( $p instanceof \WP_Post ) ) {
							continue;
						}
						$content = json_decode( $p->post_content, true );
						if ( empty( $content['nav_menus_created_posts']['value'] ) ) {
							continue;
						}
						if ( false !== array_search( $nav_menu_created_post_id, $content['nav_menus_created_posts']['value'] ) ) {
							$should_delete = false;
							break;
						}
					}
				}
				if ( $should_delete ) {
					wp_delete_post( $nav_menu_created_post_id, true );
				}
			}
		} // End foreach().
		add_action( 'delete_post', array( $this, 'clean_up_nav_menus_created_auto_drafts' ) );
	}

	/**
	 * Handle snapshot conflicts AJAX request.
	 */
	public function handle_conflicts_snapshot_request() {
		$wp_customize = $this->get_customize_manager();
		if ( ! check_ajax_referer( Post_Type::SLUG . '_conflict', 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		}
		if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // WPCS: input var ok.
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		}
		$changeset_uuid = wp_unslash( $_POST['changeset_uuid'] );
		if ( ! self::is_valid_uuid( $changeset_uuid ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_uuid' );
		}
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		if ( ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			$wp_customize = new \WP_Customize_Manager( compact( 'changeset_uuid' ) );
			$post_id      = $wp_customize->changeset_post_id();
		} else {
			$post_id = $wp_customize->find_changeset_post_id( $changeset_uuid );
		}

		if ( isset( $_POST['setting_ids'] ) ) { // WPCS: input var ok.
			$setting_ids = array_map( function( $key ) {
				// Credit: http://stackoverflow.com/a/1176923/1138341.
				return preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $key );
			}, wp_unslash( $_POST['setting_ids'] ) );
		} else {
			status_header( 400 );
			wp_send_json_error( 'required_param_missing' );
		}
		$post = get_post( $wp_customize->changeset_post_id() );
		if ( empty( $setting_ids ) && $post instanceof \WP_Post ) {
			$content  = $this->post_type->get_post_content( $post );
			$settings = array_keys( $content );
		} else {
			$settings = $setting_ids;
		}
		if ( empty( $settings ) ) {
			status_header( 400 );
			wp_send_json_error( 'no_setting_to_check' );
		}
		$changeset_post = $post_id ? get_post( $post_id ) : null;
		$return         = $this->post_type->get_conflicted_settings( $changeset_post, $settings );
		foreach ( $return as $setting_key => &$items ) {
			foreach ( $items as &$item ) {
				$item['value'] = $this->post_type->get_printable_setting_value( $item['value'], $setting_key, $item['setting_param'], get_post( $item['id'] ) );
				unset( $item['setting_param'] );
			}
			array_unshift( $items, array(
				'id' => '',
				'value' => '',
				'name' => __( 'Current Change', 'customize-snapshots' ),
				'uuid' => '',
				'edit_link' => '',
			) );
		}
		wp_send_json_success( $return );
	}
}
