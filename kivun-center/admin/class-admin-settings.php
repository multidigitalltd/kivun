<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Admin_Settings {

	private static string $option_key = 'kivun_settings';

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_post_kivun_save_settings', [ __CLASS__, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function add_menu(): void {
		add_menu_page(
			__( 'Kivun Center', 'kivun' ),
			__( 'Kivun Center', 'kivun' ),
			'manage_options',
			'kivun-settings',
			[ __CLASS__, 'render_page' ],
			'dashicons-businessman',
			4
		);
	}

	// ── Enqueue Select2 for WC product picker (metabox) ───────────────────────

	public static function enqueue( string $hook ): void {
		// Settings page
		if ( 'toplevel_page_kivun-settings' === $hook ) {
			wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/admin.css', [], KIVUN_VERSION );
		}

		// Course metabox — Select2 product picker
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			$screen = get_current_screen();
			if ( $screen && 'kivun_course' === $screen->post_type && class_exists( 'WooCommerce' ) ) {
				wp_enqueue_script( 'select2' );
				wp_enqueue_style( 'select2' );
				wp_enqueue_script(
					'kivun-admin-course',
					KIVUN_URL . 'assets/js/admin-course.js',
					[ 'jquery', 'select2' ],
					KIVUN_VERSION,
					true
				);
				wp_localize_script( 'kivun-admin-course', 'kivunAdmin', [
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'kivun_admin_nonce' ),
					'search_placeholder' => __( 'חפש מוצר...', 'kivun' ),
				] );
			}
		}
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	public static function save(): void {
		check_admin_referer( 'kivun_settings_save' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		update_option( self::$option_key, [
			'admin_email'        => sanitize_email( $_POST['admin_email'] ?? '' ),
			'jobs_per_page'      => absint( $_POST['jobs_per_page'] ?? 10 ),
			'allow_cv_upload'    => ! empty( $_POST['allow_cv_upload'] ),
			'cv_max_size_mb'     => absint( $_POST['cv_max_size_mb'] ?? 5 ),
			'webhook_url'        => esc_url_raw( $_POST['webhook_url'] ?? '' ),
		] );

		wp_redirect( admin_url( 'admin.php?page=kivun-settings&saved=1' ) );
		exit;
	}

	public static function get( string $key, $default = '' ) {
		$opts = get_option( self::$option_key, [] );
		return $opts[ $key ] ?? $default;
	}

	// ── Page ──────────────────────────────────────────────────────────────────

	public static function render_page(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'settings' );
		$tabs = [
			'settings'   => '⚙️ הגדרות',
			'meta-fields' => '🗂️ שדות מטה',
			'shortcodes' => '📋 שורטקודים',
		];
		?>
		<div class="wrap kivun-settings-wrap">
			<h1>
				<span class="dashicons dashicons-businessman" style="font-size:28px;height:28px;margin-left:8px;color:#2563eb"></span>
				Kivun Center
			</h1>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו.', 'kivun' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kivun-settings&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="kivun-tab-content">
				<?php
				match ( $tab ) {
					'settings'    => self::tab_settings(),
					'meta-fields' => self::tab_meta_fields(),
					'shortcodes'  => self::tab_shortcodes(),
					default       => self::tab_settings(),
				};
				?>
			</div>
		</div>
		<?php
	}

	// ── Tab: Settings ────────────────────────────────────────────────────────

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
			</table>

			<?php submit_button( 'שמור הגדרות' ); ?>
		</form>
		<?php
	}

	// ── Tab: Meta Fields ─────────────────────────────────────────────────────

	private static function tab_meta_fields(): void {
		$course_fields = [
			[ '_kivun_target_audience', 'קהל יעד',              'kivun_course', 'textarea', 'Dynamic Tag: קורס — קהל יעד' ],
			[ '_kivun_schedule',        'זמנים / מועדים',        'kivun_course', 'text',     'Dynamic Tag: קורס — זמנים' ],
			[ '_kivun_duration',        'משך הקורס',             'kivun_course', 'text',     'Dynamic Tag: קורס — משך' ],
			[ '_kivun_price',           'עלות (שקלים)',          'kivun_course', 'number',   'Dynamic Tag: קורס — עלות | 0 = חינמי' ],
			[ '_kivun_wc_product_id',   'מוצר WooCommerce',      'kivun_course', 'number',   'מזהה מוצר WC לתשלום — מלא רק לקורסים בתשלום' ],
			[ '_kivun_benefits',        'יתרונות / מה תלמד',    'kivun_course', 'textarea', 'שורה לכל יתרון' ],
			[ '_kivun_capacity',        'מקסימום משתתפים',       'kivun_course', 'number',   '' ],
			[ '_kivun_contact_email',   'אימייל לקבלת הרשמות',  'kivun_course', 'email',    'ידרוס את האימייל הגלובלי מההגדרות' ],
		];

		$job_fields = [
			[ '_kivun_company',         'שם חברה',              'kivun_job', 'text',    'Dynamic Tag: משרה — שם חברה' ],
			[ '_kivun_salary',          'שכר / טווח',           'kivun_job', 'text',    'Dynamic Tag: משרה — שכר | דוגמה: 10,000–15,000 ₪' ],
			[ '_kivun_requirements',    'דרישות',               'kivun_job', 'textarea','Dynamic Tag: משרה — דרישות' ],
			[ '_kivun_deadline',        'תאריך אחרון להגשה',    'kivun_job', 'date',    'Dynamic Tag: משרה — תאריך אחרון | פורמט: d/m/Y' ],
			[ '_kivun_employer_email',  'אימייל מעסיק (פרטי)',  'kivun_job', 'email',   '⚠️ לא נחשף לפרונטאנד — קו"ח נשלחים לכאן' ],
		];
		?>
		<h2><?php esc_html_e( 'שדות מטה — קורסים', 'kivun' ); ?></h2>
		<?php self::meta_table( $course_fields ); ?>

		<h2 style="margin-top:2rem"><?php esc_html_e( 'שדות מטה — משרות', 'kivun' ); ?></h2>
		<?php self::meta_table( $job_fields ); ?>

		<h2 style="margin-top:2rem"><?php esc_html_e( 'טקסונומיות', 'kivun' ); ?></h2>
		<table class="wp-list-table widefat fixed striped kivun-ref-table">
			<thead><tr><th>Slug</th><th>שם</th><th>Post Type</th><th>הירארכי</th></tr></thead>
			<tbody>
				<?php foreach ( [
					[ 'kivun_course_cat', 'קטגוריות קורסים',  'kivun_course', 'כן' ],
					[ 'kivun_job_scope',  'היקף משרה',        'kivun_job',    'לא' ],
					[ 'kivun_job_region', 'אזורים',           'kivun_job',    'כן' ],
					[ 'kivun_job_field',  'תחום מקצועי',      'kivun_job',    'כן' ],
				] as [ $slug, $name, $pt, $hier ] ) : ?>
					<tr>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo esc_html( $name ); ?></td>
						<td><code><?php echo esc_html( $pt ); ?></code></td>
						<td><?php echo esc_html( $hier ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function meta_table( array $fields ): void {
		?>
		<table class="wp-list-table widefat fixed striped kivun-ref-table">
			<thead>
				<tr>
					<th style="width:220px">מפתח (key)</th>
					<th style="width:160px">שם השדה</th>
					<th style="width:80px">סוג</th>
					<th>הערות</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fields as [ $key, $label, , $type, $note ] ) : ?>
					<tr>
						<td>
							<code class="kivun-copyable" title="לחץ להעתקה"><?php echo esc_html( $key ); ?></code>
						</td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><span class="kivun-type-badge"><?php echo esc_html( $type ); ?></span></td>
						<td><?php echo esc_html( $note ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ── Tab: Shortcodes ──────────────────────────────────────────────────────

	private static function tab_shortcodes(): void {
		$shortcodes = [
			[
				'tag'   => '[kivun_courses]',
				'title' => 'ארכיון קורסים',
				'desc'  => 'מציג רשת של קורסים עם תמונה, כותרת, עלות ו-CTA.',
				'attrs' => [
					'columns="3"'     => 'מספר עמודות (1–4)',
					'per_page="12"'   => 'כמות קורסים לעמוד',
					'category="slug"' => 'סינון לפי קטגוריה',
					'orderby="date"'  => 'מיון: date / title / menu_order',
				],
				'example' => '[kivun_courses columns="3" per_page="9" category="כישורי-עבודה"]',
			],
			[
				'tag'   => '[kivun_course_single id="X"]',
				'title' => 'קורס בודד מלא',
				'desc'  => 'מציג את כל פרטי הקורס: קהל יעד, זמנים, עלות, יתרונות, ולבסוף טופס הרשמה.',
				'attrs' => [
					'id="123"' => 'מזהה הפוסט. אם לא מצוין — משתמש ב-ID של העמוד הנוכחי',
				],
				'example' => '[kivun_course_single id="42"]',
			],
			[
				'tag'   => '[kivun_course_register id="X"]',
				'title' => 'טופס הרשמה לקורס',
				'desc'  => 'טופס הרשמה בלבד. לקורס חינמי — שולח מייל. לקורס בתשלום — מפנה ל-WooCommerce.',
				'attrs' => [
					'id="123"' => 'מזהה הקורס',
				],
				'example' => '[kivun_course_register id="42"]',
			],
			[
				'tag'   => '[kivun_jobs]',
				'title' => 'לוח משרות',
				'desc'  => 'לוח משרות עם AJAX פילטרים (היקף / אזור / תחום / חיפוש חופשי). כל כרטיסייה כוללת כפתור "שלח קו"ח" שנפתח inline.',
				'attrs' => [
					'show_filters="yes"' => 'הצג/הסתר שורת פילטרים (yes/no)',
					'per_page="10"'      => 'כמות משרות לטעינה',
					'scope="slug"'       => 'סינון מוקדם לפי היקף משרה',
					'region="slug"'      => 'סינון מוקדם לפי אזור',
					'field="slug"'       => 'סינון מוקדם לפי תחום',
				],
				'example' => '[kivun_jobs show_filters="yes" per_page="10"]',
			],
			[
				'tag'   => '[kivun_apply id="X"]',
				'title' => 'טופס שליחת קו"ח',
				'desc'  => 'טופס מועמדות בודד. קובץ נשלח לאימייל המעסיק בלי לחשוף אותו. מתאים לשילוב בעמוד משרה ספציפית.',
				'attrs' => [
					'id="123"' => 'מזהה המשרה',
				],
				'example' => '[kivun_apply id="55"]',
			],
			[
				'tag'   => '[kivun_employer_dashboard]',
				'title' => 'דשבורד מעסיק',
				'desc'  => 'ממשק ניהול חזיתי למעסיקים: פרסום משרה חדשה, צפייה ומחיקת משרות. מחייב התחברות עם תפקיד Employer.',
				'attrs' => [],
				'example' => '[kivun_employer_dashboard]',
			],
		];

		foreach ( $shortcodes as $sc ) :
			?>
			<div class="kivun-sc-card">
				<div class="kivun-sc-card__header">
					<code class="kivun-sc-tag kivun-copyable" title="לחץ להעתקה"><?php echo esc_html( $sc['tag'] ); ?></code>
					<span class="kivun-sc-card__title"><?php echo esc_html( $sc['title'] ); ?></span>
				</div>
				<p class="kivun-sc-card__desc"><?php echo esc_html( $sc['desc'] ); ?></p>

				<?php if ( $sc['attrs'] ) : ?>
					<table class="kivun-attrs-table">
						<thead><tr><th>פרמטר</th><th>תיאור</th></tr></thead>
						<tbody>
							<?php foreach ( $sc['attrs'] as $attr => $attrDesc ) : ?>
								<tr>
									<td><code><?php echo esc_html( $attr ); ?></code></td>
									<td><?php echo esc_html( $attrDesc ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<div class="kivun-sc-example">
					<span class="kivun-sc-example__label"><?php esc_html_e( 'דוגמה:', 'kivun' ); ?></span>
					<code class="kivun-copyable" title="לחץ להעתקה"><?php echo esc_html( $sc['example'] ); ?></code>
				</div>
			</div>
			<?php
		endforeach;

		// Inline copy-to-clipboard JS for this page only
		?>
		<script>
		document.querySelectorAll('.kivun-copyable').forEach(function(el) {
			el.style.cursor = 'pointer';
			el.addEventListener('click', function() {
				navigator.clipboard.writeText(el.textContent.trim()).then(function() {
					var orig = el.textContent;
					el.textContent = '✓ הועתק!';
					setTimeout(function() { el.textContent = orig; }, 1400);
				});
			});
		});
		</script>
		<?php
	}
}
