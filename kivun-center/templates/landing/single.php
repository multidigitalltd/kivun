<?php
/**
 * Template: landing page (fixed structure) single view.
 *
 * @var int    $landing_id The landing page post ID.
 * @var string $long_html  The long description (post content), pre-filtered.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_short    = (string) get_post_meta( $landing_id, '_kivun_lp_short', true );
$kivun_audience = (string) get_post_meta( $landing_id, '_kivun_ws_audience', true );
$kivun_duration = (string) get_post_meta( $landing_id, '_kivun_ws_duration', true );
$kivun_cost     = (string) get_post_meta( $landing_id, '_kivun_lp_cost', true );
$kivun_date     = (string) get_post_meta( $landing_id, '_kivun_ws_date', true );

$kivun_details = array(
	array( 'תאריך פתיחה', $kivun_date ),
	array( 'משך', $kivun_duration ),
	array( 'קהל יעד', $kivun_audience ),
	array( 'עלות', $kivun_cost ),
);
?>
<div class="kivun-landing" dir="rtl">

	<?php if ( has_post_thumbnail( $landing_id ) ) : ?>
		<div class="kivun-landing__hero">
			<?php echo get_the_post_thumbnail( $landing_id, 'large', array( 'class' => 'kivun-landing__hero-img' ) ); ?>
		</div>
	<?php endif; ?>

	<div class="kivun-landing__head">
		<h1 class="kivun-landing__title"><?php echo esc_html( get_the_title( $landing_id ) ); ?></h1>
		<?php if ( '' !== trim( $kivun_short ) ) : ?>
			<div class="kivun-landing__short"><?php echo wp_kses_post( wpautop( $kivun_short ) ); ?></div>
		<?php endif; ?>
	</div>

	<?php if ( array_filter( wp_list_pluck( $kivun_details, 1 ) ) ) : ?>
		<ul class="kivun-landing__facts">
			<?php foreach ( $kivun_details as $kivun_fact ) : ?>
				<?php if ( '' !== trim( (string) $kivun_fact[1] ) ) : ?>
					<li class="kivun-landing__fact">
						<span class="kivun-landing__fact-label"><?php echo esc_html( $kivun_fact[0] ); ?></span>
						<span class="kivun-landing__fact-value"><?php echo esc_html( $kivun_fact[1] ); ?></span>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( '' !== trim( wp_strip_all_tags( $long_html ) ) ) : ?>
		<div class="kivun-landing__body"><?php echo wp_kses_post( $long_html ); ?></div>
	<?php endif; ?>

	<div class="kivun-landing__form" id="kivun-landing-register">
		<?php
		kivun_get_template(
			'courses/interest-form.php',
			array(
				'post_id'   => $landing_id,
				'post_type' => 'kivun_workshop',
			)
		);
		?>
	</div>

</div>
