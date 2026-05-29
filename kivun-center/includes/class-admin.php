<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Admin {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'save_post',      [ __CLASS__, 'save_meta' ], 10, 2 );

		// Admin columns
		add_filter( 'manage_kivun_job_posts_columns',       [ __CLASS__, 'job_columns' ] );
		add_action( 'manage_kivun_job_posts_custom_column', [ __CLASS__, 'job_column_values' ], 10, 2 );

		add_filter( 'manage_kivun_course_posts_columns',       [ __CLASS__, 'course_columns' ] );
		add_action( 'manage_kivun_course_posts_custom_column', [ __CLASS__, 'course_column_values' ], 10, 2 );

		// Applications & Registrations admin pages
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );

		// WC product search for course metabox
		add_action( 'wp_ajax_kivun_search_products', [ __CLASS__, 'ajax_search_products' ] );
	}

	// ── Meta boxes ─────────────────────────────────────────────────────────────

	public static function register_meta_boxes(): void {
		add_meta_box( 'kivun_course_details',   'פרטי קורס',   [ __CLASS__, 'course_meta_box' ],   'kivun_course',   'normal', 'high' );
		add_meta_box( 'kivun_workshop_details', 'פרטי סדנה',   [ __CLASS__, 'workshop_meta_box' ], 'kivun_workshop', 'normal', 'high' );
		add_meta_box( 'kivun_job_details',      'פרטי משרה',   [ __CLASS__, 'job_meta_box' ],      'kivun_job',      'normal', 'high' );
	}

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
		</table>
		<?php
	}

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
				<th><?php esc_html_e( 'שכר / טווח', 'kivun' ); ?></th>
				<td><input type="text" name="_kivun_salary" value="<?php echo esc_attr( $f( '_kivun_salary' ) ); ?>" placeholder="10,000–15,000 ₪"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'דרישות', 'kivun' ); ?></th>
				<td><textarea name="_kivun_requirements" rows="4"><?php echo esc_textarea( $f( '_kivun_requirements' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'תאריך אחרון להגשה', 'kivun' ); ?></th>
				<td><input type="date" name="_kivun_deadline" value="<?php echo esc_attr( $f( '_kivun_deadline' ) ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	// ── Save ───────────────────────────────────────────────────────────────────

	public static function save_meta( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;

		if ( $post->post_type === 'kivun_course' ) {
			if ( ! isset( $_POST['kivun_course_nonce'] ) || ! wp_verify_nonce( $_POST['kivun_course_nonce'], 'kivun_save_course' ) ) return;
			if ( ! current_user_can( 'edit_post', $post_id ) ) return;

			foreach ( [
				'_kivun_target_audience' => 'textarea',
				'_kivun_schedule'        => 'text',
				'_kivun_duration'        => 'text',
				'_kivun_price'           => 'absint',
				'_kivun_wc_product_id'   => 'absint',
				'_kivun_benefits'        => 'textarea',
				'_kivun_capacity'        => 'absint',
				'_kivun_contact_email'   => 'email',
			] as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( $post->post_type === 'kivun_workshop' ) {
			if ( ! isset( $_POST['kivun_workshop_nonce'] ) || ! wp_verify_nonce( $_POST['kivun_workshop_nonce'], 'kivun_save_workshop' ) ) return;
			if ( ! current_user_can( 'edit_post', $post_id ) ) return;

			foreach ( [
				'_kivun_ws_date'      => 'text',
				'_kivun_ws_duration'  => 'text',
				'_kivun_ws_location'  => 'text',
				'_kivun_ws_audience'  => 'textarea',
				'_kivun_ws_capacity'  => 'absint',
				'_kivun_contact_email' => 'email',
			] as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}

		if ( $post->post_type === 'kivun_job' ) {
			if ( ! isset( $_POST['kivun_job_nonce'] ) || ! wp_verify_nonce( $_POST['kivun_job_nonce'], 'kivun_save_job' ) ) return;
			if ( ! current_user_can( 'edit_post', $post_id ) ) return;

			foreach ( [
				'_kivun_employer_email' => 'email',
				'_kivun_company'        => 'text',
				'_kivun_salary'         => 'text',
				'_kivun_requirements'   => 'textarea',
				'_kivun_deadline'       => 'text',
			] as $key => $type ) {
				self::save_field( $post_id, $key, $type );
			}
		}
	}

	private static function save_field( int $post_id, string $key, string $type ): void {
		$raw = $_POST[ $key ] ?? '';
		switch ( $type ) {
			case 'textarea': $value = sanitize_textarea_field( $raw ); break;
			case 'absint':   $value = absint( $raw ); break;
			case 'email':    $value = sanitize_email( $raw ); break;
			default:         $value = sanitize_text_field( $raw ); break;
		}
		update_post_meta( $post_id, $key, $value );
	}

	// ── Admin columns ──────────────────────────────────────────────────────────

	public static function job_columns( array $cols ): array {
		return array_merge( $cols, [
			'company'  => 'חברה',
			'deadline' => 'תאריך אחרון',
		] );
	}

	public static function job_column_values( string $col, int $id ): void {
		if ( $col === 'company' )  echo esc_html( get_post_meta( $id, '_kivun_company',  true ) );
		if ( $col === 'deadline' ) echo esc_html( get_post_meta( $id, '_kivun_deadline', true ) );
	}

	public static function course_columns( array $cols ): array {
		return array_merge( $cols, [
			'price'    => 'עלות',
			'schedule' => 'זמנים',
		] );
	}

	public static function course_column_values( string $col, int $id ): void {
		if ( $col === 'price' ) {
			$price = (int) get_post_meta( $id, '_kivun_price', true );
			echo $price > 0 ? esc_html( '₪' . number_format( $price ) ) : esc_html__( 'חינמי', 'kivun' );
		}
		if ( $col === 'schedule' ) echo esc_html( get_post_meta( $id, '_kivun_schedule', true ) );
	}

	// ── WC product search (AJAX) ───────────────────────────────────────────────

	public static function ajax_search_products(): void {
		check_ajax_referer( 'kivun_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();
		if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error();

		// Single product lookup by ID (for pre-select)
		if ( ! empty( $_POST['id'] ) ) {
			$product = wc_get_product( absint( $_POST['id'] ) );
			if ( $product ) {
				wp_send_json_success( [ [
					'id'   => $product->get_id(),
					'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
				] ] );
			}
			wp_send_json_success( [] );
		}

		// Search by name
		$term     = sanitize_text_field( $_POST['q'] ?? '' );
		$products = wc_get_products( [
			'status'     => 'publish',
			'limit'      => 20,
			'orderby'    => 'title',
			'order'      => 'ASC',
			's'          => $term,
		] );

		$results = array_map( fn( $p ) => [
			'id'   => $p->get_id(),
			'text' => $p->get_name() . ' (#' . $p->get_id() . ')',
		], $products );

		wp_send_json_success( $results );
	}

	// ── Admin pages ────────────────────────────────────────────────────────────

	public static function admin_menu(): void {
		add_submenu_page( 'edit.php?post_type=kivun_job',    'מועמדויות', 'מועמדויות', 'manage_options', 'kivun-applications',  [ __CLASS__, 'applications_page' ] );
		add_submenu_page( 'edit.php?post_type=kivun_course', 'הרשמות',    'הרשמות',    'manage_options', 'kivun-registrations', [ __CLASS__, 'registrations_page' ] );
	}

	public static function applications_page(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT a.*, p.post_title AS job_title FROM {$wpdb->prefix}kivun_applications a LEFT JOIN {$wpdb->posts} p ON p.ID = a.job_id ORDER BY a.created_at DESC LIMIT 200" );
		echo '<div class="wrap"><h1>מועמדויות</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>שם</th><th>משרה</th><th>אימייל</th><th>טלפון</th><th>תאריך</th><th>סטטוס</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->applicant_name ), esc_html( $r->job_title ), esc_html( $r->applicant_email ),
				esc_html( $r->applicant_phone ), esc_html( $r->created_at ), esc_html( $r->status )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function registrations_page(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT r.*, p.post_title AS course_title FROM {$wpdb->prefix}kivun_registrations r LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id ORDER BY r.created_at DESC LIMIT 500" );

		$type_labels = [
			'registration' => 'הרשמה',
			'lead'         => 'מתעניין',
			'workshop'     => 'סדנה',
		];

		echo '<div class="wrap"><h1>הרשמות, לידים וסדנאות</h1>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>שם</th><th>קורס / סדנה</th><th>סוג</th><th>אימייל</th><th>טלפון</th><th>תאריך</th><th>סטטוס</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$type_label = $type_labels[ $r->type ?? 'registration' ] ?? $r->type;
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->name ), esc_html( $r->course_title ), esc_html( $type_label ),
				esc_html( $r->email ), esc_html( $r->phone ),
				esc_html( $r->created_at ), esc_html( $r->status )
			);
		}
		echo '</tbody></table></div>';
	}
}
