<?php
/**
 * Template: employer dashboard with jobs and applications tables.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
if ( ! is_user_logged_in() || ( ! current_user_can( 'kivun_employer' ) && ! current_user_can( 'manage_options' ) ) ) {
	echo '<p class="kivun-notice">' . esc_html__( 'יש להתחבר כמעסיק כדי לגשת לאזור זה.', 'kivun' ) . '</p>';
	echo do_shortcode( '[woocommerce_my_account]' );
	return;
}

$user_id = get_current_user_id();
$jobs    = get_posts(
	array(
		'post_type'      => 'kivun_job',
		'author'         => current_user_can( 'manage_options' ) ? 0 : $user_id,
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

$scopes  = get_terms(
	array(
		'taxonomy'   => 'kivun_job_scope',
		'hide_empty' => false,
	)
);
$regions = get_terms(
	array(
		'taxonomy'   => 'kivun_job_region',
		'hide_empty' => false,
	)
);
$fields  = get_terms(
	array(
		'taxonomy'   => 'kivun_job_field',
		'hide_empty' => false,
	)
);

// Applications addressed to this employer's jobs.
$applications = Kivun_Employer::get_applications( $user_id );
$app_counts   = Kivun_Employer::application_counts( $user_id );
$app_statuses = Kivun_Employer::app_statuses();

$total_apps = count( $applications );
$new_apps   = 0;
foreach ( $applications as $a ) {
	if ( 'new' === $a->status ) {
		++$new_apps;
	}
}

$upload = wp_upload_dir();
?>
<div class="kivun-employer-dashboard" dir="rtl">

	<div class="kivun-tabs" role="tablist">
		<button type="button" class="kivun-tab is-active" data-tab="jobs" role="tab">
			<?php esc_html_e( 'המשרות שלי', 'kivun' ); ?>
		</button>
		<button type="button" class="kivun-tab" data-tab="applications" role="tab">
			<?php esc_html_e( 'הגשות מועמדות', 'kivun' ); ?>
			<?php if ( $new_apps ) : ?>
				<span class="kivun-tab-badge"><?php echo esc_html( $new_apps ); ?></span>
			<?php endif; ?>
		</button>
	</div>

	<!-- ── Jobs panel ──────────────────────────────────────────────────────── -->
	<section class="kivun-tab-panel is-active" data-panel="jobs">

		<div class="kivun-dashboard-header">
			<h2><?php esc_html_e( 'המשרות שלי', 'kivun' ); ?></h2>
			<button type="button" class="kivun-btn kivun-btn--primary" id="kivun-toggle-new-job">
				+ <?php esc_html_e( 'פרסם משרה חדשה', 'kivun' ); ?>
			</button>
		</div>

		<!-- New Job Form -->
		<div class="kivun-new-job-form" id="kivun-new-job-form" style="display:none;">
			<h3><?php esc_html_e( 'משרה חדשה', 'kivun' ); ?></h3>
			<form class="kivun-employer-form" data-action="kivun_post_job" novalidate>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'כותרת המשרה *', 'kivun' ); ?></label>
					<input type="text" name="title" required>
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'שם חברה', 'kivun' ); ?></label>
					<input type="text" name="company">
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'תיאור המשרה *', 'kivun' ); ?></label>
					<textarea name="description" rows="6" required></textarea>
				</div>

				<div class="kivun-form-grid">
					<div class="kivun-form-row">
						<label><?php esc_html_e( 'היקף משרה', 'kivun' ); ?></label>
						<select name="scope">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $scopes as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label><?php esc_html_e( 'אזור', 'kivun' ); ?></label>
						<select name="region">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $regions as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label><?php esc_html_e( 'תחום מקצועי', 'kivun' ); ?></label>
						<select name="field">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $fields as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label><?php esc_html_e( 'שכר (אופציונלי)', 'kivun' ); ?></label>
						<input type="text" name="salary" placeholder="10,000–15,000 ₪">
					</div>

					<div class="kivun-form-row">
						<label><?php esc_html_e( 'תאריך אחרון להגשה', 'kivun' ); ?></label>
						<input type="date" name="deadline">
					</div>
				</div>

				<div class="kivun-form-row">
					<label><?php esc_html_e( 'דרישות', 'kivun' ); ?></label>
					<textarea name="requirements" rows="4"></textarea>
				</div>

				<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

				<div class="kivun-form-actions">
					<button type="submit" class="kivun-btn kivun-btn--primary"><?php esc_html_e( 'פרסם משרה', 'kivun' ); ?></button>
					<button type="button" class="kivun-btn kivun-btn--outline" id="kivun-cancel-new-job"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
				</div>
			</form>
		</div>

		<!-- Jobs Table -->
		<?php if ( $jobs ) : ?>
			<table class="kivun-jobs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'כותרת', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'הגשות', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $jobs as $job ) :
					$jc = $app_counts[ $job->ID ] ?? array(
						'total' => 0,
						'new'   => 0,
					);
					?>
					<tr data-job-row="<?php echo esc_attr( $job->ID ); ?>">
						<td><?php echo esc_html( $job->post_title ); ?></td>
						<td>
							<span class="kivun-status kivun-status--<?php echo esc_attr( $job->post_status ); ?>">
								<?php
								$labels = array(
									'publish' => 'פורסם',
									'draft'   => 'טיוטה',
									'pending' => 'ממתין לאישור',
								);
								echo esc_html( $labels[ $job->post_status ] ?? $job->post_status );
								?>
							</span>
						</td>
						<td>
							<?php if ( $jc['total'] ) : ?>
								<button type="button" class="kivun-link-btn kivun-view-job-apps" data-job="<?php echo esc_attr( $job->ID ); ?>">
									<?php
									/* translators: %d: number of applications for this job. */
									echo esc_html( sprintf( _n( '%d הגשה', '%d הגשות', $jc['total'], 'kivun' ), $jc['total'] ) );
									?>
								</button>
								<?php if ( $jc['new'] ) : ?>
									<span class="kivun-tab-badge kivun-tab-badge--inline"><?php echo esc_html( $jc['new'] ); ?> <?php esc_html_e( 'חדש', 'kivun' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="kivun-muted">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( get_the_date( 'd/m/Y', $job->ID ) ); ?></td>
						<td>
							<button
								type="button"
								class="kivun-btn kivun-btn--sm kivun-delete-job"
								data-id="<?php echo esc_attr( $job->ID ); ?>"
							><?php esc_html_e( 'מחק', 'kivun' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="kivun-notice"><?php esc_html_e( 'עדיין לא פרסמת משרות.', 'kivun' ); ?></p>
		<?php endif; ?>

	</section>

	<!-- ── Applications panel ──────────────────────────────────────────────── -->
	<section class="kivun-tab-panel" data-panel="applications">

		<div class="kivun-dashboard-header">
			<h2><?php esc_html_e( 'הגשות מועמדות', 'kivun' ); ?></h2>
			<?php if ( $total_apps ) : ?>
				<a class="kivun-btn kivun-btn--outline kivun-btn--sm" href="<?php echo esc_url( Kivun_Export::url( 'applications', 0 ) ); ?>">
					⬇ <?php esc_html_e( 'ייצוא CSV', 'kivun' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php if ( ! $total_apps ) : ?>
			<p class="kivun-notice"><?php esc_html_e( 'עדיין לא התקבלו הגשות למשרות שלך.', 'kivun' ); ?></p>
		<?php else : ?>

			<div class="kivun-apps-stats">
				<span class="kivun-stat"><strong><?php echo esc_html( $total_apps ); ?></strong> <?php esc_html_e( 'סה״כ הגשות', 'kivun' ); ?></span>
				<span class="kivun-stat"><strong><?php echo esc_html( $new_apps ); ?></strong> <?php esc_html_e( 'ממתינות לטיפול', 'kivun' ); ?></span>
			</div>

			<div class="kivun-apps-filters">
				<input type="search" id="kivun-apps-search" placeholder="<?php esc_attr_e( 'חיפוש לפי שם, אימייל או טלפון…', 'kivun' ); ?>">
				<select id="kivun-apps-filter-job">
					<option value=""><?php esc_html_e( 'כל המשרות', 'kivun' ); ?></option>
					<?php
					foreach ( $jobs as $job ) :
						if ( empty( $app_counts[ $job->ID ]['total'] ) ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $job->ID ); ?>"><?php echo esc_html( $job->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="kivun-apps-filter-status">
					<option value=""><?php esc_html_e( 'כל הסטטוסים', 'kivun' ); ?></option>
					<?php foreach ( $app_statuses as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="kivun-apps-table-wrap">
			<table class="kivun-apps-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'מועמד/ת', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'משרה', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'יצירת קשר', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'מכתב מקדים', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'קו"ח', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'הערות', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
						<th><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $applications as $app ) :
					$cv_url      = ( $app->cv_file && file_exists( $app->cv_file ) )
						? str_replace( $upload['basedir'], $upload['baseurl'], $app->cv_file )
						: '';
					$search_blob = strtolower( trim( $app->applicant_name . ' ' . $app->applicant_email . ' ' . $app->applicant_phone ) );
					?>
					<tr
						class="kivun-app-row"
						data-app="<?php echo esc_attr( $app->id ); ?>"
						data-job="<?php echo esc_attr( $app->job_id ); ?>"
						data-status="<?php echo esc_attr( $app->status ); ?>"
						data-search="<?php echo esc_attr( $search_blob ); ?>"
					>
						<td><strong><?php echo esc_html( $app->applicant_name ); ?></strong></td>
						<td><?php echo esc_html( $app->job_title ); ?></td>
						<td class="kivun-app-contact">
							<a href="mailto:<?php echo esc_attr( $app->applicant_email ); ?>"><?php echo esc_html( $app->applicant_email ); ?></a>
							<?php if ( $app->applicant_phone ) : ?>
								<a href="tel:<?php echo esc_attr( $app->applicant_phone ); ?>"><?php echo esc_html( $app->applicant_phone ); ?></a>
							<?php endif; ?>
						</td>
						<td class="kivun-app-message">
							<?php if ( $app->message ) : ?>
								<span title="<?php echo esc_attr( $app->message ); ?>"><?php echo esc_html( wp_trim_words( $app->message, 10 ) ); ?></span>
							<?php else : ?>
								<span class="kivun-muted">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $cv_url ) : ?>
								<a class="kivun-btn kivun-btn--sm kivun-btn--outline" href="<?php echo esc_url( $cv_url ); ?>" target="_blank" rel="noopener">⬇ <?php esc_html_e( 'הורד', 'kivun' ); ?></a>
							<?php else : ?>
								<span class="kivun-muted">—</span>
							<?php endif; ?>
						</td>
						<td>
							<textarea
								class="kivun-app-note"
								data-app="<?php echo esc_attr( $app->id ); ?>"
								rows="2"
								placeholder="<?php esc_attr_e( 'הערה פנימית…', 'kivun' ); ?>"
							><?php echo esc_textarea( $app->notes ?? '' ); ?></textarea>
						</td>
						<td class="kivun-app-date"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $app->created_at ) ) ); ?></td>
						<td>
							<select class="kivun-app-status-select" data-app="<?php echo esc_attr( $app->id ); ?>">
								<?php foreach ( $app_statuses as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $app->status, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="kivun-saved-indicator" style="display:none"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="kivun-no-results kivun-apps-empty" style="display:none"><?php esc_html_e( 'לא נמצאו הגשות תואמות.', 'kivun' ); ?></p>

		<?php endif; ?>

	</section>

</div>
