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
			// Runs after ensure_columns(), which is what creates the columns
			// it fills.
			self::migrate_lead_utm();
			self::clean_stored_promos();
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
			$wpdb->prefix . 'kivun_campaign_links' => array(
				'whatsapp' => 'text',
			),
			$wpdb->prefix . 'kivun_applications'   => array(
				'user_id'         => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
				'applicant_phone' => "varchar(30) NOT NULL DEFAULT ''",
				'cv_file'         => "varchar(500) NOT NULL DEFAULT ''",
				'message'         => 'text',
				'notes'           => 'text',
				'status'          => "varchar(20) NOT NULL DEFAULT 'new'",
			),
			$wpdb->prefix . 'kivun_registrations'  => array(
				'city'              => "varchar(100) NOT NULL DEFAULT ''",
				'gender'            => "varchar(20) NOT NULL DEFAULT ''",
				'marketing_consent' => 'tinyint(1) NOT NULL DEFAULT 0',
				'message'           => 'text',
				'notes'             => 'text',
				'source'            => "varchar(191) NOT NULL DEFAULT ''",
				'type'              => "varchar(20) NOT NULL DEFAULT 'registration'",
				'status'            => "varchar(20) NOT NULL DEFAULT 'pending'",
				'utm_source'        => "varchar(150) NOT NULL DEFAULT ''",
				'utm_medium'        => "varchar(150) NOT NULL DEFAULT ''",
				'utm_campaign'      => "varchar(150) NOT NULL DEFAULT ''",
				'utm_content'       => "varchar(150) NOT NULL DEFAULT ''",
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
				utm_source   varchar(150)       NOT NULL DEFAULT '',
				utm_medium   varchar(150)       NOT NULL DEFAULT '',
				utm_campaign varchar(150)       NOT NULL DEFAULT '',
				utm_content  varchar(150)       NOT NULL DEFAULT '',
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY course_id (course_id),
				KEY type (type),
				KEY email (email),
				KEY utm_campaign (utm_campaign)
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
				whatsapp    text,
				created_by  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY campaign_id (campaign_id),
				KEY utm_label (utm_label)
			) $collate;
		"
		);

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_phone_numbers (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				number      varchar(32)         NOT NULL DEFAULT '',
				label       varchar(191)        NOT NULL DEFAULT '',
				is_active   tinyint(1)          NOT NULL DEFAULT 1,
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY number (number)
			) $collate;
		"
		);

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_phone_assignments (
				id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				number_id   bigint(20) UNSIGNED NOT NULL,
				campaign_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				media       varchar(100)        NOT NULL DEFAULT '',
				label       varchar(191)        NOT NULL DEFAULT '',
				starts_on   date                NOT NULL,
				ends_on     date                DEFAULT NULL,
				created_by  bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY number_id (number_id),
				KEY campaign_id (campaign_id),
				KEY starts_on (starts_on)
			) $collate;
		"
		);

		dbDelta(
			"
			CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kivun_calls (
				id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				number_id     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				assignment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				call_id       varchar(100)        NOT NULL DEFAULT '',
				dialled       varchar(32)         NOT NULL DEFAULT '',
				caller        varchar(32)         NOT NULL DEFAULT '',
				caller_name   varchar(191)        NOT NULL DEFAULT '',
				answered      tinyint(1)          NOT NULL DEFAULT 0,
				total_time    int(10) UNSIGNED    NOT NULL DEFAULT 0,
				talk_time     int(10) UNSIGNED    NOT NULL DEFAULT 0,
				recording     varchar(500)        NOT NULL DEFAULT '',
				started_at    datetime            NOT NULL,
				created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY call_id (call_id),
				KEY number_id (number_id),
				KEY assignment_id (assignment_id),
				KEY started_at (started_at)
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
	 * Recover the UTM values of leads captured before they had columns of
	 * their own.
	 *
	 * Until now a lead's campaign lived only inside its "source" text, as
	 * "UTM: a / b / c" with empty values dropped — so the position of a part
	 * does not say which field it is, and three parts could be
	 * source/medium/campaign or source/campaign/content. The campaign names
	 * are known, though: whichever part matches a real campaign slug is the
	 * campaign, and everything before and after it falls into place around it.
	 *
	 * @return void
	 */
	private static function migrate_lead_utm(): void {
		if ( get_option( 'kivun_lead_utm_migrated' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$have = $wpdb->get_col( "SHOW COLUMNS FROM `{$wpdb->prefix}kivun_registrations`" );
		if ( ! in_array( 'utm_campaign', $have, true ) ) {
			// The table is not there yet, or the column could not be added.
			// Leave the flag unset so this is retried on the next upgrade.
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slugs = $wpdb->get_col( "SELECT utm_campaign FROM {$wpdb->prefix}kivun_campaigns WHERE utm_campaign <> ''" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, source FROM {$wpdb->prefix}kivun_registrations
			 WHERE utm_campaign = '' AND source LIKE '%UTM: %'"
		);

		foreach ( (array) $rows as $row ) {
			$at = mb_strpos( (string) $row->source, 'UTM: ' );
			if ( false === $at ) {
				continue;
			}

			$parts = array_values(
				array_filter(
					array_map( 'trim', explode( '/', mb_substr( (string) $row->source, $at + 5 ) ) ),
					static function ( $part ) {
						return '' !== $part;
					}
				)
			);
			if ( ! $parts ) {
				continue;
			}

			$campaign_at = -1;
			foreach ( $parts as $index => $part ) {
				if ( in_array( $part, $slugs, true ) ) {
					$campaign_at = $index;
					break;
				}
			}

			// No known campaign in the label: the first part is still the
			// source, and the rest cannot be placed with any confidence.
			$values = array(
				'utm_source'   => $parts[0],
				'utm_medium'   => '',
				'utm_campaign' => '',
				'utm_content'  => '',
			);

			if ( $campaign_at >= 0 ) {
				$values['utm_campaign'] = $parts[ $campaign_at ];
				$values['utm_source']   = $campaign_at >= 1 ? $parts[0] : '';
				$values['utm_medium']   = $campaign_at >= 2 ? $parts[1] : '';
				$values['utm_content']  = $parts[ $campaign_at + 1 ] ?? '';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'kivun_registrations',
				$values,
				array( 'id' => (int) $row->id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		update_option( 'kivun_lead_utm_migrated', 1 );
	}

	/**
	 * Strip the markup out of WhatsApp promos already saved.
	 *
	 * The per-link promo was written from the destination's rich-text fields
	 * without reducing them to plain text first, so tags reached the message —
	 * and, on the model-written path, were imitated throughout it. Both ends
	 * are fixed, but a promo saved in between is still sitting in the table
	 * with its tags, and nobody should have to rewrite it by hand.
	 *
	 * @return void
	 */
	private static function clean_stored_promos(): void {
		if ( get_option( 'kivun_promos_cleaned' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, whatsapp FROM {$wpdb->prefix}kivun_campaign_links
			 WHERE whatsapp LIKE '%<%' OR whatsapp LIKE '%&lt;%'"
		);

		foreach ( (array) $rows as $row ) {
			$clean = Kivun_Campaigns::clean_promo( (string) $row->whatsapp );
			if ( $clean === (string) $row->whatsapp ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'kivun_campaign_links',
				array( 'whatsapp' => $clean ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		update_option( 'kivun_promos_cleaned', 1 );
	}

	/**
	 * Register the plugin's custom roles.
	 *
	 * - kivun_employer:      a business — posts and manages its OWN jobs.
	 * - kivun_jobs_manager:  manages ALL jobs and applications on the board, but
	 *                        has no access to any other part of the site.
	 * - kivun_leads_viewer:  reads the leads table and nothing else.
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

		// A reader for the leads table and nothing else. It deliberately holds
		// no editing capability: the status and note handlers already require
		// edit_posts, so this role is refused by them without a second check.
		remove_role( 'kivun_leads_viewer' );
		add_role(
			'kivun_leads_viewer',
			__( 'צפייה בלידים', 'kivun' ),
			array(
				'read'             => true,
				'kivun_view_leads' => true,
			)
		);

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
