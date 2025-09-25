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
	 * Render the page.
	 */
	public function render_page() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>
			<form id="wpmudev-posts-maintenance-form">
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
				<div id="wpmudev_posts_scan_progress" style="margin-top:20px;"></div>
			</form>
		</div>
		<script>
		jQuery(document).ready(function($){
			$('#wpmudev_scan_posts_btn').on('click', function(){
				var selected = $('#wpmudev_post_types').val() || [];
				$('#wpmudev_posts_scan_progress').html('<?php echo esc_js( __( "Scanning...", "wpmudev-plugin-test" ) ); ?>');
				$.post(ajaxurl, {
					action: 'wpmudev_scan_posts',
					post_types: selected
				}, function(response){
					if(response.success){
						$('#wpmudev_posts_scan_progress').html(
							'<?php echo esc_js( __( "Scan complete!", "wpmudev-plugin-test" ) ); ?><br>' +
							'<?php echo esc_js( __( "Processed posts:", "wpmudev-plugin-test" ) ); ?> ' + response.data.processed +
							'<br><?php echo esc_js( __( "Errors:", "wpmudev-plugin-test" ) ); ?> ' + response.data.errors
						);
					}else{
						$('#wpmudev_posts_scan_progress').html(
							'<?php echo esc_js( __( "Scan failed:", "wpmudev-plugin-test" ) ); ?> ' + response.data
						);
					}
				});
			});
		});
		</script>
		<?php
	}
}