<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Post_Type Back Compat Class.
 *
 * Implements snapshots for Customizer settings
 *
 * @package CustomizeSnapshots
 * @todo Remove this when minimum decadency is WP 4.7.
 */
class Post_Type_Back_Compat extends Post_Type {

	/**
	 * Post type.
	 *
	 * @type string
	 */
	const SLUG = 'customize_snapshot';

	/**
	 * Customize front end uuid param key name.
	 *
	 * @type string
	 */
	const FRONT_UUID_PARAM_NAME = 'customize_snapshot_uuid';

	/**
	 * Customize UUID Param Name.
	 *
	 * @type string
	 */
	const CUSTOMIZE_UUID_PARAM_NAME = 'customize_snapshot_uuid';

	/**
	 * Register post type and init hooks.
	 */
	public function init() {

		// Register post type.
		$labels = array(
			'name' => _x( 'Snapshots', 'post type general name', 'customize-snapshots' ),
			'singular_name' => _x( 'Snapshot', 'post type singular name', 'customize-snapshots' ),
			'menu_name' => _x( 'Snapshots', 'admin menu', 'customize-snapshots' ),
			'name_admin_bar' => _x( 'Snapshot', 'add new on admin bar', 'customize-snapshots' ),
			'add_new' => _x( 'Add New', 'Customize Snapshot', 'customize-snapshots' ),
			'add_new_item' => __( 'Add New Snapshot', 'customize-snapshots' ),
			'new_item' => __( 'New Snapshot', 'customize-snapshots' ),
			'edit_item' => __( 'Inspect Snapshot', 'customize-snapshots' ),
			'view_item' => __( 'View Snapshot', 'customize-snapshots' ),
			'all_items' => __( 'All Snapshots', 'customize-snapshots' ),
			'search_items' => __( 'Search Snapshots', 'customize-snapshots' ),
			'not_found' => __( 'No snapshots found.', 'customize-snapshots' ),
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

		// Call parent hooks.
		$this->hooks();

		// 4.6.x and post-type specific hooks.
		add_action( 'admin_footer-edit.php', array( $this, 'snapshot_merge_print_script' ) );
		add_action( 'load-edit.php', array( $this, 'handle_snapshot_bulk_actions_workaround' ) );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_post_name_in_insert_data' ), 10, 2 );
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
						esc_html__( 'Preview Snapshot', 'customize-snapshots' )
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
	 * Insert script for adding merge snapshot bulk action polyfill.
	 */
	public function snapshot_merge_print_script() {
		global $post_type;
		if ( static::SLUG === $post_type ) {
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
		if ( 'merge_snapshot' !== $action || ( isset( $_REQUEST['post_type'] ) && static::SLUG !== wp_unslash( $_REQUEST['post_type'] ) ) ) {
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
}
