<?php
/**
 * Posts Maintenance REST endpoint and background scan logic.
 *
 * @since 1.0.0
 * @package WPMUDEV\PluginTest
 */
namespace WPMUDEV\PluginTest\Endpoints\V1;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

class Posts_Maintenance_API extends Base {
	public function init() {
		add_action( 'wp_ajax_wpmudev_scan_posts', array( $this, 'ajax_scan_posts' ) );
		add_action( 'wpmudev_daily_posts_scan', array( $this, 'cron_scan_posts' ) );
		if ( ! wp_next_scheduled( 'wpmudev_daily_posts_scan' ) ) {
			wp_schedule_event( time(), 'daily', 'wpmudev_daily_posts_scan' );
		}
	}

	/**
	 * AJAX handler for scanning posts.
	 */
	public function ajax_scan_posts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'wpmudev-plugin-test' ) );
		}

		$post_types = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : array( 'post', 'page' );
		$result = $this->do_scan( $post_types );
		wp_send_json_success( $result );
	}

	/**
	 * Cron handler for daily scan.
	 */
	public function cron_scan_posts() {
		$this->do_scan( array( 'post', 'page' ) );
	}

	/**
	 * Scan function.
	 */
	public function do_scan( $post_types = array( 'post', 'page' ) ) {
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		$query = new \WP_Query( $args );
		$ids = $query->posts;
		$processed = 0;
		$errors = 0;
		$now = time();

		foreach ( $ids as $id ) {
			if ( update_post_meta( $id, 'wpmudev_test_last_scan', $now ) ) {
				$processed++;
			} else {
				$errors++;
			}
		}
		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'post_types'=> $post_types,
		);
	}
}