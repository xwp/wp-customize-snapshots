<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Changeset Post Type Class.
 *
 * Enhance usage of changeset.
 *
 * @package CustomizeSnapshots
 */
class Post_Type {

	/**
	 * Post type.
	 *
	 * @type string
	 */
	const SLUG = 'customize_changeset';

	/**
	 * Customize front end uuid param key name.
	 *
	 * @type string
	 */
	const FRONT_UUID_PARAM_NAME = 'customize_changeset_uuid';

	/**
	 * Customize UUID Param Name.
	 *
	 * @type string
	 */
	const CUSTOMIZE_UUID_PARAM_NAME = 'changeset_uuid';

	/**
	 * Customize_Snapshot_Manager instance.
	 *
	 * @access protected
	 * @var Customize_Snapshot_Manager
	 */
	public $snapshot_manager;

	/**
	 * Whether kses filters on content_save_pre are added.
	 *
	 * @var bool
	 */
	protected $kses_suspended = false;

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @throws Exception If the UUID is invalid.
	 *
	 * @param Customize_Snapshot_Manager $snapshot_manager     Customize snapshot bootstrap instance.
	 */
	public function __construct( Customize_Snapshot_Manager $snapshot_manager ) {
		$this->snapshot_manager = $snapshot_manager;
	}

	/**
	 * Get the post type slug.
	 *
	 * @return string Post type slug.
	 */
	public function get_slug() {
		return static::SLUG;
	}

	/**
	 * Calls common hooks Actions and filters
	 */
	public function hooks() {
		add_action( 'add_meta_boxes_' . static::SLUG, array( $this, 'remove_slug_metabox' ), 100 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'force_at_least_one_revision' ), 10, 2 );
		add_action( 'load-revision.php', array( $this, 'suspend_kses_for_snapshot_revision_restore' ) );
		add_filter( 'get_the_excerpt', array( $this, 'filter_snapshot_excerpt' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 2 );
		add_action( 'post_submitbox_minor_actions', array( $this, 'hide_disabled_publishing_actions' ) );
		add_filter( 'content_save_pre', array( $this, 'filter_out_settings_if_removed_in_metabox' ), 10 );
		add_action( 'admin_print_scripts-revision.php', array( $this, 'disable_revision_ui_for_published_posts' ) );
		add_action( 'admin_notices', array( $this, 'admin_show_merge_error' ) );
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_publish_error_admin_notice' ) );

		// Add workaround for failure to save changes to option settings when publishing changeset outside of customizer. See https://core.trac.wordpress.org/ticket/39221#comment:14
		if ( function_exists( '_wp_customize_publish_changeset' ) && function_exists( 'wp_doing_ajax' ) ) { // Workaround only works in WP 4.7.
			$priority = has_action( 'transition_post_status', '_wp_customize_publish_changeset' );
			add_action( 'transition_post_status', array( $this, 'start_pretending_customize_save_ajax_action' ), $priority - 1, 3 );
			add_action( 'transition_post_status', array( $this, 'finish_pretending_customize_save_ajax_action' ), $priority + 1, 3 );
		}
	}

