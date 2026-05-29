<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Core {

	public static function init(): void {
		self::load_includes();

		Kivun_Post_Types::init();
		Kivun_Shortcodes::init();
		Kivun_Courses::init();
		Kivun_Jobs::init();
		Kivun_Employer::init();
		Kivun_Admin::init();
		Kivun_WooCommerce::init();

		add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'enqueue_frontend' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
		add_action( 'plugins_loaded',        [ __CLASS__, 'load_textdomain' ] );
	}

	private static function load_includes(): void {
		$dir = KIVUN_DIR . 'includes/';
		foreach ( [
			'class-post-types',
			'class-courses',
			'class-jobs',
			'class-employer',
			'class-mailer',
			'class-woocommerce',
			'class-admin',
		] as $file ) {
			require_once $dir . $file . '.php';
		}
		require_once KIVUN_DIR . 'shortcodes/class-shortcodes.php';
	}

	public static function enqueue_frontend(): void {
		wp_enqueue_style(
			'kivun-frontend',
			KIVUN_URL . 'assets/css/frontend.css',
			[],
			KIVUN_VERSION
		);

		wp_enqueue_script(
			'kivun-frontend',
			KIVUN_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			KIVUN_VERSION,
			true
		);

		wp_localize_script( 'kivun-frontend', 'kivun', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'kivun_nonce' ),
			'i18n'     => [
				'sending'        => __( 'שולח...', 'kivun' ),
				'submit'         => __( 'שלח', 'kivun' ),
				'confirm_delete' => __( 'האם למחוק משרה זו?', 'kivun' ),
				'error_generic'  => __( 'אירעה שגיאה, נסה שוב.', 'kivun' ),
			],
		] );
	}

	public static function enqueue_admin( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, [ 'kivun_course', 'kivun_job' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/admin.css', [], KIVUN_VERSION );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'kivun', false, dirname( plugin_basename( KIVUN_FILE ) ) . '/languages' );
	}
}
