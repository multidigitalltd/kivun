<?php
/**
 * Core bootstrap: loads includes and registers plugin assets.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin by loading includes and registering assets.
 */
class Kivun_Core {

	/**
	 * Initializes all plugin modules and registers core hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::load_includes();

		Kivun_Post_Types::init();
		Kivun_Shortcodes::init();
		Kivun_Courses::init();
		Kivun_Workshops::init();
		Kivun_Jobs::init();
		Kivun_Employer::init();
		Kivun_Admin::init();
		Kivun_Admin_Settings::init();
		Kivun_WooCommerce::init();
		Kivun_Notifications::init();
		Kivun_Export::init();
		Kivun_Cron::init();

		// Elementor — only when Elementor Pro is active.
		add_action(
			'elementor/loaded',
			function () {
				require_once KIVUN_DIR . 'elementor/class-kivun-elementor.php';
				Kivun_Elementor::init();
			}
		);

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
	}

	/**
	 * Loads all plugin include and module files.
	 *
	 * @return void
	 */
	private static function load_includes(): void {
		$dir = KIVUN_DIR . 'includes/';
		foreach ( array(
			'functions',
			'class-kivun-post-types',
			'class-kivun-courses',
			'class-kivun-jobs',
			'class-kivun-employer',
			'class-kivun-workshops',
			'class-kivun-mailer',
			'class-kivun-woocommerce',
			'class-kivun-notifications',
			'class-kivun-export',
			'class-kivun-cron',
			'class-kivun-admin',
		) as $file ) {
			require_once $dir . $file . '.php';
		}
		require_once KIVUN_DIR . 'admin/class-kivun-admin-settings.php';
		require_once KIVUN_DIR . 'shortcodes/class-kivun-shortcodes.php';
	}

	/**
	 * Enqueues and localizes front-end styles and scripts.
	 *
	 * @return void
	 */
	public static function enqueue_frontend(): void {
		wp_enqueue_style(
			'kivun-frontend',
			KIVUN_URL . 'assets/css/frontend.css',
			array(),
			KIVUN_VERSION
		);

		wp_enqueue_script(
			'kivun-frontend',
			KIVUN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			KIVUN_VERSION,
			true
		);

		wp_localize_script(
			'kivun-frontend',
			'kivun',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_nonce' ),
				'i18n'     => array(
					'sending'        => __( 'שולח...', 'kivun' ),
					'submit'         => __( 'שלח', 'kivun' ),
					'confirm_delete' => __( 'האם למחוק משרה זו?', 'kivun' ),
					'error_generic'  => __( 'אירעה שגיאה, נסה שוב.', 'kivun' ),
					'saved'          => __( '✓ נשמר', 'kivun' ),
					'save_error'     => __( 'שגיאה', 'kivun' ),
				),
			)
		);
	}

	/**
	 * Enqueues admin styles and scripts on Kivun post-type edit screens.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen      = get_current_screen();
		$kivun_types = array( 'kivun_course', 'kivun_workshop', 'kivun_job' );
		if ( ! $screen || ! in_array( $screen->post_type, $kivun_types, true ) ) {
			return;
		}

		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/admin.css', array(), KIVUN_VERSION );

		wp_enqueue_script(
			'kivun-admin-crm',
			KIVUN_URL . 'assets/js/admin-crm.js',
			array( 'jquery' ),
			KIVUN_VERSION,
			true
		);
		wp_localize_script(
			'kivun-admin-crm',
			'kivunCrm',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_admin_nonce' ),
			)
		);
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'kivun', false, dirname( plugin_basename( KIVUN_FILE ) ) . '/languages' );
	}
}
