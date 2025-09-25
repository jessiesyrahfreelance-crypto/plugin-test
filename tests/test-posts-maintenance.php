<?php
/**
 * Unit tests for Posts Maintenance functionality.
 *
 * Covers:
 * - Daily cron synchronous scan (cron_scan_posts)
 * - Async scan start + progress (AJAX) and batch processing
 * - Permission and nonce checks (basic)
 *
 * @package Wpmudev_Plugin_Test
 */

if ( ! class_exists( 'WP_Ajax_UnitTestCase' ) ) {
	require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
	require_once dirname( dirname( __FILE__ ) ) . '/tests/phpunit/includes/testcase-ajax.php';
}

/**
 * Tests exercising AJAX flows using WP_Ajax_UnitTestCase.
 */
class PostsMaintenanceAjaxTest extends WP_Ajax_UnitTestCase {

	protected $admin_user_id;

	public function setUp(): void {
		parent::setUp();

		// Ensure the endpoint hooks are registered for tests (bypass Loader version checks).
		\WPMUDEV\PluginTest\Endpoints\V1\Posts_Maintenance_API::instance()->init();

		// Create and set an admin user to satisfy manage_options.
		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function tearDown(): void {
		// Clean up AJAX globals.
		unset( $_POST, $_GET, $_REQUEST );
		parent::tearDown();
	}

	/**
	 * Start an async job and drive progress until completion.
	 */
	public function test_start_scan_and_progress_completes() {
		// Create a small, deterministic data set.
		$post_ids  = self::factory()->post->create_many( 2, array( 'post_status' => 'publish', 'post_type' => 'post' ) );
		$page_ids  = self::factory()->post->create_many( 1, array( 'post_status' => 'publish', 'post_type' => 'page' ) );
		$draft_ids = self::factory()->post->create_many( 2, array( 'post_status' => 'draft',   'post_type' => 'post' ) ); // should be ignored

		// Kick off async scan via AJAX.
		$nonce = wp_create_nonce( 'wpmudev_pm' );
		$_POST = array(
			'action'      => 'wpmudev_scan_posts_start',
			'_ajax_nonce' => $nonce,
			// Intentionally omit post_types to cover default selection (post,page).
		);

		try {
			$this->_handleAjax( 'wpmudev_scan_posts_start' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: wp_send_json_* ends in wp_die during AJAX handlers.
		}

		$res = json_decode( $this->_last_response, true );
		$this->assertIsArray( $res, 'AJAX response should be JSON array.' );
		$this->assertTrue( (bool) $res['success'], 'Start should return success.' );
		$this->assertArrayHasKey( 'data', $res, 'Response should contain data.' );
		$this->assertArrayHasKey( 'job_id', $res['data'], 'Start should return job_id.' );
		$job_id = (string) $res['data']['job_id'];

		// Poll progress until completed; with 3 published items and batch size 50, one poll should complete.
		$_POST = array(
			'action'      => 'wpmudev_scan_posts_progress',
			'_ajax_nonce' => $nonce,
			'job_id'      => $job_id,
		);

		try {
			$this->_handleAjax( 'wpmudev_scan_posts_progress' );
		} catch ( WPAjaxDieContinueException $e ) {
		}

		$prog = json_decode( $this->_last_response, true );
		$this->assertIsArray( $prog );
		$this->assertTrue( (bool) $prog['success'] );
		$this->assertArrayHasKey( 'data', $prog );
		$data = $prog['data'];

		$this->assertEquals( 'completed', $data['status'], 'Job should complete for small data set.' );
		$this->assertSame( 0, (int) $data['errors'], 'No errors expected.' );
		$this->assertSame( 3, (int) $data['total'], 'Total should match published posts+pages only.' );
		$this->assertSame( 3, (int) $data['processed'], 'All published items should be processed.' );
		$this->assertSame( 100, (int) $data['percent'], 'Completion should be 100%.' );

		// Verify meta updated only for published, not for drafts.
		$nowish = time();
		foreach ( array_merge( $post_ids, $page_ids ) as $id ) {
			$meta = get_post_meta( $id, 'wpmudev_test_last_scan', true );
			$this->assertNotEmpty( $meta, 'Published content should have scan meta.' );
			$this->assertTrue( abs( (int) $meta - $nowish ) < DAY_IN_SECONDS, 'Meta timestamp should be near now.' );
		}
		foreach ( $draft_ids as $id ) {
			$meta = get_post_meta( $id, 'wpmudev_test_last_scan', true );
			$this->assertEmpty( $meta, 'Drafts should not be processed.' );
		}
	}

	/**
	 * Invalid nonce should be rejected.
	 */
	public function test_start_scan_rejects_invalid_nonce() {
		$_POST = array(
			'action'      => 'wpmudev_scan_posts_start',
			'_ajax_nonce' => 'invalid',
		);

		try {
			$this->_handleAjax( 'wpmudev_scan_posts_start' );
		} catch ( WPAjaxDieContinueException $e ) {
		}

		$res = json_decode( $this->_last_response, true );
		$this->assertIsArray( $res );
		$this->assertFalse( (bool) $res['success'], 'Invalid nonce should produce error.' );
	}

	/**
	 * Non-admin should be blocked.
	 */
	public function test_progress_denies_non_admin() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			'action'      => 'wpmudev_scan_posts_progress',
			'_ajax_nonce' => wp_create_nonce( 'wpmudev_pm' ),
			'job_id'      => wp_generate_uuid4(),
		);

		try {
			$this->_handleAjax( 'wpmudev_scan_posts_progress' );
		} catch ( WPAjaxDieContinueException $e ) {
		}

		$res = json_decode( $this->_last_response, true );
		$this->assertIsArray( $res );
		$this->assertFalse( (bool) $res['success'], 'Non-admin must be denied.' );
	}
}

/**
 * Tests for the daily cron and synchronous scan path.
 */
class PostsMaintenanceCronTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Ensure the endpoint is ready without depending on Loader env checks.
		\WPMUDEV\PluginTest\Endpoints\V1\Posts_Maintenance_API::instance()->init();
	}

	/**
	 * The daily cron should process only published posts/pages.
	 */
	public function test_cron_scan_updates_meta_for_published_only() {
		$published_posts = self::factory()->post->create_many( 2, array( 'post_status' => 'publish', 'post_type' => 'post' ) );
		$published_pages = self::factory()->post->create_many( 1, array( 'post_status' => 'publish', 'post_type' => 'page' ) );
		$draft_posts     = self::factory()->post->create_many( 2, array( 'post_status' => 'draft',   'post_type' => 'post' ) );

		// Run the cron method directly.
		\WPMUDEV\PluginTest\Endpoints\V1\Posts_Maintenance_API::instance()->cron_scan_posts();

		// Published items must have the meta set.
		foreach ( array_merge( $published_posts, $published_pages ) as $id ) {
			$meta = get_post_meta( $id, 'wpmudev_test_last_scan', true );
			$this->assertNotEmpty( $meta, 'Published content should have scan meta after cron.' );
		}
		// Drafts must not be updated.
		foreach ( $draft_posts as $id ) {
			$meta = get_post_meta( $id, 'wpmudev_test_last_scan', true );
			$this->assertEmpty( $meta, 'Draft content should not be processed by cron.' );
		}
	}
}