<?php
defined( 'ABSPATH' ) || exit;

$scopes  = get_terms( [ 'taxonomy' => 'kivun_job_scope',   'hide_empty' => true ] );
$regions = get_terms( [ 'taxonomy' => 'kivun_job_region',  'hide_empty' => true ] );
$fields  = get_terms( [ 'taxonomy' => 'kivun_job_field',   'hide_empty' => true ] );
?>
<div class="kivun-jobs-wrap">

	<?php if ( $show_filters ) : ?>
	<div class="kivun-jobs-filters">
		<div class="kivun-filter-group">
			<input type="text" id="kivun-filter-search" placeholder="<?php esc_attr_e( 'חיפוש משרה...', 'kivun' ); ?>" class="kivun-filter-search">
		</div>

		<div class="kivun-filter-group">
			<select id="kivun-filter-scope">
				<option value=""><?php esc_html_e( 'כל ההיקפים', 'kivun' ); ?></option>
				<?php foreach ( $scopes as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="kivun-filter-group">
			<select id="kivun-filter-region">
				<option value=""><?php esc_html_e( 'כל האזורים', 'kivun' ); ?></option>
				<?php foreach ( $regions as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="kivun-filter-group">
			<select id="kivun-filter-field">
				<option value=""><?php esc_html_e( 'כל התחומים', 'kivun' ); ?></option>
				<?php foreach ( $fields as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<?php endif; ?>

	<div class="kivun-jobs-meta">
		<span class="kivun-jobs-count"><?php echo esc_html( $query->found_posts ); ?></span>
		<?php esc_html_e( ' משרות נמצאו', 'kivun' ); ?>
	</div>

	<div class="kivun-jobs-board">
		<?php if ( $query->have_posts() ) : ?>
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<?php kivun_get_template( 'jobs/card.php' ); ?>
			<?php endwhile; ?>
		<?php else : ?>
			<p class="kivun-no-results"><?php esc_html_e( 'לא נמצאו משרות.', 'kivun' ); ?></p>
		<?php endif; ?>
	</div>

</div>
