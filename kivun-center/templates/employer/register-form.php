<?php
/**
 * Template: employer registration form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
	echo '<p class="kivun-notice">' . esc_html__( 'כבר מחובר/ת. עבור לדשבורד המעסיקים.', 'kivun' ) . '</p>';
	return;
}
?>
<div class="kivun-employer-register">
	<h2 class="kivun-employer-register__title"><?php esc_html_e( 'הצטרף כמעסיק', 'kivun' ); ?></h2>

	<form class="kivun-employer-reg-form" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>

		<div class="kivun-form-grid">
			<div class="kivun-form-row">
				<label><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
				<input type="text" name="display_name" required autocomplete="name">
			</div>
			<div class="kivun-form-row">
				<label><?php esc_html_e( 'שם חברה *', 'kivun' ); ?></label>
				<input type="text" name="company" required>
			</div>
			<div class="kivun-form-row">
				<label><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
				<input type="email" name="email" required autocomplete="email">
			</div>
			<div class="kivun-form-row">
				<label><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
				<input type="tel" name="phone" autocomplete="tel">
			</div>
			<div class="kivun-form-row">
				<label><?php esc_html_e( 'סיסמה *', 'kivun' ); ?></label>
				<input type="password" name="password" required minlength="8" autocomplete="new-password">
				<small><?php esc_html_e( 'לפחות 8 תווים', 'kivun' ); ?></small>
			</div>
		</div>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-btn kivun-btn--primary">
			<?php esc_html_e( 'צור חשבון מעסיק', 'kivun' ); ?>
		</button>
	</form>
</div>
