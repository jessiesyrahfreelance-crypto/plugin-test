<?php
/**
 * Posts Maintenance admin page.
 *
 * @link    https://wpmudev.com/
 * @since   1.0.0
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

class Posts_Maintenance extends Base {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_plugintest_posts_maintenance';

	/**
	 * Page title.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * Initializes the page.
	 */
	public function init() {
		$this->page_title = __( 'Posts Maintenance', 'wpmudev-plugin-test' );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		// Ensure jQuery is present for inline script.
		add_action( 'admin_enqueue_scripts', array( $this, 'ensure_scripts' ) );
	}

	public function register_admin_page() {
		add_menu_page(
			$this->page_title,
			$this->page_title,
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			'dashicons-update',
			8
		);
	}

	/**
	 * Enqueue required scripts on our page.
	 */
	public function ensure_scripts() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( empty( $screen->id ) || false === strpos( $screen->id, $this->page_slug ) ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Render the page.
	 */
	public function render_page() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$nonce = wp_create_nonce( 'wpmudev_pm' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>

			<form id="wpmudev-posts-maintenance-form" onsubmit="return false;">
				<h2><?php esc_html_e( 'Scan Posts', 'wpmudev-plugin-test' ); ?></h2>

				<label for="wpmudev_post_types"><?php esc_html_e( 'Select post types:', 'wpmudev-plugin-test' ); ?></label>
				<select id="wpmudev_post_types" name="post_types[]" multiple style="min-width: 250px;">
					<?php foreach ( $post_types as $type ) : ?>
						<option value="<?php echo esc_attr( $type->name ); ?>">
							<?php echo esc_html( $type->label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<br><br>

				<button type="button" id="wpmudev_scan_posts_btn" class="button button-primary">
					<?php esc_html_e( 'Scan Posts', 'wpmudev-plugin-test' ); ?>
				</button>

				<div id="wpmudev_posts_scan_progress" style="margin-top:20px;">
					<div id="wpmudev_pm_status" style="margin-bottom:8px;color:#555;"></div>
					<div id="wpmudev_pm_bar_wrap" style="width:420px;max-width:100%;height:12px;background:#e2e2e2;border-radius:6px;overflow:hidden;display:none;">
						<div id="wpmudev_pm_bar" style="height:100%;width:0;background:#0073aa;transition:width .25s;"></div>
					</div>
					<div id="wpmudev_pm_counts" style="margin-top:8px;display:none;color:#333;"></div>
					<div id="wpmudev_pm_breakdown" style="margin-top:4px;display:none;color:#333;"></div>
				</div>
			</form>
		</div>

		<script>
		(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var pollTimer = null;
			var currentJobId = null;

			function setUIStateScanning(total) {
				$('#wpmudev_scan_posts_btn').prop('disabled', true).text('<?php echo esc_js( __( 'Scanning…', 'wpmudev-plugin-test' ) ); ?>');
				$('#wpmudev_pm_bar_wrap').show();
				$('#wpmudev_pm_counts').show();
				$('#wpmudev_pm_breakdown').show();
				$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Scan started. You can navigate away; processing continues in the background.', 'wpmudev-plugin-test' ) ); ?>');
				updateProgressUI(0, 0, total);
				updateBreakdownUI(); // reset
			}
			function setUIStateIdle() {
				$('#wpmudev_scan_posts_btn').prop('disabled', false).text('<?php echo esc_js( __( 'Scan Posts', 'wpmudev-plugin-test' ) ); ?>');
			}
			function updateBreakdownUI(breakdown) {
				if (!breakdown) {
					$('#wpmudev_pm_breakdown').text('');
					return;
				}
				var processedByType = breakdown.processed || {};
				var extras = breakdown.extras || {};
				var parts = [];

				for (var key in processedByType) {
					if (!Object.prototype.hasOwnProperty.call(processedByType, key)) continue;
					var label = (key === 'post') ? 'posts' : (key === 'page' ? 'pages' : (key.endsWith('s') ? key : key + 's'));
					parts.push(label + ': ' + processedByType[key]);
				}
				if (typeof extras.categories !== 'undefined') {
					parts.push('categories: ' + extras.categories);
				}
				$('#wpmudev_pm_breakdown').text(parts.join(', '));
			}
			function updateProgressUI(percent, processed, total, errors, breakdown) {
				var pct = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
				$('#wpmudev_pm_bar').css('width', pct + '%');
				var text = '<?php echo esc_js( __( 'Progress:', 'wpmudev-plugin-test' ) ); ?> ' + pct + '%';
				if (typeof processed !== 'undefined' && typeof total !== 'undefined') {
					text += ' · ' + processed + ' / ' + total;
				}
				if (typeof errors !== 'undefined') {
					text += ' · <?php echo esc_js( __( 'Errors', 'wpmudev-plugin-test' ) ); ?>: ' + errors;
				}
				$('#wpmudev_pm_counts').text(text);

				// breakdown string (e.g., "posts: 1, pages: 3, categories: 3")
				if (breakdown) {
					updateBreakdownUI(breakdown);
				}
			}
			function pollProgress(jobId) {
				if (!jobId) return;
				if (pollTimer) clearTimeout(pollTimer);
				pollTimer = setTimeout(function doPoll(){
					$.post(ajaxurl, {
						action: 'wpmudev_scan_posts_progress',
						_ajax_nonce: nonce,
						job_id: jobId
					}).done(function(res){
						if (!res || !res.success) {
							$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Failed to get progress.', 'wpmudev-plugin-test' ) ); ?>');
							setUIStateIdle();
							return;
						}
						var d = res.data || {};
						updateProgressUI(d.percent, d.processed, d.total, d.errors, d.breakdown);

						if (d.status === 'completed') {
							$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Scan complete!', 'wpmudev-plugin-test' ) ); ?>');
							setUIStateIdle();
							currentJobId = null;
							return;
						}
						// Keep polling while running.
						if (d.status === 'running') {
							pollTimer = setTimeout(doPoll, 1000);
						} else {
							$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Scan stopped.', 'wpmudev-plugin-test' ) ); ?>');
							setUIStateIdle();
						}
					}).fail(function(){
						$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Network error while fetching progress.', 'wpmudev-plugin-test' ) ); ?>');
						setUIStateIdle();
					});
				}, 0);
			}

			$('#wpmudev_scan_posts_btn').on('click', function(){
				if (pollTimer) clearTimeout(pollTimer);
				var selected = $('#wpmudev_post_types').val() || [];
				$.post(ajaxurl, {
					action: 'wpmudev_scan_posts_start',
					_ajax_nonce: nonce,
					post_types: selected
				}).done(function(res){
					if (!res || !res.success) {
						var msg = (res && res.data) ? res.data : '<?php echo esc_js( __( 'Scan failed to start.', 'wpmudev-plugin-test' ) ); ?>';
						$('#wpmudev_pm_status').text(msg);
						setUIStateIdle();
						return;
					}
					currentJobId = res.data.job_id;
					var total = res.data.total || 0;
					setUIStateScanning(total);
					pollProgress(currentJobId);
				}).fail(function(){
					$('#wpmudev_pm_status').text('<?php echo esc_js( __( 'Unable to start scan.', 'wpmudev-plugin-test' ) ); ?>');
					setUIStateIdle();
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}