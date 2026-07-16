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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; loads a record to edit.
		$group       = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		$group_posts = self::group_posts( $group );
		$editing     = (bool) $group_posts;
		$v           = self::form_values( $group_posts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag from a safe redirect.
		$saved     = ! empty( $_GET['kivun_saved'] );
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
							<input type="text" name="audience" class="kivun-lp-input" value="<?php echo esc_attr( $v['audience'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
							<input type="text" name="duration" class="kivun-lp-input" value="<?php echo esc_attr( $v['duration'] ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
							<input type="text" name="cost" class="kivun-lp-input" value="<?php echo esc_attr( $v['cost'] ); ?>" placeholder="<?php esc_attr_e( 'חינם / 120 ₪', 'kivun' ); ?>">
							<label class="kivun-lp-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
							<input type="text" name="date" class="kivun-lp-input" value="<?php echo esc_attr( $v['date'] ); ?>" placeholder="<?php esc_attr_e( '15.9.2025 בשעה 18:00', 'kivun' ); ?>">
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
			'audience' => isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( $_POST['audience'] ) ) : '',
			'duration' => isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : '',
			'cost'     => isset( $_POST['cost'] ) ? sanitize_text_field( wp_unslash( $_POST['cost'] ) ) : '',
			'date'     => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller.
		return isset( $_POST['thumbnail_id'] ) ? absint( wp_unslash( $_POST['thumbnail_id'] ) ) : 0;
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

		ob_start();
		self::front_form();
		return (string) ob_get_clean();
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
	 * A styled "please log in" notice with a login link back to this page.
	 *
	 * @return string
	 */
	private static function front_login_notice(): string {
		$here  = (string) get_permalink();
		$here  = $here ? $here : home_url();
		$login = wp_login_url( $here );
		return '<div class="kivun-cc-front"><div class="kivun-cc-note">'
			. esc_html__( 'כדי להזין תוכן יש להתחבר תחילה.', 'kivun' )
			. ' <a class="kivun-cc-btn kivun-cc-btn--sm" href="' . esc_url( $login ) . '">' . esc_html__( 'התחברות', 'kivun' ) . '</a>'
			. '</div></div>';
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

		$page_url   = (string) get_permalink();
		$page_url   = $page_url ? $page_url : home_url();
		$can_upload = current_user_can( 'upload_files' );
		$can_delete = current_user_can( 'delete_posts' );
		$thumb_url  = (int) $v['thumb_id'] ? (string) wp_get_attachment_image_url( (int) $v['thumb_id'], 'medium' ) : '';
		$sections   = array(
			'landing' => __( 'דף נחיתה', 'kivun' ),
			'course'  => __( 'קורס', 'kivun' ),
			'session' => __( 'סדנה', 'kivun' ),
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

			<form class="kivun-cc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_create_content_front">
				<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
				<input type="hidden" name="redirect" value="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'kivun_create_content_front', 'kivun_cc_front_nonce' ); ?>

				<div class="kivun-cc-grid">
					<div class="kivun-cc-main">

						<div class="kivun-cc-card">
							<label class="kivun-cc-label" for="kivun-ccf-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-cc-req">*</span></label>
							<input type="text" id="kivun-ccf-title" name="title" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['title'] ); ?>" required>

							<label class="kivun-cc-sub" for="kivun-ccf-slug"><?php esc_html_e( 'כתובת URL (Slug)', 'kivun' ); ?></label>
							<input type="text" id="kivun-ccf-slug" name="slug" class="kivun-cc-input" dir="ltr" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="my-content">
							<p class="kivun-cc-hint"><?php esc_html_e( 'ריק = נוצר אוטומטית מהכותרת.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label" for="kivun-ccf-short"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></label>
							<textarea id="kivun-ccf-short" name="short" class="kivun-cc-input kivun-cc-textarea" rows="3"><?php echo esc_textarea( $v['short'] ); ?></textarea>

							<label class="kivun-cc-label" for="kivun-ccf-long" style="margin-top:1rem"><?php esc_html_e( 'תיאור מלא', 'kivun' ); ?></label>
							<textarea id="kivun-ccf-long" name="long" class="kivun-cc-input kivun-cc-textarea" rows="9"><?php echo esc_textarea( $v['long'] ); ?></textarea>
						</div>

						<div class="kivun-cc-card">
							<label class="kivun-cc-label"><?php esc_html_e( 'פרטים', 'kivun' ); ?></label>
							<div class="kivun-cc-row">
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
									<input type="text" name="audience" class="kivun-cc-input" value="<?php echo esc_attr( $v['audience'] ); ?>">
								</div>
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'משך', 'kivun' ); ?></label>
									<input type="text" name="duration" class="kivun-cc-input" value="<?php echo esc_attr( $v['duration'] ); ?>">
								</div>
							</div>
							<div class="kivun-cc-row">
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
									<input type="text" name="cost" class="kivun-cc-input" value="<?php echo esc_attr( $v['cost'] ); ?>" placeholder="<?php esc_attr_e( 'חינם / 120 ₪', 'kivun' ); ?>">
								</div>
								<div>
									<label class="kivun-cc-sub"><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
									<input type="text" name="date" class="kivun-cc-input" value="<?php echo esc_attr( $v['date'] ); ?>" placeholder="<?php esc_attr_e( '15.9.2025 בשעה 18:00', 'kivun' ); ?>">
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
								<?php if ( $can_upload ) : ?>
									<input type="file" name="thumbnail_file" accept="image/*" class="kivun-cc-file">
								<?php else : ?>
									<p class="kivun-cc-hint"><?php esc_html_e( 'אין לך הרשאה להעלות קבצים — התמונה תיקבע ע"י מנהל.', 'kivun' ); ?></p>
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

			<?php self::front_groups_list( $page_url, $can_delete ); ?>
		</div>

		<script>
		( function () {
			var scope = document;
			scope.querySelectorAll( '.kivun-cc-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var sec = scope.querySelector( '.kivun-cc-section[data-type="' + cb.dataset.type + '"]' );
					if ( sec ) { sec.hidden = ! cb.checked; }
				} );
			} );
			var file = scope.querySelector( '.kivun-cc-file' );
			if ( file ) {
				file.addEventListener( 'change', function () {
					var box = scope.querySelector( '.kivun-cc-media__preview' ),
						img = box ? box.querySelector( 'img' ) : null;
					if ( file.files && file.files[0] && img && window.FileReader ) {
						var r = new FileReader();
						r.onload = function ( e ) { img.src = e.target.result; box.style.display = ''; };
						r.readAsDataURL( file.files[0] );
					}
				} );
			}
			var confirmMsg = '<?php echo esc_js( __( 'למחוק את כל התוכן המקושר? הפעולה תעביר לפח את דף הנחיתה, הקורס והסדנה.', 'kivun' ) ); ?>';
			scope.querySelectorAll( '.kivun-cc-delete-form' ).forEach( function ( f ) {
				f.addEventListener( 'submit', function ( e ) {
					if ( ! window.confirm( confirmMsg ) ) { e.preventDefault(); }
				} );
			} );
		} () );
		</script>
		<?php
	}

	/**
	 * Render the "content groups" list with front-end edit (and delete) links.
	 *
	 * @param string $page_url   The current page URL (edit links point back here).
	 * @param bool   $can_delete Whether the user may delete content groups.
	 * @return void
	 */
	private static function front_groups_list( string $page_url, bool $can_delete = false ): void {
		$posts = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'         => 100, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
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
					'title'   => get_the_title( $p->ID ),
					'types'   => array(),
					'view_id' => (int) $p->ID,
				);
			}
			$groups[ $g ]['types'][] = $labels[ $p->post_type ] ?? $p->post_type;
		}
		if ( ! $groups ) {
			return;
		}
		?>
		<div class="kivun-cc-groups">
			<h3 class="kivun-cc-h3"><?php esc_html_e( 'תכנים קיימים (עריכה)', 'kivun' ); ?></h3>
			<ul class="kivun-cc-groups__list">
				<?php foreach ( $groups as $gid => $g ) : ?>
					<li class="kivun-cc-groups__item">
						<span class="kivun-cc-groups__title"><?php echo esc_html( $g['title'] ); ?></span>
						<span class="kivun-cc-groups__types"><?php echo esc_html( implode( ' · ', array_unique( $g['types'] ) ) ); ?></span>
						<span class="kivun-cc-groups__actions">
							<?php $group_view_url = isset( $g['view_id'] ) ? (string) get_permalink( (int) $g['view_id'] ) : ''; ?>
							<?php if ( '' !== $group_view_url ) : ?>
								<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $group_view_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'צפייה ↗', 'kivun' ); ?></a>
							<?php endif; ?>
							<a class="kivun-cc-btn kivun-cc-btn--sm" href="<?php echo esc_url( add_query_arg( 'kivun_group', rawurlencode( $gid ), remove_query_arg( array( 'kivun_saved', 'kivun_cc_error', 'kivun_deleted' ), $page_url ) ) ); ?>"><?php esc_html_e( 'עריכה', 'kivun' ); ?></a>
							<?php if ( $can_delete ) : ?>
								<?php echo self::delete_form( (string) $gid, $page_url, __( 'מחיקה', 'kivun' ), 'kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in delete_form(). ?>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
