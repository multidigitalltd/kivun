<?php
/**
 * Template: single workshop card.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$date     = get_post_meta( get_the_ID(), '_kivun_ws_date', true );
$duration = get_post_meta( get_the_ID(), '_kivun_ws_duration', true );
$location = get_post_meta( get_the_ID(), '_kivun_ws_location', true );
?>
<article class="kivun-course-card" id="workshop-<?php the_ID(); ?>">
	<?php if ( has_post_thumbnail() ) : ?>
		<a href="<?php the_permalink(); ?>" class="kivun-course-card__thumb">
			<?php the_post_thumbnail( 'medium' ); ?>
		</a>
	<?php endif; ?>

	<div class="kivun-course-card__body">
		<h3 class="kivun-course-card__title">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		</h3>

		<ul class="kivun-course-card__meta">
			<?php if ( $date ) : ?>
				<li><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html( $date ); ?></li>
			<?php endif; ?>
			<?php if ( $duration ) : ?>
				<li><span class="dashicons dashicons-clock"></span> <?php echo esc_html( $duration ); ?></li>
			<?php endif; ?>
			<?php if ( $location ) : ?>
				<li><span class="dashicons dashicons-location"></span> <?php echo esc_html( $location ); ?></li>
			<?php endif; ?>
		</ul>

		<div class="kivun-course-card__excerpt"><?php the_excerpt(); ?></div>

		<div class="kivun-course-card__footer">
			<span class="kivun-course-card__price kivun-info-pill--free"><?php esc_html_e( 'ללא עלות', 'kivun' ); ?></span>
			<a href="<?php the_permalink(); ?>" class="kivun-btn kivun-btn--primary">
				<?php esc_html_e( 'פרטים והרשמה', 'kivun' ); ?>
			</a>
		</div>
	</div>
</article>
