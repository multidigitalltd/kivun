<?php
/**
 * Admin settings page for the Kivun Center plugin.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Kivun Center admin settings screen and option storage.
 */
class Kivun_Admin_Settings {

	/**
	 * Option key used to store the plugin settings.
	 *
	 * @var string
	 */
	private static string $option_key = 'kivun_settings';

	/**
	 * Snapshot of the full admin menu (slug => label), taken before any items
	 * are hidden, so the settings screen can still list everything to toggle.
	 *
	 * @var array<string,string>
	 */
	private static array $menu_snapshot = array();

	/**
	 * Register the admin hooks for the settings screen.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_kivun_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_kivun_test_router_email', array( __CLASS__, 'send_test_email' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Hide the admin menu items the operator chose to hide (runs last so all
		// Kivun menus are already registered).
		add_action( 'admin_menu', array( __CLASS__, 'hide_menus' ), 999999 );

		// Always keep a Settings link on the Plugins page — a safety net in case
		// the Settings menu itself was hidden.
		add_filter( 'plugin_action_links_' . plugin_basename( KIVUN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	/**
	 * Add the top-level admin menu page.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Kivun Center', 'kivun' ),
			__( 'Kivun Center', 'kivun' ),
			'manage_options',
			'kivun-settings',
			array( __CLASS__, 'render_page' ),
			'dashicons-businessman',
			4
		);
	}

	// ── Enqueue Select2 for WC product picker (metabox) ───────────────────────

	/**
	 * Enqueue admin assets for the settings page and course metabox.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		// Settings page.
		if ( 'toplevel_page_kivun-settings' === $hook ) {
			wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/admin.css', array(), KIVUN_VERSION );
		}

		// Course metabox — Select2 product picker.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = get_current_screen();
			if ( $screen && 'kivun_course' === $screen->post_type && class_exists( 'WooCommerce' ) ) {
				wp_enqueue_script( 'select2' );
				wp_enqueue_style( 'select2' );
				wp_enqueue_script(
					'kivun-admin-course',
					KIVUN_URL . 'assets/js/admin-course.js',
					array( 'jquery', 'select2' ),
					KIVUN_VERSION,
					true
				);
				wp_localize_script(
					'kivun-admin-course',
					'kivunAdmin',
					array(
						'ajax_url'           => admin_url( 'admin-ajax.php' ),
						'nonce'              => wp_create_nonce( 'kivun_admin_nonce' ),
						'search_placeholder' => __( 'חפש מוצר...', 'kivun' ),
					)
				);
			}
		}
	}

	// ── Admin menu visibility ──────────────────────────────────────────────────

	/**
	 * Read the current global $menu into a slug => label map (separators skipped,
	 * count bubbles stripped from labels).
	 *
	 * @return array<string,string>
	 */
	private static function read_menu(): array {
		global $menu;
		$items = array();
		if ( ! is_array( $menu ) ) {
			return $items;
		}
		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) || empty( $item[0] ) ) {
				continue;
			}
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug || 0 === strpos( $slug, 'separator' ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) $item[0] ) );
			// Drop a trailing update/comment count number for a cleaner label.
			$label          = trim( (string) preg_replace( '/\s*\d+\s*$/', '', $label ) );
			$items[ $slug ] = '' !== $label ? $label : $slug;
		}
		return $items;
	}

	/**
	 * Every top-level admin menu (core + plugins) for the settings checklist —
	 * from the snapshot taken before hiding, so hidden items still appear here.
	 *
	 * @return array<string,string>
	 */
	public static function all_admin_menus(): array {
		return self::$menu_snapshot ? self::$menu_snapshot : self::read_menu();
	}

	/**
	 * Remove every admin menu the operator chose to hide (core, plugins, or this
	 * plugin's own). Runs late so all menus are registered first.
	 *
	 * The full menu is snapshotted first so the settings checklist can still show
	 * every item. Hidden pages stay reachable by direct URL (and Kivun settings
	 * via the Plugins page) — this only cleans up the visible menu.
	 *
	 * @return void
	 */
	public static function hide_menus(): void {
		self::$menu_snapshot = self::read_menu();

		$hidden = self::get( 'admin_menu_hidden', array() );
		if ( ! is_array( $hidden ) || ! $hidden ) {
			return;
		}
		foreach ( $hidden as $slug ) {
			remove_menu_page( (string) $slug );
		}
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ): array {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=kivun-settings' ) ) . '">' . esc_html__( 'הגדרות', 'kivun' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	/**
	 * Persist the submitted settings form.
	 *
	 * @return void
	 */
	public static function save(): void {
		check_admin_referer( 'kivun_settings_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Menu visibility. Hidden = items shown in the form but unticked, PLUS any
		// previously-hidden menu not currently present (e.g. a deactivated plugin),
		// so its state is preserved.
		$menu_all    = isset( $_POST['menu_all'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['menu_all'] ) ) : array();
		$menu_shown  = isset( $_POST['menu_show'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['menu_show'] ) ) : array();
		$menu_prev   = (array) self::get( 'admin_menu_hidden', array() );
		$menu_hidden = array_values(
			array_unique(
				array_merge(
					array_diff( $menu_all, $menu_shown ),
					array_diff( $menu_prev, $menu_all )
				)
			)
		);

		update_option(
			self::$option_key,
			array(
				'admin_menu_hidden'      => $menu_hidden,
				'admin_email'            => sanitize_email( wp_unslash( $_POST['admin_email'] ?? '' ) ),
				'jobs_per_page'          => absint( $_POST['jobs_per_page'] ?? 10 ),
				'cookie_banner_enabled'  => ! empty( $_POST['cookie_banner_enabled'] ),
				'cookie_policy_url'      => esc_url_raw( wp_unslash( $_POST['cookie_policy_url'] ?? '' ) ),
				'landing_builtin_single' => ! empty( $_POST['landing_builtin_single'] ),
				'allow_cv_upload'        => ! empty( $_POST['allow_cv_upload'] ),
				'cv_max_size_mb'         => absint( $_POST['cv_max_size_mb'] ?? 5 ),
				'webhook_url'            => esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ),
				'forms_router_email'     => sanitize_email( wp_unslash( $_POST['forms_router_email'] ?? '' ) ),
				'forms_router_webhook'   => esc_url_raw( wp_unslash( $_POST['forms_router_webhook'] ?? '' ) ),
				'whatsapp_enabled'       => ! empty( $_POST['whatsapp_enabled'] ),
				'whatsapp_number'        => sanitize_text_field( wp_unslash( $_POST['whatsapp_number'] ?? '' ) ),
				'whatsapp_message'       => sanitize_text_field( wp_unslash( $_POST['whatsapp_message'] ?? '' ) ),
				'openai_api_key'         => sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ?? '' ) ),
				'mercaz_url'             => esc_url_raw( wp_unslash( $_POST['mercaz_url'] ?? '' ) ),
				'mercaz_jobs_url'        => esc_url_raw( wp_unslash( $_POST['mercaz_jobs_url'] ?? '' ) ),
				'mercaz_user'            => sanitize_text_field( wp_unslash( $_POST['mercaz_user'] ?? '' ) ),
				'mercaz_pass'            => sanitize_text_field( wp_unslash( $_POST['mercaz_pass'] ?? '' ) ),
				'ai_image_model'         => sanitize_text_field( wp_unslash( $_POST['ai_image_model'] ?? 'gpt-image-1' ) ),
				'ai_image_quality'       => sanitize_key( wp_unslash( $_POST['ai_image_quality'] ?? 'medium' ) ),
				'turnstile_site_key'     => sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ?? '' ) ),
				'turnstile_secret_key'   => sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ?? '' ) ),
				'thankyou_page_id'       => absint( $_POST['thankyou_page_id'] ?? 0 ),
				'thankyou_elementor'     => ! empty( $_POST['thankyou_elementor'] ),
				'thankyou_title'         => sanitize_text_field( wp_unslash( $_POST['thankyou_title'] ?? '' ) ),
				'thankyou_message'       => sanitize_textarea_field( wp_unslash( $_POST['thankyou_message'] ?? '' ) ),
			)
		);

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal admin URL redirect.
		wp_redirect( admin_url( 'admin.php?page=kivun-settings&saved=1' ) );
		exit;
	}

	/**
	 * Send a test email to the central forms-router address, to verify that
	 * wp_mail delivers and the address is correct — independent of Elementor.
	 *
	 * @return void
	 */
	public static function send_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'kivun_test_router_email' );

		$to     = (string) self::get( 'forms_router_email', '' );
		$result = 'empty';

		if ( '' !== trim( $to ) && is_email( $to ) ) {
			$sent = wp_mail(
				$to,
				sprintf( '[%s] מייל בדיקה — ניתוב טפסים', get_bloginfo( 'name' ) ),
				sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: destination email address. */
						esc_html__( 'זהו מייל בדיקה מהניתוב הכללי של Kivun Center. אם קיבלת אותו — הניתוב לכתובת %s תקין.', 'kivun' ),
						esc_html( $to )
					)
				),
				array(
					'Content-Type: text/html; charset=UTF-8',
					sprintf( 'From: %s <%s>', get_bloginfo( 'name' ), get_option( 'admin_email' ) ),
				)
			);
			$result = $sent ? 'ok' : 'fail';
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal admin URL redirect.
		wp_redirect( admin_url( 'admin.php?page=kivun-settings&router_test=' . $result ) );
		exit;
	}

	/**
	 * Retrieve a single stored setting value.
	 *
	 * @param string $key           The setting key to read.
	 * @param mixed  $default_value The fallback value when the key is unset.
	 * @return mixed The stored value or the fallback.
	 */
	public static function get( string $key, $default_value = '' ) {
		$opts = get_option( self::$option_key, array() );
		return $opts[ $key ] ?? $default_value;
	}

	// ── Page ──────────────────────────────────────────────────────────────────

	/**
	 * Render the settings admin page with its tabs.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		?>
		<div class="wrap kivun-settings-wrap">
			<h1>
				<span class="dashicons dashicons-businessman" style="font-size:28px;height:28px;margin-left:8px;color:#2563eb"></span>
				Kivun Center
			</h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display. ?>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו.', 'kivun' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display. ?>
			<?php $router_test = isset( $_GET['router_test'] ) ? sanitize_key( wp_unslash( $_GET['router_test'] ) ) : ''; ?>
			<?php if ( 'ok' === $router_test ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'מייל הבדיקה נשלח. בדקו את תיבת הדואר (כולל ספאם). אם לא הגיע — קיימת בעיית שליחת דואר בשרת (מומלץ תוסף SMTP).', 'kivun' ); ?></p></div>
			<?php elseif ( 'fail' === $router_test ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'שליחת מייל הבדיקה נכשלה — wp_mail החזיר שגיאה. השרת אינו שולח דואר. התקינו תוסף SMTP (למשל WP Mail SMTP).', 'kivun' ); ?></p></div>
			<?php elseif ( 'empty' === $router_test ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'לא הוגדרה כתובת "אימייל מרכזי לכל הטפסים". הזינו כתובת ושמרו לפני הבדיקה.', 'kivun' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display. ?>
			<?php if ( ! empty( $_GET['thankyou_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'דף התודה נוצר ונבחר, וההפניה הופעלה. אפשר לעצב אותו ב-Elementor.', 'kivun' ); ?></p></div>
			<?php endif; ?>

			<div class="kivun-tab-content kivun-tab-content--single">
				<?php self::tab_settings(); ?>
			</div>
		</div>
		<?php
	}

	// ── Tab: Settings ────────────────────────────────────────────────────────

	/**
	 * Render the general settings tab.
	 *
	 * @return void
	 */
	private static function tab_settings(): void {
		$o = fn( $k, $d = '' ) => self::get( $k, $d );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'kivun_settings_save' ); ?>
			<input type="hidden" name="action" value="kivun_save_settings">

			<table class="form-table">
				<tr>
					<th colspan="2" style="padding-top:8px"><h2 style="margin:0"><?php esc_html_e( 'לשוניות בלוח הבקרה', 'kivun' ); ?></h2>
					<p class="description" style="font-weight:400"><?php esc_html_e( 'בחרו אילו תפריטים יופיעו בסרגל הניהול — כל התפריטים באתר (וורדפרס ותוספים אחרים) — כדי לתת ללקוח לוח בקרה נקי. פריט שהוסתר עדיין נגיש בכתובת ישירה, וההגדרות של Kivun תמיד זמינות מעמוד התוספים.', 'kivun' ); ?></p></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'הצגת תפריטים', 'kivun' ); ?></th>
					<td>
						<?php
						$kivun_menu_hidden = (array) $o( 'admin_menu_hidden', array() );
						$kivun_all_menus   = self::all_admin_menus();
						?>
						<fieldset style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 20px;max-width:820px;max-height:340px;overflow:auto;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#fff">
							<?php foreach ( $kivun_all_menus as $kivun_slug => $kivun_label ) : ?>
								<label style="display:flex;align-items:center;gap:7px;margin:0">
									<input type="checkbox" name="menu_show[]" value="<?php echo esc_attr( $kivun_slug ); ?>" <?php checked( ! in_array( $kivun_slug, $kivun_menu_hidden, true ) ); ?>>
									<input type="hidden" name="menu_all[]" value="<?php echo esc_attr( $kivun_slug ); ?>">
									<span><?php echo esc_html( $kivun_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'מסומן = מוצג בתפריט. הסירו סימון כדי להסתיר. ברירת מחדל: הכל מוצג.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'אימייל לקבלת התראות', 'kivun' ); ?></th>
					<td>
						<input type="email" name="admin_email" value="<?php echo esc_attr( $o( 'admin_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'ישלחו לכאן הרשמות לקורסים חינמיים. ניתן לדרוס לכל קורס בנפרד מהמטאבוקס.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'משרות לעמוד בלוח', 'kivun' ); ?></th>
					<td>
						<input type="number" name="jobs_per_page" value="<?php echo esc_attr( $o( 'jobs_per_page', 10 ) ); ?>" min="1" max="100" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'מבנה מובנה לדף נחיתה', 'kivun' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="landing_builtin_single" value="1" <?php checked( (bool) $o( 'landing_builtin_single', false ) ); ?>>
							<?php esc_html_e( 'הצג את המבנה המובנה של התוסף בעמוד דף הנחיתה (תמונה + פרטים + טופס).', 'kivun' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'השאר כבוי אם אתה מעצב את עמוד דף הנחיתה בעצמך (Elementor) — כך התוסף לא ידרוס את התוכן ולא יוסיף טופס.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'באנר אישור עוגיות', 'kivun' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cookie_banner_enabled" value="1" <?php checked( (bool) $o( 'cookie_banner_enabled', true ) ); ?>>
							<?php esc_html_e( 'הצג באנר אישור עוגיות (Cookie Consent) באתר.', 'kivun' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'קישור למדיניות פרטיות', 'kivun' ); ?></th>
					<td>
						<input type="url" name="cookie_policy_url" value="<?php echo esc_attr( $o( 'cookie_policy_url' ) ); ?>" class="regular-text" placeholder="https://...">
						<p class="description"><?php esc_html_e( 'מוצג כקישור בבאנר העוגיות. השאר ריק כדי להסתיר את הקישור.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'אפשר העלאת קו"ח', 'kivun' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="allow_cv_upload" value="1" <?php checked( $o( 'allow_cv_upload', true ) ); ?>>
							<?php esc_html_e( 'מתן אפשרות למועמדים לצרף קובץ PDF/Word', 'kivun' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'גודל מקסימלי לקו"ח (MB)', 'kivun' ); ?></th>
					<td>
						<input type="number" name="cv_max_size_mb" value="<?php echo esc_attr( $o( 'cv_max_size_mb', 5 ) ); ?>" min="1" max="20" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL (n8n / Zapier / Make)', 'kivun' ); ?></th>
					<td>
						<input type="url" name="webhook_url" value="<?php echo esc_attr( $o( 'webhook_url' ) ); ?>" class="large-text" placeholder="https://...">
						<p class="description">
							<?php esc_html_e( 'POST (JSON) יישלח לכתובת זו בכל ליד, הרשמה או מועמדות חדשה.', 'kivun' ); ?><br>
							<?php esc_html_e( 'משדות: event, post_id, post_title, name, email, phone, message, timestamp.', 'kivun' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th colspan="2" style="padding-top:20px"><h2 style="margin:0"><?php esc_html_e( 'ניתוב גלובלי לכל טפסי Elementor', 'kivun' ); ?></h2>
					<p class="description" style="font-weight:400"><?php esc_html_e( 'חל על כל טופס Elementor באתר (לא רק טפסי הקורסים/דפי הנחיתה). כל הגשה תישלח גם ליעדים הבאים — בנוסף לפעולות של הטופס עצמו.', 'kivun' ); ?></p></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'אימייל מרכזי לכל הטפסים', 'kivun' ); ?></th>
					<td>
						<input type="email" name="forms_router_email" value="<?php echo esc_attr( $o( 'forms_router_email' ) ); ?>" class="regular-text" placeholder="leads@example.com">
						<a class="button" style="margin-inline-start:6px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kivun_test_router_email' ), 'kivun_test_router_email' ) ); ?>"><?php esc_html_e( 'שליחת מייל בדיקה', 'kivun' ); ?></a>
						<p class="description"><?php esc_html_e( 'כל הגשת טופס Elementor באתר תישלח גם לכתובת הזו. השאר ריק כדי לבטל.', 'kivun' ); ?><br><?php esc_html_e( 'אם הטופס נשלח מקורס / סדנה / דף נחיתה שהוגדר לו "אימייל לקבלת הלידים" — הכתובת הספציפית של אותו עמוד גוברת על הכתובת המרכזית.', 'kivun' ); ?><br><strong><?php esc_html_e( 'לבדיקה: שמרו כתובת, לחצו "שליחת מייל בדיקה" — כך תדעו אם הבעיה בשליחת הדואר של השרת או בטופס.', 'kivun' ); ?></strong></p>
						<?php $router_last = get_option( 'kivun_forms_router_last', array() ); ?>
						<?php if ( is_array( $router_last ) && ! empty( $router_last['time'] ) ) : ?>
							<p class="description" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:8px 10px">
								<strong><?php esc_html_e( 'ניתוב אחרון של טופס:', 'kivun' ); ?></strong>
								<?php echo esc_html( (string) $router_last['time'] ); ?>
								— <?php echo esc_html( (string) ( $router_last['result'] ?? '' ) ); ?>
								<?php if ( ! empty( $router_last['email'] ) ) : ?>
									→ <code><?php echo esc_html( (string) $router_last['email'] ); ?></code>
								<?php endif; ?>
							</p>
						<?php else : ?>
							<p class="description" style="color:#b45309"><?php esc_html_e( '⚠️ עדיין לא נרשם ניתוב של אף טופס. אם שלחתם טופס ולא מופיע כאן — ההוק של Elementor לא מגיע לתוסף (בדקו שאתם על Elementor Pro, או שהטופס משתמש בפעולת Kivun שיש לה גיבוי ניתוב מובנה).', 'kivun' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook לכל הטפסים (CRM)', 'kivun' ); ?></th>
					<td>
						<input type="url" name="forms_router_webhook" value="<?php echo esc_attr( $o( 'forms_router_webhook' ) ); ?>" class="large-text" placeholder="https://...">
						<p class="description">
							<?php esc_html_e( 'POST (JSON) לכל הגשת טופס Elementor. שדות: event, form_name, page_url, site, fields.', 'kivun' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th colspan="2" style="padding-top:20px"><h2 style="margin:0"><?php esc_html_e( 'כפתור וואטסאפ דביק', 'kivun' ); ?></h2>
					<p class="description" style="font-weight:400"><?php esc_html_e( 'כפתור וואטסאפ צף שמופיע בכל עמודי האתר. הגולש לוחץ ונפתחת שיחה אליכם.', 'kivun' ); ?></p></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'הצג כפתור וואטסאפ', 'kivun' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( (bool) $o( 'whatsapp_enabled', false ) ); ?>>
							<?php esc_html_e( 'הפעל את כפתור הוואטסאפ הדביק בכל האתר.', 'kivun' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'מספר וואטסאפ', 'kivun' ); ?></th>
					<td>
						<input type="text" name="whatsapp_number" value="<?php echo esc_attr( $o( 'whatsapp_number' ) ); ?>" class="regular-text" dir="ltr" placeholder="972501234567">
						<p class="description"><?php esc_html_e( 'בפורמט בינלאומי ללא + וללא רווחים. לדוגמה: מספר ישראלי 050-123-4567 יוזן כ‑972501234567.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'הודעת פתיחה', 'kivun' ); ?></th>
					<td>
						<input type="text" name="whatsapp_message" value="<?php echo esc_attr( $o( 'whatsapp_message' ) ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'היי, הגעתי מהאתר ואשמח לקבל פרטים', 'kivun' ); ?>">
						<p class="description"><?php esc_html_e( 'טקסט שיופיע כבר מוכן בשיחה כשהגולש לוחץ. השאר ריק לשיחה ריקה.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th colspan="2" style="padding-top:20px"><h2 style="margin:0"><?php esc_html_e( 'דף תודה לאחר שליחת טופס', 'kivun' ); ?></h2>
					<p class="description" style="font-weight:400"><?php esc_html_e( 'הפניה אוטומטית לדף תודה מעוצב אחרי כל טופס — כולל כל טפסי Elementor — מהגדרה אחת, בלי לערוך כל טופס בנפרד.', 'kivun' ); ?></p></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'דף התודה', 'kivun' ); ?></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'thankyou_page_id',
								'selected'          => (int) $o( 'thankyou_page_id', 0 ),
								'show_option_none'  => esc_html__( '— בחרו עמוד —', 'kivun' ),
								'option_none_value' => 0,
							)
						);
						?>
						<a class="button" style="margin-inline-start:6px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kivun_create_thankyou' ), 'kivun_create_thankyou' ) ); ?>"><?php esc_html_e( 'צור דף תודה מעוצב', 'kivun' ); ?></a>
						<p class="description"><?php esc_html_e( 'בחרו עמוד קיים (שמכיל את השורטקוד [kivun_thank_you]) או לחצו "צור דף תודה" ליצירה אוטומטית.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'הפניית טפסי Elementor', 'kivun' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="thankyou_elementor" value="1" <?php checked( (bool) $o( 'thankyou_elementor', false ) ); ?>>
							<?php esc_html_e( 'הפנה כל טופס Elementor באתר לדף התודה אחרי שליחה.', 'kivun' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'טופס עם הפניה משלו (למשל קורס בתשלום → תשלום) ישמור עליה — דף התודה חל רק כשאין הפניה אחרת.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'כותרת דף התודה', 'kivun' ); ?></th>
					<td>
						<input type="text" name="thankyou_title" value="<?php echo esc_attr( $o( 'thankyou_title' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'תודה רבה!', 'kivun' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'תוכן דף התודה', 'kivun' ); ?></th>
					<td>
						<textarea name="thankyou_message" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'פנייתך התקבלה בהצלחה. ניצור איתך קשר בהקדם.', 'kivun' ); ?>"><?php echo esc_textarea( $o( 'thankyou_message' ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th colspan="2" style="padding-top:20px"><h2 style="margin:0"><?php esc_html_e( 'יצירת תמונות AI (תמונה ראשית)', 'kivun' ); ?></h2>
					<p class="description" style="font-weight:400"><?php esc_html_e( 'מאפשר כפתור "צור תמונה עם AI" בטופס פרסום התוכן. המפתח נשמר בשרת בלבד ואינו נחשף בפרונט.', 'kivun' ); ?></p></th>
				</tr>
				<tr><th colspan="2"><h2><?php esc_html_e( 'חיבור למרכז כיוון (Content & Jobs API)', 'kivun' ); ?></h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'כתובת ה-API', 'kivun' ); ?></th>
					<td>
						<input type="url" name="mercaz_url" value="<?php echo esc_attr( $o( 'mercaz_url' ) ); ?>" class="regular-text" dir="ltr" placeholder="https://mercaz-kivun.co.il/wp-json/wp/v2">
						<p class="description"><?php esc_html_e( 'הבסיס של ה-API, עד ‎/wp/v2 (ללא לוכסן בסוף).', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'כתובת ה-API למשרות', 'kivun' ); ?></th>
					<td>
						<input type="url" name="mercaz_jobs_url" value="<?php echo esc_attr( $o( 'mercaz_jobs_url' ) ); ?>" class="regular-text" dir="ltr" placeholder="https://mercaz-kivun.staging24.link/wp-json/wp/v2">
						<p class="description"><?php esc_html_e( 'רק אם המשרות יושבות על שרת אחר מהתוכן. אם ריק — תשמש הכתובת שלמעלה.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'שם משתמש', 'kivun' ); ?></th>
					<td>
						<input type="text" name="mercaz_user" value="<?php echo esc_attr( $o( 'mercaz_user' ) ); ?>" class="regular-text" dir="ltr" autocomplete="off">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'סיסמת יישום', 'kivun' ); ?></th>
					<td>
						<input type="password" name="mercaz_pass" value="<?php echo esc_attr( $o( 'mercaz_pass' ) ); ?>" class="regular-text" dir="ltr" autocomplete="off" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx">
						<p class="description">
							<?php esc_html_e( 'Application Password מתוך פרופיל המשתמש באתר מרכז כיוון — לא סיסמת ההתחברות הרגילה. אפשר להדביק עם או בלי רווחים.', 'kivun' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'בדיקה', 'kivun' ); ?></th>
					<td>
						<button type="button" class="button kivun-mercaz-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_mercaz' ) ); ?>">
							<?php esc_html_e( 'בדיקת חיבור', 'kivun' ); ?>
						</button>
						<button type="button" class="button kivun-mercaz-inspect" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_mercaz' ) ); ?>">
							<?php esc_html_e( 'הצגת שדות התוכן', 'kivun' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'בדיקת חיבור מאמתת את הפרטים ומדווחת באיזה תפקיד התחברתם. הצגת שדות קוראת פריט קיים מכל סוג ומראה אילו שדות הוא באמת מכיל — כך המיפוי נבנה לפי המציאות ולא לפי ניחוש. יש לשמור את ההגדרות לפני הבדיקה.', 'kivun' ); ?>
						</p>
						<div class="kivun-mercaz-result" style="margin-top:10px"></div>
						<script>
						( function () {
							var box = document.querySelector( '.kivun-mercaz-result' );
							if ( ! box ) { return; }

							var say = function ( html ) { box.innerHTML = html; };
							var esc = function ( t ) {
								var d = document.createElement( 'div' );
								d.textContent = String( t == null ? '' : t );
								return d.innerHTML;
							};

							var call = function ( btn, body, done ) {
								btn.disabled = true;
								say( '<em><?php echo esc_js( __( 'בודק…', 'kivun' ) ); ?></em>' );
								body.append( 'nonce', btn.dataset.nonce );
								fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
									.then( function ( r ) { return r.json(); } )
									.then( function ( res ) {
										btn.disabled = false;
										if ( ! res.success ) {
											say( '<div class="notice notice-error inline"><p>' + esc( res.data && res.data.message ) + '</p></div>' );
											return;
										}
										done( res.data );
									} )
									.catch( function () {
										btn.disabled = false;
										say( '<div class="notice notice-error inline"><p><?php echo esc_js( __( 'הבקשה נכשלה.', 'kivun' ) ); ?></p></div>' );
									} );
							};

							var testBtn = document.querySelector( '.kivun-mercaz-test' );
							if ( testBtn ) {
								testBtn.addEventListener( 'click', function () {
									var b = new URLSearchParams();
									b.append( 'action', 'kivun_mercaz_test' );
									call( testBtn, b, function ( d ) {
										say( '<div class="notice notice-success inline"><p><strong>' + esc( d.message ) + '</strong><br>' + esc( d.note ) + '</p></div>' );
									} );
								} );
							}

							var inspectBtn = document.querySelector( '.kivun-mercaz-inspect' );
							if ( inspectBtn ) {
								inspectBtn.addEventListener( 'click', function () {
									var b = new URLSearchParams();
									b.append( 'action', 'kivun_mercaz_inspect' );
									call( inspectBtn, b, function ( d ) {
										var html = '';
										Object.keys( d.report ).forEach( function ( type ) {
											var r = d.report[ type ];
											html += '<h4 style="margin:14px 0 4px">' + esc( type ) + '</h4>';
											if ( r.error ) {
												html += '<p style="color:#b32d2e">' + esc( r.error ) + '</p>';
												return;
											}
											if ( r.empty ) {
												html += '<p><em><?php echo esc_js( __( 'אין פריטים קיימים לקריאה בסוג הזה.', 'kivun' ) ); ?></em></p>';
												return;
											}
											var rows = function ( obj, title ) {
												var keys = Object.keys( obj || {} );
												if ( ! keys.length ) { return ''; }
												var out = '<p style="margin:6px 0 2px"><strong>' + esc( title ) + '</strong></p>';
												out += '<table class="widefat striped" style="max-width:900px"><tbody>';
												keys.forEach( function ( k ) {
													out += '<tr><td style="width:220px"><code>' + esc( k ) + '</code></td><td>' + esc( obj[ k ] ) + '</td></tr>';
												} );
												return out + '</tbody></table>';
											};
											html += rows( r.fields, '<?php echo esc_js( __( 'שדות', 'kivun' ) ); ?>' );
											html += rows( r.meta, 'meta' );
										} );
										say( html || '<p><?php echo esc_js( __( 'לא התקבלו נתונים.', 'kivun' ) ); ?></p>' );
									} );
								} );
							}
						} () );
						</script>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'OpenAI API Key', 'kivun' ); ?></th>
					<td>
						<input type="password" name="openai_api_key" value="<?php echo esc_attr( $o( 'openai_api_key' ) ); ?>" class="regular-text" autocomplete="off" placeholder="sk-...">
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: link to the OpenAI API keys page. */
									__( 'רכישת/יצירת מפתח כאן: <a href="%s" target="_blank" rel="noopener">platform.openai.com</a>', 'kivun' ),
									esc_url( 'https://platform.openai.com/api-keys' )
								)
							);
							?>
							<br>
							<?php esc_html_e( 'יצירת תמונה עולה כסף לפי המודל והאיכות (בקירוב 4–17 סנט לתמונה). יש לטעון קרדיטים בחשבון ה-OpenAI.', 'kivun' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'מודל', 'kivun' ); ?></th>
					<td>
						<select name="ai_image_model">
							<option value="gpt-image-1" <?php selected( $o( 'ai_image_model', 'gpt-image-1' ), 'gpt-image-1' ); ?>><?php esc_html_e( 'gpt-image-1 (מומלץ — איכות גבוהה)', 'kivun' ); ?></option>
							<option value="dall-e-3" <?php selected( $o( 'ai_image_model', 'gpt-image-1' ), 'dall-e-3' ); ?>><?php esc_html_e( 'dall-e-3', 'kivun' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'איכות', 'kivun' ); ?></th>
					<td>
						<select name="ai_image_quality">
							<option value="low" <?php selected( $o( 'ai_image_quality', 'medium' ), 'low' ); ?>><?php esc_html_e( 'נמוכה (זול)', 'kivun' ); ?></option>
							<option value="medium" <?php selected( $o( 'ai_image_quality', 'medium' ), 'medium' ); ?>><?php esc_html_e( 'בינונית (מומלץ)', 'kivun' ); ?></option>
							<option value="high" <?php selected( $o( 'ai_image_quality', 'medium' ), 'high' ); ?>><?php esc_html_e( 'גבוהה (יקר)', 'kivun' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'ב-dall-e-3: בינונית=standard, גבוהה=HD.', 'kivun' ); ?><br>
							<?php esc_html_e( 'כל תמונה נחתכת אוטומטית ליחס 16:9 לרוחב ונדחסת לכל היותר 300KB.', 'kivun' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile — Site Key', 'kivun' ); ?></th>
					<td>
						<input type="text" name="turnstile_site_key" value="<?php echo esc_attr( $o( 'turnstile_site_key' ) ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'מפתח אתר מ-Cloudflare Turnstile. כשממולא, תופיע הגנת CAPTCHA בטופס הגשת המועמדות.', 'kivun' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile — Secret Key', 'kivun' ); ?></th>
					<td>
						<input type="password" name="turnstile_secret_key" value="<?php echo esc_attr( $o( 'turnstile_secret_key' ) ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'מפתח סודי לאימות בצד השרת. נשמר פרטית ולא נחשף בפרונטאנד.', 'kivun' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'שמור הגדרות' ); ?>
		</form>
		<?php
	}
}
