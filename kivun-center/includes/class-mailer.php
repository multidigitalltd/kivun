<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Mailer {

	/**
	 * Send course registration notification to admin + confirmation to registrant.
	 */
	public static function send_course_registration( int $course_id, array $data ): void {
		$course_title   = get_the_title( $course_id );
		$admin_email    = get_post_meta( $course_id, '_kivun_contact_email', true ) ?: get_option( 'admin_email' );
		$site_name      = get_bloginfo( 'name' );

		// To admin
		wp_mail(
			$admin_email,
			sprintf( '[%s] הרשמה חדשה לקורס: %s', $site_name, $course_title ),
			self::course_admin_body( $course_title, $data ),
			self::headers()
		);

		// Confirmation to registrant
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
	 * @param int    $post_id   Course or workshop ID.
	 * @param array  $data      name, email, phone, message.
	 * @param string $type      'lead' | 'workshop'
	 */
	public static function send_lead_notification( int $post_id, array $data, string $type ): void {
		$title      = get_the_title( $post_id );
		$admin_email = get_post_meta( $post_id, '_kivun_contact_email', true )
			?: get_option( 'kivun_settings', [] )['admin_email']
			?: get_option( 'admin_email' );

		$subject = $type === 'workshop'
			? sprintf( '[%s] הרשמה חדשה לסדנה: %s', get_bloginfo( 'name' ), $title )
			: sprintf( '[%s] מתעניין/ת חדש/ה — %s', get_bloginfo( 'name' ), $title );

		$body = sprintf(
			'<p><strong>%s</strong> — %s</p>
			<ul>
				<li><strong>שם:</strong> %s</li>
				<li><strong>טלפון:</strong> %s</li>
				<li><strong>אימייל:</strong> %s</li>
				<li><strong>הערות:</strong> %s</li>
			</ul>
			<p style="color:#b91c1c;font-weight:bold">⚠️ נא לחזור אל הליד בהקדם.</p>',
			$type === 'workshop' ? 'הרשמה לסדנה' : 'פנייה מתעניין',
			esc_html( $title ),
			esc_html( $data['name'] ),
			esc_html( $data['phone'] ),
			esc_html( $data['email'] ),
			nl2br( esc_html( $data['message'] ) )
		);

		wp_mail( $admin_email, $subject, $body, self::headers() );
	}

	/**
	 * Send CV application to employer (email hidden from frontend).
	 */
	public static function send_application( string $employer_email, string $job_title, array $data ): void {
		$attachments = [];
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

	private static function headers(): array {
		return [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', get_bloginfo( 'name' ), get_option( 'admin_email' ) ),
		];
	}

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
