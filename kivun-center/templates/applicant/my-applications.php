<?php
/**
 * Template: applicant's "my applications" personal area.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	echo '<p class="kivun-notice">' . esc_html__( 'יש להתחבר כדי לצפות בהגשות שלך.', 'kivun' ) . '</p>';
	echo do_shortcode( '[woocommerce_my_account]' );
	return;
}

global $wpdb;

$user_id = get_current_user_id();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT a.*, p.post_title AS job_title
	 FROM {$wpdb->prefix}kivun_applications a
	 LEFT JOIN {$wpdb->posts} p ON p.ID = a.job_id
	 WHERE a.user_id = %d
	 ORDER BY a.created_at DESC",
		$user_id
	)
);

$status_labels = array(
	'new'       => array(
		'label' => 'ממתין',
		'class' => 'pending',
	),
	'viewed'    => array(
		'label' => 'נצפה',
		'class' => 'viewed',
	),
	'contacted' => array(
		'label' => 'נוצר קשר',
		'class' => 'contacted',
	),
	'interview' => array(
		'label' => 'הוזמנת לראיון 🎉',
		'class' => 'interview',
	),
	'hired'     => array(
		'label' => 'גויסת ✓',
		'class' => 'hired',
	),
	'rejected'  => array(
		'label' => 'לא מתאים',
		'class' => 'rejected',
	),
);
?>
<div class="kivun-my-applications" dir="rtl">
	<h2><?php esc_html_e( 'הגשות המועמדות שלי', 'kivun' ); ?></h2>

	<?php if ( ! $rows ) : ?>
		<p class="kivun-notice"><?php esc_html_e( 'עדיין לא הגשת מועמדות לאף משרה.', 'kivun' ); ?></p>
	<?php else : ?>
		<table class="kivun-my-apps-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'משרה', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'תאריך הגשה', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'קו"ח', 'kivun' ); ?></th>
					<th><?php esc_html_e( 'סטטוס', 'kivun' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $r ) :
				$status_info = $status_labels[ $r->status ] ?? array(
					'label' => $r->status,
					'class' => '',
				);
				$cv_url      = ( $r->cv_file && file_exists( $r->cv_file ) )
					? Kivun_Jobs::cv_url( (int) $r->id )
					: '';
				?>
				<tr>
					<td>
						<?php if ( 'publish' === get_post_status( $r->job_id ) ) : ?>
							<a href="<?php echo esc_url( get_permalink( $r->job_id ) ); ?>"><?php echo esc_html( $r->job_title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $r->job_title ); ?>
							<small class="kivun-muted"><?php esc_html_e( '(הוסרה)', 'kivun' ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $r->created_at ) ) ); ?></td>
					<td>
						<?php if ( $cv_url ) : ?>
							<a href="<?php echo esc_url( $cv_url ); ?>" target="_blank"><?php esc_html_e( 'הורד', 'kivun' ); ?></a>
						<?php else : ?>
							<span class="kivun-muted">—</span>
						<?php endif; ?>
					</td>
					<td>
						<span class="kivun-app-status kivun-app-status--<?php echo esc_attr( $status_info['class'] ); ?>">
							<?php echo esc_html( $status_info['label'] ); ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
