<?php
/**
 * Customize Snapshot.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Customize Snapshot Migration for 4.7 changeset.
 *
 * @package CustomizeSnapshots
 */
class Migrate {

	const KEY = 'customize_snapshot_migrate';

	/**
	 * Plugin instance.
	 *
	 * @var bool
	 */
	public $compat;

	/**
	 * Migrate constructor.
	 */
	public function __construct() {
		$this->compat = version_compare( get_bloginfo( 'version' ), '4.7-beta1', '<' );
		if ( ! $this->compat && is_admin() && is_super_admin() ) {
			$this->maybe_migrate();
		}
	}

	/**
	 * Is already migrated or not.
	 *
	 * @return bool status of migration.
	 */
	public function is_migrated() {
		$snapshot_migrate_option = get_option( self::KEY );
		return ! empty( $snapshot_migrate_option );
	}

	/**
	 * Migrate if wp version is 4.7 and above.
	 */
	public function maybe_migrate() {
		if ( ! $this->is_migrated() ) {
			add_action( 'admin_notices', array( $this, 'show_migration_notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
			add_action( 'wp_ajax_customize_snapshot_migration', array( $this, 'handle_migrate_changeset_request' ) );
		}
	}

	/**
	 * Migrate 20 posts at a time.
	 */
	public function handle_migrate_changeset_request() {
		check_ajax_referer( 'customize-snapshot-migration', 'nonce' );
		$limit = isset( $_REQUEST['limit'] ) ? absint( $_REQUEST['limit'] ) : 20;
		$found_posts = $this->changeset_migrate( $limit );
		$remaining_post = ( $found_posts < $limit ) ? 0 : $found_posts - $limit;
		$data = array(
			'remaining_posts' => $remaining_post,
		);
		if ( ! $remaining_post ) {
			update_option( self::KEY, 1 );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Print migration javascript script.
	 */
	public function enqueue_script() {
		wp_enqueue_script( 'customize-snapshot-migrate' );
	}

	/**
	 * Show admin notice to migrate.
	 */
	public function show_migration_notice() {
		?>
		<div class="notice notice-error customize-snapshot-migration">
			<p><?php esc_html_e( 'Existing snapshots need to be migrated in changesets in WordPress 4.7.', 'customize-snapshots' );
				printf( ' %s <a id="customize-snapshot-migration" data-nonce="' . esc_attr( wp_create_nonce( 'customize-snapshot-migration' ) ) . '" href="javascript:void(0)" data-migration-success="%s">%s</a> %s <span class="spinner customize-snapshot-spinner"></span>', esc_html__( 'Click', 'customize-snapshots' ), esc_html__( 'Customize snapshot migration complete!', 'customize-snapshots' ), esc_html__( 'here', 'customize-snapshots' ), esc_html__( 'to start migration.', 'customize-snapshots' ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Query data and migrate if not empty.
	 *
	 * @param int  $limit   migration limit.
	 * @param bool $dry_run get number of posts affected.
	 *
	 * @return int|array migration status or posts.
	 */
	public function changeset_migrate( $limit = - 1, $dry_run = false ) {
		$query = new \WP_Query();
		$arg = array(
			'post_type' => 'customize_snapshot',
			'no_found_rows' => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_status' => array_keys( get_post_stati() ),
			'posts_per_page' => $limit,
			'fields' => 'ids', // We will use get_post() to fetch each posts.
		);

		if ( - 1 === $limit ) {
			$arg['no_found_rows'] = true;
		}

		$query->query( $arg );
		if ( $dry_run ) {
			return $query->posts;
		}

		if ( ! empty( $query->posts ) ) {
			$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
			if ( $has_kses ) {
				kses_remove_filters(); // Prevent KSES from corrupting JSON in post_content.
			}
			if ( ! class_exists( '\WP_Customize_Manager' ) ) {
				require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			}
			foreach ( $query->posts as $id ) {
				$this->migrate_post( $id );
			}
			if ( $has_kses ) {
				kses_init_filters();
			}
		}
		if ( - 1 === $limit ) {
			update_option( self::KEY, 1 );
			return count( $query->posts );
		} else {
			return $query->found_posts;
		}
	}

	/**
	 * Migrate a post.
	 *
	 * @param int $id Post ID.
	 *
	 * @return int|\WP_Error maybe updated.
	 */
	public function migrate_post( $id ) {
		$post = get_post( $id );

		// Get data.
		$data = json_decode( $post->post_content, true );
		if ( json_last_error() || ! is_array( $data ) ) {
			$data = array();
		}

		// Validate data.
		$manager = new \WP_Customize_Manager();
		foreach ( $data as $setting_id => $setting_params ) {
			// Amend post values with any supplied data.
			if ( array_key_exists( 'value', $setting_params ) ) {
				$manager->set_post_value( $setting_id, $setting_params['value'] ); // Add to post values so that they can be validated and sanitized.
			}
		}
		do_action( 'customize_register', $manager );

		// Note that in addition to post data, this will include any stashed theme mods.
		$post_values = $manager->unsanitized_post_values( array(
			'exclude_changeset' => true,
			'exclude_post_data' => false,
		) );

		// Update data as new changeset.
		$manager->add_dynamic_settings( array_keys( $post_values ) );
		$theme = get_post_meta( $id, '_snapshot_theme', true );
		$post_data = array();
		foreach ( $post_values as $setting_id => $setting_value ) {
			$setting = $manager->get_setting( $setting_id );

			if ( $setting && 'theme_mod' === $setting->type ) {
				$prefixed_setting_id = $theme . '::' . $setting->id;
			} else {
				$prefixed_setting_id = $setting_id;
			}
			$post_data[ $prefixed_setting_id ] = array(
				'value' => $setting_value,
				'user_id' => $post->post_author,
			);
			if ( $setting instanceof \WP_Customize_Setting ) {
				$post_data[ $prefixed_setting_id ]['type'] = $setting->type;
			}
		}
		$maybe_updated = wp_update_post( wp_slash( array(
			'ID' => $post->ID,
			'post_type' => 'customize_changeset',
			'post_content' => Customize_Snapshot_Manager::encode_json( $post_data ),
		) ), true );
		return $maybe_updated;
	}
}
