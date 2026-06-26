<?php
/**
 * Template: cookie consent banner + preferences dialog.
 *
 * @var string $policy_url Optional privacy/cookie policy URL.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_categories = array(
	'necessary' => array(
		'title'  => __( 'הכרחיות', 'kivun' ),
		'desc'   => __( 'נדרשות לתפקוד התקין של האתר ולא ניתן לכבותן.', 'kivun' ),
		'locked' => true,
	),
	'analytics' => array(
		'title'  => __( 'אנליטיקה', 'kivun' ),
		'desc'   => __( 'עוזרות לנו להבין כיצד משתמשים באתר כדי לשפר אותו.', 'kivun' ),
		'locked' => false,
	),
	'marketing' => array(
		'title'  => __( 'שיווק', 'kivun' ),
		'desc'   => __( 'משמשות להתאמת פרסום ותכנים רלוונטיים עבורך.', 'kivun' ),
		'locked' => false,
	),
);
?>
<div class="kivun-cc" dir="rtl">

	<!-- Banner -->
	<div class="kivun-cc-banner" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'הודעת עוגיות', 'kivun' ); ?>" hidden>
		<div class="kivun-cc-banner__inner">
			<div class="kivun-cc-banner__text">
				<span class="kivun-cc-banner__icon" aria-hidden="true">🍪</span>
				<p>
					<?php esc_html_e( 'אנחנו משתמשים בעוגיות כדי לשפר את חוויית הגלישה, לנתח תנועה ולהתאים תכנים. ניתן לאשר את כולן או לבחור העדפות.', 'kivun' ); ?>
					<?php if ( '' !== trim( $policy_url ) ) : ?>
						<a class="kivun-cc-policy" href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'מדיניות פרטיות', 'kivun' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="kivun-cc-banner__actions">
				<button type="button" class="kivun-cc-btn kivun-cc-btn--ghost" data-cc-settings><?php esc_html_e( 'התאמה אישית', 'kivun' ); ?></button>
				<button type="button" class="kivun-cc-btn kivun-cc-btn--outline" data-cc-reject><?php esc_html_e( 'דחיית הכל', 'kivun' ); ?></button>
				<button type="button" class="kivun-cc-btn kivun-cc-btn--primary" data-cc-accept><?php esc_html_e( 'אישור הכל', 'kivun' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Preferences dialog -->
	<div class="kivun-cc-modal" role="dialog" aria-modal="true" aria-labelledby="kivun-cc-modal-title" hidden>
		<div class="kivun-cc-modal__overlay" data-cc-close></div>
		<div class="kivun-cc-modal__panel" tabindex="-1">
			<div class="kivun-cc-modal__head">
				<h2 class="kivun-cc-modal__title" id="kivun-cc-modal-title"><?php esc_html_e( 'הגדרות עוגיות', 'kivun' ); ?></h2>
				<button type="button" class="kivun-cc-close" data-cc-close aria-label="<?php esc_attr_e( 'סגירה', 'kivun' ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>

			<p class="kivun-cc-modal__intro">
				<?php esc_html_e( 'בחרו אילו סוגי עוגיות לאפשר. ניתן לשנות את ההעדפות בכל עת.', 'kivun' ); ?>
			</p>

			<ul class="kivun-cc-cats">
				<?php foreach ( $kivun_categories as $kivun_key => $kivun_cat ) : ?>
					<li class="kivun-cc-cat">
						<div class="kivun-cc-cat__row">
							<span class="kivun-cc-cat__title"><?php echo esc_html( $kivun_cat['title'] ); ?></span>
							<label class="kivun-cc-switch">
								<input
									type="checkbox"
									value="<?php echo esc_attr( $kivun_key ); ?>"
									<?php echo $kivun_cat['locked'] ? 'checked disabled' : ''; ?>
								>
								<span class="kivun-cc-switch__track" aria-hidden="true"></span>
								<span class="kivun-sr-only"><?php echo esc_html( $kivun_cat['title'] ); ?></span>
							</label>
						</div>
						<p class="kivun-cc-cat__desc"><?php echo esc_html( $kivun_cat['desc'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="kivun-cc-modal__actions">
				<button type="button" class="kivun-cc-btn kivun-cc-btn--outline" data-cc-reject><?php esc_html_e( 'דחיית הכל', 'kivun' ); ?></button>
				<button type="button" class="kivun-cc-btn kivun-cc-btn--ghost" data-cc-save><?php esc_html_e( 'שמירת בחירה', 'kivun' ); ?></button>
				<button type="button" class="kivun-cc-btn kivun-cc-btn--primary" data-cc-accept><?php esc_html_e( 'אישור הכל', 'kivun' ); ?></button>
			</div>

			<?php if ( '' !== trim( $policy_url ) ) : ?>
				<p class="kivun-cc-modal__policy">
					<a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'קראו את מדיניות הפרטיות שלנו', 'kivun' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Re-open button (shown after a choice was made) -->
	<button type="button" class="kivun-cc-reopen" data-cc-reopen aria-label="<?php esc_attr_e( 'הגדרות עוגיות', 'kivun' ); ?>" hidden>
		<span aria-hidden="true">🍪</span>
	</button>

</div>
