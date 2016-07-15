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
			$this->data = $this->snapshot_manager->post_type->get_post_content( $post );
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
		$setting_ids = array_keys( $this->data );
		$this->snapshot_manager->customize_manager->add_dynamic_settings( $setting_ids );
		foreach ( $setting_ids as $setting_id ) {
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
		$post = $this->post();
		return $post ? get_post_status( $post->ID ) : null;
	}

	/**
	 * Prepare snapshot data for saving.
	 *
	 * @todo This should switch back from taking an array of unsanitized values to taking a single id and value so that additional params can be set too?
	 * @see WP_Customize_Manager::set_post_value()
	 *
	 * @param array $unsanitized_values Unsanitized post values.
	 * @return array {
	 *     Result.
	 *
	 *     @type null|\WP_Error $error      Error object if error.
	 *     @type array          $sanitized  Sanitized values.
	 *     @type array          $validities Setting validities.
	 * }
	 */
	public function set( array $unsanitized_values ) {
		$error = new \WP_Error();
		$result = array(
			'errors' => null,
			'sanitized' => array(),
			'validities' => array(),
		);

		$customize_manager = $this->snapshot_manager->customize_manager;
		$customize_manager->add_dynamic_settings( array_keys( $unsanitized_values ) );

		// Check for recognized settings and authorized settings.
		$unrecognized_setting_ids = array();
		$unauthorized_setting_ids = array();
		foreach ( $unsanitized_values as $setting_id => $unsanitized_value ) {
			$setting = $customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				$unrecognized_setting_ids[] = $setting_id;
			} elseif ( ! current_user_can( $setting->capability ) ) {
				$unauthorized_setting_ids[] = $setting_id;
			}
		}

		// Remove values that are unrecognized or unauthorized.
		$unsanitized_values = wp_array_slice_assoc(
			$unsanitized_values,
			array_diff(
				array_keys( $unsanitized_values ),
				array_merge( $unrecognized_setting_ids, $unauthorized_setting_ids )
			)
		);

		// Validate.
		if ( method_exists( $customize_manager, 'validate_setting_values' ) ) {
			$result['validities'] = $customize_manager->validate_setting_values( $unsanitized_values );
		} else {
			// @codeCoverageIgnoreStart
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
			// @codeCoverageIgnoreEnd
		}
		$invalid_setting_ids = array_keys( array_filter( $result['validities'], function( $validity ) {
			return is_wp_error( $validity );
		} ) );

		// Sanitize.
		foreach ( $unsanitized_values as $setting_id => $unsanitized_value ) {
			$setting = $customize_manager->get_setting( $setting_id );
			if ( $setting ) {
				$result['sanitized'][ $setting_id ] = $setting->sanitize( $unsanitized_value );
			} else {
				$unrecognized_setting_ids[] = $setting_id;
			}
		}

		// Add errors.
		if ( ! empty( $unauthorized_setting_ids ) ) {
			$error->add(
				'unauthorized_settings',
				sprintf( __( 'Unauthorized settings: %s', 'customize-snapshots' ), join( ',', $unauthorized_setting_ids ) ),
				array( 'setting_ids' => $unauthorized_setting_ids )
			);
		}
		if ( ! empty( $unrecognized_setting_ids ) ) {
			$error->add(
				'unrecognized_settings',
				sprintf( __( 'Unrecognized settings: %s', 'customize-snapshots' ), join( ',', $unrecognized_setting_ids ) ),
				array( 'setting_ids' => $unrecognized_setting_ids )
			);
		}
		if ( 0 !== count( $invalid_setting_ids ) ) {
			$code = 'invalid_values';
			$message = __( 'Invalid values', 'customize-snapshots' );
			$error->add( $code, $message, compact( 'invalid_setting_ids' ) );
		}

		if ( ! empty( $error->errors ) ) {
			$result['errors'] = $error;
		} else {
			/*
			 * Note that somewhat unintuitively the unsanitized post values
			 * ($unsanitized_values) are stored as opposed to storing the
			 * sanitized ones ($result['sanitized']). It is still safe to do this
			 * because they have passed sanitization and validation here. The
			 * reason why we need to store the raw unsanitized values is so that
			 * the values can be re-populated into the post values for running
			 * through the sanitize, validate, and ultimately update logic.
			 * Once a value has gone through the sanitize logic, it may not be
			 * suitable for populating into a post value, especially widget
			 * instances which get exported with a JS value that has the instance
			 * data encoded, serialized, and hashed to prevent mutation. A
			 * sanitize filter for a widget instance will convert an encoded
			 * instance value into a regular instance array, and if this regular
			 * instance array is placed back into a post value, it will get
			 * rejected by the sanitize logic for not being an encoded value.
			 */
			foreach ( $unsanitized_values as $setting_id => $unsanitized_value ) {
				if ( ! isset( $this->data[ $setting_id ] ) ) {
					$this->data[ $setting_id ] = array();
				}
				$this->data[ $setting_id ]['value'] = $unsanitized_value;
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
		 * @param array $data Customizer snapshot data, with setting IDs mapped to an array
		 *                    containing a `value` array item and potentially other metadata.
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
