<?php
defined( 'ABSPATH' ) || exit;

$f = fn( $key ) => get_post_meta( $course_id, $key, true );

$price    = (int) $f( '_kivun_price' );
$schedule = $f( '_kivun_schedule' );
$duration = $f( '_kivun_duration' );
$audience = $f( '_kivun_target_audience' );
$benefits = $f( '_kivun_benefits' );
$capacity = $f( '_kivun_capacity' );
$is_paid  = $price > 0 && $f( '_kivun_wc_product_id' );
?>
<article class="kivun-course-single" data-course-id="<?php echo esc_attr( $course_id ); ?>">

	<?php if ( has_post_thumbnail( $course_id ) ) : ?>
		<div class="kivun-course-single__thumb">
			<?php echo get_the_post_thumbnail( $course_id, 'large' ); ?>
		</div>
	<?php endif; ?>

	<div class="kivun-course-single__content">

		<h1 class="kivun-course-single__title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h1>

		<div class="kivun-course-single__quick-info">
			<?php if ( $price > 0 ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'עלות', 'kivun' ); ?></strong>
					<?php echo '₪' . esc_html( number_format( $price ) ); ?>
				</div>
			<?php else : ?>
				<div class="kivun-info-pill kivun-info-pill--free"><?php esc_html_e( 'חינמי', 'kivun' ); ?></div>
			<?php endif; ?>

			<?php if ( $schedule ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'זמנים', 'kivun' ); ?></strong>
					<?php echo esc_html( $schedule ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $duration ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'משך', 'kivun' ); ?></strong>
					<?php echo esc_html( $duration ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $capacity ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'מקסימום משתתפים', 'kivun' ); ?></strong>
					<?php echo esc_html( $capacity ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="kivun-course-single__description">
			<?php echo wp_kses_post( get_post_field( 'post_content', $course_id ) ); ?>
		</div>

		<?php if ( $audience ) : ?>
			<div class="kivun-course-single__section">
				<h2><?php esc_html_e( 'קהל יעד', 'kivun' ); ?></h2>
				<p><?php echo esc_html( $audience ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $benefits ) : ?>
			<div class="kivun-course-single__section">
				<h2><?php esc_html_e( 'מה תלמד', 'kivun' ); ?></h2>
				<ul class="kivun-benefits-list">
					<?php foreach ( array_filter( explode( "\n", $benefits ) ) as $line ) : ?>
						<li><?php echo esc_html( trim( $line ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php kivun_get_template( 'courses/register-form.php', [ 'course_id' => $course_id ] ); ?>

	</div>
</article>
