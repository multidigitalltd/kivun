<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Installer {

	public static function activate(): void {
		self::create_tables();
		self::add_roles();

		// CPTs must be registered before seeding terms
		require_once KIVUN_DIR . 'includes/class-post-types.php';
		Kivun_Post_Types::register();

		self::seed_default_terms();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static function create_tables(): void {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_registrations (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				course_id   bigint(20) UNSIGNED NOT NULL,
				name        varchar(100)        NOT NULL,
				email       varchar(100)        NOT NULL DEFAULT '',
				phone       varchar(30)         NOT NULL DEFAULT '',
				message     text,
				notes       text,
				type        varchar(20)         NOT NULL DEFAULT 'registration',
				status      varchar(20)         NOT NULL DEFAULT 'pending',
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY course_id (course_id),
				KEY type (type),
				KEY email (email)
			) $collate;
		" );

		dbDelta( "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_applications (
				id               bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id           bigint(20) UNSIGNED NOT NULL,
				user_id          bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				applicant_name   varchar(100)        NOT NULL,
				applicant_email  varchar(100)        NOT NULL,
				applicant_phone  varchar(30)         NOT NULL DEFAULT '',
				cv_file          varchar(500)        NOT NULL DEFAULT '',
				message          text,
				notes            text,
				status           varchar(20)         NOT NULL DEFAULT 'new',
				created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY job_id (job_id),
				KEY user_id (user_id)
			) $collate;
		" );

		update_option( 'kivun_db_version', KIVUN_VERSION );
	}

	private static function add_roles(): void {
		add_role(
			'kivun_employer',
			__( 'Employer', 'kivun' ),
			[ 'read' => true ]
		);
	}

	/**
	 * Seed sensible default taxonomy terms on first activation.
	 * Skips any term that already exists.
	 */
	private static function seed_default_terms(): void {
		$defaults = [
			'kivun_job_scope' => [
				'משרה מלאה',
				'משרה חלקית',
				'פרילנס',
				'התנדבות',
			],
			'kivun_job_region' => [
				'מרכז',
				'תל אביב והסביבה',
				'ירושלים',
				'צפון',
				'חיפה והקריות',
				'דרום',
				'שפלה',
				'השרון',
				'עבודה מהבית',
			],
			'kivun_job_field' => [
				'חינוך והוראה',
				'ייעוץ מקצועי',
				'מכירות ושיווק',
				'טכנולוגיה ומחשבים',
				'בריאות ורפואה',
				'רווחה ושירותים חברתיים',
				'ניהול ומנהל',
				'אדמינסטרציה ומזכירות',
				'כספים וחשבונאות',
				'עיצוב ויצירה',
			],
			'kivun_course_cat' => [
				'כישורי עבודה',
				'יזמות עסקית',
				'פיתוח אישי',
				'טכנולוגיה',
				'שפות',
			],
			'kivun_workshop_cat' => [
				'כישורי תקשורת',
				'חיפוש עבודה',
				'יזמות',
				'מנהיגות',
				'פיתוח אישי',
			],
		];

		foreach ( $defaults as $taxonomy => $terms ) {
			foreach ( $terms as $term_name ) {
				if ( ! term_exists( $term_name, $taxonomy ) ) {
					wp_insert_term( $term_name, $taxonomy );
				}
			}
		}
	}
}
