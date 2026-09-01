<?php
/**
 * Global "thank you" page.
 *
 * A branded thank-you screen (site fonts/colors) shown after any submission.
 * One setting redirects EVERY Elementor Pro form to it after submit — no need to
 * edit each form. Forms with their own redirect (e.g. paid-course checkout) keep
 * it, because their action runs after this hook and overrides the response.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the thank-you page and drives the global Elementor redirect.
 */
class Kivun_Thank_You {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'kivun_thank_you', array( __CLASS__, 'shortcode' ) );
		// Client-side fallback: redirect to the thank-you page ONLY when the form's
		// server response set no redirect of its own (so a paid course → checkout,
		// or a form's own "Redirect" action, always wins).
		add_action( 'wp_footer', array( __CLASS__, 'redirect_script' ) );
		add_action( 'admin_post_kivun_create_thankyou', array( __CLASS__, 'create_page' ) );

		// The plugin's own forms answer over AJAX and never leave the page, so
		// they get a dialog rather than the redirect above.
		add_action( 'wp_footer', array( __CLASS__, 'render_popup' ) );
	}

	/**
	 * Whether the confirmation dialog should be printed on this request.
	 *
	 * @return bool
	 */
	public static function popup_enabled(): bool {
		if ( is_admin() ) {
			return false;
		}

		return (bool) apply_filters( 'kivun_thankyou_popup', (bool) Kivun_Admin_Settings::get( 'thankyou_popup', true ) );
	}

	/**
	 * Print the confirmation dialog, hidden, for the front-end script to open.
	 *
	 * The plugin's forms submit over AJAX and stay on the page, so the visitor
	 * previously saw only the form being replaced by a line of text — easy to
	 * miss, and it left them on a landing page with nowhere to go next. The
	 * dialog says the message plainly and offers the way onward.
	 *
	 * @return void
	 */
	public static function render_popup(): void {
		if ( ! self::popup_enabled() ) {
			return;
		}

		$title   = (string) Kivun_Admin_Settings::get( 'thankyou_title', '' );
		$message = (string) Kivun_Admin_Settings::get( 'thankyou_message', '' );
		$label   = (string) Kivun_Admin_Settings::get( 'thankyou_btn_label', '' );
		$url     = (string) Kivun_Admin_Settings::get( 'thankyou_btn_url', '' );

		$title   = '' !== trim( $title ) ? $title : __( 'תודה רבה!', 'kivun' );
		$message = '' !== trim( $message ) ? $message : __( 'פנייתך התקבלה בהצלחה. ניצור איתך קשר בהקדם.', 'kivun' );
		$label   = '' !== trim( $label ) ? $label : __( 'לאתר מרכז כיוון', 'kivun' );
		// An unset destination still has to lead somewhere, so it falls back to
		// this site's own home page rather than rendering a button that does
		// nothing when pressed.
		$url = '' !== trim( $url ) ? $url : home_url( '/' );

		kivun_get_template(
			'thank-you/popup.php',
			array(
				'title'   => $title,
				'message' => $message,
				'label'   => $label,
				'url'     => $url,
			)
		);
	}

	/**
	 * Resolve the thank-you page URL from settings.
	 *
	 * @return string The permalink, or '' when not configured.
	 */
	public static function url(): string {
		$page_id = (int) Kivun_Admin_Settings::get( 'thankyou_page_id', 0 );
		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * Output a small script that redirects every Elementor form submission to the
	 * thank-you page — but only when the server response carried no redirect_url,
	 * so paid-course checkout and any form's own "Redirect" action are respected.
	 *
	 * @return void
	 */
	public static function redirect_script(): void {
		if ( is_admin() || ! (bool) Kivun_Admin_Settings::get( 'thankyou_elementor', false ) ) {
			return;
		}
		$url = self::url();
		if ( '' === $url ) {
			return;
		}
		?>
		<script>
		( function () {
			if ( ! window.jQuery ) { return; }
			var kivunThankYou = <?php echo wp_json_encode( $url ); ?>;
			jQuery( document ).ajaxComplete( function ( event, xhr, settings ) {
				try {
					if ( ! settings || ! settings.data || String( settings.data ).indexOf( 'action=elementor_pro_forms_send_form' ) === -1 ) { return; }
					var res = xhr.responseJSON || JSON.parse( xhr.responseText );
					if ( res && res.success ) {
						var d = res.data || {};
						var redirect = d.redirect_url || ( d.data && d.data.redirect_url );
						if ( ! redirect ) { window.location.href = kivunThankYou; }
					}
				} catch ( e ) {}
			} );
		} () );
		</script>
		<?php
	}

	/**
	 * Render the branded thank-you content.
	 *
	 * @param mixed $atts Shortcode attributes (unused).
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );

		Kivun_Core::enqueue_frontend_assets();

		$title   = (string) Kivun_Admin_Settings::get( 'thankyou_title', '' );
		$message = (string) Kivun_Admin_Settings::get( 'thankyou_message', '' );
		if ( '' === trim( $title ) ) {
			$title = __( 'תודה רבה!', 'kivun' );
		}
		if ( '' === trim( $message ) ) {
			$message = __( 'פנייתך התקבלה בהצלחה. ניצור איתך קשר בהקדם.', 'kivun' );
		}

		ob_start();
		?>
		<div class="kivun-thankyou" dir="rtl">
			<span class="kivun-thankyou__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="40" height="40" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.1 14.3-4-4 1.4-1.4 2.6 2.6 5.4-5.4 1.4 1.4-6.8 6.8Z"/></svg>
			</span>
			<h1 class="kivun-thankyou__title"><?php echo esc_html( $title ); ?></h1>
			<p class="kivun-thankyou__msg"><?php echo wp_kses_post( wpautop( $message ) ); ?></p>
			<a class="kivun-thankyou__btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'חזרה לעמוד הבית', 'kivun' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Create (or reuse) a "תודה" page with the shortcode, select it in settings,
	 * and turn on the global Elementor redirect.
	 *
	 * @return void
	 */
	public static function create_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'kivun_create_thankyou' );

		$existing = (int) Kivun_Admin_Settings::get( 'thankyou_page_id', 0 );
		if ( $existing && get_post( $existing ) ) {
			$page_id = $existing;
		} else {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => __( 'תודה', 'kivun' ),
					'post_status'  => 'publish',
					'post_content' => '[kivun_thank_you]',
				)
			);
		}

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$opts                       = get_option( 'kivun_settings', array() );
			$opts                       = is_array( $opts ) ? $opts : array();
			$opts['thankyou_page_id']   = (int) $page_id;
			$opts['thankyou_elementor'] = true;
			update_option( 'kivun_settings', $opts );
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Internal admin URL redirect.
		wp_redirect( admin_url( 'admin.php?page=kivun-settings&thankyou_created=1' ) );
		exit;
	}
}
