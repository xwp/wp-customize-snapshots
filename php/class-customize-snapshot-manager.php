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
	 *
	 * @type string
	 */
	const POST_TYPE = 'customize_snapshot';

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
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		add_action( 'init', array( $this, 'create_post_type' ), 0 );

		// Bail if our conditions are not met.
		if ( ! ( ( isset( $_REQUEST['wp_customize'] ) && 'on' === $_REQUEST['wp_customize'] ) // WPCS: input var ok.
			|| ( is_admin() && isset( $_SERVER['PHP_SELF'] ) && 'customize.php' === basename( $_SERVER['PHP_SELF'] ) ) // WPCS: input var ok; sanitization ok.
			|| ( isset( $_REQUEST['customize_snapshot_uuid'] ) ) // WPCS: input var ok.
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

		$uuid = isset( $_REQUEST['customize_snapshot_uuid'] ) ? sanitize_text_field( sanitize_key( wp_unslash( $_REQUEST['customize_snapshot_uuid'] ) ) ) : null; // WPCS: input var ok.

		// Bootstrap the Customizer.
		if ( empty( $GLOBALS['wp_customize'] ) || ! ( $GLOBALS['wp_customize'] instanceof \WP_Customize_Manager ) && $uuid ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			// @codingStandardsIgnoreStart
			$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
			// @codingStandardsIgnoreEnd
		}
		$this->customize_manager = $GLOBALS['wp_customize'];

		$this->snapshot = new Customize_Snapshot( $this, $uuid );

		add_action( 'customize_controls_init', array( $this, 'set_return_url' ) );
		add_action( 'init', array( $this, 'maybe_force_redirect' ), 0 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_customize_save', array( $this, 'set_snapshot_uuid' ), 0 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'update_snapshot' ) );
		add_action( 'wp_ajax_customize_get_snapshot_uuid', array( $this, 'get_snapshot_uuid' ) );
		add_action( 'customize_save_after', array( $this, 'save_snapshot' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_menu' ), 41 );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'render_templates' ) );

		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'filter_theme_mod_nav_menu_locations' ) );
		add_filter( 'wp_get_nav_menus', array( $this, 'filter_wp_get_nav_menus' ) );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_wp_get_nav_menu_items' ), 10, 3 );
		add_filter( 'wp_get_nav_menu_object', array( $this, 'filter_wp_get_nav_menu_object' ), 10, 2 );

		// Needs priority 12 since it has to happen after the default nav menus are registered.
		add_action( 'customize_register', array( $this, 'customize_register_unpublished_menu_sections' ), 12 );

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
				'wp_loaded' => array(
					'callback' => array( $this->customize_manager->widgets, 'override_sidebars_widgets_for_theme_switch' ),
					'priority' => 10,
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

		// Preview a Snapshot.
		add_action( 'after_setup_theme', array( $this, 'set_post_values' ), 1 );
		add_action( 'wp_loaded', array( $this, 'preview' ) );

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
			);
			$return_url = add_query_arg( array_map( 'rawurlencode', $args ), $this->customize_manager->get_return_url() );
			$this->customize_manager->set_return_url( $return_url );
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
	 * Get the clean version of current URL.
	 *
	 * @return string
	 */
	public function clean_current_url() {
		return remove_query_arg( array( 'customize_snapshot_uuid' ), $this->current_url() );
	}

	/**
	 * Redirect when preview is not allowed for the current theme.
	 *
	 * @codeCoverageIgnore
	 */
	public function maybe_force_redirect() {
		if ( false === $this->snapshot->is_preview() && isset( $_GET['customize_snapshot_uuid'] ) ) { // WPCS: input var ok.
			wp_safe_redirect( esc_url_raw( $this->clean_current_url() ) );
			exit;
		}
	}

	/**
	 * Decode and store any $_POST['snapshot_customized'] data.
	 *
	 * The value is used by Customize_Snapshot_Manager::save().
	 */
	public function capture_unsanitized_snapshot_post_data() {
		false && check_ajax_referer(); // Bypass PHPCS nonce verification check; nonce is checked elsewhere in request.
		if ( current_user_can( 'customize' ) && isset( $_POST['snapshot_customized'] ) ) { // WPCS: input var ok.
			$this->unsanitized_snapshot_post_data = json_decode( wp_unslash( $_POST['snapshot_customized'] ), true ); // WPCS: input var ok; sanitization ok.
		}
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
	 * Create the custom post type.
	 *
	 * @access public
	 */
	public function create_post_type() {
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
			'capability_type' => self::POST_TYPE,
			'capabilities' => array(
				'create_posts' => 'do_not_allow',
				'publish_posts' => 'do_not_allow',
			),
			'rewrite' => false,
			'show_in_customizer' => false,
			'menu_icon' => 'dashicons-camera',
			'register_meta_box_cb' => array( $this, 'setup_metaboxes' ),
		);

		register_post_type( self::POST_TYPE, $args );

		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'remove_publish_metabox' ), 100 );
		add_action( 'load-revision.php', array( $this, 'suspend_kses_for_snapshot_revision_restore' ) );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( $this, 'filter_bulk_actions' ) );
		add_filter( 'get_the_excerpt', array( $this, 'filter_snapshot_excerpt' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_post_name_in_insert_data' ), 10, 2 );
	}

	/**
	 * Add the metabox.
	 */
	public function setup_metaboxes() {
		$id = self::POST_TYPE;
		$title = __( 'Data', 'customize-snapshots' );
		$callback = array( $this, 'render_data_metabox' );
		$screen = self::POST_TYPE;
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
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		remove_meta_box( 'authordiv', self::POST_TYPE, 'normal' );
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
		if ( empty( $post ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->suspend_kses();
		$that = $this;
		add_action( 'wp_restore_post_revision', function() use ( $that ) {
			$that->restore_kses();
		} );
	}

	/**
	 * Action to register unpublished menu sections with a custom menu section class.
	 */
	public function customize_register_unpublished_menu_sections() {
		$menus = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			if ( $menu->term_id < 0 ) {
				// Create a section for each menu.
				$section_id = 'nav_menu[' . $menu->term_id . ']';
				$this->customize_manager->remove_section( $section_id );
				$this->customize_manager->add_section( new Customize_Snapshot_Nav_Menu_Section( $this->customize_manager, $section_id, array(
					'title'     => html_entity_decode( $menu->name, ENT_QUOTES, get_bloginfo( 'charset' ) ),
					'priority'  => 10,
					'panel'     => 'nav_menus',
				) ) );
			}
		}
	}

	/**
	 * Filter menus to load the unpublished menus from the snapshot-
	 *
	 * @param array $menus Array of menus.
	 * @return array Modified array of menus.
	 */
	public function filter_wp_get_nav_menus( $menus ) {
		if ( isset( $_GET['customize_snapshot_uuid'] ) ) {
			$values = $this->snapshot->values();
			foreach ( $values as $key => $value ) {
				if ( false !== strpos( $key, 'nav_menu[' ) ) {
					if ( preg_match( '/\[([^\]]*)\]/', $key, $match ) ) {
						$menu_id = $match[1];
						$menu = new \WP_Term( (object) $values[ 'nav_menu[' . $menu_id . ']' ] );
						$menu->term_id = $menu->term_taxonomy_id = $menu_id;
						$menus[] = $menu;
					}
				}
			}
		}
		return $menus;
	}

	/**
	 * Filter the wp_get_nav_menu_items() result to supply the menu items from snapshot.
	 *
	 * @see wp_get_nav_menu_items()
	 *
	 * @param array  $items An array of menu item post objects.
	 * @param object $menu  The menu object.
	 * @param array  $args  An array of arguments used to retrieve menu item objects.
	 * @return array Array of menu items.
	 */
	function filter_wp_get_nav_menu_items( $items, $menu, $args ) {
		if ( $menu->term_id < 0 ) {
			$values = $this->snapshot->values();
			foreach ( $values as $key => $value ) {
				if ( false !== strpos( $key, 'nav_menu_item' ) ) {
					if ( isset( $value['nav_menu_term_id'] ) && $value['nav_menu_term_id'] === $menu->term_id ) {
						if ( preg_match( '/\[([^\]]*)\]/', $key, $match ) ) {
							$item_id = $match[1];
							$value['ID'] = $item_id;
							$items[] = new \WP_Post( (object) $value );
						}
					}
				}
			}
		}
		return $items;
	}

	/**
	 * Filter the wp_get_nav_menu_object() result to supply the menu object from snapshot.
	 *
	 * @see wp_get_nav_menu_object()
	 *
	 * @param object|null $menu_obj Object returned by wp_get_nav_menu_object().
	 * @param string      $menu_id  ID of the nav_menu term. Requests by slug or name will be ignored.
	 * @return object|null New menu object or null.
	 */
	function filter_wp_get_nav_menu_object( $menu_obj, $menu_id ) {
		if ( false === $menu_obj && $menu_id < 0 ) {
			$values = $this->snapshot->values();
			if ( isset( $values[ 'nav_menu[' . $menu_id . ']' ] ) ) {
				$menu_obj = (object) array(
					'term_id' => $menu_id,
					'term_taxonomy_id' => $menu_id,
					'taxonomy' => 'nav_menu',
					'name' => $values[ 'nav_menu[' . $menu_id . ']' ]['name'],
				);
			}
		}
		return $menu_obj;
	}

	/**
	 * Filter for displaying the Snapshot menu location values.
	 *
	 * @param array $menu_locations Default menu locations.
	 * @return array Modified menu locations.
	 */
	public function filter_theme_mod_nav_menu_locations( $menu_locations ) {
		if ( isset( $_GET['customize_snapshot_uuid'] ) ) {
			$mods = $this->snapshot->values();
			$locations = get_registered_nav_menus();
			foreach ( $locations as $location => $name ) {
				if ( isset( $mods[ 'nav_menu_locations[' . $location . ']' ] ) ) {
					$menu_locations[ $location ] = $mods[ 'nav_menu_locations[' . $location . ']' ];
				}
			}
		}
		return $menu_locations;
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
	public function filter_snapshot_excerpt( $excerpt, \WP_Post $post = null ) {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			$excerpt = '<ol>';
			foreach ( static::get_post_content( $post ) as $setting_id => $setting_params ) {
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
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'] );
		$post_type_obj = get_post_type_object( self::POST_TYPE );
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
		if ( empty( $post_data['post_type'] ) || self::POST_TYPE !== $post_data['post_type'] ) {
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
		$snapshot_content = static::get_post_content( $post );
		$post_status_obj = get_post_status_object( $post->post_status );

		echo '<p>';
		echo esc_html__( 'UUID:', 'customize-snapshots' ) . ' <code>' . esc_html( $post->post_name ) . '</code><br>';
		echo sprintf( '%1$s %2$s', esc_html__( 'Status:', 'customize-snapshots' ), esc_html( $post_status_obj->label ) ) . '<br>';
		echo sprintf( '%1$s %2$s %3$s', esc_html__( 'Publish Date:', 'customize-snapshots' ), esc_html( get_the_date( '', $post->ID ) ), esc_html( get_the_time( '', $post->ID ) ) ) . '<br>';
		echo sprintf( '%1$s %2$s %3$s', esc_html__( 'Modified:', 'customize-snapshots' ), esc_html( get_the_modified_date( '' ) ), esc_html( get_the_modified_time( '' ) ) ) . '<br>';
		echo sprintf( '%1$s %2$s', esc_html__( 'Author:', 'customize-snapshots' ), esc_html( get_the_author_meta( 'display_name', $post->post_author ) ) ) . '</p>';
		echo '</p>';

		if ( 'publish' !== $post->post_status ) {
			$args = array(
				'customize_snapshot_uuid' => $post->post_name,
			);
			$customize_url = add_query_arg( array_map( 'rawurlencode', $args ), wp_customize_url() );
			echo sprintf(
				'<p><a href="%s" class="button button-secondary">%s</a></p>',
				esc_url( $customize_url ),
				esc_html__( 'Edit in Customizer', 'customize-snapshots' )
			);
		}

		echo '<hr>';

		ksort( $snapshot_content );
		echo '<ul id="snapshot-settings">';
		foreach ( $snapshot_content as $setting_id => $setting_args ) {
			echo '<li>';
			echo '<details open>';
			echo '<summary><code>' . esc_html( $setting_id ) . '</code></summary>';
			echo sprintf( '<pre class="pre">%s</pre>', esc_html( static::encode_json( $setting_args['value'] ) ) );
			echo '</details>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Get the snapshot array out of the post_content.
	 *
	 * A post revision for a customize_snapshot may also be supplied.
	 *
	 * @param \WP_Post $post A customize_snapshot post or a revision post.
	 * @return array
	 */
	static public function get_post_content( \WP_Post $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			$parent_post = null;
			if ( 'revision' === $post->post_type ) {
				$parent_post = get_post( $post->post_parent );
			}
			if ( ! $parent_post || self::POST_TYPE !== $parent_post->post_type ) {
				return array();
			}
		}

		// Snapshot is stored as JSON in post_content.
		$snapshot = json_decode( $post->post_content, true );
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}

		return array();
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
		$exports = apply_filters( 'customize-snapshots-export-data', array(
			'nonce' => wp_create_nonce( self::AJAX_ACTION ),
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot->uuid(),
			'isPreview' => $this->snapshot->is_preview(),
			'currentUserCanPublish' => current_user_can( 'customize_publish' ),
			'theme' => $snapshot_theme,
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
				$this->snapshot->set( $setting, $post_data['value'] );
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
		false && check_ajax_referer(); // Note: This is a workaround for PHPCS nonce verification check.

		$uuid = ! empty( $_POST['snapshot_uuid'] ) ? sanitize_text_field( sanitize_key( wp_unslash( $_POST['snapshot_uuid'] ) ) ) : null; // WPCS: input var ok.
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
		} elseif ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		} elseif ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // WPCS: input var ok.
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		} elseif ( empty( $_POST['customize_snapshot_uuid'] ) ) { // WPCS: input var ok.
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_uuid' );
		} elseif ( empty( $this->unsanitized_snapshot_post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_snapshot_customized' );
		} elseif ( empty( $_POST['preview'] ) ) { // WPCS: input var ok.
			status_header( 400 );
			wp_send_json_error( 'missing_preview' );
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
		$this->snapshot->set_uuid( sanitize_text_field( sanitize_key( wp_unslash( $_POST['customize_snapshot_uuid'] ) ) ) ); // WPCS: input var ok.
		$uuid = $this->snapshot->uuid();
		$post = $this->snapshot->post();
		$post_type = get_post_type_object( self::POST_TYPE );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID ) :
			current_user_can( 'customize' )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$r = $this->save( $status );
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		$response = array(
			'customize_snapshot_uuid' => $uuid, // Current UUID.
			'customize_snapshot_settings' => $this->snapshot->values(), // Send back sanitized settings values.
		);

		wp_send_json_success( $response );
	}

	/**
	 * Generate a snapshot UUID via AJAX.
	 */
	public function get_snapshot_uuid() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		}

		wp_send_json_success( array(
			'uuid' => $this->snapshot->generate_uuid(),
		) );
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

		$args = array();
		$uuid = isset( $_GET['customize_snapshot_uuid'] ) ? sanitize_text_field( sanitize_key( wp_unslash( $_GET['customize_snapshot_uuid'] ) ) ) : null; // WPCS: input var ok.

		if ( $uuid && $this->snapshot->is_valid_uuid( $uuid ) ) {
			$args['customize_snapshot_uuid'] = $uuid;
		}

		$args['url'] = esc_url_raw( $this->clean_current_url() );
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

			foreach ( $values as $setting_id => $value ) {
				$this->customize_manager->set_post_value( $setting_id, $value );
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
