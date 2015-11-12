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
class Customize_Snapshot {

	/**
	 * Customize_Snapshot_Manager instance.
	 *
	 * @access protected
	 * @var Customize_Snapshot_Manager
	 */
	protected $snapshot_manager;

	/**
	 * Unique identifier.
	 *
	 * @access protected
	 * @var string
	 */
	protected $uuid;

	/**
	 * Store the snapshot data.
	 *
	 * @access protected
	 * @var array
	 */
	protected $data = array();

	/**
	 * Post object for the current snapshot.
	 *
	 * @access protected
	 * @var \WP_Post|null
	 */
	protected $post = null;

	/**
	 * Snapshot preview.
	 *
	 * @access public
	 * @var bool
	 */
	public $is_preview = false;

	/**
	 * Preview dirty values only.
	 *
	 * @access public
	 * @var bool
	 */
	public $apply_dirty;

	/**
	 * Initial loader.
	 *
	 * @access public
	 *
	 * @throws Exception If the UUID is invalid.
	 *
	 * @param Customize_Snapshot_Manager $snapshot_manager     Customize snapshot bootstrap instance.
	 * @param string|null                $uuid                 Snapshot unique identifier.
	 * @param bool                       $apply_dirty          Apply only dirty settings from snapshot to Customizer post data. Default is `true`.
	 */
	public function __construct( Customize_Snapshot_Manager $snapshot_manager, $uuid, $apply_dirty = true ) {
		$this->snapshot_manager = $snapshot_manager;
		$this->apply_dirty = $apply_dirty;
		$this->data = array();

		if ( $uuid ) {
			if ( self::is_valid_uuid( $uuid ) ) {
				$this->uuid = $uuid;
				$this->is_preview = true;
			} else {
				throw new Exception( __( 'You\'ve entered an invalid snapshot UUID.', 'customize-snapshots' ) );
			}
		} else {
			$this->uuid = self::generate_uuid();
		}

		$post = $this->post();

		// Don't preview other themes.
		if ( ( ! $this->snapshot_manager->customize_manager->is_theme_active() && is_admin() ) || ( $this->is_preview && $post && get_post_meta( $post->ID, '_snapshot_theme', true ) !== $this->snapshot_manager->customize_manager->get_stylesheet() ) ) {
			$this->is_preview = false;
			return;
		}

		if ( $post ) {
			// For reason why base64 encoding is used, see Customize_Snapshot::save().
			$this->data = json_decode( $post->post_content_filtered, true );

			if ( ! empty( $this->data ) ) {

				// For back-compat.
				if ( ! did_action( 'setup_theme' ) ) {
					/*
					 * Note we have to defer until setup_theme since the transaction
					 * can be set beforehand, and wp_magic_quotes() would not have
					 * been called yet, resulting in a $_POST['customized'] that is
					 * double-escaped. Note that this happens at priority 1, which
					 * is immediately after Customize_Snapshot_Manager::store_customized_post_data
					 * which happens at setup_theme priority 0, so that the initial
					 * POST data can be preserved.
					 */
					add_action( 'setup_theme', array( $this, 'populate_customized_post_var' ), 1 );
				} else {
					$this->populate_customized_post_var();
				}
			}
		}
	}

	/**
	 * Populate $_POST['customized'] wth the snapshot's data for back-compat.
	 *
	 * Plugins used to have to dynamically register settings by inspecting the
	 * $_POST['customized'] var and manually re-parse and inspect to see if it
	 * contains settings that wouldn't be registered otherwise. This ensures
	 * that these plugins will continue to work.
	 *
	 * Note that this can't be called prior to the setup_theme action or else
	 * magic quotes may end up getting added twice.
	 */
	public function populate_customized_post_var() {
		$_POST['customized'] = add_magic_quotes( wp_json_encode( $this->values() ) );
		$_REQUEST['customized'] = $_POST['customized'];
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
	 * Get the snapshot uuid.
	 *
	 * @return string
	 */
	public function uuid() {
		return $this->uuid;
	}

	/**
	 * Set the snapshot uuid and regenerate the post object.
	 *
	 * @param string $uuid Snapshot UUID.
	 */
	public function set_uuid( $uuid ) {
		if ( self::is_valid_uuid( $uuid ) ) {
			$this->uuid = $uuid;
			self::post( true );
		}
	}

	/**
	 * Reset the snapshot uuid.
	 *
	 * @return string
	 */
	public function reset_uuid() {
		$this->uuid = self::generate_uuid();
		return $this->uuid;
	}

	/**
	 * Check for Snapshot preview.
	 *
	 * @return bool
	 */
	public function is_preview() {
		return $this->is_preview;
	}

	/**
	 * Get the snapshot post associated with the provided UUID, or null if it does not exist.
	 *
	 * @param bool $refresh Whether or not to refresh the post object.
	 * @return \WP_Post|null
	 */
	public function post( $refresh = false ) {
		if ( ! $refresh && $this->post ) {
			return $this->post;
		}

		add_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );
		$posts = get_posts( array(
			'name' => $this->uuid,
			'posts_per_page' => 1,
			'post_type' => Customize_Snapshot_Manager::POST_TYPE,
			'post_status' => array( 'draft', 'publish' ),
		) );
		remove_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );

