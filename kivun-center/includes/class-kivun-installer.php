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

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_campaigns (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				label       varchar(191)        NOT NULL DEFAULT '',
				target_url  varchar(500)        NOT NULL DEFAULT '',
				final_url   varchar(700)        NOT NULL DEFAULT '',
				utm_source  varchar(100)        NOT NULL DEFAULT '',
				utm_medium  varchar(100)        NOT NULL DEFAULT '',
				utm_campaign varchar(150)       NOT NULL DEFAULT '',
				utm_term    varchar(150)        NOT NULL DEFAULT '',
				utm_content varchar(150)        NOT NULL DEFAULT '',
				created_by  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY utm_campaign (utm_campaign),
				KEY created_at (created_at)
			) $collate;
		"
		);

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_campaign_links (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) UNSIGNED NOT NULL,
				label       varchar(191)        NOT NULL DEFAULT '',
				target_url  varchar(500)        NOT NULL DEFAULT '',
				final_url   varchar(700)        NOT NULL DEFAULT '',
				utm_source  varchar(100)        NOT NULL DEFAULT '',
				utm_medium  varchar(100)        NOT NULL DEFAULT '',
				utm_term    varchar(150)        NOT NULL DEFAULT '',
				utm_content varchar(150)        NOT NULL DEFAULT '',
				utm_label   varchar(400)        NOT NULL DEFAULT '',
				created_by  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY campaign_id (campaign_id),
				KEY utm_label (utm_label)
			) $collate;
		"
		);

		self::migrate_campaign_links();
		self::align_job_terms();

		update_option( 'kivun_db_version', KIVUN_VERSION );
	}

	/**
	 * Rename the job taxonomies to the names Mercaz Kivun uses.
	 *
	 * The two sites named the same things differently — "משרה מלאה" against
	 * "מלאה", "טכנולוגיה ומחשבים" against "הייטק" — so a term could not be
	 * matched across them and jobs arrived uncategorised. Renaming rather than
	 * recreating keeps every existing job attached to its term.
	 *
	 * @return void
	 */
	private static function align_job_terms(): void {
		if ( get_option( 'kivun_job_terms_aligned' ) ) {
			return;
		}

		$renames = array(
			'kivun_job_scope' => array(
				'משרה מלאה'  => 'מלאה',
				'משרה חלקית' => 'חלקית',
			),
			'kivun_job_field' => array(
				'חינוך והוראה'           => 'חינוך',
				'מכירות ושיווק'          => 'מכירות',
				'טכנולוגיה ומחשבים'      => 'הייטק',
				'בריאות ורפואה'          => 'בריאות',
				'ניהול ומנהל'            => 'ניהול',
				'אדמינסטרציה ומזכירות'   => 'אדמיניסטרציה',
				'כספים וחשבונאות'        => 'כספים',
				'עיצוב ויצירה'           => 'עיצוב ואמנות',
				'רווחה ושירותים חברתיים' => 'טיפול',
				'ייעוץ מקצועי'           => 'כללי',
			),
		);

		foreach ( $renames as $taxonomy => $pairs ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			foreach ( $pairs as $from => $to ) {
				$old = get_term_by( 'name', $from, $taxonomy );
				if ( ! $old || is_wp_error( $old ) ) {
					continue;
				}

				// If the destination name already exists, move the posts over
				// and drop the old term — two terms cannot share a name.
				$existing = get_term_by( 'name', $to, $taxonomy );
				if ( $existing && ! is_wp_error( $existing ) && (int) $existing->term_id !== (int) $old->term_id ) {
					$posts = get_objects_in_term( array( (int) $old->term_id ), $taxonomy );
					if ( ! is_wp_error( $posts ) ) {
						foreach ( $posts as $post_id ) {
							wp_set_object_terms( (int) $post_id, (int) $existing->term_id, $taxonomy, true );
						}
					}
					wp_delete_term( (int) $old->term_id, $taxonomy );
					continue;
				}

				wp_update_term( (int) $old->term_id, $taxonomy, array( 'name' => $to ) );
			}
		}

		// Seed anything still missing, including the new features taxonomy.
		self::seed_default_terms();

		update_option( 'kivun_job_terms_aligned', 1 );
	}

	/**
	 * Campaigns started out as one row per link. They are now a container with
	 * many links beneath it — one per publisher pushing the same campaign — so
	 * the original flat rows are folded into that shape: one campaign per
	 * distinct utm_campaign, and each original row becomes a link under it.
	 *
	 * @return void
	 */
	private static function migrate_campaign_links(): void {
		if ( get_option( 'kivun_campaign_links_migrated' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kivun_campaigns ORDER BY id ASC" );
		if ( ! $rows ) {
			update_option( 'kivun_campaign_links_migrated', 1 );
			return;
		}

		$keep = array();
		foreach ( $rows as $row ) {
			$key = (string) $row->utm_campaign;

			// The first row for a campaign name becomes the container; the rest
			// are absorbed as links and their container rows removed.
			if ( ! isset( $keep[ $key ] ) ) {
				$keep[ $key ] = (int) $row->id;
			}

			$parts = array_filter( array( $row->utm_source, $row->utm_medium, $row->utm_campaign, $row->utm_content ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'kivun_campaign_links',
				array(
					'campaign_id' => $keep[ $key ],
					'label'       => $row->utm_source ? $row->utm_source : $row->label,
					'target_url'  => $row->target_url,
					'final_url'   => $row->final_url,
					'utm_source'  => $row->utm_source,
					'utm_medium'  => $row->utm_medium,
					'utm_term'    => $row->utm_term,
					'utm_content' => $row->utm_content,
					'utm_label'   => implode( ' / ', $parts ),
					'created_by'  => (int) $row->created_by,
					'created_at'  => $row->created_at,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);

			if ( (int) $row->id !== $keep[ $key ] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $wpdb->prefix . 'kivun_campaigns', array( 'id' => (int) $row->id ), array( '%d' ) );
			}
		}

		update_option( 'kivun_campaign_links_migrated', 1 );
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
			'kivun_job_scope'   => array(
				'מלאה',
				'חלקית',
				'משמרות',
				'היברידית',
				'עבודה מהבית',
				'פרילנס',
				'סטודנט',
				'משרת אם',
				'משרה לאקדמאים',
				'התנדבות',
			),
			'kivun_job_region'  => array(
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
			// These match the Mercaz Kivun vocabulary exactly, so a term resolves
			// there by name and nothing has to be translated on the way out.
			'kivun_job_field'   => array(
				'אבטחה',
				'אדמיניסטרציה',
				'אדרכילות/בנייה',
				'אחזקה ולוגיסטיקה',
				'ביטוח',
				'ביטחון',
				'בנקאות',
				'בריאות',
				'דיגיטל',
				'הדרכה',
				'הידרותרפיה',
				'הייטק',
				'הנדסה',
				'הנהלת חשבונות',
				'חינוך',
				'חשבות שכר',
				'חשמל',
				'טיפול',
				'כללי',
				'כספים',
				'מכירות',
				'מסעדות/אוכל',
				'מציל',
				'משאבי אנוש',
				'ניהול',
				'נקיון',
				'סיעוד',
				'עבודה מהבית',
				'עיצוב גרפי',
				'עיצוב ואמנות',
				'עריכת דין',
				'פיזותרפיה',
				'פרסום ושיווק',
				'קמעונאות',
				'ריפוי בעיסוק',
				'רכב',
				'שירותי לקוחות',
				'תעופה',
				'תעשייה',
			),
			'kivun_job_feature' => array(
				'משרה זמנית',
				'משרה מיידית',
				'מתאים להורים',
				'מתאים למגזר החרדי',
				'מתאים לסטודנטים',
				'רכב צמוד',
			),
			'kivun_course_cat'  => array(
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
