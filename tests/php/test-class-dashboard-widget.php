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
	 * Test handle_future_snapshot_preview_request
	 */
	public function test_handle_future_snapshot_preview_request() {
		// Setup.
		$manager = new Customize_Snapshot_Manager( new Plugin() );
		$post_type_obj = new Post_Type( $manager );
		$date = gmdate( 'Y-m-d H:i:s', ( time() + DAY_IN_SECONDS + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
		$search_date = gmdate( 'Y-m-d H:i:s', ( time() + DAY_IN_SECONDS + DAY_IN_SECONDS + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) );
		$date_time = new \DateTime( $search_date );
		$post_type_obj->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'status' => 'future',
			'post_date' => $date,
			'post_date_gmt' => '0000-00-00 00:00:00',
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
			'edit_date' => current_time( 'mysql' ),
		) );
		$post_type_obj->save( array(
			'uuid' => Customize_Snapshot_Manager::generate_uuid(),
			'status' => 'future',
			'post_date' => $date,
			'post_date_gmt' => '0000-00-00 00:00:00',
			'data' => array( 'foo' => array( 'value' => 'bar' ) ),
			'edit_date' => current_time( 'mysql' ),
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
		$post_type_obj = $this->getMockBuilder( 'CustomizeSnapshots\Post_Type' )
		                      ->setConstructorArgs( array( $manager ) )
		                      ->setMethods( array( 'handle_snapshot_merge_bulk_actions' ) )
		                      ->getMock();
		$post_type_obj->expects( $this->once() )
		              ->method( 'handle_snapshot_merge_bulk_actions' )
		              ->will( $this->returnValue( null ) );
		$manager->post_type = $post_type_obj;
		$dashboard = new Dashboard_Widget( $manager );
		$dashboard->handle_future_snapshot_preview_request();
	}
}
