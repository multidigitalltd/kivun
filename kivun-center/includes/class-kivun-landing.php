<?php
/**
 * Landing pages — a fixed-structure, registration-only variant of the
 * kivun_workshop post type, with a modern dedicated admin creation form and a
 * built-in single-page layout.
 *
 * The post type stays `kivun_workshop` (so existing leads and the no-payment
 * registration pipeline keep working); it is relabelled to "דפי נחיתה".
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the landing-page admin form, saving, and single rendering.
 */
class Kivun_Landing {

	/**
	 * Post type backing the landing pages.
	 */
	const POST_TYPE = 'kivun_workshop';

	/**
	 * Admin page slug for the dedicated create/edit form.
	 */
	const PAGE = 'kivun-landing-form';

	/**
	 * Hook everything into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_kivun_save_landing', array( __CLASS__, 'handle_save' ) );

		// Steer staff to the modern form instead of the default WP editor.
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_native_new' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_native_edit' ) );

		// Render the fixed landing structure on the single page.
		add_filter( 'the_content', array( __CLASS__, 'render_single_content' ) );
	}

	/**
	 * Field definitions: meta key => sanitizer. Title and long description are
	 * stored natively (post_title / post_content); the featured image is the
	 * post thumbnail.
	 *
	 * @return array<string,string>
	 */
	private static function meta_fields(): array {
		return array(
			'_kivun_lp_short'      => 'wp_kses_post',
			'_kivun_ws_audience'   => 'sanitize_textarea_field',
			'_kivun_ws_duration'   => 'sanitize_text_field',
			'_kivun_lp_cost'       => 'sanitize_text_field',
			'_kivun_ws_date'       => 'sanitize_text_field',
			'_kivun_contact_email' => 'sanitize_email',
			'_kivun_lp_cta_text'   => 'sanitize_text_field',
			'_kivun_lp_cta_btn'    => 'sanitize_text_field',
		);
	}

