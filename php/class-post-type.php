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
	 * Surpress hook call
	 *
	 * @var bool
	 */
	protected $suppress_publish_hook = false;

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
			'supports' => array( 'author', 'revisions' ),
			'capability_type' => static::SLUG,
			'capabilities' => array(
				'create_posts' => 'do_not_allow',
			),
			'rewrite' => false,
			'show_in_customizer' => false,
			'menu_icon' => 'dashicons-camera',
			'register_meta_box_cb' => array( $this, 'setup_metaboxes' ),
		);

		register_post_type( static::SLUG, $args );

		add_action( 'add_meta_boxes_' . static::SLUG, array( $this, 'remove_publish_metabox' ), 100 );
		add_action( 'load-revision.php', array( $this, 'suspend_kses_for_snapshot_revision_restore' ) );
		add_filter( 'bulk_actions-edit-' . static::SLUG, array( $this, 'filter_bulk_actions' ) );
		add_filter( 'get_the_excerpt', array( $this, 'filter_snapshot_excerpt' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_post_name_in_insert_data' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 2 );
		add_action( 'publish_' . static::SLUG, array( $this, 'publish_snapshot' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 );
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
	public function remove_publish_metabox() {
		remove_meta_box( 'slugdiv', static::SLUG, 'normal' );
		$snapshot_theme = get_post_meta( get_the_ID(), '_snapshot_theme', true );
		if ( ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme ) {
			remove_meta_box( 'submitdiv', static::SLUG, 'side' );
		}
		remove_meta_box( 'authordiv', static::SLUG, 'normal' );
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
	 * Remove edit bulk action for snapshots.
	 *
	 * @param array $actions Actions.
	 * @return array Actions.
	 */
	public function filter_bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
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

		$snapshot_theme = get_post_meta( $post->ID, '_snapshot_theme', true );
		$is_snapshot_theme_mismatch = ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme;

		unset( $actions['inline hide-if-no-js'] );
		$post_type_obj = get_post_type_object( static::SLUG );
		if ( 'publish' !== $post->post_status && current_user_can( $post_type_obj->cap->edit_post, $post->ID ) && ! $is_snapshot_theme_mismatch ) {
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

			$frontend_view_url = add_query_arg( array_map( 'rawurlencode', $args ), home_url() );
			$actions = array_merge(
				array(
					'front-view' => sprintf( '<a href="%s">%s</a>', esc_url( $frontend_view_url ), esc_html__( 'Preview Snapshot', 'customize-snapshots' ) ),
				),
				$actions
			);
		} elseif ( isset( $actions['edit'] ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				get_edit_post_link( $post->ID ),
				/* translators: %s: post title */
				esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'customize-snapshots' ), get_the_title( $post->ID ) ) ),
				__( 'View', 'customize-snapshots' )
			);
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
		$post_status_obj = get_post_status_object( $post->post_status );

		echo '<p>';
		echo esc_html__( 'UUID:', 'customize-snapshots' ) . ' <code>' . esc_html( $post->post_name ) . '</code><br>';
		echo sprintf( '%1$s %2$s', esc_html__( 'Status:', 'customize-snapshots' ), esc_html( $post_status_obj->label ) ) . '<br>';
		echo sprintf( '%1$s %2$s %3$s', esc_html__( 'Publish Date:', 'customize-snapshots' ), esc_html( get_the_date( '', $post->ID ) ), esc_html( get_the_time( '', $post->ID ) ) ) . '<br>';
		echo sprintf( '%1$s %2$s %3$s', esc_html__( 'Modified:', 'customize-snapshots' ), esc_html( get_the_modified_date( '' ) ), esc_html( get_the_modified_time( '' ) ) ) . '<br>';
		echo sprintf( '%1$s %2$s', esc_html__( 'Author:', 'customize-snapshots' ), esc_html( get_the_author_meta( 'display_name', $post->post_author ) ) ) . '</p>';
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

			$frontend_view_url = add_query_arg( array_map( 'rawurlencode', $args ), home_url() );
			echo sprintf(
				'<a href="%s" class="button button-secondary">%s</a>',
				esc_url( $frontend_view_url ),
				esc_html__( 'Preview Snapshot', 'customize-snapshots' )
			);
			echo '</p>';
		}

		echo '<hr>';

		ksort( $snapshot_content );
		echo '<ul id="snapshot-settings">';
		foreach ( $snapshot_content as $setting_id => $setting_params ) {
			if ( ! isset( $setting_params['value'] ) && ! isset( $setting_params['publish_error'] ) ) {
				continue;
			}
			$value = isset( $setting_params['value'] ) ? $setting_params['value'] : '';
			echo '<li>';
			echo '<details open>';
			echo '<summary><code>' . esc_html( $setting_id ) . '</code>';

			// Show error message when there was a publishing error.
			if ( isset( $setting_params['publish_error'] ) ) {
				echo '<span class="error-message">';
				echo '<b>' . esc_html__( 'Publish error:', 'customize-snapshots' ) . '</b> ';
				switch ( $setting_params['publish_error'] ) {
					case 'null_value':
						esc_html_e( 'Missing value.', 'customize-snapshots' );
						break;
					case 'setting_object_not_found':
						esc_html_e( 'Unrecognized setting.', 'customize-snapshots' );
						break;
					default:
						echo '<code>' . esc_html( $setting_params['publish_error'] ) . '</code>';
				}
				echo '</span>';
			}

			echo '</summary>';

			if ( is_string( $value ) || is_numeric( $value ) ) {
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

		if ( empty( $args['uuid'] ) || ! Customize_Snapshot_Manager::is_valid_uuid( $args['uuid'] ) ) {
			return new \WP_Error( 'missing_valid_uuid' );
		}

		$post_arr = array(
			'post_name' => $args['uuid'],
			'post_title' => $args['uuid'],
			'post_type' => static::SLUG,
			'meta_input' => array(
				'_snapshot_version' => $this->snapshot_manager->plugin->version,
			),
		);

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
		if ( ! empty( $args['status'] ) ) {
			if ( ! get_post_status_object( $args['status'] ) ) {
				return new \WP_Error( 'bad_status' );
			}
			$post_arr['post_status'] = $args['status'];
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
	 * Publish snapshot changes when snapshot post is being published.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post Post object.
	 */
	public function publish_snapshot( $post_id, $post ) {
		if ( did_action( 'customize_save' ) || $this->suppress_publish_hook ) {
			// Short circuit because customize_save ajax call is changing status.
			return;
		}
		$snapshot_theme = get_post_meta( $post_id, '_snapshot_theme', true );
		if ( ! empty( $snapshot_theme ) && get_stylesheet() !== $snapshot_theme ) {
			// Theme mismatch.
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				wp_update_post( array(
					'ID' => $post->ID,
					'post_status' => 'pending',
				) );
			}
		}
		$this->snapshot_manager->ensure_customize_manager();

		if ( ! did_action( 'customize_register' ) ) {
			/*
			 * When running from CLI or Cron, we have to remove the action because
			 * it will get added with a default priority of 10, after themes and plugins
			 * have already done add_action( 'customize_register' ), resulting in them
			 * being called first at the priority 10. So we manually call the
			 * prerequisite function WP_Customize_Manager::register_controls() and
			 * remove it from being called when the customize_register action fires.
			 */
			remove_action( 'customize_register', array( $this->snapshot_manager->customize_manager, 'register_controls' ) );
			$this->snapshot_manager->customize_manager->register_controls();

			/** This action is documented in wp-includes/class-wp-customize-manager.php */
			do_action( 'customize_register', $this->snapshot_manager->customize_manager );
		}
		$snapshot_content = $this->get_post_content( $post );

		if ( method_exists( $this->snapshot_manager->customize_manager, 'validate_setting_values' ) ) {
			/** This action is documented in wp-includes/class-wp-customize-manager.php */
			do_action( 'customize_save_validation_before', $this->snapshot_manager->customize_manager );
		}

		$this->snapshot_manager->customize_manager->add_dynamic_settings( array_keys( $snapshot_content ) );
		$setting_objs = array();
		$have_error = false;
		foreach ( $snapshot_content as $setting_id => &$setting_params ) {
			if ( ! isset( $setting_params['value'] ) || is_null( $setting_params['value'] ) ) {
				// Null setting save error.
				if ( ! is_array( $setting_params ) ) {
					if ( ! empty( $setting_params ) ) {
						$setting_params = array( 'value' => $setting_params );
					} else {
						$setting_params = array();
					}
				}
				$setting_params['publish_error'] = 'null_value';
				$have_error = true;
			}
			$this->snapshot_manager->customize_manager->set_post_value( $setting_id, $setting_params['value'] );
			$setting_obj = $this->snapshot_manager->customize_manager->get_setting( $setting_id );
			if ( $setting_obj instanceof \WP_Customize_Setting ) {
				$setting_objs[] = $setting_obj;
			} elseif ( ! isset( $setting_params['publish_error'] ) ) {
				// Invalid setting save error.
				$setting_params['publish_error'] = 'setting_object_not_found';
				$have_error = true;
			}
		}
		if ( empty( $setting_objs ) ) {
			$update_setting_args = array(
				'ID' => $post->ID,
				'post_status' => 'pending',
			);
			if ( true === $have_error ) {
				$update_setting_args['post_content'] = Customize_Snapshot_Manager::encode_json( $snapshot_content );
			}
			wp_update_post( $update_setting_args );
			update_post_meta( $post_id, 'snapshot_error_on_publish', 1 );
			return;
		}

		if ( true === $have_error ) {
			$this->suppress_publish_hook = true;
			wp_update_post( array(
				'ID' => $post->ID,
				'post_content' => Customize_Snapshot_Manager::encode_json( $snapshot_content ),
			) );
			$this->suppress_publish_hook = false;
		} else {
			// Remove any previous error on setting.
			delete_post_meta( $post_id, 'snapshot_error_on_publish' );
		}

		$existing_caps = wp_list_pluck( $setting_objs, 'capability' );
		foreach ( $setting_objs as $setting_obj ) {
			$setting_obj->capability = 'exist';
		}

		$action = 'customize_save';
		$callback = array( $this->snapshot_manager, 'check_customize_publish_authorization' );
		$priority = has_action( $action, $callback );
		if ( false !== $priority ) {
			remove_action( $action, $callback, $priority );
		}
		$this->snapshot_manager->customize_manager->doing_ajax( 'customize_save' );
		if ( false !== $priority ) {
			add_action( $action, $callback, $priority, 0 );
		}

		foreach ( $setting_objs as $setting_obj ) {
			$setting_obj->save();
		}

		/** This action is documented in wp-includes/class-wp-customize-manager.php */
		do_action( 'customize_save_after', $this->snapshot_manager->customize_manager );

		foreach ( $existing_caps as $i => $existing_cap ) {
			$setting_objs[ $i ]->capability = $existing_cap;
		}
	}

	/**
	 * Display snapshot save error on post list table.
	 *
	 * @param array    $status Display status.
	 * @param \WP_Post $post Post object.
	 *
	 * @return mixed
	 */
	public function display_post_states( $status, $post ) {
		if ( static::SLUG !== $post->post_type ) {
			return $status;
		}
		$maybe_error = get_post_meta( $post->ID, 'snapshot_error_on_publish', true );
		if ( $maybe_error ) {
			$status['snapshot_error'] = __( 'Error on publish', 'customize-snapshots' );
		}
		return $status;
	}
}
