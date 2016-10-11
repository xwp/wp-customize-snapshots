<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Class
 *
 * Implements snapshots for Customizer settings
 *
 * @package CustomizeSnapshots
 */
class Post_Type {

	/**
	 * Post type.
	 *
	 * @type string
	 */
	const SLUG = 'customize_snapshot';

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
	 * Register post type.
	 */
	public function register() {

		$labels = array(
			'name'               => _x( 'Snapshots', 'post type general name', 'customize-snapshots' ),
			'singular_name'      => _x( 'Snapshot', 'post type singular name', 'customize-snapshots' ),
			'menu_name'          => _x( 'Snapshots', 'admin menu', 'customize-snapshots' ),
			'name_admin_bar'     => _x( 'Snapshot', 'add new on admin bar', 'customize-snapshots' ),
			'add_new'            => _x( 'Add New', 'Customize Snapshot', 'customize-snapshots' ),
			'add_new_item'       => __( 'Add New Snapshot', 'customize-snapshots' ),
			'new_item'           => __( 'New Snapshot', 'customize-snapshots' ),
			'edit_item'          => __( 'Inspect Snapshot', 'customize-snapshots' ),
			'view_item'          => __( 'View Snapshot', 'customize-snapshots' ),
			'all_items'          => __( 'All Snapshots', 'customize-snapshots' ),
			'search_items'       => __( 'Search Snapshots', 'customize-snapshots' ),
			'not_found'          => __( 'No snapshots found.', 'customize-snapshots' ),
			'not_found_in_trash' => __( 'No snapshots found in Trash.', 'customize-snapshots' ),
		);

		$args = array(
			'labels' => $labels,
			'description' => __( 'Customize Snapshots.', 'customize-snapshots' ),
			'public' => true,
			'publicly_queryable' => false,
			'query_var' => false,
			'exclude_from_search' => true,
			'show_ui' => true,
			'show_in_nav_menus' => false,
			'show_in_menu' => true,
			'show_in_admin_bar' => false,
			'map_meta_cap' => true,
			'hierarchical' => false,
			'delete_with_user' => false,
			'menu_position' => null,
			'supports' => array( 'title', 'author', 'revisions' ),
			'capability_type' => static::SLUG,
			'capabilities' => array(
				'create_posts' => 'do_not_allow',
			),
			'rewrite' => false,
			'show_in_customizer' => false, // Prevent inception.
			'show_in_rest' => true,
			'rest_base' => 'customize_snapshots',
			'rest_controller_class' => __NAMESPACE__ . '\\Snapshot_REST_API_Controller',
			'customize_snapshot_post_type_obj' => $this,
			'menu_icon' => 'dashicons-camera',
			'register_meta_box_cb' => array( $this, 'setup_metaboxes' ),
		);

		register_post_type( static::SLUG, $args );

		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_action( 'add_meta_boxes_' . static::SLUG, array( $this, 'remove_slug_metabox' ), 100 );
		add_action( 'load-revision.php', array( $this, 'suspend_kses_for_snapshot_revision_restore' ) );
		add_filter( 'get_the_excerpt', array( $this, 'filter_snapshot_excerpt' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_post_name_in_insert_data' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_publish_error_admin_notice' ) );
		add_action( 'post_submitbox_minor_actions', array( $this, 'hide_disabled_publishing_actions' ) );
		add_filter( 'content_save_pre', array( $this, 'filter_selected_conflict_setting' ), 11 ); // 11 because remove customize setting is set on 10.
		add_action( 'admin_print_scripts-revision.php', array( $this, 'disable_revision_ui_for_published_posts' ) );

		// Version check for bulk action.
		if ( version_compare( get_bloginfo( 'version' ), '4.7', '>=' ) ) {
			add_filter( 'bulk_actions-edit-' . self::SLUG, array( $this, 'add_snapshot_bulk_actions' ) );
			add_filter( 'handle_bulk_actions-edit-' . self::SLUG, array( $this, 'handle_snapshot_bulk_actions' ), 10, 3 );
		} else {
			add_action( 'admin_print_footer_scripts-edit.php', array( $this, 'snapshot_merge_print_script' ) );
			add_action( 'load-edit.php', array( $this, 'handle_snapshot_bulk_actions_workaround' ) );
		}
		add_action( 'admin_notices', array( $this, 'admin_show_merge_error' ) );
	}

