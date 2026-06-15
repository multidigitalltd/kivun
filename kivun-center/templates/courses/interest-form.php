<?php
/**
 * Template: course interest form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kivun-interest" id="kivun-interest-<?php echo esc_attr( $post_id ); ?>">
	<h3 class="kivun-interest__title"><?php esc_html_e( 'מתעניין/ת? נחזור אליך', 'kivun' ); ?></h3>
	<p class="kivun-interest__sub"><?php esc_html_e( 'השאר פרטים ונציג שלנו יצור איתך קשר עם כל המידע.', 'kivun' ); ?></p>

	<form class="kivun-lead-form" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="post_id"   value="<?php echo esc_attr( $post_id ); ?>">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
			<input type="text" name="name" required autocomplete="name">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'טלפון *', 'kivun' ); ?></label>
			<input type="tel" name="phone" required autocomplete="tel">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'אימייל', 'kivun' ); ?></label>
			<input type="email" name="email" autocomplete="email">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'הערות / שאלות', 'kivun' ); ?></label>
			<textarea name="message" rows="3"></textarea>
		</div>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-btn kivun-btn--outline">
			<?php esc_html_e( 'שלח פנייה — נחזור אליך', 'kivun' ); ?>
		</button>
	</form>
</div>
