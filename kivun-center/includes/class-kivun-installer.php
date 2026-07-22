<?php
/**
 * Plugin activation, deactivation, and database setup routines.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, deactivation, tables, roles, and seed terms.
 */
class Kivun_Installer {

	/**
	 * Runs activation tasks: tables, roles, post types, and seed terms.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::add_roles();

		// CPTs must be registered before seeding terms.
		require_once KIVUN_DIR . 'includes/class-kivun-post-types.php';
		Kivun_Post_Types::register();

		self::seed_default_terms();
		flush_rewrite_rules();
	}

	/**
	 * Runs deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Ensure the database tables exist after a plugin update. The activation
	 * hook only runs on activate, not on a ZIP/FTP update, so a missing table
	 * would make submissions silently fail — guard against that here.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'kivun_db_version' ) !== KIVUN_VERSION ) {
			self::create_tables();
			self::ensure_columns();
			self::add_roles();
			// Rewrite slugs may change between versions (e.g. landing pages) —
			// flush once after the post types register on `init`.
			add_action( 'init', 'flush_rewrite_rules', 99 );
		}
	}

	/**
	 * Bulletproof safety net for older installs: dbDelta does not always add
	 * columns to an existing table, so explicitly ALTER any missing columns
	 * (e.g. user_id / notes that were introduced after the first release).
	 *
	 * @return void
	 */
	private static function ensure_columns(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'kivun_applications'  => array(
				'user_id'         => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
				'applicant_phone' => "varchar(30) NOT NULL DEFAULT ''",
				'cv_file'         => "varchar(500) NOT NULL DEFAULT ''",
				'message'         => 'text',
				'notes'           => 'text',
				'status'          => "varchar(20) NOT NULL DEFAULT 'new'",
			),
			$wpdb->prefix . 'kivun_registrations' => array(
				'city'              => "varchar(100) NOT NULL DEFAULT ''",
				'gender'            => "varchar(20) NOT NULL DEFAULT ''",
				'marketing_consent' => 'tinyint(1) NOT NULL DEFAULT 0',
				'message'           => 'text',
				'notes'             => 'text',
				'source'            => "varchar(191) NOT NULL DEFAULT ''",
				'type'              => "varchar(20) NOT NULL DEFAULT 'registration'",
				'status'            => "varchar(20) NOT NULL DEFAULT 'pending'",
			),
		);

		foreach ( $tables as $table => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$have = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

			foreach ( $columns as $column => $definition ) {
				if ( ! in_array( $column, $have, true ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
				}
			}
		}
	}

	/**
	 * Creates the plugin custom database tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_registrations (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				course_id   bigint(20) UNSIGNED NOT NULL,
				name        varchar(100)        NOT NULL,
				email       varchar(100)        NOT NULL DEFAULT '',
				phone       varchar(30)         NOT NULL DEFAULT '',
				city        varchar(100)        NOT NULL DEFAULT '',
				gender      varchar(20)         NOT NULL DEFAULT '',
				marketing_consent tinyint(1)    NOT NULL DEFAULT 0,
				message     text,
				notes       text,
				source      varchar(191)        NOT NULL DEFAULT '',
				type        varchar(20)         NOT NULL DEFAULT 'registration',
				status      varchar(20)         NOT NULL DEFAULT 'pending',
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY course_id (course_id),
				KEY type (type),
				KEY email (email)
			) $collate;
		"
		);

		dbDelta(
			"
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
		"
		);

		update_option( 'kivun_db_version', KIVUN_VERSION );
	}

	/**
	 * Register the plugin's custom roles.
	 *
	 * - kivun_employer:      a business — posts and manages its OWN jobs.
	 * - kivun_jobs_manager:  manages ALL jobs and applications on the board, but
	 *                        has no access to any other part of the site.
	 *
	 * @return void
	 */
	public static function add_roles(): void {
		if ( ! get_role( 'kivun_employer' ) ) {
			add_role(
				'kivun_employer',
				__( 'מעסיק', 'kivun' ),
				array( 'read' => true )
			);
		}

		// Re-create so capability changes propagate on upgrade.
		remove_role( 'kivun_jobs_manager' );
		add_role(
			'kivun_jobs_manager',
			__( 'מנהל לוח משרות', 'kivun' ),
			array(
				'read'              => true,
				'kivun_employer'    => true,
				'kivun_manage_jobs' => true,
			)
		);
	}

	/**
	 * Seed sensible default taxonomy terms on first activation.
	 * Skips any term that already exists.
	 */
	private static function seed_default_terms(): void {
		$defaults = array(
			'kivun_job_scope'  => array(
				'משרה מלאה',
				'משרה חלקית',
				'פרילנס',
				'התנדבות',
			),
			'kivun_job_region' => array(
				'מרכז',
				'תל אביב והסביבה',
				'ירושלים',
				'צפון',
				'חיפה והקריות',
				'דרום',
				'שפלה',
				'השרון',
				'עבודה מהבית',
			),
			'kivun_job_field'  => array(
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
			),
			'kivun_course_cat' => array(
				'כישורי עבודה',
				'יזמות עסקית',
				'פיתוח אישי',
				'טכנולוגיה',
				'שפות',
			),
		);

		foreach ( $defaults as $taxonomy => $terms ) {
			foreach ( $terms as $term_name ) {
				if ( ! term_exists( $term_name, $taxonomy ) ) {
					wp_insert_term( $term_name, $taxonomy );
				}
			}
		}
	}
}
