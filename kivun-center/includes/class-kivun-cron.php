<?php
/**
 * Scheduled cron tasks for the Kivun plugin.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the daily cron job that expires past-deadline jobs.
 */
class Kivun_Cron {

	/**
	 * Register cron hooks and schedule the daily event.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'kivun_daily', array( __CLASS__, 'expire_jobs' ) );

		if ( ! wp_next_scheduled( 'kivun_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'kivun_daily' );
		}
	}

	/**
	 * Clear the scheduled cron event on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'kivun_daily' );
	}

	/**
	 * Set jobs whose deadline has passed to 'draft' and notify the employer.
	 *
	 * @return void
	 */
	public static function expire_jobs(): void {
		$today = current_time( 'Y-m-d' );

		$jobs = get_posts(
			array(
				'post_type'      => 'kivun_job',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_kivun_deadline',
					'value'   => $today,
					'compare' => '<',
					'type'    => 'DATE',
				),
				),
			)
		);

		foreach ( $jobs as $job_id ) {
			wp_update_post(
				array(
					'ID'          => $job_id,
					'post_status' => 'draft',
				)
			);

			// Mark as expired so the cron won't reprocess after a manual re-publish.
			update_post_meta( $job_id, '_kivun_expired', '1' );

			self::notify_employer( $job_id );
		}
	}

	/**
	 * Email the employer that their job posting has expired.
	 *
	 * @param int $job_id The expired job post ID.
	 * @return void
	 */
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
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
