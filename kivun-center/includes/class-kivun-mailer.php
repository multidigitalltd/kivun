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
		self::send(
			$admin_email,
			sprintf( '[%s] הרשמה חדשה לקורס: %s', $site_name, $course_title ),
			self::course_admin_body( $course_title, $data ),
			__( 'הרשמה חדשה לקורס', 'kivun' )
		);

		// Confirmation to registrant.
		self::send(
			$data['email'],
			sprintf( 'אישור הרשמה — %s', $course_title ),
			self::course_confirmation_body( $course_title, $data['name'], $site_name ),
			__( 'אישור הרשמה', 'kivun' )
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

		self::send( $admin_email, $subject, $body, __( 'פנייה חדשה', 'kivun' ) );
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

		self::send(
			$employer_email,
			sprintf( 'מועמד/ת חדש/ה למשרה: %s', $job_title ),
			self::application_body( $job_title, $data ),
			__( 'מועמדות חדשה', 'kivun' ),
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
	 * @param bool   $employer_mailed Unused. Kept so existing callers still fit.
	 * @param array  $coordinator     The coordinator handling this candidate,
	 *                                who signs the letter. Empty when the rota
	 *                                named nobody.
	 * @return void
	 */
	public static function send_application_confirmation( string $applicant_email, string $applicant_name, string $job_title, string $company = '', bool $employer_mailed = true, array $coordinator = array() ): void {
		unset( $employer_mailed, $company );

		if ( ! is_email( $applicant_email ) ) {
			return;
		}

		self::send(
			$applicant_email,
			/* translators: %s: job title. */
			sprintf( __( 'אישור הגשת מועמדות — %s', 'kivun' ), $job_title ),
			self::confirmation_html( $applicant_name, $job_title, $coordinator ),
			__( 'המועמדות התקבלה', 'kivun' )
		);
	}

	/**
	 * What a candidate is told once their application is in — as paragraphs,
	 * so the letter and the message on screen are written once and cannot
	 * come to say different things.
	 *
	 * @param string               $name        The candidate's name.
	 * @param string               $job_title   The job applied for.
	 * @param array<string,string> $coordinator The coordinator handling them.
	 * @return array<int,string> Paragraphs; a line break inside one is "\n".
	 */
	public static function confirmation_lines( string $name, string $job_title, array $coordinator = array() ): array {
		$lines = array(
			/* translators: %s: the candidate's name. */
			sprintf( __( 'שלום %s,', 'kivun' ), $name ),
			/* translators: %s: job title. */
			sprintf( __( 'קורות החיים שלך למשרה %s התקבלו בהצלחה.', 'kivun' ), $job_title ),
			__( 'אנו נפנה אליך ישירות אם המועמדות תימצא מתאימה.', 'kivun' ),
		);

		// The sign-off names the person who actually has the application, so a
		// candidate with a question knows who to ask. Without a coordinator on
		// the rota there is no such person, and signing in someone's name we
		// do not have would be worse than signing as the centre.
		$sign_off = array( __( 'בברכה,', 'kivun' ) );
		foreach ( array( 'name', 'phone', 'email' ) as $part ) {
			if ( '' !== trim( (string) ( $coordinator[ $part ] ?? '' ) ) ) {
				$sign_off[] = (string) $coordinator[ $part ];
			}
		}
		if ( 1 === count( $sign_off ) ) {
			$sign_off[] = get_bloginfo( 'name' );
		}

		$lines[] = implode( "\n", $sign_off );

		return $lines;
	}

	/**
	 * The same message as email HTML.
	 *
	 * @param string               $name        The candidate's name.
	 * @param string               $job_title   The job applied for.
	 * @param array<string,string> $coordinator The coordinator handling them.
	 * @return string
	 */
	public static function confirmation_html( string $name, string $job_title, array $coordinator = array() ): string {
		$accent = (string) apply_filters( 'kivun_email_accent', '#ef315d' );
		$lines  = self::confirmation_lines( $name, $job_title, $coordinator );
		$last   = count( $lines ) - 1;
		$html   = '';

		foreach ( $lines as $index => $paragraph ) {
			// Everything but the sign-off reads as ordinary paragraphs; the
			// greeting is a shade heavier so the letter opens on the reader's
			// own name.
			if ( $index !== $last ) {
				$html .= sprintf(
					'<p dir="rtl" style="margin:0 0 14px;text-align:right%1$s">%2$s</p>',
					0 === $index ? ';font-weight:600;color:#222222' : '',
					nl2br( esc_html( $paragraph ) )
				);
				continue;
			}

			// The sign-off is a card of its own: it carries the coordinator's
			// name, phone and address, and a candidate with a question should
			// find who to ask at a glance rather than read for it.
			$parts   = explode( "\n", $paragraph );
			$opening = array_shift( $parts );

			$details = '';
			foreach ( $parts as $line ) {
				$details .= sprintf(
					'<div dir="rtl" style="text-align:right;color:#222222;font-weight:600;font-size:15px;line-height:1.7">%s</div>',
					esc_html( $line )
				);
			}

			$html .= sprintf(
				'<table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" border="0" width="100%%" style="border-collapse:collapse;margin:26px 0 0">
					<tr><td dir="rtl" align="right" style="padding:16px 18px;background:#fdf3f6;border-right:4px solid %1$s;border-radius:10px;text-align:right">
						<div dir="rtl" style="text-align:right;color:#8a8a8a;font-size:14px;margin:0 0 6px">%2$s</div>
						%3$s
					</td></tr>
				</table>',
				esc_attr( $accent ),
				esc_html( $opening ),
				$details
			);
		}

		return $html;
	}

	/**
	 * The centre's logo, as it should appear at the head of a letter.
	 *
	 * The site's own logo is used, so the letters follow the site rather than
	 * a copy of it that would go stale the day the logo is changed. Many
	 * clients block remote images by default, so the image carries the
	 * centre's name as its alt text and there is a text fallback behind it.
	 *
	 * @return string The logo URL, or '' when the site has none.
	 */
	private static function logo_url(): string {
		$url = '';

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$url = (string) wp_get_attachment_image_url( $logo_id, 'full' );
		}

		if ( '' === $url ) {
			$url = (string) get_site_icon_url( 180 );
		}

		/**
		 * The logo shown at the head of the plugin's emails.
		 *
		 * @param string $url An absolute image URL, or '' for the site name in text.
		 */
		return (string) apply_filters( 'kivun_email_logo', $url );
	}

	/**
	 * Dress a message in the site's colours, right way round.
	 *
	 * Our letters went out as bare paragraphs. A mail client has no stylesheet
	 * and no idea the site is Hebrew, so it laid every one of them out
	 * left-to-right in its own default face — which is what a candidate saw.
	 *
	 * Sent as a whole `<html dir="rtl">` document rather than a right-aligned
	 * div: Gmail and Outlook rewrite the markup they are handed, and the
	 * direction has to survive that. Every table and cell repeats `dir` and
	 * the old `align` attribute for the same reason.
	 *
	 * Built as a table with the styles written on each element. That is not
	 * old-fashioned for its own sake: mail clients drop <style> blocks and
	 * many ignore flex and grid, so a table with inline styles is the only
	 * layout that arrives looking the same in all of them.
	 *
	 * @param string $body    The message, as HTML.
	 * @param string $heading Optional heading above it.
	 * @return string
	 */
	public static function wrap( string $body, string $heading = '' ): string {
		/**
		 * The brand colour the letters are trimmed in.
		 *
		 * @param string $accent A hex colour.
		 */
		$accent = (string) apply_filters( 'kivun_email_accent', '#ef315d' );
		$site   = get_bloginfo( 'name' );
		$font   = "'Segoe UI',Arial,'Helvetica Neue',Helvetica,sans-serif";
		$logo   = self::logo_url();

		// The masthead: the logo when the site has one, and the centre's name
		// in text when it hasn't — or when the client refused to load it.
		$mast = '' !== $logo
			? sprintf(
				'<img src="%1$s" alt="%2$s" height="58" style="display:inline-block;height:58px;max-height:58px;width:auto;max-width:230px;border:0;outline:none;text-decoration:none">',
				esc_url( $logo ),
				esc_attr( $site )
			)
			: sprintf(
				'<span style="font-size:20px;font-weight:700;color:%1$s;letter-spacing:.2px">%2$s</span>',
				esc_attr( $accent ),
				esc_html( $site )
			);

		$head = '' !== trim( $heading )
			? sprintf(
				'<h1 dir="rtl" style="margin:0 0 6px;font-size:22px;line-height:1.35;font-weight:700;color:#222222;text-align:right">%1$s</h1>
				<div style="width:54px;height:3px;background:%2$s;border-radius:3px;margin:0 0 20px"></div>',
				esc_html( $heading ),
				esc_attr( $accent )
			)
			: '';

		return sprintf(
			'<!DOCTYPE html>
<html dir="rtl" lang="%1$s"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>%2$s</title>
</head>
<body dir="rtl" style="margin:0;padding:0;background:#f4f5f7">
<div dir="rtl" style="margin:0;padding:26px 12px;background:#f4f5f7;font-family:%3$s">
	<table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" border="0" width="100%%" style="border-collapse:collapse">
		<tr><td align="center">
			<!--[if mso]><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td><![endif]-->
			<table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" border="0" width="100%%" style="width:100%%;max-width:600px;border-collapse:collapse;background:#ffffff;border:1px solid #e9e9ec;border-radius:14px;overflow:hidden">
				<tr><td style="height:6px;line-height:6px;font-size:0;background:%4$s">&nbsp;</td></tr>
				<tr><td dir="rtl" align="right" style="padding:20px 30px 12px;text-align:right;border-bottom:1px solid #f0f0f2">%5$s</td></tr>
				<tr><td dir="rtl" align="right" style="padding:28px 30px 30px;text-align:right;font-size:16px;line-height:1.85;color:#3f3f46">
					%6$s%7$s
				</td></tr>
				<tr><td dir="rtl" align="right" style="padding:18px 30px 22px;background:#fafafb;border-top:1px solid #f0f0f2;text-align:right;font-size:13px;line-height:1.7;color:#8a8a8a">
					<strong style="color:#6b6b73">%8$s</strong><br>%9$s
				</td></tr>
			</table>
			<!--[if mso]></td></tr></table><![endif]-->
		</td></tr>
	</table>
</div>
</body></html>',
			esc_attr( str_replace( '_', '-', (string) get_bloginfo( 'language' ) ) ),
			esc_html( '' !== trim( $heading ) ? $heading : $site ),
			esc_attr( $font ),
			esc_attr( $accent ),
			$mast,
			$head,
			$body,
			esc_html( $site ),
			sprintf(
				'<a href="%1$s" style="color:#8a8a8a;text-decoration:none">%2$s</a>',
				esc_url( home_url( '/' ) ),
				esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) )
			)
		);
	}

	/**
	 * Send one of our letters, wrapped.
	 *
	 * @param string            $to          Recipient.
	 * @param string            $subject     Subject line.
	 * @param string            $body        The message, as HTML.
	 * @param string            $heading     Optional heading above it.
	 * @param array<int,string> $attachments Files to attach.
	 * @return bool Whether wp_mail accepted it.
	 */
	public static function send( string $to, string $subject, string $body, string $heading = '', array $attachments = array() ): bool {
		return (bool) wp_mail( $to, $subject, self::wrap( $body, $heading ), self::headers(), $attachments );
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
		// Only what the candidate actually answered. An empty "מגדר:" line
		// tells the reader nothing and makes the ones that matter harder to see.
		$rows = '';
		foreach ( array(
			'שם'         => (string) ( $d['name'] ?? '' ),
			'אימייל'     => (string) ( $d['email'] ?? '' ),
			'טלפון'      => (string) ( $d['phone'] ?? '' ),
			'מגדר'       => (string) ( $d['gender'] ?? '' ),
			'מגורים'     => (string) ( $d['residence'] ?? '' ),
			'מכתב מקדים' => (string) ( $d['message'] ?? '' ),
		) as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			$rows .= sprintf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( $label ),
				nl2br( esc_html( $value ) )
			);
		}

		return sprintf(
			'<p>קיבלת מועמדות חדשה למשרה <strong>%s</strong></p><ul>%s</ul><p>%s</p>',
			esc_html( $job ),
			$rows,
			! empty( $d['cv_path'] ) ? 'קו"ח מצורפים.' : 'לא צורפו קו"ח.'
		);
	}
}
