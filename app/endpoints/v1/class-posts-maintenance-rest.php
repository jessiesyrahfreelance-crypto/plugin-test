<?php
/**
 * Posts Maintenance REST/AJAX and background scan logic.
 *
 * @since 1.0.0
 * @package WPMUDEV\PluginTest
 */
namespace WPMUDEV\PluginTest\Endpoints\V1;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

class Posts_Maintenance_API extends Base {

	/**
	 * Option key to persist jobs map.
	 */
	private const JOBS_OPTION = 'wpmudev_pm_jobs';

	/**
	 * Batch size to process per tick.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Job expiration (seconds) after completion to keep progress visible.
	 */
	private const JOB_TTL = 3600; // 1 hour.

	public function init() {
		// Legacy synchronous manual scan (kept for compatibility).
		add_action( 'wp_ajax_wpmudev_scan_posts', array( $this, 'ajax_scan_posts' ) );

		// New async manual scan: start + progress polling.
		add_action( 'wp_ajax_wpmudev_scan_posts_start', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_wpmudev_scan_posts_progress', array( $this, 'ajax_scan_progress' ) );

		// Background batch processor (WP-Cron single event).
		add_action( 'wpmudev_pm_process_batch', array( $this, 'process_batch' ), 10, 1 );

		// Cleanup old jobs.
		add_action( 'wpmudev_pm_cleanup_job', array( $this, 'cleanup_job' ), 10, 1 );

		// Daily scheduled full scan (as required).
		add_action( 'wpmudev_daily_posts_scan', array( $this, 'cron_scan_posts' ) );
		if ( ! wp_next_scheduled( 'wpmudev_daily_posts_scan' ) ) {
			wp_schedule_event( time(), 'daily', 'wpmudev_daily_posts_scan' );
		}
	}

	/**
	 * Legacy synchronous AJAX handler for scanning posts.
	 */
	public function ajax_scan_posts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'wpmudev-plugin-test' ) );
		}

		$post_types = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : array( 'post', 'page' );
		$post_types = $this->sanitize_and_filter_post_types( $post_types );

		$result = $this->do_scan_sync( $post_types );
		wp_send_json_success( $result );
	}

	/**
	 * Start an async scan job, process first batch immediately, and schedule background processing.
	 */
	public function ajax_start_scan() {
		check_ajax_referer( 'wpmudev_pm' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'wpmudev-plugin-test' ), 403 );
		}

		$post_types = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : array( 'post', 'page' );
		$post_types = $this->sanitize_and_filter_post_types( $post_types );

		// Get IDs per type so we can build per-type totals and track processed counts by type.
		$ids_map        = $this->get_ids_map_for_post_types( $post_types ); // [type => [ids...]]
		$totals_by_type = array();
		$all_ids        = array();
		foreach ( $ids_map as $type => $ids ) {
			$totals_by_type[ $type ] = count( $ids );
			$all_ids                 = array_merge( $all_ids, $ids );
		}

		// Extras: categories total count (for display only).
		$categories_total = 0;
		try {
			$categories_total = (int) wp_count_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				)
			);
		} catch ( \Throwable $e ) {
			$categories_total = 0;
		}

		$total = count( $all_ids );

		$job_id = wp_generate_uuid4();
		$now    = time();

		$job = array(
			'id'          => $job_id,
			'post_types'  => array_values( $post_types ),
			'pending_ids' => $all_ids, // Remaining IDs to process (flat list).
			'processed'   => 0,
			'errors'      => 0,
			'total'       => $total,
			'status'      => 'running', // running|completed|failed|canceled
			'created_at'  => $now,
			'updated_at'  => $now,
			'completed_at'=> 0,
			'breakdown'   => array(
				'totals'    => $totals_by_type,                                   // [type => total]
				'processed' => array_fill_keys( array_keys( $totals_by_type ), 0 ), // [type => processed]
				'extras'    => array( 'categories' => $categories_total ),
			),
		);

		// Process first batch inline so users see immediate progress.
		$this->process_next_batch( $job );
		$this->save_job( $job );

		// Schedule a follow-up batch if there is remaining work and no event queued yet.
		if ( 'running' === $job['status'] && ! wp_next_scheduled( 'wpmudev_pm_process_batch', array( $job['id'] ) ) ) {
			wp_schedule_single_event( time() + 1, 'wpmudev_pm_process_batch', array( $job['id'] ) );
		}

		wp_send_json_success(
			array(
				'job_id'    => $job_id,
				'total'     => $total,
				'postTypes' => $job['post_types'],
			)
		);
	}

	/**
	 * Return progress for a running/completed job and process one batch inline to ensure forward progress.
	 */
	public function ajax_scan_progress() {
		check_ajax_referer( 'wpmudev_pm' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'wpmudev-plugin-test' ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( (string) $_POST['job_id'] ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( __( 'Missing job ID.', 'wpmudev-plugin-test' ) );
		}

		$job = $this->get_job( $job_id );
		if ( empty( $job ) ) {
			wp_send_json_error( __( 'Job not found.', 'wpmudev-plugin-test' ) );
		}

		// Ensure progress advances even if WP-Cron is not firing:
		if ( 'running' === $job['status'] ) {
			$this->process_next_batch( $job );
			$this->save_job( $job );

			// Keep a scheduled event as a fallback, but avoid duplicates.
			if ( 'running' === $job['status'] && ! wp_next_scheduled( 'wpmudev_pm_process_batch', array( $job['id'] ) ) ) {
				wp_schedule_single_event( time() + 1, 'wpmudev_pm_process_batch', array( $job['id'] ) );
			}
		}

		$percent = ( $job['total'] > 0 ) ? (int) floor( ( $job['processed'] / $job['total'] ) * 100 ) : 100;

		wp_send_json_success(
			array(
				'job_id'      => $job['id'],
				'status'      => $job['status'],
				'processed'   => (int) $job['processed'],
				'errors'      => (int) $job['errors'],
				'total'       => (int) $job['total'],
				'percent'     => $percent,
				'updatedAt'   => (int) $job['updated_at'],
				'completedAt' => (int) $job['completed_at'],
				'breakdown'   => isset( $job['breakdown'] ) ? $job['breakdown'] : array( 'totals' => array(), 'processed' => array(), 'extras' => array() ),
			)
		);
	}

	/**
	 * Background batch processor: runs via WP-Cron single events.
	 *
	 * @param string $job_id
	 */
	public function process_batch( $job_id ) {
		$job_id = (string) $job_id;
		if ( '' === $job_id ) {
			return;
		}

		$job = $this->get_job( $job_id );
		if ( empty( $job ) ) {
			return;
		}
		if ( 'running' !== $job['status'] ) {
			return;
		}

		$this->process_next_batch( $job );
		$this->save_job( $job );

		// If still running, schedule next batch; avoid duplicate queueing.
		if ( 'running' === $job['status'] && ! wp_next_scheduled( 'wpmudev_pm_process_batch', array( $job['id'] ) ) ) {
			wp_schedule_single_event( time() + 1, 'wpmudev_pm_process_batch', array( $job['id'] ) );
		}
	}

	/**
	 * Process up to BATCH_SIZE IDs and update the job (in memory).
	 *
	 * @param array $job
	 * @return void
	 */
	private function process_next_batch( array &$job ) {
		if ( empty( $job['pending_ids'] ) ) {
			// Nothing to do.
			if ( 'running' === $job['status'] ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				$job['updated_at']   = time();
			}
			return;
		}

		$now       = time();
		$batch_ids = array_splice( $job['pending_ids'], 0, self::BATCH_SIZE );
		$processed_this_batch = 0;
		$errors_this_batch    = 0;

		foreach ( $batch_ids as $post_id ) {
			$post_id = (int) $post_id;

			$ok = update_post_meta( $post_id, 'wpmudev_test_last_scan', $now );

			// update_post_meta() returns false if update failed OR value unchanged; treat unchanged as processed.
			if ( false === $ok ) {
				$existing = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
				if ( (string) $existing === (string) $now ) {
					$processed_this_batch++;
				} else {
					$errors_this_batch++;
				}
			} else {
				$processed_this_batch++;
			}

			// Increment per-type processed counter.
			$type = get_post_type( $post_id );
			if ( $type && isset( $job['breakdown']['processed'][ $type ] ) ) {
				$job['breakdown']['processed'][ $type ]++;
			}
		}

		$job['processed']  += $processed_this_batch;
		$job['errors']     += $errors_this_batch;
		$job['updated_at']  = $now;

		if ( empty( $job['pending_ids'] ) ) {
			$job['status']       = 'completed';
			$job['completed_at'] = $now;
		}
	}

	/**
	 * Cleanup job storage after TTL.
	 *
	 * @param string $job_id
	 */
	public function cleanup_job( $job_id ) {
		$jobs = $this->get_jobs();
		if ( isset( $jobs[ $job_id ] ) ) {
			unset( $jobs[ $job_id ] );
			$this->save_jobs( $jobs );
		}
	}

	/**
	 * Daily cron handler for full scan (synchronous).
	 */
	public function cron_scan_posts() {
		$this->do_scan_sync( array( 'post', 'page' ) );
	}

	/**
	 * Synchronous scan utility used by legacy AJAX and daily cron.
	 *
	 * @param array $post_types
	 * @return array
	 */
	private function do_scan_sync( $post_types = array( 'post', 'page' ) ) {
		$ids = $this->get_ids_for_post_types( $post_types );

		$processed = 0;
		$errors    = 0;
		$now       = time();

		foreach ( $ids as $id ) {
			$ok = update_post_meta( (int) $id, 'wpmudev_test_last_scan', $now );
			if ( false === $ok ) {
				$existing = get_post_meta( (int) $id, 'wpmudev_test_last_scan', true );
				if ( (string) $existing === (string) $now ) {
					$processed++;
				} else {
					$errors++;
				}
			} else {
				$processed++;
			}
		}

		return array(
			'processed'  => $processed,
			'errors'     => $errors,
			'post_types' => array_values( $post_types ),
			'total'      => count( $ids ),
		);
	}

	/**
	 * Query IDs for the given post types.
	 *
	 * @param array $post_types
	 * @return int[]
	 */
	private function get_ids_for_post_types( $post_types ) {
		$args  = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		$query = new \WP_Query( $args );

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Query IDs per type for the given post types. Returns [type => [ids...]].
	 *
	 * @param array $post_types
	 * @return array
	 */
	private function get_ids_map_for_post_types( $post_types ) {
		$map = array();
		foreach ( (array) $post_types as $type ) {
			$type = sanitize_key( $type );
			if ( '' === $type ) {
				continue;
			}
			$args  = array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			);
			$query      = new \WP_Query( $args );
			$map[ $type ] = array_map( 'intval', (array) $query->posts );
		}
		return $map;
	}

	/**
	 * Sanitize and limit to public post types only.
	 *
	 * @param array $post_types
	 * @return array
	 */
	private function sanitize_and_filter_post_types( $post_types ) {
		$post_types = array_map( 'sanitize_key', (array) $post_types );
		$post_types = array_filter( $post_types );

		$public_types = get_post_types( array( 'public' => true ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		// Keep only valid public types.
		$post_types = array_values( array_intersect( $post_types, array_keys( $public_types ) ) );

		// Fallback to post/page if result empty.
		$post_types = ! empty( $post_types ) ? $post_types : array( 'post', 'page' );

		return $post_types;
	}

	/**
	 * Jobs storage helpers.
	 */
	private function get_jobs() {
		$jobs = get_option( self::JOBS_OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	private function save_jobs( $jobs ) {
		$jobs = is_array( $jobs ) ? $jobs : array();
		if ( false === get_option( self::JOBS_OPTION, false ) ) {
			// Do not autoload potentially large data.
			add_option( self::JOBS_OPTION, $jobs, '', 'no' );
		} else {
			update_option( self::JOBS_OPTION, $jobs );
		}
	}

	private function get_job( $job_id ) {
		$jobs = $this->get_jobs();
		return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : array();
	}

	private function save_job( $job ) {
		if ( empty( $job['id'] ) ) {
			return;
		}
		$jobs               = $this->get_jobs();
		$jobs[ $job['id'] ] = $job;
		$this->save_jobs( $jobs );
	}
}