<?php
/**
 * Email sending for registrations, leads, and job applications.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles outgoing email notifications for the plugin.
 */
class Kivun_Mailer {

	/**
	 * Send course registration notification to admin + confirmation to registrant.
	 *
	 * @param int   $course_id The course post ID.
	 * @param array $data      The registration data.
	 * @return void
	 */
	public static function send_course_registration( int $course_id, array $data ): void {
		$course_title = get_the_title( $course_id );
		$contact_meta = get_post_meta( $course_id, '_kivun_contact_email', true );
		$admin_email  = $contact_meta ? $contact_meta : get_option( 'admin_email' );
		$site_name    = get_bloginfo( 'name' );

		// To admin.
		wp_mail(
			$admin_email,
			sprintf( '[%s] הרשמה חדשה לקורס: %s', $site_name, $course_title ),
			self::course_admin_body( $course_title, $data ),
			self::headers()
		);

		// Confirmation to registrant.
		wp_mail(
			$data['email'],
			sprintf( 'אישור הרשמה — %s', $course_title ),
			self::course_confirmation_body( $course_title, $data['name'], $site_name ),
			self::headers()
		);
	}

	/**
	 * Send lead/interest notification to admin — no confirmation to visitor.
	 *
	 * @param int    $post_id Course or workshop ID.
	 * @param array  $data    Name, email, phone, message.
	 * @param string $type    Either 'lead' or 'workshop'.
	 * @return void
	 */
	public static function send_lead_notification( int $post_id, array $data, string $type ): void {
		$title         = get_the_title( $post_id );
		$contact_meta  = get_post_meta( $post_id, '_kivun_contact_email', true );
		$settings_mail = get_option( 'kivun_settings', array() )['admin_email'] ?? '';
		if ( $contact_meta ) {
			$admin_email = $contact_meta;
		} elseif ( $settings_mail ) {
			$admin_email = $settings_mail;
		} else {
			$admin_email = get_option( 'admin_email' );
		}

		$subject = 'workshop' === $type
			? sprintf( '[%s] הרשמה חדשה לסדנה: %s', get_bloginfo( 'name' ), $title )
			: sprintf( '[%s] מתעניין/ת חדש/ה — %s', get_bloginfo( 'name' ), $title );

		// A sign-up that arrived after the current cycle closed — for the next cycle.
		if ( ! empty( $data['next_cycle'] ) ) {
			$subject = sprintf( '[%s] הרשמה למחזור הבא — %s', get_bloginfo( 'name' ), $title );
		}

		$body = sprintf(
			'<p><strong>%s</strong> — %s</p>
			<ul>
				<li><strong>שם:</strong> %s</li>
				<li><strong>טלפון:</strong> %s</li>
				<li><strong>אימייל:</strong> %s</li>
				<li><strong>עיר:</strong> %s</li>
				<li><strong>מגדר:</strong> %s</li>
				<li><strong>הערות:</strong> %s</li>
			</ul>
			<p style="color:#b91c1c;font-weight:bold">⚠️ נא לחזור אל הליד בהקדם.</p>',
			'workshop' === $type ? 'הרשמה לדף נחיתה' : 'פנייה מתעניין',
			esc_html( $title ),
			esc_html( $data['name'] ),
			esc_html( $data['phone'] ),
			esc_html( $data['email'] ),
			esc_html( $data['city'] ?? '' ),
			esc_html( $data['gender'] ?? '' ),
			nl2br( esc_html( $data['message'] ) )
		);

		wp_mail( $admin_email, $subject, $body, self::headers() );
	}

	/**
	 * Send CV application to employer (email hidden from frontend).
	 *
	 * @param string $employer_email The employer's email address.
	 * @param string $job_title      The job title.
	 * @param array  $data           The applicant data.
	 * @return void
	 */
	public static function send_application( string $employer_email, string $job_title, array $data ): void {
		$attachments = array();
		if ( ! empty( $data['cv_path'] ) && file_exists( $data['cv_path'] ) ) {
			$attachments[] = $data['cv_path'];
		}

		wp_mail(
			$employer_email,
			sprintf( 'מועמד/ת חדש/ה למשרה: %s', $job_title ),
			self::application_body( $job_title, $data ),
			self::headers(),
			$attachments
		);
	}

