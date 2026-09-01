<?php
/**
 * Template: accessibility toolbar (trigger button and feature panel).
 *
 * Features are grouped into labelled sections with monochrome icons for a
 * cleaner, less cluttered panel. Each button keeps its `data-a11y` key and
 * `aria-pressed` state, so the behaviour script is unchanged.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_a11y_groups = array(
	array(
		'title' => __( 'גודל טקסט', 'kivun' ),
		'items' => array(
			array(
				'key'   => 'font-increase',
				'label' => __( 'הגדלה', 'kivun' ),
				'icon'  => '<span class="kivun-a11y-glyph">A<sup>+</sup></span>',
			),
			array(
				'key'   => 'font-decrease',
				'label' => __( 'הקטנה', 'kivun' ),
				'icon'  => '<span class="kivun-a11y-glyph">A<sub>−</sub></span>',
			),
		),
	),
	array(
		'title' => __( 'צבע וניגודיות', 'kivun' ),
		'items' => array(
			array(
				'key'   => 'contrast',
				'label' => __( 'ניגודיות', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/></svg>',
			),
			array(
				'key'   => 'negative',
				'label' => __( 'היפוך צבעים', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/></svg>',
			),
			array(
				'key'   => 'grayscale',
				'label' => __( 'גווני אפור', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 3c4 5 6 7 6 10a6 6 0 0 1-12 0c0-3 2-5 6-10z" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
			),
		),
	),
	array(
		'title' => __( 'קריאה והדגשה', 'kivun' ),
		'items' => array(
			array(
				'key'   => 'readable-font',
				'label' => __( 'גופן קריא', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 19 10 5h2l5 14M7.5 14h7" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			),
			array(
				'key'   => 'underline-links',
				'label' => __( 'קו לקישורים', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M7 4v7a5 5 0 0 0 10 0V4M5 20h14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
			),
			array(
				'key'   => 'highlight-headings',
				'label' => __( 'הדגשת כותרות', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 4v16M18 4v16M6 12h12" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
			),
			array(
				'key'   => 'highlight-links',
				'label' => __( 'הדגשת קישורים', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9.5 8H8a4 4 0 0 0 0 8h1.5M14.5 8H16a4 4 0 0 1 0 8h-1.5M8.5 12h7" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
			),
		),
	),
	array(
		'title' => __( 'ניווט וסיוע', 'kivun' ),
		'items' => array(
			array(
				'key'   => 'reading-guide',
				'label' => __( 'סרגל קריאה', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="3" y="9" width="18" height="6" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 9v3M11 9v3M15 9v3" stroke="currentColor" stroke-width="2"/></svg>',
			),
			array(
				'key'   => 'big-cursor',
				'label' => __( 'סמן גדול', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 3l14 7-6 1.6L11 19z" fill="currentColor"/></svg>',
			),
			array(
				'key'   => 'stop-animations',
				'label' => __( 'עצירת אנימציות', 'kivun' ),
				'icon'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1" fill="currentColor"/><rect x="14" y="5" width="4" height="14" rx="1" fill="currentColor"/></svg>',
			),
		),
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

		<div class="kivun-a11y-body">
			<?php foreach ( $kivun_a11y_groups as $kivun_group ) : ?>
				<section class="kivun-a11y-group">
					<h3 class="kivun-a11y-group__title"><?php echo esc_html( $kivun_group['title'] ); ?></h3>
					<ul class="kivun-a11y-list">
						<?php foreach ( $kivun_group['items'] as $kivun_feature ) : ?>
							<li class="kivun-a11y-list__item">
								<button
									type="button"
									class="kivun-a11y-btn"
									data-a11y="<?php echo esc_attr( $kivun_feature['key'] ); ?>"
									aria-pressed="false"
								>
									<span class="kivun-a11y-btn__icon" aria-hidden="true">
										<?php
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded inline SVG/glyph icon.
										echo $kivun_feature['icon'];
										?>
									</span>
									<span class="kivun-a11y-btn__label"><?php echo esc_html( $kivun_feature['label'] ); ?></span>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>

		<div class="kivun-a11y-panel__foot">
			<button type="button" class="kivun-a11y-reset" data-a11y-reset>
				<?php esc_html_e( 'איפוס הגדרות נגישות', 'kivun' ); ?>
			</button>
		</div>
	</div>
</div>
