<?php
/**
 * Admin metaboxes, columns, CRM views, and AJAX handlers for the plugin.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin UI: metaboxes, list columns, CRM pages, and AJAX endpoints.
 */
class Kivun_Admin {

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );

		// Admin columns.
		add_filter( 'manage_kivun_job_posts_columns', array( __CLASS__, 'job_columns' ) );
		add_action( 'manage_kivun_job_posts_custom_column', array( __CLASS__, 'job_column_values' ), 10, 2 );

		add_filter( 'manage_kivun_course_posts_columns', array( __CLASS__, 'course_columns' ) );
		add_action( 'manage_kivun_course_posts_custom_column', array( __CLASS__, 'course_column_values' ), 10, 2 );

		// Applications & Registrations admin pages.
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );

		// WC product search for course metabox.
		add_action( 'wp_ajax_kivun_search_products', array( __CLASS__, 'ajax_search_products' ) );

		// Inline status update.
		add_action( 'wp_ajax_kivun_update_status', array( __CLASS__, 'ajax_update_status' ) );

		// Notes save.
		add_action( 'wp_ajax_kivun_save_note', array( __CLASS__, 'ajax_save_note' ) );

		// Delete a CRM row (registration / application).
		add_action( 'wp_ajax_kivun_delete_row', array( __CLASS__, 'ajax_delete_row' ) );
	}

	// ── Meta boxes ─────────────────────────────────────────────────────────────.

	/**
	 * Register all post-type metaboxes.
	 *
	 * @return void
	 */
	public static function register_meta_boxes(): void {
		add_meta_box( 'kivun_course_details', 'פרטי קורס', array( __CLASS__, 'course_meta_box' ), 'kivun_course', 'normal', 'high' );
		add_meta_box( 'kivun_workshop_details', 'פרטי סדנה', array( __CLASS__, 'workshop_meta_box' ), 'kivun_workshop', 'normal', 'high' );
		add_meta_box( 'kivun_session_details', 'פרטי סדנה', array( __CLASS__, 'session_meta_box' ), 'kivun_session', 'normal', 'high' );
		add_meta_box( 'kivun_event_details', 'פרטי אירוע', array( __CLASS__, 'event_meta_box' ), 'kivun_event', 'normal', 'high' );
		add_meta_box( 'kivun_job_details', 'פרטי משרה', array( __CLASS__, 'job_meta_box' ), 'kivun_job', 'normal', 'high' );

		// CRM metaboxes.
		add_meta_box( 'kivun_course_leads', 'הרשמות ולידים', array( __CLASS__, 'registrations_metabox' ), 'kivun_course', 'normal', 'default' );
		add_meta_box( 'kivun_workshop_leads', 'הרשמות לסדנה', array( __CLASS__, 'registrations_metabox' ), 'kivun_workshop', 'normal', 'default' );
		add_meta_box( 'kivun_session_leads', 'הרשמות לסדנה', array( __CLASS__, 'registrations_metabox' ), 'kivun_session', 'normal', 'default' );
		add_meta_box( 'kivun_event_leads', 'הרשמות לאירוע', array( __CLASS__, 'registrations_metabox' ), 'kivun_event', 'normal', 'default' );
		add_meta_box( 'kivun_job_apps', 'מועמדויות וקו"ח', array( __CLASS__, 'applications_metabox' ), 'kivun_job', 'normal', 'default' );
	}

	/**
	 * Render the session (workshop) details metabox.
	 *
	 * @param \WP_Post $post The session post being edited.
	 * @return void
	 */
	public static function session_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'kivun_save_session', 'kivun_session_nonce' );
		$f    = fn( $key ) => get_post_meta( $post->ID, $key, true );
		$open = kivun_session_registration_open( $post->ID );
		?>
		<table class="kivun-meta-table">
			<tr>
				<th><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></th>
				<td><textarea name="_kivun_session_short" rows="2"><?php echo esc_textarea( $f( '_kivun_session_short' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_session_audience" value="<?php echo esc_attr( $f( '_kivun_session_audience' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_session_date" value="<?php echo esc_attr( $f( '_kivun_session_date' ) ); ?>" placeholder="15.9.2025 | 18:00"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'משך', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_session_duration" value="<?php echo esc_attr( $f( '_kivun_session_duration' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'עלות', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_session_cost" value="<?php echo esc_attr( $f( '_kivun_session_cost' ) ); ?>" placeholder="חינם / 120 ₪"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מיקום', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_session_location" value="<?php echo esc_attr( $f( '_kivun_session_location' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></th>
				<td><input type="number" name="_kivun_capacity" value="<?php echo esc_attr( $f( '_kivun_capacity' ) ); ?>" min="1"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></th>
				<td><input type="email" name="_kivun_contact_email" value="<?php echo esc_attr( $f( '_kivun_contact_email' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תוקף ההרשמה (עד תאריך)', 'kivun' ); ?></th>
				<td>
					<input type="date" name="_kivun_session_valid_until" value="<?php echo esc_attr( $f( '_kivun_session_valid_until' ) ); ?>">
					<p class="description">
						<?php
						echo $open
							? '<span style="color:#1a7a40;font-weight:600">' . esc_html__( 'המחזור הנוכחי פתוח.', 'kivun' ) . '</span> '
							: '<span style="color:#b45309;font-weight:600">' . esc_html__( 'המחזור הנוכחי סגור — נרשמים חדשים נכנסים כרשומות "למחזור הבא".', 'kivun' ) . '</span> ';
						esc_html_e( 'אחרי תאריך זה המחזור הנוכחי נסגר, אך ההרשמה נשארת פתוחה והנרשמים נשמרים למחזור הבא. עדכון לתאריך עתידי פותח מחזור חדש. ריק = תמיד פתוח.', 'kivun' );
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the event details metabox.
	 *
	 * @param \WP_Post $post The event post being edited.
	 * @return void
	 */
	public static function event_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'kivun_save_event', 'kivun_event_nonce' );
		$f    = fn( $key ) => get_post_meta( $post->ID, $key, true );
		$open = kivun_event_registration_open( $post->ID );
		$mode = kivun_event_mode( $post->ID );
		?>
		<table class="kivun-meta-table">
			<tr>
				<th><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></th>
				<td><textarea name="_kivun_event_short" rows="2"><?php echo esc_textarea( $f( '_kivun_event_short' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_event_audience" value="<?php echo esc_attr( $f( '_kivun_event_audience' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תאריך האירוע', 'kivun' ); ?></th>
				<td>
					<input type="date" name="_kivun_event_date" value="<?php echo esc_attr( $f( '_kivun_event_date' ) ); ?>">
					<p class="description">
						<?php
						echo $open
							? '<span style="color:#1a7a40;font-weight:600">' . esc_html__( 'ההרשמה פתוחה.', 'kivun' ) . '</span> '
							: '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'ההרשמה סגורה — האירוע כבר התקיים.', 'kivun' ) . '</span> ';
						esc_html_e( 'לאחר תאריך האירוע ההרשמה נסגרת לצמיתות. ריק = תמיד פתוח.', 'kivun' );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מועד מלא', 'kivun' ); ?></th>
				<td>
					<input type="text" name="_kivun_event_time" value="<?php echo esc_attr( $f( '_kivun_event_time' ) ); ?>" placeholder="<?php esc_attr_e( 'יום ג׳, 15.9.2025, 18:00–20:00', 'kivun' ); ?>">
					<p class="description"><?php esc_html_e( 'טקסט חופשי לתצוגה: שעה, משך ופרטי מועד. (תאריך האירוע למעלה קובע את סגירת ההרשמה.)', 'kivun' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'עלות', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_event_cost" value="<?php echo esc_attr( $f( '_kivun_event_cost' ) ); ?>" placeholder="חינם / 120 ₪"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מיקום', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_event_location" value="<?php echo esc_attr( $f( '_kivun_event_location' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></th>
				<td><input type="number" name="_kivun_capacity" value="<?php echo esc_attr( $f( '_kivun_capacity' ) ); ?>" min="1"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></th>
				<td><input type="email" name="_kivun_contact_email" value="<?php echo esc_attr( $f( '_kivun_contact_email' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'אופן ההרשמה', 'kivun' ); ?></th>
				<td>
					<select name="_kivun_event_mode">
						<option value="form" <?php selected( $mode, 'form' ); ?>><?php esc_html_e( 'טופס באתר (נשמר בטבלת הלידים)', 'kivun' ); ?></option>
						<option value="external" <?php selected( $mode, 'external' ); ?>><?php esc_html_e( 'כפתור לקישור חיצוני', 'kivun' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'עם השורטקוד [kivun_event_register] בעמוד: "טופס" מציג טופס הרשמה שנשמר במערכת; "חיצוני" מציג כפתור לקישור שתזינו למטה.', 'kivun' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'קישור הרשמה חיצוני', 'kivun' ); ?></th>
				<td><input type="url" name="_kivun_event_external_url" value="<?php echo esc_attr( $f( '_kivun_event_external_url' ) ); ?>" placeholder="https://..."></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'טקסט כפתור ההרשמה', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_event_button" value="<?php echo esc_attr( $f( '_kivun_event_button' ) ); ?>" placeholder="<?php esc_attr_e( 'להרשמה לאירוע', 'kivun' ); ?>"></td>
			</tr>
			<?php
			$ev_img     = (int) $f( '_kivun_event_image' );
			$ev_img_url = $ev_img ? (string) wp_get_attachment_image_url( $ev_img, 'medium' ) : '';
			?>
			<tr>
				<th><?php esc_html_e( 'תמונת האירוע (לפופאפ)', 'kivun' ); ?></th>
				<td>
					<div class="kivun-ev-media">
						<div class="kivun-ev-media__preview" <?php echo $ev_img_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $ev_img_url ); ?>" alt="" style="max-width:160px;height:auto;display:block;margin-bottom:.4rem"></div>
						<input type="hidden" name="_kivun_event_image" class="kivun-ev-media__id" value="<?php echo esc_attr( (string) $ev_img ); ?>">
						<button type="button" class="button kivun-ev-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
						<button type="button" class="button-link kivun-ev-media__remove" <?php echo $ev_img_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'מומלץ עיצוב מלבני צר וגבוה. משמש לפופאפ באתר ולתמונה הראשית.', 'kivun' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'פופאפ באתר', 'kivun' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="_kivun_event_popup" value="1" <?php checked( '1', (string) $f( '_kivun_event_popup' ) ); ?>>
						<?php esc_html_e( 'הצג פופאפ עם תמונת האירוע בכל האתר עד מועד האירוע', 'kivun' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<script>
		( function () {
			var frame,
				sel = document.querySelector( '.kivun-ev-media__select' ),
				rm  = document.querySelector( '.kivun-ev-media__remove' ),
				idEl = document.querySelector( '.kivun-ev-media__id' ),
				pr  = document.querySelector( '.kivun-ev-media__preview' ),
				img = pr ? pr.querySelector( 'img' ) : null;
			if ( sel ) {
				sel.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( ! window.wp || ! wp.media ) { return; }
					if ( frame ) { frame.open(); return; }
					frame = wp.media( { multiple: false } );
					frame.on( 'select', function () {
						var a = frame.state().get( 'selection' ).first().toJSON();
						if ( idEl ) { idEl.value = a.id; }
						if ( img ) { img.src = ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ); }
						if ( pr ) { pr.style.display = ''; }
						if ( rm ) { rm.style.display = ''; }
					} );
					frame.open();
				} );
			}
			if ( rm ) {
				rm.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( idEl ) { idEl.value = ''; }
					if ( pr ) { pr.style.display = 'none'; }
					rm.style.display = 'none';
				} );
			}
		} () );
		</script>
		<?php
	}

	/**
	 * Render the course details metabox.
	 *
	 * @param \WP_Post $post The course post being edited.
	 * @return void
	 */
	public static function course_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'kivun_save_course', 'kivun_course_nonce' );
		$f = fn( $key ) => get_post_meta( $post->ID, $key, true );
		?>
		<table class="kivun-meta-table">
			<tr>
				<th><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></th>
				<td><textarea name="_kivun_target_audience" rows="3"><?php echo esc_textarea( $f( '_kivun_target_audience' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'זמנים / מועדים', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_schedule" value="<?php echo esc_attr( $f( '_kivun_schedule' ) ); ?>" placeholder="ג׳ 18:00–20:00 | מתחיל 1.9"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'משך הקורס', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_duration" value="<?php echo esc_attr( $f( '_kivun_duration' ) ); ?>" placeholder="10 מפגשים"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'עלות', 'kivun' ); ?></th>
				<td>
					<input type="number" name="_kivun_price" value="<?php echo esc_attr( $f( '_kivun_price' ) ); ?>" min="0" placeholder="0 = חינמי">
					<small> ₪ — 0 לקורס חינמי</small>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מוצר WooCommerce', 'kivun' ); ?></th>
				<td>
					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
						<select
							id="_kivun_wc_product_select"
							name="_kivun_wc_product_id"
							data-selected="<?php echo esc_attr( $f( '_kivun_wc_product_id' ) ); ?>"
							style="width:400px"
						>
							<option value=""></option>
						</select>
						<p class="description" style="margin-top:6px">
							<?php esc_html_e( 'לקורס בתשלום: צור תחילה מוצר WooCommerce (פשוט) עם המחיר הרצוי, ואז חפש ושייך אותו כאן. שדה ריק = קורס חינמי.', 'kivun' ); ?>
							<br>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>" target="_blank">
								+ <?php esc_html_e( 'צור מוצר חדש', 'kivun' ); ?>
							</a>
						</p>
					<?php else : ?>
						<input type="number" name="_kivun_wc_product_id" value="<?php echo esc_attr( $f( '_kivun_wc_product_id' ) ); ?>" placeholder="Product ID">
						<p class="description" style="color:#b32d2e">
							<?php esc_html_e( 'WooCommerce אינו מותקן. שדה זה נדרש רק לקורסים בתשלום.', 'kivun' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'יתרונות / מה תלמד', 'kivun' ); ?></th>
				<td><textarea name="_kivun_benefits" rows="4"><?php echo esc_textarea( $f( '_kivun_benefits' ) ); ?></textarea>
				<small>שורה לכל יתרון</small></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></th>
				<td><input type="number" name="_kivun_capacity" value="<?php echo esc_attr( $f( '_kivun_capacity' ) ); ?>" min="1"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></th>
				<td><input type="email" name="_kivun_contact_email" value="<?php echo esc_attr( $f( '_kivun_contact_email' ) ); ?>"></td>
			</tr>
			<tr>
				<th colspan="2" style="padding-top:18px"><strong><?php esc_html_e( 'באנר הנעה לפעולה (CTA)', 'kivun' ); ?></strong>
				<small style="font-weight:400">— לשאיבה בבאנר שבנוי ב‑Elementor דרך תגיות דינמיות</small></th>
			</tr>
			<tr>
				<th><?php esc_html_e( 'כותרת הבאנר', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_cta_title" value="<?php echo esc_attr( $f( '_kivun_cta_title' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תוכן קצר', 'kivun' ); ?></th>
				<td><textarea name="_kivun_cta_content" rows="2"><?php echo esc_textarea( $f( '_kivun_cta_content' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'טקסט הכפתור', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_cta_button" value="<?php echo esc_attr( $f( '_kivun_cta_button' ) ); ?>" placeholder="<?php esc_attr_e( 'ריק = "להרשמה ל<שם הפריט>"', 'kivun' ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the workshop details metabox.
	 *
	 * @param \WP_Post $post The workshop post being edited.
	 * @return void
	 */
	public static function workshop_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'kivun_save_workshop', 'kivun_workshop_nonce' );
		$f = fn( $key ) => get_post_meta( $post->ID, $key, true );
		?>
		<table class="kivun-meta-table">
			<tr>
				<th><?php esc_html_e( 'תאריך הסדנה', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_ws_date" value="<?php echo esc_attr( $f( '_kivun_ws_date' ) ); ?>" placeholder="ד׳ 15.9.2025 | 18:00–21:00"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'משך הסדנה', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_ws_duration" value="<?php echo esc_attr( $f( '_kivun_ws_duration' ) ); ?>" placeholder="3 שעות"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מיקום', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_ws_location" value="<?php echo esc_attr( $f( '_kivun_ws_location' ) ); ?>" placeholder="תל אביב / זום / כתובת מדויקת"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'למי מיועדת', 'kivun' ); ?></th>
				<td><textarea name="_kivun_ws_audience" rows="3"><?php echo esc_textarea( $f( '_kivun_ws_audience' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></th>
				<td><input type="number" name="_kivun_ws_capacity" value="<?php echo esc_attr( $f( '_kivun_ws_capacity' ) ); ?>" min="1"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></th>
				<td>
					<input type="email" name="_kivun_contact_email" value="<?php echo esc_attr( $f( '_kivun_contact_email' ) ); ?>">
					<small><?php esc_html_e( 'השאר ריק לשימוש באימייל הגלובלי מההגדרות', 'kivun' ); ?></small>
				</td>
			</tr>
		</table>
		<?php
	}

	// ── CRM: Registrations / Leads metabox ───────────────────────────────────.

	/**
	 * Render the registrations / leads CRM metabox for a course or workshop.
	 *
	 * @param \WP_Post $post The course or workshop post.
	 * @return void
	 */
	public static function registrations_metabox( \WP_Post $post ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}kivun_registrations WHERE course_id = %d ORDER BY created_at DESC",
				$post->ID
			)
		);

		if ( ! $rows ) {
			echo '<p style="color:#6b7280;padding:.5rem 0">' . esc_html__( 'אין הרשמות או לידים עדיין.', 'kivun' ) . '</p>';
			return;
		}

		$reg_statuses = self::reg_statuses();
		$type_labels  = self::reg_type_labels();

		printf(
			'<p style="margin-bottom:.5rem"><a href="%s" class="button button-small">⬇ ייצוא CSV</a></p>',
			esc_url( Kivun_Export::url( 'registrations', $post->ID ) )
		);

		echo '<div style="overflow-x:auto"><table class="kivun-inner-table wp-list-table widefat fixed striped">';
		echo '<thead><tr>
			<th style="width:120px">שם</th>
			<th style="width:105px">טלפון</th>
			<th style="width:150px">אימייל</th>
			<th style="width:65px">סוג</th>
			<th style="width:120px">הערה</th>
			<th>הערות פנימיות</th>
			<th style="width:100px">תאריך</th>
			<th style="width:145px">סטטוס</th>
		</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$type = $type_labels[ $r->type ?? 'registration' ] ?? $r->type;
			printf(
				'<tr>
					<td><strong>%s</strong></td>
					<td><a href="tel:%s">%s</a></td>
					<td>%s</td>
					<td><span class="kivun-type-badge">%s</span></td>
					<td class="kivun-message-cell" title="%s">%s</td>
					<td>%s</td>
					<td style="font-size:12px">%s</td>
					<td>%s <span class="kivun-saved-indicator" style="display:none"></span></td>
				</tr>',
				esc_html( $r->name ),
				esc_attr( $r->phone ),
				esc_html( $r->phone ),
				esc_html( $r->email ),
				esc_html( $type ),
				esc_attr( $r->message ?? '' ),
				esc_html( wp_trim_words( $r->message ?? '', 8 ) ),
				self::notes_input( 'registrations', (int) $r->id, $r->notes ?? '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in notes_input.
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $r->created_at ) ) ),
				self::status_select( 'registrations', (int) $r->id, $r->status, $reg_statuses ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in status_select.
			);
		}

		echo '</tbody></table></div>';
	}

	// ── CRM: Applications metabox ─────────────────────────────────────────────.

	/**
	 * Render the applications CRM metabox for a job.
	 *
	 * @param \WP_Post $post The job post.
	 * @return void
	 */
	public static function applications_metabox( \WP_Post $post ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}kivun_applications WHERE job_id = %d ORDER BY created_at DESC",
				$post->ID
			)
		);

		if ( ! $rows ) {
			echo '<p style="color:#6b7280;padding:.5rem 0">' . esc_html__( 'אין מועמדויות עדיין.', 'kivun' ) . '</p>';
			return;
		}

		$app_statuses = array(
			'new'       => 'חדש',
			'viewed'    => 'נצפה',
			'contacted' => 'נוצר קשר',
			'interview' => 'מוזמן לראיון',
			'hired'     => 'גויס ✓',
			'rejected'  => 'לא מתאים',
		);

		printf(
			'<p style="margin-bottom:.5rem"><a href="%s" class="button button-small">⬇ ייצוא CSV</a></p>',
			esc_url( Kivun_Export::url( 'applications', $post->ID ) )
		);

		echo '<div style="overflow-x:auto"><table class="kivun-inner-table wp-list-table widefat fixed striped">';
		echo '<thead><tr>
			<th style="width:120px">שם</th>
			<th style="width:150px">אימייל</th>
			<th style="width:105px">טלפון</th>
			<th style="width:110px">מכתב</th>
			<th>הערות פנימיות</th>
			<th style="width:80px">קו"ח</th>
			<th style="width:100px">תאריך</th>
			<th style="width:145px">סטטוס</th>
		</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$cv_html = '—';
			if ( $r->cv_file && file_exists( $r->cv_file ) ) {
				$cv_url  = Kivun_Jobs::cv_url( (int) $r->id );
				$cv_html = '<a href="' . esc_url( $cv_url ) . '" target="_blank" class="button button-small">⬇ הורד</a>';
			}

			printf(
				'<tr>
					<td><strong>%s</strong></td>
					<td>%s</td>
					<td><a href="tel:%s">%s</a></td>
					<td class="kivun-message-cell" title="%s">%s</td>
					<td>%s</td>
					<td>%s</td>
					<td style="font-size:12px">%s</td>
					<td>%s <span class="kivun-saved-indicator" style="display:none"></span></td>
				</tr>',
				esc_html( $r->applicant_name ),
				esc_html( $r->applicant_email ),
				esc_attr( $r->applicant_phone ),
				esc_html( $r->applicant_phone ),
				esc_attr( $r->message ?? '' ),
				esc_html( wp_trim_words( $r->message ?? '', 8 ) ),
				self::notes_input( 'applications', (int) $r->id, $r->notes ?? '' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in notes_input.
				$cv_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cv_html is built with esc_url above.
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $r->created_at ) ) ),
				self::status_select( 'applications', (int) $r->id, $r->status, $app_statuses ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in status_select.
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Registration/lead status vocabulary, shared by the wp-admin CRM page and
	 * the front-end content console so both speak the same language.
	 *
	 * @return array<string,string>
	 */
	public static function reg_statuses(): array {
		return array(
			'new_lead'     => __( 'ליד חדש', 'kivun' ),
			'next_cycle'   => __( 'למחזור הבא', 'kivun' ),
			'new'          => __( 'חדש', 'kivun' ),
			'contacted'    => __( 'נוצר קשר', 'kivun' ),
			'interested'   => __( 'מעוניין', 'kivun' ),
			'enrolled'     => __( 'נרשם סופית', 'kivun' ),
			'closed'       => __( 'נסגר ✓', 'kivun' ),
			'not_relevant' => __( 'לא רלוונטי', 'kivun' ),
		);
	}

	/**
	 * Registration/lead source-type labels, shared with the front-end console.
	 *
	 * @return array<string,string>
	 */
	public static function reg_type_labels(): array {
		return array(
			'registration' => __( 'הרשמה', 'kivun' ),
			'lead'         => __( 'מתעניין', 'kivun' ),
			'workshop'     => __( 'דף נחיתה', 'kivun' ),
			'session'      => __( 'סדנה', 'kivun' ),
			'event'        => __( 'אירוע', 'kivun' ),
			'form'         => __( 'טופס באתר', 'kivun' ),
		);
	}

	/**
	 * Build an escaped notes textarea for a CRM row.
	 *
	 * @param string $table The CRM table key ('registrations' or 'applications').
	 * @param int    $id    The row ID.
	 * @param string $value The current note value.
	 * @return string
	 */
	public static function notes_input( string $table, int $id, string $value ): string {
		return sprintf(
			'<textarea class="kivun-notes-input" data-table="%s" data-id="%d" rows="2" placeholder="%s">%s</textarea>',
			esc_attr( $table ),
			$id,
			esc_attr__( 'הוסף הערה פנימית...', 'kivun' ),
			esc_textarea( $value )
		);
	}

	/**
	 * AJAX handler: save an internal note on a CRM row.
	 *
	 * @return void
	 */
	public static function ajax_save_note(): void {
		check_ajax_referer( 'kivun_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$table = sanitize_key( wp_unslash( $_POST['table'] ?? '' ) );
		$id    = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$note  = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

		if ( ! in_array( $table, array( 'registrations', 'applications' ), true ) || ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'kivun_' . $table,
			array( 'notes' => $note ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * Build an escaped status <select> for a CRM row.
	 *
	 * @param string $table   The CRM table key ('registrations' or 'applications').
	 * @param int    $id      The row ID.
	 * @param string $current The currently selected status.
	 * @param array  $options Map of status value => label.
	 * @return string
	 */
	public static function status_select( string $table, int $id, string $current, array $options ): string {
		$html = sprintf(
			'<select class="kivun-status-select" data-table="%s" data-id="%d">',
			esc_attr( $table ),
			$id
		);
		foreach ( $options as $val => $label ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}
		$html .= '</select>';
		return $html;
	}

	// ── AJAX: update status ────────────────────────────────────────────────────.

	/**
	 * AJAX handler: update the status of a CRM row.
	 *
	 * @return void
	 */
	public static function ajax_update_status(): void {
		check_ajax_referer( 'kivun_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$table  = sanitize_key( wp_unslash( $_POST['table'] ?? '' ) );
		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );

		$allowed = array( 'registrations', 'applications' );
		if ( ! in_array( $table, $allowed, true ) || ! $id || ! $status ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'kivun_' . $table,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * AJAX handler: delete a CRM row (registration or application).
	 *
	 * @return void
	 */
	public static function ajax_delete_row(): void {
		check_ajax_referer( 'kivun_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$table = sanitize_key( wp_unslash( $_POST['table'] ?? '' ) );
		$id    = absint( wp_unslash( $_POST['id'] ?? 0 ) );

		if ( ! in_array( $table, array( 'registrations', 'applications' ), true ) || ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'kivun_' . $table,
			array( 'id' => $id ),
			array( '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * Render the job details metabox.
	 *
	 * @param \WP_Post $post The job post being edited.
	 * @return void
	 */
	public static function job_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'kivun_save_job', 'kivun_job_nonce' );
		$f = fn( $key ) => get_post_meta( $post->ID, $key, true );
		?>
		<table class="kivun-meta-table">
			<tr>
				<th><?php esc_html_e( 'אימייל מעסיק (פרטי)', 'kivun' ); ?></th>
				<td>
					<input type="email" name="_kivun_employer_email" value="<?php echo esc_attr( $f( '_kivun_employer_email' ) ); ?>">
					<small> לא יוצג בפרונטאנד</small>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'שם חברה', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_company" value="<?php echo esc_attr( $f( '_kivun_company' ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תיאור המשרה', 'kivun' ); ?></th>
				<td><textarea name="_kivun_description" rows="6"><?php echo esc_textarea( $f( '_kivun_description' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'שכר / טווח', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_salary" value="<?php echo esc_attr( $f( '_kivun_salary' ) ); ?>" placeholder="10,000–15,000 ₪"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'דרישות', 'kivun' ); ?></th>
				<td><textarea name="_kivun_requirements" rows="4"><?php echo esc_textarea( $f( '_kivun_requirements' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תאריך סגירה', 'kivun' ); ?></th>
				<td>
					<input type="date" name="_kivun_deadline" value="<?php echo esc_attr( $f( '_kivun_deadline' ) ); ?>">
					<small><?php esc_html_e( 'לאחר תאריך זה המשרה תרד מהאתר אוטומטית. ריק = ללא הגבלה.', 'kivun' ); ?></small>
				</td>
			</tr>
		</table>
		<?php
	}

	// ── Save ───────────────────────────────────────────────────────────────────.

	/**
	 * Persist metabox fields on save_post.
	 *
	 * @param int      $post_id The post ID being saved.
	 * @param \WP_Post $post    The post object being saved.
	 * @return void
	 */
	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'kivun_course' === $post->post_type ) {
			if ( ! isset( $_POST['kivun_course_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_course_nonce'] ) ), 'kivun_save_course' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( array(
				'_kivun_target_audience' => 'textarea',
				'_kivun_schedule'        => 'text',
				'_kivun_duration'        => 'text',
				'_kivun_price'           => 'absint',
				'_kivun_wc_product_id'   => 'absint',
				'_kivun_benefits'        => 'textarea',
				'_kivun_capacity'        => 'absint',
				'_kivun_contact_email'   => 'email',
				'_kivun_cta_title'       => 'text',
				'_kivun_cta_content'     => 'textarea',
				'_kivun_cta_button'      => 'text',
			) as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( 'kivun_workshop' === $post->post_type ) {
			if ( ! isset( $_POST['kivun_workshop_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_workshop_nonce'] ) ), 'kivun_save_workshop' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( array(
				'_kivun_ws_date'       => 'text',
				'_kivun_ws_duration'   => 'text',
				'_kivun_ws_location'   => 'text',
				'_kivun_ws_audience'   => 'textarea',
				'_kivun_ws_capacity'   => 'absint',
				'_kivun_contact_email' => 'email',
			) as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( 'kivun_session' === $post->post_type ) {
			if ( ! isset( $_POST['kivun_session_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_session_nonce'] ) ), 'kivun_save_session' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( array(
				'_kivun_session_short'       => 'textarea',
				'_kivun_session_audience'    => 'text',
				'_kivun_session_date'        => 'text',
				'_kivun_session_duration'    => 'text',
				'_kivun_session_cost'        => 'text',
				'_kivun_session_location'    => 'text',
				'_kivun_capacity'            => 'absint',
				'_kivun_contact_email'       => 'email',
				'_kivun_session_valid_until' => 'text',
			) as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( 'kivun_event' === $post->post_type ) {
			if ( ! isset( $_POST['kivun_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_event_nonce'] ) ), 'kivun_save_event' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( array(
				'_kivun_event_short'        => 'textarea',
				'_kivun_event_audience'     => 'text',
				'_kivun_event_date'         => 'text',
				'_kivun_event_time'         => 'text',
				'_kivun_event_cost'         => 'text',
				'_kivun_event_location'     => 'text',
				'_kivun_capacity'           => 'absint',
				'_kivun_contact_email'      => 'email',
				'_kivun_event_mode'         => 'text',
				'_kivun_event_external_url' => 'url',
				'_kivun_event_button'       => 'text',
				'_kivun_event_image'        => 'absint',
				'_kivun_event_popup'        => 'text',
			) as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( 'kivun_job' === $post->post_type ) {
			if ( ! isset( $_POST['kivun_job_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_job_nonce'] ) ), 'kivun_save_job' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( array(
				'_kivun_employer_email' => 'email',
				'_kivun_company'        => 'text',
				'_kivun_description'    => 'kses',
				'_kivun_salary'         => 'text',
				'_kivun_requirements'   => 'kses',
			) as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}

			// The closing date drives auto-expiry, so it goes through the same
			// validation and expired-flag clearing as the front-end dashboard
			// instead of being stored as free text.
			if ( isset( $_POST['_kivun_deadline'] ) ) {
				Kivun_Employer::set_job_deadline(
					$post_id,
					sanitize_text_field( wp_unslash( $_POST['_kivun_deadline'] ) )
				);
			}
		}
	}

	/**
	 * Sanitize and persist a single metabox field by type.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $key     The meta key (and matching $_POST field name).
	 * @param string $type    The field type: 'textarea', 'absint', 'email', or text.
	 * @return void
	 */
	private static function save_field( int $post_id, string $key, string $type ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_meta(); value sanitized below per field type.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		switch ( $type ) {
			case 'textarea':
				$value = sanitize_textarea_field( $raw );
				break;
			case 'absint':
				$value = absint( $raw );
				break;
			case 'email':
				$value = sanitize_email( $raw );
				break;
			case 'kses':
				$value = wp_kses_post( $raw );
				break;
			case 'url':
				$value = esc_url_raw( $raw );
				break;
			default:
				$value = sanitize_text_field( $raw );
				break;
		}
		update_post_meta( $post_id, $key, $value );
	}

	// ── Admin columns ──────────────────────────────────────────────────────────.

	/**
	 * Add custom columns to the job list table.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public static function job_columns( array $cols ): array {
		return array_merge(
			$cols,
			array(
				'company'  => 'חברה',
				'deadline' => 'תאריך אחרון',
			)
		);
	}

	/**
	 * Render values for the custom job columns.
	 *
	 * @param string $col The column key.
	 * @param int    $id  The post ID.
	 * @return void
	 */
	public static function job_column_values( string $col, int $id ): void {
		if ( 'company' === $col ) {
			echo esc_html( get_post_meta( $id, '_kivun_company', true ) );
		}
		if ( 'deadline' === $col ) {
			echo esc_html( get_post_meta( $id, '_kivun_deadline', true ) );
		}
	}

	/**
	 * Add custom columns to the course list table.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public static function course_columns( array $cols ): array {
		return array_merge(
			$cols,
			array(
				'price'    => 'עלות',
				'schedule' => 'זמנים',
			)
		);
	}

	/**
	 * Render values for the custom course columns.
	 *
	 * @param string $col The column key.
	 * @param int    $id  The post ID.
	 * @return void
	 */
	public static function course_column_values( string $col, int $id ): void {
		if ( 'price' === $col ) {
			$price = (int) get_post_meta( $id, '_kivun_price', true );
			echo $price > 0 ? esc_html( '₪' . number_format( $price ) ) : esc_html__( 'חינמי', 'kivun' );
		}
		if ( 'schedule' === $col ) {
			echo esc_html( get_post_meta( $id, '_kivun_schedule', true ) );
		}
	}

	// ── WC product search (AJAX) ───────────────────────────────────────────────.

	/**
	 * AJAX handler: search WooCommerce products for the course metabox.
	 *
	 * @return void
	 */
	public static function ajax_search_products(): void {
		check_ajax_referer( 'kivun_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error();
		}

		// Single product lookup by ID (for pre-select).
		if ( ! empty( $_POST['id'] ) ) {
			$product = wc_get_product( absint( wp_unslash( $_POST['id'] ) ) );
			if ( $product ) {
				wp_send_json_success(
					array(
						array(
							'id'   => $product->get_id(),
							'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
						),
					)
				);
			}
			wp_send_json_success( array() );
		}

		// Search by name.
		$term     = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 20,
				'orderby' => 'title',
				'order'   => 'ASC',
				's'       => $term,
			)
		);

		$results = array_map(
			fn( $p ) => array(
				'id'   => $p->get_id(),
				'text' => $p->get_name() . ' (#' . $p->get_id() . ')',
			),
			$products
		);

		wp_send_json_success( $results );
	}

	// ── Admin pages ────────────────────────────────────────────────────────────.

	/**
	 * Register the applications and registrations admin submenu pages.
	 *
	 * @return void
	 */
	public static function admin_menu(): void {
		add_submenu_page( 'edit.php?post_type=kivun_job', 'מועמדויות', 'מועמדויות', 'manage_options', 'kivun-applications', array( __CLASS__, 'applications_page' ) );
		add_submenu_page( 'edit.php?post_type=kivun_course', 'הרשמות ולידים', 'הרשמות ולידים', 'manage_options', 'kivun-registrations', array( __CLASS__, 'registrations_page' ) );

		// Prominent top-level shortcut to the unified leads & registrations screen
		// (aggregates every course/session/landing-page lead and all Elementor forms).
		add_menu_page(
			'לידים והרשמות',
			'לידים והרשמות',
			'manage_options',
			'edit.php?post_type=kivun_course&page=kivun-registrations',
			'',
			'dashicons-groups',
			'3.5'
		);

		// Convenience link under "דפי נחיתה" → the same registrations screen,
		// pre-filtered to landing-page leads (they all share one table).
		add_submenu_page(
			'edit.php?post_type=kivun_workshop',
			'לידים והרשמות',
			'לידים והרשמות',
			'manage_options',
			'edit.php?post_type=kivun_course&page=kivun-registrations&kivun_type=workshop'
		);
	}

	/**
	 * Render the applications admin list page.
	 *
	 * @return void
	 */
	public static function applications_page(): void {
		global $wpdb;

		// Inline status/notes editing assets for this page.
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );
		wp_enqueue_script( 'kivun-admin-crm', KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'admin-crm', 'js' ), array(), KIVUN_VERSION, true );
		wp_localize_script(
			'kivun-admin-crm',
			'kivunCrm',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_admin_nonce' ),
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$job_filter = isset( $_GET['kivun_job_id'] ) ? absint( wp_unslash( $_GET['kivun_job_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$conds = array();
		if ( $job_filter ) {
			$conds[] = $wpdb->prepare( 'a.job_id = %d', $job_filter );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$conds[] = $wpdb->prepare( '(a.applicant_name LIKE %s OR a.applicant_email LIKE %s OR a.applicant_phone LIKE %s)', $like, $like, $like );
		}
		$where = $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT a.*, p.post_title AS job_title
			 FROM {$wpdb->prefix}kivun_applications a
			 LEFT JOIN {$wpdb->posts} p ON p.ID = a.job_id
			 $where
			 ORDER BY a.created_at DESC
			 LIMIT 500"
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_applications" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$jobs = get_posts(
			array(
				'post_type'              => 'kivun_job',
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$statuses = Kivun_Employer::app_statuses();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'מועמדויות — כל המשרות', 'kivun' ); ?></h1>
			<p>
				<?php
				/* translators: %d: total number of applications. */
				echo esc_html( sprintf( __( 'סה״כ %d הגשות במערכת.', 'kivun' ), $total ) );
				?>
				<a href="<?php echo esc_url( Kivun_Export::url( 'applications', 0 ) ); ?>" class="button">⬇ <?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?></a>
			</p>

			<form method="get" class="kivun-apps-admin-filter" style="margin:1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="post_type" value="kivun_job">
				<input type="hidden" name="page" value="kivun-applications">
				<select name="kivun_job_id">
					<option value="0"><?php esc_html_e( 'כל המשרות', 'kivun' ); ?></option>
					<?php foreach ( $jobs as $job ) : ?>
						<option value="<?php echo esc_attr( $job->ID ); ?>" <?php selected( $job_filter, $job->ID ); ?>><?php echo esc_html( $job->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'חיפוש שם / אימייל / טלפון', 'kivun' ); ?>">
				<button class="button"><?php esc_html_e( 'סינון', 'kivun' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th style="width:130px"><?php esc_html_e( 'שם', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'משרה', 'kivun' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'אימייל', 'kivun' ); ?></th>
					<th style="width:105px"><?php esc_html_e( 'טלפון', 'kivun' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'קו"ח', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'הערות', 'kivun' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
					<th style="width:145px"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'לא נמצאו הגשות.', 'kivun' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $r->applicant_name ); ?></strong></td>
							<td>
								<?php if ( $r->job_id && get_post( $r->job_id ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $r->job_id ) ); ?>"><?php echo esc_html( $r->job_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $r->job_title ? $r->job_title : __( '(נמחקה)', 'kivun' ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $r->applicant_email ); ?></td>
							<td><a href="tel:<?php echo esc_attr( $r->applicant_phone ); ?>"><?php echo esc_html( $r->applicant_phone ); ?></a></td>
							<td>
								<?php if ( $r->cv_file && file_exists( $r->cv_file ) ) : ?>
									<a href="<?php echo esc_url( Kivun_Jobs::cv_url( (int) $r->id ) ); ?>" target="_blank" class="button button-small">⬇ <?php esc_html_e( 'הורד', 'kivun' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo self::notes_input( 'applications', (int) $r->id, $r->notes ?? '' );
								?>
								<span class="kivun-saved-indicator" style="display:none"></span>
							</td>
							<td style="font-size:12px"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $r->created_at ) ) ); ?></td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo self::status_select( 'applications', (int) $r->id, $r->status, $statuses );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the registrations admin list page.
	 *
	 * @return void
	 */
	public static function registrations_page(): void {
		global $wpdb;

		// Inline status/notes editing assets for this page.
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );
		wp_enqueue_script( 'kivun-admin-crm', KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'admin-crm', 'js' ), array(), KIVUN_VERSION, true );
		wp_localize_script(
			'kivun-admin-crm',
			'kivunCrm',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_admin_nonce' ),
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$course_filter = isset( $_GET['kivun_course_id'] ) ? absint( wp_unslash( $_GET['kivun_course_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$type_filter = isset( $_GET['kivun_type'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$reg_statuses = self::reg_statuses();
		$type_labels  = self::reg_type_labels();

		$conds = array();
		if ( $course_filter ) {
			$conds[] = $wpdb->prepare( 'r.course_id = %d', $course_filter );
		}
		if ( '' !== $type_filter && isset( $type_labels[ $type_filter ] ) ) {
			$conds[] = $wpdb->prepare( 'r.type = %s', $type_filter );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$conds[] = $wpdb->prepare( '(r.name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s)', $like, $like, $like );
		}
		$where = $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '';

		// Per-page + pagination.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$per_page = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 25;
		if ( ! in_array( $per_page, array( 25, 50, 100, 200, 500 ), true ) ) {
			$per_page = 25;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, ( $paged - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT r.*, p.post_title AS course_title
			 FROM {$wpdb->prefix}kivun_registrations r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id
			 $where
			 ORDER BY r.created_at DESC
			 $limit_sql"
		);
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations r $where" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total_pages = max( 1, (int) ceil( $found / $per_page ) );
		$paged       = min( $paged, $total_pages );

		$courses = get_posts(
			array(
				'post_type'              => array( 'kivun_course', 'kivun_workshop', 'kivun_session', 'kivun_event' ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'הרשמות, לידים וסדנאות', 'kivun' ); ?></h1>
			<p>
				<?php
				/* translators: %d: total number of registrations. */
				echo esc_html( sprintf( __( 'סה״כ %d רשומות במערכת.', 'kivun' ), $total ) );
				?>
				<a href="<?php echo esc_url( Kivun_Export::url( 'registrations', 0 ) ); ?>" class="button">⬇ <?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?></a>
			</p>

			<form method="get" class="kivun-apps-admin-filter" style="margin:1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="post_type" value="kivun_course">
				<input type="hidden" name="page" value="kivun-registrations">
				<select name="kivun_course_id">
					<option value="0"><?php esc_html_e( 'כל הקורסים והסדנאות', 'kivun' ); ?></option>
					<?php foreach ( $courses as $course ) : ?>
						<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $course_filter, $course->ID ); ?>><?php echo esc_html( $course->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="kivun_type">
					<option value=""><?php esc_html_e( 'כל הסוגים', 'kivun' ); ?></option>
					<?php foreach ( $type_labels as $type_val => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_val ); ?>" <?php selected( $type_filter, $type_val ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'חיפוש שם / אימייל / טלפון', 'kivun' ); ?>">
					<select name="per_page" title="<?php esc_attr_e( 'רשומות בעמוד', 'kivun' ); ?>">
						<?php foreach ( array( 25, 50, 100, 200, 500 ) as $pp_option ) : ?>
							<option value="<?php echo esc_attr( $pp_option ); ?>" <?php selected( $per_page, $pp_option ); ?>>
								<?php
								/* translators: %d: number of rows shown per page. */
								echo esc_html( sprintf( __( '%d בעמוד', 'kivun' ), $pp_option ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				<button class="button"><?php esc_html_e( 'סינון', 'kivun' ); ?></button>
			</form>

			<div class="kivun-reg-scroll">
			<table class="wp-list-table widefat striped kivun-reg-table">
				<thead><tr>
					<th style="width:130px"><?php esc_html_e( 'שם', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'קורס / סדנה', 'kivun' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'מקור', 'kivun' ); ?></th>
					<th style="width:70px"><?php esc_html_e( 'סוג', 'kivun' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'אימייל', 'kivun' ); ?></th>
					<th style="width:105px"><?php esc_html_e( 'טלפון', 'kivun' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'עיר', 'kivun' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'מגדר', 'kivun' ); ?></th>
						<th style="width:55px"><?php esc_html_e( 'דיוור', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'הערות', 'kivun' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
					<th style="width:145px"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
					<th style="width:60px"><?php esc_html_e( 'מחיקה', 'kivun' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="13"><?php esc_html_e( 'לא נמצאו רשומות.', 'kivun' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php $type_label = $type_labels[ $r->type ?? 'registration' ] ?? $r->type; ?>
						<tr>
							<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
							<td>
								<?php if ( $r->course_id && get_post( $r->course_id ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $r->course_id ) ); ?>"><?php echo esc_html( $r->course_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $r->course_title ? $r->course_title : __( '(נמחק)', 'kivun' ) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $r->source ?? '' ) ); ?></td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a></td>
							<td><a href="tel:<?php echo esc_attr( $r->phone ); ?>"><?php echo esc_html( $r->phone ); ?></a></td>
								<td><?php echo esc_html( (string) ( $r->city ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $r->gender ?? '' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $r->marketing_consent ) ? '✓' : '—' ); ?></td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo self::notes_input( 'registrations', (int) $r->id, $r->notes ?? '' );
								?>
								<span class="kivun-saved-indicator" style="display:none"></span>
							</td>
							<td style="font-size:12px"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $r->created_at ) ) ); ?></td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo self::status_select( 'registrations', (int) $r->id, $r->status, $reg_statuses );
								?>
							</td>
							<td>
								<button type="button" class="button-link kivun-delete-row" data-table="registrations" data-id="<?php echo esc_attr( $r->id ); ?>" style="color:#b32d2e" title="<?php esc_attr_e( 'מחיקה', 'kivun' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'מחיקה', 'kivun' ); ?></span></button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
				<?php
				$kivun_pg_base = array(
					'post_type' => 'kivun_course',
					'page'      => 'kivun-registrations',
					'per_page'  => $per_page,
				);
				if ( $course_filter ) {
					$kivun_pg_base['kivun_course_id'] = $course_filter; }
				if ( '' !== $type_filter ) {
					$kivun_pg_base['kivun_type'] = $type_filter; }
				if ( '' !== $search ) {
					$kivun_pg_base['s'] = $search; }
				$kivun_pg_url = function ( $p ) use ( $kivun_pg_base ) {
					return add_query_arg( array_merge( $kivun_pg_base, array( 'paged' => $p ) ), admin_url( 'edit.php' ) );
				};
	?>
				<div class="tablenav bottom"><div class="tablenav-pages" style="float:none;text-align:center;margin:1rem 0">
					<span class="displaying-num"><?php echo esc_html( number_format_i18n( $found ) ); ?> <?php esc_html_e( 'רשומות', 'kivun' ); ?></span>
					<span class="pagination-links" style="margin-inline-start:.75rem">
						<?php if ( $paged > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( $kivun_pg_url( 1 ) ); ?>">&laquo;</a>
							<a class="button" href="<?php echo esc_url( $kivun_pg_url( $paged - 1 ) ); ?>">&lsaquo;</a>
						<?php endif; ?>
						<span class="paging-input" style="margin:0 .5rem"><?php echo esc_html( $paged ); ?> <?php esc_html_e( 'מתוך', 'kivun' ); ?> <?php echo esc_html( $total_pages ); ?></span>
						<?php if ( $paged < $total_pages ) : ?>
							<a class="button" href="<?php echo esc_url( $kivun_pg_url( $paged + 1 ) ); ?>">&rsaquo;</a>
							<a class="button" href="<?php echo esc_url( $kivun_pg_url( $total_pages ) ); ?>">&raquo;</a>
						<?php endif; ?>
					</span>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
