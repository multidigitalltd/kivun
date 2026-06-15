<?php
/**
 * Template: job CV application form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kivun-apply-wrap">
	<form class="kivun-apply-form" enctype="multipart/form-data" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
			<input type="text" name="applicant_name" required autocomplete="name">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
			<input type="email" name="applicant_email" required autocomplete="email">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
			<input type="tel" name="applicant_phone" autocomplete="tel">
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'מכתב מקדים', 'kivun' ); ?></label>
			<textarea name="message" rows="4"></textarea>
		</div>

		<div class="kivun-form-row">
			<label><?php esc_html_e( 'קורות חיים (PDF / Word, עד 5MB)', 'kivun' ); ?></label>
			<input type="file" name="cv_file" accept=".pdf,.doc,.docx">
		</div>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-btn kivun-btn--primary">
			<?php esc_html_e( 'שלח מועמדות', 'kivun' ); ?>
		</button>
	</form>
</div>
