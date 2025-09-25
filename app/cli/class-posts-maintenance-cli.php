<?php
/**
 * WP-CLI: Posts Maintenance command.
 *
 * @link    https://wpmudev.com/
 * @since   1.0.0
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\CLI;

defined( 'WPINC' ) || die;

use WP_CLI;
use WP_CLI_Command;

/**
 * Manage Posts Maintenance scans via WP-CLI.
 */
class Posts_Maintenance_CLI extends WP_CLI_Command {

	/**
	 * Register the command.
	 *
	 * @return void
	 */
	public static function register() {
		// Command name: "wp wpmudev posts-scan".
		\WP_CLI::add_command( 'wpmudev posts-scan', __CLASS__ );
	}

	/**
	 * Scan public posts and pages and update maintenance meta.
	 *
	 * Updates post meta "wpmudev_test_last_scan" with current timestamp
	 * for each processed post ID, same as the admin interface.
	 *
	 * ## OPTIONS
	 *
	 * [--types=<types>]
	 * : Comma-separated list of post types to scan.
	 * If omitted, all public post types are scanned (e.g., post,page,...).
	 *
	 * [--batch=<number>]
	 * : Process posts in chunks to reduce memory usage.
	 * default: 200
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan default public post types.
	 *     $ wp wpmudev posts-scan
	 *
	 *     # Scan only posts and pages.
	 *     $ wp wpmudev posts-scan --types=post,page
	 *
	 *     # Scan CPTs too.
	 *     $ wp wpmudev posts-scan --types=post,page,product
	 *
	 *     # Use a smaller batch size on constrained environments.
	 *     $ wp wpmudev posts-scan --batch=100
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Assoc args.
	 */
	public function __invoke( $args, $assoc_args ) {
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 200;

		// Determine post types.
		$types_input = isset( $assoc_args['types'] ) ? (string) $assoc_args['types'] : '';
		if ( '' !== $types_input ) {
			$post_types = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $types_input ) ) ) );
		} else {
			// All public post types by default.
			$post_types = array_keys( get_post_types( array( 'public' => true ) ) );
		}

		// Validate against public types only.
		$public_types = array_keys( get_post_types( array( 'public' => true ) ) );
		$post_types   = array_values( array_intersect( $post_types, $public_types ) );

		if ( empty( $post_types ) ) {
			WP_CLI::error( 'No valid public post types to scan. Use --types=post,page or omit to scan all public types.' );
		}

		// Gather IDs per type and totals.
		$ids_by_type     = array();
		$totals_by_type  = array();
		$all_ids         = array();

		foreach ( $post_types as $type ) {
			$ids_by_type[ $type ]    = $this->get_ids_for_type( $type );
			$totals_by_type[ $type ] = count( $ids_by_type[ $type ] );
			$all_ids                 = array_merge( $all_ids, $ids_by_type[ $type ] );
		}

		$total = count( $all_ids );
		if ( 0 === $total ) {
			WP_CLI::success( 'No published posts found for the selected types.' );
			return;
		}

		$processed_by_type = array_fill_keys( $post_types, 0 );
		$errors            = 0;
		$processed_total   = 0;
		$now               = time();

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning posts', $total );

		// Process in batches to manage memory.
		for ( $offset = 0; $offset < $total; $offset += $batch ) {
			$batch_ids = array_slice( $all_ids, $offset, $batch );

			foreach ( $batch_ids as $post_id ) {
				$post_id = (int) $post_id;

				// Update last scan meta (same as admin).
				$ok = update_post_meta( $post_id, 'wpmudev_test_last_scan', $now );

				// Treat unchanged value as processed; only count truly failed as error.
				if ( false === $ok ) {
					$existing = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
					if ( (string) $existing !== (string) $now ) {
						$errors++;
					} else {
						$processed_total++;
						$type = get_post_type( $post_id );
						if ( isset( $processed_by_type[ $type ] ) ) {
							$processed_by_type[ $type ]++;
						}
					}
				} else {
					$processed_total++;
					$type = get_post_type( $post_id );
					if ( isset( $processed_by_type[ $type ] ) ) {
						$processed_by_type[ $type ]++;
					}
				}

				$progress->tick();
			}

			// Best-effort small pause to avoid hogging resources.
			usleep( 10000 ); // 10ms
		}

		$progress->finish();

		// Categories count (display only).
		$categories_total = 0;
		try {
			$categories_total = (int) wp_count_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore PHPCompatibility.LanguageConstructs.NewLanguageConstructs.t_throwableFound
			$categories_total = 0;
		}

		// Summary lines.
		WP_CLI::log( '' );
		WP_CLI::log( 'Summary:' );
		foreach ( $post_types as $type ) {
			$label = ( 'post' === $type ) ? 'posts' : ( 'page' === $type ? 'pages' : ( rtrim( $type, 's' ) === $type ? $type . 's' : $type ) );
			WP_CLI::log( sprintf( '  %s: %d / %d', $label, (int) $processed_by_type[ $type ], (int) $totals_by_type[ $type ] ) );
		}
		WP_CLI::log( sprintf( '  categories: %d', $categories_total ) );
		WP_CLI::log( sprintf( 'Processed: %d | Errors: %d | Total: %d', $processed_total, $errors, $total ) );

		if ( $errors > 0 ) {
			WP_CLI::warning( 'Completed with errors.' );
		} else {
			WP_CLI::success( 'Scan completed successfully.' );
		}
	}

	/**
	 * Get IDs for a single post type.
	 *
	 * @param string $type Post type.
	 * @return int[] IDs.
	 */
	private function get_ids_for_type( $type ) {
		$args  = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		$query = new \WP_Query( $args );

		return array_map( 'intval', (array) $query->posts );
	}
}
