<?php
/**
 * Site-wide event popup.
 *
 * When an event is marked "show popup" and its date has not yet passed, a
 * modal with the event's (tall/portrait) image and a link to the event page is
 * shown to visitors across the whole site until the event takes place.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the active event popup in the site footer.
 */
class Kivun_Event_Popup {

	/**
	 * Register the front-end hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * Resolve the single active popup event: a published event with the popup
	 * enabled whose registration is still open, preferring the nearest date.
	 *
	 * @return int Event post ID, or 0 when none is active.
	 */
	private static function active_event(): int {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = 0;

		$ids = get_posts(
			array(
				'post_type'      => 'kivun_event',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'no_found_rows'  => true,
				'meta_key'       => '_kivun_event_popup', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$best_date = null;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! kivun_event_registration_open( $id ) ) {
				continue;
			}
			$date = (string) get_post_meta( $id, '_kivun_event_date', true );
			// Prefer the nearest upcoming dated event; an undated event is a
			// last resort (empty date sorts after any real date).
			$key = '' === $date ? '9999-12-31' : $date;
			if ( null === $best_date || $key < $best_date ) {
				$best_date = $key;
				$cached    = $id;
			}
		}

		return $cached;
	}

	/**
	 * The popup image URL — the dedicated portrait image, falling back to the
	 * event's featured image.
	 *
	 * @param int $event_id The event post ID.
	 * @return string
	 */
	private static function image_url( int $event_id ): string {
		$img_id = (int) get_post_meta( $event_id, '_kivun_event_image', true );
		if ( $img_id ) {
			$url = (string) wp_get_attachment_image_url( $img_id, 'large' );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return (string) get_the_post_thumbnail_url( $event_id, 'large' );
	}

	/**
	 * Load the front-end assets when a popup is active (they carry the styling
	 * and behaviour).
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( self::active_event() ) {
			Kivun_Core::enqueue_frontend_assets();
		}
	}

	/**
	 * Output the popup markup (hidden until the script reveals it).
	 *
	 * @return void
	 */
	public static function render(): void {
		$event_id = self::active_event();
		if ( ! $event_id ) {
			return;
		}

		$image = self::image_url( $event_id );
		if ( '' === $image ) {
			return;
		}

		$link   = (string) get_permalink( $event_id );
		$title  = (string) get_the_title( $event_id );
		$button = (string) get_post_meta( $event_id, '_kivun_event_button', true );
		if ( '' === trim( $button ) ) {
			$button = __( 'למעבר לעמוד האירוע', 'kivun' );
		}
		?>
		<div class="kivun-epop" id="kivun-epop" data-event="<?php echo esc_attr( $event_id ); ?>" hidden>
			<div class="kivun-epop__overlay" data-epop-close></div>
			<div class="kivun-epop__box" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $title ); ?>" dir="rtl">
				<button type="button" class="kivun-epop__x" data-epop-close aria-label="<?php esc_attr_e( 'סגירה', 'kivun' ); ?>">&times;</button>
				<a class="kivun-epop__media" href="<?php echo esc_url( $link ); ?>">
					<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
				</a>
				<div class="kivun-epop__cta">
					<a class="kivun-epop__btn" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $button ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}
}
