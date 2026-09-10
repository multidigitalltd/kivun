<?php
/**
 * Template: single course card.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$price    = (int) get_post_meta( get_the_ID(), '_kivun_price', true );
$schedule = get_post_meta( get_the_ID(), '_kivun_schedule', true );
$duration = get_post_meta( get_the_ID(), '_kivun_duration', true );
?>
<article class="kivun-course-card" id="course-<?php the_ID(); ?>">
	<?php if ( has_post_thumbnail() ) : ?>
		<a href="<?php the_permalink(); ?>" class="kivun-course-card__thumb">
			<?php the_post_thumbnail( 'medium' ); ?>
		</a>
	<?php endif; ?>

	<div class="kivun-course-card__body">
		<h3 class="kivun-course-card__title">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		</h3>

		<?php if ( $schedule || $duration ) : ?>
			<ul class="kivun-course-card__meta">
				<?php if ( $schedule ) : ?>
					<li><span class="dashicons dashicons-clock"></span> <?php echo esc_html( $schedule ); ?></li>
				<?php endif; ?>
				<?php if ( $duration ) : ?>
					<li><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html( $duration ); ?></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>

		<div class="kivun-course-card__excerpt"><?php the_excerpt(); ?></div>

		<div class="kivun-course-card__footer">
			<span class="kivun-course-card__price">
				<?php echo $price > 0 ? '₪' . esc_html( number_format( $price ) ) : esc_html__( 'חינמי', 'kivun' ); ?>
			</span>
			<a href="<?php the_permalink(); ?>" class="kivun-btn kivun-btn--primary">
				<?php esc_html_e( 'פרטים נוספים', 'kivun' ); ?>
			</a>
		</div>
	</div>
</article>
