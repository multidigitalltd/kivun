<?php
/**
 * Template: single job page (display layer for the appended job view).
 *
 * Display only — all data, links, buttons and the apply form are unchanged.
 *
 * @package Kivun
 *
 * @var int    $job_id  The job post ID.
 * @var string $content The original post content (editor body), already filtered.
 */

defined( 'ABSPATH' ) || exit;

$content = $content ?? '';

$company      = get_post_meta( $job_id, '_kivun_company', true );
$description  = get_post_meta( $job_id, '_kivun_description', true );
$requirements = get_post_meta( $job_id, '_kivun_requirements', true );
$salary       = get_post_meta( $job_id, '_kivun_salary', true );
$deadline     = get_post_meta( $job_id, '_kivun_deadline', true );

$regions = get_the_terms( $job_id, 'kivun_job_region' );
$region  = ( $regions && ! is_wp_error( $regions ) ) ? $regions[0]->name : '';
$scopes  = get_the_terms( $job_id, 'kivun_job_scope' );
$scope   = ( $scopes && ! is_wp_error( $scopes ) ) ? $scopes[0]->name : '';
$fields  = get_the_terms( $job_id, 'kivun_job_field' );
$field   = ( $fields && ! is_wp_error( $fields ) ) ? $fields[0]->name : '';

$has_image = has_post_thumbnail( $job_id );

// Raw title (bypasses the the_title filter that hides the duplicate theme title).
$kivun_job_post = get_post( $job_id );
$job_title      = $kivun_job_post ? $kivun_job_post->post_title : '';

// Tag chips — built from the real taxonomy terms (+ salary when present).
$tags = array();
foreach ( array( 'kivun_job_scope', 'kivun_job_region', 'kivun_job_field' ) as $kivun_tax ) {
	$kivun_terms = get_the_terms( $job_id, $kivun_tax );
	if ( $kivun_terms && ! is_wp_error( $kivun_terms ) ) {
		foreach ( $kivun_terms as $kivun_term ) {
			$tags[] = $kivun_term->name;
		}
	}
}
if ( $salary ) {
	$tags[] = $salary;
}

// Short facts row.
$info = array();
if ( $region ) {
	$info[] = $region;
}
if ( $scope ) {
	$info[] = $scope;
}
if ( $field ) {
	$info[] = $field;
}
if ( $deadline ) {
	/* translators: %s: last application date. */
	$info[] = sprintf( __( 'אחרון להגשה: %s', 'kivun' ), $deadline );
}

// Highlight summary.
$summary_src = $description ? $description : $content;
$summary     = $summary_src ? wp_trim_words( wp_strip_all_tags( $summary_src ), 30 ) : '';

// Content sections — only those that actually have data.
$sections = array();
if ( $content ) {
	$sections[] = array(
		'title' => __( 'אופי העבודה', 'kivun' ),
		'html'  => $content,
	);
}
if ( $description ) {
	$sections[] = array(
		'title' => __( 'תיאור המשרה', 'kivun' ),
		'html'  => wpautop( $description ),
	);
}
if ( $requirements ) {
	$sections[] = array(
		'title' => __( 'דרישות', 'kivun' ),
		'html'  => wpautop( $requirements ),
	);
}
?>
<section class="kivun-job-page" dir="rtl">

	<div class="kivun-job-hero<?php echo $has_image ? '' : ' kivun-job-hero--noimg'; ?>">
		<?php if ( $has_image ) : ?>
			<div class="kivun-job-hero-image">
				<?php echo get_the_post_thumbnail( $job_id, 'large', array( 'alt' => esc_attr( $job_title ) ) ); ?>
			</div>
		<?php endif; ?>
		<div class="kivun-job-hero-content">
			<?php if ( $company ) : ?>
				<div class="kivun-job-small-label">
					<?php
					/* translators: %s: company name. */
					echo esc_html( sprintf( __( 'דרושים ל%s', 'kivun' ), $company ) );
					?>
				</div>
			<?php endif; ?>
			<h1 class="kivun-job-title"><?php echo esc_html( $job_title ); ?></h1>
			<div class="kivun-job-meta-top">
				<span>
					<?php
					/* translators: %s: publish date. */
					echo esc_html( sprintf( __( 'ת. פרסום: %s', 'kivun' ), get_the_date( 'j.n.Y', $job_id ) ) );
					?>
				</span>
				<span>
					<?php
					/* translators: %d: job id number. */
					echo esc_html( sprintf( __( 'מס׳ משרה: %d', 'kivun' ), $job_id ) );
					?>
				</span>
			</div>
		</div>
	</div>

	<?php if ( $tags ) : ?>
	<div class="kivun-job-tags">
		<?php foreach ( $tags as $kivun_tag ) : ?>
			<div class="kivun-job-tag">
				<span class="kivun-job-tag-icon" aria-hidden="true"></span>
				<span><?php echo esc_html( $kivun_tag ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<section class="kivun-job-about">
		<h2 class="kivun-job-section-title"><?php esc_html_e( 'על המשרה', 'kivun' ); ?></h2>

		<?php if ( $summary ) : ?>
			<div class="kivun-job-highlight-box"><p><?php echo esc_html( $summary ); ?></p></div>
		<?php endif; ?>

		<?php if ( $info ) : ?>
			<div class="kivun-job-info-row">
				<?php foreach ( $info as $info_item ) : ?>
					<div class="kivun-job-info-item">
						<span class="kivun-job-info-icon" aria-hidden="true"></span>
						<span><?php echo esc_html( $info_item ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="kivun-job-content-wrap">
			<div class="kivun-job-content">
				<?php foreach ( $sections as $section ) : ?>
					<div class="kivun-job-content-section">
						<h3><?php echo esc_html( $section['title'] ); ?></h3>
						<div class="kivun-job-content-text"><?php echo wp_kses_post( $section['html'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<div class="kivun-job-apply-area" id="kivun-apply-<?php echo esc_attr( $job_id ); ?>">
		<h2 class="kivun-job-section-title"><?php esc_html_e( 'הגשת מועמדות', 'kivun' ); ?></h2>
		<?php kivun_get_template( 'jobs/apply-form.php', array( 'job_id' => $job_id ) ); ?>
	</div>

</section>
