<?php
/**
 * Test Post Type.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Post_type
 */
class Test_Post_type extends \WP_UnitTestCase {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * A valid UUID.
	 *
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		unregister_post_type( Post_Type::SLUG );
	}

	/**
	 * Test register post type.
	 *
	 * @see Post_Type::register()
	 */
	public function test_register() {
		$this->assertFalse( post_type_exists( Post_Type::SLUG ) );
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$this->assertTrue( post_type_exists( Post_Type::SLUG ) );

		$this->assertEquals( 100, has_action( 'add_meta_boxes_' . Post_Type::SLUG, array( $post_type, 'remove_publish_metabox' ) ) );
		$this->assertEquals( 10, has_action( 'load-revision.php', array( $post_type, 'suspend_kses_for_snapshot_revision_restore' ) ) );
		$this->assertEquals( 10, has_filter( 'bulk_actions-edit-' . Post_Type::SLUG, array( $post_type, 'filter_bulk_actions' ) ) );
		$this->assertEquals( 10, has_filter( 'get_the_excerpt', array( $post_type, 'filter_snapshot_excerpt' ) ) );
		$this->assertEquals( 10, has_filter( 'post_row_actions', array( $post_type, 'filter_post_row_actions' ) ) );
		$this->assertEquals( 10, has_filter( 'wp_insert_post_data', array( $post_type, 'preserve_post_name_in_insert_data' ) ) );
		$this->assertEquals( 10, has_filter( 'user_has_cap', array( $post_type, 'filter_user_has_cap' ) ) );
	}



	/**
	 * Suspend kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see Post_Type::suspend_kses()
	 * @see Post_Type::restore_kses()
	 */
	function test_suspend_restore_kses() {
		if ( ! has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			kses_init_filters();
		}

		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->suspend_kses();
		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		$post_type->restore_kses();
		$this->assertEquals( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$post_type->suspend_kses();
		$post_type->restore_kses();
		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/**
	 * Test adding and removing the metabox.
	 *
	 * @see Post_Type::setup_metaboxes()
	 * @see Post_Type::remove_metaboxes()
	 */
	public function test_setup_metaboxes() {
		set_current_screen( Post_Type::SLUG );
		global $wp_meta_boxes;
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();

		$post_id = $this->factory()->post->create( array( 'post_type' => Post_Type::SLUG, 'post_status' => 'draft' ) );

		$wp_meta_boxes = array(); // WPCS: global override ok.
		$metabox_id = Post_Type::SLUG;
		$this->assertFalse( ! empty( $wp_meta_boxes[ Post_Type::SLUG ]['normal']['high'][ $metabox_id ] ) );
		do_action( 'add_meta_boxes_' . Post_Type::SLUG, $post_id );
		$this->assertTrue( ! empty( $wp_meta_boxes[ Post_Type::SLUG ]['normal']['high'][ $metabox_id ] ) );
	}

	/**
	 * Test remove edit bulk action for snapshots.
	 *
	 * @see Post_Type::filter_bulk_actions()
	 */
	public function test_filter_bulk_actions() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$this->assertArrayNotHasKey( 'edit', $post_type->filter_bulk_actions( array( 'edit' => 1 ) ) );
	}

	/**
	 * Test include the setting IDs in the excerpt.
	 *
	 * @see Post_Type::filter_snapshot_excerpt()
	 */
	public function test_filter_snapshot_excerpt() {
		global $post;

		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => array(
				'foo' => array( 'value' => 1 ),
				'bar' => array( 'value' => 2 ),
			),
		) );
		$this->assertInternalType( 'int', $post_id );

		$post = get_post( $post_id ); // WPCS: global override ok.
		$excerpt = get_the_excerpt();
		$this->assertContains( 'foo', $excerpt );
		$this->assertContains( 'bar', $excerpt );
	}

	/**
	 * Test add Customize link to quick edit links.
	 *
	 * @see Post_Type::filter_post_row_actions()
	 */
	public function test_filter_post_row_actions() {
		$admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$data = array(
			'foo' => array( 'value' => 1 ),
			'bar' => array( 'value' => 2 ),
		);

		// Bad post type.
		$this->assertEquals( array(), apply_filters( 'post_row_actions', array(), get_post( $this->factory()->post->create() ) ) );

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
		) );
		$original_actions = array(
			'inline hide-if-no-js' => '...',
			'edit' => '<a></a>',
		);

		wp_set_current_user( $admin_user_id );
		$filtered_actions = apply_filters( 'post_row_actions', $original_actions, get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $filtered_actions );
		$this->assertArrayHasKey( 'customize', $filtered_actions );
		$this->assertArrayHasKey( 'front-view', $filtered_actions );

		wp_set_current_user( $subscriber_user_id );
		$filtered_actions = apply_filters( 'post_row_actions', $original_actions, get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $filtered_actions );
		$this->assertArrayNotHasKey( 'customize', $filtered_actions );
		$this->assertArrayNotHasKey( 'front-view', $filtered_actions );

		$post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );

		wp_set_current_user( $admin_user_id );
		$filtered_actions = apply_filters( 'post_row_actions', $original_actions, get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $filtered_actions );
		$this->assertArrayNotHasKey( 'customize', $filtered_actions );
		$this->assertArrayNotHasKey( 'front-view', $filtered_actions );

		wp_set_current_user( $subscriber_user_id );
		$filtered_actions = apply_filters( 'post_row_actions', $original_actions, get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $filtered_actions );
		$this->assertArrayNotHasKey( 'customize', $filtered_actions );
		$this->assertArrayNotHasKey( 'front-view', $filtered_actions );

		$post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );
		$filtered_actions = apply_filters( 'post_row_actions', $original_actions, get_post( $post_id ) );
		$this->assertContains( '<a href', $filtered_actions['edit'] );
	}

	/**
	 * Tests preservation of the post_name when submitting a snapshot for review.
	 *
	 * @see Post_Type::preserve_post_name_in_insert_data()
	 */
	public function test_preserve_post_name_in_insert_data() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();

		$post_data = array(
			'post_name' => '',
			'post_type' => 'no',
			'post_status' => 'pending',
		);
		$original_post_data = array(
			'post_type' => 'no',
			'post_name' => '!original!',
			'post_status' => 'pending',
		);
		$filtered_post_data = $post_type->preserve_post_name_in_insert_data( $post_data, $original_post_data );
		$this->assertEquals( $post_data, $filtered_post_data );

		$post_data['post_type'] = Post_Type::SLUG;
		$original_post_data['post_type'] = Post_Type::SLUG;

		$filtered_post_data = $post_type->preserve_post_name_in_insert_data( $post_data, $original_post_data );
		$this->assertEquals( $original_post_data['post_name'], $filtered_post_data['post_name'] );
	}

	/**
	 * Test rendering the metabox.
	 *
	 * @see Post_Type::render_data_metabox()
	 */
	public function test_render_data_metabox() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$data = array(
			'knoa8sdhpasidg0apbdpahcas' => array( 'value' => 'a09sad0as9hdgw22dutacs' ),
			'n0nee8fa9s7ap9sdga9sdas9c' => array( 'value' => 'lasdbaosd81vvajgcaf22k' ),
		);
		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
		) );

		ob_start();
		$post_type->render_data_metabox( get_post( $post_id ) );
		$metabox_content = ob_get_clean();

		$this->assertContains( 'UUID:', $metabox_content );
		$this->assertContains( 'button-secondary', $metabox_content );
		$this->assertContains( '<ul id="snapshot-settings">', $metabox_content );
		foreach ( $data as $setting_id => $setting_args ) {
			$this->assertContains( $setting_id, $metabox_content );
			$this->assertContains( $setting_args['value'], $metabox_content );
		}

		$post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );

		add_filter( 'customize_snapshot_value_preview', array( $this, 'filter_customize_snapshot_value_preview' ), 10, 2 );
		ob_start();
		$post_type->render_data_metabox( get_post( $post_id ) );
		$metabox_content = ob_get_clean();
		remove_filter( 'customize_snapshot_value_preview', array( $this, 'filter_customize_snapshot_value_preview' ), 10 );

		$this->assertContains( 'UUID:', $metabox_content );
		$this->assertNotContains( 'button-secondary', $metabox_content );
		$this->assertContains( '<ul id="snapshot-settings">', $metabox_content );
		foreach ( $data as $setting_id => $setting_args ) {
			$this->assertContains( $setting_id, $metabox_content );
			$this->assertContains( 'FILTERED:' . $setting_id, $metabox_content );
		}

		// Try switching theme.
		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
			'theme' => 'bogus',
		) );
		ob_start();
		$post_type->render_data_metabox( get_post( $post_id ) );
		$metabox_content = ob_get_clean();
		$this->assertContains( 'snapshot was made when a different theme was active', $metabox_content );
	}

	/**
	 * Filter customize_snapshot_value_preview.
	 *
	 * @param string $preview HTML preview.
	 * @param array  $context Context.
	 * @return string Filtered preview.
	 */
	function filter_customize_snapshot_value_preview( $preview, $context ) {
		$this->assertInternalType( 'string', $preview );
		$this->assertInternalType( 'array', $context );
		$this->assertArrayHasKey( 'value', $context );
		$this->assertArrayHasKey( 'setting_params', $context );
		$this->assertArrayHasKey( 'setting_id', $context );
		$this->assertArrayHasKey( 'post', $context );
		$preview = sprintf( 'FILTERED:%s', $context['setting_id'] );
		return $preview;
	}

	/**
	 * Find a snapshot post by UUID.
	 *
	 * @see Post_Type::find_post()
	 */
	public function test_find_post() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$data = array(
			'foo' => array(
				'value' => 'bar',
			),
		);

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );
		$this->assertEquals( $post_id, $post_type->find_post( self::UUID ) );

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'draft',
		) );
		$this->assertEquals( $post_id, $post_type->find_post( self::UUID ) );

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'pending',
		) );
		$this->assertEquals( $post_id, $post_type->find_post( self::UUID ) );

		$this->assertNull( $post_type->find_post( '0734b3f9-92bb-42a5-85ee-20e90cf09b52' ) );
	}

	/**
	 * Test getting the snapshot array out of the post_content.
	 *
	 * @see Post_Type::get_post_content()
	 * @expectedException \PHPUnit_Framework_Error_Warning
	 */
	public function test_get_post_content() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();

		// Bad post type.
		$page_post_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		$page = get_post( $page_post_id );
		$this->assertNull( $post_type->get_post_content( $page ) );

		// Regular post.
		$data = array(
			'foo' => array(
				'value' => 'bar',
			),
		);
		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
		) );
		$snapshot_post = get_post( $post_id );
		$this->assertEquals( $data, $post_type->get_post_content( $snapshot_post ) );

		// Revision.
		$revision_post_id = $this->factory()->post->create( array(
			'post_type' => 'revision',
			'post_parent' => $post_id,
			'post_content' => wp_json_encode( array(
				'foo' => array(
					'value' => 'baz',
				),
			) ),
		) );
		$revision_post = get_post( $revision_post_id );
		$content = $post_type->get_post_content( $revision_post );
		$this->assertEquals( 'baz', $content['foo']['value'] );

		// Bad post data.
		$bad_post_id = $this->factory()->post->create( array( 'post_type' => Post_Type::SLUG, 'post_content' => 'BADJSON' ) );
		$bad_post = get_post( $bad_post_id );
		$content = $post_type->get_post_content( $bad_post );
		$this->assertEquals( array(), $content );
	}

	/**
	 * Test persisting the data in the snapshot post content.
	 *
	 * @see Post_Type::save()
	 */
	public function test_save() {
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();

		// Error: missing_valid_uuid.
		$r = $post_type->save( array( 'id' => 'nouuid' ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'missing_valid_uuid', $r->get_error_code() );

		$r = $post_type->save( array( 'uuid' => 'baduuid' ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'missing_valid_uuid', $r->get_error_code() );

		$r = $post_type->save( array( 'uuid' => self::UUID, 'data' => 'bad' ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'missing_data', $r->get_error_code() );

		// Error: bad_setting_params.
		$r = $post_type->save( array( 'uuid' => self::UUID, 'data' => array( 'foo' => 'bar' ) ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'bad_setting_params', $r->get_error_code() );

		// Error: missing_value_param.
		$r = $post_type->save( array( 'uuid' => self::UUID, 'data' => array( 'foo' => array( 'bar' => 'quux' ) ) ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'missing_value_param', $r->get_error_code() );

		$data = array(
			'foo' => array( 'value' => 'bar' ),
		);

		// Error: bad_status.
		$r = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'noooo',
		) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertEquals( 'bad_status', $r->get_error_code() );

		// Success without data.
		$r = $post_type->save( array( 'uuid' => self::UUID ) );
		$this->assertInternalType( 'int', $r );
		$this->assertEquals( array(), $post_type->get_post_content( get_post( $r ) ) );
		wp_delete_post( $r, true );

		// Success with data.
		$r = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => $data,
			'status' => 'publish',
			'theme' => get_stylesheet(),
		) );
		$this->assertInternalType( 'int', $r );
		$this->assertEquals( $data, $post_type->get_post_content( get_post( $r ) ) );

		$this->assertEquals( get_stylesheet(), get_post_meta( $r, '_snapshot_theme', true ) );
		$this->assertEquals( $this->plugin->version, get_post_meta( $r, '_snapshot_version', true ) );
	}

	/**
	 * Test granting customize capability.
	 *
	 * @see Post_Type::filter_user_has_cap()
	 */
	function test_filter_user_has_cap() {
		remove_all_filters( 'user_has_cap' );

		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();

		$post_id = $post_type->save( array(
			'uuid' => self::UUID,
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
		) );

		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * Snapshot publish.
	 *
	 * @see Post_Type::publish_snapshot()
	 */
	function test_publish_snapshot() {
		$admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$post_type = new Post_Type( $this->plugin->customize_snapshot_manager );
		$post_type->register();
		$tag_line = 'Snapshot blog';

		$post_id = $this->factory()->post->create( array(
			'post_name' => self::UUID,
			'post_title' => self::UUID,
			'uuid' => self::UUID,
			'post_content' => wp_json_encode( array( 'blogdescription' => array( 'value' => $tag_line ) ) ),
			'post_status' => 'draft',
			'post_type' => Post_Type::SLUG,
		) );

		wp_update_post( array(
			'ID' => $post_id,
			'post_status' => 'publish',
		) );
		$this->assertEquals( $tag_line, get_bloginfo( 'description' ) );
	}
}
