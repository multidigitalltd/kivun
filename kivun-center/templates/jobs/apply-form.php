<?php
/**
 * Template: job CV application form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_turnstile_key = Kivun_Admin_Settings::get( 'turnstile_site_key' );
?>
<div class="kivun-apply-wrap">
	<form class="kivun-apply-form" enctype="multipart/form-data" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">

		<div class="kivun-form-row">
			<label for="kivun-apply-name"><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
			<input type="text" id="kivun-apply-name" name="applicant_name" required autocomplete="name">
		</div>

		<div class="kivun-form-row">
			<label for="kivun-apply-email"><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
			<input type="email" id="kivun-apply-email" name="applicant_email" required autocomplete="email">
		</div>

		<div class="kivun-form-row">
			<label for="kivun-apply-phone"><?php esc_html_e( 'טלפון *', 'kivun' ); ?></label>
			<input type="tel" id="kivun-apply-phone" name="applicant_phone" required autocomplete="tel">
		</div>

		<div class="kivun-form-row">
			<label for="kivun-apply-message"><?php esc_html_e( 'כמה מילים עליך (אופציונלי)', 'kivun' ); ?></label>
			<textarea id="kivun-apply-message" name="message" rows="3" placeholder="<?php esc_attr_e( 'ספרו בכמה מילים על עצמכם ולמה אתם מתאימים למשרה', 'kivun' ); ?>"></textarea>
		</div>

		<div class="kivun-form-row">
			<label for="kivun-apply-cv"><?php esc_html_e( 'קורות חיים * (PDF / Word, עד 5MB)', 'kivun' ); ?></label>
			<input type="file" id="kivun-apply-cv" name="cv_file" accept=".pdf,.doc,.docx" required>
		</div>

		<?php if ( $kivun_turnstile_key ) : ?>
			<?php wp_enqueue_script( 'kivun-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, WordPress.WP.EnqueuedResourceParameters.NotInFooter -- External Cloudflare API; no version, loaded async in footer. ?>
			<div class="kivun-form-row">
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $kivun_turnstile_key ); ?>" data-language="he"></div>
			</div>
		<?php endif; ?>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-btn kivun-btn--primary">
			<?php esc_html_e( 'שלח מועמדות', 'kivun' ); ?>
		</button>
	</form>
</div>
