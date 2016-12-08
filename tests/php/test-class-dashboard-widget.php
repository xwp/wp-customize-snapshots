<?php
/**
 * Test Dashboard Widget.
 *
 * @package CustomizeSnapshots
 */

namespace CustomizeSnapshots;

/**
 * Class Test_Dashboard_Widget
 */
class Test_Dashboard_Widget extends \WP_UnitTestCase {

	/**
	 * Test Dashboard_Widget constructor.
	 */
	public function test_constructor() {
		$dashboard = new Dashboard_Widget( get_plugin_instance()->customize_snapshot_manager );
		$this->assertEquals( 10, has_action( 'wp_dashboard_setup', array( $dashboard, 'add_widget' ) ) );
		$this->assertEquals( 10, has_action( 'load-index.php', array( $dashboard, 'handle_future_snapshot_preview_request' ) ) );
	}

	/**
	 * Test enqueue_admin_dashboard_scripts
	 *
	 * @covers CustomizeSnapshots\Dashboard_Widget::enqueue_admin_dashboard_scripts()
	 */
	public function test_enqueue_admin_dashboard_scripts() {
		$plugin = get_plugin_instance();
		$plugin->register_styles( wp_styles() );
		$manager = new Dashboard_Widget( get_plugin_instance()->customize_snapshot_manager );
		$manager->enqueue_admin_dashboard_scripts( 'index.php' );
		$this->assertTrue( wp_style_is( 'customize-snapshots-dashboard', 'enqueued' ) );
	}

	/**
	 * Test add_widget
	 *
	 * @covers CustomizeSnapshots\Dashboard_Widget::add_widget()
	 */
	public function test_add_widget() {
		global $wp_meta_boxes;
		$dashboard = new Dashboard_Widget( get_plugin_instance()->customize_snapshot_manager );
		$metabox_id = 'customize_site_state_future_snapshot_preview';
		set_current_screen( 'index.php' );
		$this->assertFalse( ! empty( $wp_meta_boxes['dashboard']['normal']['core'][ $metabox_id ] ) );
		require_once( ABSPATH . 'wp-admin/includes/dashboard.php' );
		$dashboard->add_widget();
		$this->assertTrue( ! empty( $wp_meta_boxes['dashboard']['normal']['core'][ $metabox_id ] ) );
	}

	/**
	 * Test render_widget
	 *
	 * @covers CustomizeSnapshots\Dashboard_Widget::render_widget()
	 */
	public function test_render_widget() {
		$dashboard = new Dashboard_Widget( get_plugin_instance()->customize_snapshot_manager );
		$dashboard->error_code = 1;
		ob_start();
		$dashboard->render_widget();
		$widget_content = ob_get_clean();
		$this->assertContains( 'name="year"', $widget_content );
		$this->assertContains( 'name="month"', $widget_content );
		$this->assertContains( 'name="day"', $widget_content );
		$this->assertContains( 'name="hour"', $widget_content );
		$this->assertContains( 'name="minute"', $widget_content );
	}

	/**
	 * Get snapshot manager instance.
	 */
	public function get_new_plugin_instance() {
		$plugin = new Plugin();
		$plugin->init();
		return $plugin;
	}

	/**
	 * Test handle_future_snapshot_preview_request
	 */
	public function test_handle_future_snapshot_preview_request() {
		// Setup.
		$plugin = $this->get_new_plugin_instance();
		$manager = $plugin->customize_snapshot_manager;
		$post_type_obj = $manager->post_type;
		$date = gmdate( 'Y-m-d H:i:s', ( time() + DAY_IN_SECONDS + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
		$search_date = gmdate( 'Y-m-d H:i:s', ( time() + DAY_IN_SECONDS + DAY_IN_SECONDS + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
		$date_time = new \DateTime( $search_date );
		$data = array( 'foo' => array( 'value' => 'bar' ) );
		$post_id_1 = $post_type_obj->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'status' => 'future',
			'date_gmt' => $date,
			'data' => $data,
		) );
		$post_id_2 = $post_type_obj->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'status' => 'future',
			'date_gmt' => $date,
			'data' => $data,
		) );

		// Setup post var.
		$GLOBALS['hook_suffix'] = 'index.php'; // WPCS: global override ok.
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( 'customize_site_state_future_snapshot_preview' );
		$_POST['_wp_http_referer'] = $_REQUEST['_wp_http_referer'] = admin_url();
		$_POST['customize-future-snapshot-preview'] = 1;
		$_POST['year'] = $date_time->format( 'Y' );
		$_POST['month'] = $date_time->format( 'm' );
		$_POST['day'] = $date_time->format( 'd' );
		$_POST['hour'] = $date_time->format( 'H' );
		$_POST['minute'] = $date_time->format( 'i' );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Mock handle_snapshot_merge_bulk_actions.
		$post_type_obj = $this->getMockBuilder( get_class( $post_type_obj ) )
		                      ->setConstructorArgs( array( $manager ) )
		                      ->setMethods( array( 'merge_snapshots' ) )
		                      ->getMock();
		$post_type_obj->expects( $this->once() )
		              ->method( 'merge_snapshots' )
		              ->will( $this->returnValue( null ) );
		$manager->post_type = $post_type_obj;
		$dashboard = new Dashboard_Widget( $manager );
		$dashboard->handle_future_snapshot_preview_request();
		$this->assertEquals( 3, $dashboard->error_code );
		wp_update_post( array(
			'post_status' => 'draft',
			'ID' => $post_id_1,
			'post_date' => current_time( 'mysql' ),
		) );

		// Case: Only 1 snapshot to preview for future state so create duplicate.
		add_filter( 'wp_redirect', '__return_null', 99 );
		$new_duplicate_post_id = $dashboard->handle_future_snapshot_preview_request();
		$duplicate_post = get_post( $new_duplicate_post_id );
		$this->assertEquals( 'auto-draft', $duplicate_post->post_status );
		$this->assertEquals( '1', get_post_meta( $duplicate_post->ID, 'is_future_preview', true ) );
		$this->assertSame( $data, $dashboard->manager->post_type->get_post_content( $duplicate_post ) );
		remove_filter( 'wp_redirect', '__return_null', 99 );

		wp_update_post( array(
			'post_status' => 'draft',
			'ID' => $post_id_2,
			'post_date' => current_time( 'mysql' ),
		) );
		$dashboard->handle_future_snapshot_preview_request();
		$this->assertEquals( 2, $dashboard->error_code );

		$_POST['year'] = '2010';
		$dashboard->handle_future_snapshot_preview_request();
		$this->assertEquals( 1, $dashboard->error_code );

		unset( $_POST['year'] );
		$dashboard->error_code = 0;
		$dashboard->handle_future_snapshot_preview_request();
		$this->assertEquals( 1, $dashboard->error_code );

		unset( $_POST['customize-future-snapshot-preview'] );
		$dashboard->handle_future_snapshot_preview_request();

	}
}
