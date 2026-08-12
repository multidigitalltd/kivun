<?php
/**
 * Unified content creator & editor: one admin form that publishes and edits a
 * landing page, a course and/or a workshop from a single shared set of content.
 *
 * The shared content mirrors the full landing-page field set (title, URL slug,
 * short/long descriptions, image, audience, duration, cost, date, lead email,
 * CTA) and is mapped into each post type's own meta. Posts created together are
 * linked by a shared `_kivun_content_group` meta, so editing updates them all.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the unified "create / edit content" admin form and its save routine.
 */
class Kivun_Content_Creator {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'kivun-create-content';

	/**
	 * Meta key linking posts created together.
	 */
	const GROUP_META = '_kivun_content_group';

	/**
	 * Hook menu + save handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_kivun_create_content', array( __CLASS__, 'handle_save' ) );

		// Front-end: a shortcode that lets authorised users publish content from a
		// public page, without entering wp-admin.
		add_shortcode( 'kivun_content_creator', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_kivun_create_content_front', array( __CLASS__, 'handle_front_save' ) );
		add_action( 'admin_post_kivun_delete_content_front', array( __CLASS__, 'handle_front_delete' ) );
		add_action( 'admin_post_kivun_cc_duplicate_front', array( __CLASS__, 'handle_front_duplicate' ) );
	}

	/**
	 * Map of form type keys to their post types.
	 *
	 * @return array<string,string>
	 */
	private static function type_map(): array {
		return array(
			'landing' => 'kivun_workshop',
			'course'  => 'kivun_course',
			'session' => 'kivun_session',
			'event'   => 'kivun_event',
		);
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
	 * Return the posts belonging to a content group, keyed by type key.
	 *
	 * @param string $group The group id.
	 * @return array<string,int> Type key => post ID.
	 */
	private static function group_posts( string $group ): array {
		if ( '' === trim( $group ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => -1,
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $group, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$flip = array_flip( self::type_map() );
		$map  = array();
		foreach ( $posts as $p ) {
			if ( isset( $flip[ $p->post_type ] ) ) {
				$map[ $flip[ $p->post_type ] ] = (int) $p->ID;
			}
		}
		return $map;
	}

	/**
	 * Build the form field values, prefilled from an existing group when editing.
	 *
	 * @param array $group_posts Type key => post ID (empty for a new group).
	 * @return array<string,string>
	 */
	private static function form_values( array $group_posts ): array {
		$v = array(
			'title'            => '',
			'slug'             => '',
			'short'            => '',
			'long'             => '',
			'audience'         => '',
			'duration'         => '',
			'cost'             => '',
			'date'             => '',
			'email'            => '',
			'cta_title'        => '',
			'cta_content'      => '',
			'cta_button'       => '',
			'status'           => 'publish',
			'thumb_id'         => '0',
			'course_capacity'  => '',
			'course_wc'        => '',
			'session_location' => '',
			'session_capacity' => '',
			'session_valid'    => '',
			'event_date'       => '',
			'event_time'       => '',
			'event_location'   => '',
			'event_capacity'   => '',
			'event_mode'       => 'form',
			'event_url'        => '',
			'event_button'     => '',
			'event_popup'      => '',
			'event_image_id'   => '0',
		);

		if ( ! $group_posts ) {
			return $v;
		}

		$primary_key = array_key_first( $group_posts );
		$primary     = (int) $group_posts[ $primary_key ];

		$v['title']    = get_the_title( $primary );
		$v['slug']     = get_post_field( 'post_name', $primary );
		$v['long']     = (string) get_post_field( 'post_content', $primary );
		$v['status']   = (string) get_post_status( $primary );
		$v['thumb_id'] = (string) (int) get_post_thumbnail_id( $primary );

		// Shared fields — read from whichever primary type exists, per its keys.
		$keys = self::shared_meta_keys( $primary_key );
		foreach ( $keys as $field => $meta_key ) {
			if ( 'excerpt' === $meta_key ) {
				$v[ $field ] = (string) get_post_field( 'post_excerpt', $primary );
			} else {
				$v[ $field ] = (string) get_post_meta( $primary, $meta_key, true );
			}
		}
		// CTA is stored on the same keys for every type.
		$v['cta_title']   = (string) get_post_meta( $primary, '_kivun_cta_title', true );
		$v['cta_content'] = (string) get_post_meta( $primary, '_kivun_cta_content', true );
		$v['cta_button']  = (string) get_post_meta( $primary, '_kivun_cta_button', true );

		if ( isset( $group_posts['course'] ) ) {
			$v['course_capacity'] = (string) get_post_meta( $group_posts['course'], '_kivun_capacity', true );
			$v['course_wc']       = (string) get_post_meta( $group_posts['course'], '_kivun_wc_product_id', true );
		}
		if ( isset( $group_posts['session'] ) ) {
			$v['session_location'] = (string) get_post_meta( $group_posts['session'], '_kivun_session_location', true );
			$v['session_capacity'] = (string) get_post_meta( $group_posts['session'], '_kivun_capacity', true );
			$v['session_valid']    = (string) get_post_meta( $group_posts['session'], '_kivun_session_valid_until', true );
		}
		if ( isset( $group_posts['event'] ) ) {
			$eid                 = (int) $group_posts['event'];
			$v['event_date']     = (string) get_post_meta( $eid, '_kivun_event_date', true );
			$v['event_time']     = (string) get_post_meta( $eid, '_kivun_event_time', true );
			$v['event_location'] = (string) get_post_meta( $eid, '_kivun_event_location', true );
			$v['event_capacity'] = (string) get_post_meta( $eid, '_kivun_capacity', true );
			$v['event_mode']     = kivun_event_mode( $eid );
			$v['event_url']      = (string) get_post_meta( $eid, '_kivun_event_external_url', true );
			$v['event_button']   = (string) get_post_meta( $eid, '_kivun_event_button', true );
			$v['event_popup']    = (string) get_post_meta( $eid, '_kivun_event_popup', true );
			$v['event_image_id'] = (string) (int) get_post_meta( $eid, '_kivun_event_image', true );
		}

		return $v;
	}

	/**
	 * Shared field => meta key mapping for a given type.
	 *
	 * @param string $type_key The type key (landing/course/session).
	 * @return array<string,string> Shared field => meta key ('excerpt' = post_excerpt).
	 */
	private static function shared_meta_keys( string $type_key ): array {
		if ( 'course' === $type_key ) {
			return array(
				'short'    => 'excerpt',
				'audience' => '_kivun_target_audience',
				'duration' => '_kivun_duration',
				'cost'     => '_kivun_price',
				'date'     => '_kivun_schedule',
				'email'    => '_kivun_contact_email',
			);
		}
		if ( 'session' === $type_key ) {
			return array(
				'short'    => '_kivun_session_short',
				'audience' => '_kivun_session_audience',
				'duration' => '_kivun_session_duration',
				'cost'     => '_kivun_session_cost',
				'date'     => '_kivun_session_date',
				'email'    => '_kivun_contact_email',
			);
		}
		if ( 'event' === $type_key ) {
			// Events share only the neutral fields here. Their unique fields — the
			// full schedule (_kivun_event_time), the strict closing date, mode,
			// popup, image — all live in the dedicated "event" section instead, so
			// the generic "משך"/"תאריך" editors are never used for an event.
			return array(
				'short'    => '_kivun_event_short',
				'audience' => '_kivun_event_audience',
				'cost'     => '_kivun_event_cost',
				'email'    => '_kivun_contact_email',
			);
		}
		// Landing.
		return array(
			'short'    => '_kivun_lp_short',
			'audience' => '_kivun_ws_audience',
			'duration' => '_kivun_ws_duration',
			'cost'     => '_kivun_lp_cost',
			'date'     => '_kivun_ws_date',
			'email'    => '_kivun_contact_email',
		);
	}

	/**
	 * Render the unified create/edit form.
	 *
	 * @return void
	 */
	public static function form_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}

		wp_enqueue_media();
		wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );
		wp_enqueue_script( 'kivun-voice', KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'voice', 'js' ), array(), KIVUN_VERSION, true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; loads a record to edit.
		$group       = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		$group_posts = self::group_posts( $group );
		$editing     = (bool) $group_posts;
		$v           = self::form_values( $group_posts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag from a safe redirect.
		$saved         = ! empty( $_GET['kivun_saved'] );
		$sections      = array(
			'landing' => __( 'דף נחיתה', 'kivun' ),
			'course'  => __( 'קורס', 'kivun' ),
			'session' => __( 'סדנה', 'kivun' ),
			'event'   => __( 'אירוע', 'kivun' ),
		);
		$thumb_url     = (int) $v['thumb_id'] ? (string) wp_get_attachment_image_url( (int) $v['thumb_id'], 'medium' ) : '';
		$ev_img_url    = (int) $v['event_image_id'] ? (string) wp_get_attachment_image_url( (int) $v['event_image_id'], 'medium' ) : '';
		$ai_show       = current_user_can( 'upload_files' );
		$ai_configured = Kivun_AI_Image::is_configured();
		?>
		<div class="wrap kivun-lp-admin">
			<h1><?php echo $editing ? esc_html__( 'עריכת תוכן', 'kivun' ) : esc_html__( 'פרסום תוכן חדש', 'kivun' ); ?></h1>
			<p class="description"><?php esc_html_e( 'מלאו תוכן פעם אחת, סמנו מה לפרסם (דף נחיתה / קורס / סדנה), ולחצו פרסום — הכול נוצר ומתעדכן יחד.', 'kivun' ); ?></p>

			<?php if ( $saved && $editing ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'התוכן נשמר בהצלחה.', 'kivun' ); ?></p></div>
			<?php endif; ?>

			<form class="kivun-lp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_create_content">
				<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
				<?php wp_nonce_field( 'kivun_create_content', 'kivun_cc_nonce' ); ?>

				<div class="kivun-lp-grid">
					<div class="kivun-lp-main">

						<div class="kivun-lp-card kivun-cc-genai">
							<label class="kivun-lp-label"><?php esc_html_e( '✨ יצירת תוכן אוטומטית (AI)', 'kivun' ); ?></label>
							<?php if ( ! $ai_configured ) : ?>
								<p class="kivun-cc-ai-warn">
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: %s: link to the settings page. */
											__( '⚠️ היצירה כבויה — לא הוגדר מפתח API. <a href="%s">להגדרות</a>.', 'kivun' ),
											esc_url( admin_url( 'admin.php?page=kivun-settings' ) )
										)
									);
									?>
								</p>
							<?php endif; ?>
							<p class="kivun-lp-hint"><?php esc_html_e( 'תארו את הנושא וה-AI ימלא כותרת, תיאורים ופרטים — תוכלו לערוך אחר כך.', 'kivun' ); ?></p>
							<label class="kivun-lp-sub"><?php esc_html_e( 'סגנון כתיבה', 'kivun' ); ?></label>
							<select class="kivun-lp-input kivun-cc-gen-tone">
								<?php foreach ( Kivun_AI_Content::tones() as $tkey => $tlabel ) : ?>
									<option value="<?php echo esc_attr( $tkey ); ?>"><?php echo esc_html( $tlabel ); ?></option>
								<?php endforeach; ?>
							</select>
							<label class="kivun-lp-sub"><?php esc_html_e( 'נושא התוכן', 'kivun' ); ?></label>
							<textarea class="kivun-lp-input kivun-cc-gen-topic" rows="2" placeholder="<?php esc_attr_e( 'למשל: סדנת הורים-מתבגרים בת 4 מפגשים בערבים', 'kivun' ); ?>"></textarea>
							<label class="kivun-lp-sub" style="margin-top:.6rem"><?php esc_html_e( 'או העלו מודעה מעוצבת — וה-AI יחלץ ממנה את הפרטים (אופציונלי)', 'kivun' ); ?></label>
							<input type="file" class="kivun-lp-input kivun-cc-gen-image" accept="image/*">
							<p style="margin:.5rem 0 0">
								<button type="button" class="button kivun-cc-gen-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_content' ) ); ?>">
									<?php esc_html_e( '✨ צור תוכן', 'kivun' ); ?>
								</button>
								<span class="kivun-cc-gen-status" style="font-size:12px;color:#555;margin-inline-start:.5rem"></span>
							</p>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-cc-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-lp-req">*</span></label>
							<input type="text" id="kivun-cc-title" name="title" class="kivun-lp-input kivun-lp-input--lg" value="<?php echo esc_attr( $v['title'] ); ?>" required>

