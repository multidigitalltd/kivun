<?php
/**
 * Unified content creator: one admin form that publishes a landing page,
 * a course and/or a workshop from a single shared set of content.
 *
 * The admin fills shared content once (title, descriptions, image, audience),
 * ticks which of the three to publish, fills each type's specific fields, and
 * submits — creating up to three posts at once.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the unified "create content" admin form and its save routine.
 */
class Kivun_Content_Creator {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'kivun-create-content';

	/**
	 * Hook menu + save handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_kivun_create_content', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Register the top-level "create content" menu.
	 *
	 * @return void
	 */
	public static function admin_menu(): void {
		add_menu_page(
			__( 'פרסום תוכן', 'kivun' ),
			__( 'פרסום תוכן', 'kivun' ),
			'edit_posts',
			self::PAGE,
			array( __CLASS__, 'form_page' ),
			'dashicons-plus-alt',
			3
		);
	}

	/**
	 * Render the unified creation form.
	 *
	 * @return void
	 */
	public static function form_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}

		wp_enqueue_media();
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag from a safe redirect.
		$created = isset( $_GET['kivun_created'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['kivun_created'] ) ) : array();
		?>
		<div class="wrap kivun-lp-admin">
			<h1><?php esc_html_e( 'פרסום תוכן חדש', 'kivun' ); ?></h1>
			<p class="description"><?php esc_html_e( 'מלאו תוכן פעם אחת, סמנו מה לפרסם (דף נחיתה / קורס / סדנה), ולחצו פרסום — הכול נוצר יחד.', 'kivun' ); ?></p>

			<?php if ( $created ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'התוכן פורסם בהצלחה:', 'kivun' ); ?>
					<?php
					$links = array();
					foreach ( $created as $cid ) {
						if ( $cid && get_post( $cid ) ) {
							$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $cid ) ) . '">' . esc_html( get_the_title( $cid ) ) . ' (' . esc_html( get_post_type( $cid ) ) . ')</a>';
						}
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Links assembled from escaped parts above.
					echo implode( ' · ', $links );
					?>
				</p></div>
			<?php endif; ?>

			<form class="kivun-lp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_create_content">
				<?php wp_nonce_field( 'kivun_create_content', 'kivun_cc_nonce' ); ?>

				<div class="kivun-lp-grid">
					<div class="kivun-lp-main">

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-cc-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-lp-req">*</span></label>
							<input type="text" id="kivun-cc-title" name="title" class="kivun-lp-input kivun-lp-input--lg" required>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></label>
							<?php
							wp_editor(
								'',
								'kivun_cc_short',
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
							<?php
							wp_editor(
								'',
								'kivun_cc_long',
								array(
									'textarea_name' => 'long',
									'media_buttons' => true,
									'textarea_rows' => 10,
								)
							);
							?>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
							<input type="text" name="audience" class="kivun-lp-input" placeholder="<?php esc_attr_e( 'למשל: מחפשי עבודה', 'kivun' ); ?>">
						</div>

						<!-- ── Landing-specific ── -->
						<div class="kivun-lp-card kivun-cc-section" data-type="landing" hidden>
							<h3><?php esc_html_e( 'פרטי דף נחיתה', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — כותרת', 'kivun' ); ?></label>
							<input type="text" name="landing_cta_title" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — תוכן', 'kivun' ); ?></label>
							<textarea name="landing_cta_content" class="kivun-lp-input" rows="2"></textarea>
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — טקסט כפתור', 'kivun' ); ?></label>
							<input type="text" name="landing_cta_button" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת לידים', 'kivun' ); ?></label>
							<input type="email" name="landing_email" class="kivun-lp-input">
						</div>

						<!-- ── Course-specific ── -->
						<div class="kivun-lp-card kivun-cc-section" data-type="course" hidden>
							<h3><?php esc_html_e( 'פרטי קורס', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'עלות (₪, 0 = חינם)', 'kivun' ); ?></label>
							<input type="number" name="course_price" class="kivun-lp-input" min="0" value="0">
							<label class="kivun-lp-sub"><?php esc_html_e( 'זמנים / מועדים', 'kivun' ); ?></label>
							<input type="text" name="course_schedule" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" name="course_duration" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="course_capacity" class="kivun-lp-input" min="0">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></label>
							<input type="email" name="course_email" class="kivun-lp-input">
						</div>

						<!-- ── Workshop-specific ── -->
						<div class="kivun-lp-card kivun-cc-section" data-type="session" hidden>
							<h3><?php esc_html_e( 'פרטי סדנה', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
							<input type="text" name="session_date" class="kivun-lp-input" placeholder="<?php esc_attr_e( 'למשל: 15.9.2025 בשעה 18:00', 'kivun' ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" name="session_duration" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="session_location" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="session_capacity" class="kivun-lp-input" min="0">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></label>
							<input type="email" name="session_email" class="kivun-lp-input">
							<label class="kivun-lp-sub"><?php esc_html_e( 'תוקף ההרשמה (עד תאריך)', 'kivun' ); ?></label>
							<input type="date" name="session_valid_until" class="kivun-lp-input" dir="ltr">
							<p class="kivun-lp-hint"><?php esc_html_e( 'אחרי תאריך זה ההרשמה נסגרת. לפתיחת מחזור חדש — עדכנו את התאריך בסדנה.', 'kivun' ); ?></p>
						</div>

					</div>

					<div class="kivun-lp-side">

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'מה לפרסם?', 'kivun' ); ?></label>
							<label class="kivun-cc-check"><input type="checkbox" name="publish[]" value="landing" class="kivun-cc-toggle" data-type="landing"> <?php esc_html_e( 'דף נחיתה', 'kivun' ); ?></label>
							<label class="kivun-cc-check"><input type="checkbox" name="publish[]" value="course" class="kivun-cc-toggle" data-type="course"> <?php esc_html_e( 'קורס', 'kivun' ); ?></label>
							<label class="kivun-cc-check"><input type="checkbox" name="publish[]" value="session" class="kivun-cc-toggle" data-type="session"> <?php esc_html_e( 'סדנה', 'kivun' ); ?></label>
							<div class="kivun-lp-actions">
								<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'פרסום', 'kivun' ); ?></button>
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תמונה ראשית', 'kivun' ); ?></label>
							<div class="kivun-lp-media">
								<div class="kivun-lp-media__preview" style="display:none"><img src="" alt=""></div>
								<input type="hidden" name="thumbnail_id" id="kivun-lp-thumb-id" value="0">
								<button type="button" class="button kivun-lp-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
								<button type="button" class="button-link kivun-lp-media__remove" style="display:none"><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
								<p class="kivun-lp-hint"><?php esc_html_e( 'או העלאת קובץ:', 'kivun' ); ?></p>
								<input type="file" name="thumbnail_file" accept="image/*">
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></label>
							<select name="status" class="kivun-lp-input">
								<option value="publish"><?php esc_html_e( 'מפורסם', 'kivun' ); ?></option>
								<option value="draft"><?php esc_html_e( 'טיוטה', 'kivun' ); ?></option>
							</select>
						</div>

					</div>
				</div>
			</form>
		</div>

		<script>
		( function () {
			// Toggle type-specific sections by their checkbox.
			document.querySelectorAll( '.kivun-cc-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var sec = document.querySelector( '.kivun-cc-section[data-type="' + cb.dataset.type + '"]' );
					if ( sec ) { sec.hidden = ! cb.checked; }
				} );
			} );

			// Media picker.
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
					frame = wp.media( { multiple: false } );
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
	 * Create the selected posts from the submitted shared content.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST['kivun_cc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_cc_nonce'] ) ), 'kivun_create_content' ) ) {
			wp_die( esc_html__( 'בקשה לא תקפה.', 'kivun' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_die( esc_html__( 'יש להזין כותרת.', 'kivun' ) );
		}

		$long     = isset( $_POST['long'] ) ? wp_kses_post( wp_unslash( $_POST['long'] ) ) : '';
		$short    = isset( $_POST['short'] ) ? wp_kses_post( wp_unslash( $_POST['short'] ) ) : '';
		$audience = isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( $_POST['audience'] ) ) : '';
		$status   = ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'publish';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value below.
		$publish = isset( $_POST['publish'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['publish'] ) ) : array();

		$thumb_id = self::resolve_thumbnail();
		$created  = array();

		if ( in_array( 'landing', $publish, true ) ) {
			$created[] = self::create_post(
				'kivun_workshop',
				$title,
				$long,
				$status,
				$thumb_id,
				array(
					'_kivun_lp_short'      => $short,
					'_kivun_ws_audience'   => $audience,
					'_kivun_contact_email' => sanitize_email( wp_unslash( $_POST['landing_email'] ?? '' ) ),
					'_kivun_cta_title'     => sanitize_text_field( wp_unslash( $_POST['landing_cta_title'] ?? '' ) ),
					'_kivun_cta_content'   => sanitize_textarea_field( wp_unslash( $_POST['landing_cta_content'] ?? '' ) ),
					'_kivun_cta_button'    => sanitize_text_field( wp_unslash( $_POST['landing_cta_button'] ?? '' ) ),
				)
			);
		}

		if ( in_array( 'course', $publish, true ) ) {
			$created[] = self::create_post(
				'kivun_course',
				$title,
				$long,
				$status,
				$thumb_id,
				array(
					'_kivun_target_audience' => $audience,
					'_kivun_schedule'        => sanitize_text_field( wp_unslash( $_POST['course_schedule'] ?? '' ) ),
					'_kivun_duration'        => sanitize_text_field( wp_unslash( $_POST['course_duration'] ?? '' ) ),
					'_kivun_price'           => absint( $_POST['course_price'] ?? 0 ),
					'_kivun_capacity'        => absint( $_POST['course_capacity'] ?? 0 ),
					'_kivun_contact_email'   => sanitize_email( wp_unslash( $_POST['course_email'] ?? '' ) ),
				),
				$short
			);
		}

		if ( in_array( 'session', $publish, true ) ) {
			$created[] = self::create_post(
				'kivun_session',
				$title,
				$long,
				$status,
				$thumb_id,
				array(
					'_kivun_session_short'       => $short,
					'_kivun_session_audience'    => $audience,
					'_kivun_session_date'        => sanitize_text_field( wp_unslash( $_POST['session_date'] ?? '' ) ),
					'_kivun_session_duration'    => sanitize_text_field( wp_unslash( $_POST['session_duration'] ?? '' ) ),
					'_kivun_session_location'    => sanitize_text_field( wp_unslash( $_POST['session_location'] ?? '' ) ),
					'_kivun_capacity'            => absint( $_POST['session_capacity'] ?? 0 ),
					'_kivun_contact_email'       => sanitize_email( wp_unslash( $_POST['session_email'] ?? '' ) ),
					'_kivun_session_valid_until' => sanitize_text_field( wp_unslash( $_POST['session_valid_until'] ?? '' ) ),
				)
			);
		}

		$created = array_filter( $created );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'kivun_created' => $created,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Create a single post with shared content, meta and thumbnail.
	 *
	 * @param string $post_type The target post type.
	 * @param string $title     Post title.
	 * @param string $content   Post content (long description).
	 * @param string $status    Post status.
	 * @param int    $thumb_id  Featured image attachment ID (0 for none).
	 * @param array  $meta      Meta key => value pairs to store.
	 * @param string $excerpt   Optional excerpt (short description) for the post.
	 * @return int The created post ID, or 0 on failure.
	 */
	private static function create_post( string $post_type, string $title, string $content, string $status, int $thumb_id, array $meta, string $excerpt = '' ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $status,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		$post_id = (int) $post_id;

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $thumb_id ) {
			set_post_thumbnail( $post_id, $thumb_id );
		}

		return $post_id;
	}

	/**
	 * Resolve the shared featured image: an uploaded file or a media selection.
	 *
	 * @return int Attachment ID, or 0.
	 */
	private static function resolve_thumbnail(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_save().
		if ( ! empty( $_FILES['thumbnail_file']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_id = media_handle_upload( 'thumbnail_file', 0 );
			if ( ! is_wp_error( $attachment_id ) ) {
				return (int) $attachment_id;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_save().
		return isset( $_POST['thumbnail_id'] ) ? absint( wp_unslash( $_POST['thumbnail_id'] ) ) : 0;
	}
}
