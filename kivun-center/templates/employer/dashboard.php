<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || ( ! current_user_can( 'kivun_employer' ) && ! current_user_can( 'manage_options' ) ) ) {
	echo '<p class="kivun-notice">' . esc_html__( 'יש להתחבר כמעסיק כדי לגשת לאזור זה.', 'kivun' ) . '</p>';
	echo do_shortcode( '[woocommerce_my_account]' );
	return;
}

$user_id = get_current_user_id();
$jobs    = get_posts( [
	'post_type'      => 'kivun_job',
	'author'         => current_user_can( 'manage_options' ) ? 0 : $user_id,
	'post_status'    => [ 'publish', 'draft', 'pending' ],
	'posts_per_page' => -1,
	'orderby'        => 'date',
	'order'          => 'DESC',
] );

$scopes  = get_terms( [ 'taxonomy' => 'kivun_job_scope',  'hide_empty' => false ] );
$regions = get_terms( [ 'taxonomy' => 'kivun_job_region', 'hide_empty' => false ] );
$fields  = get_terms( [ 'taxonomy' => 'kivun_job_field',  'hide_empty' => false ] );
?>
<div class="kivun-employer-dashboard" dir="rtl">

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
					<th><?php esc_html_e( 'תאריך', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'פעולות', 'kivun' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<tr data-job-row="<?php echo esc_attr( $job->ID ); ?>">
					<td><?php echo esc_html( $job->post_title ); ?></td>
					<td>
						<span class="kivun-status kivun-status--<?php echo esc_attr( $job->post_status ); ?>">
							<?php
							$labels = [
								'publish' => 'פורסם',
								'draft'   => 'טיוטה',
								'pending' => 'ממתין לאישור',
							];
							echo esc_html( $labels[ $job->post_status ] ?? $job->post_status );
							?>
						</span>
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

</div>