	/**
	 * Register post type.
	 * Child class should override this to call it's own init.
	 */
	public function init() {
		$this->extend_changeset_post_type_object();
		$this->hooks();

		add_filter( 'post_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_action( 'add_meta_boxes_' . static::SLUG, array( $this, 'setup_metaboxes' ), 10, 1 );
		add_action( 'admin_menu', array( $this, 'add_admin_menu_item' ), 99 );
		add_filter( 'map_meta_cap', array( $this, 'remap_customize_meta_cap' ), 5, 4 );
		add_filter( 'bulk_actions-edit-' . static::SLUG, array( $this, 'add_snapshot_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . static::SLUG, array( $this, 'handle_snapshot_merge' ), 10, 3 );
		add_action( 'load-post-new.php', function() {
			if ( static::SLUG === get_current_screen()->post_type ) {
				wp_redirect( wp_customize_url() );
				exit;
			}
		} );
	}

	/**
	 * Extend changeset post_type object.
	 */
	public function extend_changeset_post_type_object() {
		$post_type_obj = get_post_type_object( static::SLUG );
		add_post_type_support( static::SLUG, 'revisions' );
		$post_type_obj->show_ui = true;
		$post_type_obj->show_in_menu = true;
		$post_type_obj->_edit_link = 'post.php?post=%d';
		$arg = array(
			'capability_type' => Post_Type::SLUG,
			'map_meta_cap' => true,
			'capabilities' => array(
				'publish_posts' => 'customize_publish',
			),
		);
		$arg = (object) $arg;
		$post_type_obj->cap = get_post_type_capabilities( $arg );
		$post_type_obj->show_in_customizer = false;
		$post_type_obj->customize_snapshot_post_type_obj = $this;
		$post_type_obj->show_in_rest = true;
		$post_type_obj->rest_base = 'customize_changesets';
		$post_type_obj->rest_controller_class = __NAMESPACE__ . '\\Snapshot_REST_API_Controller';
	}

	/**
	 * Force at least one revision to be stored for changeset posts.
	 *
	 * This is useful on installs where revisions are disabled via setting WP_POST_REVISIONS to 0.
	 * This ensures that the changeset post will not be automatically trashed.
	 *
	 * @see _wp_customize_publish_changeset()
	 *
	 * @param int      $num  Number of revisions to store.
	 * @param \WP_Post $post Post object.
	 * @return int Revisions to store.
	 */
	public function force_at_least_one_revision( $num, $post ) {
		if ( empty( $num ) && static::SLUG === $post->post_type ) {
			$num = 1;
		}
		return $num;
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu_item() {
		$post_type_object = get_post_type_object( static::SLUG );
		$capability = $post_type_object->cap->edit_posts;
		$page_title = $post_type_object->labels->name;
		$menu_title = $post_type_object->labels->name;
		$menu_slug = 'edit.php?post_type=' . static::SLUG;
		if ( current_user_can( 'customize' ) && isset( $_SERVER['REQUEST_URI'] ) ) { // WPCS: input var ok.
			$customize_url = add_query_arg(
				'return',
				rawurlencode( wp_validate_redirect( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ), // WPCS: input var ok.
				'customize.php'
			);

			// Remove exiting menu from appearance as it will require 'edit_theme_options' cap.
			remove_submenu_page( 'themes.php', esc_url( $customize_url ) );
			remove_menu_page( esc_url( $customize_url ) );

			// Add customize menu on top and add Changeset menu as submenu.
			$customize_page_title = __( 'Customize', 'default' );
			add_menu_page( $customize_page_title, $customize_page_title, 'customize', esc_url( $customize_url ), '', 'dashicons-admin-customizer', 61 );
			add_submenu_page( $customize_url, $page_title, $menu_title, $capability, esc_url( $menu_slug ) );
		}
	}

	/**
	 * Filter post link.
	 *
	 * @param string   $url  URL.
	 * @param \WP_Post $post Post.
	 * @return string URL.
	 */
	public function filter_post_type_link( $url, $post ) {
		if ( static::SLUG === $post->post_type ) {
			$url = $this->get_frontend_view_link( $post );
		}
		return $url;
	}

	/**
	 * Suspend kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function suspend_kses() {
		if ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			$this->kses_suspended = true;
			kses_remove_filters();
		}
	}

	/**
	 * Restore kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function restore_kses() {
		if ( $this->kses_suspended ) {
			kses_init_filters();
			$this->kses_suspended = false;
		}
	}

	/**
	 * Add the metabox.
	 */
	public function setup_metaboxes() {
		$id = static::SLUG;
		$title = __( 'Data', 'customize-snapshots' );
		$callback = array( $this, 'render_data_metabox' );
		$screen = static::SLUG;
		$context = 'normal';
		$priority = 'high';
		add_meta_box( $id, $title, $callback, $screen, $context, $priority );
	}

	/**
	 * Remove publish metabox for published posts, since they should be immutable once published.
	 *
	 * @codeCoverageIgnore
	 */
	public function remove_slug_metabox() {
		remove_meta_box( 'slugdiv', static::SLUG, 'normal' );
	}

	/**
	 * Make sure that restoring snapshot revisions doesn't involve kses corrupting the post_content.
	 *
	 * Ideally there would be an action like pre_wp_restore_post_revision instead
	 * of having to hack into the load-revision.php action. But even more ideally
	 * we should be able to disable such content_save_pre filters from even applying
	 * for certain post types, such as those which store JSON in post_content.
	 *
	 * @codeCoverageIgnore
	 */
	function suspend_kses_for_snapshot_revision_restore() {
		if ( ! isset( $_GET['revision'] ) ) { // WPCS: input var ok. CSRF ok.
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'restore' !== $_GET['action'] ) { // WPCS: input var ok, sanitization ok. CSRF ok.
			return;
		}
		$revision_post_id = intval( $_GET['revision'] ); // WPCS: input var ok.
		if ( $revision_post_id <= 0 ) {
			return;
		}
		$revision_post = wp_get_post_revision( $revision_post_id );
		if ( empty( $revision_post ) ) {
			return;
		}
		$post = get_post( $revision_post->post_parent );
		if ( empty( $post ) || static::SLUG !== $post->post_type ) {
			return;
		}

		$this->suspend_kses();
		$that = $this;
		add_action( 'wp_restore_post_revision', function() use ( $that ) {
			$that->restore_kses();
		} );
	}

	/**
	 * Include the setting IDs in the excerpt.
	 *
	 * @param string   $excerpt The post excerpt.
	 * @param \WP_Post $post    Post object.
	 * @return string Excerpt.
	 */
	public function filter_snapshot_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( static::SLUG === $post->post_type ) {
			$settings = array();
			foreach ( $this->get_post_content( $post ) as $setting_id => $setting_params ) {
				if ( ! isset( $setting_params['dirty'] ) || true === $setting_params['dirty'] ) {
					$settings[] = $setting_id;
				}
			}
			$excerpt = join( ', ', array_map( 'esc_html', $settings ) );
		}
		return $excerpt;
	}

	/**
	 * Add Customize link to quick edit links.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array Actions.
	 */
	public function filter_post_row_actions( $actions, $post ) {
		if ( static::SLUG !== $post->post_type ) {
			return $actions;
		}

		$post_type_obj = get_post_type_object( static::SLUG );
		if ( 'publish' !== $post->post_status && current_user_can( $post_type_obj->cap->edit_post, $post->ID ) ) {
			$args = array_merge(
				$this->get_customizer_state_query_vars( $post->ID ),
				array(
					static::CUSTOMIZE_UUID_PARAM_NAME => $post->post_name,
				)
			);

			$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );
			$actions = array_merge(
				array(
					'customize' => sprintf( '<a href="%s">%s</a>', esc_url( $customize_url ), esc_html__( 'Customize', 'default' ) ),
				),
				$actions
			);

			$actions = array_merge(
				array(
					'front-view' => sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_permalink( $post->ID ) ),
						esc_html__( 'Preview', 'customize-snapshots' )
					),
				),
				$actions
			);
		}

		// Rename "Edit" to "Inspect" for the row action.
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				get_edit_post_link( $post->ID, 'display' ),
				/* translators: %s: post title */
				esc_attr( sprintf( __( 'Inspect &#8220;%s&#8221;', 'customize-snapshots' ), get_the_title( $post->ID ) ) ),
				__( 'Inspect', 'customize-snapshots' )
			);
		}

