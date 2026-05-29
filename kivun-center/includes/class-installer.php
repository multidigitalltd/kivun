<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Installer {

	public static function activate(): void {
		self::create_tables();
		self::add_roles();
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
				email       varchar(100)        NOT NULL,
				phone       varchar(30)         NOT NULL DEFAULT '',
				message     text,
				status      varchar(20)         NOT NULL DEFAULT 'pending',
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY course_id (course_id),
				KEY email (email)
			) $collate;
		" );

		dbDelta( "
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_applications (
				id               bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id           bigint(20) UNSIGNED NOT NULL,
				applicant_name   varchar(100)        NOT NULL,
				applicant_email  varchar(100)        NOT NULL,
				applicant_phone  varchar(30)         NOT NULL DEFAULT '',
				cv_file          varchar(500)        NOT NULL DEFAULT '',
				message          text,
				status           varchar(20)         NOT NULL DEFAULT 'new',
				created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY job_id (job_id)
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
}
