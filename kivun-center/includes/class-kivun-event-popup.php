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
		// Rendered fully self-contained in the footer (inline CSS + JS) so it works
		// site-wide for every visitor — including logged-out users on cached pages
		// where the conditionally-loaded plugin assets may be stripped.
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
	 * Output the self-contained popup (inline styles + behaviour) in the footer.
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

		$accent = (string) apply_filters( 'kivun_event_popup_accent', '#de2952' );
		?>
		<style id="kivun-epop-css">
		.kivun-epop{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px}
		.kivun-epop[hidden]{display:none}
		.kivun-epop__overlay{position:absolute;inset:0;background:rgba(15,18,26,.68);opacity:0;transition:opacity .25s ease}
		.kivun-epop.is-open .kivun-epop__overlay{opacity:1}
		.kivun-epop__box{position:relative;z-index:1;width:100%;max-width:360px;max-height:90vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.35);transform:translateY(16px) scale(.98);opacity:0;transition:transform .28s ease,opacity .28s ease}
		.kivun-epop.is-open .kivun-epop__box{transform:none;opacity:1}
		.kivun-epop__x{position:absolute;inset-inline-end:8px;inset-block-start:8px;z-index:2;width:34px;height:34px;border:0;border-radius:50%;cursor:pointer;background:rgba(0,0,0,.5);color:#fff;font-size:22px;line-height:1;display:flex;align-items:center;justify-content:center}
		.kivun-epop__x:hover{background:rgba(0,0,0,.72)}
		.kivun-epop__media{display:block}
		.kivun-epop__media img{display:block;width:100%;height:auto;border-radius:16px 16px 0 0}
		.kivun-epop__cta{padding:14px 16px 18px;text-align:center}
		.kivun-epop__btn{display:inline-flex;align-items:center;justify-content:center;width:100%;background:<?php echo esc_html( $accent ); ?>;color:#fff;text-decoration:none;font-weight:700;padding:.8rem 1.2rem;border-radius:999px;font-size:1.02rem}
		.kivun-epop__btn:hover{filter:brightness(.94);color:#fff}
		</style>
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
		<script id="kivun-epop-js">
		(function(){
			var pop=document.getElementById('kivun-epop');
			if(!pop){return;}
			var key='kivunEpop_'+(pop.getAttribute('data-event')||'');
			try{if(window.sessionStorage&&sessionStorage.getItem(key)){return;}}catch(e){}
			function seen(){try{if(window.sessionStorage){sessionStorage.setItem(key,'1');}}catch(e){}}
			function close(){pop.hidden=true;pop.classList.remove('is-open');seen();}
			function open(){pop.hidden=false;void pop.offsetWidth;pop.classList.add('is-open');seen();}
			var closers=pop.querySelectorAll('[data-epop-close]');
			for(var i=0;i<closers.length;i++){closers[i].addEventListener('click',function(e){e.preventDefault();close();});}
			document.addEventListener('keydown',function(e){if((e.key==='Escape'||e.keyCode===27)&&pop.classList.contains('is-open')){close();}});
			window.setTimeout(open,900);
		}());
		</script>
		<?php
	}
}