							<label class="kivun-lp-sub" for="kivun-cc-slug"><?php esc_html_e( 'כתובת URL (Slug)', 'kivun' ); ?></label>
							<input type="text" id="kivun-cc-slug" name="slug" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="my-content">
							<p class="kivun-lp-hint"><?php esc_html_e( 'משמש לכל שלושת הפוסטים (כל אחד תחת הבסיס שלו). ריק = נוצר מהכותרת.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></label>
							<?php
							wp_editor(
								$v['short'],
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
								$v['long'],
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
							<label class="kivun-lp-label"><?php esc_html_e( 'פרטים', 'kivun' ); ?></label>
							<label class="kivun-lp-sub"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
							<?php
								wp_editor(
									(string) $v['audience'],
									'kivun_cc_audience',
									array(
										'textarea_name' => 'audience',
										'media_buttons' => false,
										'teeny'         => true,
										'quicktags'     => false,
										'textarea_rows' => 3,
									)
								);
							?>
							<div class="kivun-cc-nonevent">
								<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
								<?php
									wp_editor(
										(string) $v['duration'],
										'kivun_cc_duration',
										array(
											'textarea_name' => 'duration',
											'media_buttons' => false,
											'teeny'     => true,
											'quicktags' => false,
											'textarea_rows' => 3,
										)
									);
								?>
							</div>
							<label class="kivun-lp-sub"><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
							<?php
								wp_editor(
									(string) $v['cost'],
									'kivun_cc_cost',
									array(
										'textarea_name' => 'cost',
										'media_buttons' => false,
										'teeny'         => true,
										'quicktags'     => false,
										'textarea_rows' => 3,
									)
								);
							?>
							<div class="kivun-cc-nonevent">
								<label class="kivun-lp-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
								<?php
									wp_editor(
										(string) $v['date'],
										'kivun_cc_date',
										array(
											'textarea_name' => 'date',
											'media_buttons' => false,
											'teeny'     => true,
											'quicktags' => false,
											'textarea_rows' => 3,
										)
									);
								?>
							</div>
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת לידים/הרשמות', 'kivun' ); ?></label>
							<input type="email" name="email" class="kivun-lp-input" value="<?php echo esc_attr( $v['email'] ); ?>">
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'באנר הנעה לפעולה (CTA)', 'kivun' ); ?></label>
							<label class="kivun-lp-sub"><?php esc_html_e( 'כותרת', 'kivun' ); ?></label>
							<input type="text" name="cta_title" class="kivun-lp-input" value="<?php echo esc_attr( $v['cta_title'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'תוכן', 'kivun' ); ?></label>
							<textarea name="cta_content" class="kivun-lp-input" rows="2"><?php echo esc_textarea( $v['cta_content'] ); ?></textarea>
							<label class="kivun-lp-sub"><?php esc_html_e( 'טקסט כפתור', 'kivun' ); ?></label>
							<input type="text" name="cta_button" class="kivun-lp-input" value="<?php echo esc_attr( $v['cta_button'] ); ?>">
						</div>

						<!-- Course-only extras -->
						<div class="kivun-lp-card kivun-cc-section" data-type="course" <?php echo isset( $group_posts['course'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'קורס — פרטים נוספים', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="course_capacity" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['course_capacity'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מזהה מוצר WooCommerce (אופציונלי)', 'kivun' ); ?></label>
							<input type="number" name="course_wc" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['course_wc'] ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'לקורס בתשלום: מזהה מוצר WooCommerce. ריק = חינם / לפי שדה העלות.', 'kivun' ); ?></p>
						</div>

						<!-- Session-only extras -->
						<div class="kivun-lp-card kivun-cc-section" data-type="session" <?php echo isset( $group_posts['session'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'סדנה — פרטים נוספים', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="session_location" class="kivun-lp-input" value="<?php echo esc_attr( $v['session_location'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="session_capacity" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['session_capacity'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'תוקף ההרשמה (עד תאריך)', 'kivun' ); ?></label>
							<input type="date" name="session_valid_until" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['session_valid'] ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'אחרי תאריך זה ההרשמה נסגרת. לפתיחת מחזור חדש — עדכנו את התאריך.', 'kivun' ); ?></p>
						</div>

						<!-- Event-only extras -->
						<div class="kivun-lp-card kivun-cc-section" data-type="event" <?php echo isset( $group_posts['event'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'אירוע — פרטים נוספים', 'kivun' ); ?></h3>
							<p class="kivun-lp-hint"><?php esc_html_e( 'לאירוע יש שדות ייחודיים משלו כאן. המועד ותאריך האירוע נלקחים מהשדות שבבלוק זה — לא מהשדות "משך" / "תאריך" הכלליים שמעלה.', 'kivun' ); ?></p>
							<label class="kivun-lp-sub"><?php esc_html_e( 'תאריך האירוע (סוגר את ההרשמה)', 'kivun' ); ?></label>
							<input type="date" name="event_date" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['event_date'] ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'לאחר תאריך זה ההרשמה נסגרת לצמיתות. ריק = תמיד פתוח.', 'kivun' ); ?></p>
							<label class="kivun-lp-sub"><?php esc_html_e( 'מועד מלא (שעה / משך — לתצוגה)', 'kivun' ); ?></label>
							<input type="text" name="event_time" class="kivun-lp-input" value="<?php echo esc_attr( $v['event_time'] ); ?>" placeholder="<?php esc_attr_e( 'יום ג׳, 15.9.2025, 18:00–20:00', 'kivun' ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="event_location" class="kivun-lp-input" value="<?php echo esc_attr( $v['event_location'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="event_capacity" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['event_capacity'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אופן ההרשמה', 'kivun' ); ?></label>
							<select name="event_mode" class="kivun-lp-input">
								<option value="form" <?php selected( $v['event_mode'], 'form' ); ?>><?php esc_html_e( 'טופס באתר (נשמר בטבלת הלידים)', 'kivun' ); ?></option>
								<option value="external" <?php selected( $v['event_mode'], 'external' ); ?>><?php esc_html_e( 'כפתור לקישור חיצוני', 'kivun' ); ?></option>
							</select>
							<label class="kivun-lp-sub"><?php esc_html_e( 'קישור הרשמה חיצוני', 'kivun' ); ?></label>
							<input type="url" name="event_url" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['event_url'] ); ?>" placeholder="https://...">
							<label class="kivun-lp-sub"><?php esc_html_e( 'טקסט כפתור ההרשמה', 'kivun' ); ?></label>
							<input type="text" name="event_button" class="kivun-lp-input" value="<?php echo esc_attr( $v['event_button'] ); ?>" placeholder="<?php esc_attr_e( 'להרשמה לאירוע', 'kivun' ); ?>">

							<label class="kivun-lp-sub" style="margin-top:.9rem"><?php esc_html_e( 'תמונת האירוע (מלבנית צרה/גבוהה — לפופאפ)', 'kivun' ); ?></label>
							<div class="kivun-ev-media">
								<div class="kivun-ev-media__preview" <?php echo $ev_img_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $ev_img_url ); ?>" alt="" style="max-width:160px;height:auto"></div>
								<input type="hidden" name="event_image_id" class="kivun-ev-media__id" value="<?php echo esc_attr( $v['event_image_id'] ); ?>">
								<button type="button" class="button kivun-ev-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
								<button type="button" class="button-link kivun-ev-media__remove" <?php echo $ev_img_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
								<p class="kivun-lp-hint"><?php esc_html_e( 'או העלאת קובץ:', 'kivun' ); ?></p>
								<input type="file" name="event_image_file" accept="image/*">
							</div>

							<label class="kivun-cc-check" style="margin-top:.9rem">
								<input type="checkbox" name="event_popup" value="1" <?php checked( '1', $v['event_popup'] ); ?>>
								<?php esc_html_e( 'הצגת פופאפ באתר עד מועד האירוע (עם תמונת האירוע וכפתור לעמוד)', 'kivun' ); ?>
							</label>
						</div>

					</div>

					<div class="kivun-lp-side">

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'מה לפרסם?', 'kivun' ); ?></label>
							<?php foreach ( $sections as $key => $label ) : ?>
								<label class="kivun-cc-check">
									<input type="checkbox" name="publish[]" value="<?php echo esc_attr( $key ); ?>" class="kivun-cc-toggle" data-type="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $group_posts[ $key ] ) ); ?>>
									<?php echo esc_html( $label ); ?>
									<?php if ( isset( $group_posts[ $key ] ) ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $group_posts[ $key ] ) ); ?>" style="margin-inline-start:auto;font-size:12px">↗</a>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
							<div class="kivun-lp-actions">
								<button type="submit" class="button button-primary button-hero"><?php echo $editing ? esc_html__( 'שמירת שינויים', 'kivun' ) : esc_html__( 'פרסום', 'kivun' ); ?></button>
								<?php if ( $editing ) : ?>
									<a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'תוכן חדש', 'kivun' ); ?></a>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תמונה ראשית', 'kivun' ); ?></label>
							<div class="kivun-lp-media">
								<div class="kivun-lp-media__preview" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $thumb_url ); ?>" alt=""></div>
								<input type="hidden" name="thumbnail_id" id="kivun-lp-thumb-id" value="<?php echo esc_attr( $v['thumb_id'] ); ?>">
								<button type="button" class="button kivun-lp-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
								<button type="button" class="button-link kivun-lp-media__remove" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
								<p class="kivun-lp-hint"><?php esc_html_e( 'או העלאת קובץ:', 'kivun' ); ?></p>
								<input type="file" name="thumbnail_file" accept="image/*">
								<?php if ( $ai_show ) : ?>
									<?php if ( ! $ai_configured ) : ?>
										<p class="kivun-cc-ai-warn" style="margin-top:.6rem">
											<?php
											echo wp_kses_post(
												sprintf(
													/* translators: %s: link to the settings page. */
													__( '⚠️ היצירה האוטומטית כבויה — לא הוגדר מפתח API. <a href="%s">להגדרות</a>.', 'kivun' ),
													esc_url( admin_url( 'admin.php?page=kivun-settings' ) )
												)
											);
											?>
										</p>
									<?php endif; ?>
									<label class="kivun-lp-sub" style="margin-top:.75rem"><?php esc_html_e( 'סגנון התמונה', 'kivun' ); ?></label>
									<select class="kivun-lp-input kivun-cc-ai-style">
										<?php foreach ( Kivun_AI_Image::styles() as $skey => $slabel ) : ?>
											<option value="<?php echo esc_attr( $skey ); ?>"><?php echo esc_html( $slabel ); ?></option>
										<?php endforeach; ?>
									</select>
									<label class="kivun-lp-sub"><?php esc_html_e( 'תיאור חופשי לתמונה (אופציונלי)', 'kivun' ); ?></label>
									<textarea class="kivun-lp-input kivun-cc-ai-prompt" rows="2" placeholder="<?php esc_attr_e( 'אם ריק — ייווצר מהכותרת והתיאור הקצר', 'kivun' ); ?>"></textarea>
									<p style="margin:.5rem 0 .35rem">
										<button type="button" class="button kivun-cc-ai-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_image' ) ); ?>">
											<?php esc_html_e( '✨ צור תמונה עם AI', 'kivun' ); ?>
										</button>
									</p>
									<span class="kivun-cc-ai-status" style="font-size:12px;color:#555"></span>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></label>
							<select name="status" class="kivun-lp-input">
								<option value="publish" <?php selected( $v['status'], 'publish' ); ?>><?php esc_html_e( 'מפורסם', 'kivun' ); ?></option>
								<option value="draft" <?php selected( $v['status'], 'draft' ); ?>><?php esc_html_e( 'טיוטה', 'kivun' ); ?></option>
							</select>
						</div>

					</div>
				</div>
			</form>

			<?php if ( ! $editing ) : ?>
				<?php self::render_groups_list(); ?>
			<?php endif; ?>
		</div>

		<script>
		( function () {
			function kivunUpdateNonEvent() {
				var ev    = document.querySelector( '.kivun-cc-toggle[data-type="event"]:checked' ),
					other = document.querySelector( '.kivun-cc-toggle[data-type="landing"]:checked, .kivun-cc-toggle[data-type="course"]:checked, .kivun-cc-toggle[data-type="session"]:checked' ),
					hide  = ev && ! other;
				document.querySelectorAll( '.kivun-cc-nonevent' ).forEach( function ( el ) { el.style.display = hide ? 'none' : ''; } );
			}
			document.querySelectorAll( '.kivun-cc-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var sec = document.querySelector( '.kivun-cc-section[data-type="' + cb.dataset.type + '"]' );
					if ( sec ) { sec.hidden = ! cb.checked; }
					kivunUpdateNonEvent();
				} );
			} );
			kivunUpdateNonEvent();
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

