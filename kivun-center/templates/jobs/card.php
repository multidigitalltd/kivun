<?php
/**
 * Template: single job card for the jobs board.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$job_id   = get_the_ID();
$company  = get_post_meta( $job_id, '_kivun_company', true );
$salary   = get_post_meta( $job_id, '_kivun_salary', true );
$deadline = get_post_meta( $job_id, '_kivun_deadline', true );

$scopes  = get_the_terms( $job_id, 'kivun_job_scope' );
$scopes  = $scopes ? $scopes : array();
$regions = get_the_terms( $job_id, 'kivun_job_region' );
$regions = $regions ? $regions : array();
$fields  = get_the_terms( $job_id, 'kivun_job_field' );
$fields  = $fields ? $fields : array();
?>
<article class="kivun-job-card" id="job-<?php echo esc_attr( $job_id ); ?>" data-job-row="<?php echo esc_attr( $job_id ); ?>">

	<div class="kivun-job-card__header">
		<h3 class="kivun-job-card__title"><?php the_title(); ?></h3>
		<?php if ( $company ) : ?>
			<span class="kivun-job-card__company"><?php echo esc_html( $company ); ?></span>
		<?php endif; ?>
	</div>

	<div class="kivun-job-card__tags">
		<?php foreach ( $scopes as $t ) : ?>
			<span class="kivun-tag kivun-tag--scope"><?php echo esc_html( $t->name ); ?></span>
		<?php endforeach; ?>
		<?php foreach ( $regions as $t ) : ?>
			<span class="kivun-tag kivun-tag--region"><?php echo esc_html( $t->name ); ?></span>
		<?php endforeach; ?>
		<?php foreach ( $fields as $t ) : ?>
			<span class="kivun-tag kivun-tag--field"><?php echo esc_html( $t->name ); ?></span>
		<?php endforeach; ?>
	</div>

	<div class="kivun-job-card__excerpt">
		<?php the_excerpt(); ?>
	</div>

	<div class="kivun-job-card__footer">
		<?php if ( $salary ) : ?>
			<span class="kivun-job-card__salary"><?php echo esc_html( $salary ); ?></span>
		<?php endif; ?>
		<?php if ( $deadline ) : ?>
			<span class="kivun-job-card__deadline">
				<?php
					/* translators: %s: application deadline date. */
					printf( esc_html__( 'אחרון: %s', 'kivun' ), esc_html( $deadline ) );
				?>
			</span>
		<?php endif; ?>
		<button
			type="button"
			class="kivun-btn kivun-btn--outline kivun-apply-toggle"
			data-target="apply-<?php echo esc_attr( $job_id ); ?>"
		>
			<?php esc_html_e( 'שלח קו"ח', 'kivun' ); ?>
		</button>
	</div>

	<div class="kivun-job-apply-inline" id="apply-<?php echo esc_attr( $job_id ); ?>" style="display:none;">
		<?php kivun_get_template( 'jobs/apply-form.php', array( 'job_id' => $job_id ) ); ?>
	</div>

</article>
