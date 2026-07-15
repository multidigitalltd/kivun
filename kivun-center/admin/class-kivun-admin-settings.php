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
	 * Register the admin hooks for the settings screen.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_kivun_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
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

		update_option(
			self::$option_key,
			array(
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
				'turnstile_site_key'     => sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ?? '' ) ),
				'turnstile_secret_key'   => sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ?? '' ) ),
			)
		);

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal admin URL redirect.
		wp_redirect( admin_url( 'admin.php?page=kivun-settings&saved=1' ) );
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
						<p class="description"><?php esc_html_e( 'כל הגשת טופס Elementor באתר תישלח גם לכתובת הזו. השאר ריק כדי לבטל.', 'kivun' ); ?><br><?php esc_html_e( 'אם הטופס נשלח מקורס / סדנה / דף נחיתה שהוגדר לו "אימייל לקבלת הלידים" — הכתובת הספציפית של אותו עמוד גוברת על הכתובת המרכזית.', 'kivun' ); ?></p>
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
