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

		Kivun_Installer::maybe_upgrade();

		Kivun_Post_Types::init();
		Kivun_Shortcodes::init();
		Kivun_Courses::init();
		Kivun_Workshops::init();
		Kivun_Landing::init();
		Kivun_Jobs::init();
		Kivun_Employer::init();
		Kivun_Turnstile_Login::init();
		Kivun_Admin::init();
		Kivun_Admin_Settings::init();
		Kivun_WooCommerce::init();
		Kivun_Notifications::init();
		Kivun_Export::init();
		Kivun_Cron::init();
		Kivun_Accessibility::init();
		Kivun_Cookies::init();
		Kivun_WhatsApp::init();
		Kivun_Support::init();
		Kivun_CTA::init();
		Kivun_Forms_Router::init();
		Kivun_Lead_Capture::init();
		Kivun_Utm::init();
		Kivun_Campaigns::init();
		Kivun_Duplicate::init();
		Kivun_Thank_You::init();
		Kivun_Content_Creator::init();
		Kivun_Unified_Meta::init();
		Kivun_AI_Image::init();
		Kivun_AI_Content::init();
		Kivun_Event_Popup::init();

		// Elementor — only when Elementor Pro is active.
		add_action(
			'elementor/loaded',
			function () {
				require_once KIVUN_DIR . 'elementor/class-kivun-elementor.php';
				Kivun_Elementor::init();
			}
		);

		// Register the custom Forms action as early as possible so we never miss
		// the Forms registrar hook. The class file only declares a class (no
		// Elementor symbols are resolved at parse time), so it is safe to load
		// unconditionally; both callbacks bail out when Pro Forms is absent.
		require_once KIVUN_DIR . 'elementor/class-kivun-elementor.php';
		add_action( 'elementor_pro/forms/actions/register', array( 'Kivun_Elementor', 'register_form_actions' ) );
		add_action( 'elementor_pro/init', array( 'Kivun_Elementor', 'register_form_actions_legacy' ), 99 );

		add_filter( 'body_class', array( __CLASS__, 'body_classes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'maybe_enqueue_for_shortcode' ), 10, 2 );
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
			'class-kivun-turnstile-login',
			'class-kivun-workshops',
			'class-kivun-landing',
			'class-kivun-mailer',
			'class-kivun-woocommerce',
			'class-kivun-notifications',
			'class-kivun-export',
			'class-kivun-cron',
			'class-kivun-admin',
			'class-kivun-accessibility',
			'class-kivun-cookies',
			'class-kivun-whatsapp',
			'class-kivun-support',
			'class-kivun-cta',
			'class-kivun-forms-router',
			'class-kivun-lead-capture',
			'class-kivun-utm',
			'class-kivun-campaigns',
			'class-kivun-duplicate',
			'class-kivun-thank-you',
			'class-kivun-content-creator',
			'class-kivun-unified-meta',
			'class-kivun-ai-image',
			'class-kivun-ai-content',
			'class-kivun-event-popup',
		) as $file ) {
			require_once $dir . $file . '.php';
		}
		require_once KIVUN_DIR . 'admin/class-kivun-admin-settings.php';
		require_once KIVUN_DIR . 'shortcodes/class-kivun-shortcodes.php';
	}

	/**
	 * Add state classes to the <body> on a single event, so an Elementor (or
	 * theme) template can show/hide the registration form via CSS:
	 *  - kivun-event-open / kivun-event-closed
	 *  - kivun-event-mode-form / kivun-event-mode-external
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public static function body_classes( $classes ): array {
		if ( is_singular( 'kivun_event' ) ) {
			$id        = (int) get_queried_object_id();
			$classes[] = kivun_event_registration_open( $id ) ? 'kivun-event-open' : 'kivun-event-closed';
			$classes[] = 'kivun-event-mode-' . kivun_event_mode( $id );
		}
		return $classes;
	}

	/**
	 * Enqueues and localizes front-end styles and scripts.
	 *
	 * @return void
	 */
	public static function enqueue_frontend(): void {
		// Always register + localize so the assets are available the moment a
		// Kivun shortcode renders (see maybe_enqueue_for_shortcode), then
		// enqueue eagerly on the obvious contexts (CPT singular/archive/tax).
		wp_register_style(
			'kivun-frontend',
			KIVUN_URL . 'assets/css/' . self::asset( 'frontend', 'css' ),
			array(),
			KIVUN_VERSION
		);

		wp_register_script(
			'kivun-frontend',
			KIVUN_URL . 'assets/js/' . self::asset( 'frontend', 'js' ),
			array(),
			KIVUN_VERSION,
			true
		);
		wp_script_add_data( 'kivun-frontend', 'defer', true );

		wp_register_script(
			'kivun-voice',
			KIVUN_URL . 'assets/js/' . self::asset( 'voice', 'js' ),
			array(),
			KIVUN_VERSION,
			true
		);
		wp_script_add_data( 'kivun-voice', 'defer', true );

		wp_localize_script(
			'kivun-frontend',
			'kivun',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_nonce' ),
				'i18n'     => array(
					'sending'                  => __( 'שולח...', 'kivun' ),
					'submit'                   => __( 'שלח', 'kivun' ),
					'confirm_delete'           => __( 'האם למחוק משרה זו?', 'kivun' ),
					'error_generic'            => __( 'אירעה שגיאה, נסה שוב.', 'kivun' ),
					'saved'                    => __( '✓ נשמר', 'kivun' ),
					'save_error'               => __( 'שגיאה', 'kivun' ),
					'confirm_send_login'       => __( 'לשלוח למפרסם מייל עם קישור להגדרת סיסמה?', 'kivun' ),
					'confirm_disable_employer' => __( 'להשבית את המפרסם? הוא לא יוכל להתחבר ולא יופיע ברשימת הפרסום. המשרות וההגשות שלו יישמרו.', 'kivun' ),
					'renew_prompt'             => __( 'תאריך סגירה חדש למשרה (YYYY-MM-DD):', 'kivun' ),
					'confirm_delete_campaign'  => __( 'למחוק את הקמפיין מהרשימה? הלידים שהגיעו דרכו יישמרו.', 'kivun' ),
				),
			)
		);

		if ( self::needs_frontend_assets() ) {
			self::enqueue_frontend_assets();
		}
	}

	/**
	 * Enqueue the (already registered) front-end style and script. Safe to
	 * call multiple times and late (e.g. while a shortcode renders).
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'kivun-frontend' );
		wp_enqueue_script( 'kivun-frontend' );
	}

	/**
	 * Ensure the front-end assets load whenever a Kivun shortcode actually
	 * renders — including inside Elementor's Shortcode widget, page-builder
	 * blocks, or widgets that bypass post_content detection.
	 *
	 * @param string $output The shortcode output.
	 * @param string $tag    The shortcode tag being processed.
	 * @return string The unmodified shortcode output.
	 */
	public static function maybe_enqueue_for_shortcode( $output, $tag ) {
		if ( is_string( $tag ) && 0 === strpos( $tag, 'kivun_' ) ) {
			self::enqueue_frontend_assets();
		}
		return $output;
	}

	/**
	 * Decide whether the current request actually renders Kivun output, so
	 * front-end CSS/JS load only where needed instead of site-wide.
	 *
	 * @return bool
	 */
	private static function needs_frontend_assets(): bool {
		$cpts       = array( 'kivun_job', 'kivun_course', 'kivun_workshop', 'kivun_session', 'kivun_event' );
		$taxonomies = array(
			'kivun_job_scope',
			'kivun_job_region',
			'kivun_job_field',
			'kivun_course_cat',
			'kivun_workshop_cat',
		);
		$needs      = is_singular( $cpts ) || is_post_type_archive( $cpts ) || is_tax( $taxonomies );

		if ( ! $needs ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$shortcodes = array(
					'kivun_courses',
					'kivun_course_single',
					'kivun_course_register',
					'kivun_course_interest',
					'kivun_workshops',
					'kivun_workshop_single',
					'kivun_jobs',
					'kivun_apply',
					'kivun_spots_left',
					'kivun_my_applications',
					'kivun_employer_register',
					'kivun_employer_dashboard',
				);

				// Elementor keeps page content (including Shortcode widgets) in
				// post meta rather than post_content, so scan both sources.
				$elementor = get_post_meta( $post->ID, '_elementor_data', true );
				$elementor = is_string( $elementor ) ? $elementor : '';

				foreach ( $shortcodes as $shortcode ) {
					if ( has_shortcode( $post->post_content, $shortcode )
						|| ( '' !== $elementor && false !== strpos( $elementor, '[' . $shortcode ) ) ) {
						$needs = true;
						break;
					}
				}
			}
		}

		/**
		 * Allow themes/page-builders that render Kivun output without a
		 * shortcode or CPT context to force-load the front-end assets.
		 *
		 * @param bool $needs Whether the assets are required on this request.
		 */
		return (bool) apply_filters( 'kivun_load_frontend_assets', $needs );
	}

	/**
	 * Return the minified asset file name when available (and not debugging),
	 * otherwise the original. Keeps a single source of truth for the file name.
	 *
	 * @param string $name Base file name without extension (e.g. 'frontend').
	 * @param string $ext  Extension, 'css' or 'js'.
	 * @return string File name to load (e.g. 'frontend.min.js').
	 */
	public static function asset( string $name, string $ext ): string {
		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
		$dir   = ( 'css' === $ext ) ? 'assets/css/' : 'assets/js/';
		$min   = $name . '.min.' . $ext;

		if ( ! $debug && file_exists( KIVUN_DIR . $dir . $min ) ) {
			return $min;
		}

		return $name . '.' . $ext;
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
		$kivun_types = array( 'kivun_course', 'kivun_workshop', 'kivun_job', 'kivun_event' );
		if ( ! $screen || ! in_array( $screen->post_type, $kivun_types, true ) ) {
			return;
		}

		// The event editor has a media-library image picker for the popup image.
		if ( 'kivun_event' === $screen->post_type ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . self::asset( 'admin', 'css' ), array(), KIVUN_VERSION );

		wp_enqueue_script(
			'kivun-voice',
			KIVUN_URL . 'assets/js/' . self::asset( 'voice', 'js' ),
			array(),
			KIVUN_VERSION,
			true
		);

		wp_enqueue_script(
			'kivun-admin-crm',
			KIVUN_URL . 'assets/js/' . self::asset( 'admin-crm', 'js' ),
			array(),
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