	/**
	 * Filter post link.
	 *
	 * @param string   $url  URL.
	 * @param \WP_Post $post Post.
	 * @return string URL.
	 */
	public function filter_post_type_link( $url, $post ) {
		if ( self::SLUG === $post->post_type ) {
			$url = add_query_arg(
				array( 'customize_snapshot_uuid' => $post->post_name ),
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
				'customize_snapshot_uuid' => $post->post_name,
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
						esc_html__( 'Preview Snapshot', 'customize-snapshots' )
					),
				),
				$actions
			);
		} else {
			unset( $actions['inline hide-if-no-js'] );

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
		return $actions;
	}

	/**
	 * Preserve the post_name when submitting a snapshot for review.
	 *
	 * @see wp_insert_post()
	 * @link https://github.com/xwp/wordpress-develop/blob/831a186108983ade4d647124d4e56e09aa254704/src/wp-includes/post.php#L3134-L3137
	 *
	 * @param array $post_data          Post data.
	 * @param array $original_post_data Original post data.
	 * @return array Post data.
	 */
	public function preserve_post_name_in_insert_data( $post_data, $original_post_data ) {
		if ( empty( $post_data['post_type'] ) || static::SLUG !== $post_data['post_type'] ) {
			return $post_data;
		}
		if ( empty( $post_data['post_name'] ) && 'pending' === $post_data['post_status'] ) {
			$post_data['post_name'] = $original_post_data['post_name'];
		}
		return $post_data;
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
			echo sprintf( esc_html__( 'This snapshot was made when a different theme was active (%1$s), so currently it cannot be edited.', 'customize-snapshots' ), esc_html( $snapshot_theme ) );
			echo '</p>';
		} elseif ( 'publish' !== $post->post_status ) {
			echo '<p>';
			$args = array(
				'customize_snapshot_uuid' => $post->post_name,
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
				esc_html__( 'Preview Snapshot', 'customize-snapshots' )
			);
			echo '</p>';
		}

		echo '<hr>';

		ksort( $snapshot_content );
		wp_nonce_field( static::SLUG . '_resolve_settings', static::SLUG.'_merge_conflict' );
		echo '<ul id="snapshot-settings">';
		foreach ( $snapshot_content as $setting_id => $setting_params ) {
			if ( ! isset( $setting_params['value'] ) && ! isset( $setting_params['publish_error'] ) ) {
				continue;
			}
			$value = isset( $setting_params['value'] ) ? $setting_params['value'] : '';
			echo '<li>';
			echo '<details open class="snapshot-setting-value">';
			echo '<summary><code>' . esc_html( $setting_id ) . '</code> ';
			echo '<span class="snapshot-setting-actions">';
			$this->resolve_conflict_markup( $setting_id, $setting_params, $snapshot_content );
			echo '</span>';
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

			echo '<div id="snapshot-setting-preview-' . esc_attr( $setting_id ) . '">';
			echo $preview; // WPCS: xss ok.
			echo '</div>';
			echo '</details>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Find a snapshot post by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return int|null Post ID or null if not found.
	 */
	public function find_post( $uuid ) {
		add_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );
		$query = new \WP_Query( array(
			'name' => $uuid,
			'posts_per_page' => 1,
			'post_type' => static::SLUG,
			'post_status' => get_post_stati(),
			'no_found_rows' => true,
			'ignore_sticky_posts' => true,
			'cache_results' => false,
		) );
		$posts = $query->posts;
		remove_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );

		$post = array_shift( $posts );
		if ( $post ) {
			return $post->ID;
		} else {
			return null;
		}
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
			if ( isset( $args['post_date'], $args['edit_date'], $args['post_date_gmt'] ) ) {
				$post_arr['post_date'] = $args['post_date'];
				$post_arr['edit_date'] = $args['edit_date'];
				$post_arr['post_date_gmt'] = $args['post_date_gmt'];
			}
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
		}

		$this->suspend_kses();
		if ( $is_update ) {
			$r = wp_update_post( wp_slash( $post_arr ), true );
		} else {
			$r = wp_insert_post( wp_slash( $post_arr ), true );
		}
		$this->restore_kses();

		return $r;
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
		if ( ! $current_screen || 'customize_snapshot' !== $current_screen->id || 'post' !== $current_screen->base ) {
			return;
		}
		if ( ! isset( $_REQUEST['snapshot_error_on_publish'] ) ) {
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'Failed to publish snapshot due to an error with saving one of its settings. This may be due to a theme or plugin having been changed since the snapshot was created. See below.', 'customize-snapshots' ) ?></p>
		</div>
		<?php
	}

	/**
	 * Disable the revision revert UI for published posts.
	 */
	public function disable_revision_ui_for_published_posts() {
		if ( 'publish' !== get_post_status() || self::SLUG !== get_post_type() ) {
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
		if ( 'publish' !== $post->post_status || self::SLUG !== $post->post_type ) {
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
	public function handle_snapshot_bulk_actions( $redirect_to, $do_action, $post_ids ) {
		if ( 'merge_snapshot' !== $do_action ) {
			return $redirect_to;
		}
		$posts = array_map( 'get_post', $post_ids );
		if ( count( $posts ) <= 1 ) {
			return empty( $redirect_to ) ? add_query_arg( array( 'merge-error' => 1 ) ) : add_query_arg( array( 'merge-error' => 1 ), $redirect_to );
		}

		usort( $posts, function( $a, $b ) {
			$compare_a = $a->post_modified;
			$compare_b = $b->post_modified;
			if ( '0000-00-00 00:00:00' === $compare_a ) {
				$compare_a = $a->post_date;
			}
			if ( '0000-00-00 00:00:00' === $compare_b ) {
				$compare_a = $b->post_date;
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
		$conflict_keys = call_user_func_array( 'array_intersect_key', $snapshots_data );
		$merged_snapshot_data = call_user_func_array( 'array_merge', $snapshots_data );

		foreach ( $conflict_keys as $key => $conflict_val ) {
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
			$last_value = end( $original_values );
			$merged_snapshot_data[ $key ]['selected_uuid'] = $last_value['uuid'];
		}
		$post_id = $this->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'status' => 'draft',
			'data' => $merged_snapshot_data,
			'post_date' => current_time( 'mysql', false ),
			'post_date_gmt' => current_time( 'mysql', true ),
		) );
		$redirect_to = get_edit_post_link( $post_id, 'raw' );
		return $redirect_to;
	}

	/**
	 * Insert script for adding merge snapshot bulk action polyfill.
	 */
	public function snapshot_merge_print_script() {
		global $post_type;
		if ( self::SLUG === $post_type ) {
			?>
			<script type="text/javascript">
				jQuery( function( $ ) {
					var optionText = <?php echo wp_json_encode( __( 'Merge Snapshot', 'customize-snapshots' ) ); ?>;
					$( 'select[name="action"], select[name="action2"]' ).each( function() {
						var option = $( '<option>', {
							text: optionText,
							value: 'merge_snapshot'
						} );
						$( this ).append( option );
					} );
				} );
			</script>
			<?php
		}
	}

	/**
	 * Handles bulk action for 4.6.x and older version.
	 */
	public function handle_snapshot_bulk_actions_workaround() {
		$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
		$action = $wp_list_table->current_action();
		if ( 'merge_snapshot' !== $action || ( isset( $_REQUEST['post_type'] ) && self::SLUG !== wp_unslash( $_REQUEST['post_type'] ) ) ) {
			return;
		}
		check_admin_referer( 'bulk-posts' );
		$post_ids = array_map( 'intval', $_REQUEST['post'] );
		if ( empty( $post_ids ) ) {
			return;
		}
		$redirect_url = $this->handle_snapshot_bulk_actions( wp_get_referer(), 'merge_snapshot', $post_ids );
		if ( ! empty( $redirect_url ) ) {
			wp_safe_redirect( $redirect_url );
			exit;
		}
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
	 * Generate resolve conflict markup.
	 * This will add thickbox with radio button to select between conflicted setting values.
	 *
	 * @param string $setting_id       setting id.
	 * @param array  $value            setting value.
	 * @param array  $snapshot_content snapshot post-content.
	 */
	public function resolve_conflict_markup( $setting_id, $value, $snapshot_content ) {
		if ( isset( $value['merge_conflict'] ) ) {
			$setting_id_key = str_replace( ']', '\\]', str_replace( '[', '\\[', $setting_id ) );
			echo '<a href="#TB_inline?width=600&height=550&inlineId=snapshot-resolve-' . esc_attr( $setting_id_key ) . '" id="' . esc_attr( $setting_id ) . '" class="snapshot-resolve-setting-conflict remove thickbox">' . esc_html__( 'Resolve conflict', 'customize-snapshots' ) . '</a>';
			echo '<div id="snapshot-resolve-' . esc_attr( $setting_id ) . '" style="display:none;">';
			echo '<ul>';
			foreach ( $value['merge_conflict'] as $conflicted_data ) {
				echo '<li>';
				echo '<details open>';
				echo '<summary>';
				$input = '<input type="radio" class="snapshot-resolved-settings" data-setting-value-selector="snapshot-setting-preview-' . $setting_id_key . '"';
				$input .= 'name="' . self::SLUG . '_resolve_conflict_uuid[' . array_search( $setting_id, array_keys( $snapshot_content ) ) . ']" value=' .
				                                   wp_json_encode( array(
					                                   'setting_id' => $setting_id,
					                                   'uuid' => $conflicted_data['uuid'],
				                                   ) ) . ' ';
				$input .= checked( $value['selected_uuid'], $conflicted_data['uuid'], false ) . '>';
				echo $input; // WPCS: xss ok.
				echo '<code> ' . esc_html( $conflicted_data['uuid'] ) . ' </code></summary>';

				// @Todo change below code with get_printable_setting_value() when https://github.com/xwp/wp-customize-snapshots/pull/71 get merged.
				if ( '' === $conflicted_data['value'] ) {
					$preview = '<p><em>' . esc_html__( '(Empty string)', 'customize-snapshots' ) . '</em></p>';
				} elseif ( is_string( $conflicted_data['value'] ) || is_numeric( $conflicted_data['value'] ) ) {
					$preview = '<p>' . esc_html( $conflicted_data['value'] ) . '</p>';
				} elseif ( is_bool( $value ) ) {
					$preview = '<p>' . wp_json_encode( $conflicted_data['value'] ) . '</p>';
				} else {
					$preview = sprintf( '<pre class="pre">%s</pre>', esc_html( Customize_Snapshot_Manager::encode_json( $conflicted_data['value'] ) ) );
				}
				echo '<div class="snapshot-conflict-setting-data">';
				echo $preview; // WPCS: xss ok.
				echo '</div>';
				echo '</details></input></li>';
			}
			echo '</ul>';
			echo '</div>';
		} // End if().
	}

	/**
	 * Change conflicted value in post_content, if they were changed in the meta box.
	 *
	 * @param String $content Post content to filter.
	 * @return String $content Post content, possibly filtered.
	 */
	public function filter_selected_conflict_setting( $content ) {
		global $post;
		$key_for_selected_resolved_setting = static::SLUG . '_resolve_conflict_uuid';
		$post_type_object = get_post_type_object( static::SLUG );

		$should_filter_for_resolve_conflict = (
			isset( $post->post_status )
			&&
			( 'publish' !== $post->post_status )
			&&
			current_user_can( $post_type_object->cap->edit_post, $post->ID )
			&&
			( static::SLUG === $post->post_type )
			&&
			! empty( $_REQUEST[ $key_for_selected_resolved_setting ] )
			&&
			is_array( $_REQUEST[ $key_for_selected_resolved_setting ] )
			&&
			isset( $_REQUEST[ static::SLUG . '_merge_conflict' ] )
			&&
			wp_verify_nonce( $_REQUEST[ static::SLUG . '_merge_conflict' ], static::SLUG . '_resolve_settings' )
			&&
			! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		);

		if ( ! $should_filter_for_resolve_conflict ) {
			return $content;
		}
		$setting_ids_to_unset = wp_unslash( $_REQUEST[ $key_for_selected_resolved_setting ] );
		$snapshot_data = json_decode( wp_unslash( $content ), true );
		foreach ( $setting_ids_to_unset as $i => $data ) {
			$data = json_decode( $data, true );
			if ( isset( $data['setting_id'], $data['uuid'], $snapshot_data[ $data['setting_id'] ], $snapshot_data[ $data['setting_id'] ]['merge_conflict'] ) ) {
				$setting_key = $data['setting_id'];
				if ( isset( $snapshot_data[ $setting_key ]['selected_uuid'] ) && $snapshot_data[ $setting_key ]['selected_uuid'] === $data['uuid'] ) {
					// No change.
					continue;
				}
				$snapshot_data[ $setting_key ]['selected_uuid'] = $data['uuid'];
				foreach ( $snapshot_data[ $setting_key ]['merge_conflict'] as $conflict_data ) {
					if ( isset( $conflict_data['uuid'], $conflict_data['value'] ) && $conflict_data['uuid'] === $data['uuid'] ) {
						$snapshot_data[ $setting_key ]['value'] = $conflict_data['value'];
						break;
					}
				}
			}
		}
		$content = Customize_Snapshot_Manager::encode_json( $snapshot_data );
		return $content;
	}
}
