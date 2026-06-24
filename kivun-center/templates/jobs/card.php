<?php
/**
 * Template: single job card for the jobs board.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$job_id    = get_the_ID();
$company   = get_post_meta( $job_id, '_kivun_company', true );
$permalink = get_permalink( $job_id );

$regions = get_the_terms( $job_id, 'kivun_job_region' );
$region  = ( $regions && ! is_wp_error( $regions ) ) ? $regions[0]->name : '';
$scopes  = get_the_terms( $job_id, 'kivun_job_scope' );
$scope   = ( $scopes && ! is_wp_error( $scopes ) ) ? $scopes[0]->name : '';
?>
<article class="kivun-job-card" id="job-<?php echo esc_attr( $job_id ); ?>" data-job-row="<?php echo esc_attr( $job_id ); ?>">

	<?php if ( $company ) : ?>
		<div class="kivun-job-card__logo"><?php echo esc_html( $company ); ?></div>
	<?php endif; ?>

	<h3 class="kivun-job-card__title">
		<a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a>
	</h3>

	<ul class="kivun-job-card__meta">
		<li class="kivun-job-card__meta-item">
			<span class="kivun-job-card__ico" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
			</span>
			<span class="kivun-job-card__label"><?php esc_html_e( 'ת. פרסום:', 'kivun' ); ?></span>
			<span class="kivun-job-card__value"><?php echo esc_html( get_the_date( 'j.n.Y', $job_id ) ); ?></span>
		</li>

		<?php if ( $region ) : ?>
		<li class="kivun-job-card__meta-item">
			<span class="kivun-job-card__ico" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
			</span>
			<span class="kivun-job-card__label"><?php esc_html_e( 'אזור:', 'kivun' ); ?></span>
			<span class="kivun-job-card__value"><?php echo esc_html( $region ); ?></span>
		</li>
		<?php endif; ?>

		<?php if ( $scope ) : ?>
		<li class="kivun-job-card__meta-item">
			<span class="kivun-job-card__ico" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
			</span>
			<span class="kivun-job-card__label"><?php esc_html_e( 'סוג משרה:', 'kivun' ); ?></span>
			<span class="kivun-job-card__value"><?php echo esc_html( $scope ); ?></span>
		</li>
		<?php endif; ?>
	</ul>

	<a class="kivun-job-card__cta" href="<?php echo esc_url( $permalink ); ?>">
		<span class="kivun-job-card__cta-ico" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
		</span>
		<?php esc_html_e( 'להגשת מועמדות', 'kivun' ); ?>
	</a>

</article>
