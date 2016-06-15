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
	 * Post id for the current snapshot.
	 *
	 * @access protected
	 * @var \WP_Post|null
	 */
	protected $post_id = null;

	/**
	 * Initial loader.
	 *
	 * @access public
	 *
	 * @throws Exception If the UUID is invalid.
	 *
	 * @param Customize_Snapshot_Manager $snapshot_manager     Customize snapshot bootstrap instance.
	 * @param string                     $uuid                 Snapshot unique identifier.
	 */
	public function __construct( Customize_Snapshot_Manager $snapshot_manager, $uuid ) {
		$this->snapshot_manager = $snapshot_manager;
		$this->data = array();

		if ( ! Customize_Snapshot_Manager::is_valid_uuid( $uuid ) ) {
			throw new Exception( __( 'You\'ve entered an invalid snapshot UUID.', 'customize-snapshots' ) );
		}
		$this->uuid = $uuid;
		$post = $this->post();
		if ( $post ) {
			$this->data = json_decode( $post->post_content, true );
			if ( json_last_error() || ! is_array( $this->data ) ) {
				$this->snapshot_manager->plugin->trigger_warning( 'JSON parse error, expected array: ' . ( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error() ) );
				$this->data = array();
			}
		}
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
	 * Get the snapshot post associated with the provided UUID, or null if it does not exist.
	 *
	 * @return \WP_Post|null Post or null.
	 */
	public function post() {
		if ( ! $this->post_id ) {
			$this->post_id = $this->snapshot_manager->post_type->find_post( $this->uuid );
		}
		if ( $this->post_id ) {
			return get_post( $this->post_id );
		} else {
			return null;
		}
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
		return $this->post_id ? get_post_status( $this->post_id ) : null;
	}

	/**
	 * Store a setting's value in the snapshot's data.
	 *
	 * @since 0.4.0 Removed support for `$dirty` argument.
	 *
	 * @param \WP_Customize_Setting $setting    Setting.
	 * @param mixed                 $value      Must be JSON-serializable.
	 * @param bool                  $deprecated Whether the setting is dirty or not.
	 */
	public function old__set( \WP_Customize_Setting $setting, $value, $deprecated = null ) {
		if ( ! is_null( $deprecated ) ) {
			_doing_it_wrong( __METHOD__, 'The $dirty argument has been removed.', '0.4.0' );
			if ( false === $deprecated ) {
				return;
			}
		}
		$this->data[ $setting->id ] = $value;
	}

	/**
	 * Prepare snapshot data for saving.
	 *
	 * @param array $unsanitized_post_values Unsanitized post values.
	 * @return array {
	 *     Result.
	 *
	 *     @type null|\WP_Error $error      Error object if error.
	 *     @type array          $sanitized  Sanitized values.
	 *     @type array          $validities Setting validities.
	 * }
	 */
	public function set( array $unsanitized_post_values ) {
		$result = array(
			'error' => null,
			'sanitized' => array(),
			'validities' => array(),
		);

		$customize_manager = $this->snapshot_manager->customize_manager;
		$customize_manager->add_dynamic_settings( array_keys( $unsanitized_post_values ) );

		$unrecognized_setting_ids = array();
		foreach ( $unsanitized_post_values as $setting_id => $unsanitized_post_value ) {
			$setting = $customize_manager->get_setting( $setting_id );
			if ( $setting ) {
				$result['sanitized'][ $setting_id ] = $setting->sanitize( $unsanitized_post_value );
			} else {
				$unrecognized_setting_ids[] = $setting_id;
			}
		}
		if ( ! empty( $unrecognized_setting_ids ) ) {
			$result['error'] = new \WP_Error(
				'unrecognized_settings',
				sprintf( __( 'Unrecognized settings: %s' ), join( ',', $unrecognized_setting_ids ) ),
				array( 'setting_ids' => $unrecognized_setting_ids )
			);
		}

		if ( method_exists( $customize_manager, 'validate_setting_values' ) ) {
			$result['validities'] = $customize_manager->validate_setting_values( $result['sanitized'] );
		} else {
			$result['validities'] = array_map(
				function( $sanitized ) {
					if ( is_null( $sanitized ) ) {
						return new \WP_Error( 'invalid_value', __( 'Invalid value', 'customize-snapshots' ) );
					} else {
						return true;
					}
				},
				$result['sanitized']
			);
		}

		$invalid_setting_ids = array_keys( array_filter( $result['validities'], function( $validity ) {
			return is_wp_error( $validity );
		} ) );

		if ( 0 === count( $invalid_setting_ids ) ) {
			$this->data = array_merge( $this->data, $result['sanitized'] );
		} else {
			$code = 'invalid_values';
			$message = __( 'Invalid values' );
			if ( $result['error'] ) {
				$result['error']->add( $code, $message, compact( 'invalid_setting_ids' ) );
			} else {
				$result['error'] = new \WP_Error( $code, $message, compact( 'invalid_setting_ids' ) );
			}
		}

		return $result;
	}

	/**
	 * Return whether the snapshot was saved (created/inserted) yet.
	 *
	 * @return bool
	 */
	public function saved() {
		return ! is_null( $this->post() );
	}

	/**
	 * Persist the data in the snapshot post content.
	 *
	 * @param array $args Args.
	 * @return true|\WP_Error
	 */
	public function save( array $args ) {

		/**
		 * Filter the snapshot's data before it's saved to 'post_content'.
		 *
		 * @param array $data Customizer settings and values.
		 */
		$this->data = apply_filters( 'customize_snapshot_save', $this->data );

		$result = $this->snapshot_manager->post_type->save( array_merge(
			$args,
			array(
				'uuid' => $this->uuid,
				'data' => $this->data,
				'theme' => $this->snapshot_manager->customize_manager->get_stylesheet(),
			)
		) );

		if ( ! is_wp_error( $result ) ) {
			$this->post_id = $result;
		}

		return $result;
	}
}