			var aiBtn = document.querySelector( '.kivun-cc-ai-btn' );
			if ( aiBtn ) {
				aiBtn.addEventListener( 'click', function () {
					var titleEl  = document.querySelector( '[name="title"]' ),
						title    = titleEl ? titleEl.value.trim() : '',
						status   = document.querySelector( '.kivun-cc-ai-status' ),
						styleEl  = document.querySelector( '.kivun-cc-ai-style' ),
						promptEl = document.querySelector( '.kivun-cc-ai-prompt' ),
						custom   = promptEl ? promptEl.value.trim() : '';
					if ( ! title && ! custom ) {
						if ( status ) { status.textContent = '<?php echo esc_js( __( 'מלאו כותרת או תיאור חופשי.', 'kivun' ) ); ?>'; }
						return;
					}
					var shortVal = ( window.tinymce && tinymce.get( 'kivun_cc_short' ) )
							? tinymce.get( 'kivun_cc_short' ).getContent( { format: 'text' } )
							: ( ( document.getElementById( 'kivun_cc_short' ) || {} ).value || '' ),
						typeEl = document.querySelector( '.kivun-cc-toggle:checked' ),
						fd     = new FormData();
					fd.append( 'action', 'kivun_generate_ai_image' );
					fd.append( 'nonce', aiBtn.dataset.nonce );
					fd.append( 'title', title );
					fd.append( 'desc', shortVal );
					fd.append( 'type', typeEl ? typeEl.dataset.type : '' );
					fd.append( 'style', styleEl ? styleEl.value : 'photo' );
					fd.append( 'prompt', custom );

					aiBtn.disabled = true;
					if ( status ) { status.textContent = '<?php echo esc_js( __( 'יוצר תמונה… זה עשוי לקחת עד דקה', 'kivun' ) ); ?>'; }

					fetch( aiBtn.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							aiBtn.disabled = false;
							if ( res && res.success && res.data ) {
								if ( idInput ) { idInput.value = res.data.id; }
								if ( img ) { img.src = res.data.url; }
								if ( preview ) { preview.style.display = ''; }
								if ( removeBtn ) { removeBtn.style.display = ''; }
								if ( status ) { status.textContent = '<?php echo esc_js( __( '✓ נוצרה תמונה', 'kivun' ) ); ?>'; }
							} else {
								if ( status ) { status.textContent = ( res && res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'יצירת התמונה נכשלה', 'kivun' ) ); ?>'; }
							}
						} )
						.catch( function () {
							aiBtn.disabled = false;
							if ( status ) { status.textContent = '<?php echo esc_js( __( 'שגיאת רשת', 'kivun' ) ); ?>'; }
						} );
				} );
			}

			// Event portrait / popup image picker.
			( function () {
				var evFrame,
					evSel = document.querySelector( '.kivun-ev-media__select' ),
					evRm  = document.querySelector( '.kivun-ev-media__remove' ),
					evId  = document.querySelector( '.kivun-ev-media__id' ),
					evPr  = document.querySelector( '.kivun-ev-media__preview' ),
					evImg = evPr ? evPr.querySelector( 'img' ) : null;
				if ( evSel ) {
					evSel.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						if ( evFrame ) { evFrame.open(); return; }
						evFrame = wp.media( { multiple: false } );
						evFrame.on( 'select', function () {
							var a = evFrame.state().get( 'selection' ).first().toJSON();
							if ( evId ) { evId.value = a.id; }
							if ( evImg ) { evImg.src = ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ); }
							if ( evPr ) { evPr.style.display = ''; }
							if ( evRm ) { evRm.style.display = ''; }
						} );
						evFrame.open();
					} );
				}
				if ( evRm ) {
					evRm.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						if ( evId ) { evId.value = ''; }
						if ( evPr ) { evPr.style.display = 'none'; }
						evRm.style.display = 'none';
					} );
				}
			} () );

			// AI content generation — fills the fields from a short topic.
			var genBtn = document.querySelector( '.kivun-cc-gen-btn' );
			if ( genBtn ) {
				genBtn.addEventListener( 'click', function () {
					var topicEl = document.querySelector( '.kivun-cc-gen-topic' ),
						topic   = topicEl ? topicEl.value.trim() : '',
						toneEl  = document.querySelector( '.kivun-cc-gen-tone' ),
						imgEl   = document.querySelector( '.kivun-cc-gen-image' ),
						imgFile = ( imgEl && imgEl.files && imgEl.files[0] ) ? imgEl.files[0] : null,
						gstat   = document.querySelector( '.kivun-cc-gen-status' ),
						typeEl  = document.querySelector( '.kivun-cc-toggle:checked' ),
						fd      = new FormData();
					if ( ! topic && ! imgFile ) {
						if ( gstat ) { gstat.textContent = '<?php echo esc_js( __( 'מלאו נושא או העלו מודעה.', 'kivun' ) ); ?>'; }
						return;
					}
					if ( imgFile ) { fd.append( 'image', imgFile ); }
					function setF( id, val ) {
						if ( window.tinymce && tinymce.get( id ) ) { tinymce.get( id ).setContent( val || '' ); }
						else { var el = document.getElementById( id ); if ( el ) { el.value = val || ''; } }
					}
					fd.append( 'action', 'kivun_generate_ai_content' );
					fd.append( 'nonce', genBtn.dataset.nonce );
					fd.append( 'topic', topic );
					fd.append( 'tone', toneEl ? toneEl.value : 'marketing' );
					fd.append( 'type', typeEl ? typeEl.dataset.type : '' );
					genBtn.disabled = true;
					if ( gstat ) { gstat.textContent = '<?php echo esc_js( __( 'יוצר תוכן… זה עשוי לקחת מספר שניות', 'kivun' ) ); ?>'; }
					fetch( genBtn.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							genBtn.disabled = false;
							if ( res && res.success && res.data ) {
								var d = res.data, t = document.getElementById( 'kivun-cc-title' );
								if ( t && d.title ) { t.value = d.title; }
								setF( 'kivun_cc_short', d.short );
								setF( 'kivun_cc_long', d.long );
								setF( 'kivun_cc_audience', d.audience );
								setF( 'kivun_cc_duration', d.duration );
								setF( 'kivun_cc_cost', d.cost );
								setF( 'kivun_cc_date', d.date );
								if ( gstat ) { gstat.textContent = '<?php echo esc_js( __( '✓ נוצר תוכן — ערכו ושמרו', 'kivun' ) ); ?>'; }
							} else {
								if ( gstat ) { gstat.textContent = ( res && res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'יצירת התוכן נכשלה', 'kivun' ) ); ?>'; }
							}
						} )
						.catch( function () {
							genBtn.disabled = false;
							if ( gstat ) { gstat.textContent = '<?php echo esc_js( __( 'שגיאת רשת', 'kivun' ) ); ?>'; }
						} );
				} );
			}
		} () );
		</script>
		<?php
	}

	/**
	 * Render a list of existing content groups with edit links.
	 *
	 * @return void
	 */
	private static function render_groups_list(): void {
		$posts = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		if ( ! $posts ) {
			return;
		}

		$labels = array(
			'kivun_workshop' => __( 'דף נחיתה', 'kivun' ),
			'kivun_course'   => __( 'קורס', 'kivun' ),
			'kivun_session'  => __( 'סדנה', 'kivun' ),
			'kivun_event'    => __( 'אירוע', 'kivun' ),
		);
		$groups = array();
		foreach ( $posts as $p ) {
			$g = (string) get_post_meta( $p->ID, self::GROUP_META, true );
			if ( '' === $g ) {
				continue;
			}
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array(
					'title' => get_the_title( $p->ID ),
					'types' => array(),
				);
			}
			$groups[ $g ]['types'][] = $labels[ $p->post_type ] ?? $p->post_type;
		}
		if ( ! $groups ) {
			return;
		}
		?>
		<h2 style="margin-top:2rem"><?php esc_html_e( 'תכנים שנוצרו יחד (עריכה מאוחדת)', 'kivun' ); ?></h2>
		<table class="wp-list-table widefat fixed striped" style="max-width:760px">
			<thead><tr>
				<th><?php esc_html_e( 'כותרת', 'kivun' ); ?></th>
				<th style="width:220px"><?php esc_html_e( 'פורסם כ', 'kivun' ); ?></th>
				<th style="width:90px"></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $groups as $gid => $g ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $g['title'] ); ?></strong></td>
					<td><?php echo esc_html( implode( ' · ', array_unique( $g['types'] ) ) ); ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&group=' . rawurlencode( $gid ) ) ); ?>"><?php esc_html_e( 'עריכה', 'kivun' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Create/update the selected posts from the submitted shared content.
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

		$result = self::run_save();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'group'       => rawurlencode( $result ),
					'kivun_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Read the submitted content and create/update the selected posts.
	 *
	 * The caller MUST verify a nonce and the user capability before invoking it.
	 *
	 * @return string|\WP_Error The content group id on success, or an error.
	 */
	public static function run_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce before calling run_save().
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'kivun_no_title', __( 'יש להזין כותרת.', 'kivun' ) );
		}

		$s = array(
			'title'    => $title,
			'slug'     => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'long'     => isset( $_POST['long'] ) ? wp_kses_post( wp_unslash( $_POST['long'] ) ) : '',
			'short'    => isset( $_POST['short'] ) ? wp_kses_post( wp_unslash( $_POST['short'] ) ) : '',
			'audience' => isset( $_POST['audience'] ) ? wp_kses_post( wp_unslash( $_POST['audience'] ) ) : '',
			'duration' => isset( $_POST['duration'] ) ? wp_kses_post( wp_unslash( $_POST['duration'] ) ) : '',
			'cost'     => isset( $_POST['cost'] ) ? wp_kses_post( wp_unslash( $_POST['cost'] ) ) : '',
			'date'     => isset( $_POST['date'] ) ? wp_kses_post( wp_unslash( $_POST['date'] ) ) : '',
			'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'cta_t'    => isset( $_POST['cta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_title'] ) ) : '',
			'cta_c'    => isset( $_POST['cta_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cta_content'] ) ) : '',
			'cta_b'    => isset( $_POST['cta_button'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_button'] ) ) : '',
			'status'   => ( isset( $_POST['status'] ) && 'draft' === $_POST['status'] ) ? 'draft' : 'publish',
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value below.
		$publish = isset( $_POST['publish'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['publish'] ) ) : array();
		$group   = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		if ( '' === trim( $group ) ) {
			$group = wp_generate_uuid4();
		}
		$thumb_id = self::resolve_thumbnail();

		$cta = array(
			'_kivun_cta_title'   => $s['cta_t'],
			'_kivun_cta_content' => $s['cta_c'],
			'_kivun_cta_button'  => $s['cta_b'],
		);

		if ( in_array( 'landing', $publish, true ) ) {
			self::upsert(
				$group,
				'kivun_workshop',
				$s,
				'',
				$thumb_id,
				array_merge(
					array(
						'_kivun_lp_short'      => $s['short'],
						'_kivun_ws_audience'   => $s['audience'],
						'_kivun_ws_duration'   => $s['duration'],
						'_kivun_lp_cost'       => $s['cost'],
						'_kivun_ws_date'       => $s['date'],
						'_kivun_contact_email' => $s['email'],
					),
					$cta
				)
			);
		}

		if ( in_array( 'course', $publish, true ) ) {
			$price = (int) preg_replace( '/[^0-9]/', '', $s['cost'] );
			self::upsert(
				$group,
				'kivun_course',
				$s,
				$s['short'],
				$thumb_id,
				array_merge(
					array(
						'_kivun_target_audience' => $s['audience'],
						'_kivun_duration'        => $s['duration'],
						'_kivun_price'           => $price,
						'_kivun_schedule'        => $s['date'],
						'_kivun_capacity'        => absint( $_POST['course_capacity'] ?? 0 ),
						'_kivun_wc_product_id'   => absint( $_POST['course_wc'] ?? 0 ),
						'_kivun_contact_email'   => $s['email'],
					),
					$cta
				)
			);
		}

		if ( in_array( 'session', $publish, true ) ) {
			self::upsert(
				$group,
				'kivun_session',
				$s,
				'',
				$thumb_id,
				array_merge(
					array(
						'_kivun_session_short'       => $s['short'],
						'_kivun_session_audience'    => $s['audience'],
						'_kivun_session_duration'    => $s['duration'],
						'_kivun_session_cost'        => $s['cost'],
						'_kivun_session_date'        => $s['date'],
						'_kivun_session_location'    => sanitize_text_field( wp_unslash( $_POST['session_location'] ?? '' ) ),
						'_kivun_capacity'            => absint( $_POST['session_capacity'] ?? 0 ),
						'_kivun_contact_email'       => $s['email'],
						'_kivun_session_valid_until' => sanitize_text_field( wp_unslash( $_POST['session_valid_until'] ?? '' ) ),
					),
					$cta
				)
			);
		}

		if ( in_array( 'event', $publish, true ) ) {
			$event_image = self::resolve_image( 'event_image_file', 'event_image_id' );
			$event_mode  = ( isset( $_POST['event_mode'] ) && 'external' === $_POST['event_mode'] ) ? 'external' : 'form';
			self::upsert(
				$group,
				'kivun_event',
				$s,
				$s['short'],
				$event_image ? $event_image : $thumb_id,
				array_merge(
					array(
						'_kivun_event_short'        => $s['short'],
						'_kivun_event_audience'     => $s['audience'],
						'_kivun_event_time'         => sanitize_text_field( wp_unslash( $_POST['event_time'] ?? '' ) ),
						'_kivun_event_cost'         => $s['cost'],
						'_kivun_event_date'         => sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ),
						'_kivun_event_location'     => sanitize_text_field( wp_unslash( $_POST['event_location'] ?? '' ) ),
						'_kivun_capacity'           => absint( $_POST['event_capacity'] ?? 0 ),
						'_kivun_event_mode'         => $event_mode,
						'_kivun_event_external_url' => esc_url_raw( wp_unslash( $_POST['event_url'] ?? '' ) ),
						'_kivun_event_button'       => sanitize_text_field( wp_unslash( $_POST['event_button'] ?? '' ) ),
						'_kivun_event_popup'        => empty( $_POST['event_popup'] ) ? '' : '1',
						'_kivun_event_image'        => $event_image,
						'_kivun_contact_email'      => $s['email'],
					),
					$cta
				)
			);
		}

		return $group;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Create or update the post of a given type inside a content group.
	 *
	 * @param string $group     The content group id.
	 * @param string $post_type The target post type.
	 * @param array  $shared    Shared fields (title, slug, long, status).
	 * @param string $excerpt   Optional excerpt (short description).
	 * @param int    $thumb_id  Featured image attachment ID (0 = leave as is).
	 * @param array  $meta       Meta key => value pairs.
	 * @return int The post ID, or 0 on failure.
	 */
	private static function upsert( string $group, string $post_type, array $shared, string $excerpt, int $thumb_id, array $meta ): int {
		$existing = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $group, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => $shared['title'],
			'post_content' => $shared['long'],
			'post_excerpt' => $excerpt,
			'post_status'  => $shared['status'],
		);
		if ( '' !== $shared['slug'] ) {
			$postarr['post_name'] = $shared['slug'];
		}

		if ( $existing ) {
			$postarr['ID'] = (int) $existing[0];
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			return 0;
		}
		$post_id = (int) $result;

		update_post_meta( $post_id, self::GROUP_META, $group );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		if ( $thumb_id ) {
			set_post_thumbnail( $post_id, $thumb_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller before run_save().
		} elseif ( ! empty( $_POST['remove_thumbnail'] ) ) {
			delete_post_thumbnail( $post_id );
		}

		return $post_id;
	}

	/**
	 * Resolve the shared featured image: an uploaded file or a media selection.
	 *
	 * @return int Attachment ID, or 0.
	 */
	private static function resolve_thumbnail(): int {
		return self::resolve_image( 'thumbnail_file', 'thumbnail_id' );
	}

	/**
	 * Resolve an image field: a freshly uploaded file (preferred) or an
	 * already-selected attachment ID. Shared by the featured image and the
	 * event's portrait/popup image.
	 *
	 * The caller MUST verify the nonce before invoking this.
	 *
	 * @param string $file_field The $_FILES key for an uploaded file.
	 * @param string $id_field   The $_POST key holding a chosen attachment ID.
	 * @return int Attachment ID, or 0.
	 */
	private static function resolve_image( string $file_field, string $id_field ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller.
		if ( ! empty( $_FILES[ $file_field ]['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_id = media_handle_upload( $file_field, 0 );
			if ( ! is_wp_error( $attachment_id ) ) {
				return (int) $attachment_id;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller.
		return isset( $_POST[ $id_field ] ) ? absint( wp_unslash( $_POST[ $id_field ] ) ) : 0;
	}

	// ── Front-end shortcode ───────────────────────────────────────────────────

	/**
	 * Shortcode: render the content-creation form on a public page for logged-in
	 * users who can create content.
	 *
	 * @param mixed $atts Shortcode attributes (unused).
	 * @return string Rendered HTML.
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return self::front_login_notice();
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<div class="kivun-cc-front"><div class="kivun-cc-note kivun-cc-note--error">'
				. esc_html__( 'אין לך הרשאה להזין תוכן. פנו למנהל המערכת.', 'kivun' )
				. '</div></div>';
		}

		Kivun_Core::enqueue_frontend_assets();
		// Voice dictation is for content authors only — load it here (the content
		// creator page), never on the public lead/registration forms.
		wp_enqueue_script( 'kivun-voice' );

		// The leads tab reuses the CRM's inline status/notes editing, so it needs
		// the same script and nonce the wp-admin page uses.
		wp_enqueue_script(
			'kivun-admin-crm',
			KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'admin-crm', 'js' ),
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

		ob_start();
		self::front_console();
		return (string) ob_get_clean();
	}

	/**
	 * Whether the current user may see and manage the leads/registrations CRM.
	 *
	 * Publishing content only needs edit_posts, but the leads table exposes
	 * every enquirer's name, email and phone — so it is limited to people who
	 * manage other people's content (editors and administrators) rather than
	 * every author who can create a landing page.
	 *
	 * @return bool
	 */
	public static function can_manage_leads(): bool {
		return current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Render the whole front-end management console: publishing form, content
	 * library and the leads/registrations CRM, as tabs — so a manager never has
	 * to open wp-admin to run the dynamic content.
	 *
	 * @return void
	 */
	private static function front_console(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab state.
		$group = isset( $_GET['kivun_group'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_group'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab state.
		$tab = isset( $_GET['kivun_tab'] ) ? sanitize_key( wp_unslash( $_GET['kivun_tab'] ) ) : '';

		// Editing a specific group always lands on the form.
		if ( '' !== $group ) {
			$tab = 'form';
		}
		$show_leads = self::can_manage_leads();
		if ( ! in_array( $tab, array( 'form', 'library', 'leads' ), true ) || ( 'leads' === $tab && ! $show_leads ) ) {
			$tab = 'form';
		}

		$page_url   = (string) get_permalink();
		$page_url   = $page_url ? $page_url : home_url();
		$can_delete = current_user_can( 'delete_posts' );
		$lead_count = $show_leads ? self::leads_count() : 0;
		?>
		<div class="kivun-cc-console" dir="rtl">

			<div class="kivun-tabs kivun-cc-tabs" role="tablist" aria-label="<?php esc_attr_e( 'ניהול תוכן', 'kivun' ); ?>">
				<?php
				$console_tabs = array(
					'form'    => __( 'פרסום תוכן', 'kivun' ),
					'library' => __( 'התכנים שלי', 'kivun' ),
				);
				if ( $show_leads ) {
					$console_tabs['leads'] = __( 'לידים והרשמות', 'kivun' );
				}
				foreach ( $console_tabs as $tab_key => $tab_label ) :
					$is_active = ( $tab === $tab_key );
					?>
					<button
						type="button"
						class="kivun-tab <?php echo $is_active ? 'is-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $tab_key ); ?>"
						role="tab"
						id="kivun-cctab-<?php echo esc_attr( $tab_key ); ?>"
						aria-controls="kivun-ccpanel-<?php echo esc_attr( $tab_key ); ?>"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
						<?php echo $is_active ? '' : 'tabindex="-1"'; ?>
					>
						<?php echo esc_html( $tab_label ); ?>
						<?php if ( 'leads' === $tab_key && $lead_count ) : ?>
							<span class="kivun-tab-badge kivun-tab-badge--soft"><?php echo esc_html( number_format_i18n( $lead_count ) ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>

			<section class="kivun-tab-panel <?php echo 'form' === $tab ? 'is-active' : ''; ?>" data-panel="form" id="kivun-ccpanel-form" role="tabpanel" aria-labelledby="kivun-cctab-form" tabindex="0" <?php echo 'form' === $tab ? '' : 'hidden'; ?>>
				<?php self::front_form(); ?>
			</section>

			<section class="kivun-tab-panel <?php echo 'library' === $tab ? 'is-active' : ''; ?>" data-panel="library" id="kivun-ccpanel-library" role="tabpanel" aria-labelledby="kivun-cctab-library" tabindex="0" <?php echo 'library' === $tab ? '' : 'hidden'; ?>>
				<?php self::front_library( $page_url, $can_delete ); ?>
			</section>

			<?php if ( $show_leads ) : ?>
				<section class="kivun-tab-panel <?php echo 'leads' === $tab ? 'is-active' : ''; ?>" data-panel="leads" id="kivun-ccpanel-leads" role="tabpanel" aria-labelledby="kivun-cctab-leads" tabindex="0" <?php echo 'leads' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_leads( $page_url ); ?>
				</section>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Total number of registration/lead rows, for the tab badge.
	 *
	 * @return int
	 */
	private static function leads_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations" );
	}

	/**
	 * Handle the front-end submission (logged-in authorised users only).
	 *
	 * @return void
	 */
	public static function handle_front_save(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}
		if ( ! isset( $_POST['kivun_cc_front_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_cc_front_nonce'] ) ), 'kivun_create_content_front' ) ) {
			wp_die( esc_html__( 'בקשה לא תקפה.', 'kivun' ) );
		}

		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( '' === $redirect ) {
			$redirect = home_url();
		}

		$result = self::run_save();
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'kivun_cc_error', 1, $redirect );
		} else {
			$redirect = add_query_arg(
				array(
					'kivun_group' => rawurlencode( $result ),
					'kivun_saved' => 1,
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle a front-end "delete whole content group" submission.
	 *
	 * @return void
	 */
	public static function handle_front_delete(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}
		if ( ! isset( $_POST['kivun_cc_del_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_cc_del_nonce'] ) ), 'kivun_delete_content_front' ) ) {
			wp_die( esc_html__( 'בקשה לא תקפה.', 'kivun' ) );
		}

		$group    = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( '' === $redirect ) {
			$redirect = home_url();
		}
		$redirect = remove_query_arg( array( 'kivun_group', 'kivun_saved', 'kivun_cc_error' ), $redirect );

		$deleted  = self::delete_group( $group );
		$redirect = add_query_arg( $deleted > 0 ? 'kivun_deleted' : 'kivun_cc_error', 1, $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Trash every post linked to a content group (respecting per-post caps).
	 *
	 * @param string $group The content group id.
	 * @return int Number of posts trashed.
	 */
	private static function delete_group( string $group ): int {
		$posts = self::group_posts( $group );
		if ( ! $posts ) {
			return 0;
		}

		$count = 0;
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( current_user_can( 'delete_post', $post_id ) && wp_trash_post( $post_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Build a small stand-alone delete form (posts outside the main form).
	 *
	 * @param string $group     The content group id.
	 * @param string $page_url  Redirect target after deletion.
	 * @param string $label     Button label.
	 * @param string $btn_class Button CSS classes.
	 * @return string Escaped HTML.
	 */
	private static function delete_form( string $group, string $page_url, string $label, string $btn_class ): string {
		return '<form class="kivun-cc-delete-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="kivun_delete_content_front">'
			. '<input type="hidden" name="group" value="' . esc_attr( $group ) . '">'
			. '<input type="hidden" name="redirect" value="' . esc_url( $page_url ) . '">'
			. '<input type="hidden" name="kivun_cc_del_nonce" value="' . esc_attr( wp_create_nonce( 'kivun_delete_content_front' ) ) . '">'
			. '<button type="submit" class="' . esc_attr( $btn_class ) . '">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	/**
	 * Build a small stand-alone "duplicate this post" form.
	 *
	 * @param int    $post_id   The specific post to duplicate.
	 * @param string $page_url  Redirect target after duplication.
	 * @param string $btn_class Button CSS classes.
	 * @return string Escaped HTML.
	 */
	private static function duplicate_form( int $post_id, string $page_url, string $btn_class ): string {
		return '<form class="kivun-cc-dup-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="kivun_cc_duplicate_front">'
			. '<input type="hidden" name="post" value="' . (int) $post_id . '">'
			. '<input type="hidden" name="redirect" value="' . esc_url( $page_url ) . '">'
			. '<input type="hidden" name="kivun_cc_dup_nonce" value="' . esc_attr( wp_create_nonce( 'kivun_cc_duplicate_front' ) ) . '">'
			. '<button type="submit" class="' . esc_attr( $btn_class ) . '">' . esc_html__( 'שכפל', 'kivun' ) . '</button>'
			. '</form>';
	}

	/**
	 * Handle a front-end "duplicate this specific post" submission. The copy is a
	 * fresh draft in its own new content group (not the whole original group).
	 *
	 * @return void
	 */
	public static function handle_front_duplicate(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}
		if ( ! isset( $_POST['kivun_cc_dup_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kivun_cc_dup_nonce'] ) ), 'kivun_cc_duplicate_front' ) ) {
			wp_die( esc_html__( 'בקשה לא תקפה.', 'kivun' ) );
		}

		$post_id  = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( '' === $redirect ) {
			$redirect = home_url();
		}
		$redirect = remove_query_arg( array( 'kivun_group', 'kivun_saved', 'kivun_cc_error', 'kivun_deleted' ), $redirect );

		$new_group = '';
		if ( $post_id && in_array( get_post_type( $post_id ), array_values( self::type_map() ), true ) ) {
			$group = wp_generate_uuid4();
			if ( self::clone_post( $post_id, $group ) ) {
				$new_group = $group;
			}
		}

		if ( '' !== $new_group ) {
			$redirect = add_query_arg(
				array(
					'kivun_group'      => rawurlencode( $new_group ),
					'kivun_duplicated' => 1,
				),
				$redirect
			);
		} else {
			$redirect = add_query_arg( 'kivun_cc_error', 1, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Clone a single post (content, meta, taxonomies, thumbnail) as a draft,
	 * linked to the given content group.
	 *
	 * @param int    $post_id   The source post ID.
	 * @param string $new_group The target content group id.
	 * @return bool
	 */
	private static function clone_post( int $post_id, string $new_group ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				/* translators: %s: original title. */
				'post_title'   => sprintf( __( '%s (עותק)', 'kivun' ), $post->post_title ),
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return false;
		}

		$skip = array( '_edit_lock', '_edit_last', self::GROUP_META );
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( wp_slash( $value ) ) );
			}
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, (int) $thumb );
		}

		update_post_meta( $new_id, self::GROUP_META, $new_group );
		return true;
	}

	/**
	 * A styled inline login box for the content-creator page. Reuses the
	 * `.kivun-employer-login` structure so Nextend Social Login injects its
	 * "Continue with Google" button and the shared script/styles apply (button
	 * moved above the fields, centered).
	 *
	 * @return string
	 */
	private static function front_login_notice(): string {
		$here = (string) get_permalink();
		$here = $here ? $here : home_url();

		Kivun_Core::enqueue_frontend_assets();

		ob_start();
		?>
		<div class="kivun-cc-front">
			<div class="kivun-employer-login kivun-cc-login" dir="rtl">
				<h2 class="kivun-employer-login__title"><?php esc_html_e( 'התחברות', 'kivun' ); ?></h2>
				<p class="kivun-notice"><?php esc_html_e( 'יש להתחבר כדי להזין תוכן.', 'kivun' ); ?></p>
				<?php
				wp_login_form(
					array(
						'redirect'       => $here,
						'label_username' => __( 'אימייל / שם משתמש', 'kivun' ),
						'label_password' => __( 'סיסמה', 'kivun' ),
						'label_remember' => __( 'זכור אותי', 'kivun' ),
						'label_log_in'   => __( 'התחברות', 'kivun' ),
					)
				);
				?>
				<p class="kivun-employer-login__links">
					<a href="<?php echo esc_url( wp_lostpassword_url( $here ) ); ?>"><?php esc_html_e( 'שכחתי סיסמה', 'kivun' ); ?></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the branded front-end create/edit form.
	 *
	 * @return void
	 */
	private static function front_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill.
		$group       = isset( $_GET['kivun_group'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_group'] ) ) : '';
		$group_posts = self::group_posts( $group );
		$editing     = (bool) $group_posts;
		$v           = self::form_values( $group_posts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
		$saved = ! empty( $_GET['kivun_saved'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
		$error = ! empty( $_GET['kivun_cc_error'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
		$deleted = ! empty( $_GET['kivun_deleted'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
		$duplicated = ! empty( $_GET['kivun_duplicated'] );

		$page_url      = (string) get_permalink();
		$page_url      = $page_url ? $page_url : home_url();
		$can_upload    = current_user_can( 'upload_files' );
		$can_delete    = current_user_can( 'delete_posts' );
		$ai_configured = Kivun_AI_Image::is_configured();
		$thumb_url     = (int) $v['thumb_id'] ? (string) wp_get_attachment_image_url( (int) $v['thumb_id'], 'medium' ) : '';
		$ev_img_url    = (int) $v['event_image_id'] ? (string) wp_get_attachment_image_url( (int) $v['event_image_id'], 'medium' ) : '';
		$sections      = array(
			'landing' => __( 'דף נחיתה', 'kivun' ),
			'course'  => __( 'קורס', 'kivun' ),
			'session' => __( 'סדנה', 'kivun' ),
			'event'   => __( 'אירוע', 'kivun' ),
		);
		?>
		<div class="kivun-cc-front">
			<div class="kivun-cc-head">
				<h2 class="kivun-cc-title"><?php echo $editing ? esc_html__( 'עריכת תוכן', 'kivun' ) : esc_html__( 'פרסום תוכן חדש', 'kivun' ); ?></h2>
				<p class="kivun-cc-lead"><?php esc_html_e( 'מלאו את התוכן פעם אחת, סמנו מה לפרסם — דף נחיתה, קורס וסדנה נוצרים ומתעדכנים יחד.', 'kivun' ); ?></p>
			</div>

			<?php if ( $saved ) : ?>
				<div class="kivun-cc-note kivun-cc-note--success">
					<span><?php esc_html_e( 'התוכן פורסם בהצלחה.', 'kivun' ); ?></span>
					<?php if ( $group_posts ) : ?>
						<span class="kivun-cc-note__actions">
							<?php
							$view_labels = array(
								'landing' => __( 'דף הנחיתה', 'kivun' ),
								'course'  => __( 'הקורס', 'kivun' ),
								'session' => __( 'הסדנה', 'kivun' ),
								'event'   => __( 'האירוע', 'kivun' ),
							);
							foreach ( $group_posts as $tkey => $pid ) :
								$view_url = (string) get_permalink( (int) $pid );
								if ( '' === $view_url ) {
									continue;
								}
								?>
								<a class="kivun-cc-btn kivun-cc-btn--sm" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener">
									<?php
									/* translators: %s: content type label (landing page / course / session). */
									printf( esc_html__( 'צפייה ב%s ↗', 'kivun' ), esc_html( $view_labels[ $tkey ] ?? '' ) );
									?>
								</a>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="kivun-cc-note kivun-cc-note--error"><?php esc_html_e( 'הפעולה נכשלה — ודאו שמילאתם כותרת ושיש לכם הרשאה.', 'kivun' ); ?></div>
			<?php endif; ?>
			<?php if ( $deleted ) : ?>
				<div class="kivun-cc-note kivun-cc-note--success"><?php esc_html_e( 'התוכן נמחק (הועבר לפח).', 'kivun' ); ?></div>
			<?php endif; ?>
			<?php if ( $duplicated ) : ?>
				<div class="kivun-cc-note kivun-cc-note--success"><?php esc_html_e( 'התוכן שוכפל — לפניך העותק (טיוטה). ערכו ושמרו.', 'kivun' ); ?></div>
			<?php endif; ?>

			<form class="kivun-cc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_create_content_front">
				<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
				<input type="hidden" name="redirect" value="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'kivun_create_content_front', 'kivun_cc_front_nonce' ); ?>

				<div class="kivun-cc-grid">
					<div class="kivun-cc-main">

						<div class="kivun-cc-card kivun-cc-genai">
							<label class="kivun-cc-label"><?php esc_html_e( '✨ יצירת תוכן אוטומטית (AI)', 'kivun' ); ?></label>
							<?php if ( ! $ai_configured ) : ?>
								<p class="kivun-cc-ai-warn"><?php esc_html_e( '⚠️ היצירה האוטומטית אינה פעילה כרגע (לא הוגדר מפתח API / נגמרו הקרדיטים). פנו למנהל האתר.', 'kivun' ); ?></p>
							<?php endif; ?>
							<p class="kivun-cc-hint"><?php esc_html_e( 'תארו את הנושא וה-AI ימלא כותרת, תיאורים ופרטים — תוכלו לערוך אחר כך.', 'kivun' ); ?></p>
							<label class="kivun-cc-sub"><?php esc_html_e( 'סגנון כתיבה', 'kivun' ); ?></label>
							<select class="kivun-cc-input kivun-cc-gen-tone">
								<?php foreach ( Kivun_AI_Content::tones() as $tkey => $tlabel ) : ?>
									<option value="<?php echo esc_attr( $tkey ); ?>"><?php echo esc_html( $tlabel ); ?></option>
								<?php endforeach; ?>
							</select>
							<label class="kivun-cc-sub"><?php esc_html_e( 'נושא התוכן', 'kivun' ); ?></label>
							<textarea class="kivun-cc-input kivun-cc-textarea kivun-cc-gen-topic" rows="2" placeholder="<?php esc_attr_e( 'למשל: סדנת הורים-מתבגרים בת 4 מפגשים בערבים', 'kivun' ); ?>"></textarea>
							<?php if ( $can_upload ) : ?>
								<label class="kivun-cc-sub" style="margin-top:.6rem"><?php esc_html_e( 'או העלו מודעה מעוצבת — וה-AI יחלץ ממנה את הפרטים (אופציונלי)', 'kivun' ); ?></label>
								<input type="file" class="kivun-cc-input kivun-cc-gen-image" accept="image/*">
							<?php endif; ?>
							<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-cc-gen-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_content' ) ); ?>">
								<?php esc_html_e( '✨ צור תוכן', 'kivun' ); ?>
							</button>
							<span class="kivun-cc-gen-status" role="status" aria-live="polite"></span>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label" for="kivun-ccf-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-cc-req">*</span></label>
							<input type="text" id="kivun-ccf-title" name="title" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['title'] ); ?>" required>

							<label class="kivun-cc-sub" for="kivun-ccf-slug"><?php esc_html_e( 'כתובת URL (Slug)', 'kivun' ); ?></label>
							<input type="text" id="kivun-ccf-slug" name="slug" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="my-content">
							<p class="kivun-cc-hint"><?php esc_html_e( 'ריק = נוצר אוטומטית מהכותרת.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></label>
							<?php
							wp_editor(
								(string) $v['short'],
								'kivun_ccf_short',
								array(
									'textarea_name' => 'short',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
									'textarea_rows' => 4,
								)
							);
							?>

							<label class="kivun-cc-label" style="margin-top:1rem"><?php esc_html_e( 'תיאור מלא', 'kivun' ); ?></label>
							<?php
							wp_editor(
								(string) $v['long'],
								'kivun_ccf_long',
								array(
									'textarea_name' => 'long',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => false,
									'textarea_rows' => 10,
								)
							);
							?>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'פרטים', 'kivun' ); ?></label>
							<div class="kivun-cc-row">
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
									<textarea name="audience" class="kivun-cc-input kivun-cc-textarea" rows="2"><?php echo esc_textarea( $v['audience'] ); ?></textarea>
								</div>
								<div class="kivun-cc-nonevent">
									<label class="kivun-cc-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
									<textarea name="duration" class="kivun-cc-input kivun-cc-textarea" rows="2"><?php echo esc_textarea( $v['duration'] ); ?></textarea>
								</div>
							</div>
							<div class="kivun-cc-row">
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
									<textarea name="cost" class="kivun-cc-input kivun-cc-textarea" rows="2" placeholder="<?php esc_attr_e( 'חינם / 120 ₪', 'kivun' ); ?>"><?php echo esc_textarea( $v['cost'] ); ?></textarea>
								</div>
								<div class="kivun-cc-nonevent">
									<label class="kivun-cc-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
									<textarea name="date" class="kivun-cc-input kivun-cc-textarea" rows="2" placeholder="<?php esc_attr_e( '15.9.2025 בשעה 18:00', 'kivun' ); ?>"><?php echo esc_textarea( $v['date'] ); ?></textarea>
								</div>
							</div>
							<label class="kivun-cc-sub"><?php esc_html_e( 'אימייל לקבלת לידים/הרשמות', 'kivun' ); ?></label>
							<input type="email" name="email" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['email'] ); ?>">
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'באנר הנעה לפעולה (CTA)', 'kivun' ); ?></label>
							<label class="kivun-cc-sub"><?php esc_html_e( 'כותרת', 'kivun' ); ?></label>
							<input type="text" name="cta_title" class="kivun-cc-input" value="<?php echo esc_attr( $v['cta_title'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'תוכן', 'kivun' ); ?></label>
							<textarea name="cta_content" class="kivun-cc-input kivun-cc-textarea" rows="2"><?php echo esc_textarea( $v['cta_content'] ); ?></textarea>
							<label class="kivun-cc-sub"><?php esc_html_e( 'טקסט כפתור', 'kivun' ); ?></label>
							<input type="text" name="cta_button" class="kivun-cc-input" value="<?php echo esc_attr( $v['cta_button'] ); ?>">
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-type="course" <?php echo isset( $group_posts['course'] ) ? '' : 'hidden'; ?>>
							<h3 class="kivun-cc-h3"><?php esc_html_e( 'קורס — פרטים נוספים', 'kivun' ); ?></h3>
							<label class="kivun-cc-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="course_capacity" class="kivun-cc-input" min="0" value="<?php echo esc_attr( $v['course_capacity'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'מזהה מוצר WooCommerce (אופציונלי)', 'kivun' ); ?></label>
							<input type="number" name="course_wc" class="kivun-cc-input" min="0" value="<?php echo esc_attr( $v['course_wc'] ); ?>">
							<p class="kivun-cc-hint"><?php esc_html_e( 'לקורס בתשלום: מזהה מוצר WooCommerce. ריק = חינם / לפי שדה העלות.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-type="session" <?php echo isset( $group_posts['session'] ) ? '' : 'hidden'; ?>>
							<h3 class="kivun-cc-h3"><?php esc_html_e( 'סדנה — פרטים נוספים', 'kivun' ); ?></h3>
							<label class="kivun-cc-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="session_location" class="kivun-cc-input" value="<?php echo esc_attr( $v['session_location'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="session_capacity" class="kivun-cc-input" min="0" value="<?php echo esc_attr( $v['session_capacity'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'תוקף ההרשמה (עד תאריך)', 'kivun' ); ?></label>
							<input type="date" name="session_valid_until" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['session_valid'] ); ?>">
							<p class="kivun-cc-hint"><?php esc_html_e( 'אחרי תאריך זה המחזור הנוכחי נסגר; ההרשמה נשמרת למחזור הבא. עדכון תאריך פותח מחזור חדש.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-type="event" <?php echo isset( $group_posts['event'] ) ? '' : 'hidden'; ?>>
							<h3 class="kivun-cc-h3"><?php esc_html_e( 'אירוע — פרטים נוספים', 'kivun' ); ?></h3>
							<p class="kivun-cc-hint"><?php esc_html_e( 'לאירוע יש שדות ייחודיים משלו כאן. המועד ותאריך האירוע נלקחים מהשדות שבבלוק זה — לא מהשדות "משך" / "תאריך" הכלליים שמעלה.', 'kivun' ); ?></p>
							<label class="kivun-cc-sub"><?php esc_html_e( 'תאריך האירוע (סוגר את ההרשמה)', 'kivun' ); ?></label>
							<input type="date" name="event_date" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['event_date'] ); ?>">
							<p class="kivun-cc-hint"><?php esc_html_e( 'לאחר תאריך זה ההרשמה נסגרת לצמיתות. ריק = תמיד פתוח.', 'kivun' ); ?></p>
							<label class="kivun-cc-sub"><?php esc_html_e( 'מועד מלא (שעה / משך — לתצוגה)', 'kivun' ); ?></label>
							<input type="text" name="event_time" class="kivun-cc-input" value="<?php echo esc_attr( $v['event_time'] ); ?>" placeholder="<?php esc_attr_e( 'יום ג׳, 15.9.2025, 18:00–20:00', 'kivun' ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="event_location" class="kivun-cc-input" value="<?php echo esc_attr( $v['event_location'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="event_capacity" class="kivun-cc-input" min="0" value="<?php echo esc_attr( $v['event_capacity'] ); ?>">
							<label class="kivun-cc-sub"><?php esc_html_e( 'אופן ההרשמה', 'kivun' ); ?></label>
							<select name="event_mode" class="kivun-cc-input">
								<option value="form" <?php selected( $v['event_mode'], 'form' ); ?>><?php esc_html_e( 'טופס באתר (נשמר בטבלת הלידים)', 'kivun' ); ?></option>
								<option value="external" <?php selected( $v['event_mode'], 'external' ); ?>><?php esc_html_e( 'כפתור לקישור חיצוני', 'kivun' ); ?></option>
							</select>
							<label class="kivun-cc-sub"><?php esc_html_e( 'קישור הרשמה חיצוני', 'kivun' ); ?></label>
							<input type="url" name="event_url" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['event_url'] ); ?>" placeholder="https://...">
							<label class="kivun-cc-sub"><?php esc_html_e( 'טקסט כפתור ההרשמה', 'kivun' ); ?></label>
							<input type="text" name="event_button" class="kivun-cc-input" value="<?php echo esc_attr( $v['event_button'] ); ?>" placeholder="<?php esc_attr_e( 'להרשמה לאירוע', 'kivun' ); ?>">

							<label class="kivun-cc-sub" style="margin-top:.9rem"><?php esc_html_e( 'תמונת האירוע (מלבנית צרה/גבוהה — לפופאפ)', 'kivun' ); ?></label>
							<?php if ( (int) $v['event_image_id'] && $ev_img_url ) : ?>
								<div class="kivun-cc-media__preview" style="margin-bottom:.5rem"><img src="<?php echo esc_url( $ev_img_url ); ?>" alt="" style="max-width:140px;height:auto"></div>
							<?php endif; ?>
							<input type="hidden" name="event_image_id" value="<?php echo esc_attr( $v['event_image_id'] ); ?>">
							<?php if ( $can_upload ) : ?>
								<input type="file" name="event_image_file" accept="image/*" class="kivun-cc-input">
							<?php else : ?>
								<p class="kivun-cc-hint"><?php esc_html_e( 'אין לך הרשאה להעלות קבצים — התמונה תיקבע ע"י מנהל.', 'kivun' ); ?></p>
							<?php endif; ?>

							<label class="kivun-cc-check" style="margin-top:.9rem">
								<input type="checkbox" name="event_popup" value="1" <?php checked( '1', $v['event_popup'] ); ?>>
								<span><?php esc_html_e( 'הצגת פופאפ באתר עד מועד האירוע', 'kivun' ); ?></span>
							</label>
						</div>

					</div>

					<div class="kivun-cc-side">
						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'מה לפרסם?', 'kivun' ); ?></label>
							<?php foreach ( $sections as $key => $label ) : ?>
								<label class="kivun-cc-check">
									<input type="checkbox" name="publish[]" value="<?php echo esc_attr( $key ); ?>" class="kivun-cc-toggle" data-type="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $group_posts[ $key ] ) ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
									<?php if ( isset( $group_posts[ $key ] ) ) : ?>
										<a class="kivun-cc-view" href="<?php echo esc_url( (string) get_permalink( $group_posts[ $key ] ) ); ?>" target="_blank" rel="noopener">↗</a>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
							<p class="kivun-cc-hint"><?php esc_html_e( 'בחרו לפחות סוג אחד.', 'kivun' ); ?></p>
							<button type="submit" class="kivun-cc-btn kivun-cc-btn--block"><?php echo $editing ? esc_html__( 'שמירת שינויים', 'kivun' ) : esc_html__( 'פרסום', 'kivun' ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="kivun-cc-link" href="<?php echo esc_url( remove_query_arg( array( 'kivun_group', 'kivun_saved', 'kivun_cc_error' ), $page_url ) ); ?>"><?php esc_html_e( 'תוכן חדש', 'kivun' ); ?></a>
							<?php endif; ?>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'תמונה ראשית', 'kivun' ); ?></label>
							<div class="kivun-cc-media">
								<div class="kivun-cc-media__preview" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $thumb_url ); ?>" alt=""></div>
								<input type="hidden" name="thumbnail_id" value="<?php echo esc_attr( $v['thumb_id'] ); ?>">
								<input type="hidden" name="remove_thumbnail" value="0" class="kivun-cc-remove-flag">
								<?php if ( $can_upload ) : ?>
									<input type="file" name="thumbnail_file" accept="image/*" class="kivun-cc-file">
									<button type="button" class="kivun-cc-media__remove"<?php echo $thumb_url ? '' : ' hidden'; ?>><?php esc_html_e( 'הסרת התמונה', 'kivun' ); ?></button>
								<?php else : ?>
									<p class="kivun-cc-hint"><?php esc_html_e( 'אין לך הרשאה להעלות קבצים — התמונה תיקבע ע"י מנהל.', 'kivun' ); ?></p>
								<?php endif; ?>
								<?php if ( $can_upload ) : ?>
									<div class="kivun-cc-ai">
										<?php if ( ! $ai_configured ) : ?>
											<p class="kivun-cc-ai-warn"><?php esc_html_e( '⚠️ היצירה האוטומטית אינה פעילה כרגע (לא הוגדר מפתח API / נגמרו הקרדיטים). פנו למנהל האתר.', 'kivun' ); ?></p>
										<?php endif; ?>
										<label class="kivun-cc-sub"><?php esc_html_e( 'סגנון התמונה', 'kivun' ); ?></label>
										<select class="kivun-cc-input kivun-cc-ai-style">
											<?php foreach ( Kivun_AI_Image::styles() as $skey => $slabel ) : ?>
												<option value="<?php echo esc_attr( $skey ); ?>"><?php echo esc_html( $slabel ); ?></option>
											<?php endforeach; ?>
										</select>
										<label class="kivun-cc-sub"><?php esc_html_e( 'תיאור חופשי לתמונה (אופציונלי)', 'kivun' ); ?></label>
										<textarea class="kivun-cc-input kivun-cc-textarea kivun-cc-ai-prompt" rows="2" placeholder="<?php esc_attr_e( 'אם ריק — התמונה תיווצר מהכותרת והתיאור הקצר של העמוד', 'kivun' ); ?>"></textarea>
										<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-cc-ai-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_image' ) ); ?>">
											<?php esc_html_e( '✨ צור תמונה עם AI', 'kivun' ); ?>
										</button>
										<span class="kivun-cc-ai-status" role="status" aria-live="polite"></span>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label" for="kivun-ccf-status"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></label>
							<select id="kivun-ccf-status" name="status" class="kivun-cc-input">
								<option value="publish" <?php selected( $v['status'], 'publish' ); ?>><?php esc_html_e( 'מפורסם', 'kivun' ); ?></option>
								<option value="draft" <?php selected( $v['status'], 'draft' ); ?>><?php esc_html_e( 'טיוטה', 'kivun' ); ?></option>
							</select>
						</div>
					</div>
				</div>
			</form>

			<?php if ( $editing && $can_delete ) : ?>
				<div class="kivun-cc-card kivun-cc-danger">
					<div>
						<strong class="kivun-cc-danger__title"><?php esc_html_e( 'מחיקת התוכן', 'kivun' ); ?></strong>
						<p class="kivun-cc-hint"><?php esc_html_e( 'מחיקה תעביר לפח את כל הפוסטים המקושרים (דף נחיתה / קורס / סדנה) יחד.', 'kivun' ); ?></p>
					</div>
					<?php echo self::delete_form( $group, $page_url, __( 'מחיקת כל התוכן', 'kivun' ), 'kivun-cc-btn kivun-cc-btn--danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in delete_form(). ?>
				</div>
			<?php endif; ?>

		</div>

		<?php
	}


	/**
	 * Render the content library: every content group this console created,
	 * with its status, live registration count and management actions.
	 *
	 * @param string $page_url   The current page URL (edit links point back here).
	 * @param bool   $can_delete Whether the user may delete content groups.
	 * @return void
	 */
	private static function front_library( string $page_url, bool $can_delete = false ): void {
		$posts = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$labels = array(
			'kivun_workshop' => __( 'דף נחיתה', 'kivun' ),
			'kivun_course'   => __( 'קורס', 'kivun' ),
			'kivun_session'  => __( 'סדנה', 'kivun' ),
			'kivun_event'    => __( 'אירוע', 'kivun' ),
		);

		$groups = array();
		foreach ( $posts as $p ) {
			$g = (string) get_post_meta( $p->ID, self::GROUP_META, true );
			if ( '' === $g ) {
				continue;
			}
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array(
					'title'   => get_the_title( $p->ID ),
					'types'   => array(),
					'ids'     => array(),
					'view_id' => (int) $p->ID,
					'status'  => $p->post_status,
					'date'    => get_the_date( 'd/m/Y', $p->ID ),
				);
			}
			$groups[ $g ]['types'][] = $labels[ $p->post_type ] ?? $p->post_type;
			$groups[ $g ]['ids'][]   = (int) $p->ID;
			// A group counts as published as soon as any of its posts is live.
			if ( 'publish' === $p->post_status ) {
				$groups[ $g ]['status'] = 'publish';
			}
		}

		$lead_counts = self::leads_per_content();
		?>
		<div class="kivun-cc-front">
			<div class="kivun-cc-head">
				<h2 class="kivun-cc-title"><?php esc_html_e( 'התכנים שלי', 'kivun' ); ?></h2>
				<p class="kivun-cc-lead"><?php esc_html_e( 'כל התכנים שנוצרו כאן — עריכה, שכפול, צפייה ומחיקה, ומספר הפניות שהתקבלו לכל תוכן.', 'kivun' ); ?></p>
			</div>

			<?php if ( ! $groups ) : ?>
				<div class="kivun-cc-note"><?php esc_html_e( 'עדיין לא נוצרו תכנים. עברו ללשונית "פרסום תוכן" כדי להתחיל.', 'kivun' ); ?></div>
			<?php else : ?>
				<div class="kivun-cc-tablewrap">
				<table class="kivun-cc-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'כותרת', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'סוגים', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'פניות', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $groups as $gid => $g ) :
						$view_url = (string) get_permalink( (int) $g['view_id'] );

						$group_leads = 0;
						foreach ( $g['ids'] as $gpid ) {
							$group_leads += (int) ( $lead_counts[ $gpid ] ?? 0 );
						}

						$edit_url = add_query_arg(
							'kivun_group',
							rawurlencode( (string) $gid ),
							remove_query_arg( array( 'kivun_saved', 'kivun_cc_error', 'kivun_deleted', 'kivun_duplicated' ), $page_url )
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( $g['title'] ); ?></strong></td>
							<td>
								<?php foreach ( array_unique( $g['types'] ) as $type_label ) : ?>
									<span class="kivun-cc-badge"><?php echo esc_html( $type_label ); ?></span>
								<?php endforeach; ?>
							</td>
							<td>
								<span class="kivun-status kivun-status--<?php echo esc_attr( $g['status'] ); ?>">
									<?php echo esc_html( 'publish' === $g['status'] ? __( 'פורסם', 'kivun' ) : __( 'טיוטה', 'kivun' ) ); ?>
								</span>
							</td>
							<td>
								<?php if ( $group_leads ) : ?>
									<a class="kivun-cc-leadlink" href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'kivun_tab' => 'leads',
												'kivun_content' => (int) $g['view_id'],
											),
											$page_url
										)
									);
									?>
																		">
										<?php echo esc_html( number_format_i18n( $group_leads ) ); ?>
									</a>
								<?php else : ?>
									<span class="kivun-muted" aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $g['date'] ); ?></td>
							<td class="kivun-cc-rowactions">
								<?php if ( '' !== $view_url ) : ?>
									<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'צפייה ↗', 'kivun' ); ?></a>
								<?php endif; ?>
								<a class="kivun-cc-btn kivun-cc-btn--sm" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'עריכה', 'kivun' ); ?></a>
								<?php echo self::duplicate_form( (int) ( $g['view_id'] ?? 0 ), $page_url, 'kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in duplicate_form(). ?>
								<?php if ( $can_delete ) : ?>
									<?php echo self::delete_form( (string) $gid, $page_url, __( 'מחיקה', 'kivun' ), 'kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in delete_form(). ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Registration/lead totals keyed by content post ID, for the library table.
	 *
	 * @return array<int,int>
	 */
	private static function leads_per_content(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT course_id, COUNT(*) AS total
			 FROM {$wpdb->prefix}kivun_registrations
			 WHERE course_id > 0
			 GROUP BY course_id"
		);

		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ (int) $r->course_id ] = (int) $r->total;
		}
		return $counts;
	}

	/**
	 * Render the leads / registrations CRM on the front end: the same data and
	 * inline editing as the wp-admin page, so managing enquiries never requires
	 * opening wp-admin.
	 *
	 * @param string $page_url The current page URL (filters post back here).
	 * @return void
	 */
	private static function front_leads( string $page_url ): void {
		global $wpdb;

		$statuses    = Kivun_Admin::reg_statuses();
		$type_labels = Kivun_Admin::reg_type_labels();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing filters.
		$content_filter = isset( $_GET['kivun_content'] ) ? absint( wp_unslash( $_GET['kivun_content'] ) ) : 0;
		$type_filter    = isset( $_GET['kivun_ltype'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_ltype'] ) ) : '';
		$status_filter  = isset( $_GET['kivun_lstatus'] ) ? sanitize_key( wp_unslash( $_GET['kivun_lstatus'] ) ) : '';
		$search         = isset( $_GET['kivun_ls'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_ls'] ) ) : '';
		$per_page       = isset( $_GET['kivun_lpp'] ) ? absint( wp_unslash( $_GET['kivun_lpp'] ) ) : 25;
		$paged          = isset( $_GET['kivun_lpaged'] ) ? max( 1, absint( wp_unslash( $_GET['kivun_lpaged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $per_page, array( 25, 50, 100, 200 ), true ) ) {
			$per_page = 25;
		}

		$conds = array();
		if ( $content_filter ) {
			$conds[] = $wpdb->prepare( 'r.course_id = %d', $content_filter );
		}
		if ( '' !== $type_filter && isset( $type_labels[ $type_filter ] ) ) {
			$conds[] = $wpdb->prepare( 'r.type = %s', $type_filter );
		}
		if ( '' !== $status_filter && isset( $statuses[ $status_filter ] ) ) {
			$conds[] = $wpdb->prepare( 'r.status = %s', $status_filter );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$conds[] = $wpdb->prepare( '(r.name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s)', $like, $like, $like );
		}
		$where = $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '';

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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total_pages = max( 1, (int) ceil( $found / $per_page ) );
		$paged       = min( $paged, $total_pages );

		$contents = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$can_delete_rows = current_user_can( 'manage_options' );

		// Base args preserved across filtering and paging.
		$base_args = array( 'kivun_tab' => 'leads' );
		if ( $content_filter ) {
			$base_args['kivun_content'] = $content_filter;
		}
		if ( '' !== $type_filter ) {
			$base_args['kivun_ltype'] = $type_filter;
		}
		if ( '' !== $status_filter ) {
			$base_args['kivun_lstatus'] = $status_filter;
		}
		if ( '' !== $search ) {
			$base_args['kivun_ls'] = $search;
		}
		$base_args['kivun_lpp'] = $per_page;

		$page_link = static function ( int $p ) use ( $base_args, $page_url ): string {
			return add_query_arg( array_merge( $base_args, array( 'kivun_lpaged' => $p ) ), $page_url );
		};
		?>
		<div class="kivun-cc-front">
			<div class="kivun-cc-head">
				<h2 class="kivun-cc-title"><?php esc_html_e( 'לידים והרשמות', 'kivun' ); ?></h2>
				<p class="kivun-cc-lead"><?php esc_html_e( 'כל הפניות שהתקבלו מהתכנים — עדכון סטטוס והערות נשמרים אוטומטית.', 'kivun' ); ?></p>
			</div>

			<div class="kivun-cc-leadbar">
				<span class="kivun-stat"><strong><?php echo esc_html( number_format_i18n( $found ) ); ?></strong> <?php esc_html_e( 'רשומות בתצוגה', 'kivun' ); ?></span>
				<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( Kivun_Export::url( 'registrations', $content_filter ) ); ?>">
					⬇ <?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?>
				</a>
			</div>

			<form class="kivun-cc-leadfilters" method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="kivun_tab" value="leads">

				<label class="kivun-sr-only" for="kivun-lf-content"><?php esc_html_e( 'סינון לפי תוכן', 'kivun' ); ?></label>
				<select id="kivun-lf-content" name="kivun_content">
					<option value="0"><?php esc_html_e( 'כל התכנים', 'kivun' ); ?></option>
					<?php foreach ( $contents as $c ) : ?>
						<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $content_filter, $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="kivun-sr-only" for="kivun-lf-type"><?php esc_html_e( 'סינון לפי סוג', 'kivun' ); ?></label>
				<select id="kivun-lf-type" name="kivun_ltype">
					<option value=""><?php esc_html_e( 'כל הסוגים', 'kivun' ); ?></option>
					<?php foreach ( $type_labels as $tv => $tl ) : ?>
						<option value="<?php echo esc_attr( $tv ); ?>" <?php selected( $type_filter, $tv ); ?>><?php echo esc_html( $tl ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="kivun-sr-only" for="kivun-lf-status"><?php esc_html_e( 'סינון לפי סטטוס', 'kivun' ); ?></label>
				<select id="kivun-lf-status" name="kivun_lstatus">
					<option value=""><?php esc_html_e( 'כל הסטטוסים', 'kivun' ); ?></option>
					<?php foreach ( $statuses as $sv => $sl ) : ?>
						<option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $status_filter, $sv ); ?>><?php echo esc_html( $sl ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="kivun-sr-only" for="kivun-lf-search"><?php esc_html_e( 'חיפוש', 'kivun' ); ?></label>
				<input type="search" id="kivun-lf-search" name="kivun_ls" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'שם, אימייל או טלפון…', 'kivun' ); ?>">

				<label class="kivun-sr-only" for="kivun-lf-pp"><?php esc_html_e( 'רשומות בעמוד', 'kivun' ); ?></label>
				<select id="kivun-lf-pp" name="kivun_lpp">
					<?php foreach ( array( 25, 50, 100, 200 ) as $pp ) : ?>
						<option value="<?php echo esc_attr( $pp ); ?>" <?php selected( $per_page, $pp ); ?>>
							<?php
							/* translators: %d: number of rows shown per page. */
							echo esc_html( sprintf( __( '%d בעמוד', 'kivun' ), $pp ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm"><?php esc_html_e( 'סינון', 'kivun' ); ?></button>
				<?php if ( $content_filter || '' !== $type_filter || '' !== $status_filter || '' !== $search ) : ?>
					<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( add_query_arg( 'kivun_tab', 'leads', $page_url ) ); ?>"><?php esc_html_e( 'ניקוי', 'kivun' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( ! $rows ) : ?>
				<div class="kivun-cc-note"><?php esc_html_e( 'לא נמצאו רשומות תואמות.', 'kivun' ); ?></div>
			<?php else : ?>
				<div class="kivun-cc-tablewrap">
				<table class="kivun-cc-table kivun-cc-leadtable">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'שם', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'תוכן', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'סוג', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'יצירת קשר', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'עיר', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'דיוור', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'הערות', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
							<?php if ( $can_delete_rows ) : ?>
								<th scope="col"><?php esc_html_e( 'מחיקה', 'kivun' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
							<td>
								<?php
								$row_title = $r->course_title ? $r->course_title : __( '(נמחק)', 'kivun' );
								$row_link  = $r->course_id ? (string) get_permalink( (int) $r->course_id ) : '';
								if ( $row_link ) :
									?>
									<a href="<?php echo esc_url( $row_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row_title ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $r->source ) ) : ?>
									<span class="kivun-cc-source"><?php echo esc_html( (string) $r->source ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="kivun-cc-badge"><?php echo esc_html( $type_labels[ $r->type ?? 'registration' ] ?? (string) $r->type ); ?></span></td>
							<td class="kivun-app-contact">
								<a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a>
								<?php if ( $r->phone ) : ?>
									<a href="tel:<?php echo esc_attr( $r->phone ); ?>"><?php echo esc_html( $r->phone ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $r->city ?? '' ) ); ?></td>
							<td><?php echo esc_html( empty( $r->marketing_consent ) ? '—' : '✓' ); ?></td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo Kivun_Admin::notes_input( 'registrations', (int) $r->id, (string) ( $r->notes ?? '' ) );
								?>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
							</td>
							<td class="kivun-cc-date"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $r->created_at ) ) ); ?></td>
							<td>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo Kivun_Admin::status_select( 'registrations', (int) $r->id, (string) $r->status, $statuses );
								?>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
							</td>
							<?php if ( $can_delete_rows ) : ?>
								<td>
									<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--danger kivun-delete-row" data-table="registrations" data-id="<?php echo esc_attr( $r->id ); ?>">
										<?php esc_html_e( 'מחיקה', 'kivun' ); ?>
									</button>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="kivun-cc-pager">
						<?php if ( $paged > 1 ) : ?>
							<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $page_link( $paged - 1 ) ); ?>">‹ <?php esc_html_e( 'הקודם', 'kivun' ); ?></a>
						<?php endif; ?>
						<span class="kivun-cc-pager__state">
							<?php
							/* translators: 1: current page, 2: total pages. */
							echo esc_html( sprintf( __( 'עמוד %1$d מתוך %2$d', 'kivun' ), $paged, $total_pages ) );
							?>
						</span>
						<?php if ( $paged < $total_pages ) : ?>
							<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $page_link( $paged + 1 ) ); ?>"><?php esc_html_e( 'הבא', 'kivun' ); ?> ›</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