		unset( $actions['inline hide-if-no-js'] );

		return $actions;
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_data_metabox( $post ) {
		$snapshot_content = $this->get_post_content( $post );

		echo '<p>';
		echo esc_html__( 'UUID:', 'customize-snapshots' ) . ' <code>' . esc_html( $post->post_name ) . '</code><br>';
		echo sprintf( '%1$s %2$s %3$s', esc_html__( 'Modified:', 'customize-snapshots' ), esc_html( get_the_modified_date( '' ) ), esc_html( get_the_modified_time( '' ) ) ) . '<br>';
		echo '</p>';

		$snapshot_theme = get_post_meta( $post->ID, '_snapshot_theme', true );
		if ( ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme ) {
			echo '<p>';
			/* translators: 1 is the theme the changeset was created for */
			echo sprintf( esc_html__( 'This changeset was made when a different theme was active (%1$s), so currently it cannot be edited.', 'customize-snapshots' ), esc_html( $snapshot_theme ) );
			echo '</p>';
		} elseif ( 'publish' !== $post->post_status ) {
			echo '<p>';
			$args = array_merge(
				$this->get_customizer_state_query_vars( $post->ID ),
				array(
					static::CUSTOMIZE_UUID_PARAM_NAME => $post->post_name,
				)
			);

			$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );
			echo sprintf(
				'<a href="%s" class="button button-secondary">%s</a> ',
				esc_url( $customize_url ),
				esc_html__( 'Edit in Customizer', 'customize-snapshots' )
			);

			$frontend_view_url = get_permalink( $post->ID );
			echo sprintf(
				'<a href="%s" class="button button-secondary">%s</a>',
				esc_url( $frontend_view_url ),
				esc_html__( 'Preview Changeset', 'customize-snapshots' )
			);
			echo '</p>';
		}

		echo '<hr>';

