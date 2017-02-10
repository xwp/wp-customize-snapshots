<?php
/**
 * Test Post Type Back Compat.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Post_type_Back_Compat
 */
class Test_Post_Type_Back_Compat extends \WP_UnitTestCase {

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
		if ( ! $this->plugin->compat ) {
			$this->markTestSkipped( 'WordPress Version 4.6.x or below is required for this test-case.' );
		}
		$GLOBALS['wp_customize'] = null; // WPCS: Global override ok.
		unregister_post_type( Post_Type_Back_Compat::SLUG );
	}

	/**
	 * Test register post type.
	 *
	 * @see Post_Type::init()
	 */
	public function test_init() {
		$this->assertFalse( post_type_exists( Post_Type_Back_Compat::SLUG ) );
		$post_type_obj = new Post_Type_Back_Compat( $this->plugin->customize_snapshot_manager );
		$this->plugin->customize_snapshot_manager->init();
		$post_type_obj->init();
		$this->assertTrue( post_type_exists( Post_Type_Back_Compat::SLUG ) );

		$this->assertEquals( 10, has_action( 'admin_notices', array( $post_type_obj, 'show_publish_error_admin_notice' ) ) );
		$this->assertEquals( 10, has_filter( 'display_post_states', array( $post_type_obj, 'display_post_states' ) ) );
		$this->assertEquals( 10, has_action( 'admin_footer-edit.php', array( $post_type_obj, 'snapshot_merge_print_script' ) ) );
		$this->assertEquals( 10, has_action( 'load-edit.php', array( $post_type_obj, 'handle_snapshot_merge_workaround' ) ) );
		$this->assertEquals( 10, has_filter( 'post_type_link', array( $post_type_obj, 'filter_post_type_link' ) ) );
		$this->assertEquals( 10, has_filter( 'wp_insert_post_data', array( $post_type_obj, 'preserve_post_name_in_insert_data' ) ) );
	}

	/**
	 * Tests show_publish_error_admin_notice.
	 *
	 * @covers \CustomizeSnapshots\Post_Type_Back_Compat::show_publish_error_admin_notice()
	 */
	public function test_show_publish_error_admin_notice() {
		global $current_screen, $post;
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post_type_obj = new Post_Type_Back_Compat( $this->plugin->customize_snapshot_manager );
		$post_type_obj->init();
		$post_id = $post_type_obj->save( array(
			'uuid' => self::UUID,
			'data' => array(),
		) );

		ob_start();
		$post_type_obj->show_publish_error_admin_notice();
		$this->assertEmpty( ob_get_clean() );

		$current_screen = \WP_Screen::get( 'customize_snapshot' ); // WPCS: Override ok.
		$current_screen->id = 'customize_snapshot';
		$current_screen->base = 'edit';
		ob_start();
		$post_type_obj->show_publish_error_admin_notice();
		$this->assertEmpty( ob_get_clean() );

		$current_screen->base = 'post';
		ob_start();
		$post_type_obj->show_publish_error_admin_notice();
		$this->assertEmpty( ob_get_clean() );

		$_REQUEST['snapshot_error_on_publish'] = '1';
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
		$post = get_post( $post_id ); // WPCS: override ok.
		ob_start();
		$post_type_obj->show_publish_error_admin_notice();
		$this->assertContains( 'notice-error', ob_get_clean() );
	}

	/**
	 * Tests display_post_states.
	 *
	 * @covers \CustomizeSnapshots\Post_Type_Back_Compat::display_post_states()
	 */
	public function test_display_post_states() {
		$post_type_obj = new Post_Type_Back_Compat( $this->plugin->customize_snapshot_manager );

		$post_id = $post_type_obj->save( array(
			'uuid' => self::UUID,
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
		) );
		$states = $post_type_obj->display_post_states( array(), get_post( $post_id ) );
		$this->assertArrayNotHasKey( 'snapshot_error', $states );

		update_post_meta( $post_id, 'snapshot_error_on_publish', true );
		$states = $post_type_obj->display_post_states( array(), get_post( $post_id ) );
		$this->assertArrayHasKey( 'snapshot_error', $states );
	}

	/**
	 * Test snapshot_merge_print_script
	 *
	 * @see Post_Type_Back_Compat::snapshot_merge_print_script()
	 */
	public function test_snapshot_merge_print_script() {
		global $post_type;
		$post_type = Post_Type_Back_Compat::SLUG; // WPCS: global override ok.
		$post_type_obj = new Post_Type_Back_Compat( $this->plugin->customize_snapshot_manager );
		ob_start();
		$post_type_obj->snapshot_merge_print_script();
		$script_content = ob_get_clean();

		$this->assertContains( 'select[name="action"]', $script_content );
		$this->assertContains( 'select[name="action2"]', $script_content );
		$this->assertContains( 'merge_snapshot', $script_content );
		$this->assertContains( 'text/javascript', $script_content );
	}

	/**
	 * Test handle_snapshot_bulk_actions_workaround
	 *
	 * @see Post_Type_Back_Compat::handle_snapshot_merge_workaround()
	 */
	public function test_handle_snapshot_bulk_actions_workaround() {
		$GLOBALS['hook_suffix'] = 'posts-' . Post_Type_Back_Compat::SLUG; // WPCS: global override ok.
		$_POST['action'] = $_REQUEST['action'] = $_GET['action'] = 'merge_snapshot';
		$_POST['post_type'] = $_REQUEST['post_type'] = $_GET['post_type'] = Post_Type_Back_Compat::SLUG;
		$_POST['post'] = $_REQUEST['post'] = $_GET['post'] = array( 1, 2 );
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = $_GET['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
		$_POST['_wp_http_referer'] = $_REQUEST['_wp_http_referer'] = $_GET['_wp_http_referer'] = admin_url();
		$post_type_obj = $this->getMockBuilder( 'CustomizeSnapshots\Post_Type_Back_Compat' )
		                      ->setConstructorArgs( array( $this->plugin->customize_snapshot_manager ) )
		                      ->setMethods( array( 'handle_snapshot_merge' ) )
		                      ->getMock();
		$post_type_obj->expects( $this->once() )
		              ->method( 'handle_snapshot_merge' )
		              ->will( $this->returnValue( null ) );
		$post_type_obj->handle_snapshot_merge_workaround();
	}

	/**
	 * Tests preservation of the post_name when submitting a snapshot for review.
	 *
	 * @see Post_Type_Back_Compat::preserve_post_name_in_insert_data()
	 */
	public function test_preserve_post_name_in_insert_data() {
		$post_type_obj = new Post_Type_Back_Compat( $this->plugin->customize_snapshot_manager );
		$post_type_obj->init();

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
		$filtered_post_data = $post_type_obj->preserve_post_name_in_insert_data( $post_data, $original_post_data );
		$this->assertEquals( $post_data, $filtered_post_data );

		$post_data['post_type'] = Post_Type_Back_Compat::SLUG;
		$original_post_data['post_type'] = Post_Type_Back_Compat::SLUG;

		$filtered_post_data = $post_type_obj->preserve_post_name_in_insert_data( $post_data, $original_post_data );
		$this->assertEquals( $original_post_data['post_name'], $filtered_post_data['post_name'] );
	}

	/**
	 * Snapshot publish.
	 *
	 * @see Post_Type::save()
	 */
	function test_publish_snapshot() {
		$admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$post_type = get_plugin_instance()->customize_snapshot_manager->post_type;
		$post_type->init();
		$tag_line = 'Snapshot blog';

		$data = array(
			'blogdescription' => array(
				'value' => $tag_line,
			),
			'foo' => array(
				'value' => 'bar',
			),
			'baz' => array(
				'value' => null,
			),
		);

		$validated_content = array(
			'blogdescription' => array(
				'value' => $tag_line,
			),
			'foo' => array(
				'value' => 'bar',
				'publish_error' => 'unrecognized_setting',
			),
			'baz' => array(
				'value' => null,
				'publish_error' => 'null_value',
			),
		);

		/*
		 * Ensure that directly updating a post succeeds with invalid settings
		 * works because the post is a draft. Note that if using
		 * Customize_Snapshot::set() this would fail because it does validation.
		 */
		$post_id = $post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$content = $post_type->get_post_content( get_post( $post_id ) );
		$this->assertEquals( $data, $content );

		/*
		 * Ensure that attempting to publish a snapshot with invalid settings
		 * will get the publish_errors added as well as kick it back to pending.
		 */
		remove_all_filters( 'redirect_post_location' );
		$post_id = $post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		wp_publish_post( $post_id );
		$snapshot_post = get_post( $post_id );
		$content = $post_type->get_post_content( $snapshot_post );
		$this->assertEquals( 'pending', $snapshot_post->post_status );
		$this->assertEquals( $validated_content, $content );
		$this->assertContains(
			'snapshot_error_on_publish=1',
			apply_filters( 'redirect_post_location', get_edit_post_link( $snapshot_post->ID ), $snapshot_post->ID )
		);

		/*
		 * Remove invalid settings and now attempt publish.
		 */
		remove_all_filters( 'redirect_post_location' );
		unset( $data['foo'] );
		unset( $data['baz'] );
		$post_id = $post_type->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'data' => $data,
			'status' => 'draft',
		) );
		wp_publish_post( $post_id );
		$snapshot_post = get_post( $post_id );
		$content = $post_type->get_post_content( $snapshot_post );
		$this->assertEquals( 'publish', $snapshot_post->post_status );
		$this->assertEquals( $data, $content );
		$this->assertEquals( $tag_line, get_bloginfo( 'description' ) );
		$this->assertNotContains(
			'snapshot_error_on_publish=1',
			apply_filters( 'redirect_post_location', get_edit_post_link( $snapshot_post->ID ), $snapshot_post->ID )
		);
	}
}
