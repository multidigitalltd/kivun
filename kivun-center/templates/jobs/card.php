<?php
/**
 * Template: single job card for the jobs board.
 *
 * Display layer only — all data, links and the apply flow are unchanged.
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
<div class="kivun-jc-wrap" id="job-<?php echo esc_attr( $job_id ); ?>" dir="rtl">

	<div class="kivun-jc">

		<div class="kivun-jc-header">
			<?php if ( $company ) : ?>
				<div class="kivun-jc-logo"><?php echo esc_html( $company ); ?></div>
			<?php endif; ?>
			<h3 class="kivun-jc-title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a>
			</h3>
		</div>

		<div class="kivun-jc-body">

			<div class="kivun-jc-row">
				<span class="kivun-jc-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
				</span>
				<span class="kivun-jc-text"><?php esc_html_e( 'ת. פרסום:', 'kivun' ); ?> <?php echo esc_html( get_the_date( 'j.n.Y', $job_id ) ); ?></span>
			</div>

			<?php if ( $region ) : ?>
			<div class="kivun-jc-row">
				<span class="kivun-jc-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
				</span>
				<span class="kivun-jc-text"><?php esc_html_e( 'איזור:', 'kivun' ); ?> <?php echo esc_html( $region ); ?></span>
			</div>
			<?php endif; ?>

			<?php if ( $scope ) : ?>
			<div class="kivun-jc-row">
				<span class="kivun-jc-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
				</span>
				<span class="kivun-jc-text"><?php esc_html_e( 'סוג משרה:', 'kivun' ); ?> <?php echo esc_html( $scope ); ?></span>
			</div>
			<?php endif; ?>

		</div>

	</div>

	<a href="<?php echo esc_url( $permalink ); ?>" class="kivun-jc-apply">
		<span class="kivun-jc-apply-ico" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7L7 17M16 17H7V8"/></svg>
		</span>
		<span><?php esc_html_e( 'להגשת מועמדות', 'kivun' ); ?></span>
	</a>

</div>