	/**
	 * Register the dedicated "new landing page" admin submenu.
	 *
	 * @return void
	 */
	public static function admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'דף נחיתה חדש', 'kivun' ),
			'➕ ' . __( 'דף נחיתה חדש', 'kivun' ),
			'edit_posts',
			self::PAGE,
			array( __CLASS__, 'form_page' )
		);
	}

	/**
	 * Redirect the native "Add New" screen to the modern form.
	 *
	 * @return void
	 */
	public static function redirect_native_new(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the screen's post_type only.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}
		wp_safe_redirect( self::form_url() );
		exit;
	}

	/**
	 * Redirect the native edit screen to the modern form.
	 *
	 * @return void
	 */
	public static function redirect_native_edit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the edited post id only.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the screen action only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'edit' !== $action || ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		wp_safe_redirect( self::form_url( $post_id ) );
		exit;
	}

	/**
	 * Build the admin URL for the create/edit form.
	 *
	 * @param int $landing_id Optional landing page ID to edit.
	 * @return string
	 */
	private static function form_url( int $landing_id = 0 ): string {
		$args = array(
			'post_type' => self::POST_TYPE,
			'page'      => self::PAGE,
		);
		if ( $landing_id ) {
			$args['landing_id'] = $landing_id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Render the modern create/edit form.
	 *
	 * @return void
	 */
	public static function form_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לערוך דפי נחיתה.', 'kivun' ) );
		}

		wp_enqueue_media();
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; loading an existing record to edit.
		$landing_id = isset( $_GET['landing_id'] ) ? absint( wp_unslash( $_GET['landing_id'] ) ) : 0;
		$post       = $landing_id ? get_post( $landing_id ) : null;
		if ( $post && self::POST_TYPE !== $post->post_type ) {
			$post       = null;
			$landing_id = 0;
		}

		$title     = $post ? $post->post_title : '';
		$long      = $post ? $post->post_content : '';
		$status    = $post ? $post->post_status : 'publish';
		$slug      = $post ? $post->post_name : '';
		$slug_base = trailingslashit( home_url( '/landing/' ) );
		$short     = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_lp_short', true ) : '';
		$audience  = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_ws_audience', true ) : '';
		$duration  = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_ws_duration', true ) : '';
		$cost      = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_lp_cost', true ) : '';
		$date      = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_ws_date', true ) : '';
		$email     = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_contact_email', true ) : '';
		$cta_text  = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_lp_cta_text', true ) : '';
		$cta_btn   = $landing_id ? (string) get_post_meta( $landing_id, '_kivun_lp_cta_btn', true ) : '';
		$thumb_id  = $landing_id ? (int) get_post_thumbnail_id( $landing_id ) : 0;
		$thumb_url = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag from a safe redirect.
		$msg = isset( $_GET['kivun_msg'] ) ? sanitize_key( wp_unslash( $_GET['kivun_msg'] ) ) : '';
		?>
		<div class="wrap kivun-lp-admin">
			<h1><?php echo $landing_id ? esc_html__( 'עריכת דף נחיתה', 'kivun' ) : esc_html__( 'דף נחיתה חדש', 'kivun' ); ?></h1>

			<?php if ( 'saved' === $msg && $post ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php esc_html_e( 'דף הנחיתה נשמר בהצלחה.', 'kivun' ); ?>
						<a href="<?php echo esc_url( (string) get_permalink( $landing_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'צפייה בדף ↗', 'kivun' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form class="kivun-lp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_save_landing">
				<input type="hidden" name="landing_id" value="<?php echo esc_attr( $landing_id ); ?>">
				<?php wp_nonce_field( 'kivun_save_landing', 'kivun_landing_nonce' ); ?>

				<div class="kivun-lp-grid">
					<div class="kivun-lp-main">

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-lp-title"><?php esc_html_e( 'כותרת דף הנחיתה', 'kivun' ); ?> <span class="kivun-lp-req">*</span></label>
							<input type="text" id="kivun-lp-title" name="title" class="kivun-lp-input kivun-lp-input--lg" value="<?php echo esc_attr( $title ); ?>" required placeholder="<?php esc_attr_e( 'לדוגמה: סדנת חיפוש עבודה אפקטיבי', 'kivun' ); ?>">

							<label class="kivun-lp-sub" for="kivun-lp-slug"><?php esc_html_e( 'כתובת הדף (Slug)', 'kivun' ); ?></label>
							<div class="kivun-lp-slug">
								<span class="kivun-lp-slug__base" dir="ltr"><?php echo esc_html( $slug_base ); ?></span>
								<input type="text" id="kivun-lp-slug" name="slug" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $slug ); ?>" placeholder="my-landing-page">
							</div>
							<p class="kivun-lp-hint">
								<?php esc_html_e( 'החלק האחרון של הכתובת. השאר ריק כדי לייצר אוטומטית מהכותרת.', 'kivun' ); ?>
								<?php if ( $landing_id ) : ?>
									<a href="<?php echo esc_url( (string) get_permalink( $landing_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'צפייה בכתובת הנוכחית ↗', 'kivun' ); ?></a>
								<?php endif; ?>
							</p>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></label>
							<p class="kivun-lp-hint"><?php esc_html_e( 'משפט–שניים שמופיעים מתחת לכותרת בראש הדף.', 'kivun' ); ?></p>
							<?php
							wp_editor(
								$short,
								'kivun_lp_short',
								array(
									'textarea_name' => 'short',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
									'textarea_rows' => 4,
								)
							);
							?>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תיאור מלא', 'kivun' ); ?></label>
							<p class="kivun-lp-hint"><?php esc_html_e( 'כל הפרטים על דף הנחיתה — מה כולל, למי מתאים, מה מקבלים.', 'kivun' ); ?></p>
							<?php
							wp_editor(
								$long,
								'kivun_lp_long',
								array(
									'textarea_name' => 'long',
									'media_buttons' => true,
									'teeny'         => false,
									'textarea_rows' => 10,
								)
							);
							?>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'הנעה לפעולה (CTA)', 'kivun' ); ?></label>
							<p class="kivun-lp-hint"><?php esc_html_e( 'ערכים לשאיבה בבאנר ה‑CTA שבנית ב‑Elementor (דרך תגיות דינמיות).', 'kivun' ); ?></p>

							<label class="kivun-lp-sub" for="kivun-lp-cta-text"><?php esc_html_e( 'טקסט הנעה לפעולה', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-cta-text" name="cta_text" class="kivun-lp-input" value="<?php echo esc_attr( $cta_text ); ?>" placeholder="<?php esc_attr_e( 'למשל: הצטרפו עוד היום ותתחילו לזוז קדימה', 'kivun' ); ?>">

							<label class="kivun-lp-sub" for="kivun-lp-cta-btn"><?php esc_html_e( 'טקסט הכפתור', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-cta-btn" name="cta_btn" class="kivun-lp-input" value="<?php echo esc_attr( $cta_btn ); ?>" placeholder="<?php esc_attr_e( 'ריק = "להרשמה ל<שם הדף>"', 'kivun' ); ?>">
						</div>

					</div>

					<div class="kivun-lp-side">

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'פרסום', 'kivun' ); ?></label>
							<select name="status" class="kivun-lp-input">
								<option value="publish" <?php selected( $status, 'publish' ); ?>><?php esc_html_e( 'מפורסם', 'kivun' ); ?></option>
								<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'טיוטה', 'kivun' ); ?></option>
							</select>
							<div class="kivun-lp-actions">
								<button type="submit" class="button button-primary button-hero"><?php echo $landing_id ? esc_html__( 'שמירת שינויים', 'kivun' ) : esc_html__( 'יצירת דף נחיתה', 'kivun' ); ?></button>
								<a class="button button-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'חזרה לרשימה', 'kivun' ); ?></a>
							</div>
							<?php if ( $landing_id ) : ?>
								<p class="kivun-lp-hint" style="margin-top:10px">
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'post_type' => 'kivun_course',
												'page' => 'kivun-registrations',
												'kivun_course_id' => $landing_id,
											),
											admin_url( 'edit.php' )
										)
									);
									?>
												">
										<?php esc_html_e( 'צפייה בלידים של דף זה ↗', 'kivun' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תמונה ראשית', 'kivun' ); ?></label>
							<div class="kivun-lp-media">
								<div class="kivun-lp-media__preview" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>>
									<img src="<?php echo esc_url( $thumb_url ); ?>" alt="">
								</div>
								<input type="hidden" name="thumbnail_id" id="kivun-lp-thumb-id" value="<?php echo esc_attr( $thumb_id ); ?>">
								<button type="button" class="button kivun-lp-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
								<button type="button" class="button-link kivun-lp-media__remove" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
								<p class="kivun-lp-hint"><?php esc_html_e( 'אפשר גם להעלות קובץ מהמחשב:', 'kivun' ); ?></p>
								<input type="file" name="thumbnail_file" accept="image/*">
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'פרטי הדף', 'kivun' ); ?></label>

							<label class="kivun-lp-sub" for="kivun-lp-audience"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-audience" name="audience" class="kivun-lp-input" value="<?php echo esc_attr( $audience ); ?>" placeholder="<?php esc_attr_e( 'למשל: מחפשי עבודה, הורים צעירים', 'kivun' ); ?>">

							<label class="kivun-lp-sub" for="kivun-lp-duration"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-duration" name="duration" class="kivun-lp-input" value="<?php echo esc_attr( $duration ); ?>" placeholder="<?php esc_attr_e( 'למשל: 3 שעות / 4 מפגשים', 'kivun' ); ?>">

							<label class="kivun-lp-sub" for="kivun-lp-cost"><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-cost" name="cost" class="kivun-lp-input" value="<?php echo esc_attr( $cost ); ?>" placeholder="<?php esc_attr_e( 'למשל: חינם / 120 ₪', 'kivun' ); ?>">

							<label class="kivun-lp-sub" for="kivun-lp-date"><?php esc_html_e( 'תאריך פתיחה', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-date" name="date" class="kivun-lp-input" value="<?php echo esc_attr( $date ); ?>" placeholder="<?php esc_attr_e( 'למשל: 15.9.2025 בשעה 18:00', 'kivun' ); ?>">
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-lp-email"><?php esc_html_e( 'אימייל לקבלת הלידים', 'kivun' ); ?></label>
							<p class="kivun-lp-hint"><?php esc_html_e( 'כל הרשמה מהדף הזה תישלח לכתובת הזו. אם ריק — לפי הגדרות המערכת.', 'kivun' ); ?></p>
							<input type="email" id="kivun-lp-email" name="contact_email" class="kivun-lp-input" value="<?php echo esc_attr( $email ); ?>" placeholder="leads@example.com">
						</div>

					</div>
				</div>
			</form>
		</div>

		<script>
		( function () {
			var frame,
				selectBtn = document.querySelector( '.kivun-lp-media__select' ),
				removeBtn = document.querySelector( '.kivun-lp-media__remove' ),
				idInput   = document.getElementById( 'kivun-lp-thumb-id' ),
				preview   = document.querySelector( '.kivun-lp-media__preview' ),
				img       = preview ? preview.querySelector( 'img' ) : null;

			if ( selectBtn ) {
				selectBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( frame ) { frame.open(); return; }
					frame = wp.media( { title: '<?php echo esc_js( __( 'בחירת תמונה ראשית', 'kivun' ) ); ?>', button: { text: '<?php echo esc_js( __( 'בחירה', 'kivun' ) ); ?>' }, multiple: false } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						idInput.value = att.id;
						if ( img ) { img.src = ( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url ); }
						if ( preview ) { preview.style.display = ''; }
						if ( removeBtn ) { removeBtn.style.display = ''; }
					} );
					frame.open();
				} );
			}
			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					idInput.value = '';
					if ( preview ) { preview.style.display = 'none'; }
					removeBtn.style.display = 'none';
				} );
			}
		} () );
		</script>
		<?php
	}

	/**
	 * Persist the landing page from the dedicated form.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST['kivun_landing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_landing_nonce'] ) ), 'kivun_save_landing' ) ) {
			wp_die( esc_html__( 'בקשה לא תקפה.', 'kivun' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לשמור דפי נחיתה.', 'kivun' ) );
		}

		$landing_id = isset( $_POST['landing_id'] ) ? absint( wp_unslash( $_POST['landing_id'] ) ) : 0;
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$long       = isset( $_POST['long'] ) ? wp_kses_post( wp_unslash( $_POST['long'] ) ) : '';
		$status     = ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'publish';
		$slug       = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';

		if ( '' === $title ) {
			wp_die( esc_html__( 'יש להזין כותרת לדף הנחיתה.', 'kivun' ) );
		}

		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $title,
			'post_content' => $long,
			'post_status'  => $status,
		);

		// Only set the slug when the admin provided one; otherwise let WordPress
		// keep the existing slug (edit) or generate one from the title (create).
		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}

		if ( $landing_id && get_post_type( $landing_id ) === self::POST_TYPE ) {
			$postarr['ID'] = $landing_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$landing_id = (int) $result;

		// Meta fields.
		foreach ( self::meta_fields() as $key => $sanitizer ) {
			$field = self::field_for_meta( $key );
			if ( null === $field ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via the per-field callback below.
			$raw   = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			$value = call_user_func( $sanitizer, $raw );
			update_post_meta( $landing_id, $key, $value );
		}

		// Featured image: prefer an uploaded file, else a media-library selection.
		self::handle_thumbnail( $landing_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => self::POST_TYPE,
					'page'       => self::PAGE,
					'landing_id' => $landing_id,
					'kivun_msg'  => 'saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Map a meta key to its form field name.
	 *
	 * @param string $meta_key The meta key.
	 * @return string|null
	 */
	private static function field_for_meta( string $meta_key ): ?string {
		$map = array(
			'_kivun_lp_short'      => 'short',
			'_kivun_ws_audience'   => 'audience',
			'_kivun_ws_duration'   => 'duration',
			'_kivun_lp_cost'       => 'cost',
			'_kivun_ws_date'       => 'date',
			'_kivun_contact_email' => 'contact_email',
			'_kivun_lp_cta_text'   => 'cta_text',
			'_kivun_lp_cta_btn'    => 'cta_btn',
		);
		return $map[ $meta_key ] ?? null;
	}

	/**
	 * Set the featured image from an uploaded file or a media selection.
	 *
	 * @param int $landing_id The landing page ID.
	 * @return void
	 */
	private static function handle_thumbnail( int $landing_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_save() before this runs.
		if ( ! empty( $_FILES['thumbnail_file']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_id = media_handle_upload( 'thumbnail_file', $landing_id );
			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $landing_id, (int) $attachment_id );
				return;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_save() before this runs.
		$thumb_id = isset( $_POST['thumbnail_id'] ) ? absint( wp_unslash( $_POST['thumbnail_id'] ) ) : 0;
		if ( $thumb_id ) {
			set_post_thumbnail( $landing_id, $thumb_id );
		} else {
			delete_post_thumbnail( $landing_id );
		}
	}

	/**
	 * Render the fixed landing structure on the single page.
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public static function render_single_content( string $content ): string {
		if ( is_admin() || ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Off by default: most sites design the landing single in Elementor and
		// don't want the plugin to override the content or inject a form. Opt in
		// from Kivun Center → Settings (or via the filter below).
		$default = (bool) Kivun_Admin_Settings::get( 'landing_builtin_single', false );

		/**
		 * Allow forcing/disabling the built-in landing layout.
		 *
		 * @param bool $render Whether to render the built-in layout.
		 */
		if ( ! apply_filters( 'kivun_render_landing_single', $default ) ) {
			return $content;
		}

		ob_start();
		kivun_get_template(
			'landing/single.php',
			array(
				'landing_id' => get_the_ID(),
				'long_html'  => $content,
			)
		);
		return (string) ob_get_clean();
	}
}
