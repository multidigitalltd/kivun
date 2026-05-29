<?php defined( 'ABSPATH' ) || exit; ?>
<div class="kivun-workshops kivun-workshops--cols-<?php echo esc_attr( $columns ); ?>">
	<?php if ( $query->have_posts() ) : ?>
		<?php while ( $query->have_posts() ) : $query->the_post(); ?>
			<?php kivun_get_template( 'workshops/card.php' ); ?>
		<?php endwhile; ?>
	<?php else : ?>
		<p class="kivun-no-results"><?php esc_html_e( 'אין סדנאות להצגה כרגע.', 'kivun' ); ?></p>
	<?php endif; ?>
</div>
