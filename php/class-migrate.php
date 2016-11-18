<?php
/**
 * Customize Snapshot Migration for 4.7 changeset.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Migrate
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
		if ( is_admin() ) {
			$this->maybe_migrate();
		}
	}

	/**
	 * Migrate if wp version is 4.7 and above.
	 */
	public function maybe_migrate() {
		if ( $this->compat ) {
			return;
		}
		$snapshot_migrate_option = get_option( self::KEY );
		if ( empty( $snapshot_migrate_option ) ) {
			$snapshot_migrate_option = $this->changeset_migrate();
			if ( $snapshot_migrate_option ) {
				update_option( self::KEY, 1 );
			}
		}
	}

	/**
	 * Query data and migrate if not empty.
	 *
	 * @return bool migration status.
	 */
	public function changeset_migrate() {
		$query = new \WP_Query();
		$query->query( array(
			'post_type' => 'customize_snapshot',
			'no_found_rows' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_status' => array_keys( get_post_stati() ),
			'posts_per_page' => -1, // Because we are migrating all posts.
			'fields' => 'ids', // We will use get_post() to fetch each posts.
		) );

		if ( ! empty( $query->posts ) ) {
			$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
			if ( $has_kses ) {
				kses_remove_filters(); // Prevent KSES from corrupting JSON in post_content.
			}
			if ( ! class_exists( '\WP_Customize_Manager' ) ) {
				require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			}
			set_time_limit( 0 ); // This script will run for more than 30 sec.
			foreach ( $query->posts as $id ) {
				$this->migrate_post( $id );
			}
			if ( $has_kses ) {
				kses_init_filters();
			}
		}
		return true;
	}

	/**
	 * Migrate a post.
	 *
	 * @param int $id Post ID.
	 *
	 * @return int|\WP_Error maybe updated.
	 */
	protected function migrate_post( $id ) {
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
				$post_data[ $setting_id ]['type'] = $setting->type;
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
