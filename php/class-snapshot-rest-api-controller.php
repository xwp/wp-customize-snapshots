<?php
/**
 * REST API Controller.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * REST API Controller Class
 *
 * @todo Add support for editing. Make sure Post_Type::save() is used.
 * @todo Add support for PATCH requests.
 * @todo Allow use of UUID instead of ID in routes.
 * @todo Disallow edits to slug.
 *
 * @package CustomizeSnapshots
 */
class Snapshot_REST_API_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Post type instance.
	 *
	 * @var Post_Type
	 */
	public $snapshot_post_type;

	/**
	 * Snapshot_REST_API_Controller constructor.
	 *
	 * @throws Exception If the post type was not registered properly.
	 * @param string $post_type Post type.
	 */
	public function __construct( $post_type ) {
		$post_type_obj = get_post_type_object( $post_type );
		if ( empty( $post_type_obj ) || empty( $post_type_obj->customize_snapshot_post_type_obj ) ) {
			throw new Exception( 'Missing customize_snapshot post type obj or arg for customize_snapshot_post_type_obj' );
		}
		$this->snapshot_post_type = $post_type_obj->customize_snapshot_post_type_obj;
		parent::__construct( $post_type );
	}

	/**
	 * Get the Post's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();
		$schema['properties']['content'] = array(
			'description' => __( 'Object mapping setting ID to an object of setting params, including value.', 'customize-snapshots' ),
			'type'        => 'object',
			'context'     => array( 'view', 'edit' ),
		);
		return $schema;
	}

	/**
	 * Get the query params for collections of attachments.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		$params['author']['sanitize_callback'] = array( $this, 'parse_author_list' );
		$params['author_exclude']['sanitize_callback'] = array( $this, 'parse_author_list' );
		return $params;
	}

	/**
	 * Parse comma-separated list of authors represented as IDs or usernames.
	 *
	 * @param string $author_list Authors.
	 * @return array User IDs.
	 */
	public function parse_author_list( $author_list ) {
		if ( empty( $author_list ) ) {
			return array();
		}
		$authors = array();
		foreach ( preg_split( '/\s*,\s*/', trim( $author_list ) ) as $author ) {
			if ( is_numeric( $author ) ) {
				$authors[] = intval( $author );
			} else {
				$user = get_user_by( 'slug', sanitize_user( $author ) );
				if ( $user ) {
					$authors[] = $user->ID;
				} else {
					$authors[] = -1;
				}
			}
		}
		return $authors;
	}

	/**
	 * Check for fundamental customize capability to do anything with snapshots.
	 *
	 * @return bool|\WP_Error
	 */
	protected function check_initial_access_permission() {
		if ( ! current_user_can( 'customize' ) ) {
			return new \WP_Error( 'rest_customize_unauthorized', __( 'Sorry, Customizer snapshots require proper authentication (the customize capability).', 'customize-snapshots' ), array(
				'status' => rest_authorization_required_code(),
			) );
		}
		return true;
	}

	/**
	 * Check if a given request has basic access to read a snapshot.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		$error = $this->check_initial_access_permission();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has basic access to read snapshots.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		$error = $this->check_initial_access_permission();
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Restrict read permission to whether the user can edit.
	 *
	 * @param \WP_Post $post Post object.
	 * @return boolean Can we read it?
	 */
	public function check_read_permission( $post ) {
		$post_type_obj = get_post_type_object( $this->snapshot_post_type->snapshot_manager->get_post_type() );
		if ( ! current_user_can( $post_type_obj->cap->edit_post, $post->ID ) ) {
			return false;
		}
		return current_user_can( 'customize' ) && parent::check_read_permission( $post );
	}

	/**
	 * Prepare a single post output for response.
	 *
	 * @param \WP_Post         $post    Post object.
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response $response Response.
	 */
	public function prepare_item_for_response( $post, $request ) {
		$response = parent::prepare_item_for_response( $post, $request );
		$response->data['content'] = $this->snapshot_post_type->get_post_content( $post );
		return $response;
	}

	/**
	 * Creates a snapshot/changeset post.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error WP_Error object.
	 */
	public function create_item( $request ) {
		unset( $request );
		return new \WP_Error( 'rest_cannot_create', __( 'Now allowed to create post', 'customize-snapshots' ), array(
			'status' => rest_authorization_required_code(),
		) );
	}

	/**
	 * Update one item from the collection.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		unset( $request );
		/* translators: %s is the method name */
		return new \WP_Error( 'invalid-method', sprintf( __( "Method '%s' not yet implemented.", 'customize-snapshots' ), __METHOD__ ), array(
			'status' => 405,
		) );
	}

	/**
	 * Delete one item from the collection.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_item( $request ) {
		unset( $request );
		/* translators: %s is the method name */
		return new \WP_Error( 'invalid-method', sprintf( __( "Method '%s' not yet implemented.", 'customize-snapshots' ), __METHOD__ ), array(
			'status' => 405,
		) );
	}
}
