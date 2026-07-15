<?php
/**
 * Unified content creator & editor: one admin form that publishes and edits a
 * landing page, a course and/or a workshop from a single shared set of content.
 *
 * Posts created together are linked by a shared `_kivun_content_group` meta, so
 * editing the shared content (title, descriptions, image, audience) updates all
 * of them at once.
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
			'title'               => '',
			'short'               => '',
			'long'                => '',
			'audience'            => '',
			'status'              => 'publish',
			'thumb_id'            => '0',
			'landing_cta_title'   => '',
			'landing_cta_content' => '',
			'landing_cta_button'  => '',
			'landing_email'       => '',
			'course_price'        => '0',
			'course_schedule'     => '',
			'course_duration'     => '',
			'course_capacity'     => '',
			'course_email'        => '',
			'session_date'        => '',
			'session_duration'    => '',
			'session_location'    => '',
			'session_capacity'    => '',
			'session_email'       => '',
			'session_valid_until' => '',
		);

		if ( ! $group_posts ) {
			return $v;
		}

		// Shared content comes from the primary (first available) post.
		$primary_key   = array_key_first( $group_posts );
		$primary       = (int) $group_posts[ $primary_key ];
		$v['title']    = get_the_title( $primary );
		$v['long']     = (string) get_post_field( 'post_content', $primary );
		$v['status']   = (string) get_post_status( $primary );
		$v['thumb_id'] = (string) (int) get_post_thumbnail_id( $primary );

		if ( 'landing' === $primary_key ) {
			$v['short']    = (string) get_post_meta( $primary, '_kivun_lp_short', true );
			$v['audience'] = (string) get_post_meta( $primary, '_kivun_ws_audience', true );
		} elseif ( 'course' === $primary_key ) {
			$v['short']    = (string) get_post_field( 'post_excerpt', $primary );
			$v['audience'] = (string) get_post_meta( $primary, '_kivun_target_audience', true );
		} else {
			$v['short']    = (string) get_post_meta( $primary, '_kivun_session_short', true );
			$v['audience'] = (string) get_post_meta( $primary, '_kivun_session_audience', true );
		}

		if ( isset( $group_posts['landing'] ) ) {
			$lid                      = $group_posts['landing'];
			$v['landing_cta_title']   = (string) get_post_meta( $lid, '_kivun_cta_title', true );
			$v['landing_cta_content'] = (string) get_post_meta( $lid, '_kivun_cta_content', true );
			$v['landing_cta_button']  = (string) get_post_meta( $lid, '_kivun_cta_button', true );
			$v['landing_email']       = (string) get_post_meta( $lid, '_kivun_contact_email', true );
		}
		if ( isset( $group_posts['course'] ) ) {
			$cid                  = $group_posts['course'];
			$v['course_price']    = (string) get_post_meta( $cid, '_kivun_price', true );
			$v['course_schedule'] = (string) get_post_meta( $cid, '_kivun_schedule', true );
			$v['course_duration'] = (string) get_post_meta( $cid, '_kivun_duration', true );
			$v['course_capacity'] = (string) get_post_meta( $cid, '_kivun_capacity', true );
			$v['course_email']    = (string) get_post_meta( $cid, '_kivun_contact_email', true );
		}
		if ( isset( $group_posts['session'] ) ) {
			$sid                      = $group_posts['session'];
			$v['session_date']        = (string) get_post_meta( $sid, '_kivun_session_date', true );
			$v['session_duration']    = (string) get_post_meta( $sid, '_kivun_session_duration', true );
			$v['session_location']    = (string) get_post_meta( $sid, '_kivun_session_location', true );
			$v['session_capacity']    = (string) get_post_meta( $sid, '_kivun_capacity', true );
			$v['session_email']       = (string) get_post_meta( $sid, '_kivun_contact_email', true );
			$v['session_valid_until'] = (string) get_post_meta( $sid, '_kivun_session_valid_until', true );
		}

		return $v;
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; loads a record to edit.
		$group       = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		$group_posts = self::group_posts( $group );
		$editing     = (bool) $group_posts;
		$v           = self::form_values( $group_posts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag from a safe redirect.
		$saved = ! empty( $_GET['kivun_saved'] );

		$sections  = array(
			'landing' => __( 'דף נחיתה', 'kivun' ),
			'course'  => __( 'קורס', 'kivun' ),
			'session' => __( 'סדנה', 'kivun' ),
		);
		$thumb_url = (int) $v['thumb_id'] ? (string) wp_get_attachment_image_url( (int) $v['thumb_id'], 'medium' ) : '';
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

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-cc-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-lp-req">*</span></label>
							<input type="text" id="kivun-cc-title" name="title" class="kivun-lp-input kivun-lp-input--lg" value="<?php echo esc_attr( $v['title'] ); ?>" required>
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
							<label class="kivun-lp-label"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
							<input type="text" name="audience" class="kivun-lp-input" value="<?php echo esc_attr( $v['audience'] ); ?>" placeholder="<?php esc_attr_e( 'למשל: מחפשי עבודה', 'kivun' ); ?>">
						</div>

						<!-- Landing-specific -->
						<div class="kivun-lp-card kivun-cc-section" data-type="landing" <?php echo isset( $group_posts['landing'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'פרטי דף נחיתה', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — כותרת', 'kivun' ); ?></label>
							<input type="text" name="landing_cta_title" class="kivun-lp-input" value="<?php echo esc_attr( $v['landing_cta_title'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — תוכן', 'kivun' ); ?></label>
							<textarea name="landing_cta_content" class="kivun-lp-input" rows="2"><?php echo esc_textarea( $v['landing_cta_content'] ); ?></textarea>
							<label class="kivun-lp-sub"><?php esc_html_e( 'CTA — טקסט כפתור', 'kivun' ); ?></label>
							<input type="text" name="landing_cta_button" class="kivun-lp-input" value="<?php echo esc_attr( $v['landing_cta_button'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת לידים', 'kivun' ); ?></label>
							<input type="email" name="landing_email" class="kivun-lp-input" value="<?php echo esc_attr( $v['landing_email'] ); ?>">
						</div>

						<!-- Course-specific -->
						<div class="kivun-lp-card kivun-cc-section" data-type="course" <?php echo isset( $group_posts['course'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'פרטי קורס', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'עלות (₪, 0 = חינם)', 'kivun' ); ?></label>
							<input type="number" name="course_price" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['course_price'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'זמנים / מועדים', 'kivun' ); ?></label>
							<input type="text" name="course_schedule" class="kivun-lp-input" value="<?php echo esc_attr( $v['course_schedule'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" name="course_duration" class="kivun-lp-input" value="<?php echo esc_attr( $v['course_duration'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="course_capacity" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['course_capacity'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></label>
							<input type="email" name="course_email" class="kivun-lp-input" value="<?php echo esc_attr( $v['course_email'] ); ?>">
						</div>

						<!-- Workshop-specific -->
						<div class="kivun-lp-card kivun-cc-section" data-type="session" <?php echo isset( $group_posts['session'] ) ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'פרטי סדנה', 'kivun' ); ?></h3>
							<label class="kivun-lp-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
							<input type="text" name="session_date" class="kivun-lp-input" value="<?php echo esc_attr( $v['session_date'] ); ?>" placeholder="<?php esc_attr_e( 'למשל: 15.9.2025 בשעה 18:00', 'kivun' ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" name="session_duration" class="kivun-lp-input" value="<?php echo esc_attr( $v['session_duration'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
							<input type="text" name="session_location" class="kivun-lp-input" value="<?php echo esc_attr( $v['session_location'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
							<input type="number" name="session_capacity" class="kivun-lp-input" min="0" value="<?php echo esc_attr( $v['session_capacity'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'אימייל לקבלת הרשמות', 'kivun' ); ?></label>
							<input type="email" name="session_email" class="kivun-lp-input" value="<?php echo esc_attr( $v['session_email'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'תוקף ההרשמה (עד תאריך)', 'kivun' ); ?></label>
							<input type="date" name="session_valid_until" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['session_valid_until'] ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'אחרי תאריך זה ההרשמה נסגרת. לפתיחת מחזור חדש — עדכנו את התאריך.', 'kivun' ); ?></p>
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
			document.querySelectorAll( '.kivun-cc-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var sec = document.querySelector( '.kivun-cc-section[data-type="' + cb.dataset.type + '"]' );
					if ( sec ) { sec.hidden = ! cb.checked; }
				} );
			} );
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
		$group   = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		if ( '' === trim( $group ) ) {
			$group = wp_generate_uuid4();
		}

		$thumb_id    = self::resolve_thumbnail();
		$group_posts = self::group_posts( $group );
		$shared      = compact( 'title', 'long', 'status' );

		if ( in_array( 'landing', $publish, true ) ) {
			self::upsert(
				$group,
				'kivun_workshop',
				$shared,
				'',
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
			self::upsert(
				$group,
				'kivun_course',
				$shared,
				$short,
				$thumb_id,
				array(
					'_kivun_target_audience' => $audience,
					'_kivun_schedule'        => sanitize_text_field( wp_unslash( $_POST['course_schedule'] ?? '' ) ),
					'_kivun_duration'        => sanitize_text_field( wp_unslash( $_POST['course_duration'] ?? '' ) ),
					'_kivun_price'           => absint( $_POST['course_price'] ?? 0 ),
					'_kivun_capacity'        => absint( $_POST['course_capacity'] ?? 0 ),
					'_kivun_contact_email'   => sanitize_email( wp_unslash( $_POST['course_email'] ?? '' ) ),
				)
			);
		}

		if ( in_array( 'session', $publish, true ) ) {
			self::upsert(
				$group,
				'kivun_session',
				$shared,
				'',
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

		unset( $group_posts );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'group'       => rawurlencode( $group ),
					'kivun_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Create or update the post of a given type inside a content group.
	 *
	 * @param string $group     The content group id.
	 * @param string $post_type The target post type.
	 * @param array  $shared    Shared fields: title, long, status.
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
