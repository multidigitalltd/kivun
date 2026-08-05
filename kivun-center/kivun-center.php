<?php
/**
 * Plugin Name:  Kivun Center
 * Plugin URI:   https://github.com/multidigitalltd/kivun
 * Description:  לוח משרות, קורסים וסדנאות למרכז כיוון — Elementor Dynamic Tags, CRM מובנה, WooCommerce.
 * Version:      1.68.4
 * Author:       MultiDigital
 * Author URI:   https://multidigital.co.il
 * Text Domain:  kivun
 * Domain Path:  /languages
 * Requires PHP: 8.0
 * Requires WP:  6.0
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

define( 'KIVUN_VERSION', '1.68.4' );
define( 'KIVUN_FILE', __FILE__ );
define( 'KIVUN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KIVUN_URL', plugin_dir_url( __FILE__ ) );

require_once KIVUN_DIR . 'includes/class-kivun-installer.php';
require_once KIVUN_DIR . 'includes/class-kivun-cron.php';
register_activation_hook( __FILE__, array( 'Kivun_Installer', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		Kivun_Installer::deactivate();
		Kivun_Cron::deactivate();
	}
);

require_once KIVUN_DIR . 'includes/class-kivun-core.php';
Kivun_Core::init();

/**
 * Load a plugin template, allowing theme overrides in /your-theme/kivun/.
 *
 * @param string $template Relative path inside /templates/ (e.g. 'jobs/card.php').
 * @param array  $vars     Variables to extract into template scope.
 */
function kivun_get_template( string $template, array $vars = array() ): void {
	$file = KIVUN_DIR . 'templates/' . $template;

	$theme_override = get_stylesheet_directory() . '/kivun/' . $template;
	if ( file_exists( $theme_override ) ) {
		$file = $theme_override;
	}

	if ( ! file_exists( $file ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
	extract( $vars, EXTR_SKIP );
	include $file;
}