	/**
	 * Confirm to the applicant that their application was received.
	 * Sent in addition to the employer notification, never instead of it.
	 *
	 * @param string $applicant_email The applicant's email address.
	 * @param string $applicant_name  The applicant's name.
	 * @param string $job_title       The job title applied for.
	 * @param string $company         Optional company name.
	 * @param bool   $employer_mailed Whether the publisher was actually notified.
	 *                                When false the application is still saved
	 *                                and visible in the dashboard, so the wording
	 *                                must not claim it was forwarded.
	 * @return void
	 */
	public static function send_application_confirmation( string $applicant_email, string $applicant_name, string $job_title, string $company = '', bool $employer_mailed = true ): void {
		if ( ! is_email( $applicant_email ) ) {
			return;
		}

		$site = get_bloginfo( 'name' );

		$received = $employer_mailed
			? __( 'התקבלו בהצלחה ונשלחו למפרסם המשרה.', 'kivun' )
			: __( 'התקבלו בהצלחה ונשמרו במערכת.', 'kivun' );

		wp_mail(
			$applicant_email,
			/* translators: %s: job title. */
			sprintf( __( 'אישור הגשת מועמדות — %s', 'kivun' ), $job_title ),
			sprintf(
				/* translators: 1: applicant name, 2: job title, 3: company line, 4: received sentence, 5: site name. */
				'<p>שלום %1$s,</p>
				<p>קורות החיים שלך למשרה <strong>%2$s</strong>%3$s %4$s</p>
				<p>המפרסם יפנה אליך ישירות אם המועמדות תימצא מתאימה. שימו לב שלא כל פנייה מקבלת מענה.</p>
				<p>בהצלחה!<br>צוות %5$s</p>',
				esc_html( $applicant_name ),
				esc_html( $job_title ),
				$company ? ' ב<strong>' . esc_html( $company ) . '</strong>' : '',
				esc_html( $received ),
				esc_html( $site )
			),
			self::headers()
		);
	}

	/**
	 * Build the default email headers.
	 *
	 * @return array
	 */
	private static function headers(): array {
		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', get_bloginfo( 'name' ), get_option( 'admin_email' ) ),
		);
	}

	/**
	 * Build the admin notification body for a course registration.
	 *
	 * @param string $course The course title.
	 * @param array  $d      The registration data.
	 * @return string
	 */
	private static function course_admin_body( string $course, array $d ): string {
		return sprintf(
			'<p>הרשמה חדשה לקורס <strong>%s</strong></p>
			<ul>
				<li><strong>שם:</strong> %s</li>
				<li><strong>אימייל:</strong> %s</li>
				<li><strong>טלפון:</strong> %s</li>
				<li><strong>הערות:</strong> %s</li>
			</ul>',
			esc_html( $course ),
			esc_html( $d['name'] ),
			esc_html( $d['email'] ),
			esc_html( $d['phone'] ),
			nl2br( esc_html( $d['message'] ) )
		);
	}

	/**
	 * Build the confirmation email body for a registrant.
	 *
	 * @param string $course The course title.
	 * @param string $name   The registrant's name.
	 * @param string $site   The site name.
	 * @return string
	 */
	private static function course_confirmation_body( string $course, string $name, string $site ): string {
		return sprintf(
			'<p>שלום %s,</p>
			<p>הרשמתך לקורס <strong>%s</strong> התקבלה בהצלחה.</p>
			<p>נחזור אליך בהקדם עם פרטים נוספים.</p>
			<p>בברכה,<br>צוות %s</p>',
			esc_html( $name ),
			esc_html( $course ),
			esc_html( $site )
		);
	}

	/**
	 * Build the application notification body for an employer.
	 *
	 * @param string $job The job title.
	 * @param array  $d   The applicant data.
	 * @return string
	 */
	private static function application_body( string $job, array $d ): string {
		return sprintf(
			'<p>קיבלת מועמדות חדשה למשרה <strong>%s</strong></p>
			<ul>
				<li><strong>שם:</strong> %s</li>
				<li><strong>אימייל:</strong> %s</li>
				<li><strong>טלפון:</strong> %s</li>
				<li><strong>מכתב מקדים:</strong> %s</li>
			</ul>
			<p>%s</p>',
			esc_html( $job ),
			esc_html( $d['name'] ),
			esc_html( $d['email'] ),
			esc_html( $d['phone'] ),
			nl2br( esc_html( $d['message'] ) ),
			! empty( $d['cv_path'] ) ? 'קו"ח מצורפים.' : 'לא צורפו קו"ח.'
		);
	}
}
