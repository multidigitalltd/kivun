<?php
/**
 * Template: jobs board archive with filters.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$scopes  = get_terms(
	array(
		'taxonomy'   => 'kivun_job_scope',
		'hide_empty' => true,
	)
);
$regions = get_terms(
	array(
		'taxonomy'   => 'kivun_job_region',
		'hide_empty' => true,
	)
);
$fields  = get_terms(
	array(
		'taxonomy'   => 'kivun_job_field',
		'hide_empty' => true,
	)
);
?>
<div class="kivun-jobs-wrap">

	<?php if ( $show_filters ) : ?>
	<div class="kivun-jobs-filters">

		<div class="kivun-filtertabs" role="tablist" aria-label="<?php esc_attr_e( 'מצב חיפוש', 'kivun' ); ?>">
			<button type="button" class="kivun-filtertab is-active" data-filtermode="categories"><?php esc_html_e( 'חיפוש לפי קטגוריות', 'kivun' ); ?></button>
			<button type="button" class="kivun-filtertab" data-filtermode="free"><?php esc_html_e( 'חיפוש חופשי', 'kivun' ); ?></button>
		</div>

		<div class="kivun-filterrow" data-filterpanel="categories">
			<div class="kivun-select-pill">
				<label class="kivun-sr-only" for="kivun-filter-scope"><?php esc_html_e( 'סוג משרה', 'kivun' ); ?></label>
				<select id="kivun-filter-scope">
					<option value=""><?php esc_html_e( 'בחרו סוג משרה', 'kivun' ); ?></option>
					<?php foreach ( $scopes as $job_term ) : ?>
						<option value="<?php echo esc_attr( $job_term->slug ); ?>"><?php echo esc_html( $job_term->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="kivun-pill-ico" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
				</span>
			</div>

			<div class="kivun-select-pill">
				<label class="kivun-sr-only" for="kivun-filter-region"><?php esc_html_e( 'אזור', 'kivun' ); ?></label>
				<select id="kivun-filter-region">
					<option value=""><?php esc_html_e( 'בחרו אזור', 'kivun' ); ?></option>
					<?php foreach ( $regions as $job_term ) : ?>
						<option value="<?php echo esc_attr( $job_term->slug ); ?>"><?php echo esc_html( $job_term->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="kivun-pill-ico" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
				</span>
			</div>

			<div class="kivun-select-pill">
				<label class="kivun-sr-only" for="kivun-filter-field"><?php esc_html_e( 'תחום', 'kivun' ); ?></label>
				<select id="kivun-filter-field">
					<option value=""><?php esc_html_e( 'בחרו תחום', 'kivun' ); ?></option>
					<?php foreach ( $fields as $job_term ) : ?>
						<option value="<?php echo esc_attr( $job_term->slug ); ?>"><?php echo esc_html( $job_term->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="kivun-pill-ico" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
				</span>
			</div>
		</div>

		<div class="kivun-filterrow" data-filterpanel="free" hidden>
			<div class="kivun-search-pill">
				<label class="kivun-sr-only" for="kivun-filter-search"><?php esc_html_e( 'חיפוש חופשי', 'kivun' ); ?></label>
				<span class="kivun-search-ico" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
				</span>
				<input type="search" id="kivun-filter-search" placeholder="<?php esc_attr_e( 'חיפוש משרה לפי שם או מילת מפתח…', 'kivun' ); ?>">
			</div>
		</div>

	</div>
	<?php endif; ?>

	<div class="kivun-jobs-meta">
		<span class="kivun-jobs-count"><?php echo esc_html( $query->found_posts ); ?></span>
		<?php esc_html_e( ' משרות נמצאו', 'kivun' ); ?>
	</div>

	<div class="kivun-jobs-board">
		<?php if ( $query->have_posts() ) : ?>
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				?>
				<?php kivun_get_template( 'jobs/card.php' ); ?>
			<?php endwhile; ?>
		<?php else : ?>
			<p class="kivun-no-results"><?php esc_html_e( 'לא נמצאו משרות.', 'kivun' ); ?></p>
		<?php endif; ?>
	</div>

</div>