		ksort( $snapshot_content );
		wp_nonce_field( static::SLUG . '_settings', static::SLUG );
		echo '<ul id="snapshot-settings">';
		foreach ( $snapshot_content as $setting_id => $setting_params ) {
			if ( ! isset( $setting_params['value'] ) && ! isset( $setting_params['publish_error'] ) ) {
				continue;
			}
			$value = isset( $setting_params['value'] ) ? $setting_params['value'] : '';
			echo '<li>';
			echo '<details open>';
			echo '<summary><code>' . esc_html( $setting_id ) . '</code> ';
			if ( 'publish' !== get_post_status( $post ) ) {
				echo '<a href="#" id="' . esc_attr( $setting_id ) . '" data-text-restore="' . esc_attr__( 'Restore setting', 'customize-snapshots' ) . '" class="snapshot-toggle-setting-removal remove">' . esc_html__( 'Remove setting', 'customize-snapshots' ) . '</a>';
			}

			// Show error message when there was a publishing error.
			if ( isset( $setting_params['publish_error'] ) ) {
				echo '<span class="error-message">';
				echo '<b>' . esc_html__( 'Publish error:', 'customize-snapshots' ) . '</b> ';
				switch ( $setting_params['publish_error'] ) {
					case 'null_value':
						esc_html_e( 'Missing value.', 'customize-snapshots' );
						break;
					case 'unrecognized_setting':
						esc_html_e( 'Unrecognized setting.', 'customize-snapshots' );
						break;
					case 'invalid_value':
						esc_html_e( 'Invalid value.', 'customize-snapshots' );
						break;
					default:
						echo '<code>' . esc_html( $setting_params['publish_error'] ) . '</code>';
				}
				echo '</span>';
			}

			echo '</summary>';

			if ( '' === $value ) {
				$preview = '<p><em>' . esc_html__( '(Empty string)', 'customize-snapshots' ) . '</em></p>';
			} elseif ( is_string( $value ) || is_numeric( $value ) ) {
				$preview = '<p>' . esc_html( $value ) . '</p>';
			} elseif ( is_bool( $value ) ) {
				$preview = '<p>' . wp_json_encode( $value ) . '</p>';
			} else {
				$preview = sprintf( '<pre class="pre">%s</pre>', esc_html( Customize_Snapshot_Manager::encode_json( $value ) ) );
			}

			/**
			 * Filters the previewed value for a snapshot.
			 *
			 * @param string $preview HTML markup.
			 * @param array  $context {
			 *     Context.
			 *
			 *     @type mixed    $value          Value being previewed.
			 *     @type string   $setting_id     Setting args, including value.
			 *     @type array    $setting_params Setting args, including value.
			 *     @type \WP_Post $post           Snapshot post.
			 * }
			 */
			$preview = apply_filters( 'customize_snapshot_value_preview', $preview, compact( 'value', 'setting_id', 'setting_params', 'post' ) );

			echo $preview; // WPCS: xss ok.
			echo '</details>';
			echo '</li>';
		} // End foreach().
		echo '</ul>';
	}

	/**
	 * Find a snapshot post by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return int|null Post ID or null if not found.
	 */
	public function find_post( $uuid ) {
		$manager = $this->snapshot_manager->ensure_customize_manager();
		return $manager->find_changeset_post_id( $uuid );
	}

	/**
	 * This is needed to ensure that draft posts can be queried by name.
	 *
	 * @todo This can probably be removed, since we're explicitly requesting all statuses.
	 * @codeCoverageIgnore
	 *
	 * @param \WP_Query $query WP Query.
	 */
	public function _override_wp_query_is_single( $query ) {
		$query->is_single = false;
	}

	/**
	 * Get the snapshot array out of the post_content.
	 *
	 * A post revision for a customize_snapshot may also be supplied.
	 *
	 * @param \WP_Post $post A customize_snapshot post or a revision post.
	 * @return array|null Array of data or null if bad post supplied.
	 */
	public function get_post_content( \WP_Post $post ) {
		if ( static::SLUG !== $post->post_type ) {
			$parent_post = null;
			if ( 'revision' === $post->post_type ) {
				$parent_post = get_post( $post->post_parent );
			}
			if ( ! $parent_post || static::SLUG !== $parent_post->post_type ) {
				return null;
			}
		}

		// Snapshot is stored as JSON in post_content.
		$data = json_decode( $post->post_content, true );
		if ( json_last_error() || ! is_array( $data ) ) {
			$this->snapshot_manager->plugin->trigger_warning( 'JSON parse error, expected array: ' . ( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error() ) );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}

		return $data;
	}

	/**
	 * Persist the data in the snapshot post content.
	 *
	 * @param array $args Args.
	 *
	 * @internal For saving changesets use \WP_Customize_Manager::save_changeset_post().
	 *
	 * @return int|\WP_Error Post ID for snapshot or WP_Error instance.
	 */
	public function save( array $args ) {

		// @todo Add support for $args['post_id'].
		if ( empty( $args['uuid'] ) || ! Customize_Snapshot_Manager::is_valid_uuid( $args['uuid'] ) ) {
			return new \WP_Error( 'missing_valid_uuid' );
		}

		$post_arr = array(
			'post_name' => $args['uuid'],
			'post_title' => ! empty( $args['post_title'] ) ? $args['post_title'] : $args['uuid'],
			'post_type' => static::SLUG,
			'meta_input' => array(
				'_snapshot_version' => $this->snapshot_manager->plugin->version,
			),
		);
		if ( ! empty( $args['status'] ) ) {
			if ( ! get_post_status_object( $args['status'] ) ) {
				return new \WP_Error( 'bad_status' );
			}
			$post_arr['post_status'] = $args['status'];
		}

		$post_id = $this->find_post( $args['uuid'] );
		$is_update = ! empty( $post_id );

		if ( $post_id ) {
			$post_arr['ID'] = $post_id;
		}

		if ( isset( $args['data'] ) ) {
			if ( ! is_array( $args['data'] ) ) {
				return new \WP_Error( 'missing_data' );
			}
			foreach ( $args['data'] as $setting_id => $setting_params ) {
				if ( ! is_array( $setting_params ) ) {
					return new \WP_Error( 'bad_setting_params' );
				}
				if ( ! array_key_exists( 'value', $setting_params ) ) {
					return new \WP_Error( 'missing_value_param' );
				}
			}
			$post_arr['post_content'] = Customize_Snapshot_Manager::encode_json( $args['data'] );
		} elseif ( ! $is_update ) {
			$post_arr['post_content'] = Customize_Snapshot_Manager::encode_json( array() );
		}

		if ( ! empty( $args['theme'] ) ) {
			$post_arr['meta_input']['_snapshot_theme'] = $args['theme'];
		}
		if ( ! empty( $args['author'] ) ) {
			$post_arr['post_author'] = $args['author'];
		}
		if ( ! empty( $args['date_gmt'] ) ) {
			$post_arr['post_date_gmt'] = $args['date_gmt'];
			$post_arr['post_date'] = get_date_from_gmt( $args['date_gmt'] );
		}

		$this->suspend_kses();
		if ( $is_update ) {
			$post_arr['edit_date'] = true;
			$r = wp_update_post( wp_slash( $post_arr ), true );
		} else {
			$r = wp_insert_post( wp_slash( $post_arr ), true );
		}
		$this->restore_kses();

		return $r;
	}

	/**
	 * Remember whether customize_save is being pretended.
	 *
	 * @link https://core.trac.wordpress.org/ticket/39221#comment:14
	 *
	 * @var bool
	 */
	protected $is_pretending_customize_save_ajax_action = false;

	/**
	 * Previous value for $_REQUEST['action'].
	 *
	 * @link https://core.trac.wordpress.org/ticket/39221#comment:14
	 *
	 * @var string
	 */
	protected $previous_request_action_param;

	/**
	 * Start pretending customize_save Ajax action.
	 *
	 * Add workaround for failure to save changes to option settings when publishing changeset outside of customizer.
	 *
	 * @link https://core.trac.wordpress.org/ticket/39221#comment:14
	 * @see \WP_Customize_Manager::doing_ajax()
	 *
	 * @param string   $new_status     New post status.
	 * @param string   $old_status     Old post status.
	 * @param \WP_Post $changeset_post Changeset post object.
	 */
	function start_pretending_customize_save_ajax_action( $new_status, $old_status, $changeset_post ) {
		$is_publishing_changeset = ( 'customize_changeset' === $changeset_post->post_type && 'publish' === $new_status && 'publish' !== $old_status );
		$is_customize_save_action = ( isset( $_REQUEST['action'] ) && 'customize_save' === $_REQUEST['action'] );
		if ( ! $is_publishing_changeset || $is_customize_save_action ) {
			return;
		}
		$this->is_pretending_customize_save_ajax_action = true;
		$this->previous_request_action_param = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : null;
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['action'] = 'customize_save';
	}

	/**
	 * Finish pretending customize_save Ajax action.
	 *
	 * Clean up workaround for failure to save changes to option settings when publishing changeset outside of customizer.
	 *
	 * @link https://core.trac.wordpress.org/ticket/39221#comment:14
	 * @see \WP_Customize_Manager::doing_ajax()
	 *
	 * @param string   $new_status     New post status.
	 * @param string   $old_status     Old post status.
	 * @param \WP_Post $changeset_post Changeset post object.
	 */
	function finish_pretending_customize_save_ajax_action( $new_status, $old_status, $changeset_post ) {
		$is_publishing_changeset = ( 'customize_changeset' === $changeset_post->post_type && 'publish' === $new_status && 'publish' !== $old_status );
		if ( ! $is_publishing_changeset || ! $this->is_pretending_customize_save_ajax_action ) {
			return;
		}
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['action'] = $this->previous_request_action_param;
		$this->is_pretending_customize_save_ajax_action = false;
	}

	/**
	 * Re-map customize meta cap to edit_theme_options primitive cap.
	 *
	 * @param array  $caps All caps.
	 * @param string $cap  Requested cap.
	 *
	 * @return array All caps.
	 */
	public function remap_customize_meta_cap( $caps, $cap ) {
		$post_type_obj = get_post_type_object( static::SLUG );

		/*
		 * This remap_customize_meta_cap method runs at map_meta_cap priority 5 and so here we just-in-time remove
		 * the unnecessary WP_Customize_Manager::grant_edit_post_capability_for_changeset() method added as a
		 * map_meta_cap filter with priority 10. This method is added during autosave request in
		 * WP_Customize_Manager::save_changeset_post() function in 4.9, but the logic in this
		 * remap_customize_meta_cap method in Customize Snapshots makes the core function obsolete.
		 */
		remove_filter(
			'map_meta_cap',
			array( $this->snapshot_manager->get_customize_manager(), 'grant_edit_post_capability_for_changeset' ),
			10
		);

		if ( isset( $post_type_obj->cap->$cap ) && 'customize' === $post_type_obj->cap->$cap ) {
			foreach ( $caps as &$required_cap ) {
				if ( 'customize' === $required_cap ) {
					$required_cap = 'edit_theme_options';
				}
			}
		}
		return $caps;
	}

	/**
	 * Add the customize_publish capability to users who can edit_theme_options by default.
	 *
	 * @param array $allcaps An array of all the user's capabilities.
	 * @param array $caps    Actual capabilities for meta capability.
	 * @return array All caps.
	 */
	public function filter_user_has_cap( $allcaps, $caps ) {
		if ( ! empty( $allcaps['edit_theme_options'] ) ) {
			$allcaps['customize_publish'] = true;
		}

		// Grant all customize snapshot caps which weren't explicitly disallowed to users who can customize.
		if ( isset( $caps[0] ) && false !== strpos( $caps[0], static::SLUG ) ) {
			$post_type_obj = get_post_type_object( static::SLUG );
			$primitive_caps = array_flip( (array) $post_type_obj->cap );
			unset( $primitive_caps['do_not_allow'] );
			foreach ( array_keys( $primitive_caps ) as $granted_cap ) {
				$allcaps[ $granted_cap ] = current_user_can( 'customize' );
			}

			if ( ! current_user_can( 'customize_publish' ) || empty( $allcaps['customize_publish'] ) ) {
				$allcaps[ $post_type_obj->cap->publish_posts ] = false;
			}
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$allcaps[ $post_type_obj->cap->edit_others_posts ] = false;
			}
			if ( ! current_user_can( 'delete_others_posts' ) ) {
				$allcaps[ $post_type_obj->cap->delete_others_posts ] = false;
			}
		}

		return $allcaps;
	}

	/**
	 * Disable the revision revert UI for published posts.
	 */
	public function disable_revision_ui_for_published_posts() {
		if ( 'publish' !== get_post_status() || static::SLUG !== get_post_type() ) {
			return;
		}
		?>
		<style>
			.restore-revision.button {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * Hide publishing actions that are no longer relevant when a snapshot is published.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function hide_disabled_publishing_actions( $post ) {
		if ( 'publish' !== $post->post_status || static::SLUG !== $post->post_type ) {
			return;
		}
		?>
		<style>
			#misc-publishing-actions .misc-pub-post-status,
			#misc-publishing-actions .misc-pub-visibility,
			#misc-publishing-actions .misc-pub-curtime,
			.submitbox #publish {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * Add snapshot bulk actions.
	 *
	 * @param array $bulk_actions actions.
	 *
	 * @return mixed
	 */
	public function add_snapshot_bulk_actions( $bulk_actions ) {
		$bulk_actions['merge_snapshot'] = __( 'Merge Changesets', 'customize-snapshots' );
		return $bulk_actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect_to url to redirect to.
	 * @param string $do_action   current action.
	 * @param array  $post_ids    post ids.
	 *
	 * @return string url.
	 */
	public function handle_snapshot_merge( $redirect_to, $do_action, $post_ids ) {
		if ( 'merge_snapshot' !== $do_action ) {
			return $redirect_to;
		}
		$posts = array_map( 'get_post', $post_ids );
		$posts = array_filter( $posts );
		if ( count( $posts ) <= 1 ) {
			return empty( $redirect_to ) ? add_query_arg( array(
				'merge-error' => 1,
			) ) : add_query_arg( array(
				'merge-error' => 1,
			), $redirect_to );
		}
		$post_id = $this->merge_snapshots( $posts );
		$redirect_to = get_edit_post_link( $post_id, 'raw' );
		if ( $redirect_to ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}
		return $post_id;
	}

	/**
	 * Merge two or more snapshots
	 *
	 * @param array $post_ids post id array.
	 *
	 * @return int Changeset post id.
	 */
	public function merge_snapshots( $post_ids ) {
		$posts = array_map( 'get_post', $post_ids );
		usort( $posts, function( $a, $b ) {
			$compare_a = $a->post_modified;
			$compare_b = $b->post_modified;
			if ( '0000-00-00 00:00:00' === $compare_a ) {
				$compare_a = $a->post_date;
			}
			if ( '0000-00-00 00:00:00' === $compare_b ) {
				$compare_b = $b->post_date;
			}
			return strtotime( $compare_a ) - strtotime( $compare_b );
		} );

		$snapshot_post_data = array();
		foreach ( $posts as $post ) {
			$snapshot_post_data[] = array(
				'data' => $this->get_post_content( $post ),
				'uuid' => $post->post_name,
			);
		}
		$snapshots_data = wp_list_pluck( $snapshot_post_data, 'data' );
		$data_size = count( $snapshots_data );
		$conflict_keys  = array();

		/*
		 * This iterates all $snapshots_data and extract conflict keys
		 */
		for ( $i = 0; $i < $data_size; $i ++ ) {
			$copy_snapshots_data = $snapshots_data;
			$current_keys = array_keys( $snapshots_data[ $i ] );
			unset( $copy_snapshots_data[ $i ] );
			$temp_other_keys = array_keys( call_user_func_array( 'array_merge', $copy_snapshots_data ) );
			$common_keys = array_intersect( $temp_other_keys, $current_keys );
			$conflict_keys = array_merge( $conflict_keys, $common_keys );
		}
		$conflict_keys = array_flip( $conflict_keys );
		$merged_snapshot_data = call_user_func_array( 'array_merge', $snapshots_data );

		foreach ( $conflict_keys as $key => $i ) {
			$original_values = array();
			foreach ( $snapshot_post_data as $post_data ) {
				if ( isset( $post_data['data'][ $key ] ) ) {
					$original_values[] = array(
						'uuid' => $post_data['uuid'],
						'value' => $post_data['data'][ $key ]['value'],
					);
				}
			}
			$merged_snapshot_data[ $key ]['merge_conflict'] = $original_values;
		}
		$post_id = $this->save( array(
			'uuid' => wp_generate_uuid4(),
			'status' => 'draft',
			'data' => $merged_snapshot_data,
			'date_gmt' => gmdate( 'Y-m-d H:i:s' ),
		) );
		return $post_id;
	}

	/**
	 * Show admin notice in case of merge error
	 */
	public function admin_show_merge_error() {
		if ( ! isset( $_REQUEST['merge-error'] ) ) { // WPCS: input var ok. CSRF ok.
			return;
		}
		$error = array(
			1 => __( 'At-least two changesets required for merge.', 'customize-snapshots' ),
		);
		$error_code = intval( $_REQUEST['merge-error'] ); // WPCS: input var ok.
		if ( ! isset( $error[ $error_code ] ) ) {
			return;
		}
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error[ $error_code ] ) );
	}

	/**
	 * Filter settings out of post content, if they were removed in the meta box.
	 *
	 * In each snapshot's edit page, there are JavaScript-controlled links to remove each setting.
	 * On clicking a setting, the JS sets a hidden input field with that setting's ID.
	 * And these settings appear in $_POST as the array 'customize_snapshot_remove_settings.'
	 * So look for these removed settings in that array, on saving.
	 * And possibly filter out those settings from the post content.
	 *
	 * @param String $content Post content to filter.
	 * @return String $content Post content, possibly filtered.
	 */
	public function filter_out_settings_if_removed_in_metabox( $content ) {
		global $post;
		$key_for_settings = static::SLUG . '_remove_settings';
		$post_type_object = get_post_type_object( static::SLUG );

		$should_filter_content = (
			isset( $post->post_status )
			&&
			( 'publish' !== $post->post_status )
			&&
			current_user_can( $post_type_object->cap->edit_post, $post->ID )
			&&
			( static::SLUG === $post->post_type )
			&&
			! empty( $_POST[ $key_for_settings ] ) // WPCS: input var ok.
			&&
			is_array( $_POST[ $key_for_settings ] ) // WPCS: input var ok. CSRF ok.
			&&
			isset( $_POST[ static::SLUG ] ) // WPCS: input var ok.
			&&
			wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ static::SLUG ] ) ), static::SLUG . '_settings' ) // WPCS: input var ok.
			&&
			! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		);

		if ( ! $should_filter_content ) {
			return $content;
		}

		$data = json_decode( wp_unslash( $content ), true );
		foreach ( $_POST[ $key_for_settings ] as $setting_id_to_unset ) { // WPCS: input var ok. Sanitization ok, since array items only to be used to unset array keys.
			unset( $data[ $setting_id_to_unset ] );
		}
		$content = Customize_Snapshot_Manager::encode_json( $data );

		return $content;
	}

	/**
	 * Get customizer session state query vars.
	 *
	 * @param int $post_id Post id.
	 * @return array $preview_url_query_vars Preview url query vars.
	 */
	public function get_customizer_state_query_vars( $post_id ) {
		$preview_url_query_vars = get_post_meta( $post_id, '_preview_url_query_vars', true );

		if ( ! is_array( $preview_url_query_vars ) ) {
			$preview_url_query_vars = array();
		}

		return $preview_url_query_vars;
	}

	/**
	 * Set customizer session state query vars.
	 *
	 * Supplied query vars are validated and sanitized.
	 *
	 * @param int   $post_id    Post id.
	 * @param array $query_vars Post id.
	 * @return array Sanitized query vars.
	 */
	public function set_customizer_state_query_vars( $post_id, $query_vars ) {
		$stored_query_vars = array();
		$autofocus_query_vars = array( 'autofocus[panel]', 'autofocus[section]', 'autofocus[control]' );

		$wp_customize = $this->snapshot_manager->ensure_customize_manager();

		foreach ( wp_array_slice_assoc( $query_vars, $autofocus_query_vars ) as $key => $value ) {
			if ( preg_match( '/^[a-z|\[|\]|_|\-|0-9]+$/', $value ) ) {
				$stored_query_vars[ $key ] = $value;
			}
		}
		if ( ! empty( $query_vars['url'] ) && wp_validate_redirect( $query_vars['url'] ) ) {
			$stored_query_vars['url'] = esc_url_raw( $query_vars['url'] );
		}
		if ( isset( $query_vars['device'] ) && in_array( $query_vars['device'], array_keys( $wp_customize->get_previewable_devices() ), true ) ) {
			$stored_query_vars['device'] = $query_vars['device'];
		}
		if ( isset( $query_vars['scroll'] ) && is_int( $query_vars['scroll'] ) ) {
			$stored_query_vars['scroll'] = $query_vars['scroll'];
		}
		if ( isset( $query_vars['previewing_theme'] ) ) {
			$theme = $wp_customize->get_stylesheet();
			$stored_query_vars['theme'] = $query_vars['previewing_theme'] ? $theme : '';
		}
		update_post_meta( $post_id, '_preview_url_query_vars', $stored_query_vars );
		return $stored_query_vars;
	}

	/**
	 * Get frontend view link.
	 *
	 * Returns URL to frontend with customize_changeset_uuid param supplied.
	 * If the changeset was saved in the customizer then the URL being previewed
	 * will serve as the base URL as opposed to the home URL as normally.
	 *
	 * @see Post_Type::filter_post_type_link()
	 * @param int|\WP_Post $post Changeset post.
	 * @return string URL.
	 */
	public function get_frontend_view_link( $post ) {
		$post = get_post( $post );
		$preview_url_query_vars = $this->get_customizer_state_query_vars( $post->ID );
		$base_url = isset( $preview_url_query_vars['url'] ) ? $preview_url_query_vars['url'] : home_url( '/' );
		$current_theme = get_stylesheet();
		$args = array(
			static::FRONT_UUID_PARAM_NAME => $post->post_name,
		);

		if ( isset( $preview_url_query_vars['theme'] ) && $current_theme !== $preview_url_query_vars['theme'] ) {
			$args = array_merge( $args, array(
				'customize_theme' => $preview_url_query_vars['theme'],
			) );
		}

		return add_query_arg( $args, $base_url );
	}

	/**
	 * Display snapshot save error on post list table.
	 *
	 * @param array    $states Display states.
	 * @param \WP_Post $post   Post object.
	 *
	 * @return mixed
	 */
	public function display_post_states( $states, $post ) {
		if ( static::SLUG !== $post->post_type ) {
			return $states;
		}
		$maybe_error = get_post_meta( $post->ID, 'snapshot_error_on_publish', true );
		if ( $maybe_error ) {
			$states['snapshot_error'] = __( 'Error on publish', 'customize-snapshots' );
		}
		return $states;
	}

	/**
	 * Show an admin notice when publishing fails and the post gets kicked back to pending.
	 */
	public function show_publish_error_admin_notice() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$current_screen = get_current_screen();
		if ( ! $current_screen || static::SLUG !== $current_screen->id || 'post' !== $current_screen->base ) {
			return;
		}
		if ( ! isset( $_REQUEST['snapshot_error_on_publish'] ) ) { // WPCS: input var ok. CSRF ok.
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'Failed to publish snapshot due to an error with saving one of its settings. This may be due to a theme or plugin having been changed since the snapshot was created. See below.', 'customize-snapshots' ); ?></p>
		</div>
		<?php
	}
}
