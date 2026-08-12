<?php
/**
 * Template: employer dashboard with jobs and applications tables.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	$kivun_redirect = ( is_singular() && get_permalink() ) ? get_permalink() : home_url( add_query_arg( null, null ) );
	?>
	<div class="kivun-employer-login" dir="rtl">
		<h2 class="kivun-employer-login__title"><?php esc_html_e( 'התחברות מעסיקים', 'kivun' ); ?></h2>
		<p class="kivun-notice"><?php esc_html_e( 'יש להתחבר כדי לגשת לאזור ניהול המשרות.', 'kivun' ); ?></p>
		<?php
		wp_login_form(
			array(
				'redirect'       => $kivun_redirect,
				'label_username' => __( 'אימייל / שם משתמש', 'kivun' ),
				'label_password' => __( 'סיסמה', 'kivun' ),
				'label_remember' => __( 'זכור אותי', 'kivun' ),
				'label_log_in'   => __( 'התחברות', 'kivun' ),
			)
		);
		?>
		<p class="kivun-employer-login__links">
			<a href="<?php echo esc_url( wp_lostpassword_url( $kivun_redirect ) ); ?>"><?php esc_html_e( 'שכחתי סיסמה', 'kivun' ); ?></a>
		</p>
	</div>
	<?php
	return;
}

// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
if ( ! current_user_can( 'kivun_employer' ) && ! current_user_can( 'manage_options' ) ) {
	echo '<p class="kivun-notice">' . esc_html__( 'החשבון שלך אינו משויך כמעסיק. פנה למנהל המערכת לקבלת הרשאה.', 'kivun' ) . '</p>';
	return;
}

$user_id    = get_current_user_id();
$is_manager = Kivun_Employer::can_manage_all();

// Which publisher the manager is currently acting on behalf of (0 = whole
// board). Read-only view scoping — no state change, so no nonce needed.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$acting_as = ( $is_manager && isset( $_GET['kivun_as'] ) ) ? Kivun_Employer::acting_as( absint( wp_unslash( $_GET['kivun_as'] ) ) ) : 0;
// All publishers for the management tab and the act-as switcher (a disabled
// publisher's history stays reachable); only active ones may receive new jobs.
$employers        = $is_manager ? Kivun_Employer::get_employers() : array();
$active_employers = $is_manager ? Kivun_Employer::get_employers( false ) : array();

// Human label for the publisher the manager is acting as — used by the banner
// and as the pre-selected value of the "post on behalf" selector.
$acting_label = '';
if ( $acting_as ) {
	$acting_user  = get_userdata( $acting_as );
	$acting_comp  = (string) get_user_meta( $acting_as, '_kivun_company', true );
	$acting_label = $acting_user
		? trim( ( $acting_comp ? $acting_comp . ' — ' : '' ) . $acting_user->display_name )
		: '';
}

$jobs = get_posts(
	array(
		'post_type'      => 'kivun_job',
		'author'         => $is_manager ? $acting_as : $user_id,
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => 100,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
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

// Applications addressed to this employer's jobs (scoped to the acting-as
// publisher when a manager has selected one).
$applications = Kivun_Employer::get_applications( $user_id, $acting_as );
$app_counts   = Kivun_Employer::application_counts( $user_id, $acting_as );
$app_statuses = Kivun_Employer::app_statuses();

$total_apps = count( $applications );
$new_apps   = 0;
foreach ( $applications as $a ) {
	if ( 'new' === $a->status ) {
		++$new_apps;
	}
}

$published_jobs = 0;
foreach ( $jobs as $j ) {
	if ( 'publish' === $j->post_status ) {
		++$published_jobs;
	}
}
?>
<div class="kivun-employer-dashboard" dir="rtl"<?php echo $is_manager ? ' data-manager="1"' : ''; ?>>

	<?php if ( $is_manager ) : ?>
		<div class="kivun-mgr-bar">
			<div class="kivun-mgr-as">
				<label for="kivun-as-select"><?php esc_html_e( 'פעולה בשם מפרסם:', 'kivun' ); ?></label>
				<select id="kivun-as-select">
					<option value="0"><?php esc_html_e( 'כל המפרסמים (תצוגת מנהל)', 'kivun' ); ?></option>
					<?php
					foreach ( $employers as $emp ) :
						$emp_comp  = (string) get_user_meta( $emp->ID, '_kivun_company', true );
						$emp_label = trim( ( $emp_comp ? $emp_comp . ' — ' : '' ) . $emp->display_name );
						?>
						<option value="<?php echo esc_attr( $emp->ID ); ?>" <?php selected( $acting_as, $emp->ID ); ?>><?php echo esc_html( $emp_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="button" class="kivun-btn kivun-btn--outline kivun-btn--sm" id="kivun-add-employer">
				+ <?php esc_html_e( 'מפרסם חדש', 'kivun' ); ?>
			</button>
		</div>

		<?php if ( $acting_as ) : ?>
			<div class="kivun-acting-banner">
				<span><?php esc_html_e( 'פועל/ת בשם:', 'kivun' ); ?> <strong><?php echo esc_html( $acting_label ); ?></strong></span>
				<a class="kivun-acting-exit" href="<?php echo esc_url( remove_query_arg( 'kivun_as' ) ); ?>"><?php esc_html_e( 'יציאה ↩', 'kivun' ); ?></a>
			</div>
		<?php endif; ?>

		<!-- Create-publisher form (managers only) -->
		<div class="kivun-add-employer-form" id="kivun-add-employer-form" hidden>
			<h3><?php esc_html_e( 'הוספת מפרסם חדש', 'kivun' ); ?></h3>
			<form class="kivun-create-employer-form" novalidate>
				<div class="kivun-form-grid">
					<div class="kivun-form-row">
						<label for="kivun-ce-name"><?php esc_html_e( 'שם איש קשר *', 'kivun' ); ?></label>
						<input type="text" id="kivun-ce-name" name="display_name" required>
					</div>
					<div class="kivun-form-row">
						<label for="kivun-ce-company"><?php esc_html_e( 'שם חברה / ארגון *', 'kivun' ); ?></label>
						<input type="text" id="kivun-ce-company" name="company" required>
					</div>
					<div class="kivun-form-row">
						<label for="kivun-ce-email"><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
						<input type="email" id="kivun-ce-email" name="email" dir="ltr" required>
						<p class="kivun-field-hint"><?php esc_html_e( 'לכתובת זו יישלחו התראות על הגשות מועמדות חדשות.', 'kivun' ); ?></p>
					</div>
					<div class="kivun-form-row">
						<label for="kivun-ce-phone"><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
						<input type="tel" id="kivun-ce-phone" name="phone" dir="ltr">
					</div>
				</div>
				<label class="kivun-check-inline">
					<input type="checkbox" name="send_login" value="1">
					<?php esc_html_e( 'שליחת מייל למפרסם להגדרת סיסמה (כדי שיוכל להתחבר ולנהל בעצמו). כברירת מחדל כבוי — הניהול נעשה על ידך.', 'kivun' ); ?>
				</label>
				<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>
				<div class="kivun-form-actions">
					<button type="submit" class="kivun-btn kivun-btn--primary"><?php esc_html_e( 'הוספת מפרסם', 'kivun' ); ?></button>
					<button type="button" class="kivun-btn kivun-btn--outline" id="kivun-cancel-add-employer"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
				</div>
			</form>
		</div>
	<?php endif; ?>

	<div class="kivun-tabs" role="tablist" aria-label="<?php esc_attr_e( 'ניהול אזור מעסיק', 'kivun' ); ?>">
		<button type="button" class="kivun-tab is-active" data-tab="jobs" role="tab" id="kivun-tab-jobs" aria-controls="kivun-panel-jobs" aria-selected="true">
			<?php esc_html_e( 'המשרות שלי', 'kivun' ); ?>
		</button>
		<button type="button" class="kivun-tab" data-tab="applications" role="tab" id="kivun-tab-applications" aria-controls="kivun-panel-applications" aria-selected="false" tabindex="-1">
			<?php esc_html_e( 'הגשות מועמדות', 'kivun' ); ?>
			<?php if ( $new_apps ) : ?>
				<span class="kivun-tab-badge"><?php echo esc_html( $new_apps ); ?><span class="kivun-sr-only"> <?php esc_html_e( 'הגשות חדשות', 'kivun' ); ?></span></span>
			<?php endif; ?>
		</button>
		<?php if ( $is_manager ) : ?>
			<button type="button" class="kivun-tab" data-tab="publishers" role="tab" id="kivun-tab-publishers" aria-controls="kivun-panel-publishers" aria-selected="false" tabindex="-1">
				<?php esc_html_e( 'מפרסמים', 'kivun' ); ?>
				<span class="kivun-tab-badge kivun-tab-badge--soft"><?php echo esc_html( (string) count( $employers ) ); ?></span>
			</button>
		<?php endif; ?>
	</div>

	<!-- ── Jobs panel ──────────────────────────────────────────────────────── -->
	<section class="kivun-tab-panel is-active" data-panel="jobs" id="kivun-panel-jobs" role="tabpanel" aria-labelledby="kivun-tab-jobs" tabindex="0">

		<div class="kivun-dashboard-header">
			<h2><?php esc_html_e( 'המשרות שלי', 'kivun' ); ?></h2>
			<button type="button" class="kivun-btn kivun-btn--primary" id="kivun-toggle-new-job">
				+ <?php esc_html_e( 'פרסם משרה חדשה', 'kivun' ); ?>
			</button>
		</div>

		<?php if ( $jobs ) : ?>
			<div class="kivun-apps-stats">
				<span class="kivun-stat"><strong><?php echo esc_html( count( $jobs ) ); ?></strong> <?php esc_html_e( 'סה״כ משרות', 'kivun' ); ?></span>
				<span class="kivun-stat"><strong><?php echo esc_html( $published_jobs ); ?></strong> <?php esc_html_e( 'משרות פעילות', 'kivun' ); ?></span>
				<span class="kivun-stat"><strong><?php echo esc_html( $total_apps ); ?></strong> <?php esc_html_e( 'סה״כ הגשות', 'kivun' ); ?></span>
			</div>
		<?php endif; ?>

		<!-- New / Edit Job Form -->
		<div class="kivun-new-job-form" id="kivun-new-job-form" style="display:none;">
			<h3
				id="kivun-new-job-heading"
				data-new="<?php esc_attr_e( 'משרה חדשה', 'kivun' ); ?>"
				data-edit="<?php esc_attr_e( 'עריכת משרה', 'kivun' ); ?>"
			><?php esc_html_e( 'משרה חדשה', 'kivun' ); ?></h3>
			<form class="kivun-employer-form" data-action="kivun_post_job" novalidate>
				<input type="hidden" name="job_id" value="">

				<?php if ( $is_manager ) : ?>
					<div class="kivun-form-row kivun-mgr-only" data-default-employer="<?php echo esc_attr( $acting_as ); ?>">
						<label for="kivun-f-employer"><?php esc_html_e( 'פרסום בשם מפרסם *', 'kivun' ); ?></label>
						<select id="kivun-f-employer" name="employer_id" required>
							<option value=""><?php esc_html_e( '— בחר/י מפרסם —', 'kivun' ); ?></option>
							<?php
							foreach ( $active_employers as $emp ) :
								$emp_comp  = (string) get_user_meta( $emp->ID, '_kivun_company', true );
								$emp_label = trim( ( $emp_comp ? $emp_comp . ' — ' : '' ) . $emp->display_name );
								?>
								<option value="<?php echo esc_attr( $emp->ID ); ?>" <?php selected( $acting_as, $emp->ID ); ?>><?php echo esc_html( $emp_label ); ?></option>
								<?php
							endforeach;

							// Disabled publishers can't receive new jobs, but must stay
							// selectable so editing one of their existing jobs round-trips.
							$active_ids = wp_list_pluck( $active_employers, 'ID' );
							foreach ( $employers as $emp ) :
								if ( in_array( $emp->ID, $active_ids, true ) ) {
									continue;
								}
								$emp_comp  = (string) get_user_meta( $emp->ID, '_kivun_company', true );
								$emp_label = trim( ( $emp_comp ? $emp_comp . ' — ' : '' ) . $emp->display_name );
								?>
								<option value="<?php echo esc_attr( $emp->ID ); ?>"><?php echo esc_html( $emp_label . ' (' . __( 'מושבת', 'kivun' ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="kivun-field-hint"><?php esc_html_e( 'המשרה תשויך למפרסם זה, וההגשות יישלחו לכתובת המייל שלו.', 'kivun' ); ?></p>
					</div>
				<?php endif; ?>

				<div class="kivun-form-row">
					<label for="kivun-f-title"><?php esc_html_e( 'כותרת המשרה *', 'kivun' ); ?></label>
					<input type="text" id="kivun-f-title" name="title" required>
				</div>

				<div class="kivun-form-row">
					<label for="kivun-f-company"><?php esc_html_e( 'שם חברה', 'kivun' ); ?></label>
					<input type="text" id="kivun-f-company" name="company">
				</div>

				<div class="kivun-form-row">
					<label for="kivunjobdesc"><?php esc_html_e( 'תיאור המשרה *', 'kivun' ); ?></label>
					<?php
					wp_editor(
						'',
						'kivunjobdesc',
						array(
							'textarea_name' => 'description',
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => false,
							'textarea_rows' => 8,
						)
					);
					?>
				</div>

				<div class="kivun-form-grid">
					<div class="kivun-form-row">
						<label for="kivun-f-scope"><?php esc_html_e( 'היקף משרה', 'kivun' ); ?></label>
						<select id="kivun-f-scope" name="scope">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $scopes as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label for="kivun-f-region"><?php esc_html_e( 'אזור', 'kivun' ); ?></label>
						<select id="kivun-f-region" name="region">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $regions as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label for="kivun-f-field"><?php esc_html_e( 'תחום מקצועי', 'kivun' ); ?></label>
						<select id="kivun-f-field" name="field">
							<option value=""><?php esc_html_e( 'בחר', 'kivun' ); ?></option>
							<?php foreach ( $fields as $t ) : ?>
								<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="kivun-form-row">
						<label for="kivun-f-salary"><?php esc_html_e( 'שכר (אופציונלי)', 'kivun' ); ?></label>
						<input type="text" id="kivun-f-salary" name="salary" placeholder="10,000–15,000 ₪">
					</div>

					<div class="kivun-form-row">
						<label for="kivun-f-deadline"><?php esc_html_e( 'תאריך סגירה (אופציונלי)', 'kivun' ); ?></label>
						<input type="date" id="kivun-f-deadline" name="deadline" dir="ltr" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
						<p class="kivun-field-hint"><?php esc_html_e( 'לאחר תאריך זה המשרה תרד מהאתר אוטומטית. ריק = ללא הגבלת זמן.', 'kivun' ); ?></p>
					</div>
				</div>

				<div class="kivun-form-row">
					<label for="kivunjobreq"><?php esc_html_e( 'דרישות', 'kivun' ); ?></label>
					<?php
					wp_editor(
						'',
						'kivunjobreq',
						array(
							'textarea_name' => 'requirements',
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => false,
							'textarea_rows' => 6,
						)
					);
					?>
				</div>

				<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

				<div class="kivun-form-actions">
					<button
						type="submit"
						class="kivun-btn kivun-btn--primary"
						id="kivun-new-job-submit"
						data-new="<?php esc_attr_e( 'פרסם משרה', 'kivun' ); ?>"
						data-edit="<?php esc_attr_e( 'שמירת שינויים', 'kivun' ); ?>"
					><?php esc_html_e( 'פרסם משרה', 'kivun' ); ?></button>
					<button type="button" class="kivun-btn kivun-btn--outline" id="kivun-cancel-new-job"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
				</div>
			</form>
		</div>

		<!-- Jobs Table -->
		<?php if ( $jobs ) : ?>
			<div class="kivun-jobs-table-wrap">
			<table class="kivun-jobs-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'מס׳ משרה', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'כותרת', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'הגשות', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סגירה', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $jobs as $job ) :
					$jc = $app_counts[ $job->ID ] ?? array(
						'total' => 0,
						'new'   => 0,
					);

					$kivun_scope_t  = get_the_terms( $job->ID, 'kivun_job_scope' );
					$kivun_region_t = get_the_terms( $job->ID, 'kivun_job_region' );
					$kivun_field_t  = get_the_terms( $job->ID, 'kivun_job_field' );

					$kivun_deadline = (string) get_post_meta( $job->ID, '_kivun_deadline', true );
					$kivun_expired  = (bool) get_post_meta( $job->ID, '_kivun_expired', true );
					?>
					<tr
						data-job-row="<?php echo esc_attr( $job->ID ); ?>"
						data-employer="<?php echo esc_attr( $job->post_author ); ?>"
						data-title="<?php echo esc_attr( $job->post_title ); ?>"
						data-company="<?php echo esc_attr( get_post_meta( $job->ID, '_kivun_company', true ) ); ?>"
						data-salary="<?php echo esc_attr( get_post_meta( $job->ID, '_kivun_salary', true ) ); ?>"
						data-scope="<?php echo esc_attr( ( $kivun_scope_t && ! is_wp_error( $kivun_scope_t ) ) ? $kivun_scope_t[0]->name : '' ); ?>"
						data-region="<?php echo esc_attr( ( $kivun_region_t && ! is_wp_error( $kivun_region_t ) ) ? $kivun_region_t[0]->name : '' ); ?>"
						data-field="<?php echo esc_attr( ( $kivun_field_t && ! is_wp_error( $kivun_field_t ) ) ? $kivun_field_t[0]->name : '' ); ?>"
						data-description="<?php echo esc_attr( get_post_meta( $job->ID, '_kivun_description', true ) ); ?>"
						data-requirements="<?php echo esc_attr( get_post_meta( $job->ID, '_kivun_requirements', true ) ); ?>"
						data-deadline="<?php echo esc_attr( $kivun_deadline ); ?>"
					>
						<td><span class="kivun-job-id">#<?php echo esc_html( (string) $job->ID ); ?></span></td>
						<td>
							<?php echo esc_html( $job->post_title ); ?>
							<?php
							if ( $is_manager && ! $acting_as ) :
								$owner_comp = (string) get_user_meta( $job->post_author, '_kivun_company', true );
								$owner_name = get_the_author_meta( 'display_name', $job->post_author );
								$owner_lbl  = trim( ( $owner_comp ? $owner_comp . ' — ' : '' ) . $owner_name );
								if ( $owner_lbl ) :
									?>
									<span class="kivun-row-owner"><?php echo esc_html( $owner_lbl ); ?></span>
									<?php
								endif;
							endif;
							?>
						</td>
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
								<span class="kivun-muted" aria-hidden="true">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( get_the_date( 'd/m/Y', $job->ID ) ); ?></td>
						<td class="kivun-job-deadline">
							<?php if ( $kivun_deadline ) : ?>
								<span class="<?php echo $kivun_expired ? 'kivun-deadline-past' : ''; ?>">
									<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $kivun_deadline ) ) ); ?>
								</span>
								<?php if ( $kivun_expired ) : ?>
									<span class="kivun-expired-tag"><?php esc_html_e( 'פג תוקף', 'kivun' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="kivun-muted" aria-hidden="true">—</span>
							<?php endif; ?>
						</td>
						<td>
							<button
								type="button"
								class="kivun-btn kivun-btn--sm kivun-btn--outline kivun-edit-job"
								data-id="<?php echo esc_attr( $job->ID ); ?>"
							><?php esc_html_e( 'ערוך', 'kivun' ); ?></button>
							<?php if ( $kivun_expired || 'publish' !== $job->post_status ) : ?>
								<button
									type="button"
									class="kivun-btn kivun-btn--sm kivun-renew-job"
									data-id="<?php echo esc_attr( $job->ID ); ?>"
								><?php esc_html_e( 'חידוש', 'kivun' ); ?></button>
							<?php endif; ?>
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
			</div>
		<?php else : ?>
			<p class="kivun-notice"><?php esc_html_e( 'עדיין לא פרסמת משרות.', 'kivun' ); ?></p>
		<?php endif; ?>

	</section>

	<!-- ── Applications panel ──────────────────────────────────────────────── -->
	<section class="kivun-tab-panel" data-panel="applications" id="kivun-panel-applications" role="tabpanel" aria-labelledby="kivun-tab-applications" tabindex="0" hidden>

		<div class="kivun-dashboard-header">
			<h2><?php esc_html_e( 'הגשות מועמדות', 'kivun' ); ?></h2>
			<?php if ( $total_apps ) : ?>
				<a class="kivun-btn kivun-btn--outline kivun-btn--sm" href="<?php echo esc_url( Kivun_Export::url( 'applications', 0, $acting_as ) ); ?>">
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
				<div class="kivun-form-row">
					<label class="kivun-sr-only" for="kivun-apps-search"><?php esc_html_e( 'חיפוש הגשות', 'kivun' ); ?></label>
					<input type="search" id="kivun-apps-search" placeholder="<?php esc_attr_e( 'חיפוש לפי שם, אימייל או טלפון…', 'kivun' ); ?>">
				</div>
				<div class="kivun-form-row">
					<label class="kivun-sr-only" for="kivun-apps-filter-job"><?php esc_html_e( 'סינון לפי משרה', 'kivun' ); ?></label>
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
				</div>
				<div class="kivun-form-row">
					<label class="kivun-sr-only" for="kivun-apps-filter-status"><?php esc_html_e( 'סינון לפי סטטוס', 'kivun' ); ?></label>
					<select id="kivun-apps-filter-status">
						<option value=""><?php esc_html_e( 'כל הסטטוסים', 'kivun' ); ?></option>
						<?php foreach ( $app_statuses as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="kivun-apps-table-wrap">
			<table class="kivun-apps-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'מועמד/ת', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'משרה', 'kivun' ); ?></th>
						<?php if ( $is_manager && ! $acting_as ) : ?>
							<th scope="col"><?php esc_html_e( 'מפרסם', 'kivun' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'יצירת קשר', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'מכתב מקדים', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'קו"ח', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'הערות', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
						<th scope="col"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $applications as $app ) :
					$cv_url      = ( $app->cv_file && file_exists( $app->cv_file ) )
						? Kivun_Jobs::cv_url( (int) $app->id )
						: '';
					$search_blob = strtolower( trim( $app->applicant_name . ' ' . $app->applicant_email . ' ' . $app->applicant_phone ) );
					// Managers can also search applications by publisher/company.
					if ( $is_manager && ! $acting_as ) {
						$search_blob .= ' ' . strtolower(
							trim(
								get_user_meta( $app->job_author, '_kivun_company', true ) . ' ' .
								get_the_author_meta( 'display_name', $app->job_author )
							)
						);
					}
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
						<?php if ( $is_manager && ! $acting_as ) : ?>
							<td class="kivun-app-owner">
								<?php
								$app_comp = (string) get_user_meta( $app->job_author, '_kivun_company', true );
								$app_name = get_the_author_meta( 'display_name', $app->job_author );
								$app_lbl  = trim( $app_comp ? $app_comp : $app_name );
								echo $app_lbl ? esc_html( $app_lbl ) : '<span class="kivun-muted" aria-hidden="true">—</span>';
								?>
							</td>
						<?php endif; ?>
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
								<span class="kivun-muted" aria-hidden="true">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $cv_url ) : ?>
								<a class="kivun-btn kivun-btn--sm kivun-btn--outline" href="<?php echo esc_url( $cv_url ); ?>" target="_blank" rel="noopener">
									<span aria-hidden="true">⬇</span>
									<?php
									/* translators: %s: applicant name. */
									echo esc_html( sprintf( __( 'הורדת קו"ח של %s', 'kivun' ), $app->applicant_name ) );
									?>
								</a>
							<?php else : ?>
								<span class="kivun-muted" aria-hidden="true">—</span>
							<?php endif; ?>
						</td>
						<td>
							<label class="kivun-sr-only" for="kivun-note-<?php echo esc_attr( $app->id ); ?>">
								<?php
								/* translators: %s: applicant name. */
								echo esc_html( sprintf( __( 'הערה פנימית עבור %s', 'kivun' ), $app->applicant_name ) );
								?>
							</label>
							<textarea
								class="kivun-app-note"
								id="kivun-note-<?php echo esc_attr( $app->id ); ?>"
								data-app="<?php echo esc_attr( $app->id ); ?>"
								rows="2"
								placeholder="<?php esc_attr_e( 'הערה פנימית…', 'kivun' ); ?>"
							><?php echo esc_textarea( $app->notes ?? '' ); ?></textarea>
						</td>
						<td class="kivun-app-date"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $app->created_at ) ) ); ?></td>
						<td>
							<label class="kivun-sr-only" for="kivun-status-<?php echo esc_attr( $app->id ); ?>">
								<?php
								/* translators: %s: applicant name. */
								echo esc_html( sprintf( __( 'עדכון סטטוס מועמדות של %s', 'kivun' ), $app->applicant_name ) );
								?>
							</label>
							<select class="kivun-app-status-select" id="kivun-status-<?php echo esc_attr( $app->id ); ?>" data-app="<?php echo esc_attr( $app->id ); ?>">
								<?php foreach ( $app_statuses as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>"<?php selected( $app->status, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="kivun-no-results kivun-apps-empty" style="display:none"><?php esc_html_e( 'לא נמצאו הגשות תואמות.', 'kivun' ); ?></p>

		<?php endif; ?>

	</section>

	<?php if ( $is_manager ) : ?>
		<!-- ── Publishers panel (managers only) ────────────────────────────── -->
		<section class="kivun-tab-panel" data-panel="publishers" id="kivun-panel-publishers" role="tabpanel" aria-labelledby="kivun-tab-publishers" tabindex="0" hidden>

			<div class="kivun-dashboard-header">
				<h2><?php esc_html_e( 'מפרסמים', 'kivun' ); ?></h2>
				<button type="button" class="kivun-btn kivun-btn--primary kivun-btn--sm" id="kivun-add-employer-2">
					+ <?php esc_html_e( 'מפרסם חדש', 'kivun' ); ?>
				</button>
			</div>

			<?php if ( ! $employers ) : ?>
				<p class="kivun-notice"><?php esc_html_e( 'עדיין אין מפרסמים. הוסף/י את הראשון בעזרת הכפתור למעלה.', 'kivun' ); ?></p>
			<?php else : ?>
				<div class="kivun-jobs-table-wrap">
				<table class="kivun-jobs-table kivun-publishers-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'חברה / ארגון', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'איש קשר', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'אימייל להתראות', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'טלפון', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'משרות', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
							<th scope="col"><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $employers as $emp ) :
						$emp_company  = (string) get_user_meta( $emp->ID, '_kivun_company', true );
						$emp_phone    = (string) get_user_meta( $emp->ID, '_kivun_phone', true );
						$emp_disabled = (bool) get_user_meta( $emp->ID, '_kivun_disabled', true );
						$emp_jobs     = count(
							get_posts(
								array(
									'post_type'      => 'kivun_job',
									'author'         => $emp->ID,
									'post_status'    => array( 'publish', 'draft', 'pending' ),
									'posts_per_page' => -1,
									'fields'         => 'ids',
									'no_found_rows'  => true,
								)
							)
						);
						?>
						<tr
							data-employer-row="<?php echo esc_attr( $emp->ID ); ?>"
							data-name="<?php echo esc_attr( $emp->display_name ); ?>"
							data-company="<?php echo esc_attr( $emp_company ); ?>"
							data-email="<?php echo esc_attr( $emp->user_email ); ?>"
							data-phone="<?php echo esc_attr( $emp_phone ); ?>"
							class="<?php echo $emp_disabled ? 'kivun-row-disabled' : ''; ?>"
						>
							<td><strong><?php echo esc_html( $emp_company ? $emp_company : '—' ); ?></strong></td>
							<td><?php echo esc_html( $emp->display_name ); ?></td>
							<td dir="ltr"><a href="mailto:<?php echo esc_attr( $emp->user_email ); ?>"><?php echo esc_html( $emp->user_email ); ?></a></td>
							<td dir="ltr"><?php echo $emp_phone ? esc_html( $emp_phone ) : '<span class="kivun-muted" aria-hidden="true">—</span>'; ?></td>
							<td><?php echo esc_html( (string) $emp_jobs ); ?></td>
							<td>
								<span class="kivun-status kivun-status--<?php echo $emp_disabled ? 'draft' : 'publish'; ?>">
									<?php echo $emp_disabled ? esc_html__( 'מושבת', 'kivun' ) : esc_html__( 'פעיל', 'kivun' ); ?>
								</span>
							</td>
							<td class="kivun-emp-actions">
								<?php if ( ! $emp_disabled ) : ?>
									<a class="kivun-btn kivun-btn--sm kivun-btn--outline" href="<?php echo esc_url( add_query_arg( 'kivun_as', $emp->ID ) ); ?>"><?php esc_html_e( 'פעל בשמו', 'kivun' ); ?></a>
								<?php endif; ?>
								<button type="button" class="kivun-btn kivun-btn--sm kivun-btn--outline kivun-edit-employer" data-id="<?php echo esc_attr( $emp->ID ); ?>"><?php esc_html_e( 'ערוך', 'kivun' ); ?></button>
								<?php if ( ! $emp_disabled ) : ?>
									<button type="button" class="kivun-btn kivun-btn--sm kivun-btn--outline kivun-send-login" data-id="<?php echo esc_attr( $emp->ID ); ?>"><?php esc_html_e( 'שלח גישה', 'kivun' ); ?></button>
								<?php endif; ?>
								<button
									type="button"
									class="kivun-btn kivun-btn--sm <?php echo $emp_disabled ? 'kivun-btn--outline' : ''; ?> kivun-toggle-employer"
									data-id="<?php echo esc_attr( $emp->ID ); ?>"
									data-disable="<?php echo $emp_disabled ? '0' : '1'; ?>"
								><?php echo $emp_disabled ? esc_html__( 'הפעלה', 'kivun' ) : esc_html__( 'השבתה', 'kivun' ); ?></button>
								<span class="kivun-saved-indicator" role="status" aria-live="polite" style="display:none"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="kivun-field-hint"><?php esc_html_e( 'השבתת מפרסם חוסמת ממנו התחברות ומסירה אותו מרשימת הפרסום — המשרות וההגשות שלו נשמרות במלואן.', 'kivun' ); ?></p>
			<?php endif; ?>

			<!-- Inline edit form for an existing publisher -->
			<div class="kivun-add-employer-form" id="kivun-edit-employer-form" hidden>
				<h3><?php esc_html_e( 'עריכת פרטי מפרסם', 'kivun' ); ?></h3>
				<form class="kivun-update-employer-form" novalidate>
					<input type="hidden" name="employer_id" value="">
					<div class="kivun-form-grid">
						<div class="kivun-form-row">
							<label for="kivun-ee-name"><?php esc_html_e( 'שם איש קשר *', 'kivun' ); ?></label>
							<input type="text" id="kivun-ee-name" name="display_name" required>
						</div>
						<div class="kivun-form-row">
							<label for="kivun-ee-company"><?php esc_html_e( 'שם חברה / ארגון *', 'kivun' ); ?></label>
							<input type="text" id="kivun-ee-company" name="company" required>
						</div>
						<div class="kivun-form-row">
							<label for="kivun-ee-email"><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
							<input type="email" id="kivun-ee-email" name="email" dir="ltr" required>
							<p class="kivun-field-hint"><?php esc_html_e( 'שינוי הכתובת יעדכן גם את כל המשרות הקיימות של המפרסם.', 'kivun' ); ?></p>
						</div>
						<div class="kivun-form-row">
							<label for="kivun-ee-phone"><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
							<input type="tel" id="kivun-ee-phone" name="phone" dir="ltr">
						</div>
					</div>
					<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>
					<div class="kivun-form-actions">
						<button type="submit" class="kivun-btn kivun-btn--primary"><?php esc_html_e( 'שמירת שינויים', 'kivun' ); ?></button>
						<button type="button" class="kivun-btn kivun-btn--outline" id="kivun-cancel-edit-employer"><?php esc_html_e( 'ביטול', 'kivun' ); ?></button>
					</div>
				</form>
			</div>

		</section>
	<?php endif; ?>

</div>
