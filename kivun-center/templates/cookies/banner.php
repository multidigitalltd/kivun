<?php
/**
 * Template: cookie notice banner (informational only).
 *
 * @var string $policy_url Optional privacy/cookie policy URL.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kivun-cc" dir="rtl">
	<div class="kivun-cc-banner" role="region" aria-label="<?php esc_attr_e( 'הודעת עוגיות', 'kivun' ); ?>" hidden>
		<div class="kivun-cc-banner__inner">
			<div class="kivun-cc-banner__text">
				<span class="kivun-cc-banner__icon" aria-hidden="true">🍪</span>
				<p>
					<?php esc_html_e( 'אתר זה עושה שימוש בעוגיות ובכלי מעקב כדי לשפר את חוויית הגלישה ולנתח את השימוש באתר.', 'kivun' ); ?>
					<?php if ( '' !== trim( $policy_url ) ) : ?>
						<?php esc_html_e( 'המשך הגלישה מהווה הסכמה למדיניות הפרטיות.', 'kivun' ); ?>
						<a class="kivun-cc-policy" href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'למדיניות הפרטיות המלאה', 'kivun' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="kivun-cc-banner__actions">
				<button type="button" class="kivun-cc-btn kivun-cc-btn--primary" data-cc-accept><?php esc_html_e( 'הבנתי', 'kivun' ); ?></button>
			</div>
		</div>
	</div>
</div>
