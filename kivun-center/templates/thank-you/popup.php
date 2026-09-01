<?php
/**
 * Template: confirmation dialog shown after a plugin form is submitted.
 *
 * @var string $title   Heading.
 * @var string $message Body text.
 * @var string $label   Button label.
 * @var string $url     Where the button leads.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="kivun-ty" dir="rtl" hidden>
	<div class="kivun-ty__backdrop" data-ty-close></div>
	<div class="kivun-ty__dialog" role="dialog" aria-modal="true" aria-labelledby="kivun-ty-title" tabindex="-1">
		<button type="button" class="kivun-ty__close" data-ty-close aria-label="<?php esc_attr_e( 'סגירה', 'kivun' ); ?>">&times;</button>
		<div class="kivun-ty__icon" aria-hidden="true">✓</div>
		<h2 class="kivun-ty__title" id="kivun-ty-title"><?php echo esc_html( $title ); ?></h2>
		<p class="kivun-ty__text"><?php echo esc_html( $message ); ?></p>
		<a class="kivun-ty__btn" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<button type="button" class="kivun-ty__dismiss" data-ty-close><?php esc_html_e( 'סגירה', 'kivun' ); ?></button>
	</div>
</div>
