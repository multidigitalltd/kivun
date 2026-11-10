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
	 * Meta key marking content put away in the library's archive.
	 *
	 * Listing only. The pages stay published and reachable, their forms keep
	 * taking enquiries, and every lead already filed against them is
	 * untouched — this says where the content appears on the management
	 * screen and nothing more.
	 */
	const ARCHIVED_META = '_kivun_archived';

	/**
	 * Where the console was last seen, so invitations can link to it.
	 */
	const CONSOLE_URL_OPTION = 'kivun_console_url';

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

		add_action( 'wp_ajax_kivun_archive_content', array( __CLASS__, 'ajax_archive_content' ) );
		add_action( 'wp_ajax_kivun_add_leads_viewer', array( __CLASS__, 'ajax_add_leads_viewer' ) );
		add_action( 'wp_ajax_kivun_remove_leads_viewer', array( __CLASS__, 'ajax_remove_leads_viewer' ) );

		add_filter( 'teeny_mce_before_init', array( __CLASS__, 'wizard_editor_init' ), 10, 2 );
	}

	/**
	 * The wizard's two editors, set up the way the publish handoff draws them.
	 *
	 * Only the order of the toolbar changes, plus one button: the design
	 * groups it as type (B I U S), blocks (quote, lists), alignment and
	 * direction, then links and history, and adds a left-to-right toggle for
	 * a paragraph in English. That toggle is TinyMCE's own `ltr` button from
	 * the `directionality` plugin WordPress ships — set once, it marks the
	 * paragraph `dir="ltr"`; pressed again, it takes the mark off.
	 *
	 * Everything already on the toolbar stays on it. The buttons the design
	 * names are put in its order, and anything else — something another
	 * plugin added through `teeny_mce_buttons` — keeps its place at the end
	 * rather than disappearing because this list did not know its name.
	 *
	 * Scoped to these two editor ids. Every other editor on the site, the
	 * jobs board's included, is handed back exactly as it came.
	 *
	 * @param array<string,mixed> $init      The teenyMCE settings.
	 * @param string              $editor_id Which editor they are for.
	 * @return array<string,mixed>
	 */
	public static function wizard_editor_init( $init, $editor_id ) {
		if ( ! is_array( $init ) || ! in_array( $editor_id, array( 'kivun_ccf_short', 'kivun_ccf_long' ), true ) ) {
			return $init;
		}

		$groups = array(
			array( 'bold', 'italic', 'underline', 'strikethrough' ),
			array( 'blockquote', 'bullist', 'numlist' ),
			array( 'alignright', 'aligncenter', 'alignleft', 'ltr' ),
			array( 'link', 'undo', 'redo', 'fullscreen' ),
		);

		$current = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $init['toolbar1'] ?? '' ) ) ), 'strlen' ) );
		$current = array_values( array_diff( $current, array( '|' ) ) );
		$known   = array_merge( ...$groups );

		$toolbar = array();
		foreach ( $groups as $group ) {
			// The direction toggle is the one button this adds; the rest are
			// only placed if they were there to begin with.
			$present = array_values(
				array_filter(
					$group,
					static function ( $button ) use ( $current ) {
						return 'ltr' === $button || in_array( $button, $current, true );
					}
				)
			);
			if ( $present ) {
				if ( $toolbar ) {
					$toolbar[] = '|';
				}
				$toolbar = array_merge( $toolbar, $present );
			}
		}
		$rest = array_values( array_diff( $current, $known ) );
		if ( $rest ) {
			$toolbar[] = '|';
			$toolbar   = array_merge( $toolbar, $rest );
		}
		$init['toolbar1'] = implode( ',', $toolbar );

		$plugins = array_filter( array_map( 'trim', explode( ',', (string) ( $init['plugins'] ?? '' ) ) ), 'strlen' );
		if ( ! in_array( 'directionality', $plugins, true ) ) {
			$plugins[] = 'directionality';
		}
		$init['plugins'] = implode( ',', $plugins );

		// The editable area's own padding and type: 18px at 1.6, 18/22 in
		// from the edges. It lives in an iframe, so the page's stylesheet
		// cannot reach it — this is the only place it can be said.
		$style                 = 'body.mce-content-body{margin:0;padding:18px 22px;font-size:18px;line-height:1.6;color:#2e2e31}';
		$init['content_style'] = trim( ( isset( $init['content_style'] ) ? (string) $init['content_style'] . ' ' : '' ) . $style );

		return $init;
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
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
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
			'schedule'         => '',
			'whatsapp'         => '',
			'city'             => '',
			'thumb_id'         => '0',
			'share_id'         => '0',
			'course_capacity'  => '',
			'course_wc'        => '',
			'session_location' => '',
			'session_capacity' => '',
			'session_valid'    => '',
			'session_repeat'   => '',
			'session_next'     => '',
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
		$v['share_id'] = (string) (int) get_post_meta( $primary, Kivun_Share_Image::META, true );
		$v['whatsapp'] = (string) get_post_meta( $primary, '_kivun_whatsapp', true );
		$v['city']     = (string) get_post_meta( $primary, '_kivun_city', true );
		if ( 'future' === $v['status'] ) {
			// datetime-local wants Y-m-d\TH:i, in site time.
			$v['schedule'] = str_replace( ' ', 'T', substr( (string) get_post_field( 'post_date', $primary ), 0, 16 ) );
		}

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
			$v['session_repeat']   = (string) get_post_meta( $group_posts['session'], '_kivun_session_recurring', true );
			$v['session_next']     = (string) get_post_meta( $group_posts['session'], '_kivun_session_next_open', true );
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
		$share_url     = (int) $v['share_id'] ? (string) wp_get_attachment_image_url( (int) $v['share_id'], 'medium' ) : '';
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
							<label class="kivun-lp-label"><?php esc_html_e( 'יצירת תוכן אוטומטית (AI)', 'kivun' ); ?></label>
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
									<?php echo kivun_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור תוכן', 'kivun' ); ?>
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
							<label class="kivun-lp-sub"><input type="checkbox" name="session_recurring" value="1" <?php checked( '1', $v['session_repeat'] ); ?>> <?php esc_html_e( 'סדנה קבועה (שוטפת) — חוזרת במחזורים', 'kivun' ); ?></label>
							<label class="kivun-lp-sub"><?php esc_html_e( 'המחזור הבא נפתח ב־', 'kivun' ); ?></label>
							<input type="date" name="session_next_open" class="kivun-lp-input" dir="ltr" value="<?php echo esc_attr( $v['session_next'] ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'אחרי תאריך התוקף ההרשמה נשמרת למחזור הבא. בסדנה קבועה הדף מציג מתי המחזור הבא נפתח. לפתיחת מחזור חדש — עדכנו את התאריכים.', 'kivun' ); ?></p>
						</div>

						<!-- Event-only extras -->
						<div class="kivun-lp-card kivun-cc-section" data-type="event" <?php echo isset( $group_posts['event'] ) ? '' : 'hidden'; ?>>
							<h3><?php echo kivun_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'אירוע — פרטים נוספים', 'kivun' ); ?></h3>
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
								<input type="hidden" class="kivun-lp-media__id" name="thumbnail_id" id="kivun-lp-thumb-id" value="<?php echo esc_attr( $v['thumb_id'] ); ?>">
								<?php // Without this the remove button beside it emptied the id and nothing else, and the save read that as "unchanged" rather than "remove". Pre-existing; found while giving the share image the same control. ?>
								<input type="hidden" name="remove_thumbnail" value="0" class="kivun-lp-media__flag">
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
											<?php echo kivun_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור תמונה עם AI', 'kivun' ); ?>
										</button>
									</p>
									<span class="kivun-cc-ai-status" style="font-size:12px;color:#555"></span>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'תמונה לשיתוף קישור', 'kivun' ); ?></label>
							<div class="kivun-lp-media">
								<p class="kivun-lp-hint"><?php esc_html_e( 'לא מוצגת בעמוד — רק בכרטיסיה שנפתחת כששולחים את הקישור. מומלץ 1200×630. בלעדיה תוצג התמונה הראשית.', 'kivun' ); ?></p>
								<div class="kivun-lp-media__preview" <?php echo $share_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $share_url ); ?>" alt=""></div>
								<input type="hidden" class="kivun-lp-media__id" name="share_image_id" value="<?php echo esc_attr( $v['share_id'] ); ?>">
								<input type="hidden" name="remove_share_image" value="0" class="kivun-lp-media__flag">
								<button type="button" class="button kivun-lp-media__select"><?php esc_html_e( 'בחירת תמונה', 'kivun' ); ?></button>
								<button type="button" class="button-link kivun-lp-media__remove" <?php echo $share_url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
								<p class="kivun-lp-hint"><?php esc_html_e( 'או העלאת קובץ:', 'kivun' ); ?></p>
								<input type="file" name="share_image_file" accept="image/*">
							</div>
						</div>

						<div class="kivun-lp-card">
							<label class="kivun-lp-label" for="kivun-lp-city"><?php esc_html_e( 'יישוב', 'kivun' ); ?></label>
							<input type="text" id="kivun-lp-city" name="city" class="kivun-lp-input" value="<?php echo esc_attr( $v['city'] ); ?>" placeholder="<?php esc_attr_e( 'למשל: ירושלים', 'kivun' ); ?>">
							<p class="kivun-lp-hint"><?php esc_html_e( 'נשלח למרכז כיוון. תוכן ללא יישוב לא יופיע שם בחיפוש לפי מרחק.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-lp-card kivun-cc-card">
							<label class="kivun-lp-label"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></label>
							<select name="status" class="kivun-lp-input kivun-cc-status">
								<option value="publish" <?php selected( $v['status'], 'publish' ); ?>><?php esc_html_e( 'מפורסם', 'kivun' ); ?></option>
								<option value="draft" <?php selected( $v['status'], 'draft' ); ?>><?php esc_html_e( 'טיוטה', 'kivun' ); ?></option>
								<option value="private" <?php selected( $v['status'], 'private' ); ?>><?php esc_html_e( 'פרטי', 'kivun' ); ?></option>
								<option value="future" <?php selected( $v['status'], 'future' ); ?>><?php esc_html_e( 'מתוזמן', 'kivun' ); ?></option>
							</select>
							<div class="kivun-cc-schedule" <?php echo 'future' === $v['status'] ? '' : 'hidden'; ?>>
								<label class="kivun-lp-label" for="kivun-lp-schedule"><?php esc_html_e( 'תאריך ושעת הפרסום', 'kivun' ); ?></label>
								<input type="datetime-local" id="kivun-lp-schedule" name="schedule" class="kivun-lp-input" value="<?php echo esc_attr( $v['schedule'] ); ?>">
							</div>
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
			// The publish-date field is only meaningful when scheduling. The
			// shared front-end script does not load on this screen, so the
			// toggle lives here.
			( function () {
				var statusSel = document.querySelector( '.kivun-cc-status' );
				if ( ! statusSel ) { return; }
				var sync = function () {
					var box = document.querySelector( '.kivun-cc-schedule' );
					if ( box ) { box.hidden = statusSel.value !== 'future'; }
				};
				statusSel.addEventListener( 'change', sync );
				sync();
			} )();
			// One description of what a media box is made of, because two
			// things drive one: the buttons inside it, and the AI button
			// further down that fills the featured image in. While the
			// parts were plain variables those two shared them by accident
			// of scope — and the moment a second box made that scope a
			// loop, the AI handler was reaching for names that no longer
			// existed. Named once, asked for twice, by whoever needs it.
			var kivunLpParts = function ( box ) {
				if ( ! box ) { return null; }
				var preview = box.querySelector( '.kivun-lp-media__preview' );
				return {
					select:  box.querySelector( '.kivun-lp-media__select' ),
					remove:  box.querySelector( '.kivun-lp-media__remove' ),
					id:      box.querySelector( '.kivun-lp-media__id' ),
					flag:    box.querySelector( '.kivun-lp-media__flag' ),
					file:    box.querySelector( 'input[type="file"]' ),
					preview: preview,
					img:     preview ? preview.querySelector( 'img' ) : null
				};
			};

			// The box a given field belongs to, for a caller that means one
			// of them in particular rather than all of them.
			var kivunLpBoxFor = function ( name ) {
				var field = document.querySelector( '.kivun-lp-media__id[name="' + name + '"]' );
				return field ? kivunLpParts( field.closest( '.kivun-lp-media' ) ) : null;
			};

			document.querySelectorAll( '.kivun-lp-media' ).forEach( function ( box ) {
				var frame,
					parts     = kivunLpParts( box ),
					selectBtn = parts.select,
					removeBtn = parts.remove,
					idInput   = parts.id,
					flag      = parts.flag,
					fileInput = parts.file,
					preview   = parts.preview,
					img       = parts.img;

				if ( ! idInput ) { return; }

				if ( selectBtn ) {
					selectBtn.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						if ( frame ) { frame.open(); return; }
						frame = wp.media( { multiple: false } );
						frame.on( 'select', function () {
							var att = frame.state().get( 'selection' ).first().toJSON();
							idInput.value = att.id;
							if ( flag ) { flag.value = '0'; }
							// resolve_image() takes an uploaded file over a
							// chosen id, so a file still staged here would
							// overrule the picture just picked.
							if ( fileInput ) { fileInput.value = ''; }
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
						if ( flag ) { flag.value = '1'; }
						// Same reason as above, and worse here: a file chosen
						// and then removed before saving would be installed
						// by the save as though it had never been removed.
						if ( fileInput ) { fileInput.value = ''; }
						if ( preview ) { preview.style.display = 'none'; }
						removeBtn.style.display = 'none';
					} );
				}
			} );

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
								var shot = kivunLpBoxFor( 'thumbnail_id' );
								if ( shot ) {
									if ( shot.id ) { shot.id.value = res.data.id; }
									if ( shot.flag ) { shot.flag.value = '0'; }
									if ( shot.img ) { shot.img.src = res.data.url; }
									if ( shot.preview ) { shot.preview.style.display = ''; }
									if ( shot.remove ) { shot.remove.style.display = ''; }
								}
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
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
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
	 * Resolve the requested post status, and the publish date that goes with it.
	 *
	 * Scheduling is the one status that can be asked for and not granted: a
	 * date in the past would leave the post sitting as 'future' and never
	 * appearing, so it publishes immediately instead. An empty date does the
	 * same rather than failing the whole save.
	 *
	 * @return array{status:string,postdate:string,postdate_gmt:string} Status and both date forms (empty when not scheduling).
	 */
	private static function collect_status(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce before calling run_save().
		$requested = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
		$raw_date  = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$none = array(
			'status'       => in_array( $requested, array( 'publish', 'draft', 'private' ), true ) ? $requested : 'publish',
			'postdate'     => '',
			'postdate_gmt' => '',
		);

		if ( 'future' !== $requested ) {
			return $none;
		}

		// The picker sends wall-clock time as the site reads it, so it must be
		// interpreted in the site timezone — not PHP's, which WordPress pins to
		// UTC. Getting this wrong publishes hours off the requested moment.
		$tz = wp_timezone();
		try {
			$when = new \DateTimeImmutable( str_replace( 'T', ' ', $raw_date ), $tz );
		} catch ( \Exception $e ) {
			return $none;
		}

		if ( '' === $raw_date || $when <= new \DateTimeImmutable( 'now', $tz ) ) {
			return $none;
		}

		return array(
			'status'       => 'future',
			'postdate'     => $when->format( 'Y-m-d H:i:s' ),
			'postdate_gmt' => $when->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
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

		$status = self::collect_status();

		$s = array(
			'title'    => $title,
			'slug'     => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
			'long'     => isset( $_POST['long'] ) ? self::clean_rich( wp_unslash( $_POST['long'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_rich() runs wp_kses.
			'short'    => isset( $_POST['short'] ) ? self::clean_rich( wp_unslash( $_POST['short'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_rich() runs wp_kses.
			'audience' => isset( $_POST['audience'] ) ? wp_kses_post( wp_unslash( $_POST['audience'] ) ) : '',
			'duration' => isset( $_POST['duration'] ) ? wp_kses_post( wp_unslash( $_POST['duration'] ) ) : '',
			'cost'     => isset( $_POST['cost'] ) ? wp_kses_post( wp_unslash( $_POST['cost'] ) ) : '',
			'date'     => isset( $_POST['date'] ) ? wp_kses_post( wp_unslash( $_POST['date'] ) ) : '',
			'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'cta_t'    => isset( $_POST['cta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_title'] ) ) : '',
			'cta_c'    => isset( $_POST['cta_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cta_content'] ) ) : '',
			'cta_b'    => isset( $_POST['cta_button'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_button'] ) ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by clean_promo().
			'whatsapp' => isset( $_POST['whatsapp'] ) ? Kivun_Campaigns::clean_promo( (string) wp_unslash( $_POST['whatsapp'] ) ) : '',
			'city'     => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			'status'   => $status['status'],
			'postdate' => $status['postdate'],
			'postgmt'  => $status['postdate_gmt'],
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value below.
		$publish = isset( $_POST['publish'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['publish'] ) ) : array();
		$group   = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		if ( '' === trim( $group ) ) {
			$group = wp_generate_uuid4();
		}
		$thumb_id = self::resolve_thumbnail();
		$share_id = self::resolve_image( 'share_image_file', 'share_image_id' );

		$cta = array(
			'_kivun_cta_title'   => $s['cta_t'],
			'_kivun_cta_content' => $s['cta_c'],
			'_kivun_cta_button'  => $s['cta_b'],
		);

		if ( in_array( 'landing', $publish, true ) ) {
			$landing_id = self::upsert(
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
				),
				$share_id
			);
			self::save_landing_form( (int) $landing_id );
		}

		if ( in_array( 'course', $publish, true ) ) {
			$price = (int) preg_replace( '/[^0-9]/', '', $s['cost'] );
			self::upsert(
				$group,
				'kivun_course',
				$s,
				self::excerpt_text( $s['short'] ),
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
				),
				$share_id
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
						// A recurring workshop ("סדנה שוטפת") and the day its
						// next cycle opens. The date is kept only while the
						// switch is on, so a workshop switched back to a
						// one-off does not go on announcing a cycle.
						'_kivun_session_recurring'   => empty( $_POST['session_recurring'] ) ? '' : '1',
						'_kivun_session_next_open'   => empty( $_POST['session_recurring'] ) ? '' : self::valid_date( sanitize_text_field( wp_unslash( $_POST['session_next_open'] ?? '' ) ) ),
					),
					$cta
				),
				$share_id
			);
		}

		if ( in_array( 'event', $publish, true ) ) {
			$event_image = self::resolve_image( 'event_image_file', 'event_image_id' );
			$event_mode  = ( isset( $_POST['event_mode'] ) && 'external' === $_POST['event_mode'] ) ? 'external' : 'form';
			self::upsert(
				$group,
				'kivun_event',
				$s,
				self::excerpt_text( $s['short'] ),
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
				),
				$share_id
			);
		}

		self::remove_unchecked_types( $group, $publish );

		return $group;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A landing page's own form, saved with the rest of the content.
	 *
	 * The form used to be built on a tab of its own, against a page that had
	 * to exist first, and saved by itself over AJAX. It is now part of
	 * creating the content: the builder sits in the wizard and its state
	 * travels in the same post as the title and the text.
	 *
	 * Applied only when the wizard's builder was actually on the page —
	 * `custom_form_present` says so. The wp-admin form posts to the same save
	 * and has no builder; reading its silence as "no custom form" would turn
	 * off a form somebody built, every time somebody fixed a typo there.
	 *
	 * Switched off, the page goes back to the fixed form. Its questions are
	 * kept, so switching it on again brings them back rather than starting
	 * from nothing.
	 *
	 * @param int $landing_id The landing page just saved.
	 * @return void
	 */
	private static function save_landing_form( int $landing_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called from run_save(), whose callers verify the nonce.
		if ( ! $landing_id || empty( $_POST['custom_form_present'] ) ) {
			return;
		}

		if ( empty( $_POST['custom_form_on'] ) ) {
			delete_post_meta( $landing_id, Kivun_Custom_Forms::FLAG );
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field by Kivun_Custom_Forms::save().
		$fields = json_decode( (string) wp_unslash( $_POST['custom_form_fields'] ?? '[]' ), true );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is matched against the known keys by save().
		$builtins = (array) wp_unslash( $_POST['custom_form_builtins'] ?? array() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Kivun_Custom_Forms::save( $landing_id, is_array( $fields ) ? $fields : array(), $builtins );
	}

	/**
	 * Take down the content types that were unticked while editing a group.
	 *
	 * Unticking used to do nothing at all: only the ticked types were written,
	 * and the rest were skipped rather than removed — so the page stayed live,
	 * and the tick came back on reload because the post was still there. From
	 * the editor's side it looked as though the click had been ignored.
	 *
	 * They go to the trash rather than being deleted outright. Clearing a
	 * checkbox is a light action and should not destroy a page beyond recovery,
	 * and its registrations are keyed to the post id, which trashing preserves.
	 *
	 * @param string            $group   The content group id.
	 * @param array<int,string> $publish Type keys that are still ticked.
	 * @return void
	 */
	private static function remove_unchecked_types( string $group, array $publish ): void {
		// Nothing ticked means the form was not asking for anything to change —
		// the wizard refuses to submit in that state — so treat it as a no-op
		// rather than as a request to take the whole group down.
		if ( ! $publish ) {
			return;
		}

		foreach ( self::group_posts( $group ) as $type_key => $post_id ) {
			if ( in_array( $type_key, $publish, true ) ) {
				continue;
			}
			if ( current_user_can( 'delete_post', $post_id ) ) {
				wp_trash_post( $post_id );
			}
		}
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
	 * @param int    $share_id  Share-image attachment ID (0 = leave as is).
	 * @return int The post ID, or 0 on failure.
	 */
	private static function upsert( string $group, string $post_type, array $shared, string $excerpt, int $thumb_id, array $meta, int $share_id = 0 ): int {
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
		if ( 'future' === $shared['status'] && ! empty( $shared['postdate'] ) ) {
			// Both forms are set explicitly: post_date in site time for display,
			// post_date_gmt because that is what wp-cron compares against when
			// deciding a scheduled post is due.
			$postarr['post_date']     = $shared['postdate'];
			$postarr['post_date_gmt'] = $shared['postgmt'];
			$postarr['edit_date']     = true;
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
		// Where the group is in the archive, a type added to it now goes
		// there with the rest. The library lists groups but the mark sits on
		// posts, so a sibling without it would put the same group in both
		// tables at once — each row holding half its types, half its leads,
		// and a button pointing the opposite way to the other.
		if ( self::group_is_archived( $group ) ) {
			update_post_meta( $post_id, self::ARCHIVED_META, '1' );
		}
		// The WhatsApp promo describes the group, not one post type, so every
		// post in the group carries the same copy.
		update_post_meta( $post_id, '_kivun_whatsapp', $shared['whatsapp'] ?? '' );
		update_post_meta( $post_id, '_kivun_city', $shared['city'] ?? '' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		if ( $thumb_id ) {
			set_post_thumbnail( $post_id, $thumb_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller before run_save().
		} elseif ( ! empty( $_POST['remove_thumbnail'] ) ) {
			delete_post_thumbnail( $post_id );
		}

		// The picture a shared link shows, which never appears on the page.
		// Both screens that reach this save carry the field, so a save from
		// either one says what it means; a save from a screen that did not
		// would read as "none" and quietly clear it.
		if ( $share_id ) {
			update_post_meta( $post_id, Kivun_Share_Image::META, $share_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller before run_save().
		} elseif ( ! empty( $_POST['remove_share_image'] ) ) {
			delete_post_meta( $post_id, Kivun_Share_Image::META );
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
		// The console is a content editor with a leads table in it, and the door
		// only ever asked about the content half. A leads reader deliberately
		// holds no editing capability, so the one screen the role exists for
		// turned them away — everything behind this line already knew to show
		// them the table and nothing else.
		if ( ! current_user_can( 'edit_posts' ) && ! self::can_manage_leads() && ! self::is_agency() ) {
			return '<div class="kivun-cc-front"><div class="kivun-cc-note kivun-cc-note--error">'
				. esc_html__( 'אין לך הרשאה להזין תוכן. פנו למנהל המערכת.', 'kivun' )
				. '</div></div>';
		}

		// This screen shows live data and carries a nonce; a cached copy of it
		// is both stale and unusable. Said again here because a page builder can
		// hide the shortcode from the earlier check.
		Kivun_Core::no_cache();

		Kivun_Core::enqueue_frontend_assets();
		// Voice dictation is for content authors only — load it here (the content
		// creator page), never on the public lead/registration forms, and not for
		// a leads reader, who is never shown a field to dictate into.
		if ( ! self::is_leads_only() && ! self::is_agency() ) {
			wp_enqueue_script( 'kivun-voice' );
		}

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
		return self::flatten_output( (string) ob_get_clean() );
	}

	/**
	 * The console's markup with no line breaks for a page builder to read.
	 *
	 * Elementor's text widget, and some themes, run the shortcode's output
	 * through wpautop(). That turns every newline between two tags into a
	 * `<br />` and every blank line into a paragraph — invisible, but each
	 * one is a box. In the wizard's grids they took cells of their own: the
	 * four type tiles came out one cell apart, two rows deep, each tile tall
	 * with its icon floating in the middle. 138 of them on the publish tab.
	 *
	 * So the newlines go before wpautop() can see them. Outside a textarea
	 * a run of whitespace with a newline in it is one space — which is all
	 * the browser ever rendered it as, so nothing on screen moves. Inside a
	 * textarea the newlines are the content (a WhatsApp message, a list of
	 * audiences), so they are kept as `&#10;`, which the browser reads back
	 * as the same newline and which wpautop() does not recognise.
	 *
	 * Script, style and pre blocks are passed through as they are: a newline
	 * can end a statement in a script, and pre is wpautop()'s own exception.
	 *
	 * @param string $html The rendered console.
	 * @return string The same markup, on one line.
	 */
	public static function flatten_output( string $html ): string {
		$parts = preg_split( '#(<(textarea|script|style|pre)\b[^>]*>.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$out   = '';
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i++ ) {
			$part = $parts[ $i ];
			// The capture groups follow each protected block: the block
			// itself, then the bare tag name, which is skipped.
			if ( 1 === $i % 3 ) {
				if ( 0 === stripos( $part, '<textarea' ) ) {
					$open  = (int) strpos( $part, '>' ) + 1;
					$close = (int) strripos( $part, '</textarea' );
					$body  = str_replace( array( "\r\n", "\r", "\n" ), '&#10;', substr( $part, $open, $close - $open ) );
					$part  = substr( $part, 0, $open ) . $body . substr( $part, $close );
				}
				$out .= $part;
				++$i;
				continue;
			}
			$out .= (string) preg_replace( '/\s*[\r\n]\s*/', ' ', $part );
		}
		return $out;
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
		if ( current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
		return current_user_can( 'kivun_view_leads' );
	}

	/**
	 * Whether the current user may see the leads table.
	 *
	 * Wider than can_manage_leads(), which is the gate on writing: the agency
	 * reads the enquiries its campaigns produced without touching a status, a
	 * note or who is handling it. Display asks this one; every write asks the
	 * other, so a reader is refused at the handler whatever the screen shows.
	 *
	 * @return bool
	 */
	public static function can_read_leads(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
		return self::can_manage_leads() || current_user_can( 'kivun_read_leads' );
	}

	/**
	 * The plain-text teaser stored as a post's excerpt.
	 *
	 * An excerpt is shown in a card, in a list, in a search result — places
	 * that style their own text. Markup carried into it wins over the theme,
	 * so one item ends up in a different font from the rest of the grid.
	 *
	 * @param string $html The short description.
	 * @return string
	 */
	private static function excerpt_text( string $html ): string {
		return Kivun_AI_Content::plain_text( $html );
	}

	/**
	 * Keep the formatting a person meant, drop the formatting they carried.
	 *
	 * Text pasted from a document or another site arrives wrapped in spans that
	 * name their own font and size. Stored as-is, the card and the page render
	 * that text in a font the site never uses, sitting oddly beside content
	 * typed directly in — which is what a visitor notices first. Paragraphs,
	 * emphasis and lists are intentional and kept; a font is not.
	 *
	 * @param string $html Submitted rich text.
	 * @return string
	 */
	public static function clean_rich( string $html ): string {
		$allowed = array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'blockquote' => array(),
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
		);

		$html = wp_kses( $html, $allowed );

		// A paste also brings its own spacing. Non-breaking spaces arrive both
		// as the character and as the entity — wp_kses decodes neither — and
		// each one renders as a gap the writer did not type.
		$html = str_replace( array( "\xc2\xa0", '&nbsp;', '&#160;', '&#xa0;' ), ' ', $html );
		$html = (string) preg_replace( '/[ \t]{2,}/u', ' ', $html );

		return trim( $html );
	}

	/**
	 * Every post the leads filter covers when these contents are chosen.
	 *
	 * Everything the visitor could have arrived through: a course and its
	 * landing page are one piece of content, so filtering to one of them and
	 * hiding the rest would answer a question nobody asked. With several
	 * chosen it is every arrival into any of them.
	 *
	 * One named thing because two places need the same answer — the table's
	 * query and the export button beside it. While each worked it out for
	 * itself they disagreed, and the screen said thirty-five while the
	 * spreadsheet held one.
	 *
	 * @param array<int,int> $content_filter The contents chosen in the filter.
	 * @return array<int,int> Every post id they cover; empty for no filter.
	 */
	public static function content_scope( array $content_filter ): array {
		$scope = array();
		foreach ( $content_filter as $chosen_content ) {
			$scope = array_merge( $scope, self::group_post_ids( (int) $chosen_content ) );
		}

		return array_values( array_unique( array_map( 'absint', $scope ) ) );
	}

	/**
	 * Every post that belongs to the same piece of content as this one.
	 *
	 * A course and the landing page built for it are one thing to whoever made
	 * them, and one thing to whoever left their details — the visitor saw a
	 * page, not a post type. They are linked by a shared group meta, which is
	 * what makes them one thing here too.
	 *
	 * @param int $post_id Any post in the group.
	 * @return array<int,int> The group's post IDs, or just this one when it
	 *                        belongs to no group.
	 */
	public static function group_post_ids( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$group = (string) get_post_meta( $post_id, self::GROUP_META, true );
		if ( '' === $group ) {
			return array( $post_id );
		}

		$ids = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $group, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		// The post asked about is in the list even if it has since been removed
		// from the query's reach, so a filter never comes back empty-handed.
		if ( ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
		}

		return $ids;
	}

	/**
	 * Whether a content group is in the library's archive.
	 *
	 * Asked of the group rather than of one post: the mark is written to
	 * every post in the group, so any of them answers, and a group half
	 * marked is a group that would be listed twice.
	 *
	 * @param string $group The group key.
	 * @return bool
	 */
	public static function group_is_archived( string $group ): bool {
		if ( '' === $group ) {
			return false;
		}

		$marked = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'   => self::GROUP_META,
						'value' => $group,
					),
					array(
						'key'     => self::ARCHIVED_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return ! empty( $marked );
	}

	/**
	 * AJAX: put a piece of content away in the library's archive, or bring
	 * it back.
	 *
	 * Where it is listed, and nothing else. The pages stay published and
	 * reachable, their forms keep taking enquiries, and the leads already
	 * filed against them are untouched — somebody tidying the management
	 * screen is not somebody taking a page off the site, and the two must
	 * not be the same button.
	 *
	 * The mark goes on every post in the group, because the library lists
	 * the group: a course and the landing page built for it are one line
	 * there, and one of them left behind would put that line back.
	 *
	 * @return void
	 */
	public static function ajax_archive_content(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		// Whoever may create content may put it away. Reading the leads is
		// not enough: this is the content side of the console.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה.', 'kivun' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$group    = sanitize_text_field( wp_unslash( $_POST['group'] ?? '' ) );
		$archived = ! empty( $_POST['archived'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $group ) {
			wp_send_json_error( array( 'message' => __( 'התוכן לא נמצא.', 'kivun' ) ), 400 );
		}

		$ids = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $group, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'התוכן לא נמצא.', 'kivun' ) ), 404 );
		}

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( $archived ) {
				update_post_meta( $id, self::ARCHIVED_META, '1' );
			} else {
				delete_post_meta( $id, self::ARCHIVED_META );
			}
		}

		wp_send_json_success(
			array(
				'message' => $archived
					? __( 'התוכן הועבר לארכיון.', 'kivun' )
					: __( 'התוכן הוחזר.', 'kivun' ),
			)
		);
	}

	/**
	 * The content list for a leads filter: one entry per piece of content, not
	 * one per post.
	 *
	 * A course with a landing page used to appear twice under the same title,
	 * and picking either showed only half the enquiries — the visitor's half
	 * depended on which of the two they happened to land on.
	 *
	 * @param array<int,\WP_Post> $posts The candidate posts, in display order.
	 * @return array{options:array<int,string>,rep:array<int,int>} The options as
	 *         id => title, and a map from any post id to the id that represents
	 *         its group in that list.
	 */
	public static function content_filter_options( array $posts ): array {
		$options = array();
		$rep     = array();
		$first   = array();

		foreach ( $posts as $post ) {
			$id    = (int) $post->ID;
			$group = (string) get_post_meta( $id, self::GROUP_META, true );

			if ( '' === $group ) {
				$options[ $id ] = (string) $post->post_title;
				$rep[ $id ]     = $id;
				continue;
			}

			if ( ! isset( $first[ $group ] ) ) {
				$first[ $group ] = $id;
				$options[ $id ]  = (string) $post->post_title;
			}

			$rep[ $id ] = $first[ $group ];
		}

		return array(
			'options' => $options,
			'rep'     => $rep,
		);
	}

	/**
	 * Where the console lives, so a letter can send somebody to it.
	 *
	 * The page is found rather than configured, three ways over: the address it
	 * was last seen at, the page holding the shortcode, and finally the site's
	 * front page so a link is never broken. Elementor keeps a shortcode widget
	 * in the builder data rather than in the content, so both are searched.
	 *
	 * @param string $tab Which tab to open on, e.g. 'leads'.
	 * @return string
	 */
	public static function console_url( string $tab = '' ): string {
		$url = (string) get_option( self::CONSOLE_URL_OPTION, '' );

		if ( '' === $url ) {
			$url = self::find_console_page();
		}

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		/**
		 * The address of the management console.
		 *
		 * @param string $url The address worked out so far.
		 * @param string $tab The tab being linked to.
		 */
		$url = (string) apply_filters( 'kivun_console_url', $url, $tab );

		return '' !== $tab ? add_query_arg( 'kivun_tab', $tab, $url ) : $url;
	}

	/**
	 * Search the site for the page that renders the console.
	 *
	 * @return string The permalink, or '' when no page holds the shortcode.
	 */
	private static function find_console_page(): string {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '[kivun_content_creator' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs once, then kept in an option.
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s
				 LIMIT 1",
				$like
			)
		);

		// A page built in Elementor keeps the shortcode in its builder data
		// rather than in its content.
		if ( ! $page_id ) {
			$page_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					 WHERE p.post_type = 'page' AND p.post_status = 'publish'
					   AND m.meta_key = '_elementor_data' AND m.meta_value LIKE %s
					 LIMIT 1",
					$like
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Remember where the console was just rendered.
	 *
	 * Cheaper and surer than searching for it, and it keeps up with the page
	 * being moved or renamed. Written only when it has actually changed.
	 *
	 * @param string $url The permalink of the page rendering the console.
	 * @return void
	 */
	private static function remember_console_url( string $url ): void {
		if ( '' === trim( $url ) || (string) get_option( self::CONSOLE_URL_OPTION, '' ) === $url ) {
			return;
		}

		update_option( self::CONSOLE_URL_OPTION, esc_url_raw( $url ), false );
	}

	/**
	 * Who may hand out access to the leads table.
	 *
	 * Creating a user account and granting it sight of every enquiry the centre
	 * receives is an administrator's decision, not an editor's — the leads tab
	 * is open to editors, so this is checked separately from seeing it.
	 *
	 * @return bool
	 */
	public static function can_grant_leads_access(): bool {
		return current_user_can( 'create_users' ) && current_user_can( 'promote_users' );
	}

	/**
	 * Everyone currently holding the leads-viewer role.
	 *
	 * @return array<int,\WP_User>
	 */
	public static function leads_viewers(): array {
		return get_users(
			array(
				'role'    => 'kivun_leads_viewer',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * AJAX: give someone access to the leads table.
	 *
	 * @return void
	 */
	public static function ajax_add_leads_viewer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_grant_leads_access() ) {
			wp_send_json_error( array( 'message' => __( 'רק מנהל אתר יכול להוסיף גישה ללידים.', 'kivun' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין כתובת אימייל תקינה.', 'kivun' ) ) );
		}

		$existing = get_user_by( 'email', $email );

		if ( $existing ) {
			// An account already here is granted the role rather than refused:
			// refusing would leave no way to give an existing colleague access.
			if ( in_array( 'kivun_leads_viewer', (array) $existing->roles, true ) ) {
				wp_send_json_error( array( 'message' => __( 'למשתמש הזה כבר יש גישה ללידים.', 'kivun' ) ) );
			}

			// An administrator or editor already sees the leads; adding this
			// role would take capabilities away rather than add them.
			if ( user_can( $existing, 'edit_others_posts' ) || user_can( $existing, 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'למשתמש הזה כבר יש גישה ללידים מתוקף תפקידו הקיים.', 'kivun' ),
					)
				);
			}

			$existing->add_role( 'kivun_leads_viewer' );

			// They already have a password; what they do not have is any idea
			// where the screen is.
			$sent = self::send_leads_invite( $existing, false );

			wp_send_json_success(
				array(
					'message' => sprintf(
						$sent
							/* translators: %s: user display name. */
							? __( 'הגישה ניתנה למשתמש הקיים %s, ונשלח אליו מייל עם הקישור.', 'kivun' )
							/* translators: %s: user display name. */
							: __( 'הגישה ניתנה למשתמש הקיים %s, אך שליחת המייל נכשלה — יש למסור לו את הקישור ידנית.', 'kivun' ),
						$existing->display_name
					),
				)
			);
		}

		// A fresh account. The login is derived from the address, since these
		// are colleagues invited by email rather than people choosing a name.
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$login = '' !== $base ? $base : 'leads' . wp_rand( 100, 999 );
		$try   = $login;
		$n     = 1;
		while ( username_exists( $try ) ) {
			++$n;
			$try = $login . $n;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $try,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 20, true ),
				'display_name' => '' !== $name ? $name : $try,
				'first_name'   => $name,
				'role'         => 'kivun_leads_viewer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		// One letter, carrying both halves: where the leads are, and the link to
		// set a password. WordPress's own notification is the fallback rather
		// than the default — it points at wp-admin, which this role cannot
		// open, and never names the screen the account was opened for.
		$user = get_userdata( (int) $user_id );
		$sent = $user instanceof \WP_User ? self::send_leads_invite( $user, true ) : false;

		if ( ! $sent ) {
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: email address. */
					__( 'נוצר משתמש חדש ונשלחה אליו הזמנה ל-%s, עם קישור ישיר לרשימת הלידים.', 'kivun' ),
					$email
				),
			)
		);
	}

	/**
	 * Tell somebody they now have the leads table, and where it is.
	 *
	 * WordPress's own invitation says only that an account exists and offers a
	 * password link into wp-admin — which this role cannot open. It never names
	 * the one screen the account was made for, so somebody handed it had no way
	 * of knowing where to go.
	 *
	 * @param \WP_User $user   Who is being invited.
	 * @param bool     $is_new Whether the account was just created, and so still
	 *                         needs a password set.
	 * @return bool Whether the letter was accepted for delivery.
	 */
	private static function send_leads_invite( \WP_User $user, bool $is_new ): bool {
		$site    = get_bloginfo( 'name' );
		$console = self::console_url( 'leads' );

		$name = trim( (string) $user->first_name );
		if ( '' === $name ) {
			$name = (string) $user->display_name;
		}

		$body = sprintf(
			'<p dir="rtl" style="margin:0 0 14px;text-align:right;font-weight:600;color:#222222">%s</p>',
			/* translators: %s: the invited person's name. */
			esc_html( sprintf( __( 'שלום %s,', 'kivun' ), $name ) )
		);

		$body .= sprintf(
			'<p dir="rtl" style="margin:0 0 14px;text-align:right">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: site name. */
					__( 'ניתנה לך גישה לרשימת הפניות והלידים של %s. בעמוד תוכל/י לראות את כל הפניות שמתקבלות באתר, לסנן אותן ולעדכן את הסטטוס של כל פנייה.', 'kivun' ),
					$site
				)
			)
		);

		$body .= Kivun_Mailer::button( $console, __( 'מעבר לרשימת הלידים', 'kivun' ) );

		if ( $is_new ) {
			// The password was generated and never shown, so this link is the
			// only way in. It goes to the address the account was opened with
			// and nowhere else.
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$reset = network_site_url(
					'wp-login.php?action=rp&key=' . rawurlencode( (string) $key ) . '&login=' . rawurlencode( $user->user_login ),
					'login'
				);

				$body .= sprintf(
					'<p dir="rtl" style="margin:22px 0 10px;text-align:right">%s</p>%s<p dir="rtl" style="margin:10px 0 0;text-align:right;font-size:14px;color:#8a8a8a">%s</p>',
					esc_html__( 'נפתח עבורך חשבון באתר. כדי להיכנס בפעם הראשונה יש לבחור סיסמה:', 'kivun' ),
					Kivun_Mailer::button( $reset, __( 'בחירת סיסמה', 'kivun' ) ),
					esc_html(
						sprintf(
							/* translators: %s: the account's username. */
							__( 'שם המשתמש שלך: %s', 'kivun' ),
							$user->user_login
						)
					)
				);
			}
		}

		return Kivun_Mailer::send(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( 'גישה לרשימת הלידים — %s', 'kivun' ), $site ),
			$body,
			__( 'גישה לרשימת הלידים', 'kivun' )
		);
	}

	/**
	 * AJAX: take away access to the leads table.
	 *
	 * @return void
	 */
	public static function ajax_remove_leads_viewer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_grant_leads_access() ) {
			wp_send_json_error( array( 'message' => __( 'רק מנהל אתר יכול לשנות גישה ללידים.', 'kivun' ) ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user || ! in_array( 'kivun_leads_viewer', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'המשתמש לא נמצא.', 'kivun' ) ) );
		}

		// Only the role is removed. Deleting the account would be a far larger
		// act than "stop showing them the leads", and is not what was asked.
		$user->remove_role( 'kivun_leads_viewer' );

		wp_send_json_success( array( 'message' => __( 'הגישה הוסרה.', 'kivun' ) ) );
	}

	/**
	 * Split a lead's stored source into where it came from and its UTM parts.
	 *
	 * Kivun_Utm appends "UTM: source / medium / campaign / content" to the end
	 * of whatever the form recorded, so the two are separated by the last
	 * occurrence of that marker rather than by position.
	 *
	 * @param string $source The stored source column.
	 * @return array{origin:string,source:string,medium:string,campaign:string,content:string}
	 */
	public static function parse_source( string $source ): array {
		$out = array(
			'origin'   => trim( $source ),
			'source'   => '',
			'medium'   => '',
			'campaign' => '',
			'content'  => '',
		);

		$at = mb_strpos( $source, 'UTM: ' );
		if ( false === $at ) {
			return $out;
		}

		$out['origin'] = trim( rtrim( trim( mb_substr( $source, 0, $at ) ), '·' ) );
		$parts         = array_map( 'trim', explode( '/', mb_substr( $source, $at + 5 ) ) );

		foreach ( array( 'source', 'medium', 'campaign', 'content' ) as $i => $key ) {
			if ( isset( $parts[ $i ] ) && '' !== $parts[ $i ] ) {
				$out[ $key ] = $parts[ $i ];
			}
		}

		return $out;
	}

	/**
	 * One lead's origin and UTM values, ready to display.
	 *
	 * Prefers the stored columns and only falls back to reading the source
	 * text, which is what leads captured before those columns existed have.
	 * The text drops empty values, so "a / b / c" cannot say which fields are
	 * present and a campaign can be shown in the medium's place.
	 *
	 * @param object $row A registrations row.
	 * @return array{origin:string,source:string,medium:string,campaign:string,content:string}
	 */
	public static function lead_source( $row ): array {
		$parsed = self::parse_source( (string) ( $row->source ?? '' ) );

		$stored = array(
			'source'   => (string) ( $row->utm_source ?? '' ),
			'medium'   => (string) ( $row->utm_medium ?? '' ),
			'campaign' => (string) ( $row->utm_campaign ?? '' ),
			'content'  => (string) ( $row->utm_content ?? '' ),
		);

		if ( '' === $stored['source'] && '' === $stored['campaign'] ) {
			return $parsed;
		}

		return array_merge( $stored, array( 'origin' => $parsed['origin'] ) );
	}

	/**
	 * Whether the current user should be shown where a lead came from.
	 *
	 * Which campaign brought somebody in is a question about the advertising,
	 * and it is answered on the campaigns screen. A coordinator working the
	 * leads is doing a different job — they need the person, not the channel —
	 * so the column is kept off that table for them and the screen stays about
	 * the work in front of them.
	 *
	 * Not the same bar as reading the leads: the leads reader is exactly who
	 * this hides it from. Whoever runs the content or the site sees it in both
	 * places.
	 *
	 * @return bool
	 */
	public static function can_see_lead_source(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_others_posts' );
	}

	/**
	 * Whether the current user should be shown the whole UTM, rather than
	 * only the medium a lead arrived through.
	 *
	 * Two different questions hide behind one column. "This came from
	 * WhatsApp" is context for whoever is working the lead. "This came
	 * from campaign אחזקת-מבנים, publisher ערוץ-הירושלמים" is the
	 * advertising ledger: which supplier was paid for which placement,
	 * and what it returned. That belongs to whoever runs the advertising,
	 * and it is not a coordinator's business.
	 *
	 * So the medium stays for everyone who may see the column at all,
	 * and the campaign and content are kept for the site's managers and
	 * for the agency, whose own placements they are.
	 *
	 * Deliberately a narrower bar than can_see_lead_source(): that one
	 * admits edit_others_posts, which is most of the content team.
	 *
	 * @return bool
	 */
	public static function can_see_full_lead_source(): bool {
		return current_user_can( 'manage_options' ) || self::is_agency();
	}

	/**
	 * Whether the current user is an outside agency: the campaign numbers
	 * and nothing else.
	 *
	 * They are shown how many enquiries and calls each of their links
	 * produced, and never a single enquiry. A name and a phone number are
	 * the person's, not the agency's, and the agency's job is answered by
	 * the count.
	 *
	 * Nothing is written either. The capability is deliberately not
	 * edit_posts: every handler in this plugin that guards on edit_posts
	 * would have opened to it the moment it was granted, and a role defined
	 * by what it cannot do must not be defined with the capability that
	 * does it.
	 *
	 * @return bool
	 */
	public static function is_agency(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
		return current_user_can( 'kivun_view_campaigns' )
			&& ! current_user_can( 'edit_others_posts' )
			&& ! current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user may read the leads and nothing else.
	 *
	 * The console is otherwise a content editor; this role has no business in
	 * it beyond the table, so every other tab is withheld rather than shown
	 * and refused.
	 *
	 * @return bool
	 */
	public static function is_leads_only(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
		return current_user_can( 'kivun_view_leads' )
			&& ! current_user_can( 'edit_others_posts' )
			&& ! current_user_can( 'manage_options' );
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

		// A leads reader has one tab, so nothing else can be reached — not by a
		// stale URL and not by an edit link kept from a previous session.
		$leads_only = self::is_leads_only();
		$agency     = self::is_agency();

		// Editing a specific group always lands on the form.
		if ( '' !== $group && ! $leads_only && ! $agency ) {
			$tab = 'form';
		}
		$show_leads = self::can_read_leads();
		// The jobs board is a separate system with its own data (jobs and CV
		// applications) — it gets its own tab rather than being mixed into the
		// content leads table.
		$show_jobs = Kivun_Employer::can_manage_all();

		$show_camp  = Kivun_Campaigns::can_manage() || $agency;
		$show_calls = Kivun_Phones::can_manage();

		if ( $leads_only ) {
			$tab        = 'leads';
			$show_jobs  = false;
			$show_camp  = false;
			$show_calls = false;
		}

		// One tab, so a stale URL and an old bookmark both land on it rather
		// than on something the agency has no business seeing refused.
		if ( $agency ) {
			// Two tabs: the campaigns, and the enquiries they produced. Any
			// other tab lands on the campaigns rather than being shown and
			// refused — a stale URL or an old bookmark reaches nothing it has
			// no business in.
			if ( ! in_array( $tab, array( 'campaigns', 'leads' ), true ) ) {
				$tab = 'campaigns';
			}
			$show_jobs  = false;
			$show_calls = false;
		}

		// The custom landing pages had a tab of their own. Their forms are now
		// built in the wizard and the pages are listed with the rest of the
		// content, so a bookmark to the old tab lands where they are now.
		if ( 'forms' === $tab && ! $leads_only && ! $agency ) {
			$tab = 'library';
		}

		if (
			! in_array( $tab, array( 'form', 'library', 'leads', 'jobs', 'campaigns', 'calls' ), true ) ||
			( 'leads' === $tab && ! $show_leads ) ||
			( 'jobs' === $tab && ! $show_jobs ) ||
			( 'campaigns' === $tab && ! $show_camp ) ||
			( 'calls' === $tab && ! $show_calls )
		) {
			$tab = 'form';
		}

		$page_url = (string) get_permalink();
		$page_url = $page_url ? $page_url : home_url();
		self::remember_console_url( $page_url );

		$can_delete = current_user_can( 'delete_posts' );
		$stats      = self::console_stats( $show_leads, $show_jobs, ! $leads_only && ! $agency, $show_calls );
		$user       = wp_get_current_user();

		$console_tabs = ( $leads_only || $agency ) ? array() : array(
			'form'    => array(
				'label' => __( 'פרסום תוכן', 'kivun' ),
				'icon'  => 'pen',
				'count' => null,
			),
			'library' => array(
				'label' => __( 'התכנים שלי', 'kivun' ),
				'icon'  => 'grid',
				'count' => $stats['content'],
			),
		);
		if ( $show_leads ) {
			$console_tabs['leads'] = array(
				'label' => __( 'לידים והרשמות', 'kivun' ),
				'icon'  => 'people',
				'count' => $stats['leads'],
			);
		}
		if ( $show_camp ) {
			$console_tabs['campaigns'] = array(
				'label' => __( 'קמפיינים', 'kivun' ),
				'icon'  => 'mega',
				'count' => null,
			);
		}
		if ( $show_calls ) {
			$console_tabs['calls'] = array(
				'label' => __( 'טלפונים', 'kivun' ),
				'icon'  => 'phone',
				'count' => null,
			);
		}
		if ( $show_jobs ) {
			$console_tabs['jobs'] = array(
				'label' => __( 'לוח משרות', 'kivun' ),
				'icon'  => 'case',
				'count' => $stats['jobs'],
			);
		}
		?>
		<div class="kivun-cc-console" dir="rtl">

			<header class="kivun-cc-topbar">
				<div class="kivun-cc-topbar__brand">
					<span class="kivun-cc-topbar__mark" aria-hidden="true"><?php echo kivun_icon( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
					<div>
						<h2 class="kivun-cc-topbar__title"><?php esc_html_e( 'מערכת ניהול תוכן', 'kivun' ); ?></h2>
						<p class="kivun-cc-topbar__sub"><?php esc_html_e( 'תכנים, פניות ולוח משרות — במקום אחד', 'kivun' ); ?></p>
					</div>
				</div>
				<div class="kivun-cc-topbar__user">
					<span class="kivun-cc-topbar__hello" data-initial="<?php echo esc_attr( function_exists( 'mb_substr' ) ? mb_substr( $user->display_name, 0, 1 ) : substr( $user->display_name, 0, 1 ) ); ?>">
						<?php
						/* translators: %s: display name of the logged-in manager. */
						echo esc_html( sprintf( __( 'שלום, %s', 'kivun' ), $user->display_name ) );
						?>
					</span>
					<a class="kivun-cc-topbar__logout" href="<?php echo esc_url( wp_logout_url( $page_url ) ); ?>"><?php esc_html_e( 'יציאה', 'kivun' ); ?></a>
				</div>
			</header>

			<div class="kivun-cc-stats">
				<?php
				// An agency's headline is what its campaigns produced. The
				// site's own totals are not its business and would read as
				// its own result, which they are not: enquiries arrive from
				// organic search and from the newsletter too, and counting
				// those here would credit the agency for them.
				if ( $agency ) :
					$kivun_agency_leads = array_sum( Kivun_Campaigns::campaign_lead_counts() );
					$kivun_agency_calls = array_sum( Kivun_Campaigns::campaign_call_counts() );

					$kivun_agency_clicks = 0;
					foreach ( Kivun_Campaigns::links_by_campaign() as $kivun_camp_links ) {
						foreach ( $kivun_camp_links as $kivun_one_link ) {
							$kivun_agency_clicks += (int) ( $kivun_one_link->clicks ?? 0 );
						}
					}
					?>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $kivun_agency_clicks ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'כניסות', 'kivun' ); ?></span>
					</div>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $kivun_agency_leads ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'פניות מהקמפיינים', 'kivun' ); ?></span>
					</div>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $kivun_agency_calls ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'שיחות', 'kivun' ); ?></span>
					</div>
					<?php
				endif;
				?>
				<?php if ( ! $leads_only && ! $agency ) : ?>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $stats['published'] ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'פרסומים', 'kivun' ); ?></span>
					</div>
				<?php endif; ?>
				<?php
				/*
				 * Calls, where the switchboard is connected. This card took
				 * the place of "ממתינים לפרסום", which said almost nothing:
				 * it is nearly always zero, and the one screen that acts on
				 * it already lists them.
				 *
				 * Only for whoever may open the tracking-numbers screen —
				 * a figure nobody can click through to is a dead end.
				 */
				?>
				<?php if ( $show_calls ) : ?>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $stats['calls'] ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'שיחות', 'kivun' ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $show_leads ) : ?>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $stats['leads'] ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'פניות', 'kivun' ); ?></span>
					</div>
					<?php
					/*
					 * The one card that asks for something to be done. The
					 * design gives it a red dot, a red figure and a badge —
					 * and the style for the first two has been in the
					 * stylesheet all along under `kivun-cc-stat--alert`,
					 * which nothing ever emitted. So the card has been
					 * rendering as an ordinary one.
					 *
					 * Only when there is something waiting: a badge reading
					 * "לטיפול" over a zero is an alarm about nothing.
					 */
					$kivun_leads_waiting = (int) $stats['leads_new'] > 0;
					?>
					<div class="kivun-cc-stat <?php echo $kivun_leads_waiting ? 'kivun-cc-stat--alert' : ''; ?>">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $stats['leads_new'] ) ); ?></span>
						<span class="kivun-cc-stat__label">
							<span class="kivun-cc-stat__text"><?php esc_html_e( 'ממתינות לטיפול', 'kivun' ); ?></span>
							<?php if ( $kivun_leads_waiting ) : ?>
								<span class="kivun-cc-stat__flag"><?php esc_html_e( 'לטיפול', 'kivun' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
				<?php endif; ?>
				<?php if ( $show_jobs ) : ?>
					<div class="kivun-cc-stat">
						<span class="kivun-cc-stat__num"><?php echo esc_html( number_format_i18n( $stats['jobs'] ) ); ?></span>
						<span class="kivun-cc-stat__label"><?php esc_html_e( 'משרות', 'kivun' ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<?php // A leads reader has a single screen and no tabs to switch between, so the bar would be an empty strip above the table. ?>
			<?php if ( $console_tabs ) : ?>
			<div class="kivun-tabs kivun-cc-tabs" role="tablist" aria-label="<?php esc_attr_e( 'ניהול תוכן', 'kivun' ); ?>">
				<?php
				foreach ( $console_tabs as $tab_key => $tab_meta ) :
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
						<span class="kivun-tabicon" aria-hidden="true"><?php echo kivun_duo_icon( $tab_meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
						<?php echo esc_html( $tab_meta['label'] ); ?>
						<?php if ( $tab_meta['count'] ) : ?>
							<span class="kivun-tab-badge kivun-tab-badge--soft"><?php echo esc_html( number_format_i18n( $tab_meta['count'] ) ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php
			// Not rendered, rather than rendered and hidden. A hidden panel
			// is still in the response: front_library() asks for every
			// draft, pending, private and scheduled item on the site, and
			// front_form() will fill itself in from a group id in the URL.
			// An agency reading the page source would have had all of it.
			?>
			<?php if ( ! $leads_only && ! $agency ) : ?>
				<section class="kivun-tab-panel <?php echo 'form' === $tab ? 'is-active' : ''; ?>" data-panel="form" id="kivun-ccpanel-form" role="tabpanel" aria-labelledby="kivun-cctab-form" tabindex="0" <?php echo 'form' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_form(); ?>
				</section>

				<section class="kivun-tab-panel <?php echo 'library' === $tab ? 'is-active' : ''; ?>" data-panel="library" id="kivun-ccpanel-library" role="tabpanel" aria-labelledby="kivun-cctab-library" tabindex="0" <?php echo 'library' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_library( $page_url, $can_delete ); ?>
				</section>
			<?php endif; ?>

			<?php if ( $show_leads ) : ?>
				<section class="kivun-tab-panel <?php echo 'leads' === $tab ? 'is-active' : ''; ?>" data-panel="leads" id="kivun-ccpanel-leads" role="tabpanel" aria-labelledby="kivun-cctab-leads" tabindex="0" <?php echo 'leads' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_leads( $page_url ); ?>
				</section>
			<?php endif; ?>

			<?php if ( $show_camp ) : ?>
				<section class="kivun-tab-panel kivun-tab-panel--list <?php echo 'campaigns' === $tab ? 'is-active' : ''; ?>" data-panel="campaigns" id="kivun-ccpanel-campaigns" role="tabpanel" aria-labelledby="kivun-cctab-campaigns" tabindex="0" <?php echo 'campaigns' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_campaigns(); ?>
				</section>
			<?php endif; ?>

			<?php if ( $show_calls ) : ?>
				<section class="kivun-tab-panel kivun-tab-panel--list <?php echo 'calls' === $tab ? 'is-active' : ''; ?>" data-panel="calls" id="kivun-ccpanel-calls" role="tabpanel" aria-labelledby="kivun-cctab-calls" tabindex="0" <?php echo 'calls' === $tab ? '' : 'hidden'; ?>>
					<?php self::front_calls(); ?>
				</section>
			<?php endif; ?>

			<?php if ( $show_jobs ) : ?>
				<section class="kivun-tab-panel kivun-tab-panel--list <?php echo 'jobs' === $tab ? 'is-active' : ''; ?>" data-panel="jobs" id="kivun-ccpanel-jobs" role="tabpanel" aria-labelledby="kivun-cctab-jobs" tabindex="0" <?php echo 'jobs' === $tab ? '' : 'hidden'; ?>>
					<div class="kivun-cc-front">
						<div class="kivun-cc-head">
							<h2 class="kivun-cc-title"><?php esc_html_e( 'לוח המשרות', 'kivun' ); ?></h2>
							<?php
							// The other half of the pair: the leads screen points
							// here for applications, and this one points there for
							// everything else. Shown only where that tab is on the
							// screen — a jobs manager without the leads screen
							// cannot follow it, and the line says nothing else.
							if ( self::can_manage_leads() ) :
								$kivun_leads_tab = sprintf(
									'<a href="%s">%s</a>',
									esc_url( add_query_arg( 'kivun_tab', 'leads', $page_url ) ),
									esc_html__( 'לידים והרשמות', 'kivun' )
								);
								?>
								<p class="kivun-cc-lead">
									<?php
									printf(
										/* translators: %s: a link whose text is the name of the leads tab. */
										esc_html__( 'לידים והרשמות לקורסים, סדנאות ואירועים נמצאות בלשונית %s.', 'kivun' ),
										$kivun_leads_tab // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from the escaped parts above.
									);
									?>
								</p>
							<?php endif; ?>
						</div>
						<?php if ( Kivun_Mercaz::configured() && Kivun_Employer::can_manage_all() ) : ?>
							<div class="kivun-mercaz-bulk">
								<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-mercaz-push-all">
									<?php esc_html_e( 'שדר מחדש את כל המשרות', 'kivun' ); ?>
								</button>
								<span class="kivun-mercaz-bulk__hint"><?php esc_html_e( 'מעדכן כל משרה במקומה אצל מרכז כיוון — לא יוצר כפילויות — ומפרט מה עדיין חסר.', 'kivun' ); ?></span>
								<div class="kivun-mercaz-bulk__out" hidden></div>
							</div>
						<?php endif; ?>
					</div>
					<?php
					/**
					 * Signals that the jobs dashboard is rendering inside the
					 * content console, so it skips its own header/stat chrome.
					 */
					do_action( 'kivun_console_rendering' );
					kivun_get_template( 'employer/dashboard.php' );
					?>
				</section>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Headline counts for the console's stat cards.
	 *
	 * @param bool $with_leads   Whether to count content enquiries.
	 * @param bool $with_jobs    Whether to count jobs.
	 * @param bool $with_content Whether to count the content library at all.
	 * @param bool $with_calls   Whether to count switchboard calls.
	 * @return array<string,int>
	 */
	private static function console_stats( bool $with_leads, bool $with_jobs, bool $with_content = true, bool $with_calls = false ): array {
		global $wpdb;

		$stats = array(
			'content'   => 0,
			'published' => 0,
			'pending'   => 0,
			'leads'     => 0,
			'leads_new' => 0,
			'calls'     => 0,
			'jobs'      => 0,
		);

		// A leads reader is shown no content and counts none: the tally would
		// tell them how much there is across a site they cannot open, and the
		// query behind it reads every post to work that out.
		if ( $with_calls ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stats['calls'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_calls" );
		}

		if ( ! $with_content ) {
			return array_merge( $stats, self::lead_stats( $with_leads, $with_jobs ) );
		}

		$posts = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'meta_key'               => self::GROUP_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		// Count distinct content groups, not individual linked posts.
		$groups    = array();
		$published = array();
		foreach ( $posts as $p ) {
			$g = (string) get_post_meta( $p->ID, self::GROUP_META, true );
			if ( '' === $g ) {
				continue;
			}
			$groups[ $g ] = true;
			if ( 'publish' === $p->post_status ) {
				$published[ $g ] = true;
			}
		}
		$stats['content']   = count( $groups );
		$stats['published'] = count( $published );
		// Anything with no live post yet is still waiting to go out.
		$stats['pending'] = max( 0, $stats['content'] - $stats['published'] );

		return array_merge( $stats, self::lead_stats( $with_leads, $with_jobs ) );
	}

	/**
	 * The counts that do not depend on the content library: the leads table and
	 * the jobs board.
	 *
	 * @param bool $with_leads Whether to count leads.
	 * @param bool $with_jobs  Whether to count jobs.
	 * @return array<string,int> Only the keys that were asked for.
	 */
	private static function lead_stats( bool $with_leads, bool $with_jobs ): array {
		global $wpdb;

		$stats = array();

		if ( $with_leads ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stats['leads']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations" );
			$stats['leads_new'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations WHERE status IN ( 'new', 'new_lead' )"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( $with_jobs ) {
			$job_ids       = get_posts(
				array(
					'post_type'      => 'kivun_job',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$stats['jobs'] = count( $job_ids );
		}

		return $stats;
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
	 * The button's insides, for an action drawn as an icon.
	 *
	 * An icon-only control still has to say what it is. The word that used
	 * to be on the button becomes the accessible name and the tooltip, so
	 * nothing is lost to a screen reader or to someone who hovers to check
	 * before clicking — only to the pixels.
	 *
	 * @param string $icon  Icon name, or '' to keep the label as text.
	 * @param string $label The word.
	 * @return array{0:string,1:string} The extra attributes, and the content.
	 */
	private static function icon_button_parts( string $icon, string $label ): array {
		if ( '' === $icon ) {
			return array( '', esc_html( $label ) );
		}

		return array(
			' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"',
			kivun_icon( $icon ),
		);
	}

	/**
	 * Build a small stand-alone delete form (posts outside the main form).
	 *
	 * @param string $group     The content group id.
	 * @param string $page_url  Redirect target after deletion.
	 * @param string $label     Button label.
	 * @param string $btn_class Button CSS classes.
	 * @param string $icon      Icon name to draw instead of the label, or ''.
	 * @return string Escaped HTML.
	 */
	private static function delete_form( string $group, string $page_url, string $label, string $btn_class, string $icon = '' ): string {
		list( $attrs, $content ) = self::icon_button_parts( $icon, $label );

		return '<form class="kivun-cc-delete-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="kivun_delete_content_front">'
			. '<input type="hidden" name="group" value="' . esc_attr( $group ) . '">'
			. '<input type="hidden" name="redirect" value="' . esc_url( $page_url ) . '">'
			. '<input type="hidden" name="kivun_cc_del_nonce" value="' . esc_attr( wp_create_nonce( 'kivun_delete_content_front' ) ) . '">'
			. '<button type="submit" class="' . esc_attr( $btn_class ) . '"' . $attrs . '>' . $content . '</button>'
			. '</form>';
	}

	/**
	 * Build a small stand-alone "duplicate this post" form.
	 *
	 * @param int    $post_id   The specific post to duplicate.
	 * @param string $page_url  Redirect target after duplication.
	 * @param string $btn_class Button CSS classes.
	 * @param string $icon      Icon name to draw instead of the label, or ''.
	 * @return string Escaped HTML.
	 */
	private static function duplicate_form( int $post_id, string $page_url, string $btn_class, string $icon = '' ): string {
		list( $attrs, $content ) = self::icon_button_parts( $icon, __( 'שכפל', 'kivun' ) );

		return '<form class="kivun-cc-dup-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="kivun_cc_duplicate_front">'
			. '<input type="hidden" name="post" value="' . (int) $post_id . '">'
			. '<input type="hidden" name="redirect" value="' . esc_url( $page_url ) . '">'
			. '<input type="hidden" name="kivun_cc_dup_nonce" value="' . esc_attr( wp_create_nonce( 'kivun_cc_duplicate_front' ) ) . '">'
			. '<button type="submit" class="' . esc_attr( $btn_class ) . '"' . $attrs . '>' . $content . '</button>'
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

		// The archive mark is not copied: a duplicate is new work, and it
		// would otherwise be born inside the shut section where nobody is
		// looking for the thing they just made.
		// The centre's record id is not copied either, nor the state that
		// goes with it. It names a record on their side that belongs to
		// the original: a copy carrying it would push over that record
		// and replace it, rather than making one of its own — and the
		// thing it overwrote is already out there being read.
		$skip = array(
			'_edit_lock',
			'_edit_last',
			self::GROUP_META,
			self::ARCHIVED_META,
			Kivun_Mercaz_Sync::REMOTE_ID,
			Kivun_Mercaz_Sync::SYNCED_AT,
			Kivun_Mercaz_Sync::LAST_ERROR,
		);
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

		// The registration link for the WhatsApp promo. A landing page is the
		// natural destination; otherwise the first published post in the group.
		$group_view_url = '';
		foreach ( array( 'landing', 'course', 'session', 'event' ) as $kivun_pref ) {
			if ( ! empty( $group_posts[ $kivun_pref ] ) && 'publish' === get_post_status( $group_posts[ $kivun_pref ] ) ) {
				$group_view_url = (string) get_permalink( $group_posts[ $kivun_pref ] );
				break;
			}
		}

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
		$share_url     = (int) $v['share_id'] ? (string) wp_get_attachment_image_url( (int) $v['share_id'], 'medium' ) : '';
		$ev_img_url    = (int) $v['event_image_id'] ? (string) wp_get_attachment_image_url( (int) $v['event_image_id'], 'medium' ) : '';
		$sections      = array(
			'landing' => __( 'דף נחיתה', 'kivun' ),
			'course'  => __( 'קורס', 'kivun' ),
			'session' => __( 'סדנה', 'kivun' ),
			'event'   => __( 'אירוע', 'kivun' ),
		);
		?>
		<div class="kivun-cc-front">

			<?php if ( $saved ) : ?>
				<?php
				/*
				 * Marked apart from the other two success notices on this
				 * screen — deleted and duplicated — because only this one
				 * is the design's charcoal card. They are three different
				 * events that happen to share a class.
				 */
				?>
				<div class="kivun-cc-note kivun-cc-note--success kivun-cc-published">
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

			<form class="kivun-cc-form kivun-cc-wizard" data-current="1" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="kivun_create_content_front">
				<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
				<input type="hidden" name="redirect" value="<?php echo esc_url( $page_url ); ?>">
				<?php wp_nonce_field( 'kivun_create_content_front', 'kivun_cc_front_nonce' ); ?>

				<?php
				$kivun_steps = array(
					1 => __( 'מה מפרסמים', 'kivun' ),
					2 => __( 'תוכן', 'kivun' ),
					3 => __( 'פרטים', 'kivun' ),
					4 => __( 'מדיה ופרסום', 'kivun' ),
				);
				?>

				<?php
				/*
				 * The wizard's body, laid out as the publish handoff draws it.
				 *
				 * Everything the save reads and everything the script binds
				 * to is unchanged: the same names, ids and classes on the same
				 * controls. What changed is what wraps them. Three wrappers
				 * recur and are worth naming once:
				 *
				 * - `kivun-cc-cardhead` — a card's icon disc, title and helper,
				 *   as one row. The helper under a title is plain text in the
				 *   design, not the info box a hint is elsewhere.
				 * - `kivun-cc-field` — the box a field and its dictation button
				 *   share. voice.js puts the button right after the field, so
				 *   it lands inside this box and can be placed against it,
				 *   where before it had to be floated back up by the field's
				 *   own height.
				 * - `kivun-cc-drop` — a file picker drawn as the design's
				 *   dashed zone. The real input is inside it and still does
				 *   the work; the pill and the file name beside it are what
				 *   it looks like.
				 */
				$kivun_upload_svg = '<svg class="kivun-cc-drop__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 16V4 M7 9l5-5 5 5 M4 20h16"/></svg>';
				?>

				<div class="kivun-cc-grid">
					<div class="kivun-cc-main">

						<div class="kivun-cc-card kivun-cc-genai" data-step="1" style="order:2">
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip kivun-cc-chip--ai" aria-hidden="true"><?php echo kivun_duo_icon( 'spark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'יצירת תוכן אוטומטית (AI)', 'kivun' ); ?></h3>
									<p class="kivun-cc-help"><?php esc_html_e( 'תארו את הנושא וה-AI ימלא כותרת, תיאורים ופרטים — תוכלו לערוך אחר כך.', 'kivun' ); ?></p>
								</div>
							</div>
							<?php if ( ! $ai_configured ) : ?>
								<p class="kivun-cc-ai-warn"><?php esc_html_e( '⚠️ היצירה האוטומטית אינה פעילה כרגע (לא הוגדר מפתח API / נגמרו הקרדיטים). פנו למנהל האתר.', 'kivun' ); ?></p>
							<?php endif; ?>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-gen-tone"><?php esc_html_e( 'סגנון כתיבה', 'kivun' ); ?></label>
								<select id="kivun-ccf-gen-tone" class="kivun-cc-input kivun-cc-gen-tone kivun-cc-input--w360">
									<?php foreach ( Kivun_AI_Content::tones() as $tkey => $tlabel ) : ?>
										<option value="<?php echo esc_attr( $tkey ); ?>"><?php echo esc_html( $tlabel ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-gen-topic"><?php esc_html_e( 'נושא התוכן', 'kivun' ); ?></label>
								<span class="kivun-cc-field kivun-cc-field--topic">
									<textarea id="kivun-ccf-gen-topic" class="kivun-cc-input kivun-cc-textarea kivun-cc-gen-topic" rows="4" placeholder="<?php esc_attr_e( 'למשל: סדנת הורים-מתבגרים בת 4 מפגשים בערבים', 'kivun' ); ?>"></textarea>
								</span>
								<p class="kivun-cc-recnote" aria-hidden="true"><?php esc_html_e( 'מקליט… לחצו שוב לעצירה', 'kivun' ); ?></p>
							</div>
							<?php if ( $can_upload ) : ?>
								<div class="kivun-cc-fieldset">
									<span class="kivun-cc-sub" id="kivun-ccf-gen-image-label"><?php esc_html_e( 'או העלו מודעה מעוצבת — וה-AI יחלץ ממנה את הפרטים', 'kivun' ); ?> <span class="kivun-cc-opt"><?php esc_html_e( '(אופציונלי)', 'kivun' ); ?></span></span>
									<label class="kivun-cc-drop kivun-cc-drop--row">
										<input type="file" class="kivun-cc-gen-image kivun-cc-drop__input" accept="image/*" aria-labelledby="kivun-ccf-gen-image-label">
										<span class="kivun-cc-drop__btn" aria-hidden="true"><?php echo $kivun_upload_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'בחירת קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-drop__name" data-empty="<?php esc_attr_e( 'לא נבחר קובץ', 'kivun' ); ?>"><?php esc_html_e( 'לא נבחר קובץ', 'kivun' ); ?></span>
										<button type="button" class="kivun-cc-drop__clear" hidden><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
									</label>
								</div>
							<?php endif; ?>
							<div class="kivun-cc-actions">
								<button type="button" class="kivun-cc-btn kivun-cc-btn--ai kivun-cc-btn--ai-lg kivun-cc-gen-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_content' ) ); ?>">
									<?php echo kivun_duo_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור תוכן', 'kivun' ); ?>
								</button>
								<span class="kivun-cc-gen-status" role="status" aria-live="polite"></span>
							</div>
						</div>

						<div class="kivun-cc-card" data-step="2" style="order:1">
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?> <span class="kivun-cc-req">*</span></label>
								<span class="kivun-cc-field">
									<input type="text" id="kivun-ccf-title" name="title" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['title'] ); ?>" placeholder="<?php esc_attr_e( 'שם הקורס, הסדנה או האירוע', 'kivun' ); ?>" required>
								</span>
								<p class="kivun-cc-err"><?php esc_html_e( 'יש למלא כותרת כדי להמשיך.', 'kivun' ); ?></p>
							</div>

							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-slug"><?php esc_html_e( 'כתובת URL (Slug)', 'kivun' ); ?></label>
								<?php
								/*
								 * The address is one field with its own prefix shown
								 * inside it, so what is being typed reads as the tail
								 * of a real URL rather than a bare word.
								 *
								 * The prefix is decoration: it is not an input, carries
								 * no name, and nothing is submitted for it. The input
								 * keeps its id, name, value and dir exactly as before,
								 * and the dictation button still attaches to the field
								 * and lands inside this wrapper, which is where it
								 * should sit anyway.
								 */
								?>
								<span class="kivun-cc-slugfield" dir="ltr">
									<span class="kivun-cc-slugfield__prefix" aria-hidden="true">kivun.org.il/</span>
									<input type="text" id="kivun-ccf-slug" name="slug" class="kivun-cc-input kivun-cc-slugfield__input" dir="ltr" value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="my-content">
								</span>
								<p class="kivun-cc-dothint"><?php esc_html_e( 'ריק = נוצר אוטומטית מהכותרת.', 'kivun' ); ?></p>
							</div>
						</div>

						<div class="kivun-cc-card kivun-cc-editors" data-step="2" style="order:2">
							<div class="kivun-cc-fieldset">
								<span class="kivun-cc-sub"><?php esc_html_e( 'תיאור קצר', 'kivun' ); ?></span>
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
										'editor_height' => 120,
									)
								);
								?>
							</div>

							<div class="kivun-cc-fieldset">
								<span class="kivun-cc-sub"><?php esc_html_e( 'תיאור מלא', 'kivun' ); ?></span>
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
										'editor_height' => 200,
									)
								);
								?>
							</div>
						</div>

						<div class="kivun-cc-card" data-step="3" style="order:1">
							<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'פרטים', 'kivun' ); ?></h3>
							<div class="kivun-cc-details">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-audience"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'people' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></label>
									<span class="kivun-cc-field kivun-cc-field--top">
										<textarea id="kivun-ccf-audience" name="audience" class="kivun-cc-input kivun-cc-textarea" rows="2"><?php echo esc_textarea( $v['audience'] ); ?></textarea>
									</span>
								</div>
								<div class="kivun-cc-fieldset kivun-cc-nonevent">
									<label class="kivun-cc-sub" for="kivun-ccf-duration"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'משך', 'kivun' ); ?></label>
									<span class="kivun-cc-field kivun-cc-field--top">
										<textarea id="kivun-ccf-duration" name="duration" class="kivun-cc-input kivun-cc-textarea" rows="2"><?php echo esc_textarea( $v['duration'] ); ?></textarea>
									</span>
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-cost"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'עלות', 'kivun' ); ?></label>
									<span class="kivun-cc-field kivun-cc-field--top">
										<textarea id="kivun-ccf-cost" name="cost" class="kivun-cc-input kivun-cc-textarea" rows="2" placeholder="<?php esc_attr_e( 'חינם / 120 ₪', 'kivun' ); ?>"><?php echo esc_textarea( $v['cost'] ); ?></textarea>
									</span>
								</div>
								<div class="kivun-cc-fieldset kivun-cc-nonevent">
									<label class="kivun-cc-sub" for="kivun-ccf-date"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'cal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'תאריך / מועד', 'kivun' ); ?></label>
									<span class="kivun-cc-field kivun-cc-field--top">
										<textarea id="kivun-ccf-date" name="date" class="kivun-cc-input kivun-cc-textarea" rows="2" placeholder="<?php esc_attr_e( '15.9.2025 בשעה 18:00', 'kivun' ); ?>"><?php echo esc_textarea( $v['date'] ); ?></textarea>
									</span>
								</div>
							</div>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-email"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'אימייל לקבלת לידים/הרשמות', 'kivun' ); ?></label>
								<span class="kivun-cc-field">
									<input type="email" id="kivun-ccf-email" name="email" class="kivun-cc-input kivun-cc-input--lg kivun-cc-input--ltr" dir="ltr" value="<?php echo esc_attr( $v['email'] ); ?>" placeholder="name@kivun.org.il">
								</span>
								<p class="kivun-cc-err"><?php esc_html_e( 'כתובת האימייל לא תקינה.', 'kivun' ); ?></p>
							</div>
						</div>

						<div class="kivun-cc-card" data-step="4" style="order:2">
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'cta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'באנר הנעה לפעולה (CTA)', 'kivun' ); ?></h3>
								</div>
							</div>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-cta-title"><?php esc_html_e( 'כותרת', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="text" id="kivun-ccf-cta-title" name="cta_title" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['cta_title'] ); ?>">
									</span>
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-cta-button"><?php esc_html_e( 'טקסט כפתור', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="text" id="kivun-ccf-cta-button" name="cta_button" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['cta_button'] ); ?>">
									</span>
								</div>
							</div>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-cta-content"><?php esc_html_e( 'תוכן', 'kivun' ); ?></label>
								<span class="kivun-cc-field kivun-cc-field--top">
									<textarea id="kivun-ccf-cta-content" name="cta_content" class="kivun-cc-input kivun-cc-textarea" rows="3"><?php echo esc_textarea( $v['cta_content'] ); ?></textarea>
								</span>
							</div>
							<?php
							/*
							 * A picture of the banner, drawn from the three fields
							 * above as they are typed. Nothing in it is submitted —
							 * it has no inputs — so it cannot disagree with what
							 * gets saved; it can only be a moment behind it.
							 */
							?>
							<div class="kivun-cc-ctaprev" hidden>
								<span class="kivun-cc-ctaprev__label"><?php esc_html_e( 'תצוגה מקדימה', 'kivun' ); ?></span>
								<div class="kivun-cc-ctaprev__card">
									<div class="kivun-cc-ctaprev__text">
										<div class="kivun-cc-ctaprev__title"></div>
										<div class="kivun-cc-ctaprev__body"></div>
									</div>
									<span class="kivun-cc-ctaprev__btn" hidden><span class="kivun-cc-ctaprev__btntext"></span></span>
								</div>
							</div>
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-step="3" style="order:2" data-type="course" <?php echo isset( $group_posts['course'] ) ? '' : 'hidden'; ?>>
							<h3 class="kivun-cc-h3 kivun-cc-cardtitle"><?php esc_html_e( 'קורס — פרטים נוספים', 'kivun' ); ?></h3>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-course-capacity"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
									<input type="number" id="kivun-ccf-course-capacity" name="course_capacity" class="kivun-cc-input kivun-cc-input--lg" min="0" value="<?php echo esc_attr( $v['course_capacity'] ); ?>">
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-course-wc"><?php esc_html_e( 'מזהה מוצר WooCommerce (אופציונלי)', 'kivun' ); ?></label>
									<input type="number" id="kivun-ccf-course-wc" name="course_wc" class="kivun-cc-input kivun-cc-input--lg" min="0" value="<?php echo esc_attr( $v['course_wc'] ); ?>">
								</div>
							</div>
							<p class="kivun-cc-hint"><?php esc_html_e( 'לקורס בתשלום: מזהה מוצר WooCommerce. ריק = חינם / לפי שדה העלות.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-step="3" style="order:3" data-type="session" <?php echo isset( $group_posts['session'] ) ? '' : 'hidden'; ?>>
							<h3 class="kivun-cc-h3 kivun-cc-cardtitle"><?php esc_html_e( 'סדנה — פרטים נוספים', 'kivun' ); ?></h3>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-session-location"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
								<span class="kivun-cc-field">
									<input type="text" id="kivun-ccf-session-location" name="session_location" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['session_location'] ); ?>">
								</span>
							</div>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-session-capacity"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
									<input type="number" id="kivun-ccf-session-capacity" name="session_capacity" class="kivun-cc-input kivun-cc-input--lg" min="0" value="<?php echo esc_attr( $v['session_capacity'] ); ?>">
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-session-valid"><?php esc_html_e( 'ההרשמה למחזור הנוכחי פתוחה עד', 'kivun' ); ?></label>
									<input type="date" id="kivun-ccf-session-valid" name="session_valid_until" class="kivun-cc-input kivun-cc-input--lg" dir="ltr" value="<?php echo esc_attr( $v['session_valid'] ); ?>">
								</div>
							</div>
							<?php
							/*
							 * "סדנה שוטפת": a workshop that runs in cycles. Switched
							 * on, it asks when the next cycle opens, and the page
							 * says so once this cycle's date has passed. A block,
							 * not a span, for the switch: it holds a field set, and
							 * wpautop() would split a block out of an inline one.
							 */
							?>
							<div class="kivun-cc-sessrepeat">
								<label class="kivun-cc-optcheck kivun-cf-toggle">
									<input type="checkbox" name="session_recurring" value="1" class="kivun-ses-switch" <?php checked( '1', $v['session_repeat'] ); ?>>
									<span class="kivun-cf-track" aria-hidden="true"></span>
									<span><?php esc_html_e( 'סדנה קבועה (שוטפת) — חוזרת במחזורים', 'kivun' ); ?></span>
								</label>
								<div class="kivun-cc-fieldset kivun-cc-sessnext">
									<label class="kivun-cc-sub" for="kivun-ccf-session-next"><?php esc_html_e( 'המחזור הבא נפתח ב־', 'kivun' ); ?></label>
									<input type="date" id="kivun-ccf-session-next" name="session_next_open" class="kivun-cc-input kivun-cc-input--lg" dir="ltr" value="<?php echo esc_attr( $v['session_next'] ); ?>">
								</div>
							</div>
							<p class="kivun-cc-hint"><?php esc_html_e( 'עד התאריך נרשמים למחזור הנוכחי. אחריו ההרשמה ממשיכה ונשמרת למחזור הבא — ובסדנה קבועה הדף מציג מתי הוא נפתח. כשמחזור חדש נפתח, מעדכנים את התאריכים.', 'kivun' ); ?></p>
						</div>

						<div class="kivun-cc-card kivun-cc-section" data-step="3" style="order:4" data-type="event" <?php echo isset( $group_posts['event'] ) ? '' : 'hidden'; ?>>
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'cal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-h3 kivun-cc-cardtitle"><?php esc_html_e( 'אירוע — פרטים נוספים', 'kivun' ); ?></h3>
									<p class="kivun-cc-help"><?php esc_html_e( 'לאירוע יש שדות ייחודיים משלו כאן. המועד ותאריך האירוע נלקחים מהשדות שבבלוק זה — לא מהשדות "משך" / "תאריך" הכלליים שמעלה.', 'kivun' ); ?></p>
								</div>
							</div>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-date"><?php esc_html_e( 'תאריך האירוע (סוגר את ההרשמה)', 'kivun' ); ?></label>
									<input type="date" id="kivun-ccf-event-date" name="event_date" class="kivun-cc-input kivun-cc-input--lg" dir="ltr" value="<?php echo esc_attr( $v['event_date'] ); ?>">
									<p class="kivun-cc-dothint"><?php esc_html_e( 'לאחר תאריך זה ההרשמה נסגרת לצמיתות. ריק = תמיד פתוח.', 'kivun' ); ?></p>
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-time"><?php esc_html_e( 'מועד מלא (שעה / משך — לתצוגה)', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="text" id="kivun-ccf-event-time" name="event_time" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['event_time'] ); ?>" placeholder="<?php esc_attr_e( 'יום ג׳, 15.9.2025, 18:00–20:00', 'kivun' ); ?>">
									</span>
								</div>
							</div>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-location"><?php esc_html_e( 'מיקום', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="text" id="kivun-ccf-event-location" name="event_location" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['event_location'] ); ?>">
									</span>
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-capacity"><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></label>
									<input type="number" id="kivun-ccf-event-capacity" name="event_capacity" class="kivun-cc-input kivun-cc-input--lg" min="0" value="<?php echo esc_attr( $v['event_capacity'] ); ?>">
								</div>
							</div>
							<div class="kivun-cc-fieldset">
								<label class="kivun-cc-sub" for="kivun-ccf-event-mode"><?php esc_html_e( 'אופן ההרשמה', 'kivun' ); ?></label>
								<select id="kivun-ccf-event-mode" name="event_mode" class="kivun-cc-input kivun-cc-input--w440">
									<option value="form" <?php selected( $v['event_mode'], 'form' ); ?>><?php esc_html_e( 'טופס באתר (נשמר בטבלת הלידים)', 'kivun' ); ?></option>
									<option value="external" <?php selected( $v['event_mode'], 'external' ); ?>><?php esc_html_e( 'כפתור לקישור חיצוני', 'kivun' ); ?></option>
								</select>
							</div>
							<div class="kivun-cc-pair">
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-url"><?php esc_html_e( 'קישור הרשמה חיצוני', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="url" id="kivun-ccf-event-url" name="event_url" class="kivun-cc-input kivun-cc-input--lg kivun-cc-input--ltr" dir="ltr" value="<?php echo esc_attr( $v['event_url'] ); ?>" placeholder="https://...">
									</span>
								</div>
								<div class="kivun-cc-fieldset">
									<label class="kivun-cc-sub" for="kivun-ccf-event-button"><?php esc_html_e( 'טקסט כפתור ההרשמה', 'kivun' ); ?></label>
									<span class="kivun-cc-field">
										<input type="text" id="kivun-ccf-event-button" name="event_button" class="kivun-cc-input kivun-cc-input--lg" value="<?php echo esc_attr( $v['event_button'] ); ?>" placeholder="<?php esc_attr_e( 'להרשמה לאירוע', 'kivun' ); ?>">
									</span>
								</div>
							</div>

							<div class="kivun-cc-fieldset">
								<span class="kivun-cc-sub"><?php esc_html_e( 'תמונת האירוע (מלבנית צרה/גבוהה — לפופאפ)', 'kivun' ); ?></span>
								<?php if ( (int) $v['event_image_id'] && $ev_img_url ) : ?>
									<div class="kivun-cc-media__preview kivun-cc-evimg"><img src="<?php echo esc_url( $ev_img_url ); ?>" alt=""></div>
								<?php endif; ?>
								<input type="hidden" name="event_image_id" value="<?php echo esc_attr( $v['event_image_id'] ); ?>">
								<?php if ( $can_upload ) : ?>
									<label class="kivun-cc-drop kivun-cc-drop--row">
										<input type="file" name="event_image_file" accept="image/*" class="kivun-cc-drop__input">
										<span class="kivun-cc-drop__btn" aria-hidden="true"><?php echo $kivun_upload_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'בחירת קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-drop__name" data-empty="<?php esc_attr_e( 'לא נבחר קובץ', 'kivun' ); ?>"><?php esc_html_e( 'לא נבחר קובץ', 'kivun' ); ?></span>
										<button type="button" class="kivun-cc-drop__clear" hidden><?php esc_html_e( 'הסרה', 'kivun' ); ?></button>
									</label>
								<?php else : ?>
									<p class="kivun-cc-hint"><?php esc_html_e( 'אין לך הרשאה להעלות קבצים — התמונה תיקבע ע"י מנהל.', 'kivun' ); ?></p>
								<?php endif; ?>
							</div>

							<label class="kivun-cc-optcheck">
								<input type="checkbox" name="event_popup" value="1" <?php checked( '1', $v['event_popup'] ); ?>>
								<span><?php esc_html_e( 'הצגת פופאפ באתר עד מועד האירוע', 'kivun' ); ?></span>
							</label>
						</div>

						<div class="kivun-cc-card kivun-cc-typescard" data-step="1" style="order:1">
							<div class="kivun-cc-cardhead">
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'מה לפרסם?', 'kivun' ); ?></h3>
									<p class="kivun-cc-help kivun-cc-types-help"><?php esc_html_e( 'בחרו לפחות סוג אחד.', 'kivun' ); ?></p>
								</div>
							</div>
							<?php
							$kivun_type_icons = array(
								'landing' => 'page',
								'course'  => 'cap',
								'session' => 'people',
								'event'   => 'cal',
							);
							?>
							<div class="kivun-cc-types">
								<?php foreach ( $sections as $key => $label ) : ?>
									<label class="kivun-cc-check">
										<input type="checkbox" name="publish[]" value="<?php echo esc_attr( $key ); ?>" class="kivun-cc-toggle" data-type="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $group_posts[ $key ] ) ); ?>>
										<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( isset( $kivun_type_icons[ $key ] ) ? $kivun_type_icons[ $key ] : 'page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
										<span><?php echo esc_html( $label ); ?></span>
										<?php if ( isset( $group_posts[ $key ] ) ) : ?>
											<a class="kivun-cc-view" href="<?php echo esc_url( (string) get_permalink( $group_posts[ $key ] ) ); ?>" target="_blank" rel="noopener">↗</a>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</div>
							<?php if ( $editing ) : ?>
								<a class="kivun-cc-link" href="<?php echo esc_url( remove_query_arg( array( 'kivun_group', 'kivun_saved', 'kivun_cc_error' ), $page_url ) ); ?>"><?php esc_html_e( 'תוכן חדש', 'kivun' ); ?></a>
							<?php endif; ?>
						</div>

						<div class="kivun-cc-card" data-step="4" style="order:1">
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'img' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'תמונה ראשית', 'kivun' ); ?></h3>
								</div>
							</div>
							<div class="kivun-cc-media kivun-cc-media--hero">
								<input type="hidden" class="kivun-cc-media__id" name="thumbnail_id" value="<?php echo esc_attr( $v['thumb_id'] ); ?>">
								<input type="hidden" name="remove_thumbnail" value="0" class="kivun-cc-remove-flag">
								<?php if ( $can_upload ) : ?>
									<?php
									/*
									 * The zone is the picker. The preview is laid over
									 * it once there is an image, and lets clicks through,
									 * so clicking the picture still opens the picker to
									 * replace it — the way the design's zone behaves.
									 */
									?>
									<label class="kivun-cc-drop kivun-cc-drop--zone">
										<input type="file" name="thumbnail_file" accept="image/*" class="kivun-cc-file kivun-cc-drop__input">
										<span class="kivun-cc-drop__btn" aria-hidden="true"><?php echo $kivun_upload_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'בחירת קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-drop__name" data-empty="<?php esc_attr_e( 'לא נבחר קובץ', 'kivun' ); ?>"><?php esc_html_e( 'לא נבחר קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-media__preview" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $thumb_url ); ?>" alt=""></span>
									</label>
									<div class="kivun-cc-media__side">
										<div class="kivun-cc-media__current">
											<button type="button" class="kivun-cc-media__remove"<?php echo $thumb_url ? '' : ' hidden'; ?>><?php esc_html_e( 'הסרת התמונה', 'kivun' ); ?></button>
										</div>
										<div class="kivun-cc-ai">
											<?php if ( ! $ai_configured ) : ?>
												<p class="kivun-cc-ai-warn"><?php esc_html_e( '⚠️ היצירה האוטומטית אינה פעילה כרגע (לא הוגדר מפתח API / נגמרו הקרדיטים). פנו למנהל האתר.', 'kivun' ); ?></p>
											<?php endif; ?>
											<div class="kivun-cc-fieldset">
												<label class="kivun-cc-sub" for="kivun-ccf-ai-style"><?php esc_html_e( 'סגנון התמונה', 'kivun' ); ?></label>
												<select id="kivun-ccf-ai-style" class="kivun-cc-input kivun-cc-ai-style kivun-cc-input--w440">
													<?php foreach ( Kivun_AI_Image::styles() as $skey => $slabel ) : ?>
														<option value="<?php echo esc_attr( $skey ); ?>"><?php echo esc_html( $slabel ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
											<div class="kivun-cc-fieldset">
												<label class="kivun-cc-sub" for="kivun-ccf-ai-prompt"><?php esc_html_e( 'תיאור חופשי לתמונה', 'kivun' ); ?> <span class="kivun-cc-opt"><?php esc_html_e( '(אופציונלי)', 'kivun' ); ?></span></label>
												<span class="kivun-cc-field kivun-cc-field--top">
													<textarea id="kivun-ccf-ai-prompt" class="kivun-cc-input kivun-cc-textarea kivun-cc-ai-prompt" rows="3" placeholder="<?php esc_attr_e( 'אם ריק — התמונה תיווצר מהכותרת והתיאור הקצר של העמוד', 'kivun' ); ?>"></textarea>
												</span>
											</div>
											<div class="kivun-cc-actions">
												<button type="button" class="kivun-cc-btn kivun-cc-btn--ai kivun-cc-ai-btn" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_image' ) ); ?>">
													<?php echo kivun_duo_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור תמונה עם AI', 'kivun' ); ?>
												</button>
												<span class="kivun-cc-ai-status" role="status" aria-live="polite"></span>
											</div>
										</div>
									</div>
								<?php else : ?>
									<div class="kivun-cc-media__preview" <?php echo $thumb_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $thumb_url ); ?>" alt=""></div>
									<p class="kivun-cc-hint"><?php esc_html_e( 'אין לך הרשאה להעלות קבצים — התמונה תיקבע ע"י מנהל.', 'kivun' ); ?></p>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-cc-card" data-step="4" style="order:1">
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'img' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'תמונה לשיתוף קישור', 'kivun' ); ?></h3>
									<p class="kivun-cc-help"><?php esc_html_e( 'לא מוצגת בעמוד. היא מה שרואים כששולחים את הקישור בוואטסאפ או מפרסמים אותו ברשתות. מומלץ 1200×630.', 'kivun' ); ?></p>
								</div>
							</div>
							<div class="kivun-cc-media kivun-cc-media--hero">
								<input type="hidden" class="kivun-cc-media__id" name="share_image_id" value="<?php echo esc_attr( $v['share_id'] ); ?>">
								<input type="hidden" name="remove_share_image" value="0" class="kivun-cc-remove-flag">
								<?php if ( $can_upload ) : ?>
									<label class="kivun-cc-drop kivun-cc-drop--zone">
										<input type="file" name="share_image_file" accept="image/*" class="kivun-cc-file kivun-cc-drop__input">
										<span class="kivun-cc-drop__btn" aria-hidden="true"><?php echo $kivun_upload_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'בחירת קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-drop__name" data-empty="<?php esc_attr_e( 'לא נבחר קובץ', 'kivun' ); ?>"><?php esc_html_e( 'לא נבחר קובץ', 'kivun' ); ?></span>
										<span class="kivun-cc-media__preview" <?php echo $share_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $share_url ); ?>" alt=""></span>
									</label>
									<div class="kivun-cc-media__side">
										<div class="kivun-cc-media__current">
											<button type="button" class="kivun-cc-media__remove"<?php echo $share_url ? '' : ' hidden'; ?>><?php esc_html_e( 'הסרת התמונה', 'kivun' ); ?></button>
										</div>
										<p class="kivun-cc-hint"><?php esc_html_e( 'אם לא נבחרה — תוצג התמונה הראשית.', 'kivun' ); ?></p>
									</div>
								<?php else : ?>
									<div class="kivun-cc-media__preview" <?php echo $share_url ? '' : 'style="display:none"'; ?>><img src="<?php echo esc_url( $share_url ); ?>" alt=""></div>
									<p class="kivun-cc-hint"><?php esc_html_e( 'אם לא נבחרה — תוצג התמונה הראשית.', 'kivun' ); ?></p>
								<?php endif; ?>
							</div>
						</div>

						<div class="kivun-cc-card kivun-cc-card--tight" data-step="3" style="order:5">
							<label class="kivun-cc-sub" for="kivun-ccf-city"><span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span><?php esc_html_e( 'יישוב', 'kivun' ); ?></label>
							<span class="kivun-cc-field">
								<input
									type="text"
									id="kivun-ccf-city"
									name="city"
									class="kivun-cc-input kivun-cc-input--lg"
									list="kivun-city-list"
									autocomplete="off"
									value="<?php echo esc_attr( $v['city'] ); ?>"
									placeholder="<?php esc_attr_e( 'למשל: ירושלים', 'kivun' ); ?>"
								>
							</span>
							<datalist id="kivun-city-list"></datalist>
							<p class="kivun-field-hint"><?php esc_html_e( 'היישוב שבו מתקיים התוכן. נשלח למרכז כיוון — תוכן ללא יישוב לא מופיע שם בחיפוש לפי מרחק.', 'kivun' ); ?></p>
						</div>

						<?php
						/*
						 * The sign-up form of the landing page, built here while
						 * the page is written, and only for a landing page: this
						 * card shows and hides with that type, as the course and
						 * event cards do with theirs.
						 *
						 * Switched off, the page keeps the fixed form; switched
						 * on, it asks what is built below. The hidden field tells
						 * the save that this card was there at all, so a save
						 * from a form without it never turns a form off.
						 */
						$kivun_landing_id = isset( $group_posts['landing'] ) ? (int) $group_posts['landing'] : 0;
						$kivun_form_on    = $kivun_landing_id && Kivun_Custom_Forms::enabled( $kivun_landing_id );
						?>
						<div class="kivun-cc-card kivun-cc-section kivun-cc-formcard" data-step="3" style="order:6" data-type="landing" <?php echo isset( $group_posts['landing'] ) ? '' : 'hidden'; ?>>
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<h3 class="kivun-cc-cardtitle"><?php esc_html_e( 'טופס ההרשמה של דף הנחיתה', 'kivun' ); ?></h3>
									<p class="kivun-cc-help"><?php esc_html_e( 'הטופס הקבוע שואל שם, טלפון, אימייל, עיר ומגדר. אפשר במקומו לבנות לדף טופס משלו — השאלות שלכם, בסדר שלכם.', 'kivun' ); ?></p>
								</div>
							</div>
							<input type="hidden" name="custom_form_present" value="1">
							<label class="kivun-cc-optcheck kivun-cf-toggle">
								<input type="checkbox" name="custom_form_on" value="1" class="kivun-cf-switch" <?php checked( $kivun_form_on ); ?>>
								<span class="kivun-cf-track" aria-hidden="true"></span>
								<span><?php esc_html_e( 'טופס מותאם לדף הזה', 'kivun' ); ?></span>
							</label>
							<div class="kivun-cf-wrap">
								<?php self::custom_form_builder( $kivun_landing_id ); ?>
							</div>
						</div>

						<div class="kivun-cc-card kivun-cc-wa" data-step="4" style="order:4">
							<div class="kivun-cc-cardhead kivun-cc-cardhead--split">
								<div class="kivun-cc-cardhead__main">
									<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'wa' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
									<div class="kivun-cc-cardhead__text">
										<label class="kivun-cc-cardtitle" for="kivun-ccf-whatsapp"><?php esc_html_e( 'הודעה להפצה בוואטסאפ', 'kivun' ); ?></label>
										<p class="kivun-cc-help"><?php esc_html_e( 'תמצית שיווקית של התוכן, בפורמט שמתאים לשליחה בקבוצות. אפשר לערוך אחרי היצירה — מה שיישמר כאן הוא מה שיישמר עם התוכן.', 'kivun' ); ?></p>
									</div>
								</div>
								<button
									type="button"
									class="kivun-cc-btn kivun-cc-btn--ai kivun-wa-btn"
									data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'kivun_ai_content' ) ); ?>"
									data-url="<?php echo esc_url( $group_view_url ); ?>"
								>
									<?php echo kivun_duo_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור הודעה לוואטסאפ', 'kivun' ); ?>
								</button>
							</div>
							<span class="kivun-cc-wa-status" role="status" aria-live="polite"></span>

							<span class="kivun-cc-field kivun-cc-field--top">
								<textarea
									id="kivun-ccf-whatsapp"
									name="whatsapp"
									class="kivun-cc-input kivun-cc-textarea kivun-wa-text"
									rows="8"
									dir="rtl"
									placeholder="<?php esc_attr_e( 'לחצו על "צור הודעה לוואטסאפ" — או כתבו כאן בעצמכם.', 'kivun' ); ?>"
								><?php echo esc_textarea( Kivun_Campaigns::clean_promo( (string) $v['whatsapp'] ) ); ?></textarea>
							</span>

							<div class="kivun-wa-actions">
								<button type="button" class="kivun-cc-btn kivun-cc-btn--ghost kivun-wa-copy"><?php esc_html_e( 'העתקת ההודעה', 'kivun' ); ?></button>
								<a class="kivun-cc-btn kivun-cc-btn--ghost kivun-wa-share" href="https://wa.me/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'שליחה בוואטסאפ', 'kivun' ); ?></a>
							</div>
							<?php if ( '' === $group_view_url ) : ?>
								<p class="kivun-cc-footnote"><?php esc_html_e( 'הקישור להרשמה יתווסף אחרי הפרסום — צרו את ההודעה שוב אז, או הדביקו את הכתובת ידנית.', 'kivun' ); ?></p>
							<?php endif; ?>
						</div>

						<div class="kivun-cc-card" data-step="4" style="order:3">
							<div class="kivun-cc-cardhead">
								<span class="kivun-cc-chip" aria-hidden="true"><?php echo kivun_duo_icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
								<div class="kivun-cc-cardhead__text">
									<label class="kivun-cc-cardtitle" for="kivun-ccf-status"><?php esc_html_e( 'סטטוס פרסום', 'kivun' ); ?></label>
								</div>
							</div>
							<select id="kivun-ccf-status" name="status" class="kivun-cc-input kivun-cc-status kivun-cc-input--w440">
								<option value="publish" <?php selected( $v['status'], 'publish' ); ?>><?php esc_html_e( 'פרסום מיידי — גלוי לכולם', 'kivun' ); ?></option>
								<option value="draft" <?php selected( $v['status'], 'draft' ); ?>><?php esc_html_e( 'טיוטה — לא מפורסם, נשמר לעריכה', 'kivun' ); ?></option>
								<option value="private" <?php selected( $v['status'], 'private' ); ?>><?php esc_html_e( 'פרטי — גלוי רק לבעלי הרשאה', 'kivun' ); ?></option>
								<option value="future" <?php selected( $v['status'], 'future' ); ?>><?php esc_html_e( 'מתוזמן — יפורסם אוטומטית בתאריך', 'kivun' ); ?></option>
							</select>

							<div class="kivun-cc-schedule kivun-cc-fieldset" <?php echo 'future' === $v['status'] ? '' : 'hidden'; ?>>
								<label class="kivun-cc-sub" for="kivun-ccf-schedule"><?php esc_html_e( 'תאריך ושעת הפרסום', 'kivun' ); ?></label>
								<input
									type="datetime-local"
									id="kivun-ccf-schedule"
									name="schedule"
									class="kivun-cc-input kivun-cc-input--w440"
									value="<?php echo esc_attr( $v['schedule'] ); ?>"
								>
								<p class="kivun-cc-dothint">
									<?php
									printf(
										/* translators: %s: the site's current local time. */
										esc_html__( 'התוכן יפורסם אוטומטית במועד שנבחר. השעה הנוכחית באתר: %s', 'kivun' ),
										esc_html( wp_date( 'd/m/Y H:i' ) )
									);
									?>
								</p>
							</div>

							<p class="kivun-field-hint kivun-cc-status-note">
								<?php esc_html_e( 'הסטטוס חל על כל הפריטים שנוצרים יחד — דף הנחיתה, הקורס והסדנה.', 'kivun' ); ?>
							</p>
						</div>

						<?php
						/*
						 * The bottom bar sits in the content column, under the
						 * step's cards, the way the design draws it — not across
						 * the whole width under the rail as well. It carries no
						 * data-step, so it is on every step; the script reaches
						 * its buttons by class from the form, wherever they sit.
						 *
						 * The order inside it is the reading order: where you are
						 * first, then back, then on.
						 */
						?>
						<p class="kivun-wiz-error" role="alert" hidden></p>

						<div class="kivun-wiz-nav">
							<span class="kivun-wiz-progress" role="status" aria-live="polite"></span>
							<span class="kivun-wiz-nav__go">
								<button type="button" class="kivun-cc-btn kivun-cc-btn--ghost kivun-wiz-prev" hidden>
									<?php esc_html_e( 'חזרה', 'kivun' ); ?>
								</button>
								<button type="button" class="kivun-cc-btn kivun-wiz-next">
									<?php esc_html_e( 'המשך', 'kivun' ); ?>
								</button>
								<button type="submit" class="kivun-cc-btn kivun-wiz-submit" hidden>
									<?php echo $editing ? esc_html__( 'שמירת שינויים', 'kivun' ) : esc_html__( 'פרסום התוכן', 'kivun' ); ?>
								</button>
							</span>
						</div>
					</div>

					<?php
					/*
					 * The rail. It holds what the form is and where you are in
					 * it, and nothing you fill in — so it can stay put while
					 * the steps change beside it.
					 *
					 * It sits inside the form on purpose: the step handler
					 * reads `form.querySelectorAll('.kivun-wiz-step')` and
					 * walks up from a click with `closest('.kivun-cc-wizard')`.
					 * Moved out of the form, the steps would still be drawn
					 * and would no longer navigate.
					 *
					 * Written after the cards so the form is what a screen
					 * reader meets first; the stylesheet puts it on the right.
					 */
					?>
					<aside class="kivun-cc-side">
						<div class="kivun-cc-card kivun-cc-rail">
							<div class="kivun-cc-rail__head">
								<h2 class="kivun-cc-rail__title"><?php echo $editing ? esc_html__( 'עריכת תוכן', 'kivun' ) : esc_html__( 'פרסום תוכן חדש', 'kivun' ); ?></h2>
								<p class="kivun-cc-rail__lead"><?php esc_html_e( 'מלאו את התוכן פעם אחת, סמנו מה לפרסם — דף נחיתה, קורס וסדנה נוצרים ומתעדכנים יחד.', 'kivun' ); ?></p>
							</div>

							<ol class="kivun-wiz-steps" aria-label="<?php esc_attr_e( 'שלבי פרסום', 'kivun' ); ?>">
								<?php foreach ( $kivun_steps as $kivun_n => $kivun_label ) : ?>
									<li class="kivun-wiz-step <?php echo 1 === $kivun_n ? 'is-current' : ''; ?>" data-gostep="<?php echo esc_attr( (string) $kivun_n ); ?>">
										<span class="kivun-wiz-step__num"><?php echo esc_html( (string) $kivun_n ); ?></span>
										<span class="kivun-wiz-step__label"><?php echo esc_html( $kivun_label ); ?></span>
									</li>
								<?php endforeach; ?>
							</ol>
						</div>
					</aside>
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
	 * The library's content, either the part in use or the part put away.
	 *
	 * Asked for separately rather than filtered after the fact. The list is
	 * capped at 200 and always was: past that the oldest content simply
	 * stopped appearing, with nothing on screen to say so. Two queries mean
	 * putting something away actually frees a place in the list it was
	 * crowding, which is half of what the archive is for here.
	 *
	 * @param bool $archived Which half to fetch.
	 * @return array<int,\WP_Post>
	 */
	private static function library_posts( bool $archived ): array {
		return get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => self::GROUP_META,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::ARCHIVED_META,
						'compare' => $archived ? 'EXISTS' : 'NOT EXISTS',
					),
				),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Give a landing page made outside the console a content group of its own.
	 *
	 * The library lists content by group, and the wizard opens content by
	 * group. A landing page created in WordPress's own editor, or through
	 * the REST API, has none — and the custom-forms tab, which listed every
	 * landing page whatever its origin, was the one screen that reached it.
	 * With that tab folded into the library, such a page would have gone on
	 * asking its custom questions with nowhere left to change or switch them
	 * off.
	 *
	 * So it is adopted: given a group of its own, once, the first time the
	 * library is drawn after it appeared. From then on it is a library row
	 * like any other, and opens in the wizard with its form. A page that
	 * already has a group is never touched, so a group is never split or
	 * renamed by this.
	 *
	 * @return void
	 */
	private static function adopt_ungrouped_landings(): void {
		$ids = get_posts(
			array(
				'post_type'              => 'kivun_workshop',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'                 => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => self::GROUP_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $ids as $id ) {
			// Asked again per post rather than trusted from the query, so a
			// page that gained a group in the meantime keeps the one it has.
			if ( '' === (string) get_post_meta( (int) $id, self::GROUP_META, true ) ) {
				update_post_meta( (int) $id, self::GROUP_META, wp_generate_uuid4() );
			}
		}
	}

	/**
	 * The builder for a landing page's form, inside the publishing wizard.
	 *
	 * The questions are edited as rows and posted as one definition, rather
	 * than saved one at a time: a half-applied form is a form a visitor can
	 * meet, and the order of the questions is itself part of what is being
	 * edited.
	 *
	 * It is part of the wizard's own form, so it is a `div`, not a `form` —
	 * a form inside a form is not one the browser will submit. The questions
	 * travel as JSON in `custom_form_fields`, kept in step by the script; the
	 * built-in fields are ordinary checkboxes named `custom_form_builtins[]`.
	 * save_landing_form() stores them with the rest of the content.
	 *
	 * For a page that does not exist yet the id is 0, and the builder starts
	 * from an empty list and the fixed form's set of built-in fields.
	 *
	 * @param int $post_id The landing page.
	 * @return void
	 */
	private static function custom_form_builder( int $post_id ): void {
		$fields   = Kivun_Custom_Forms::fields( $post_id );
		$builtins = Kivun_Custom_Forms::builtins( $post_id );
		?>
		<div class="kivun-form-builder" data-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="custom_form_fields" class="kivun-cf-out" value="<?php echo esc_attr( (string) wp_json_encode( $fields ) ); ?>">
			<div class="kivun-cf-split">

				<div class="kivun-cf-edit">
					<section class="kivun-cf-card">
						<h3 class="kivun-cf-card__title"><?php esc_html_e( 'שדות קבועים', 'kivun' ); ?></h3>
						<p class="kivun-cf-help">
							<?php esc_html_e( 'אלה העמודות של הליד עצמו — לפיהן עובדות חלוקת הרכזים, סינון העיר והשידור למרכז כיוון. שם וטלפון תמיד נשאלים.', 'kivun' ); ?>
						</p>
						<div class="kivun-cf-builtins">
							<?php foreach ( Kivun_Custom_Forms::optional_builtins() as $kivun_key => $kivun_label ) : ?>
								<label class="kivun-cf-builtin">
									<input type="checkbox" name="custom_form_builtins[]" class="kivun-cf-builtin-box" value="<?php echo esc_attr( $kivun_key ); ?>" <?php checked( in_array( $kivun_key, $builtins, true ) ); ?>>
									<span><?php echo esc_html( $kivun_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="kivun-cf-card">
						<div class="kivun-cf-card__head">
							<h3 class="kivun-cf-card__title"><?php esc_html_e( 'השאלות שלכם', 'kivun' ); ?></h3>
							<span class="kivun-cf-count" aria-hidden="true"></span>
						</div>

						<?php
						// Shown only while there are no questions, and hidden
						// by the script the moment one is added.
						?>
						<p class="kivun-cf-empty">
							<?php esc_html_e( 'עדיין אין שאלות. הוסיפו אחת והיא תופיע מיד בתצוגה המקדימה.', 'kivun' ); ?>
						</p>

						<div class="kivun-cf-list"></div>

						<button type="button" class="kivun-cf-add-btn">
							<?php echo kivun_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'הוספת שאלה', 'kivun' ); ?>
						</button>
						<p class="kivun-cf-help kivun-cf-dragnote">
							<?php esc_html_e( 'אפשר לגרור שאלה כדי לשנות את הסדר, או להזיז אותה בחצים.', 'kivun' ); ?>
						</p>
					</section>
				</div>

				<?php
				// The preview is built by the script from the same state the
				// save posts, so it cannot show a form that would not be
				// saved. Inert: it is there to be read, not filled in.
				?>
				<aside class="kivun-cf-preview" aria-label="<?php esc_attr_e( 'תצוגה מקדימה של הטופס', 'kivun' ); ?>">
					<div class="kivun-cf-preview__head">
						<span class="kivun-cf-preview__tag"><?php esc_html_e( 'תצוגה מקדימה', 'kivun' ); ?></span>
						<span class="kivun-cf-preview__caption"><?php esc_html_e( 'כך המבקר יראה את הטופס', 'kivun' ); ?></span>
					</div>
					<div class="kivun-cf-preview__body" aria-live="polite"></div>
				</aside>
			</div>

			<p class="kivun-error kivun-cf-error" style="display:none;color:var(--kivun-error)"></p>

			<?php
			// The definition the script starts from. A data attribute rather
			// than a script block, so nothing here can be read as code.
			?>
			<span class="kivun-cf-seed" hidden data-fields="<?php echo esc_attr( (string) wp_json_encode( $fields ) ); ?>"></span>
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
		self::adopt_ungrouped_landings();
		$groups   = self::library_groups( self::library_posts( false ) );
		$put_away = self::library_groups( self::library_posts( true ) );

		// A group is marked on every one of its posts, so the two halves do
		// not overlap. Should one ever be marked in part — a post created by
		// some path that forgot — it is listed once, in the open list, each
		// row holding only the posts that half saw. Being shown is the safe
		// side of that mistake: content nobody can find is worse than
		// content still in view after somebody filed it away.
		$put_away = array_diff_key( $put_away, $groups );

		$lead_counts = self::leads_per_content();
		?>
		<div class="kivun-cc-front">
			<div class="kivun-cc-panel kivun-lib-card">
			<?php
			/*
			 * The "התכנים שלי" handoff: one card holding the heading, the
			 * search and the live/archive switch on one row, and the list.
			 *
			 * Both lists are already here, which is why switching between them
			 * and searching need nothing from the server. Archiving leaves the
			 * content itself untouched — its pages stay published and keep
			 * taking enquiries; this is only where it is listed.
			 *
			 * The switch is always drawn, an empty archive included: it is
			 * where the archive is found, and an archive that only appears
			 * once something is in it is one nobody knows is there.
			 */
			?>
			<div class="kivun-lib-head">
				<div class="kivun-lib-head__text">
					<h2 class="kivun-cc-title"><?php esc_html_e( 'התכנים שלי', 'kivun' ); ?></h2>
					<p class="kivun-cc-lead"><?php esc_html_e( 'כל התכנים שנוצרו כאן — עריכה, שכפול, צפייה ומחיקה, ומספר הפניות שהתקבלו לכל תוכן.', 'kivun' ); ?></p>
				</div>
				<div class="kivun-cc-filterbar kivun-lib-tools">
					<?php
					/*
					 * The field is named with aria-label rather than a
					 * visually-hidden <span>. `.screen-reader-text` is a
					 * theme's class, not ours — the plugin ships no rule for
					 * it — so on a theme that does not define it the label
					 * would simply be drawn next to the box.
					 */
					?>
					<div class="kivun-cc-search kivun-lib-search">
						<input
							type="search"
							class="kivun-cc-search__input"
							placeholder="<?php esc_attr_e( 'חיפוש לפי כותרת', 'kivun' ); ?>"
							aria-label="<?php esc_attr_e( 'חיפוש לפי כותרת', 'kivun' ); ?>"
							autocomplete="off"
						>
						<?php echo kivun_icon( 'l-search', 'kivun-lib-search__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
					</div>

					<div class="kivun-cc-segmented kivun-lib-seg" role="tablist">
						<button type="button" class="kivun-cc-seg is-active" data-shows="live" role="tab" aria-selected="true">
							<?php esc_html_e( 'פעילים', 'kivun' ); ?>
							<span class="kivun-cc-seg__count"><?php echo esc_html( number_format_i18n( count( $groups ) ) ); ?></span>
						</button>
						<button type="button" class="kivun-cc-seg" data-shows="archive" role="tab" aria-selected="false">
							<?php esc_html_e( 'ארכיון', 'kivun' ); ?>
							<span class="kivun-cc-seg__count"><?php echo esc_html( number_format_i18n( count( $put_away ) ) ); ?></span>
						</button>
					</div>
				</div>
			</div>

			<div class="kivun-cc-pane" data-pane="live">
				<?php self::library_table( $groups, $lead_counts, $page_url, $can_delete, false ); ?>
			</div>

			<div class="kivun-cc-pane" data-pane="archive" hidden>
				<?php self::library_table( $put_away, $lead_counts, $page_url, $can_delete, true ); ?>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Fold the library's posts into one row per content group.
	 *
	 * @param array<int,\WP_Post> $posts The posts.
	 * @return array<string,array<string,mixed>> Group key => its row.
	 */
	private static function library_groups( array $posts ): array {
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
					'kinds'   => array(),
					'ids'     => array(),
					'view_id' => (int) $p->ID,
					'status'  => $p->post_status,
					'date'    => get_the_date( 'd/m/Y', $p->ID ),
				);
			}
			$groups[ $g ]['types'][] = $labels[ $p->post_type ] ?? $p->post_type;
			$groups[ $g ]['kinds'][] = $p->post_type;
			$groups[ $g ]['ids'][]   = (int) $p->ID;
			// The landing page's own form, said on its row — the list it is
			// found in now that it has no tab of its own.
			if ( 'kivun_workshop' === $p->post_type && Kivun_Custom_Forms::enabled( (int) $p->ID ) ) {
				$groups[ $g ]['form'] = count( Kivun_Custom_Forms::fields( (int) $p->ID ) );
			}
			// A recurring workshop, and when its next cycle opens if that is
			// set and still ahead.
			if ( 'kivun_session' === $p->post_type && kivun_session_is_recurring( (int) $p->ID ) ) {
				$groups[ $g ]['repeat'] = kivun_session_cycle( (int) $p->ID )['next'];
			}
			// A group counts as published as soon as any of its posts is live;
			// short of that, report the most informative state it is actually
			// in — "scheduled" and "private" both say more than "draft".
			$rank = array(
				'draft'   => 0,
				'pending' => 1,
				'private' => 2,
				'future'  => 3,
				'publish' => 4,
			);
			$have = $rank[ $groups[ $g ]['status'] ] ?? 0;
			$new  = $rank[ $p->post_status ] ?? 0;
			if ( $new > $have ) {
				$groups[ $g ]['status'] = $p->post_status;
			}
			if ( 'future' === $p->post_status ) {
				$groups[ $g ]['scheduled'] = get_the_date( 'd/m/Y H:i', $p->ID );
			}
		}

		return $groups;
	}

	/**
	 * One list of content groups — the ones in use, or the ones put away.
	 *
	 * Drawn as the handoff's grid of rows rather than an HTML table, because
	 * the columns change with the list's own width rather than the screen's:
	 * seven when it is wide, four when it is not, with the types and status
	 * moving under the title and the date into the מרכז כיוון cell. Both
	 * placements are in the markup and the stylesheet shows one; the script
	 * only says which, by measuring the list. A cell needed in one layout
	 * alone carries `kivun-lib__w` (wide) or `kivun-lib__c` (compact).
	 *
	 * Nothing scrolls sideways: the compact layout is the answer to a
	 * narrow list, not a scrollbar.
	 *
	 * Roles are table roles, so it is still read as a table.
	 *
	 * @param array<string,array<string,mixed>> $groups      The rows.
	 * @param array<int,int>                    $lead_counts Leads per content id.
	 * @param string                            $page_url    The console's address.
	 * @param bool                              $can_delete  Whether deletion is offered.
	 * @param bool                              $archived    Whether these are the ones put away.
	 * @return void
	 */
	private static function library_table( array $groups, array $lead_counts, string $page_url, bool $can_delete, bool $archived ): void {
		$mercaz = Kivun_Mercaz::configured();

		$type_keys     = array(
			'kivun_workshop' => 'landing',
			'kivun_course'   => 'course',
			'kivun_session'  => 'session',
			'kivun_event'    => 'event',
		);
		$type_labels   = array(
			'kivun_workshop' => __( 'דף נחיתה', 'kivun' ),
			'kivun_course'   => __( 'קורס', 'kivun' ),
			'kivun_session'  => __( 'סדנה', 'kivun' ),
			'kivun_event'    => __( 'אירוע', 'kivun' ),
		);
		$status_labels = array(
			'publish'  => __( 'פורסם', 'kivun' ),
			'draft'    => __( 'טיוטה', 'kivun' ),
			'pending'  => __( 'ממתין לאישור', 'kivun' ),
			'private'  => __( 'פרטי', 'kivun' ),
			'future'   => __( 'מתוזמן', 'kivun' ),
			'archived' => __( 'בארכיון', 'kivun' ),
		);
		?>
		<div class="kivun-lib<?php echo $mercaz ? '' : ' kivun-lib--nomercaz'; ?>" role="table" aria-label="<?php echo esc_attr( $archived ? __( 'ארכיון', 'kivun' ) : __( 'התכנים שלי', 'kivun' ) ); ?>">
			<div class="kivun-lib__head" role="row">
				<span role="columnheader"><?php esc_html_e( 'כותרת', 'kivun' ); ?></span>
				<span role="columnheader" class="kivun-lib__w"><?php esc_html_e( 'סוגים', 'kivun' ); ?></span>
				<span role="columnheader" class="kivun-lib__w"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'פניות', 'kivun' ); ?></span>
				<span role="columnheader" class="kivun-lib__w"><?php esc_html_e( 'תאריך', 'kivun' ); ?></span>
				<span role="columnheader" class="kivun-lib__whenhead">
					<?php if ( $mercaz ) : ?>
						<span class="kivun-lib__w"><?php esc_html_e( 'מרכז כיוון', 'kivun' ); ?></span>
						<span class="kivun-lib__c"><?php esc_html_e( 'תאריך ומרכז כיוון', 'kivun' ); ?></span>
					<?php else : ?>
						<span class="kivun-lib__c"><?php esc_html_e( 'תאריך', 'kivun' ); ?></span>
					<?php endif; ?>
				</span>
				<span role="columnheader"><?php esc_html_e( 'פעולות', 'kivun' ); ?></span>
			</div>

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

				$leads_url = add_query_arg(
					array(
						'kivun_tab'     => 'leads',
						'kivun_content' => (int) $g['view_id'],
					),
					$page_url
				);

				$kinds      = array_values( array_unique( $g['kinds'] ?? array() ) );
				$status_key = $archived ? 'archived' : (string) $g['status'];
				$status     = $status_labels[ $status_key ] ?? $status_key;

				// What the row is beyond its types and status: its own form,
				// and when it goes live if it is scheduled.
				$meta = array();
				if ( isset( $g['form'] ) ) {
					/* translators: %d: number of questions on the landing page's own form. */
					$meta[] = sprintf( _n( 'טופס מותאם · %d שאלה', 'טופס מותאם · %d שאלות', (int) $g['form'], 'kivun' ), (int) $g['form'] );
				}
				if ( isset( $g['repeat'] ) ) {
					$meta[] = '' !== $g['repeat']
						/* translators: %s: the day the workshop's next cycle opens. */
						? sprintf( __( 'סדנה שוטפת · המחזור הבא %s', 'kivun' ), kivun_display_date( (string) $g['repeat'] ) )
						: __( 'סדנה שוטפת', 'kivun' );
				}
				if ( ! empty( $g['scheduled'] ) && 'future' === $g['status'] ) {
					/* translators: %s: date and time the content goes live. */
					$meta[] = sprintf( __( 'יפורסם ב-%s', 'kivun' ), (string) $g['scheduled'] );
				}

				$kivun_archive_label = $archived ? __( 'שחזור מהארכיון', 'kivun' ) : __( 'העברה לארכיון', 'kivun' );
				?>
				<div class="kivun-lib__row" role="row">
					<div class="kivun-lib__title" role="cell" data-search="<?php echo esc_attr( $g['title'] ); ?>">
						<span class="kivun-lib__name"><?php echo esc_html( $g['title'] ); ?></span>
						<span class="kivun-lib__facts kivun-lib__c">
							<?php foreach ( $kinds as $kind ) : ?>
								<span class="kivun-lib__dot kivun-lib__dot--<?php echo esc_attr( $type_keys[ $kind ] ?? 'other' ); ?>"><?php echo esc_html( $type_labels[ $kind ] ?? $kind ); ?></span>
							<?php endforeach; ?>
							<span class="kivun-lib__dot kivun-lib__dot--st kivun-lib__dot--<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status ); ?></span>
						</span>
						<?php if ( $meta ) : ?>
							<span class="kivun-lib__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
						<?php endif; ?>
					</div>

					<div class="kivun-lib__types kivun-lib__w" role="cell">
						<?php foreach ( $kinds as $kind ) : ?>
							<span class="kivun-lib__dot kivun-lib__dot--<?php echo esc_attr( $type_keys[ $kind ] ?? 'other' ); ?>"><?php echo esc_html( $type_labels[ $kind ] ?? $kind ); ?></span>
						<?php endforeach; ?>
					</div>

					<div class="kivun-lib__status kivun-lib__w" role="cell">
						<span class="kivun-lib__dot kivun-lib__dot--<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status ); ?></span>
					</div>

					<div class="kivun-lib__leads" role="cell" data-label="<?php esc_attr_e( 'פניות:', 'kivun' ); ?>">
						<?php if ( $group_leads ) : ?>
							<a class="kivun-cc-leadlink" href="<?php echo esc_url( $leads_url ); ?>"><?php echo esc_html( number_format_i18n( $group_leads ) ); ?></a>
						<?php else : ?>
							<span class="kivun-lib__none" aria-label="<?php esc_attr_e( 'אין פניות', 'kivun' ); ?>">—</span>
						<?php endif; ?>
					</div>

					<div class="kivun-lib__date kivun-lib__w" role="cell"><?php echo esc_html( $g['date'] ); ?></div>

					<?php
					/*
					 * When it went to מרכז כיוון, and the button that sends it.
					 * In the compact layout the date moves in here, above the
					 * "sent" line. The status and the button are siblings: the
					 * sending script finds the one from the other through
					 * their shared parent.
					 */
					?>
					<div class="kivun-lib__when<?php echo $mercaz ? ' kivun-mercaz-cell' : ''; ?>" role="cell">
						<span class="kivun-lib__whendate kivun-lib__c"><?php echo esc_html( $g['date'] ); ?></span>
						<?php if ( $mercaz ) : ?>
							<?php
							$kivun_push_id = (int) $g['view_id'];
							$kivun_synced  = (string) get_post_meta( $kivun_push_id, Kivun_Mercaz_Sync::SYNCED_AT, true );
							$kivun_failed  = (string) get_post_meta( $kivun_push_id, Kivun_Mercaz_Sync::LAST_ERROR, true );
							$kivun_state   = $kivun_failed ? 'is-error' : ( $kivun_synced ? 'is-ok' : 'is-unsent' );
							/* translators: %s: date and time the content was sent. */
							$kivun_sent_tpl = __( 'נשלח ב-%s', 'kivun' );
							?>
							<span class="kivun-mercaz-status <?php echo esc_attr( $kivun_state ); ?>">
								<?php
								if ( $kivun_failed ) {
									echo esc_html( $kivun_failed );
								} elseif ( $kivun_synced ) {
									echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'נשלח ב-%s', 'kivun' ), kivun_local_date( (string) $kivun_synced ) ) );
								} else {
									esc_html_e( 'עוד לא נשלח', 'kivun' );
								}
								?>
							</span>
							<button type="button" class="kivun-cc-linkbtn kivun-mercaz-push" data-id="<?php echo esc_attr( (string) $kivun_push_id ); ?>" data-sent="<?php echo esc_attr( $kivun_sent_tpl ); ?>" data-again="<?php esc_attr_e( 'שליחה מחדש', 'kivun' ); ?>">
								<?php echo $kivun_synced ? esc_html__( 'שליחה מחדש', 'kivun' ) : esc_html__( 'שליחה', 'kivun' ); ?>
							</button>
						<?php endif; ?>
					</div>

					<?php
					/*
					 * Editing keeps its word and the fill; the rest are marks in
					 * one segmented pill. Each mark still says what it is —
					 * aria-label and title carry the word that is no longer
					 * drawn, so it is lost to the pixels and not to a screen
					 * reader or a hover.
					 *
					 * Divs, not spans, around the actions: two of them hold a
					 * <form>, and a block inside an inline element is what a
					 * page builder's wpautop() splits — the form ends up
					 * outside its group, and the confirm step outside its row.
					 *
					 * Deleting asks first, in the row itself: the trash mark
					 * swaps the actions for "למחוק? מחיקה / ביטול", and the
					 * existing delete form is what "מחיקה" submits.
					 */
					?>
					<div class="kivun-cc-rowactions kivun-lib__actions" role="cell">
						<div class="kivun-lib__acts">
							<a class="kivun-cc-btn kivun-cc-act kivun-cc-act--edit kivun-lib__edit" href="<?php echo esc_url( $edit_url ); ?>"><?php echo kivun_icon( 'l-pencil' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'עריכה', 'kivun' ); ?></a>
							<div class="kivun-cc-actgroup">
							<?php if ( '' !== $view_url ) : ?>
								<a class="kivun-cc-icon-btn" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'צפייה', 'kivun' ); ?>" title="<?php esc_attr_e( 'צפייה', 'kivun' ); ?>"><?php echo kivun_icon( 'l-eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></a>
							<?php endif; ?>
							<?php echo self::duplicate_form( (int) ( $g['view_id'] ?? 0 ), $page_url, 'kivun-cc-icon-btn', 'l-copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in duplicate_form(). ?>
							<?php if ( current_user_can( 'edit_posts' ) ) : ?>
								<button
									type="button"
									class="kivun-cc-icon-btn kivun-archive-content"
									data-group="<?php echo esc_attr( (string) $gid ); ?>"
									data-archived="<?php echo $archived ? '0' : '1'; ?>"
									aria-label="<?php echo esc_attr( $kivun_archive_label ); ?>"
									title="<?php echo esc_attr( $kivun_archive_label ); ?>"
								><?php echo kivun_icon( 'l-archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></button>
							<?php endif; ?>
							<?php if ( $can_delete ) : ?>
								<button type="button" class="kivun-cc-icon-btn kivun-cc-icon-btn--danger kivun-lib__ask" aria-label="<?php esc_attr_e( 'מחיקה', 'kivun' ); ?>" title="<?php esc_attr_e( 'מחיקה', 'kivun' ); ?>"><?php echo kivun_icon( 'l-trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></button>
							<?php endif; ?>
							</div>
						</div>
						<?php if ( $can_delete ) : ?>
							<div class="kivun-lib__confirm" hidden>
								<span class="kivun-lib__q"><?php esc_html_e( 'למחוק?', 'kivun' ); ?></span>
								<?php echo self::delete_form( (string) $gid, $page_url, __( 'מחיקה', 'kivun' ), 'kivun-lib__yes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in delete_form(). ?>
								<button type="button" class="kivun-lib__no"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<p class="kivun-lib__empty kivun-lib__empty--base"<?php echo $groups ? ' hidden' : ''; ?>>
				<?php echo $archived ? esc_html__( 'תכנים שתעבירו לארכיון יופיעו כאן', 'kivun' ) : esc_html__( 'אין תכנים פעילים', 'kivun' ); ?>
			</p>
			<p class="kivun-lib__empty kivun-cc-noresults" hidden><?php esc_html_e( 'לא נמצאו תכנים בחיפוש הזה', 'kivun' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Load what the console's markup needs, for an admin screen showing it.
	 *
	 * The front-end bundle is registered on wp_enqueue_scripts, which does not
	 * run in wp-admin, so the same files and the same `kivun` object are set up
	 * here rather than reimplementing either.
	 *
	 * @return void
	 */
	private static function enqueue_console_assets(): void {
		wp_enqueue_style( 'kivun-frontend', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'frontend', 'css' ), array(), KIVUN_VERSION );
		wp_enqueue_script( 'kivun-frontend', KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'frontend', 'js' ), array(), KIVUN_VERSION, true );
		wp_localize_script(
			'kivun-frontend',
			'kivun',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kivun_nonce' ),
				'i18n'     => array(
					'sending'                   => __( 'שולח...', 'kivun' ),
					'saved'                     => __( '✓ נשמר', 'kivun' ),
					'error_generic'             => __( 'אירעה שגיאה, נסה שוב.', 'kivun' ),
					'confirm_delete_campaign'   => __( 'למחוק את הקמפיין וכל הקישורים שתחתיו? הפניות שהגיעו דרכם יישמרו.', 'kivun' ),
					'confirm_delete_link'       => __( 'למחוק את הקישור? הפניות שהגיעו דרכו יישמרו.', 'kivun' ),
					'confirm_delete_phone'      => __( 'להסיר את המספר ואת כל השיוכים שלו? השיחות שנרשמו יישמרו.', 'kivun' ),
					'confirm_delete_assignment' => __( 'למחוק את השיוך? השיחות שנרשמו בתקופה יישארו ללא שיוך לקמפיין.', 'kivun' ),
				),
			)
		);
	}

	/**
	 * The campaigns workspace, as a wp-admin page.
	 *
	 * @return void
	 */
	public static function admin_campaigns_page(): void {
		if ( ! Kivun_Campaigns::can_manage() ) {
			wp_die( esc_html__( 'אין לך הרשאה לצפות בעמוד הזה.', 'kivun' ) );
		}

		self::enqueue_console_assets();
		echo '<div class="wrap"><div class="kivun-cc-console" dir="rtl">';
		self::front_campaigns();
		echo '</div></div>';
	}

	/**
	 * The call-tracking workspace, as a wp-admin page.
	 *
	 * @return void
	 */
	public static function admin_calls_page(): void {
		if ( ! Kivun_Phones::can_manage() ) {
			wp_die( esc_html__( 'אין לך הרשאה לצפות בעמוד הזה.', 'kivun' ) );
		}

		self::enqueue_console_assets();
		echo '<div class="wrap"><div class="kivun-cc-console" dir="rtl">';
		self::front_calls();
		echo '</div></div>';
	}

	/**
	 * Render the call-tracking workspace: the pool of virtual numbers, what
	 * each is currently advertising, and the history of what it advertised
	 * before — with the calls each period brought in.
	 *
	 * @return void
	 */
	private static function front_calls(): void {
		$numbers     = Kivun_Phones::numbers();
		$assignments = Kivun_Phones::assignments_by_number();
		$counts      = Kivun_Phones::call_counts();
		$media       = Kivun_Phones::media();
		$campaigns   = Kivun_Campaigns::all();
		$today       = wp_date( 'Y-m-d' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing filters.
		$call_filters = Kivun_Phones::filters( wp_unslash( $_GET ) );
		$report       = Kivun_Phones::report( 30, $call_filters );
		$filtering    = (bool) $call_filters;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing filters.
		$call_paged = isset( $_GET['kivun_call_paged'] ) ? max( 1, absint( wp_unslash( $_GET['kivun_call_paged'] ) ) ) : 1;
		$call_pp    = isset( $_GET['kivun_call_pp'] ) ? absint( wp_unslash( $_GET['kivun_call_pp'] ) ) : 25;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $call_pp, array( 25, 50, 100, 200 ), true ) ) {
			$call_pp = 25;
		}

		$call_log    = Kivun_Phones::calls( $call_filters, $call_pp, $call_paged );
		$call_pages  = max( 1, (int) ceil( $call_log['found'] / $call_pp ) );
		$call_paged  = min( $call_paged, $call_pages );
		$console_url = self::console_url( 'calls' );

		// Preserved across paging, so a page two of a filtered view stays filtered.
		$call_args = array( 'kivun_tab' => 'calls' );
		foreach ( array(
			'campaign_id' => 'kivun_call_campaign',
			'number_id'   => 'kivun_call_number',
			'media'       => 'kivun_call_media',
			'from'        => 'kivun_call_from',
			'to'          => 'kivun_call_to',
			'search'      => 'kivun_call_s',
		) as $key => $arg ) {
			if ( ! empty( $call_filters[ $key ] ) ) {
				$call_args[ $arg ] = $call_filters[ $key ];
			}
		}
		$call_args['kivun_call_pp'] = $call_pp;

		$call_page_link = static function ( int $p ) use ( $call_args, $console_url ): string {
			return add_query_arg( array_merge( $call_args, array( 'kivun_call_paged' => $p ) ), $console_url );
		};

		// Offered as destinations when a campaign is created from this screen.
		$contents = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$pages    = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 100, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$top_media    = $report['media'][0] ?? null;
		$top_campaign = $report['campaigns'][0] ?? null;
		?>
		<?php
		/*
		 * The screen gets a scope of its own, the way the wizard has
		 * `kivun-cc-wizard`, and for a sharper reason than tidiness: this
		 * screen's blocks are drawn with `kivun-cc-card` and
		 * `kivun-camp-summary`, and the jobs board template uses both of
		 * those too — inside this very console. A rule written for the
		 * numbers without a scope would redraw the jobs board as well,
		 * half-restyling a screen nobody has designed yet.
		 *
		 * Added alongside `kivun-cc-front` rather than replacing it: the
		 * filter script walks up with `closest('.kivun-cc-front')`.
		 */
		?>
		<div class="kivun-cc-front kivun-cc-calls">
			<div class="kivun-cc-head">
				<h2 class="kivun-cc-title"><?php esc_html_e( 'טלפונים', 'kivun' ); ?></h2>
				<p class="kivun-cc-lead">
					<?php esc_html_e( 'לכל מספר וירטואלי אפשר לקבוע במה הוא מתפרסם ובאיזו תקופה. שיחה נרשמת לפי השיוך שהיה פעיל ביום שבו היא הגיעה — כך ששינוי השיוך היום לא משנה את הדוחות של אתמול.', 'kivun' ); ?>
				</p>
			</div>

			<?php
			// The filters drive everything below them — the figures, the two
			// rankings and the log — so "how many came from this campaign in
			// March" is one question asked once rather than three read off
			// separate screens.
			?>
			<form class="kivun-cc-leadfilters" method="get" action="<?php echo esc_url( $console_url ); ?>">
				<input type="hidden" name="kivun_tab" value="calls">

				<label class="kivun-sr-only" for="kivun-cf-campaign"><?php esc_html_e( 'סינון לפי קמפיין', 'kivun' ); ?></label>
				<select id="kivun-cf-campaign" name="kivun_call_campaign">
					<option value="0"><?php esc_html_e( 'כל הקמפיינים', 'kivun' ); ?></option>
					<?php foreach ( $campaigns as $camp ) : ?>
						<option value="<?php echo esc_attr( $camp->id ); ?>" <?php selected( $call_filters['campaign_id'] ?? 0, (int) $camp->id ); ?>><?php echo esc_html( $camp->label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="kivun-sr-only" for="kivun-cf-media"><?php esc_html_e( 'סינון לפי מדיה', 'kivun' ); ?></label>
				<select id="kivun-cf-media" name="kivun_call_media">
					<option value=""><?php esc_html_e( 'כל המדיות', 'kivun' ); ?></option>
					<?php foreach ( $media as $mv => $ml ) : ?>
						<option value="<?php echo esc_attr( $mv ); ?>" <?php selected( $call_filters['media'] ?? '', $mv ); ?>><?php echo esc_html( $ml ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="kivun-sr-only" for="kivun-cf-number"><?php esc_html_e( 'סינון לפי מספר', 'kivun' ); ?></label>
				<select id="kivun-cf-number" name="kivun_call_number">
					<option value="0"><?php esc_html_e( 'כל המספרים', 'kivun' ); ?></option>
					<?php foreach ( $numbers as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt->id ); ?>" <?php selected( $call_filters['number_id'] ?? 0, (int) $opt->id ); ?>>
							<?php echo esc_html( $opt->label ? $opt->number . ' — ' . $opt->label : $opt->number ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<span class="kivun-cc-daterange">
					<label for="kivun-cf-from"><?php esc_html_e( 'מתאריך', 'kivun' ); ?></label>
					<input type="date" id="kivun-cf-from" name="kivun_call_from" dir="ltr" value="<?php echo esc_attr( $call_filters['from'] ?? '' ); ?>">
					<label for="kivun-cf-to"><?php esc_html_e( 'עד', 'kivun' ); ?></label>
					<input type="date" id="kivun-cf-to" name="kivun_call_to" dir="ltr" value="<?php echo esc_attr( $call_filters['to'] ?? '' ); ?>">
				</span>

				<label class="kivun-sr-only" for="kivun-cf-search"><?php esc_html_e( 'חיפוש', 'kivun' ); ?></label>
				<input type="search" id="kivun-cf-search" name="kivun_call_s" value="<?php echo esc_attr( $call_filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'מספר מתקשר או שם…', 'kivun' ); ?>">

				<select name="kivun_call_pp" aria-label="<?php esc_attr_e( 'שורות בעמוד', 'kivun' ); ?>">
					<?php foreach ( array( 25, 50, 100, 200 ) as $pp_opt ) : ?>
						<option value="<?php echo esc_attr( $pp_opt ); ?>" <?php selected( $call_pp, $pp_opt ); ?>><?php echo esc_html( sprintf( /* translators: %s: rows per page. */ __( '%s בעמוד', 'kivun' ), number_format_i18n( $pp_opt ) ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm"><?php esc_html_e( 'סינון', 'kivun' ); ?></button>
				<?php if ( $filtering ) : ?>
					<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $console_url ); ?>"><?php esc_html_e( 'ניקוי', 'kivun' ); ?></a>
				<?php endif; ?>
				<?php
				// The export carries the filters, so what downloads is what is
				// on screen rather than the whole log to be sorted out later.
				$export_url = add_query_arg(
					array_diff_key( $call_args, array_flip( array( 'kivun_tab', 'kivun_call_pp' ) ) ),
					Kivun_Export::url( 'calls' )
				);
				?>
				<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $export_url ); ?>">
					<?php echo kivun_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'ייצוא שיחות', 'kivun' ); ?>
				</a>
				<?php
				// The other question about the same numbers: not which calls
				// came in, but what each number brought — which is what a
				// number is rented for. Same dates as the log above it.
				// The screen's own names for the dates are prefixed; the export
				// reads them unprefixed. Translated here rather than hoped
				// for — an intersection on the wrong keys finds nothing and
				// downloads all of time while the screen shows one month,
				// and nothing in the file says which it is.
				$kivun_numbers_dates = array();
				foreach ( array(
					'kivun_call_from' => 'from',
					'kivun_call_to'   => 'to',
				) as $kivun_screen_key => $kivun_export_key ) {
					if ( isset( $call_args[ $kivun_screen_key ] ) && '' !== $call_args[ $kivun_screen_key ] ) {
						$kivun_numbers_dates[ $kivun_export_key ] = $call_args[ $kivun_screen_key ];
					}
				}

				$kivun_numbers_url = add_query_arg( $kivun_numbers_dates, Kivun_Export::url( 'numbers' ) );
				?>
				<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $kivun_numbers_url ); ?>">
					<?php echo kivun_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'דוח מספרים', 'kivun' ); ?>
				</a>
			</form>

			<?php
			// Figures rather than a chart: each is a single current value,
			// which a tile says plainly and a one-bar chart only decorates.
			?>
			<div class="kivun-kpi">
				<div class="kivun-kpi__tile">
					<span class="kivun-kpi__label"><?php esc_html_e( 'סך השיחות', 'kivun' ); ?></span>
					<strong class="kivun-kpi__value"><?php echo esc_html( number_format_i18n( $report['total'] ) ); ?></strong>
					<span class="kivun-kpi__note">
						<?php echo esc_html( $filtering ? __( 'בסינון הנוכחי', 'kivun' ) : __( 'מאז תחילת המעקב', 'kivun' ) ); ?>
					</span>
				</div>

				<?php
				// A missed call is a lead that rang and got nobody, which is the
				// one figure on this screen worth acting on the same day — but
				// only where the switchboard says how long anyone talked. Set to
				// report the start of a call and nothing else, it sends no talk
				// time, every call reads as unanswered, and this tile would
				// announce a crisis that is only a setting.
				?>
				<?php if ( $report['knows'] ) : ?>
				<div class="kivun-kpi__tile">
					<span class="kivun-kpi__label"><?php esc_html_e( 'שיחות שלא נענו', 'kivun' ); ?></span>
					<strong class="kivun-kpi__value"><?php echo esc_html( number_format_i18n( $report['missed'] ) ); ?></strong>
					<span class="kivun-kpi__note">
						<?php
						echo $report['total']
							? esc_html(
								sprintf(
									/* translators: %s: percentage of calls answered. */
									__( '%s%% מהשיחות נענו', 'kivun' ),
									number_format_i18n( round( $report['answered'] / $report['total'] * 100 ) )
								)
							)
							: esc_html__( 'אין שיחות בסינון הזה', 'kivun' );
						?>
					</span>
				</div>
				<?php endif; ?>

				<div class="kivun-kpi__tile">
					<span class="kivun-kpi__label"><?php esc_html_e( 'שיחות החודש האחרון', 'kivun' ); ?></span>
					<strong class="kivun-kpi__value"><?php echo esc_html( number_format_i18n( $report['recent'] ) ); ?></strong>
					<span class="kivun-kpi__note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of days. */
								__( '%s הימים האחרונים', 'kivun' ),
								number_format_i18n( $report['days'] )
							)
						);
						?>
					</span>
				</div>

				<div class="kivun-kpi__tile">
					<span class="kivun-kpi__label"><?php esc_html_e( 'המדיה המובילה', 'kivun' ); ?></span>
					<strong class="kivun-kpi__value kivun-kpi__value--text">
						<?php echo $top_media ? esc_html( $top_media['name'] ) : '—'; ?>
					</strong>
					<span class="kivun-kpi__note">
						<?php
						echo $top_media
							? esc_html(
								sprintf(
									/* translators: %s: number of calls. */
									_n( '%s שיחה', '%s שיחות', $top_media['total'], 'kivun' ),
									number_format_i18n( $top_media['total'] )
								)
							)
							: esc_html__( 'עדיין אין שיחות משויכות', 'kivun' );
						?>
					</span>
				</div>

				<div class="kivun-kpi__tile">
					<span class="kivun-kpi__label"><?php esc_html_e( 'הקמפיין המוביל', 'kivun' ); ?></span>
					<strong class="kivun-kpi__value kivun-kpi__value--text">
						<?php echo $top_campaign ? esc_html( $top_campaign['name'] ) : '—'; ?>
					</strong>
					<span class="kivun-kpi__note">
						<?php
						echo $top_campaign
							? esc_html(
								sprintf(
									/* translators: %s: number of calls. */
									_n( '%s שיחה', '%s שיחות', $top_campaign['total'], 'kivun' ),
									number_format_i18n( $top_campaign['total'] )
								)
							)
							: esc_html__( 'לא שויך קמפיין למספרים', 'kivun' );
						?>
					</span>
				</div>
			</div>

			<?php if ( $report['unmatched'] ) : ?>
				<p class="kivun-field-hint kivun-camp-unmapped">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of calls. */
							_n(
								'%s שיחה הגיעה למספר שאינו ברשימה — כדאי להוסיף אותו כדי שתיספר.',
								'%s שיחות הגיעו למספרים שאינם ברשימה — כדאי להוסיף אותם כדי שייספרו.',
								$report['unmatched'],
								'kivun'
							),
							number_format_i18n( $report['unmatched'] )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $report['media'] || $report['campaigns'] ) : ?>
				<div class="kivun-rank-pair">
					<?php
					self::rank_list( __( 'שיחות לפי מדיה', 'kivun' ), $report['media'] );
					self::rank_list( __( 'שיחות לפי קמפיין', 'kivun' ), $report['campaigns'] );
					?>
				</div>
			<?php endif; ?>

			<?php
			// The calls themselves. The figures above say how many; somebody
			// chasing a particular one needs to see it, and somebody checking
			// a campaign's numbers needs to be able to look at what they are
			// counting rather than take the tile's word for it.
			?>
			<?php
			// Open whenever somebody is in the middle of reading it: filtering,
			// or past its first page — arriving on page 2 to a closed log is
			// how "הבא" used to read as "close".
			?>
			<details class="kivun-cc-card kivun-camp-block kivun-calls-log" <?php echo ( $filtering || $call_paged > 1 ) ? 'open' : ''; ?>>
				<summary class="kivun-camp-head">
					<div class="kivun-camp-heading">
						<span class="kivun-camp-caret" aria-hidden="true"></span>
						<h3 class="kivun-camp-title"><?php esc_html_e( 'יומן שיחות', 'kivun' ); ?></h3>
					</div>
					<div class="kivun-camp-metrics">
						<span class="kivun-cc-badge kivun-camp-total">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of calls. */
									_n( '%s שיחה', '%s שיחות', $call_log['found'], 'kivun' ),
									number_format_i18n( $call_log['found'] )
								)
							);
							?>
						</span>
					</div>
				</summary>

				<?php if ( ! $call_log['rows'] ) : ?>
					<div class="kivun-cc-note">
						<?php echo esc_html( $filtering ? __( 'אין שיחות שעונות על הסינון הזה.', 'kivun' ) : __( 'עדיין לא נרשמו שיחות.', 'kivun' ) ); ?>
					</div>
				<?php else : ?>
					<div class="kivun-cc-tablewrap">
						<table class="kivun-cc-table kivun-calls-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'מועד', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'מתקשר', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'חייג אל', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'קמפיין', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'מדיה', 'kivun' ); ?></th>
									<?php if ( $report['knows'] ) : ?>
										<th scope="col"><?php esc_html_e( 'מענה', 'kivun' ); ?></th>
									<?php endif; ?>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $call_log['rows'] as $call ) : ?>
								<tr>
									<td class="kivun-cc-date"><?php echo esc_html( kivun_local_date( (string) $call->started_at ) ); ?></td>
									<td dir="ltr">
										<a href="tel:<?php echo esc_attr( $call->caller ); ?>"><?php echo esc_html( $call->caller ); ?></a>
										<?php if ( ! empty( $call->caller_name ) ) : ?>
											<span class="kivun-cc-source"><?php echo esc_html( $call->caller_name ); ?></span>
										<?php endif; ?>
									</td>
									<td dir="ltr">
										<?php echo esc_html( (string) ( $call->number ? $call->number : $call->dialled ) ); ?>
										<?php if ( ! empty( $call->number_label ) ) : ?>
											<span class="kivun-cc-source"><?php echo esc_html( $call->number_label ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( (string) ( $call->campaign_label ? $call->campaign_label : '—' ) ); ?></td>
									<td><?php echo esc_html( $call->media ? Kivun_Phones::media_label( (string) $call->media ) : '—' ); ?></td>
									<?php if ( $report['knows'] ) : ?>
										<td>
											<?php if ( ! empty( $call->answered ) ) : ?>
												<span class="kivun-cc-badge"><?php esc_html_e( 'נענתה', 'kivun' ); ?></span>
											<?php else : ?>
												<span class="kivun-cc-badge kivun-cc-badge--warn"><?php esc_html_e( 'לא נענתה', 'kivun' ); ?></span>
											<?php endif; ?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php
					/*
					 * "טען עוד" rather than pages. Moving to another page reloaded
					 * the whole console and closed this log on the way, so every
					 * page was a click to go and another to open it again. The
					 * script fetches the next page and adds its rows here; with no
					 * script the button is still a link to that page, which now
					 * arrives open.
					 */
					$call_from = ( $call_paged - 1 ) * $call_pp + 1;
					$call_to   = $call_from + count( $call_log['rows'] ) - 1;
					?>
					<?php if ( $call_pages > 1 ) : ?>
						<div class="kivun-cc-pager kivun-calls-more">
							<?php if ( $call_paged > 1 ) : ?>
								<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost" href="<?php echo esc_url( $call_page_link( 1 ) ); ?>"><?php esc_html_e( 'לתחילת היומן', 'kivun' ); ?></a>
							<?php endif; ?>
							<span class="kivun-cc-pager__state kivun-calls-more__state" data-total="<?php echo esc_attr( (string) $call_log['found'] ); ?>">
								<?php
								// The first page counts from the top; a later one,
								// reached by its link, says which stretch it is.
								echo esc_html(
									1 === $call_paged
										? sprintf(
											/* translators: 1: how many calls are listed so far, 2: how many there are. */
											__( 'מוצגות %1$s מתוך %2$s שיחות', 'kivun' ),
											number_format_i18n( $call_to ),
											number_format_i18n( $call_log['found'] )
										)
										: sprintf(
											/* translators: 1: first call listed, 2: last call listed, 3: how many there are. */
											__( 'שיחות %1$s–%2$s מתוך %3$s', 'kivun' ),
											number_format_i18n( $call_from ),
											number_format_i18n( $call_to ),
											number_format_i18n( $call_log['found'] )
										)
								);
								?>
							</span>
							<?php if ( $call_paged < $call_pages ) : ?>
								<a class="kivun-cc-btn kivun-cc-btn--sm kivun-calls-more__btn" href="<?php echo esc_url( $call_page_link( $call_paged + 1 ) ); ?>"><?php esc_html_e( 'טען עוד', 'kivun' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</details>


			<?php if ( ! $numbers ) : ?>
				<div class="kivun-cc-note"><?php esc_html_e( 'עדיין לא הוגדרו מספרי מעקב.', 'kivun' ); ?></div>
			<?php else : ?>
				<?php
				foreach ( $numbers as $num ) :
					$history = $assignments[ (int) $num->id ] ?? array();
					$current = null;
					foreach ( $history as $row ) {
						$open = ( null === $row->ends_on || '' === $row->ends_on );
						if ( $row->starts_on <= $today && ( $open || $row->ends_on >= $today ) ) {
							$current = $row;
							break;
						}
					}
					?>
					<?php
					// Collapsed like the campaigns, and for the same reason: the
					// pool grows, each number carries its whole history, and all
					// of it open at once buries the one being looked for.
					?>
					<details class="kivun-cc-card kivun-camp-block" data-phone-row="<?php echo esc_attr( $num->id ); ?>">
						<summary class="kivun-camp-head">
							<div class="kivun-camp-heading">
								<span class="kivun-camp-caret" aria-hidden="true"></span>
								<div>
									<h3 class="kivun-camp-title" dir="ltr"><?php echo esc_html( $num->number ); ?></h3>
									<?php if ( $num->label ) : ?>
										<span class="kivun-cc-source"><?php echo esc_html( $num->label ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<div class="kivun-camp-metrics">
								<?php if ( $current ) : ?>
									<span class="kivun-cc-badge kivun-camp-total">
										<?php echo esc_html( Kivun_Phones::media_label( (string) $current->media ) ); ?>
										<?php echo $current->campaign_label ? esc_html( ' · ' . $current->campaign_label ) : ''; ?>
									</span>
								<?php else : ?>
									<span class="kivun-cc-badge"><?php esc_html_e( 'פנוי', 'kivun' ); ?></span>
								<?php endif; ?>
								<button
									type="button"
									class="kivun-cc-iconbtn kivun-cc-iconbtn--danger kivun-delete-phone"
									data-id="<?php echo esc_attr( $num->id ); ?>"
									title="<?php esc_attr_e( 'הסרת המספר', 'kivun' ); ?>"
								>
									<?php echo kivun_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
									<span class="kivun-sr-only"><?php esc_html_e( 'הסרת המספר', 'kivun' ); ?></span>
								</button>
							</div>
						</summary>

						<?php if ( $history ) : ?>
							<div class="kivun-cc-tablewrap">
								<table class="kivun-cc-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'תקופה', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'מדיה', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'קמפיין', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'שיחות', 'kivun' ); ?></th>
											<?php if ( $report['knows'] ) : ?>
												<th scope="col"><?php esc_html_e( 'נענו', 'kivun' ); ?></th>
											<?php endif; ?>
											<th scope="col"><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $history as $row ) : ?>
										<?php
										$stat = $counts[ (int) $row->id ] ?? array(
											'total'    => 0,
											'answered' => 0,
										);
										?>
										<tr data-assignment-row="<?php echo esc_attr( $row->id ); ?>">
											<td class="kivun-cc-date">
												<?php echo esc_html( kivun_local_date( (string) $row->starts_on, 'd/m/Y' ) ); ?>
												—
												<?php
												echo $row->ends_on
													? esc_html( kivun_local_date( (string) $row->ends_on, 'd/m/Y' ) )
													: esc_html__( 'פעיל', 'kivun' );
												?>
											</td>
											<td><span class="kivun-cc-badge"><?php echo esc_html( Kivun_Phones::media_label( (string) $row->media ) ); ?></span></td>
											<td>
												<?php echo esc_html( $row->campaign_label ? $row->campaign_label : '—' ); ?>
												<?php if ( $row->label ) : ?>
													<span class="kivun-cc-source"><?php echo esc_html( $row->label ); ?></span>
												<?php endif; ?>
											</td>
											<td><strong><?php echo esc_html( number_format_i18n( $stat['total'] ) ); ?></strong></td>
											<?php if ( $report['knows'] ) : ?>
												<td><?php echo esc_html( number_format_i18n( $stat['answered'] ) ); ?></td>
											<?php endif; ?>
											<td>
												<?php
												// A period's dates are set from a media plan
												// and change once the real run is known.
												// Without this the only way to correct them
												// was to delete the period, which takes its
												// calls with it.
												?>
												<button
													type="button"
													class="kivun-cc-iconbtn kivun-edit-assignment"
													data-id="<?php echo esc_attr( $row->id ); ?>"
													title="<?php esc_attr_e( 'עריכת השיוך', 'kivun' ); ?>"
												>
													<?php echo kivun_icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
													<span class="kivun-sr-only"><?php esc_html_e( 'עריכת השיוך', 'kivun' ); ?></span>
												</button>
												<button
													type="button"
													class="kivun-cc-iconbtn kivun-cc-iconbtn--danger kivun-delete-assignment"
													data-id="<?php echo esc_attr( $row->id ); ?>"
													title="<?php esc_attr_e( 'מחיקת השיוך', 'kivun' ); ?>"
												>
													<?php echo kivun_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
													<span class="kivun-sr-only"><?php esc_html_e( 'מחיקת השיוך', 'kivun' ); ?></span>
												</button>
											</td>
										</tr>
										<tr class="kivun-assignment-editrow" data-assignment-edit="<?php echo esc_attr( $row->id ); ?>" hidden>
											<td colspan="<?php echo esc_attr( $report['knows'] ? '6' : '5' ); ?>">
												<form class="kivun-assignment-form kivun-assignment-edit" data-number="<?php echo esc_attr( $num->id ); ?>" data-id="<?php echo esc_attr( $row->id ); ?>">
													<div class="kivun-form-grid">
														<div class="kivun-form-row">
															<label><?php esc_html_e( 'מדיה *', 'kivun' ); ?></label>
															<input type="text" class="kivun-cc-input kivun-as-media" list="kivun-media-list" autocomplete="off" value="<?php echo esc_attr( (string) $row->media ); ?>">
														</div>
														<div class="kivun-form-row">
															<label><?php esc_html_e( 'קמפיין', 'kivun' ); ?></label>
															<select class="kivun-cc-input kivun-as-campaign">
																<option value="0"><?php esc_html_e( '— ללא —', 'kivun' ); ?></option>
																<?php foreach ( $campaigns as $kivun_ec ) : ?>
																	<option value="<?php echo esc_attr( $kivun_ec->id ); ?>" <?php selected( (int) $row->campaign_id, (int) $kivun_ec->id ); ?>><?php echo esc_html( $kivun_ec->label ); ?></option>
																<?php endforeach; ?>
															</select>
														</div>
														<div class="kivun-form-row">
															<label><?php esc_html_e( 'פירוט הפרסום', 'kivun' ); ?></label>
															<input type="text" class="kivun-cc-input kivun-as-label" value="<?php echo esc_attr( (string) $row->label ); ?>">
														</div>
														<div class="kivun-form-row">
															<label><?php esc_html_e( 'מתאריך *', 'kivun' ); ?></label>
															<input type="date" class="kivun-cc-input kivun-as-start" dir="ltr" value="<?php echo esc_attr( (string) $row->starts_on ); ?>">
														</div>
														<div class="kivun-form-row">
															<label><?php esc_html_e( 'עד תאריך', 'kivun' ); ?></label>
															<input type="date" class="kivun-cc-input kivun-as-end" dir="ltr" value="<?php echo esc_attr( (string) ( $row->ends_on ?? '' ) ); ?>">
															<p class="kivun-field-hint"><?php esc_html_e( 'ריק = ממשיך עד שיוגדר שיוך חדש.', 'kivun' ); ?></p>
														</div>
													</div>
													<?php
													// Said plainly, because it is the part nobody
													// expects: the calls follow the dates.
													?>
													<p class="kivun-field-hint"><?php esc_html_e( 'שינוי התאריכים מזיז גם את השיחות: שיחה שיצאה מהתקופה חוזרת להיות לא משויכת, ושיחה שנכנסה אליה תיזקף לשיוך הזה.', 'kivun' ); ?></p>
													<p class="kivun-error kivun-phone-error" style="display:none;color:var(--kivun-error)"></p>
													<div class="kivun-form-actions">
														<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm"><?php esc_html_e( 'עדכון השיוך', 'kivun' ); ?></button>
													</div>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php else : ?>
							<p class="kivun-field-hint"><?php esc_html_e( 'למספר הזה עדיין לא הוגדר שיוך. שיחות שיגיעו אליו יירשמו, אך לא ישויכו לקמפיין.', 'kivun' ); ?></p>
						<?php endif; ?>

						<details class="kivun-camp-addlink">
							<summary class="kivun-camp-summary"><?php esc_html_e( '+ שיוך לתקופה חדשה', 'kivun' ); ?></summary>
							<form class="kivun-assignment-form" data-number="<?php echo esc_attr( $num->id ); ?>">
								<div class="kivun-form-grid">
									<div class="kivun-form-row">
										<label><?php esc_html_e( 'מדיה *', 'kivun' ); ?></label>
										<input type="text" class="kivun-cc-input kivun-as-media" list="kivun-media-list" autocomplete="off" placeholder="<?php esc_attr_e( 'למשל: עיתון', 'kivun' ); ?>">
										<p class="kivun-field-hint"><?php esc_html_e( 'טקסט חופשי. ההצעות הן קיצור דרך — כדאי לכתוב אותה מדיה באותו נוסח, אחרת היא תתפצל לשתי שורות בדוח.', 'kivun' ); ?></p>
									</div>
									<div class="kivun-form-row">
										<label><?php esc_html_e( 'קמפיין', 'kivun' ); ?></label>
										<select class="kivun-cc-input kivun-as-campaign">
											<option value="0"><?php esc_html_e( '— ללא —', 'kivun' ); ?></option>
											<?php foreach ( $campaigns as $camp ) : ?>
												<option value="<?php echo esc_attr( $camp->id ); ?>"><?php echo esc_html( $camp->label ); ?></option>
											<?php endforeach; ?>
											<option value="__new__"><?php esc_html_e( '+ קמפיין חדש…', 'kivun' ); ?></option>
										</select>
										<p class="kivun-field-hint"><?php esc_html_e( 'אותם קמפיינים של הלשונית "קמפיינים" — כך רואים יחד לידים מהאתר ושיחות טלפון.', 'kivun' ); ?></p>

										<?php
										// A campaign is often thought of only once the
										// number is in hand, and leaving the screen to
										// create one means losing the half-filled
										// assignment. It is created from here instead,
										// by the same handler and the same rules.
										?>
										<div class="kivun-newcamp" hidden>
											<label class="kivun-cc-sub"><?php esc_html_e( 'שם הקמפיין החדש', 'kivun' ); ?></label>
											<input type="text" class="kivun-cc-input kivun-newcamp-name" placeholder="<?php esc_attr_e( 'למשל: כנס הורים 2026', 'kivun' ); ?>">

											<label class="kivun-cc-sub"><?php esc_html_e( 'יעד ברירת מחדל', 'kivun' ); ?></label>
											<?php self::campaign_target_field( $contents, $pages ); ?>
											<p class="kivun-field-hint"><?php esc_html_e( 'לאן יגיעו הגולשים מקישורי הקמפיין. נדרש גם כשהקמפיין מתפרסם בטלפון בלבד, כדי שאפשר יהיה להוסיף לו קישורים בהמשך.', 'kivun' ); ?></p>

											<p class="kivun-error kivun-newcamp-error" style="display:none;color:var(--kivun-error)"></p>
											<div class="kivun-form-actions">
												<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-newcamp-btn"><?php esc_html_e( 'יצירת הקמפיין', 'kivun' ); ?></button>
												<span class="kivun-cc-wa-status kivun-newcamp-status" role="status" aria-live="polite"></span>
											</div>
										</div>
									</div>
									<div class="kivun-form-row">
										<label><?php esc_html_e( 'פירוט הפרסום', 'kivun' ); ?></label>
										<input type="text" class="kivun-cc-input kivun-as-label" placeholder="<?php esc_attr_e( 'למשל: מודעה בעמוד 4', 'kivun' ); ?>">
									</div>
									<div class="kivun-form-row">
										<label><?php esc_html_e( 'מתאריך *', 'kivun' ); ?></label>
										<input type="date" class="kivun-cc-input kivun-as-start" dir="ltr" value="<?php echo esc_attr( $today ); ?>">
									</div>
									<div class="kivun-form-row">
										<label><?php esc_html_e( 'עד תאריך', 'kivun' ); ?></label>
										<input type="date" class="kivun-cc-input kivun-as-end" dir="ltr">
										<p class="kivun-field-hint"><?php esc_html_e( 'ריק = ממשיך עד שיוגדר שיוך חדש.', 'kivun' ); ?></p>
									</div>
								</div>
								<p class="kivun-error kivun-phone-error" style="display:none;color:var(--kivun-error)"></p>
								<div class="kivun-form-actions">
									<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm"><?php esc_html_e( 'שמירת השיוך', 'kivun' ); ?></button>
								</div>
							</form>
						</details>
					</details>
				<?php endforeach; ?>
			<?php endif; ?>

			<datalist id="kivun-media-list">
				<?php foreach ( $media as $suggestion ) : ?>
					<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
				<?php endforeach; ?>
			</datalist>

			<details class="kivun-cc-card kivun-camp-new">
				<summary class="kivun-camp-summary"><?php esc_html_e( '+ הוספת מספר', 'kivun' ); ?></summary>
				<form class="kivun-phone-form">
					<div class="kivun-form-grid">
						<div class="kivun-form-row">
							<label><?php esc_html_e( 'מספר הטלפון *', 'kivun' ); ?></label>
							<input type="text" class="kivun-cc-input kivun-phone-number" dir="ltr" placeholder="072-2345678">
						</div>
						<div class="kivun-form-row">
							<label><?php esc_html_e( 'כינוי (אופציונלי)', 'kivun' ); ?></label>
							<input type="text" class="kivun-cc-input kivun-phone-label" placeholder="<?php esc_attr_e( 'למשל: מספר 7', 'kivun' ); ?>">
						</div>
					</div>
					<p class="kivun-error kivun-phone-error" style="display:none;color:var(--kivun-error)"></p>
					<div class="kivun-form-actions">
						<button type="submit" class="kivun-cc-btn"><?php esc_html_e( 'הוספה', 'kivun' ); ?></button>
					</div>
				</form>

				<div class="kivun-phone-import">
					<label class="kivun-cc-sub"><?php esc_html_e( 'או ייבוא מקובץ CSV', 'kivun' ); ?></label>
					<p class="kivun-field-hint">
						<?php esc_html_e( 'עמודה אחת עם המספר, ואם רוצים עמודה נוספת עם כינוי. שורת כותרת, סדר העמודות וקידוד עברית מזוהים לבד. מספרים שכבר קיימים יידלגו.', 'kivun' ); ?>
					</p>
					<div class="kivun-camp-out">
						<input type="file" class="kivun-cc-input kivun-phone-file" accept=".csv,text/csv,text/plain">
						<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-phone-import-btn"><?php esc_html_e( 'ייבוא', 'kivun' ); ?></button>
					</div>
					<span class="kivun-cc-wa-status kivun-phone-import-status" role="status" aria-live="polite"></span>
				</div>
			</details>

			<?php
			// Folded away: this is set up once, when the switchboard is
			// connected, and then never touched again — while the numbers and
			// their assignments above it are the daily work.
			?>
			<details class="kivun-cc-card kivun-phone-setup">
				<summary class="kivun-camp-summary"><?php esc_html_e( 'חיבור למרכזייה — הגדרה חד-פעמית', 'kivun' ); ?></summary>
				<p class="kivun-cc-hint">
					<?php esc_html_e( 'מדביקים את הכתובת בהגדרות הוובהוק של המרכזייה. POST ו-GET שניהם נתמכים, ואין צורך לבחור. די בשליחה בתחילת שיחה — כל המספרים יכולים לשלוח לאותה כתובת, כי המספר שחויג הוא שמזהה את הפרסום.', 'kivun' ); ?>
				</p>

				<label class="kivun-cc-sub"><?php esc_html_e( 'כתובת (HTTP URL)', 'kivun' ); ?></label>
				<div class="kivun-camp-out">
					<input type="text" class="kivun-cc-input kivun-camp-saved" dir="ltr" readonly value="<?php echo esc_url( Kivun_Phones::webhook_url() ); ?>">
					<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
				</div>

				<label class="kivun-cc-sub"><?php esc_html_e( 'כותרת (HTTP header)', 'kivun' ); ?></label>
				<div class="kivun-camp-out">
					<input type="text" class="kivun-cc-input kivun-camp-saved" dir="ltr" readonly value="Content-Type: application/json">
					<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
				</div>

				<p class="kivun-cc-hint" style="margin-top:.8rem">
					<?php esc_html_e( 'שני שדות נדרשים: המספר שחויג והמספר של המתקשר. השמות מזוהים לפי המקובל אצל הספקים, ללא תלות באותיות גדולות/קטנות:', 'kivun' ); ?>
				</p>
				<ul class="kivun-cc-hint" style="margin:.3rem 0 .6rem 0;padding-inline-start:1.1rem">
					<li><?php esc_html_e( 'המספר שחויג:', 'kivun' ); ?> <code dir="ltr">Ddi</code> / <code dir="ltr">dnumber</code> / <code dir="ltr">cnumber</code> / <code dir="ltr">extension</code></li>
					<li><?php esc_html_e( 'המתקשר:', 'kivun' ); ?> <code dir="ltr">Cli</code> / <code dir="ltr">callerid</code> / <code dir="ltr">snumber</code></li>
					<li><?php esc_html_e( 'מזהה השיחה:', 'kivun' ); ?> <code dir="ltr">Id</code> / <code dir="ltr">callid</code> / <code dir="ltr">uniqueid</code></li>
				</ul>
				<p class="kivun-cc-hint">
					<?php esc_html_e( 'אם המרכזייה שולחת גם בסיום שיחה — שדות משך השיחה (Duration / billsec), שעת ההתחלה (startAt) וקישור להקלטה (dlink) ייקלטו אף הם, ויעדכנו את אותה שורה לפי מזהה השיחה במקום ליצור כפילות.', 'kivun' ); ?>
				</p>

				<label class="kivun-cc-sub"><?php esc_html_e( 'גוף הבקשה — לספקים שדורשים תבנית (למשל 015)', 'kivun' ); ?></label>
				<textarea class="kivun-cc-input kivun-cc-textarea kivun-camp-saved" dir="ltr" rows="6" readonly>{"callid":#callid#,"uniqueid":#uniqueid#,"start":#start#,"status":"#status#","direction":"#direction#","dnumber":"#dnumber#","cnumber":"#cnumber#","extension":"#extension#","callerid":"#callerid_external#","callername":"#callername#","snumber":"#snumber#","totaltime":#totaltime#,"talktime":#talktime#,"recording":"#recording#"}</textarea>
				<div class="kivun-wa-actions">
					<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקת גוף הבקשה', 'kivun' ); ?></button>
				</div>
				<p class="kivun-field-hint">
					<?php esc_html_e( 'מרכזייה שיש לה רשימת שדות מוכנה לסימון — אין צורך בתבנית הזו, מספיק לסמן את השדות.', 'kivun' ); ?>
				</p>

				<p class="kivun-field-hint">
					<?php esc_html_e( 'הכתובת מכילה מפתח סודי בסופה — כל מי שמחזיק בה יכול לרשום שיחות. אין לפרסם אותה מחוץ להגדרות המרכזייה. המפתח נמצא בנתיב ולא אחרי סימן השאלה, כדי שמרכזייה שבונה לעצמה שורת פרמטרים לא תמחק אותו. אפשר גם לשלוח אותו בכותרת Authorization.', 'kivun' ); ?>
				</p>
			</details>
		</div>
		<?php
	}

	/**
	 * Render the campaigns workspace: campaigns as containers, each holding the
	 * tracking links built under it, with per-link and per-campaign lead counts.
	 *
	 * @return void
	 */
	private static function front_campaigns(): void {
		// An outside agency reads this screen and writes nothing on it. The
		// handlers behind every control below already refuse the role — it
		// holds no editing capability — so this is not the lock but the
		// courtesy: a button that cannot work should not be offered.
		$kivun_read_only = self::is_agency();

		// Whether a lead count is worth making a link of. Separate from the
		// line above: the agency may now read the enquiries its campaigns
		// produced even though it writes nothing here, so the figure leads
		// somewhere for it too — while a reader who cannot open that tab gets
		// the number alone rather than an anchor back to the page they are on.
		$kivun_leads_reachable = self::can_read_leads();

		// Counted once for the whole screen rather than per campaign.
		$kivun_call_counts = Kivun_Campaigns::campaign_call_counts();

		// The tracked numbers each campaign is running on, so a campaign
		// advertised by phone and by link is read in one place.
		$kivun_numbers_by_camp = Kivun_Phones::by_campaign();
		$kivun_knows_answers   = Kivun_Phones::has_answer_data();

		// Links here are built from the page's own address. add_query_arg() with
		// no base builds on the CURRENT one, which drags every stale parameter
		// along: a leftover kivun_group forces the console back to the publish
		// form, and a leftover filter narrows the leads table to nothing.
		$page_url = (string) get_permalink();
		$page_url = $page_url ? $page_url : home_url();

		$campaigns   = Kivun_Campaigns::all();
		$links_by_id = Kivun_Campaigns::links_by_campaign();
		$link_counts = Kivun_Campaigns::link_lead_counts();
		$camp_counts = Kivun_Campaigns::campaign_lead_counts();

		// Targets: content created here, plus every published page.
		$contents = get_posts(
			array(
				'post_type'              => array_values( self::type_map() ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$pages    = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 100, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		?>
		<div class="kivun-cc-front">
			<div class="kivun-cc-head">
				<h2 class="kivun-cc-title"><?php esc_html_e( 'קמפיינים', 'kivun' ); ?></h2>
				<p class="kivun-cc-lead">
					<?php esc_html_e( 'קמפיין הוא מסגרת אחת — אירוע, מבצע או תוכן שמקדמים. תחתיו יוצרים קישור נפרד לכל מפרסם, וכך רואים בדיוק מי הביא כמה פניות.', 'kivun' ); ?>
				</p>
			</div>

			<?php
			// The dates are the report. "How did last month go" is the
			// question people actually bring, and the answer used to be to
			// download everything and cut it up in a spreadsheet.
			?>
			<form class="kivun-report-bar" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kivun_export_csv">
				<input type="hidden" name="type" value="campaigns">
				<?php wp_nonce_field( 'kivun_export', '_wpnonce', false ); ?>
				<label class="kivun-cc-sub" for="kivun-camp-from"><?php esc_html_e( 'דוח קמפיינים', 'kivun' ); ?></label>
				<span class="kivun-report-bar__row">
					<input type="date" id="kivun-camp-from" name="from" aria-label="<?php esc_attr_e( 'מתאריך', 'kivun' ); ?>">
					<span aria-hidden="true">–</span>
					<input type="date" name="to" aria-label="<?php esc_attr_e( 'עד תאריך', 'kivun' ); ?>">
					<select name="campaign_id" aria-label="<?php esc_attr_e( 'קמפיין', 'kivun' ); ?>">
						<option value="0"><?php esc_html_e( 'כל הקמפיינים', 'kivun' ); ?></option>
						<?php foreach ( $campaigns as $kivun_rep_camp ) : ?>
							<option value="<?php echo esc_attr( (string) $kivun_rep_camp->id ); ?>"><?php echo esc_html( $kivun_rep_camp->label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost">
						<?php echo kivun_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?>
					</button>
				</span>
				<p class="kivun-field-hint"><?php esc_html_e( 'שורה לכל קישור: כניסות, לידים ואחוז המרה. התאריכים חותכים את הלידים; השאירו ריק להכל.', 'kivun' ); ?></p>
			</form>

			<?php if ( ! $kivun_read_only ) : ?>
				<details class="kivun-cc-card kivun-camp-new">
					<summary class="kivun-camp-summary"><?php esc_html_e( '+ קמפיין חדש', 'kivun' ); ?></summary>
					<?php self::campaign_form( $contents, $pages ); ?>
				</details>
			<?php endif; ?>

			<?php if ( ! $campaigns ) : ?>
				<div class="kivun-cc-note"><?php esc_html_e( 'עדיין לא נוצרו קמפיינים. התחילו בקמפיין אחד, והוסיפו תחתיו קישור לכל מפרסם.', 'kivun' ); ?></div>
			<?php else : ?>
				<?php
				// The ones put away are captured as they are drawn and printed
				// below, inside a section that starts shut. Drawn by the same
				// code either way: an archived campaign is still edited, still
				// counted and still has its links copied out of it — the only
				// difference is where on the page it sits.
				$kivun_put_away = '';
				$kivun_away_n   = 0;
				foreach ( $campaigns as $camp ) :
					$kivun_is_away = Kivun_Campaigns::is_archived( $camp );
					if ( $kivun_is_away ) {
						++$kivun_away_n;
						ob_start();
					}
					$links = $links_by_id[ (int) $camp->id ] ?? array();
					$rows  = 0;
					foreach ( $links as $link ) {
						$rows += (int) ( $link_counts[ (int) $link->id ] ?? 0 );
					}
					// The campaign's own count, not the sum of its rows: a lead
					// tagged with the campaign through a link that was since
					// edited or removed still belongs to it.
					//
					// The gap between the two is worth showing, but only when
					// it is real: a lead old enough to predate the utm columns
					// is still counted against its link, by the label at the
					// end of its source text, while the campaign counts the
					// columns alone. That makes the difference an undercount
					// rather than an overcount, so it is floored at zero and
					// the note stays quiet rather than crying wolf.
					$total    = (int) ( $camp_counts[ (int) $camp->id ] ?? 0 );
					$unmapped = max( 0, $total - $rows );
					?>
					<?php
					// Collapsed by default. With many campaigns, each holding many
					// links, everything open at once buries the one being looked
					// for — and the header alone already carries the counts.
					?>
					<details class="kivun-cc-card kivun-camp-block" data-campaign-row="<?php echo esc_attr( $camp->id ); ?>">
						<summary class="kivun-camp-head">
							<div class="kivun-camp-heading">
								<span class="kivun-camp-caret" aria-hidden="true"></span>
								<div>
									<h3 class="kivun-camp-title"><?php echo esc_html( $camp->label ); ?></h3>
									<span class="kivun-cc-source"><code><?php echo esc_html( $camp->utm_campaign ); ?></code></span>
								</div>
							</div>
							<div class="kivun-camp-metrics">
								<span class="kivun-cc-badge"><?php echo esc_html( sprintf( /* translators: %s: number of links. */ _n( '%s קישור', '%s קישורים', count( $links ), 'kivun' ), number_format_i18n( count( $links ) ) ) ); ?></span>
								<span class="kivun-cc-badge kivun-camp-total"><?php echo esc_html( sprintf( /* translators: %s: number of leads. */ _n( '%s פנייה', '%s פניות', $total, 'kivun' ), number_format_i18n( $total ) ) ); ?></span>
								<?php
								// Calls belong beside enquiries: both are what the
								// campaign produced, and a campaign promoted by a
								// tracked number is answered mostly by phone.
								$kivun_calls = (int) ( $kivun_call_counts[ (int) $camp->id ] ?? 0 );
								?>
								<?php if ( $kivun_calls ) : ?>
									<span class="kivun-cc-badge"><?php echo esc_html( sprintf( /* translators: %s: number of calls. */ _n( '%s שיחה', '%s שיחות', $kivun_calls, 'kivun' ), number_format_i18n( $kivun_calls ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( ! $kivun_read_only ) : ?>
								<button
									type="button"
									class="kivun-cc-iconbtn kivun-edit-campaign"
									data-id="<?php echo esc_attr( $camp->id ); ?>"
									title="<?php esc_attr_e( 'עריכת הקמפיין', 'kivun' ); ?>"
								>
									<?php echo kivun_icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
									<span class="kivun-sr-only"><?php esc_html_e( 'עריכת הקמפיין', 'kivun' ); ?></span>
								</button>
								<button
									type="button"
									class="kivun-cc-iconbtn kivun-archive-campaign"
									data-id="<?php echo esc_attr( $camp->id ); ?>"
									data-archived="<?php echo $kivun_is_away ? '0' : '1'; ?>"
									title="<?php echo $kivun_is_away ? esc_attr__( 'החזרה מהארכיון', 'kivun' ) : esc_attr__( 'העברה לארכיון — הקישורים ימשיכו לעבוד', 'kivun' ); ?>"
								>
									<?php echo kivun_icon( $kivun_is_away ? 'restore' : 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
									<span class="kivun-sr-only"><?php echo $kivun_is_away ? esc_html__( 'החזרה מהארכיון', 'kivun' ) : esc_html__( 'העברה לארכיון', 'kivun' ); ?></span>
								</button>
								<button
									type="button"
									class="kivun-cc-iconbtn kivun-cc-iconbtn--danger kivun-delete-campaign"
									data-id="<?php echo esc_attr( $camp->id ); ?>"
									title="<?php esc_attr_e( 'מחיקת הקמפיין וכל הקישורים שתחתיו', 'kivun' ); ?>"
								>
									<?php echo kivun_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
									<span class="kivun-sr-only"><?php esc_html_e( 'מחיקת הקמפיין', 'kivun' ); ?></span>
								</button>
								<?php endif; ?>
							</div>
						</summary>

						<div class="kivun-camp-editpanel" data-campaign-edit="<?php echo esc_attr( $camp->id ); ?>" hidden>
							<?php self::campaign_form( $contents, $pages, $camp ); ?>
						</div>

						<?php if ( $unmapped ) : ?>
							<p class="kivun-field-hint kivun-camp-unmapped">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of leads. */
										_n(
											'%s פנייה הגיעה עם התיוג של הקמפיין אך לא דרך אף אחד מהקישורים שברשימה — למשל קישור שנערך או נמחק אחרי שהופץ.',
											'%s פניות הגיעו עם התיוג של הקמפיין אך לא דרך אף אחד מהקישורים שברשימה — למשל קישור שנערך או נמחק אחרי שהופץ.',
											$unmapped,
											'kivun'
										),
										number_format_i18n( $unmapped )
									)
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( $links ) : ?>
							<div class="kivun-cc-tablewrap">
								<table class="kivun-cc-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'מפרסם', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'מקור / מדיום', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'קישור', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'פניות', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $links as $link ) : ?>
										<?php $link_leads = (int) ( $link_counts[ (int) $link->id ] ?? 0 ); ?>
										<tr data-link-row="<?php echo esc_attr( $link->id ); ?>">
											<td><strong><?php echo esc_html( $link->label ); ?></strong></td>
											<td>
												<span class="kivun-cc-badge"><?php echo esc_html( $link->utm_source ); ?></span>
												<?php if ( $link->utm_medium ) : ?>
													<span class="kivun-cc-badge"><?php echo esc_html( $link->utm_medium ); ?></span>
												<?php endif; ?>
												<?php if ( $link->utm_content ) : ?>
													<span class="kivun-cc-badge"><?php echo esc_html( $link->utm_content ); ?></span>
												<?php endif; ?>
											</td>
											<td class="kivun-camp-cell">
												<?php $kivun_short = Kivun_Short_Links::url_for( (string) ( $link->slug ?? '' ) ); ?>
												<?php if ( '' !== $kivun_short ) : ?>
													<?php
													// First, and the one the copy button takes: this is
													// the link that gets pasted into a message. The full
													// one is underneath for anyone who needs to read the
													// parameters, which is a different job.
													?>
													<div class="kivun-camp-cell__row">
														<input type="text" class="kivun-cc-input kivun-camp-saved kivun-camp-short" dir="ltr" readonly value="<?php echo esc_url( $kivun_short ); ?>">
														<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
														<span class="kivun-camp-clicks" title="<?php esc_attr_e( 'כניסות דרך הקישור הקצר', 'kivun' ); ?>">
															<?php
															/* translators: %s: number of clicks. */
															echo esc_html( sprintf( __( '%s כניסות', 'kivun' ), number_format_i18n( (int) ( $link->clicks ?? 0 ) ) ) );
															?>
														</span>
													</div>
												<?php endif; ?>
												<div class="kivun-camp-cell__row">
													<input type="text" class="kivun-cc-input kivun-camp-saved" dir="ltr" readonly value="<?php echo esc_url( $link->final_url ); ?>">
													<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
													<?php if ( ! empty( $link->whatsapp ) ) : ?>
														<?php
														// The message is behind a fold, but copying it is the
														// thing people came to do — so that one button stays
														// out here and works without opening anything.
														?>
														<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-wa-copy"><?php esc_html_e( 'העתקת ההודעה', 'kivun' ); ?></button>
													<?php endif; ?>
												</div>

												<?php if ( ! empty( $link->whatsapp ) ) : ?>
													<details class="kivun-link-wa-saved">
														<summary class="kivun-camp-summary">
															<?php
															// A reader who cannot save should not be promised an
															// edit before the fold has even opened.
															if ( $kivun_read_only ) {
																esc_html_e( 'הצגת ההודעה', 'kivun' );
															} else {
																esc_html_e( 'הצגה ועריכה של ההודעה', 'kivun' );
															}
															?>
														</summary>
														<?php
														// Cleaned on the way out as well as on the way in: a promo
														// saved by an earlier release still has its markup, and
														// nobody should have to wait for an upgrade to read it.
														?>
														<?php
														// Read-only for an agency, which may take this
														// message and may not change it. Left editable it
														// invites a rewrite that cannot be saved and is
														// gone on the next reload — and worse, one that
														// gets copied and sent while the message stored
														// here still says something else.
														//
														// readonly rather than disabled: the text stays
														// selectable and the copy button still reads it,
														// which is the one thing this reader came for.
														?>
														<textarea class="kivun-cc-input kivun-cc-textarea kivun-wa-text" rows="8" dir="rtl" <?php echo $kivun_read_only ? 'readonly' : ''; ?>><?php echo esc_textarea( (string) $link->whatsapp ); ?></textarea>
														<div class="kivun-wa-actions">
															<?php if ( ! $kivun_read_only ) : ?>
																<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-wa-save" data-id="<?php echo esc_attr( (string) $link->id ); ?>"><?php esc_html_e( 'שמירת ההודעה', 'kivun' ); ?></button>
															<?php endif; ?>
															<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-wa-copy"><?php esc_html_e( 'העתקת ההודעה', 'kivun' ); ?></button>
															<a class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-wa-share" href="https://wa.me/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'שליחה בוואטסאפ', 'kivun' ); ?></a>
															<span class="kivun-cc-wa-status" role="status" aria-live="polite"></span>
														</div>
														<?php if ( $kivun_read_only ) : ?>
															<p class="kivun-field-hint"><?php esc_html_e( 'ההודעה מוצגת לצפייה ולהעתקה בלבד. הקישור שבתוכה שייך למפרסם הזה, והוא מה שמודד את הכניסות — כדאי להעתיק את ההודעה כפי שהיא.', 'kivun' ); ?></p>
														<?php else : ?>
															<p class="kivun-field-hint"><?php esc_html_e( 'הקישור המסומן שבתוך ההודעה שייך למפרסם הזה — כדאי לא לשנות אותו. לכתיבת הודעה מחדש מהתוכן שביעד, השתמשו בכפתור העריכה שבסוף השורה.', 'kivun' ); ?></p>
														<?php endif; ?>
													</details>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $link_leads && $kivun_leads_reachable ) : ?>
													<a class="kivun-cc-leadlink" href="
													<?php
													echo esc_url(
														add_query_arg(
															array(
																'kivun_tab' => 'leads',
																'kivun_ls'  => $link->utm_label,
															),
															$page_url
														)
													);
													?>
																						"><?php echo esc_html( number_format_i18n( $link_leads ) ); ?></a>
												<?php elseif ( $link_leads ) : ?>
													<?php
													// The number, with nowhere to go. The link opens the
													// enquiries themselves, and an anchor that lands back
													// on the page it was clicked from reads as a fault
													// rather than a boundary.
													?>
													<?php echo esc_html( number_format_i18n( $link_leads ) ); ?>
												<?php else : ?>
													<span class="kivun-muted" aria-hidden="true">—</span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( ! $kivun_read_only ) : ?>
												<button
													type="button"
													class="kivun-cc-iconbtn kivun-edit-link"
													data-id="<?php echo esc_attr( $link->id ); ?>"
													title="<?php esc_attr_e( 'עריכת הקישור', 'kivun' ); ?>"
												>
													<?php echo kivun_icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
													<span class="kivun-sr-only"><?php esc_html_e( 'עריכת הקישור', 'kivun' ); ?></span>
												</button>
												<button
													type="button"
													class="kivun-cc-iconbtn kivun-cc-iconbtn--danger kivun-delete-link"
													data-id="<?php echo esc_attr( $link->id ); ?>"
													title="<?php esc_attr_e( 'מחיקת הקישור', 'kivun' ); ?>"
												>
													<?php echo kivun_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
													<span class="kivun-sr-only"><?php esc_html_e( 'מחיקת הקישור', 'kivun' ); ?></span>
												</button>
												<?php endif; ?>
											</td>
										</tr>
										<?php if ( ! $kivun_read_only ) : ?>
										<tr class="kivun-link-editrow" data-link-edit="<?php echo esc_attr( $link->id ); ?>" hidden>
											<td colspan="5"><?php self::campaign_link_form( $camp, $link ); ?></td>
										</tr>
										<?php endif; ?>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php else : ?>
							<p class="kivun-field-hint"><?php esc_html_e( 'אין עדיין קישורים בקמפיין הזה. הוסיפו קישור לכל מפרסם כדי לדעת מי מביא פניות.', 'kivun' ); ?></p>
						<?php endif; ?>

						<?php
						// The campaign's phone numbers, under its links. A
						// campaign on a billboard and in a newsletter is one
						// campaign: the printed number and the link are two
						// of its channels, and reading them off two screens
						// leaves somebody adding up by hand to answer how it
						// went.
						$kivun_camp_numbers = $kivun_numbers_by_camp[ (int) $camp->id ] ?? array();
						?>
						<?php if ( $kivun_camp_numbers ) : ?>
							<div class="kivun-cc-tablewrap kivun-camp-numbers">
								<table class="kivun-cc-table">
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e( 'מספר מעקב', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'מדיה', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'תקופה', 'kivun' ); ?></th>
											<th scope="col"><?php esc_html_e( 'שיחות', 'kivun' ); ?></th>
											<?php if ( $kivun_knows_answers ) : ?>
												<th scope="col"><?php esc_html_e( 'נענו', 'kivun' ); ?></th>
											<?php endif; ?>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $kivun_camp_numbers as $kivun_assign ) : ?>
										<tr>
											<td>
												<strong dir="ltr"><?php echo esc_html( (string) $kivun_assign->number ); ?></strong>
												<?php if ( ! empty( $kivun_assign->label ) ) : ?>
													<br><span class="kivun-cc-source"><?php echo esc_html( (string) $kivun_assign->label ); ?></span>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( Kivun_Phones::media_label( (string) $kivun_assign->media ) ); ?></td>
											<?php
											// A number is reusable, so a row here may be a
											// period that has closed. Shown as such: an
											// ended assignment that reads like a live one
											// is a number somebody prints on a flyer after
											// it has moved to another campaign.
											$kivun_ends = (string) ( $kivun_assign->ends_on ?? '' );
											$kivun_over = '' !== $kivun_ends && $kivun_ends < gmdate( 'Y-m-d' );
											?>
											<td>
												<?php echo esc_html( kivun_local_date( (string) $kivun_assign->starts_on ) ); ?>
												<?php if ( '' !== $kivun_ends ) : ?>
													– <?php echo esc_html( kivun_local_date( $kivun_ends ) ); ?>
												<?php endif; ?>
												<?php if ( $kivun_over ) : ?>
													<br><span class="kivun-cc-badge kivun-camp-ended"><?php esc_html_e( 'הסתיים', 'kivun' ); ?></span>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( number_format_i18n( (int) $kivun_assign->calls ) ); ?></td>
											<?php if ( $kivun_knows_answers ) : ?>
												<td><?php echo esc_html( number_format_i18n( (int) $kivun_assign->answered ) ); ?></td>
											<?php endif; ?>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>

						<?php if ( ! $kivun_read_only ) : ?>
						<details class="kivun-camp-addlink">
							<summary class="kivun-camp-summary"><?php esc_html_e( '+ הוספת קישור למפרסם', 'kivun' ); ?></summary>
							<?php self::campaign_link_form( $camp ); ?>
						</details>
						<?php endif; ?>
					</details>
					<?php
					if ( $kivun_is_away ) {
						$kivun_put_away .= ob_get_clean();
					}
					?>
				<?php endforeach; ?>

				<?php if ( '' !== $kivun_put_away ) : ?>
					<details class="kivun-archive">
						<summary class="kivun-camp-summary">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: how many campaigns are archived. */
									__( 'ארכיון (%s)', 'kivun' ),
									number_format_i18n( $kivun_away_n )
								)
							);
							?>
						</summary>
						<?php
						// Captured above, from the same drawing code as the rest.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already-escaped markup, captured above.
						echo $kivun_put_away;
						?>
					</details>
				<?php endif; ?>

				<p class="kivun-field-hint"><?php esc_html_e( 'מחיקה מסירה את הקישור מהרשימה בלבד — הפניות שכבר הגיעו דרכו נשמרות.', 'kivun' ); ?></p>
			<?php endif; ?>

			<datalist id="kivun-camp-sources">
				<?php foreach ( Kivun_Campaigns::sources() as $sv => $sl ) : ?>
					<option value="<?php echo esc_attr( $sv ); ?>"><?php echo esc_html( $sl ); ?></option>
				<?php endforeach; ?>
			</datalist>
			<datalist id="kivun-camp-mediums">
				<?php foreach ( Kivun_Campaigns::mediums() as $mv => $ml ) : ?>
					<option value="<?php echo esc_attr( $mv ); ?>"><?php echo esc_html( $ml ); ?></option>
				<?php endforeach; ?>
			</datalist>
		</div>
		<?php
	}

	/**
	 * The campaign targets, sorted into a heading per content type.
	 *
	 * @param array<int,\WP_Post> $contents The content posts.
	 * @return array<string,array<int,\WP_Post>> Heading => its posts, empty
	 *                                           headings left out.
	 */
	private static function target_groups( array $contents ): array {
		$headings = array(
			'kivun_course'   => __( 'קורסים', 'kivun' ),
			'kivun_workshop' => __( 'דפי נחיתה', 'kivun' ),
			'kivun_session'  => __( 'סדנאות', 'kivun' ),
			'kivun_event'    => __( 'אירועים', 'kivun' ),
		);

		$grouped = array();
		foreach ( $headings as $type => $heading ) {
			foreach ( $contents as $post ) {
				if ( $type === $post->post_type ) {
					$grouped[ $heading ][] = $post;
				}
			}
		}

		// Anything of a type not named above still has to be reachable.
		foreach ( $contents as $post ) {
			if ( ! isset( $headings[ $post->post_type ] ) ) {
				$grouped[ __( 'תכנים', 'kivun' ) ][] = $post;
			}
		}

		return $grouped;
	}

	/**
	 * The destination picker a campaign is created with.
	 *
	 * Rendered in two places — the campaigns screen and the shortcut for
	 * creating a campaign while assigning a phone number — so it lives in one.
	 *
	 * @param array<int,\WP_Post> $contents Content posts offered as targets.
	 * @param array<int,\WP_Post> $pages    Site pages offered as targets.
	 * @param string              $target   The currently chosen URL.
	 * @param bool                $custom   Whether that URL is a typed-in one.
	 * @return void
	 */
	private static function campaign_target_field( array $contents, array $pages, string $target = '', bool $custom = false ): void {
		?>
		<select class="kivun-cc-input kivun-camp-target">
			<option value=""><?php esc_html_e( '— בחר/י יעד —', 'kivun' ); ?></option>
			<?php
			// Grouped by what the target actually is. A piece of content built
			// here is often two posts — a course and the landing page made for
			// it — carrying the same title and two different addresses. Listed
			// flat they read as the same thing twice, with no way to tell which
			// link you are about to hand out. They are not duplicates and must
			// not be merged: pointing a campaign at the landing page rather
			// than the course is a real choice. So each is shown under its own
			// heading, and the choice becomes legible.
			foreach ( self::target_groups( $contents ) as $kivun_group_label => $kivun_group ) :
				?>
				<optgroup label="<?php echo esc_attr( $kivun_group_label ); ?>">
					<?php foreach ( $kivun_group as $c ) : ?>
						<?php $c_url = (string) get_permalink( $c->ID ); ?>
						<option value="<?php echo esc_url( $c_url ); ?>" <?php selected( ! $custom && $c_url === $target ); ?>><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
			<?php if ( $pages ) : ?>
				<optgroup label="<?php esc_attr_e( 'עמודים באתר', 'kivun' ); ?>">
					<?php foreach ( $pages as $pg ) : ?>
						<?php $pg_url = (string) get_permalink( $pg->ID ); ?>
						<option value="<?php echo esc_url( $pg_url ); ?>" <?php selected( ! $custom && $pg_url === $target ); ?>><?php echo esc_html( $pg->post_title ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
			<option value="__custom__" <?php selected( $custom ); ?>><?php esc_html_e( 'כתובת אחרת (הדבקה ידנית)…', 'kivun' ); ?></option>
		</select>
		<input type="url" class="kivun-cc-input kivun-camp-custom" dir="ltr" placeholder="https://…" value="<?php echo $custom ? esc_url( $target ) : ''; ?>" <?php echo $custom ? '' : 'hidden'; ?>>
		<?php
	}

	/**
	 * A ranked breakdown: the rows in order, each with a bar as long as its
	 * share of the largest.
	 *
	 * The bar carries the magnitude by its length, so every row wears the same
	 * one colour — a different hue per row would say the rows are different
	 * kinds of thing, which they are not, and would be unreadable to a
	 * colourblind reader at that. The count is written out beside it, so
	 * nothing here is conveyed by colour alone.
	 *
	 * @param string                                  $title The heading.
	 * @param array<int,array{name:string,total:int}> $rows  Rows, largest first.
	 * @return void
	 */
	private static function rank_list( string $title, array $rows ): void {
		if ( ! $rows ) {
			return;
		}

		$max = max( array_column( $rows, 'total' ) );
		$max = $max > 0 ? $max : 1;
		?>
		<div class="kivun-cc-card kivun-rank">
			<h3 class="kivun-rank__title"><?php echo esc_html( $title ); ?></h3>
			<ul class="kivun-rank__list">
				<?php foreach ( $rows as $row ) : ?>
					<li class="kivun-rank__row">
						<span class="kivun-rank__name"><?php echo esc_html( $row['name'] ); ?></span>
						<span class="kivun-rank__bar" aria-hidden="true">
							<span class="kivun-rank__fill" style="width:<?php echo esc_attr( (string) round( ( $row['total'] / $max ) * 100 ) ); ?>%"></span>
						</span>
						<span class="kivun-rank__count"><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The campaign form, used both to create one and to edit one.
	 *
	 * One renderer rather than two: an edit form that drifts from the create
	 * form is how a field ends up saveable in one place and not the other.
	 *
	 * @param array<int,\WP_Post> $contents Content posts offered as targets.
	 * @param array<int,\WP_Post> $pages    Site pages offered as targets.
	 * @param object|null         $camp     The campaign being edited, or null.
	 * @return void
	 */
	private static function campaign_form( array $contents, array $pages, $camp = null ): void {
		$editing = null !== $camp;
		$target  = $editing ? (string) $camp->target_url : '';
		$options = array();

		foreach ( array_merge( $contents, $pages ) as $post ) {
			$options[] = (string) get_permalink( $post->ID );
		}
		// A destination that is not one of the listed pages — an external site,
		// or a page since unpublished — still has to be editable, so the form
		// opens on the free-text field with the value already in it.
		$custom = $editing && '' !== $target && ! in_array( $target, $options, true );
		?>
		<form class="kivun-campaign-form" <?php echo $editing ? 'data-id="' . esc_attr( (string) $camp->id ) . '"' : ''; ?>>
			<div class="kivun-form-grid">
				<div class="kivun-form-row">
					<label><?php esc_html_e( 'שם הקמפיין *', 'kivun' ); ?></label>
					<input type="text" class="kivun-cc-input kivun-camp-campaign" value="<?php echo $editing ? esc_attr( (string) $camp->label ) : ''; ?>" placeholder="<?php esc_attr_e( 'למשל: כנס הורים 2026', 'kivun' ); ?>">
					<p class="kivun-field-hint">
						<?php
						echo $editing
							? esc_html__( 'שם לתצוגה בלבד. שינוי שלו לא משפיע על הקישורים שכבר הופצו.', 'kivun' )
							: '<code>utm_campaign</code> — ' . esc_html__( 'עדיף באנגלית ובאותיות קטנות. רווחים יומרו למקפים.', 'kivun' );
						?>
					</p>
				</div>

				<?php if ( $editing ) : ?>
					<div class="kivun-form-row">
						<label><?php esc_html_e( 'מזהה הקמפיין', 'kivun' ); ?></label>
						<input type="text" class="kivun-cc-input kivun-camp-slug" dir="ltr" value="<?php echo esc_attr( (string) $camp->utm_campaign ); ?>">
						<p class="kivun-field-hint">
							<code>utm_campaign</code> —
							<?php esc_html_e( 'שינוי שלו בונה מחדש את כל הקישורים שבקמפיין. קישורים שכבר הופצו יפסיקו להיספר, כי הם עדיין נושאים את המזהה הישן.', 'kivun' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'יעד ברירת מחדל *', 'kivun' ); ?></label>
					<?php self::campaign_target_field( $contents, $pages, $target, $custom ); ?>
					<p class="kivun-field-hint"><?php esc_html_e( 'לאן יגיעו הגולשים. כל הקישורים בקמפיין מובילים לשם.', 'kivun' ); ?></p>
				</div>
			</div>

			<p class="kivun-error kivun-camp-error" style="display:none;color:var(--kivun-error)"></p>

			<div class="kivun-form-actions">
				<button type="submit" class="kivun-cc-btn <?php echo $editing ? 'kivun-cc-btn--sm' : ''; ?>">
					<?php echo $editing ? esc_html__( 'שמירת השינויים', 'kivun' ) : esc_html__( 'יצירת הקמפיין', 'kivun' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * The tracking-link form, used both to add a link and to edit one.
	 *
	 * @param object      $camp The campaign the link belongs to.
	 * @param object|null $link The link being edited, or null to add one.
	 * @return void
	 */
	private static function campaign_link_form( $camp, $link = null ): void {
		$editing = null !== $link;
		$target  = $editing ? (string) $link->target_url : (string) $camp->target_url;
		?>
		<form
			class="kivun-camplink-form"
			data-campaign="<?php echo esc_attr( (string) $camp->id ); ?>"
			data-campaign-slug="<?php echo esc_attr( (string) $camp->utm_campaign ); ?>"
			<?php echo $editing ? 'data-id="' . esc_attr( (string) $link->id ) . '"' : ''; ?>
		>
			<div class="kivun-form-grid">
				<div class="kivun-form-row">
					<label><?php esc_html_e( 'שם המפרסם *', 'kivun' ); ?></label>
					<input type="text" class="kivun-cc-input kivun-link-label" value="<?php echo $editing ? esc_attr( (string) $link->label ) : ''; ?>" placeholder="<?php esc_attr_e( 'למשל: דנה כהן', 'kivun' ); ?>">
					<p class="kivun-field-hint"><?php esc_html_e( 'לתצוגה בטבלה בלבד — לא נכנס לכתובת.', 'kivun' ); ?></p>
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'מקור *', 'kivun' ); ?></label>
					<input type="text" class="kivun-cc-input kivun-link-source" list="kivun-camp-sources" value="<?php echo $editing ? esc_attr( (string) $link->utm_source ) : ''; ?>" placeholder="<?php esc_attr_e( 'למשל: instagram', 'kivun' ); ?>">
					<p class="kivun-field-hint"><code>utm_source</code> — <?php esc_html_e( 'עדיף באנגלית ובאותיות קטנות.', 'kivun' ); ?></p>
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'מדיום', 'kivun' ); ?></label>
					<input type="text" class="kivun-cc-input kivun-link-medium" list="kivun-camp-mediums" value="<?php echo $editing ? esc_attr( (string) $link->utm_medium ) : ''; ?>" placeholder="<?php esc_attr_e( 'למשל: social', 'kivun' ); ?>">
					<p class="kivun-field-hint"><code>utm_medium</code></p>
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'מזהה פרסום', 'kivun' ); ?></label>
					<input type="text" class="kivun-cc-input kivun-link-content" value="<?php echo $editing ? esc_attr( (string) $link->utm_content ) : ''; ?>" placeholder="<?php esc_attr_e( 'למשל: story-1', 'kivun' ); ?>">
					<p class="kivun-field-hint"><code>utm_content</code> — <?php esc_html_e( 'נדרש רק כששני מפרסמים חולקים אותו מקור ומדיום.', 'kivun' ); ?></p>
				</div>
			</div>

			<?php if ( $editing ) : ?>
				<p class="kivun-field-hint">
					<?php esc_html_e( 'שינוי המקור, המדיום או מזהה הפרסום משנה את הכתובת. פניות שכבר הגיעו דרך הכתובת הישנה ייספרו לקמפיין אך לא לשורה הזו.', 'kivun' ); ?>
				</p>
			<?php endif; ?>

			<?php $kivun_edit_short = $editing ? Kivun_Short_Links::url_for( (string) ( $link->slug ?? '' ) ) : ''; ?>

			<label class="kivun-cc-sub"><?php esc_html_e( 'הקישור הקצר', 'kivun' ); ?></label>
			<?php if ( '' !== $kivun_edit_short ) : ?>
				<div class="kivun-camp-out">
					<input type="text" class="kivun-cc-input kivun-camp-short" dir="ltr" readonly value="<?php echo esc_url( $kivun_edit_short ); ?>">
					<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
				</div>
			<?php endif; ?>
			<?php
			// Drawn, never typed. The field carries the code to the save and
			// the alphabet it was drawn from — the server's own, so neither the
			// characters nor the length can drift from it — while the line below
			// is what anybody actually reads. One code, said once.
			?>
			<input
				type="hidden"
				class="kivun-short-slug"
				name="slug"
				value="<?php echo esc_attr( $editing ? (string) ( $link->slug ?? '' ) : '' ); ?>"
				data-short-base="<?php echo esc_attr( home_url( '/' . Kivun_Short_Links::BASE . '/' ) ); ?>"
				data-short-alphabet="<?php echo esc_attr( Kivun_Short_Links::ALPHABET ); ?>"
				data-short-length="<?php echo esc_attr( (string) Kivun_Short_Links::LENGTH ); ?>"
			>

			<?php if ( '' === $kivun_edit_short ) : ?>
				<?php
				// Shown as text, not as a field with a copy button beside it. The
				// short link is a pointer to a row that does not exist yet, so
				// until this is saved it leads nowhere — and an input with a copy
				// button next to it is an invitation to paste a dead link into a
				// message. The working one, with its button, appears once saved.
				?>
			<p class="kivun-short-preview" hidden>
				<span class="kivun-short-preview__label"><?php esc_html_e( 'הקישור הקצר שייווצר:', 'kivun' ); ?></span>
				<code class="kivun-short-preview__url" dir="ltr"></code>
				<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
				<span class="kivun-short-preview__note"><?php esc_html_e( 'יתחיל לעבוד אחרי השמירה', 'kivun' ); ?></span>
			</p>
			<?php endif; ?>

			<label class="kivun-cc-sub"><?php esc_html_e( 'הקישור המלא', 'kivun' ); ?></label>
			<p class="kivun-field-hint"><?php esc_html_e( 'זה מה שהקישור הקצר פותח, וזה מה שגוגל אנליטיקס רואה. אין צורך לשתף אותו.', 'kivun' ); ?></p>
			<div class="kivun-camp-out">
				<input type="text" class="kivun-cc-input kivun-camp-result" dir="ltr" readonly data-target="<?php echo esc_url( $target ); ?>" value="<?php echo $editing ? esc_url( (string) $link->final_url ) : ''; ?>" placeholder="<?php esc_attr_e( 'מלאו מקור…', 'kivun' ); ?>">
				<button type="button" class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-camp-copy"><?php esc_html_e( 'העתקה', 'kivun' ); ?></button>
			</div>

			<div class="kivun-cc-wa kivun-link-wa" <?php echo self::is_agency() ? 'hidden' : ''; ?>>
				<label class="kivun-cc-sub"><?php esc_html_e( 'הודעה לוואטסאפ למפרסם הזה', 'kivun' ); ?></label>
				<p class="kivun-field-hint"><?php esc_html_e( 'ההודעה נכתבת מהתוכן שביעד, ומשובץ בה הקישור המסומן של המפרסם הזה — כך שכל מפרסם מפיץ הודעה עם קישור משלו.', 'kivun' ); ?></p>

				<button
					type="button"
					class="kivun-cc-btn kivun-cc-btn--sm kivun-cc-btn--ghost kivun-link-wa-btn"
					data-campaign="<?php echo esc_attr( (string) $camp->id ); ?>"
				>
					<?php echo kivun_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'צור הודעה לוואטסאפ', 'kivun' ); ?>
				</button>
				<span class="kivun-cc-wa-status" role="status" aria-live="polite"></span>

				<textarea
					name="whatsapp"
					class="kivun-cc-input kivun-cc-textarea kivun-wa-text kivun-link-wa-text"
					rows="10"
					dir="rtl"
					placeholder="<?php esc_attr_e( 'אפשר גם לכתוב כאן בעצמכם. ההודעה תישמר יחד עם הקישור.', 'kivun' ); ?>"
				><?php echo $editing ? esc_textarea( (string) $link->whatsapp ) : ''; ?></textarea>
			</div>

			<p class="kivun-error kivun-camp-error" style="display:none;color:var(--kivun-error)"></p>

			<div class="kivun-form-actions">
				<button type="submit" class="kivun-cc-btn kivun-cc-btn--sm">
					<?php echo $editing ? esc_html__( 'שמירת השינויים', 'kivun' ) : esc_html__( 'הוספת הקישור', 'kivun' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Accept a Y-m-d date from a date input, or '' when it is not a real date.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function valid_date( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
		return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
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
	 * A filter's chosen ids, however many arrived.
	 *
	 * A single choice and several of them come through the same parameter —
	 * one as a scalar, the rest as an array — so both are read as a list and
	 * the rest of the code has one shape to deal with. Zeros go, because the
	 * "all" option carries one and a list holding it would otherwise filter
	 * to nothing.
	 *
	 * @param mixed $raw The parameter as it arrived.
	 * @return array<int,int>
	 */
	public static function filter_ids( $raw ): array {
		$list = array_map( 'absint', is_array( $raw ) ? $raw : array( $raw ) );

		return array_values( array_unique( array_filter( $list ) ) );
	}

	/**
	 * The same for a filter whose values are words rather than ids.
	 *
	 * @param mixed $raw The parameter as it arrived.
	 * @return array<int,string>
	 */
	public static function filter_values( $raw ): array {
		$list = array_map(
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			},
			is_array( $raw ) ? $raw : array( $raw )
		);

		return array_values(
			array_unique(
				array_filter(
					$list,
					static function ( $value ) {
						return '' !== trim( $value );
					}
				)
			)
		);
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

		// Two lists, because the filter and the rows are asking different
		// things. A row's control offers what a person may decide; the filter
		// also has to name the leads nobody has decided anything about, which
		// is not a status but a question about the absence of one.
		$statuses        = Kivun_Admin::reg_statuses();
		$filter_statuses = Kivun_Admin::filter_statuses();
		$type_labels     = Kivun_Admin::reg_type_labels();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing filters.
		// Several at once, because a question is usually about more than one
		// of them: the leads from two courses, or from three towns. Read as a
		// list even when one arrives, so the rest of the code has one shape
		// to handle. phpcs cannot see the per-value sanitising inside.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content_filter = self::filter_ids( wp_unslash( $_GET['kivun_content'] ?? array() ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value in filter_values().
		$type_filter = self::filter_values( wp_unslash( $_GET['kivun_ltype'] ?? array() ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value in filter_values().
		$status_filter = self::filter_values( wp_unslash( $_GET['kivun_lstatus'] ?? array() ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value in filter_values().
		$gender_filter = self::filter_values( wp_unslash( $_GET['kivun_lgender'] ?? array() ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per value in filter_values().
		$city_filter = self::filter_values( wp_unslash( $_GET['kivun_lcity'] ?? array() ) );
		$search      = isset( $_GET['kivun_ls'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_ls'] ) ) : '';
		$per_page    = isset( $_GET['kivun_lpp'] ) ? absint( wp_unslash( $_GET['kivun_lpp'] ) ) : 25;
		$paged       = isset( $_GET['kivun_lpaged'] ) ? max( 1, absint( wp_unslash( $_GET['kivun_lpaged'] ) ) ) : 1;
		$date_from   = isset( $_GET['kivun_lfrom'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_lfrom'] ) ) : '';
		$date_to     = isset( $_GET['kivun_lto'] ) ? sanitize_text_field( wp_unslash( $_GET['kivun_lto'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$date_from = self::valid_date( $date_from );
		$date_to   = self::valid_date( $date_to );

		// A backwards range returns nothing at all, which reads as a bug — swap.
		if ( '' !== $date_from && '' !== $date_to && $date_from > $date_to ) {
			list( $date_from, $date_to ) = array( $date_to, $date_from );
		}

		if ( ! in_array( $per_page, array( 25, 50, 100, 200 ), true ) ) {
			$per_page = 25;
		}

		// Worked out once and kept, because the export button below is built
		// from the same list. It used to carry the chosen contents as they
		// came, so the table counted a whole piece of content and the
		// spreadsheet held whatever had been filed against the single post
		// that was named. Reported from the live site: thirty-five enquiries
		// on screen, one in the file.
		$content_scope = self::content_scope( $content_filter );

		$conds = array();
		if ( $content_scope ) {
			$conds[] = 'r.course_id IN ( ' . implode( ', ', $content_scope ) . ' )';
		}
		// Only values this screen offers reach the query — a hand-edited URL
		// asking for a type that does not exist should narrow to nothing
		// rather than be passed through to the database.
		$chosen_types = array_values( array_intersect( $type_filter, array_keys( $type_labels ) ) );
		if ( $chosen_types ) {
			$marks = implode( ', ', array_fill( 0, count( $chosen_types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marks is one placeholder per value, built from the count.
			$conds[] = $wpdb->prepare( "r.type IN ( {$marks} )", $chosen_types );
		}

		// "לא טופלו" is one choice standing for the several words a lead is
		// captured with, so what reaches the query is the words.
		$chosen_statuses = Kivun_Admin::filter_status_values( $status_filter );
		if ( $chosen_statuses ) {
			$marks = implode( ', ', array_fill( 0, count( $chosen_statuses ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marks is one placeholder per value, built from the count.
			$conds[] = $wpdb->prepare( "r.status IN ( {$marks} )", $chosen_statuses );
		}
		// Matched on the words rather than on an exact string: the value is
		// whatever the form offered, and the forms do not agree with each
		// other. The same words the rota splits by, so the filter and the
		// split cannot disagree about who is who. Several groups are joined
		// with OR — "women and the ones with no answer" is a real question.
		$gender_parts = array();
		foreach ( $gender_filter as $group ) {
			if ( ! in_array( $group, array( 'male', 'female', 'none' ), true ) ) {
				continue;
			}
			$fragment = Kivun_Coordinators::gender_where( $group, 'r.gender' );
			if ( '' !== $fragment ) {
				$gender_parts[] = '( ' . $fragment . ' )';
			}
		}
		if ( $gender_parts ) {
			$conds[] = '( ' . implode( ' OR ', $gender_parts ) . ' )';
		}
		if ( $city_filter ) {
			// Built with one placeholder per value rather than by pasting the
			// list in: these are names somebody typed into a form once, and a
			// town with an apostrophe in it is not a reason to trust them.
			$marks = implode( ', ', array_fill( 0, count( $city_filter ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $marks is one placeholder per value, built from the count.
			$conds[] = $wpdb->prepare( "r.city IN ( {$marks} )", $city_filter );
		}
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// Source is searchable too, so a campaign link's lead count can hand
			// off to this table filtered to exactly that arrival label.
			$conds[] = $wpdb->prepare( '(r.name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s OR r.source LIKE %s)', $like, $like, $like, $like );
		}
		// Inclusive range: "to" covers the whole of that day.
		if ( '' !== $date_from ) {
			$conds[] = $wpdb->prepare( 'r.created_at >= %s', $date_from . ' 00:00:00' );
		}
		if ( '' !== $date_to ) {
			$conds[] = $wpdb->prepare( 'r.created_at <= %s', $date_to . ' 23:59:59' );
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
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		// One entry per piece of content. The group meta is read for each, so
		// the meta cache is left primed above rather than switched off.
		$content_list = self::content_filter_options( $contents );
		// A group is offered under one of its posts, so a chosen id is mapped
		// to the one the list actually shows — otherwise picking a landing
		// page would leave the box looking empty.
		$selected_content = array();
		foreach ( $content_filter as $chosen_content ) {
			$selected_content[] = (int) ( $content_list['rep'][ $chosen_content ] ?? $chosen_content );
		}

		// Only the settlements that actually occur, so the list is short and
		// nothing in it returns an empty table. Not filtered by the other
		// choices on purpose: a list that shrinks as you use it hides the
		// option you were reaching for.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$city_list = $wpdb->get_col(
			"SELECT DISTINCT city FROM {$wpdb->prefix}kivun_registrations
			 WHERE TRIM( city ) <> '' ORDER BY city ASC"
		);

		$can_delete_rows = current_user_can( 'manage_options' );
		$show_source     = self::can_see_lead_source();
		// Whether the column shows the whole UTM or only the medium.
		$show_full_source = self::can_see_full_lead_source();

		// Base args preserved across filtering and paging.
		$base_args = array( 'kivun_tab' => 'leads' );
		if ( $content_filter ) {
			$base_args['kivun_content'] = $content_filter;
		}
		if ( $type_filter ) {
			$base_args['kivun_ltype'] = $type_filter;
		}
		if ( $status_filter ) {
			$base_args['kivun_lstatus'] = $status_filter;
		}
		if ( $gender_filter ) {
			$base_args['kivun_lgender'] = $gender_filter;
		}
		if ( $city_filter ) {
			$base_args['kivun_lcity'] = $city_filter;
		}
		if ( '' !== $search ) {
			$base_args['kivun_ls'] = $search;
		}
		if ( '' !== $date_from ) {
			$base_args['kivun_lfrom'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$base_args['kivun_lto'] = $date_to;
		}
		$base_args['kivun_lpp'] = $per_page;

		$page_link = static function ( int $p ) use ( $base_args, $page_url ): string {
			return add_query_arg( array_merge( $base_args, array( 'kivun_lpaged' => $p ) ), $page_url );
		};

		/*
		 * The table's columns, in the system's order, each with the width the
		 * "לידים והרשמות" handoff gives it. A width with `true` beside it is a
		 * floor that shares the slack (`minmax(…, 1fr)`); the rest are fixed.
		 * One list, because the header, the column picker and the grid all
		 * read from it — and a cell drawn without a matching heading is the
		 * row slipping a column out of line.
		 *
		 * "סוג" is not a column of its own here: the handoff writes it under
		 * the content title, with its colour, so it is read with what it is
		 * the type of. It is still on every row.
		 */
		$lead_cols = array(
			'name'    => array( __( 'שם', 'kivun' ), 120, true, false ),
			'content' => array( __( 'תוכן', 'kivun' ), 160, true, true ),
		);
		if ( $show_source ) {
			$lead_cols['utm'] = array( __( 'מקור (UTM)', 'kivun' ), 150, false, true );
		}
		$lead_cols += array(
			'contact' => array( __( 'יצירת קשר', 'kivun' ), 180, false, true ),
			'city'    => array( __( 'עיר', 'kivun' ), 84, false, true ),
			'gender'  => array( __( 'מגדר', 'kivun' ), 64, false, true ),
			'handler' => array( __( 'רכז/ת מטפל/ת', 'kivun' ), 150, false, true ),
			'consent' => array( __( 'דיוור', 'kivun' ), 56, false, true ),
			'privacy' => array( __( 'פרטיות', 'kivun' ), 60, false, true ),
			'message' => array( __( 'פרטי הפנייה', 'kivun' ), 170, false, true ),
			'notes'   => array( __( 'הערות פנימיות', 'kivun' ), 190, false, true ),
			'date'    => array( __( 'תאריך', 'kivun' ), 92, false, true ),
			'status'  => array( __( 'סטטוס', 'kivun' ), 124, false, true ),
		);
		if ( $can_delete_rows ) {
			$lead_cols['delete'] = array( __( 'מחיקה', 'kivun' ), 52, false, false );
		}
		$lead_tracks = array();
		$lead_min    = 40 + 12 * ( count( $lead_cols ) - 1 );
		foreach ( $lead_cols as $lead_col ) {
			$lead_tracks[] = $lead_col[2] ? 'minmax(' . $lead_col[1] . 'px, 1fr)' : $lead_col[1] . 'px';
			$lead_min     += $lead_col[1];
		}

		$filtered = $content_filter || $type_filter || $status_filter || $gender_filter || $city_filter || '' !== $search || '' !== $date_from || '' !== $date_to;
		?>
		<div class="kivun-cc-front kivun-leads">
			<div class="kivun-leads-head">
				<div class="kivun-cc-head kivun-leads-intro">
					<h2 class="kivun-cc-title"><?php esc_html_e( 'לידים והרשמות', 'kivun' ); ?></h2>
					<p class="kivun-cc-lead"><?php esc_html_e( 'פניות שהתקבלו מהתכנים — קורסים, סדנאות, דפי נחיתה ואירועים — וכן פניות מטפסים כלליים באתר (צור קשר וכדו׳), המסומנות "טופס באתר".', 'kivun' ); ?></p>
					<?php
					// Only where that tab is actually on the screen. A leads
					// reader has one tab and no others to reach, so pointing
					// them at one is an answer they cannot act on.
					if ( Kivun_Employer::can_manage_all() && ! self::is_leads_only() ) :
						$kivun_jobs_tab = sprintf(
							'<a href="%s">%s</a>',
							esc_url( add_query_arg( 'kivun_tab', 'jobs', $page_url ) ),
							esc_html__( 'לוח משרות', 'kivun' )
						);
						?>
						<p class="kivun-cc-sep-note">
							<?php
							printf(
								/* translators: %s: a link whose text is the name of the jobs board tab. */
								esc_html__( 'הגשות מועמדים למשרות (עם קורות חיים) נמצאות בלשונית %s.', 'kivun' ),
								$kivun_jobs_tab // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from the escaped parts above.
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<div class="kivun-leads-actions">
					<?php if ( self::can_grant_leads_access() ) : ?>
						<?php $kivun_viewers = self::leads_viewers(); ?>
						<button type="button" class="kivun-leads-btn kivun-leads-accessbtn" aria-expanded="false" aria-controls="kivun-leads-access">
							<?php echo kivun_icon( 'l-adduser' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
							<?php esc_html_e( 'גישה לצפייה בלידים', 'kivun' ); ?>
							<span class="kivun-leads-count"><?php echo esc_html( number_format_i18n( count( $kivun_viewers ) ) ); ?></span>
						</button>
					<?php endif; ?>
					<?php
					// Not offered to whoever the export refuses. An agency reads
					// this table and does not take it away, so the handler turns
					// the request down — and a button whose every press ends at
					// "Unauthorized" reads as a fault rather than a boundary.
					//
					// The ids the table below was actually queried with, not
					// the ones the filter names: the same list, so the two
					// cannot disagree about what the filter means.
					if ( self::can_manage_leads() ) :
						?>
						<a class="kivun-leads-btn kivun-leads-btn--dark" href="<?php echo esc_url( Kivun_Export::url( 'registrations', $content_scope ) ); ?>">
							<?php echo kivun_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
							<?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( self::can_grant_leads_access() ) : ?>
				<div class="kivun-leads-access kivun-viewers" id="kivun-leads-access" hidden>
					<form class="kivun-viewer-form">
						<label class="kivun-leads-access__title" for="kivun-viewer-email"><?php esc_html_e( 'הוספת גישה לצפייה בלידים', 'kivun' ); ?></label>
						<input type="email" id="kivun-viewer-email" class="kivun-viewer-email" dir="ltr" autocomplete="off" placeholder="name@example.com">
						<input type="text" class="kivun-viewer-name" aria-label="<?php esc_attr_e( 'שם', 'kivun' ); ?>" placeholder="<?php esc_attr_e( 'שם (לא חובה)', 'kivun' ); ?>">
						<button type="submit" class="kivun-leads-access__add"><?php esc_html_e( 'הוספה', 'kivun' ); ?></button>
						<span class="kivun-leads-access__count">
							<?php
							$kivun_viewer_count = count( $kivun_viewers );
							/* translators: %s: how many people may read the leads. */
							echo esc_html( sprintf( _n( '%s משתמש עם גישה', '%s משתמשים עם גישה', $kivun_viewer_count, 'kivun' ), number_format_i18n( $kivun_viewer_count ) ) );
							?>
						</span>
						<p class="kivun-field-hint kivun-leads-access__hint">
							<?php esc_html_e( 'נוצר משתמש חדש ונשלח אליו מייל עם קישור ישיר לרשימת הלידים, ולצידו קישור לבחירת סיסמה. הוא יראה את טבלת הלידים בלבד ויוכל לעדכן סטטוס — בלי גישה לתוכן, לקמפיינים או לשאר האתר. אם כבר יש לו חשבון, תתווסף לו ההרשאה והוא יקבל מייל עם הקישור.', 'kivun' ); ?>
						</p>
						<?php // A div, not a paragraph: wpautop() reads an empty one as a gap to fill and leaves it unclosed. ?>
						<div class="kivun-error kivun-viewer-error" role="alert" style="display:none;color:var(--kivun-error)"></div>
						<span class="kivun-cc-wa-status kivun-viewer-status" role="status" aria-live="polite"></span>
					</form>

					<?php if ( $kivun_viewers ) : ?>
						<ul class="kivun-leads-viewers">
							<?php foreach ( $kivun_viewers as $kivun_viewer ) : ?>
								<li class="kivun-leads-viewer" data-viewer-row="<?php echo esc_attr( $kivun_viewer->ID ); ?>">
									<strong><?php echo esc_html( $kivun_viewer->display_name ); ?></strong>
									<span dir="ltr"><?php echo esc_html( $kivun_viewer->user_email ); ?></span>
									<button
										type="button"
										class="kivun-cc-iconbtn kivun-cc-iconbtn--danger kivun-remove-viewer"
										data-id="<?php echo esc_attr( $kivun_viewer->ID ); ?>"
										title="<?php esc_attr_e( 'הסרת הגישה', 'kivun' ); ?>"
									>
										<?php echo kivun_icon( 'l-trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
										<span class="kivun-sr-only"><?php esc_html_e( 'הסרת הגישה', 'kivun' ); ?></span>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="kivun-field-hint kivun-leads-access__hint"><?php esc_html_e( 'הסרה מבטלת את ההרשאה בלבד — חשבון המשתמש נשאר.', 'kivun' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form class="kivun-cc-leadfilters" method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="kivun_tab" value="leads">

				<div class="kivun-leads-frow">
					<div class="kivun-leads-search">
						<?php echo kivun_icon( 'l-search', 'kivun-leads-search__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
						<label class="kivun-sr-only" for="kivun-lf-search"><?php esc_html_e( 'חיפוש', 'kivun' ); ?></label>
						<input type="search" id="kivun-lf-search" name="kivun_ls" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'שם, אימייל, טלפון או מקור…', 'kivun' ); ?>">
					</div>
					<div class="kivun-cc-daterange">
						<label for="kivun-lf-from"><?php esc_html_e( 'מתאריך', 'kivun' ); ?></label>
						<input type="date" id="kivun-lf-from" name="kivun_lfrom" dir="ltr" value="<?php echo esc_attr( $date_from ); ?>">
						<label for="kivun-lf-to"><?php esc_html_e( 'עד', 'kivun' ); ?></label>
						<input type="date" id="kivun-lf-to" name="kivun_lto" dir="ltr" value="<?php echo esc_attr( $date_to ); ?>">
					</div>
				</div>

				<div class="kivun-leads-picks">
					<div class="kivun-leads-pick">
						<label class="kivun-sr-only" for="kivun-lf-content"><?php esc_html_e( 'סינון לפי תוכן', 'kivun' ); ?></label>
						<select id="kivun-lf-content" name="kivun_content[]" multiple class="kivun-pick-src" data-pick-label="סינון לפי תוכן" data-pick-empty="<?php esc_attr_e( 'אין התאמה', 'kivun' ); ?>" data-pick-all="<?php esc_attr_e( 'כל התכנים', 'kivun' ); ?>">
							<?php foreach ( $content_list['options'] as $c_id => $c_title ) : ?>
								<option value="<?php echo esc_attr( $c_id ); ?>" <?php echo in_array( (int) $c_id, $selected_content, true ) ? 'selected' : ''; ?>><?php echo esc_html( $c_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="kivun-leads-pick">
						<label class="kivun-sr-only" for="kivun-lf-type"><?php esc_html_e( 'סינון לפי סוג', 'kivun' ); ?></label>
						<select id="kivun-lf-type" name="kivun_ltype[]" multiple class="kivun-pick-src" data-pick-label="סינון לפי סוג" data-pick-empty="<?php esc_attr_e( 'אין התאמה', 'kivun' ); ?>" data-pick-all="<?php esc_attr_e( 'כל הסוגים', 'kivun' ); ?>">
							<?php foreach ( $type_labels as $tv => $tl ) : ?>
								<option value="<?php echo esc_attr( $tv ); ?>" <?php echo in_array( (string) $tv, $type_filter, true ) ? 'selected' : ''; ?>><?php echo esc_html( $tl ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="kivun-leads-pick">
						<label class="kivun-sr-only" for="kivun-lf-status"><?php esc_html_e( 'סינון לפי סטטוס', 'kivun' ); ?></label>
						<select id="kivun-lf-status" name="kivun_lstatus[]" multiple class="kivun-pick-src" data-pick-label="סינון לפי סטטוס" data-pick-empty="<?php esc_attr_e( 'אין התאמה', 'kivun' ); ?>" data-pick-all="<?php esc_attr_e( 'כל הסטטוסים', 'kivun' ); ?>">
							<?php foreach ( $filter_statuses as $sv => $sl ) : ?>
								<option value="<?php echo esc_attr( $sv ); ?>" <?php echo in_array( (string) $sv, $status_filter, true ) ? 'selected' : ''; ?>><?php echo esc_html( $sl ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="kivun-leads-pick">
						<label class="kivun-sr-only" for="kivun-lf-gender"><?php esc_html_e( 'סינון לפי מגדר', 'kivun' ); ?></label>
						<select id="kivun-lf-gender" name="kivun_lgender[]" multiple class="kivun-pick-src" data-pick-label="סינון לפי מגדר" data-pick-empty="<?php esc_attr_e( 'אין התאמה', 'kivun' ); ?>" data-pick-all="<?php esc_attr_e( 'כל המגדרים', 'kivun' ); ?>">
							<option value="male" <?php echo in_array( 'male', $gender_filter, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'גברים', 'kivun' ); ?></option>
							<option value="female" <?php echo in_array( 'female', $gender_filter, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'נשים', 'kivun' ); ?></option>
							<?php
							// Worth having its own entry: a lead with no gender is one
							// the rota could not place, so this lists exactly the ones
							// that never reached a coordinator.
							?>
							<option value="none" <?php echo in_array( 'none', $gender_filter, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'ללא מגדר', 'kivun' ); ?></option>
						</select>
					</div>
					<?php if ( $city_list ) : ?>
						<div class="kivun-leads-pick">
							<label class="kivun-sr-only" for="kivun-lf-city"><?php esc_html_e( 'סינון לפי עיר', 'kivun' ); ?></label>
							<select id="kivun-lf-city" name="kivun_lcity[]" multiple class="kivun-pick-src" data-pick-label="סינון לפי עיר" data-pick-empty="<?php esc_attr_e( 'אין התאמה', 'kivun' ); ?>" data-pick-all="<?php esc_attr_e( 'כל הערים', 'kivun' ); ?>">
								<?php foreach ( $city_list as $kivun_city ) : ?>
									<option value="<?php echo esc_attr( $kivun_city ); ?>" <?php echo in_array( (string) $kivun_city, $city_filter, true ) ? 'selected' : ''; ?>><?php echo esc_html( $kivun_city ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>
				</div>

				<div class="kivun-leads-frow kivun-leads-frow--foot">
					<?php
					/*
					 * What the filter is currently showing, inside the card that
					 * sets it, rather than in a bar above it. The freshness clock
					 * comes along because it qualifies the same number: a page
					 * served from a cache shows the moment the copy was taken,
					 * so if this time does not change on reload, the count cannot
					 * be trusted either.
					 */
					?>
					<div class="kivun-cc-filterbar__read">
						<span class="kivun-stat"><strong><?php echo esc_html( number_format_i18n( $found ) ); ?></strong> <?php esc_html_e( 'רשומות בתצוגה', 'kivun' ); ?></span>
						<span class="kivun-stat kivun-freshness" title="<?php esc_attr_e( 'אם השעה לא משתנה ברענון — הדף מוגש ממטמון והנתונים אינם מעודכנים.', 'kivun' ); ?>">
							<?php
							printf(
								/* translators: %s: time the page was generated. */
								esc_html__( 'עודכן %s', 'kivun' ),
								esc_html( wp_date( 'H:i:s' ) )
							);
							?>
						</span>
						<?php if ( $filtered ) : ?>
							<a class="kivun-leads-clear" href="<?php echo esc_url( add_query_arg( 'kivun_tab', 'leads', $page_url ) ); ?>"><?php esc_html_e( 'ניקוי סינון', 'kivun' ); ?></a>
						<?php endif; ?>
					</div>
					<div class="kivun-leads-apply">
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
						<button type="submit" class="kivun-leads-filterbtn"><?php echo kivun_icon( 'l-filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'סינון', 'kivun' ); ?></button>
					</div>
				</div>
			</form>

			<?php if ( ! $rows ) : ?>
				<div class="kivun-cc-note"><?php esc_html_e( 'לא נמצאו רשומות תואמות.', 'kivun' ); ?></div>
			<?php else : ?>
				<?php
				// How the table is shown is the reader's choice, not the screen's.
				// Cards are easier on a phone and heavy going at a desk, so neither
				// can be the answer for everybody. Left alone it is the table, which
				// scrolls sideways, and cards on a phone; once somebody chooses,
				// that choice is remembered.
				//
				// And the columns can be put away: most of the time three or four
				// of them are not being read.
				?>
				<div class="kivun-cc-viewbar" data-for="leads">
					<div class="kivun-cc-viewtoggle" role="group" aria-label="<?php esc_attr_e( 'צורת התצוגה', 'kivun' ); ?>">
						<?php
						foreach ( array(
							'table' => __( 'טבלה', 'kivun' ),
							'cards' => __( 'כרטיסים', 'kivun' ),
						) as $kivun_view => $kivun_view_label ) :
							?>
							<button type="button" class="kivun-cc-viewbtn" data-view="<?php echo esc_attr( $kivun_view ); ?>" aria-pressed="false">
								<?php echo kivun_icon( 'l-' . $kivun_view, 'kivun-cc-viewbtn__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
								<?php echo esc_html( $kivun_view_label ); ?>
							</button>
						<?php endforeach; ?>
					</div>

					<details class="kivun-cc-colpicker">
						<summary class="kivun-leads-btn kivun-leads-colbtn"><?php echo kivun_icon( 'l-columns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'עמודות', 'kivun' ); ?></summary>
						<div class="kivun-cc-colpicker__menu">
							<?php
							// A column withheld from this reader is not offered back
							// to them here, and the name stays: a row nobody can
							// tell apart from the next is no row at all.
							foreach ( $lead_cols as $kivun_col => $lead_col ) :
								if ( ! $lead_col[3] ) {
									continue;
								}
								?>
								<label class="kivun-cc-colpicker__row">
									<input type="checkbox" data-col-toggle="<?php echo esc_attr( $kivun_col ); ?>" checked>
									<span class="kivun-cc-colpicker__box" aria-hidden="true"><?php echo kivun_icon( 'l-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?></span>
									<?php echo esc_html( $lead_col[0] ); ?>
								</label>
							<?php endforeach; ?>
							<button type="button" class="kivun-cc-colpicker__reset"><?php esc_html_e( 'איפוס', 'kivun' ); ?></button>
						</div>
					</details>
				</div>

				<div class="kivun-cc-tablewrap kivun-cc-tablewrap--stacks">
				<table class="kivun-cc-table kivun-cc-leadtable" style="<?php echo esc_attr( '--lead-cols: ' . implode( ' ', $lead_tracks ) . '; --lead-min: ' . $lead_min . 'px;' ); ?>">
					<thead>
						<tr>
							<?php foreach ( $lead_cols as $kivun_col => $lead_col ) : ?>
								<th scope="col" data-col="<?php echo esc_attr( $kivun_col ); ?>" data-w="<?php echo esc_attr( $lead_col[1] . ( $lead_col[2] ? 'fr' : '' ) ); ?>"<?php echo 'delete' === $kivun_col ? ' class="kivun-lead__delhead"' : ''; ?>><?php echo esc_html( $lead_col[0] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						// A lead with no post was never filed against one — the jobs
						// board is an archive, so it is named instead. Only a lead
						// that had a post and lost it is deleted.
						if ( $r->course_title ) {
							$row_title = (string) $r->course_title;
						} elseif ( $r->course_id ) {
							$row_title = __( '(נמחק)', 'kivun' );
						} else {
							$row_title = $type_labels[ $r->type ?? '' ] ?? __( 'לוח משרות', 'kivun' );
						}
						$row_link    = $r->course_id ? (string) get_permalink( (int) $r->course_id ) : '';
						$row_type    = (string) ( $r->type ?? 'registration' );
						$src         = self::lead_source( $r );
						$row_answers = self::lead_answers( (string) ( $r->message ?? '' ) );
						$row_more    = 'kivun-lead-more-' . (int) $r->id;
						$row_utm     = self::lead_utm_line( $src, $show_full_source );
						$row_city    = trim( (string) ( $r->city ?? '' ) );
						$row_gender  = trim( (string) ( $r->gender ?? '' ) );
						?>
						<tr class="kivun-lead">
							<td data-label="<?php esc_attr_e( 'שם', 'kivun' ); ?>" data-col="name"><strong><?php echo esc_html( $r->name ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'תוכן', 'kivun' ); ?>" data-col="content">
								<?php if ( $row_link ) : ?>
									<a class="kivun-lead__title" href="<?php echo esc_url( $row_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row_title ); ?></a>
								<?php else : ?>
									<span class="kivun-lead__title"><?php echo esc_html( $row_title ); ?></span>
								<?php endif; ?>
								<span class="kivun-lead__type" data-type="<?php echo esc_attr( $row_type ); ?>"><?php echo esc_html( $type_labels[ $row_type ] ?? $row_type ); ?></span>
								<?php if ( '' !== $src['origin'] ) : ?>
									<span class="kivun-cc-source"><?php echo esc_html( $src['origin'] ); ?></span>
								<?php endif; ?>
								<?php
								// A finished Holland questionnaire: the representative
								// prints the result from here — the participant was not
								// shown it. Under the name, so it is in view without
								// scrolling the table sideways.
								?>
								<?php if ( Kivun_Holland::has_report( $r ) && self::can_manage_leads() ) : ?>
									<a class="kivun-lead__report" href="<?php echo esc_url( Kivun_Holland::report_url( (int) $r->id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'דוח להדפסה', 'kivun' ); ?></a>
								<?php endif; ?>
							</td>
							<?php if ( $show_source ) : ?>
							<td class="kivun-cc-utm" data-label="<?php esc_attr_e( 'מקור (UTM)', 'kivun' ); ?>" data-col="utm">
								<?php if ( '' !== $src['source'] ) : ?>
									<span class="kivun-lead__src" data-src="<?php echo esc_attr( self::lead_source_tone( $src['source'] ) ); ?>"><?php echo esc_html( $src['source'] ); ?></span>
									<?php
									/*
									 * Everything past the medium is the advertising
									 * ledger — which campaign, which publisher — and
									 * it is kept for the managers and the agency.
									 * A coordinator working the lead is shown where
									 * it came in from and nothing further.
									 */
									?>
									<?php if ( '' !== $row_utm ) : ?>
										<span class="kivun-cc-source"><?php echo esc_html( $row_utm ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="kivun-muted" aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
							<?php endif; ?>
							<td class="kivun-app-contact" data-label="<?php esc_attr_e( 'יצירת קשר', 'kivun' ); ?>" data-col="contact">
								<?php if ( $r->email ) : ?>
									<a href="mailto:<?php echo esc_attr( $r->email ); ?>" dir="ltr"><?php echo esc_html( $r->email ); ?></a>
								<?php endif; ?>
								<?php if ( $r->phone ) : ?>
									<a class="kivun-lead__phone" href="tel:<?php echo esc_attr( $r->phone ); ?>" dir="ltr"><?php echo esc_html( $r->phone ); ?></a>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'עיר', 'kivun' ); ?>" data-col="city"><?php echo '' !== $row_city ? esc_html( $row_city ) : '<span class="kivun-muted">—</span>'; ?></td>
							<td data-label="<?php esc_attr_e( 'מגדר', 'kivun' ); ?>" data-col="gender"><?php echo '' !== $row_gender ? esc_html( $row_gender ) : '<span class="kivun-muted">—</span>'; ?></td>
							<td data-label="<?php esc_attr_e( 'רכז/ת מטפל/ת', 'kivun' ); ?>" data-col="handler">
								<?php
								// A control rather than a word. The rota places
								// most leads, but one that arrived with no
								// gender on it is one it cannot place, and it
								// was landing here with nobody against it and
								// no way to put somebody there.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo Kivun_Admin::handler_select( (int) $r->id, (string) ( $r->coordinator ?? '' ) );
								?>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
								<?php if ( '' === trim( (string) ( $r->coordinator ?? '' ) ) && '' === $row_gender ) : ?>
									<?php
									// Why this one is empty. A lead the rota was
									// never asked about is not the same as one it
									// was asked about and could not place, and
									// only the second is a fault to go and fix —
									// the note says which this is, and the field
									// above it is how it gets fixed.
									?>
									<span class="kivun-muted kivun-handler-why"><?php esc_html_e( 'הגיע ללא מגדר', 'kivun' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'דיוור', 'kivun' ); ?>" data-col="consent"><?php echo self::lead_tick( ! empty( $r->marketing_consent ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></td>
							<td data-label="<?php esc_attr_e( 'פרטיות', 'kivun' ); ?>" data-col="privacy"><?php echo self::lead_tick( ! empty( $r->privacy_consent ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></td>
							<td data-label="<?php esc_attr_e( 'פרטי הפנייה', 'kivun' ); ?>" data-col="message">
								<button type="button" class="kivun-lead__more" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_more ); ?>"><?php echo kivun_icon( 'l-chev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?><?php esc_html_e( 'פרטי הפנייה', 'kivun' ); ?></button>
								<?php if ( $row_answers ) : ?>
									<?php foreach ( $row_answers as $row_answer ) : ?>
										<span class="kivun-lead__answer" dir="auto"><?php echo esc_html( ( '' !== $row_answer[0] ? $row_answer[0] . ': ' : '' ) . $row_answer[1] ); ?></span>
									<?php endforeach; ?>
								<?php else : ?>
									<span class="kivun-lead__answer kivun-muted">—</span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'הערות פנימיות', 'kivun' ); ?>" data-col="notes">
								<?php
								// Working a lead is writing down what came of it,
								// so everyone who can see the table can keep a
								// note on it — the same reasoning as the status.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo Kivun_Admin::notes_input( 'registrations', (int) $r->id, (string) ( $r->notes ?? '' ) );
								?>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
							</td>
							<td class="kivun-cc-date" data-label="<?php esc_attr_e( 'תאריך', 'kivun' ); ?>" data-col="date"><span><?php echo esc_html( kivun_local_date( (string) $r->created_at, 'd/m/Y' ) ); ?></span> <span class="kivun-lead__time"><?php echo esc_html( kivun_local_date( (string) $r->created_at, 'H:i' ) ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'סטטוס', 'kivun' ); ?>" data-col="status">
								<?php
								// Everyone who can see the leads can move one along;
								// tracking progress is the point of the table. A
								// lead nobody has reached reads "חדש", as the
								// handoff names it, rather than an empty pill.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns pre-escaped HTML.
								echo Kivun_Admin::status_select( 'registrations', (int) $r->id, (string) $r->status, $statuses, __( 'חדש', 'kivun' ) );
								?>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
							</td>
							<?php if ( $can_delete_rows ) : ?>
								<td data-label="<?php esc_attr_e( 'מחיקה', 'kivun' ); ?>" data-col="delete">
									<?php
									// Asked in the row, and asked once: the mark opens
									// "מחיקה / ביטול" in its place, and "מחיקה" is the
									// button the delete handler has always bound to.
									?>
									<button type="button" class="kivun-lead__ask" aria-expanded="false" aria-label="<?php esc_attr_e( 'מחיקת הרשומה', 'kivun' ); ?>" title="<?php esc_attr_e( 'מחיקת הרשומה', 'kivun' ); ?>">
										<?php echo kivun_icon( 'l-trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, escaped SVG. ?>
									</button>
									<div class="kivun-lead__confirm" hidden>
										<button type="button" class="kivun-delete-row kivun-lead__yes" data-table="registrations" data-id="<?php echo esc_attr( $r->id ); ?>"><?php esc_html_e( 'מחיקה', 'kivun' ); ?></button>
										<button type="button" class="kivun-lead__no"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
									</div>
								</td>
							<?php endif; ?>
						</tr>
						<tr class="kivun-lead-more" id="<?php echo esc_attr( $row_more ); ?>" hidden>
							<td colspan="<?php echo esc_attr( (string) count( $lead_cols ) ); ?>">
								<dl class="kivun-lead-more__card">
									<?php
									$row_facts = array(
										array( __( 'שם', 'kivun' ), (string) $r->name ),
										array( __( 'טלפון', 'kivun' ), (string) $r->phone ),
										array( __( 'אימייל', 'kivun' ), (string) $r->email ),
									);
									if ( $show_source ) {
										$row_facts[] = array( __( 'מקור (UTM)', 'kivun' ), implode( ' · ', array_filter( array( $src['source'], $row_utm ), 'strlen' ) ) );
									}
									foreach ( array_merge( $row_facts, $row_answers ) as $row_fact ) :
										?>
										<div class="kivun-lead-more__item">
											<dt><?php echo esc_html( '' !== $row_fact[0] ? $row_fact[0] : __( 'הודעה', 'kivun' ) ); ?></dt>
											<dd dir="auto">
												<?php
												// One block per line rather than a break tag: the
												// shortcode's output is flattened onto one
												// line, and the screen hides wpautop()'s breaks.
												$row_lines = array_filter( array_map( 'trim', explode( "\n", $row_fact[1] ) ), 'strlen' );
												if ( $row_lines ) :
													foreach ( $row_lines as $row_line ) :
														?>
														<span class="kivun-lead-more__line"><?php echo esc_html( $row_line ); ?></span>
														<?php
													endforeach;
												else :
													echo '—';
												endif;
												?>
											</dd>
										</div>
									<?php endforeach; ?>
								</dl>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="kivun-leads-hint"><?php esc_html_e( 'אפשר לגלול את הטבלה הצידה · עמודות אפשר להסתיר בכפתור "עמודות"', 'kivun' ); ?></p>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="kivun-cc-pager">
						<?php if ( $paged > 1 ) : ?>
							<a class="kivun-leads-page" href="<?php echo esc_url( $page_link( $paged - 1 ) ); ?>">‹ <?php esc_html_e( 'הקודם', 'kivun' ); ?></a>
						<?php else : ?>
							<span class="kivun-leads-page is-off" aria-disabled="true">‹ <?php esc_html_e( 'הקודם', 'kivun' ); ?></span>
						<?php endif; ?>
						<span class="kivun-cc-pager__state">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages. */
								esc_html__( 'עמוד %1$s מתוך %2$s', 'kivun' ),
								'<strong>' . esc_html( number_format_i18n( $paged ) ) . '</strong>',
								esc_html( number_format_i18n( $total_pages ) )
							);
							?>
						</span>
						<?php if ( $paged < $total_pages ) : ?>
							<a class="kivun-leads-page kivun-leads-page--next" href="<?php echo esc_url( $page_link( $paged + 1 ) ); ?>"><?php esc_html_e( 'הבא', 'kivun' ); ?> ›</a>
						<?php else : ?>
							<span class="kivun-leads-page kivun-leads-page--next is-off" aria-disabled="true"><?php esc_html_e( 'הבא', 'kivun' ); ?> ›</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A lead's form answers, one [label, value] pair per line.
	 *
	 * The message is stored as the visitor's own words, if any, followed by a
	 * "label: value" line for every other field the form asked. Lines that are
	 * not in that shape are the free text, and come back as one entry with no
	 * label.
	 *
	 * @param string $message The stored message.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function lead_answers( string $message ): array {
		$pairs = array();
		$free  = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $message ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^([^:]{1,60}):\s(.*)$/u', $line, $m ) ) {
				$pairs[] = array( trim( $m[1] ), trim( $m[2] ) );
			} elseif ( $pairs ) {
				// A line after an answer is more of that answer: a textarea
				// on the form keeps its own line breaks in the stored text.
				$last               = count( $pairs ) - 1;
				$pairs[ $last ][1] .= "\n" . $line;
			} else {
				$free[] = $line;
			}
		}

		if ( $free ) {
			array_unshift( $pairs, array( '', implode( "\n", $free ) ) );
		}

		return $pairs;
	}

	/**
	 * The line under a lead's source: what else the UTM says, for whoever may
	 * read it.
	 *
	 * Everything past the medium is the advertising ledger, so it is only
	 * spelled out for the managers and the agency.
	 *
	 * @param array<string,string> $src              The lead's source, from lead_source().
	 * @param bool                 $show_full_source Whether the reader may see the whole UTM.
	 * @return string
	 */
	private static function lead_utm_line( array $src, bool $show_full_source ): string {
		if ( ! $show_full_source ) {
			return '';
		}

		$campaign = implode( ' · ', array_filter( array( (string) ( $src['campaign'] ?? '' ), (string) ( $src['content'] ?? '' ) ), 'strlen' ) );

		return implode( ' · ', array_filter( array( $campaign, (string) ( $src['medium'] ?? '' ) ), 'strlen' ) );
	}

	/**
	 * Which colour a source's dot takes.
	 *
	 * @param string $source A lead's utm_source.
	 * @return string One of whatsapp, facebook, google, other.
	 */
	public static function lead_source_tone( string $source ): string {
		$source = strtolower( trim( $source ) );
		if ( 'wa' === $source || false !== strpos( $source, 'whatsapp' ) ) {
			return 'whatsapp';
		}
		if ( in_array( $source, array( 'fb', 'ig', 'meta' ), true ) || false !== strpos( $source, 'facebook' ) || false !== strpos( $source, 'instagram' ) ) {
			return 'facebook';
		}
		if ( false !== strpos( $source, 'google' ) || false !== strpos( $source, 'youtube' ) ) {
			return 'google';
		}
		return 'other';
	}

	/**
	 * A consent cell: a ticked sky disc, or a dash.
	 *
	 * @param bool $given Whether the visitor agreed.
	 * @return string
	 */
	private static function lead_tick( bool $given ): string {
		return $given
			? '<span class="kivun-lead__tick" role="img" aria-label="' . esc_attr__( 'כן', 'kivun' ) . '">' . kivun_icon( 'l-check' ) . '</span>'
			: '<span class="kivun-muted" aria-label="' . esc_attr__( 'לא', 'kivun' ) . '">—</span>';
	}
}
