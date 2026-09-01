<?php
/**
 * Template: single workshop view.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$f        = fn( $key ) => get_post_meta( $workshop_id, $key, true );
$date     = $f( '_kivun_ws_date' );
$duration = $f( '_kivun_ws_duration' );
$location = $f( '_kivun_ws_location' );
$audience = $f( '_kivun_ws_audience' );
$capacity = $f( '_kivun_ws_capacity' );
?>
<article class="kivun-course-single" data-workshop-id="<?php echo esc_attr( $workshop_id ); ?>">

	<?php if ( has_post_thumbnail( $workshop_id ) ) : ?>
		<div class="kivun-course-single__thumb">
			<?php echo get_the_post_thumbnail( $workshop_id, 'large' ); ?>
		</div>
	<?php endif; ?>

	<div class="kivun-course-single__content">

		<h1 class="kivun-course-single__title"><?php echo esc_html( get_the_title( $workshop_id ) ); ?></h1>

		<div class="kivun-course-single__quick-info">
			<div class="kivun-info-pill kivun-info-pill--free"><?php esc_html_e( 'ללא עלות', 'kivun' ); ?></div>

			<?php if ( $date ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'תאריך', 'kivun' ); ?></strong> <?php echo esc_html( $date ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $duration ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'משך', 'kivun' ); ?></strong> <?php echo esc_html( $duration ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $location ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'מיקום', 'kivun' ); ?></strong> <?php echo esc_html( $location ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $capacity ) : ?>
				<div class="kivun-info-pill">
					<strong><?php esc_html_e( 'מקומות', 'kivun' ); ?></strong> <?php echo esc_html( $capacity ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="kivun-course-single__description">
			<?php echo wp_kses_post( get_post_field( 'post_content', $workshop_id ) ); ?>
		</div>

		<?php if ( $audience ) : ?>
			<div class="kivun-course-single__section">
				<h2><?php esc_html_e( 'למי מיועדת הסדנה', 'kivun' ); ?></h2>
				<p><?php echo esc_html( $audience ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Registration / lead form -->
		<?php
		kivun_get_template(
			'courses/interest-form.php',
			array(
				'post_id'   => $workshop_id,
				'post_type' => 'kivun_workshop',
			)
		);
		?>

	</div>
</article>
