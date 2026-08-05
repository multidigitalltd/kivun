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

		<?php
		$kivun_privacy = '' !== $kivun_policy ? $kivun_policy : (string) get_privacy_policy_url();
		/**
		 * The URL for the "terms of use" consent link (defaults to the privacy URL).
		 *
		 * @param string $url The terms URL.
		 */
		$kivun_terms = (string) apply_filters( 'kivun_terms_url', $kivun_privacy );
		?>
		<label class="kivun-ef__consent">
			<input type="checkbox" name="marketing_consent" value="1">
			<span>
				<?php echo esc_html__( 'אני מאשר/ת שקראתי ואני מסכים/ה ל', 'kivun' ); ?>
				<?php if ( '' !== $kivun_privacy ) : ?>
					<a href="<?php echo esc_url( $kivun_privacy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'מדיניות הפרטיות', 'kivun' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'מדיניות הפרטיות', 'kivun' ); ?>
				<?php endif; ?>
				<?php echo esc_html__( 'ול', 'kivun' ); ?>
				<?php if ( '' !== $kivun_terms ) : ?>
					<a href="<?php echo esc_url( $kivun_terms ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'תנאי השימוש', 'kivun' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'תנאי השימוש', 'kivun' ); ?>
				<?php endif; ?>
			</span>
		</label>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-ef__submit">
			<span class="kivun-ef__submit-label"><?php esc_html_e( 'לפרטים והרשמה', 'kivun' ); ?></span>
			<span class="kivun-ef__submit-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="14" height="14" focusable="false"><path fill="currentColor" d="M7 7h9v9h-2V10.4L6.7 17.7 5.3 16.3 12.6 9H7V7Z"/></svg>
			</span>
		</button>
	</form>
</div>