		if ( empty( $posts ) ) {
			$this->post = null;
		} else {
			$this->post = array_shift( $posts );
		}

		return $this->post;
	}

	/**
	 * This is needed to ensure that draft posts can be queried by name.
	 *
	 * @param \WP_Query $query WP Query.
	 */
	public function _override_wp_query_is_single( $query ) {
		$query->is_single = false;
	}

	/**
	 * Get the value for a setting in the snapshot.
	 *
	 * @param \WP_Customize_Setting|string $setting Setting.
	 * @param mixed                        $default Return value if the snapshot lacks a value for the given setting.
	 * @return mixed
	 */
	public function get( $setting, $default = null ) {
		if ( is_string( $setting ) ) {
			$setting_obj = $this->snapshot_manager->customize_manager->get_setting( $setting );
			if ( $setting_obj ) {
				$setting_id = $setting_obj->id;
				$setting = $setting_obj;
			} else {
				$setting_id = $setting;
				$setting = null;
			}
			unset( $setting_obj );
		} else {
			$setting_id = $setting->id;
		}

		if ( ! isset( $this->data[ $setting_id ] ) ) {
			if ( is_null( $default ) && isset( $setting->default ) ) {
				return $setting->default;
			}

			return $default;
		}

		$value = $this->data[ $setting_id ];

		// @todo if ( $setting ) { $setting->sanitize( wp_slash( $value ) ); } ?
		unset( $setting );

		return $value;
	}

	/**
	 * Return all settings' values in the snapshot.
	 *
	 * @return array
	 */
	public function values() {
		$values = $this->data;
		$dirty = $this->apply_dirty;

		// Filter when the scope is dirty.
		if ( $dirty ) {
			$values = array_filter( $values, function( $setting ) use ( $dirty ) {
				return $setting['dirty'] === $dirty;
			} );
		}

		$values = wp_list_pluck( $values, 'value' );
		return $values;
	}

	/**
	 * Get the underlying data for the snapshot.
	 *
	 * @return array
	 */
	public function data() {
		return $this->data;
	}

	/**
	 * Return the Customizer settings corresponding to the data contained in the snapshot.
	 *
	 * @return \WP_Customize_Setting[]
	 */
	public function settings() {
		$settings = array();
		foreach ( array_keys( $this->data ) as $setting_id ) {
			$setting = $this->snapshot_manager->customize_manager->get_setting( $setting_id );
			if ( $setting ) {
				$settings[] = $setting;
			}
		}
		return $settings;
	}

	/**
	 * Get the status of the snapshot.
	 *
	 * @return string|null
	 */
	public function status() {
		return $this->post ? get_post_status( $this->post->ID ) : null;
	}

	/**
	 * Store a setting's value in the snapshot's data.
	 *
	 * @param \WP_Customize_Setting $setting Setting.
	 * @param mixed                 $value   Must be JSON-serializable.
	 * @param bool                  $dirty   Whether the setting is dirty or not.
	 */
	public function set( \WP_Customize_Setting $setting, $value, $dirty ) {
		$this->data[ $setting->id ] = array(
			'value' => $value,
			'dirty' => $dirty,
			'sanitized' => false,
		);
	}

	/**
	 * Return whether the snapshot was saved (created/inserted) yet.
	 *
	 * @return bool
	 */
	public function saved() {
		return ! empty( $this->post );
	}

	/**
	 * Persist the data in the snapshot post content.
	 *
	 * @param string $status Post status.
	 *
	 * @return null|\WP_Error
	 */
	public function save( $status = 'draft' ) {
		if ( ! current_user_can( 'customize' ) ) {
			return new \WP_Error( 'customize_not_allowed', __( 'You are not authorized to save Snapshots.', 'customize-snapshots' ) );
		}

		$options = 0;
		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$options |= JSON_UNESCAPED_SLASHES;
		}
		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			$options |= JSON_PRETTY_PRINT;
		}

		/**
		 * Filter the snapshot's data before it's saved to 'post_content_filtered'.
		 *
		 * @param array $data Customizer settings and values.
		 * @return array
		 */
		$this->data = apply_filters( 'customize_snapshot_save', $this->data );

		// JSON encoded snapshot data.
		$post_content = wp_json_encode( $this->data, $options );

		if ( ! $this->post ) {
			$postarr = array(
				'post_type' => Customize_Snapshot_Manager::POST_TYPE,
				'post_name' => $this->uuid,
				'post_title' => $this->uuid,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
				'post_content_filtered' => $post_content,
			);
			$r = wp_insert_post( $postarr, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$this->post = get_post( $r );
			update_post_meta( $this->post->ID, '_snapshot_theme', $this->snapshot_manager->customize_manager->get_stylesheet() );
		} else {
			$postarr = array(
				'ID' => $this->post->ID,
				'post_status' => $status,
				'post_content_filtered' => wp_slash( $post_content ),
			);
			$r = wp_update_post( $postarr, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$this->post = get_post( $r );
		}

		return null;
	}
}
