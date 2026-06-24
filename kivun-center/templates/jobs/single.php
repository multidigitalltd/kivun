<?php
/**
 * Template: single job details + application form, appended to job content.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$company      = get_post_meta( $job_id, '_kivun_company', true );
$salary       = get_post_meta( $job_id, '_kivun_salary', true );
$requirements = get_post_meta( $job_id, '_kivun_requirements', true );
$deadline     = get_post_meta( $job_id, '_kivun_deadline', true );

$regions = get_the_terms( $job_id, 'kivun_job_region' );
$region  = ( $regions && ! is_wp_error( $regions ) ) ? $regions[0]->name : '';
$scopes  = get_the_terms( $job_id, 'kivun_job_scope' );
$scope   = ( $scopes && ! is_wp_error( $scopes ) ) ? $scopes[0]->name : '';
$fields  = get_the_terms( $job_id, 'kivun_job_field' );
$field   = ( $fields && ! is_wp_error( $fields ) ) ? $fields[0]->name : '';

$rows = array(
	__( 'חברה', 'kivun' )              => $company,
	__( 'אזור', 'kivun' )              => $region,
	__( 'סוג משרה', 'kivun' )          => $scope,
	__( 'תחום', 'kivun' )              => $field,
	__( 'שכר', 'kivun' )               => $salary,
	__( 'תאריך אחרון להגשה', 'kivun' ) => $deadline,
);
?>
<div class="kivun-job-single" dir="rtl">

	<div class="kivun-job-single__details">
		<h3 class="kivun-job-single__heading"><?php esc_html_e( 'פרטי המשרה', 'kivun' ); ?></h3>
		<ul class="kivun-job-single__list">
			<?php foreach ( $rows as $label => $value ) : ?>
				<?php if ( $value ) : ?>
					<li>
						<span class="kivun-job-single__label"><?php echo esc_html( $label ); ?>:</span>
						<span class="kivun-job-single__value"><?php echo esc_html( $value ); ?></span>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>

		<?php if ( $requirements ) : ?>
			<div class="kivun-job-single__requirements">
				<h4 class="kivun-job-single__subheading"><?php esc_html_e( 'דרישות התפקיד', 'kivun' ); ?></h4>
				<?php echo wp_kses_post( wpautop( $requirements ) ); ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="kivun-job-single__apply" id="apply-<?php echo esc_attr( $job_id ); ?>">
		<h3 class="kivun-job-single__heading"><?php esc_html_e( 'הגשת מועמדות', 'kivun' ); ?></h3>
		<?php kivun_get_template( 'jobs/apply-form.php', array( 'job_id' => $job_id ) ); ?>
	</div>

</div>
