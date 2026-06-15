<?php
/**
 * Template: accessibility toolbar (trigger button and feature panel).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feature buttons rendered inside the panel.
 *
 * @var array<int, array{key:string,label:string}> $kivun_a11y_features
 */
$kivun_a11y_features = array(
	array(
		'key'   => 'font-increase',
		'label' => __( 'הגדלת גודל הטקסט', 'kivun' ),
	),
	array(
		'key'   => 'font-decrease',
		'label' => __( 'הקטנת גודל הטקסט', 'kivun' ),
	),
	array(
		'key'   => 'contrast',
		'label' => __( 'ניגודיות גבוהה', 'kivun' ),
	),
	array(
		'key'   => 'negative',
		'label' => __( 'היפוך צבעים', 'kivun' ),
	),
	array(
		'key'   => 'grayscale',
		'label' => __( 'גווני אפור', 'kivun' ),
	),
	array(
		'key'   => 'underline-links',
		'label' => __( 'קו תחתון לקישורים', 'kivun' ),
	),
	array(
		'key'   => 'readable-font',
		'label' => __( 'גופן קריא', 'kivun' ),
	),
	array(
		'key'   => 'stop-animations',
		'label' => __( 'עצירת אנימציות', 'kivun' ),
	),
	array(
		'key'   => 'highlight-headings',
		'label' => __( 'הדגשת כותרות', 'kivun' ),
	),
	array(
		'key'   => 'highlight-links',
		'label' => __( 'הדגשת קישורים', 'kivun' ),
	),
	array(
		'key'   => 'reading-guide',
		'label' => __( 'סרגל קריאה', 'kivun' ),
	),
	array(
		'key'   => 'big-cursor',
		'label' => __( 'סמן עכבר גדול', 'kivun' ),
	),
);
?>
<div class="kivun-a11y" dir="rtl">
	<button
		type="button"
		class="kivun-a11y-toggle"
		aria-expanded="false"
		aria-controls="kivun-a11y-panel"
		aria-label="<?php esc_attr_e( 'פתיחת תפריט נגישות', 'kivun' ); ?>"
	>
		<svg class="kivun-a11y-icon" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false">
			<circle cx="12" cy="3.6" r="2.2" fill="currentColor"></circle>
			<path fill="currentColor" d="M3 8.2c0-.7.6-1.2 1.3-1l7.7 1.7 7.7-1.7c.7-.2 1.3.3 1.3 1 0 .5-.3.9-.8 1l-5.7 1.3.9 9.6c.1.7-.5 1.3-1.2 1.3-.6 0-1.1-.4-1.2-1l-.9-5.3-.9 5.3c-.1.6-.6 1-1.2 1-.7 0-1.3-.6-1.2-1.3l.9-9.6L3.8 9.2c-.5-.1-.8-.5-.8-1z"></path>
		</svg>
		<span class="kivun-sr-only"><?php esc_html_e( 'תפריט נגישות', 'kivun' ); ?></span>
	</button>

	<div
		id="kivun-a11y-panel"
		class="kivun-a11y-panel"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'תפריט נגישות', 'kivun' ); ?>"
		hidden
	>
		<div class="kivun-a11y-panel__head">
			<h2 class="kivun-a11y-panel__title"><?php esc_html_e( 'אפשרויות נגישות', 'kivun' ); ?></h2>
			<button
				type="button"
				class="kivun-a11y-close"
				aria-label="<?php esc_attr_e( 'סגירת תפריט נגישות', 'kivun' ); ?>"
			>
				<span aria-hidden="true">&times;</span>
				<span class="kivun-sr-only"><?php esc_html_e( 'סגירה', 'kivun' ); ?></span>
			</button>
		</div>

		<ul class="kivun-a11y-list">
			<?php foreach ( $kivun_a11y_features as $kivun_a11y_feature ) : ?>
				<li class="kivun-a11y-list__item">
					<button
						type="button"
						class="kivun-a11y-btn"
						data-a11y="<?php echo esc_attr( $kivun_a11y_feature['key'] ); ?>"
						aria-pressed="false"
					>
						<?php echo esc_html( $kivun_a11y_feature['label'] ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="kivun-a11y-panel__foot">
			<button type="button" class="kivun-a11y-reset" data-a11y-reset>
				<?php esc_html_e( 'איפוס הגדרות נגישות', 'kivun' ); ?>
			</button>
		</div>
	</div>
</div>
