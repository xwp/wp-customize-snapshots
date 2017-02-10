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
	 * Calls common hooks Actions and filters
	 */
	public function hooks() {
		add_action( 'add_meta_boxes_' . static::SLUG, array( $this, 'remove_slug_metabox' ), 100 );
		add_action( 'load-revision.php', array( $this, 'suspend_kses_for_snapshot_revision_restore' ) );
		add_filter( 'get_the_excerpt', array( $this, 'filter_snapshot_excerpt' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 2 );
		add_action( 'post_submitbox_minor_actions', array( $this, 'hide_disabled_publishing_actions' ) );
		add_filter( 'content_save_pre', array( $this, 'filter_out_settings_if_removed_in_metabox' ), 10 );
		add_action( 'admin_print_scripts-revision.php', array( $this, 'disable_revision_ui_for_published_posts' ) );
		add_action( 'admin_notices', array( $this, 'admin_show_merge_error' ) );
		add_action( 'wp_ajax_snapshot_fork', array( $this, 'handle_snapshot_fork' ) );
		add_action( 'admin_footer-post.php', array( $this, 'snapshot_admin_script_template' ) );
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );
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
		add_action( 'admin_menu',array( $this, 'add_admin_menu_item' ) );
		add_filter( 'map_meta_cap', array( $this, 'remap_customize_meta_cap' ), 5, 4 );
		add_filter( 'bulk_actions-edit-' . static::SLUG, array( $this, 'add_snapshot_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . static::SLUG, array( $this, 'handle_snapshot_merge' ), 10, 3 );
		add_action( 'admin_print_styles-edit.php', array( $this, 'hide_add_new_changeset_button' ) );
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
	 * Add admin menu item.
	 */
	public function add_admin_menu_item() {
		$post_type_object = get_post_type_object( static::SLUG );
		$capability = $post_type_object->cap->edit_posts;
		$page_title = $post_type_object->labels->name;
		$menu_title = $post_type_object->labels->name;
		$menu_slug = 'edit.php?post_type=' . static::SLUG;
		add_theme_page( $page_title, $menu_title, $capability, $menu_slug );
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
			$url = add_query_arg(
				array( static::FRONT_UUID_PARAM_NAME => $post->post_name ),
				home_url( '/' )
			);
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

		$id = static::SLUG . '-fork';
		$title = __( 'Forked Snapshots', 'customize-snapshots' );
		$callback = array( $this, 'render_forked_metabox' );
		$screen = static::SLUG;
		$context = 'normal';
		$priority = 'default';
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
		if ( ! isset( $_GET['revision'] ) ) { // WPCS: input var ok.
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'restore' !== $_GET['action'] ) { // WPCS: input var ok, sanitization ok.
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
			$excerpt = '<ol>';
			foreach ( $this->get_post_content( $post ) as $setting_id => $setting_params ) {
				if ( ! isset( $setting_params['dirty'] ) || true === $setting_params['dirty'] ) {
					$excerpt .= sprintf( '<li><code>%s</code></li>', esc_attr( $setting_id ) );
				}
			}
			$excerpt .= '</ol>';
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
			$args = array(
				static::CUSTOMIZE_UUID_PARAM_NAME => $post->post_name,
			);
			$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );
			$actions = array_merge(
				array(
					'customize' => sprintf( '<a href="%s">%s</a>', esc_url( $customize_url ), esc_html__( 'Customize', 'customize-snapshots' ) ),
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
		} else {
			if ( isset( $actions['edit'] ) ) {
				$actions['edit'] = sprintf(
					'<a href="%s" aria-label="%s">%s</a>',
					get_edit_post_link( $post->ID, 'display' ),
					/* translators: %s: post title */
					esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'customize-snapshots' ), get_the_title( $post->ID ) ) ),
					__( 'View', 'customize-snapshots' )
				);
			}
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

		$fork_markup = sprintf( '<button id="snapshot-fork" class="button button-secondary" data-post-id="%s" data-nonce="%s">%s</button>', esc_attr( $post->ID ), wp_create_nonce( 'snapshot-fork' ), esc_html__( 'Fork', 'customize-snapshots' ) );
		$fork_markup .= '<span class="spinner snapshot-fork-spinner"></span>';
		$snapshot_theme = get_post_meta( $post->ID, '_snapshot_theme', true );

		if ( ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme ) {
			echo '<p>';
			/* translators: 1 is the theme the snapshot was created for */
			echo sprintf( esc_html__( 'This snapshot was made when a different theme was active (%1$s), so currently it cannot be edited.', 'customize-snapshots' ), esc_html( $snapshot_theme ) );
			echo '</p>';
		} elseif ( 'publish' !== $post->post_status ) {
			echo '<p>';
			$args = array(
				static::CUSTOMIZE_UUID_PARAM_NAME => $post->post_name,
			);
			$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );
			echo sprintf(
				'<a href="%s" class="button button-secondary">%s</a> ',
				esc_url( $customize_url ),
				esc_html__( 'Edit in Customizer', 'customize-snapshots' )
			);

			$frontend_view_url = get_permalink( $post->ID );
			echo sprintf(
				'<a href="%s" class="button button-secondary">%s</a> ',
				esc_url( $frontend_view_url ),
				esc_html__( 'Preview Snapshot', 'customize-snapshots' )
			);

			echo $fork_markup; // WPCS: XSS ok.
			echo '</p>';
		} else {
			echo "<p>$fork_markup</p>"; // WPCS: XSS ok.
		}

		echo '<hr>';

		ksort( $snapshot_content );
		wp_nonce_field( static::SLUG . '_settings', static::SLUG, false );
		echo '<ul id="snapshot-settings">';
		foreach ( $snapshot_content as $setting_id => $setting_params ) {
			if ( ! isset( $setting_params['value'] ) && ! isset( $setting_params['publish_error'] ) ) {
				continue;
			}
			$value = isset( $setting_params['value'] ) ? $setting_params['value'] : '';
			echo '<li>';
			echo '<details open>';
			echo '<summary><code>' . esc_html( $setting_id ) . '</code> ';
			echo '<a href="#" id="' . esc_attr( $setting_id ) . '" data-text-restore="' . esc_attr__( 'Restore setting', 'customize-snapshots' ) . '" class="snapshot-toggle-setting-removal remove">' . esc_html__( 'Remove setting', 'customize-snapshots' ) . '</a>';

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
	 * Renders Forked snapshot metabox.
	 *
	 * @param \WP_Post $post current post object.
	 */
	public function render_forked_metabox( $post ) {
		$post_query = new \WP_Query( array(
			'post_parent' => $post->ID,
			'posts_per_page' => 20, // Todo 20 looks fine i don't think people would have that many forks.
			'post_type' => array( static::SLUG ),
			'post_status' => 'any',
		) ); ?>
		<ul id="snapshot-fork-list"><?php
		if ( $post_query->have_posts() ) {
			foreach ( $post_query->get_posts() as $p ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $p ), 'raw' ) . '">' . get_the_title( $p ) . '</a></li>';
			}
		} ?>
		</ul><?php
		if ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			echo '<h2>' . esc_html__( 'Parent:', 'customize-snapshots' ) . ' <a href="' . esc_url( get_edit_post_link( $parent, 'raw' ) ) . '">' . get_the_title( $parent ) . '</a></h2>';
		}
	}

	/**
	 * Find a snapshot post by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return int|null Post ID or null if not found.
	 */
	public function find_post( $uuid ) {
		$this->snapshot_manager->ensure_customize_manager();
		return $this->snapshot_manager->customize_manager->find_changeset_post_id( $uuid );
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
	 * Re-map customize meta cap to edit_theme_options primitive cap.
	 *
	 * @param array  $caps All caps.
	 * @param string $cap  Requested cap.
	 *
	 * @return array All caps.
	 */
	public function remap_customize_meta_cap( $caps, $cap ) {
		$post_type_obj = get_post_type_object( static::SLUG );
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
	 * Display snapshot states post list table.
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
		if ( $post->post_parent ) {
			$states['forked'] = __( 'Forked', 'customize-snapshots' );
		}
		return $states;
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
	 * Hide add new button for customize_changeset post_type.
	 */
	public function hide_add_new_changeset_button() {
		global $typenow;
		if ( static::SLUG === $typenow ) {
			?>
			<style>
				a.page-title-action {
					display: none;
				}
			</style>
			<?php
		}
	}

	/**
	 * Add snapshot bulk actions.
	 *
	 * @param array $bulk_actions actions.
	 *
	 * @return mixed
	 */
	public function add_snapshot_bulk_actions( $bulk_actions ) {
		$bulk_actions['merge_snapshot'] = __( 'Merge Snapshot', 'customize-snapshots' );
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
			return empty( $redirect_to ) ? add_query_arg( array( 'merge-error' => 1 ) ) : add_query_arg( array( 'merge-error' => 1 ), $redirect_to );
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
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
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
		if ( ! isset( $_REQUEST['merge-error'] ) ) {
			return;
		}
		$error = array(
			1 => __( 'At-least two snapshot required for merge.', 'customize-snapshots' ),
		);
		$error_code = intval( $_REQUEST['merge-error'] );
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
	 * And these settings appear in $_REQUEST as the array 'customize_snapshot_remove_settings.'
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
			! empty( $_REQUEST[ $key_for_settings ] )
			&&
			is_array( $_REQUEST[ $key_for_settings ] )
			&&
			isset( $_REQUEST[ static::SLUG ] )
			&&
			wp_verify_nonce( $_REQUEST[ static::SLUG ], static::SLUG . '_settings' )
			&&
			! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		);

		if ( ! $should_filter_content ) {
			return $content;
		}

		$setting_ids_to_unset = $_REQUEST[ $key_for_settings ];
		$data = json_decode( wp_unslash( $content ), true );
		foreach ( $setting_ids_to_unset as $setting_id ) {
			unset( $data[ $setting_id ] );
		}
		$content = Customize_Snapshot_Manager::encode_json( $data );

		return $content;
	}

	/**
	 * Prints admin underscore templates.
	 */
	public function snapshot_admin_script_template() {
		global $post;
		if ( isset( $post->post_type ) && static::SLUG === $post->post_type ) { ?>
			<script type="text/html" id="tmpl-snapshot-fork-item">
				<li><a href="{{data.edit_link}}">{{data.post_title}}</a></li>
			</script>
		<?php }
	}

	/**
	 * Handles snapshot fork ajax request.
	 */
	public function handle_snapshot_fork() {
		if ( ! check_ajax_referer( 'snapshot-fork', 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_request' );
		}

		if ( ! isset( $_POST['ID'] ) || ! intval( $_POST['ID'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'wrong_input' );
		}

		$post_id = intval( $_POST['ID'] );
		$parent_post = get_post( $post_id );
		if ( static::SLUG !== $parent_post->post_type ) {
			status_header( 400 );
			wp_send_json_error( 'invalid-post' );
		}

		$post_type_object = get_post_type_object( static::SLUG );
		if ( ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized_user' );
		}

		$uuid = Customize_Snapshot_Manager::generate_uuid();
		$new_post_arr = array(
			'menu_order' => $parent_post->menu_order,
			'comment_status' => $parent_post->comment_status,
			'ping_status' => $parent_post->ping_status,
			'post_author' => get_current_user_id(),
			'post_content' => $parent_post->post_content,
			'post_excerpt' => $parent_post->post_excerpt,
			'post_mime_type' => $parent_post->post_mime_type,
			'post_parent' => $parent_post->ID,
			'post_password' => $parent_post->post_password,
			'post_status' => 'draft',
			'post_title' => ( $parent_post->post_name === $parent_post->post_title ) ? $uuid : $parent_post->post_title,
			'post_type' => static::SLUG,
			'post_date' => current_time( 'mysql', false ),
			'post_date_gmt' => current_time( 'mysql', true ),
			'post_modified' => current_time( 'mysql', false ),
			'post_modified_gmt' => current_time( 'mysql', true ),
			'post_name' => $uuid,
		);
		$all_meta = get_post_meta( $post_id );
		if ( ! empty( $all_meta ) ) {
			$ignore = array( '_edit_lock', '_edit_last' );
			$new_post_arr['meta_input'] = array();
			foreach ( $all_meta as $key => $val ) {
				if ( ! in_array( $key, $ignore, true ) ) {
					$new_post_arr['meta_input'][ $key ] = array_shift( $val );
				}
			}
		}
		$this->suspend_kses();
		$forked_post_id = wp_insert_post( $new_post_arr );
		$this->restore_kses();
		$forked_post = get_post( $forked_post_id, ARRAY_A );
		$forked_post['edit_link'] = get_edit_post_link( $forked_post_id, 'raw' );
		wp_send_json_success( $forked_post );
	}
}
