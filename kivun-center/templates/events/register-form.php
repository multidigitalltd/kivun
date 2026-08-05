<?php
/**
 * Template: event registration form (clean 2-column, underline inputs).
 *
 * Uses the shared `.kivun-lead-form` JS handler (posts to kivun_submit_lead),
 * so submissions are saved to the leads table with source + UTM.
 *
 * @var int    $post_id   The event post ID.
 * @var string $post_type The post type (kivun_event).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_policy = (string) Kivun_Admin_Settings::get( 'cookie_policy_url', '' );
?>
<div class="kivun-ef" id="kivun-ef-<?php echo esc_attr( $post_id ); ?>" dir="rtl">
	<form class="kivun-lead-form kivun-ef__form" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">

		<div class="kivun-ef__grid">
			<div class="kivun-ef__field">
				<label><?php esc_html_e( 'שם מלא', 'kivun' ); ?></label>
				<input type="text" name="name" required autocomplete="name">
			</div>
			<div class="kivun-ef__field">
				<label><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
				<input type="tel" name="phone" required autocomplete="tel">
			</div>
			<div class="kivun-ef__field">
				<label><?php esc_html_e( 'אימייל', 'kivun' ); ?></label>
				<input type="email" name="email" autocomplete="email">
			</div>
			<div class="kivun-ef__field">
				<label><?php esc_html_e( 'עיר', 'kivun' ); ?></label>
				<input type="text" name="city" autocomplete="address-level2">
			</div>
		</div>

		<label class="kivun-ef__consent">
			<input type="checkbox" name="marketing_consent" value="1">
			<span>
				<?php
				if ( '' !== $kivun_policy ) {
					printf(
						wp_kses(
							/* translators: %s: privacy policy URL. */
							__( 'אני מאשר/ת שקראתי ומסכים/ה ל<a href="%s" target="_blank" rel="noopener">מדיניות הפרטיות ותנאי השימוש</a>', 'kivun' ),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						),
						esc_url( $kivun_policy )
					);
				} else {
					esc_html_e( 'אני מאשר/ת שקראתי ומסכים/ה למדיניות הפרטיות ותנאי השימוש', 'kivun' );
				}
				?>
			</span>
		</label>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-ef__submit">
			<?php esc_html_e( 'לפרטים והרשמה', 'kivun' ); ?>
		</button>
	</form>
</div>
