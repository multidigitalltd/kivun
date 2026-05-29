<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Cron {

	public static function init(): void {
		add_action( 'kivun_daily', [ __CLASS__, 'expire_jobs' ] );

		if ( ! wp_next_scheduled( 'kivun_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'kivun_daily' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'kivun_daily' );
	}

	/**
	 * Set jobs whose deadline has passed to 'draft' and notify the employer.
	 */
	public static function expire_jobs(): void {
		$today = current_time( 'Y-m-d' );

		$jobs = get_posts( [
			'post_type'      => 'kivun_job',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'key'     => '_kivun_deadline',
					'value'   => $today,
					'compare' => '<',
					'type'    => 'DATE',
				],
			],
		] );

		foreach ( $jobs as $job_id ) {
			wp_update_post( [
				'ID'          => $job_id,
				'post_status' => 'draft',
			] );

			// Mark as expired so the cron won't reprocess after a manual re-publish
			update_post_meta( $job_id, '_kivun_expired', '1' );

			self::notify_employer( $job_id );
		}
	}

	private static function notify_employer( int $job_id ): void {
		$email = get_post_meta( $job_id, '_kivun_employer_email', true );
		if ( ! $email ) {
			return;
		}

		$title     = get_the_title( $job_id );
		$site      = get_bloginfo( 'name' );
		$admin_url = admin_url( 'post.php?post=' . $job_id . '&action=edit' );

		wp_mail(
			$email,
			sprintf( '[%s] המשרה "%s" פגה תוקף', $site, $title ),
			sprintf(
				'<p>שלום,</p>
				<p>המשרה <strong>%s</strong> הוסרה מהאתר כיוון שעבר תאריך הגשת המועמדויות שהגדרת.</p>
				<p>כדי לפרסם מחדש עם תאריך חדש: <a href="%s">לחץ כאן לעריכה</a></p>
				<p>בברכה,<br>%s</p>',
				esc_html( $title ),
				esc_url( $admin_url ),
				esc_html( $site )
			),
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}
}
