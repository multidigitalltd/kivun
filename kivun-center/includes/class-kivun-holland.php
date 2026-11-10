<?php
/**
 * The Holland questionnaire (RIASEC): a page of its own, entered with a name,
 * a phone number and an ID number, that scores the answers and shows the
 * result on the spot.
 *
 * The questions, the instructions and the result texts are the client's own
 * (the spreadsheet "שאלון הולנד"), copied here word for word. Three parts of
 * 72 yes/no items each — activities, skills, professions — twelve items of
 * each of the six types in every part. A "yes" is one point to the item's
 * type, so every type is scored out of 36, and the three highest make the
 * person's Holland code.
 *
 * Every person who enters is a lead. The row is written the moment they log
 * in, not when they finish: somebody who stopped at question 40 is still
 * somebody the centre wants to call, and the console shows how far they got.
 * Their answers are saved as they go, so the same person coming back — same
 * ID number and phone — picks up where they stopped instead of starting
 * 216 questions again.
 *
 * Scoring happens here and only here. The page sends answers, never scores,
 * so the numbers in the console are the ones the questionnaire produces and
 * not whatever a browser chose to post.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Questions, scoring, and the page that asks them.
 */
class Kivun_Holland {

	/**
	 * The lead type the rows are filed under.
	 */
	const LEAD_TYPE = 'holland';

	/**
	 * The six types, in the order the questionnaire and the literature give
	 * them. Ties in the code are broken by this order.
	 */
	const TYPES = array( 'R', 'I', 'A', 'S', 'E', 'C' );

	/**
	 * Items per type in each part.
	 */
	const PER_TYPE = 12;

	/**
	 * Items shown on one screen of the questionnaire.
	 *
	 * One: each question has the screen to itself, with the two answers as
	 * large buttons and the next question coming up as soon as one is
	 * chosen. Twelve to a page read as a long form, which is not how the
	 * client wants it taken. The template still handles more than one, should
	 * that ever be wanted back.
	 */
	const PAGE_SIZE = 1;

	/**
	 * Registers the shortcode and the two endpoints.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'kivun_holland', array( __CLASS__, 'render' ) );

		foreach ( array( 'start', 'save' ) as $action ) {
			add_action( 'wp_ajax_kivun_holland_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_kivun_holland_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_for_page' ), 20 );

		// The questionnaire's page stands on its own: no site header, footer
		// or page title around the card.
		add_filter( 'template_include', array( __CLASS__, 'blank_template' ), 99 );

		// The representative's printable report. Logged-in staff only: there
		// is no nopriv hook, and the handler checks the leads capability too.
		add_action( 'wp_ajax_kivun_holland_report', array( __CLASS__, 'ajax_report' ) );
	}

	/**
	 * Load the questionnaire's own style and script in the page head when the
	 * page carries the shortcode, so the login box is not drawn unstyled while
	 * the footer catches up. Elementor keeps a Shortcode widget's content in
	 * post meta, so that is searched as well.
	 *
	 * @return void
	 */
	public static function enqueue_for_page(): void {
		if ( is_singular() && self::page_has_questionnaire( get_post() ) ) {
			self::enqueue();
		}
	}

	/**
	 * Whether a post carries the questionnaire's shortcode — in its content,
	 * or in Elementor's data, where a Shortcode widget keeps it.
	 *
	 * @param mixed $post The post.
	 * @return bool
	 */
	public static function page_has_questionnaire( $post ): bool {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return false;
		}
		$haystack = (string) ( $post->post_content ?? '' ) . (string) get_post_meta( (int) $post->ID, '_elementor_data', true );
		return false !== strpos( $haystack, '[kivun_holland' );
	}

	/**
	 * The page that holds the questionnaire is drawn without the site's
	 * header, footer and page title — the questionnaire card is the page.
	 *
	 * The same idea as Elementor's own "Canvas" template: WordPress's head
	 * and footer hooks still run, so every style and script the page needs
	 * (Elementor's included) still loads; only the theme's chrome is left
	 * out. A theme can supply its own kivun/holland/blank-page.php.
	 *
	 * @param string $template The template WordPress chose.
	 * @return string
	 */
	public static function blank_template( $template ) {
		if ( ! is_singular() || ! self::page_has_questionnaire( get_post() ) ) {
			return $template;
		}

		/**
		 * Whether the questionnaire's page is drawn without the site's header
		 * and footer. Default true.
		 *
		 * @param bool $blank Whether to leave them out.
		 */
		if ( ! apply_filters( 'kivun_holland_blank_page', true ) ) {
			return $template;
		}

		$theme = get_stylesheet_directory() . '/kivun/holland/blank-page.php';
		return file_exists( $theme ) ? $theme : KIVUN_DIR . 'templates/holland/blank-page.php';
	}

	/**
	 * Enqueue the questionnaire's style and script, registered with the
	 * plugin's others in Kivun_Core::enqueue_frontend(). Safe to call twice.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		Kivun_Core::enqueue_frontend_assets();
		wp_enqueue_style( 'kivun-holland' );
		wp_enqueue_script( 'kivun-holland' );
	}

	// ── The content ───────────────────────────────────────────────────────────

	/**
	 * The three parts, with the instructions the spreadsheet gives each — as
	 * two sentences, "yes" and "no", because the screens set them apart.
	 *
	 * The names and wording follow the design handoff (holland v2), which took
	 * them from the sheet: the second part is "כישורים", and its instructions
	 * keep the sheet's own spelling.
	 *
	 * @return array<string,array{title:string,yes:string,no:string,instructions:string}>
	 */
	public static function sections(): array {
		$parts = array(
			'activities'  => array(
				'title' => __( 'פעילויות', 'kivun' ),
				'yes'   => __( 'בחר ב"כן" עבור פעילויות שהנך אוהב לעשות או שיש לך עניין ללמוד לעשות.', 'kivun' ),
				'no'    => __( 'בחר ב"לא" עבור פעילויות שאינך אוהב לעשות או שאין לך כל יחס אליהן ואין לך כל עניין ללמוד לעשות.', 'kivun' ),
			),
			'skills'      => array(
				'title' => __( 'כישורים', 'kivun' ),
				'yes'   => __( 'בחר ב"כן" את הפעילויות שהנך יכול לבצע היטב או במומחיות.', 'kivun' ),
				'no'    => __( 'בחר ב"לא" את הפעילויות שמעולם לא בצעת או שבצעת באופן גרוע.', 'kivun' ),
			),
			'professions' => array(
				'title' => __( 'מקצועות', 'kivun' ),
				'yes'   => __( 'בחר ב"כן" את המקצועות שמעניינים או מושכים אותך.', 'kivun' ),
				'no'    => __( 'בחר ב"לא" את המקצועות שאינך מחבב או שאתה מוצא אותם כלא מעניינים.', 'kivun' ),
			),
		);
		foreach ( $parts as $key => $part ) {
			$parts[ $key ]['instructions'] = $part['yes'] . ' ' . $part['no'];
		}
		return $parts;
	}

	/**
	 * The questionnaire's title, as the screens and the report give it.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'שאלון הכוון תעסוקתי – מודל הולנד', 'kivun' );
	}

	/**
	 * Each type's colours: its fill, its text colour on white, its tint, and
	 * the colour of a letter set on its fill. From the design handoff — the
	 * orange is darker than before so a white letter on it reaches 3:1, and
	 * a letter on the yellow is near-black.
	 *
	 * @return array<string,array{c:string,t:string,tint:string,fg:string}>
	 */
	public static function type_colors(): array {
		return array(
			'R' => array(
				'c'    => '#2e9e5b',
				't'    => '#1d7a45',
				'tint' => '#e8f6ee',
				'fg'   => '#ffffff',
			),
			'I' => array(
				'c'    => '#7c3aed',
				't'    => '#6d28d9',
				'tint' => '#f2ecfe',
				'fg'   => '#ffffff',
			),
			'A' => array(
				'c'    => '#e06b12',
				't'    => '#a9520a',
				'tint' => '#fef1e4',
				'fg'   => '#ffffff',
			),
			'S' => array(
				'c'    => '#e8b400',
				't'    => '#7a5c00',
				'tint' => '#fdf6dc',
				'fg'   => '#2b2100',
			),
			'E' => array(
				'c'    => '#d6304f',
				't'    => '#b3203d',
				'tint' => '#fceaee',
				'fg'   => '#ffffff',
			),
			'C' => array(
				'c'    => '#2a7fd0',
				't'    => '#1c64a8',
				'tint' => '#e8f1fb',
				'fg'   => '#ffffff',
			),
		);
	}

	/**
	 * The opening line of the questionnaire, before anyone logs in.
	 *
	 * @return string
	 */
	public static function intro(): string {
		return __( 'השאלון נועד לבדוק את הנטיות המקצועיות שלך ולסייע בידך בבחירה ובהכוון מקצועי. אנא קרא את ההוראות בדייקנות והקפד לענות על כל השאלות.', 'kivun' );
	}

	/**
	 * The item texts, by part and then by type, twelve to a type.
	 *
	 * @return array<string,array<string,array<int,string>>>
	 */
	private static function texts(): array {
		return array(
			'activities'  => array(
				'R' => array(
					'לגזום דשא ושיחים בגינה',
					'להשתמש במברגה חשמלית',
					'להרכיב מחשב',
					'לצפות בעבודתם של טכנאים',
					'להשתתף בקורס מכונאות רכב',
					'להרכיב רהיטים מאיקאה בכוחות עצמי',
					'לטפל בבעיות במערכת החשמל בבית',
					'לסייד את הבית',
					'לכרוך ספרים',
					'לתקן מכשירי חשמל ביתיים',
					'לבנות סוכה',
					'לפרק ולהרכיב מכשירים סלולריים',
				),
				'I' => array(
					'לפתור חידות מאתגרות',
					'ללמוד על נדידת הציפורים',
					'לראות סרטוני מדע',
					'ללמוד על הווצרות סופות טורנדו',
					'לשמוע פודקאסט על מחקרים רפואיים',
					'לשחק במשחקי חשיבה (דוג\' שחמט)',
					'לפתור תשבצים',
					'לאסוף ולנתח מידע על נושא מסוים ולהגיע למסקנות לגביו',
					'לקרוא ספרי ביוגרפיה על רבנים',
					'לקרוא על ההיסטוריה של השואה',
					'ללמוד ספרי מחשבה והגות יהודית (דוג\' מורה נבוכים, כוזרי, מהר"ל)',
					'ללמוד גמרא בעיון',
				),
				'A' => array(
					'ללמוד לנגן בכלי נגינה שונים',
					'להכין תכשיטים',
					'לצייר או לאייר',
					'להשתתף בסרט',
					'לקרוא או לכתוב שירה',
					'להשתתף בחוג אומנות',
					'להביע את עצמי באמצעות יצירה',
					'לעסוק בעיצוב (לדוגמא, בגדים, תכשיטים, כלים לבית)',
					'ליצור שילובים מעניינים וייחודיים לבגדים ולסטייל',
					'להלחין ניגונים',
					'להשתתף בחוג חזנות ופיוטים',
					'לצייר קריקטורות',
				),
				'S' => array(
					'להדריך בחוגי ילדים',
					'להתנדב במשמר האזרחי',
					'לפגוש אנשי חינוך ידועים',
					'לעזור לאחרים בבעיותיהם האישיות',
					'לסייע לאנשים עם מוגבלויות',
					'לעבוד עם ילדים או נוער',
					'להתנדב בהדרכה בארגון נוער',
					'לנסות לפתור בעיות של אנשים אחרים',
					'לעבוד במסגרת חינוכית או חברתית',
					'להתנדב בעזר מציון',
					'להיות "אוזן קשבת" למצוקות החברים בישיבה',
					'לעזור לחבר המתקשה בהבנת הלימוד בישיבה',
				),
				'E' => array(
					'לארגן אירועים משפחתיים',
					'למכור או לשווק ארבעת מינים',
					'לנהל שירות או עסק עצמאי',
					'לפגוש מנהלים או מנהיגים ידועים',
					'לשמש כנציג של קבוצה',
					'לרתום אנשים אחרים לטובת רעיון שאני מאמין בו',
					'ליזום ולנהל פעילויות או פרויקטים',
					'לפתוח כולל ערב',
					'להיות מתכנן הטיולים ב"בין הזמנים"',
					'לארגן אירועי התרמה',
					'להשתתף בקורס יזמות וניהול',
					'לדבר לפני קהל',
				),
				'C' => array(
					'ללמוד להקליד ב"שיטה עיוורת"',
					'למלא טפסי מס הכנסה',
					'לבצע מעקב מפורט אחר הוצאות והכנסות',
					'לבדוק טעויות בחשבון בקופה או בבנק',
					'לארגן רישומים מדוייקים של מלאי סחורות בעסק',
					'להתנהל מול בירוקרטיה (תשלום חשבונות, ביטוח לאומי וכדומה)',
					'לשמור את הדברים בארונות מסודרים',
					'להשתמש בתוכנות מחשב כמו וורד ואקסל',
					'לסדר ולרכז מקומות בבית המדרש',
					'לנהל כספי גמ"ח',
					'לסייע למשרד הישיבה במשימות אדמיניסטרטביות',
					'להקליד נתונים אישיים ולבסס נתונים במחשב',
				),
			),
			'skills'      => array(
				'R' => array(
					'אני יכול לבנות גדר או פרגולה מעץ',
					'אני יכול לגזום שיחים או עצים בגינה',
					'אני יכול לתקן תקלות פשוטות במכונית',
					'אני יכול להשתמש בכלי עבודה (מקדחה או מברגה)',
					'אני יכול להחליף צמיג במכונית',
					'אני יכול להרכיב רהיטים שונים לפי תרשים',
					'אני יכול לבצע תיקוני חשמל פשוטים',
					'אני יכול לצבוע רהיטי עץ',
					'אני יכול לבנות בגבס',
					'אני יכול לבנות סוכה',
					'אני יכול לעשות תיקוני שרברבות פשוטים',
					'אני יכול לפרק ולהרכיב מחשבים וציוד מחשב',
				),
				'I' => array(
					'אני יודע מה מרכיבי המזון של מאכלים שונים',
					'אני מבין עקרונות בפיסיקה',
					'אני יודע מהם חוקי המשיכה',
					'אני יכול לנתח טיעון לוגי',
					'אני מבין איך פועלות מערכות בגוף האדם',
					'אני מבין את הרקע לתופעות הרי הגעש',
					'אני מבין את תפקידי הדי.אנ.אי. בגנטיקה',
					'אני יודע להסיק מסקנות מלוחות גרפיים',
					'אני טוב בלמצוא הסברים לתופעות',
					'אני יודע להבין תהליכים ולזהות גורמים לאירועים',
					'אני יכול לחקור מאורעות היסטוריים',
					'אני יכול לאסוף ולנתח מידע על נושא מסוים ולהגיע למסקנות לגביו',
				),
				'A' => array(
					'אני יכול לעצב רהיטים',
					'אני יודע לכתוב ברכות לאירועים',
					'אני יכול לתכנן ולעצב הזמנות לאירועים',
					'אני יכול לתכנן או לעצב גינה של בית פרטי',
					'אני יכול לשחק בהצגה',
					'אני יכול להלחין מוזיקה לשירים',
					'אני יכול לכתוב סיפורים / שירה / בלוג / טור אישי',
					'אני יכול לצלם צילום אומנותי',
					'אני יכול לעצב את חדרי באופן שמשקף את האופי שלי',
					'אני טוב בלהביע את עצמי ורגשותיי באמצעות יצירה',
					'אני יודע לרקוד בסגנונות ריקוד שונים',
					'אני יכול לשמש כבעל תפילה בשבתות וחגים',
				),
				'S' => array(
					'אני יכול להתנדב בפרוייקט של איסוף מזון או פרוייקט דומה',
					'אני יכול ליצור קשר עם אנשים מבוגרים בקלות',
					'אנשים רבים משתפים אותי בבעיותיהם האישיות',
					'אני מסוגל לעזור בפתרון קונפליקטים בין אנשים',
					'יש לי הבנה טובה של יחסי אנוש',
					'אני יכול להיות מדריך בקבוצה',
					'אני יודע איך לגרום לאנשים לחוש בנוח',
					'אני יכול לסייע לאנשים עם בעיות רגשיות',
					'אני יכול לזהות את רגשותיהם של אחרים בקלות',
					'אני יודע להתיידד בקלות עם אנשים חדשים',
					'אני מסוגל לייעץ לחברים בבעיות ובעניינים שונים',
					'אני עובד טוב במיוחד בצוות',
				),
				'E' => array(
					'אני יכול לדבר מול קהל',
					'אני יכול לקבל החלטות בתנאי אי וודאות',
					'אני יכול לנהל עסק קטן',
					'אני יכול לארגן עבודתם של אנשים אחרים',
					'אני יכול להוביל קבוצה ולהשפיע',
					'אני יכול לשכנע אנשים לפעול לפי דרכי',
					'אני יכול לשמש כאיש מכירות',
					'אני יכול לגייס כספים למימוש פרויקט',
					'אני יכול לנהל משא ומתן',
					'אני יכול לנהל דיון',
					'אני מסוגל להוביל צוות למטרה',
					'אני טוב בהתמקחות כשצריך לקדם עסקאות',
				),
				'C' => array(
					'אני יכול לארגן ולסדר ארכיונים',
					'אני יכול להפעיל תוכנות ממוחשבות לניהול חשבונות',
					'אני יודע להשתמש ביישומי אופיס במחשב',
					'אני יכול לנהל מעקב ובקרה אחרי תקציבים וכספים',
					'אני יכול לפעול באורח מאורגן ומסודר',
					'אני יכול לערוך מעקב על מלאי במחסנים',
					'אני יכול לדאוג לניירת רבה בפרק זמן קצר ובצורה מסודרת',
					'אני יכול לתכנן לוח זמנים לפעילות מורכבת',
					'אני יכול לנהל כספי גמ"ח',
					'אני יכול להשתמש במחשב לארגון נתונים',
					'אני יכול לכתוב מיילים עסקיים',
					'אני יכול לבצע הגהה מדויקת למסמך',
				),
			),
			'professions' => array(
				'R' => array(
					'מנופאי',
					'מהנדס חשמל',
					'חקלאי',
					'מוהל',
					'טכנאי אנרגיה סולרית',
					'טכנאי מצלמות אבטחה',
					'מכונאות רכב',
					'מטיס רחפנים',
					'הנדסת בניין',
					'הנדסת מכונות חקלאיות',
					'טכנאות אלקטרוניקה',
					'טכנאי תאורה',
				),
				'I' => array(
					'חוקר מדעי הסביבה',
					'עובד במעבדה ביולוגית',
					'היסטוריון',
					'פיסיקאי',
					'חוקר במדעי הטבע',
					'מדעי החקלאות',
					'מעבדנות רפואית (לבורנט)',
					'מדען נתונים (Data Scientist)',
					'מחקר בתחום הבריאות',
					'עריכת ירחון מדעי',
					'בוטניקה (מדעי הצמח)',
					'ספרנות ומידענות',
				),
				'A' => array(
					'מעבד מוזיקלי',
					'עיצוב אתרים',
					'מאייר ומעצב פרסומות',
					'זמר / פייטן / חזן',
					'מעצב חוויית משתמש (UI/UX Designer)',
					'גרפיקה',
					'עיצוב פנים',
					'צילום',
					'ציור',
					'כותב תוכן',
					'עורך וידאו',
					'עיצוב תעשייתי',
				),
				'S' => array(
					'מורה בבית ספר',
					'מדריך קידום נוער',
					'יועץ זוגי או משפחתי',
					'קואוצ\'ר / מטפל',
					'מורה דרך / מדריך טיולים',
					'מעביר חוגים לילדים',
					'ייעוץ חינוכי',
					'הוראה בחינוך מיוחד',
					'קלינאות תקשורת',
					'עבודה סוציאלית',
					'פסיכולוגיה',
					'ריפוי בעיסוק',
				),
				'E' => array(
					'יזם פרוייקטים',
					'מנהל חנות',
					'מתווך דירות',
					'איש יחסי ציבור',
					'סוכן למכירת מכוניות',
					'קופירייטר (רעיונאי)',
					'עיתונאי',
					'סוחר באמזון',
					'שדרן רדיו',
					'ניהול מחלקת שיווק',
					'הפקת אירועים וכנסים',
					'ניהול עסק פרטי',
				),
				'C' => array(
					'בקרת איכות לתוכנות- QA',
					'רואה חשבון',
					'טלר בבנק',
					'שמאי מקרקעין',
					'מתפעל רשת מחשבים',
					'מנהל אדמניסטרטיבי',
					'חשב שכר',
					'מנהל חשבונות',
					'ייעוץ מס',
					'מנהל מערכות מידע',
					'שמאי רכוש',
					'מומחה לבקרת איכות',
				),
			),
		);
	}

	/**
	 * What each type means, as the result page explains it.
	 *
	 * @return array<string,array{name:string,about:string,traits:string,fields:string}>
	 */
	public static function type_info(): array {
		return array(
			'R' => array(
				'name'   => __( 'ביצועי-מעשי', 'kivun' ),
				'about'  => __( 'אנשים שאוהבים "ללכלך את הידיים" בעשייה פרקטית. הם נהנים לעבוד עם כלים, מכונות או ציוד, ומעדיפים פעילות טכנית ופיזית על פני עיסוק בתיאוריות.', 'kivun' ),
				'traits' => __( 'מעשיים, ממוקדי-מטרה, ומתחברים יותר לעשייה מאשר לדיבורים.', 'kivun' ),
				'fields' => __( 'הנדסה, מכונאות, חקלאות, תפעול ותחזוקה.', 'kivun' ),
			),
			'I' => array(
				'name'   => __( 'חקרני', 'kivun' ),
				'about'  => __( 'אנשים שמונעים מסקרנות ורצון להבין לעומק איך דברים עובדים. הם אוהבים לאסוף נתונים, לנתח מצבים ולפתור בעיות מורכבות.', 'kivun' ),
				'traits' => __( 'רציונליים, אנליטיים, אוהבים לחשוב ולהתעמק, ומעריכים חשיבה אובייקטיבית.', 'kivun' ),
				'fields' => __( 'מדעים, רפואה, מתמטיקה, מחקר ופיתוח.', 'kivun' ),
			),
			'A' => array(
				'name'   => __( 'אמנותי-יצירתי', 'kivun' ),
				'about'  => __( 'אנשים שזקוקים למקום פתוח לביטוי אישי ולדמיון. הם מחפשים סביבת עבודה לא שגרתית שבה יוכלו להביא לידי ביטוי את הרגש והמקוריות שלהם.', 'kivun' ),
				'traits' => __( 'יצירתיים, מקוריים, רגישים, ואוהבים לפעול מחוץ לקופסה.', 'kivun' ),
				'fields' => __( 'עיצוב, כתיבה, מוזיקה, משחק ואמנות חזותית.', 'kivun' ),
			),
			'S' => array(
				'name'   => __( 'חברתי-טיפולי', 'kivun' ),
				'about'  => __( 'אנשים שהמיקוד המרכזי שלהם הוא בעבודה עם בני אדם. הם שואבים סיפוק ממתן עזרה לאחרים, טיפול, ייעוץ או הדרכה.', 'kivun' ),
				'traits' => __( 'תקשורתיים, סבלניים, חמים, פתוחים ובעלי אינטליגנציה רגשית גבוהה.', 'kivun' ),
				'fields' => __( 'ייעוץ, עבודה סוציאלית, פסיכולוגיה, הוראה וסיעוד.', 'kivun' ),
			),
			'E' => array(
				'name'   => __( 'יזמי-ניהולי', 'kivun' ),
				'about'  => __( 'אנשים שאוהבים להוביל, להשפיע ולהניע תהליכים. הם נהנים מסביבה דינמית, הצבת יעדים, וחתירה להישגים, מעמד או הצלחה עסקית.', 'kivun' ),
				'traits' => __( 'שאפתנים, בעלי ביטחון עצמי, אסרטיביים ובעלי כושר שכנוע.', 'kivun' ),
				'fields' => __( 'ניהול, יזמות עסקית, שיווק ומכירות, עריכת דין.', 'kivun' ),
			),
			'C' => array(
				'name'   => __( 'מנהלי-ארגוני', 'kivun' ),
				'about'  => __( 'אנשים שפורחים בסביבה מסודרת ומאורגנת. הם אוהבים לעבוד עם נתונים, מספרים וכללים ברורים, ומצטיינים בירידה לפרטים.', 'kivun' ),
				'traits' => __( 'מסודרים, דייקנים, יעילים מאוד, ואוהבים מסגרות עבודה מוגדרות.', 'kivun' ),
				'fields' => __( 'הנהלת חשבונות, מערכות מידע, אדמיניסטרציה וניתוח נתונים.', 'kivun' ),
			),
		);
	}

	/**
	 * The two paragraphs that open the result page.
	 *
	 * @return array<int,string>
	 */
	public static function results_intro(): array {
		return array(
			__( 'נטיות תעסוקתיות הן למעשה תחומי העניין שלך בעולם העבודה – הפעילויות שאתה נהנה לעשות, שמסקרנים אותך ושבהם תרצה להתפתח. הנטייה שלך מתגבשת עם השנים מתוך האופי המולד שלך, החוויות שצברת, ואנשים משמעותיים שהיוו עבורך השראה.', 'kivun' ),
			__( 'כדי לעזור לך למפות את הכיוון הנכון עבורך, נהוג לחלק את עולם התעסוקה לשש קבוצות עיקריות. אצל רוב האנשים ניתן לזהות שילוב של שתיים או שלוש קבוצות שבולטות במיוחד.', 'kivun' ),
		);
	}

	/**
	 * Every item, numbered 1–216 in the questionnaire's own numbering.
	 *
	 * Activities run 1–72, skills 73–144, professions 145–216, and within a
	 * part each type takes a block of twelve in R I A S E C order. The
	 * spreadsheet's professions sheet repeats 167 and 168 by mistake; the
	 * numbering here follows the rule the other two sheets keep, so every
	 * item has a number of its own.
	 *
	 * @return array<int,array{section:string,type:string,text:string}> Keyed by item number.
	 */
	public static function items(): array {
		static $items = null;
		if ( null !== $items ) {
			return $items;
		}

		$items = array();
		$num   = 0;
		foreach ( self::texts() as $section => $by_type ) {
			foreach ( self::TYPES as $type ) {
				foreach ( $by_type[ $type ] as $text ) {
					++$num;
					$items[ $num ] = array(
						'section' => $section,
						'type'    => $type,
						'text'    => $text,
					);
				}
			}
		}

		return $items;
	}

	/**
	 * How many items there are in all.
	 *
	 * @return int
	 */
	public static function total(): int {
		return count( self::items() );
	}

	/**
	 * The order the items are asked in, part by part.
	 *
	 * On paper each part is a table of six columns, one per type, read a row
	 * at a time. Asked as a list in number order, the first twelve would all
	 * be one type and the pattern would be plain to anyone; taking a row at a
	 * time — the first item of each type, then the second — keeps the paper's
	 * mix.
	 *
	 * @return array<string,array<int,int>> Section => item numbers in asking order.
	 */
	public static function asking_order(): array {
		$by = array();
		foreach ( self::items() as $num => $item ) {
			$by[ $item['section'] ][ $item['type'] ][] = $num;
		}

		$order = array();
		foreach ( $by as $section => $types ) {
			$order[ $section ] = array();
			for ( $row = 0; $row < self::PER_TYPE; $row++ ) {
				foreach ( self::TYPES as $type ) {
					$order[ $section ][] = $types[ $type ][ $row ];
				}
			}
		}

		return $order;
	}

	// ── Answers and scores ────────────────────────────────────────────────────

	/**
	 * Answers as stored: one character per item, in item-number order —
	 * 'y' for yes, 'n' for no, '-' for not answered yet.
	 *
	 * Anything else in what was posted reads as unanswered, and the string is
	 * always exactly as long as the questionnaire.
	 *
	 * @param mixed $raw The posted or stored answers.
	 * @return string
	 */
	public static function normalize_responses( $raw ): string {
		$total = self::total();
		$raw   = is_string( $raw ) ? $raw : '';
		$out   = '';
		for ( $i = 0; $i < $total; $i++ ) {
			$c    = $raw[ $i ] ?? '-';
			$out .= ( 'y' === $c || 'n' === $c ) ? $c : '-';
		}
		return $out;
	}

	/**
	 * How many items have an answer.
	 *
	 * @param string $responses Normalized answers.
	 * @return int
	 */
	public static function answered( string $responses ): int {
		return substr_count( $responses, 'y' ) + substr_count( $responses, 'n' );
	}

	/**
	 * Score a full set of answers.
	 *
	 * @param string $responses Normalized answers.
	 * The code is the three highest types, with two refinements. A type
	 * that scored the same as the third is part of the code too — picking
	 * one of two equal scores would be the order of the alphabet speaking,
	 * not the answers — so a tie for third gives a code of four letters (or
	 * more). And a type nobody said "yes" to is no tendency at all, so a
	 * zero never makes it into the code, even to fill it out to three.
	 *
	 * @return array{totals:array<string,int>,sections:array<string,array<string,int>>,code:string,tie:bool,max:int}
	 */
	public static function score( string $responses ): array {
		$totals   = array_fill_keys( self::TYPES, 0 );
		$sections = array();
		foreach ( array_keys( self::sections() ) as $section ) {
			$sections[ $section ] = array_fill_keys( self::TYPES, 0 );
		}

		foreach ( self::items() as $num => $item ) {
			if ( 'y' === ( $responses[ $num - 1 ] ?? '' ) ) {
				++$totals[ $item['type'] ];
				++$sections[ $item['section'] ][ $item['type'] ];
			}
		}

		$ranked = self::rank( $totals );
		$third  = $totals[ $ranked[2] ];
		$code   = '';
		foreach ( $ranked as $i => $type ) {
			if ( $totals[ $type ] <= 0 || ( $i >= 3 && $totals[ $type ] !== $third ) ) {
				break;
			}
			$code .= $type;
		}

		return array(
			'totals'   => $totals,
			'sections' => $sections,
			'code'     => $code,
			'tie'      => strlen( $code ) > 3,
			'max'      => self::PER_TYPE * count( self::sections() ),
		);
	}

	/**
	 * The types from highest score to lowest; equal scores keep R I A S E C
	 * order.
	 *
	 * @param array<string,int> $totals Score per type.
	 * @return array<int,string>
	 */
	public static function rank( array $totals ): array {
		$types = self::TYPES;
		usort(
			$types,
			static function ( string $a, string $b ) use ( $totals ): int {
				$diff = ( $totals[ $b ] ?? 0 ) <=> ( $totals[ $a ] ?? 0 );
				return 0 !== $diff ? $diff : array_search( $a, self::TYPES, true ) <=> array_search( $b, self::TYPES, true );
			}
		);
		return $types;
	}

	// ── Who is answering ──────────────────────────────────────────────────────

	/**
	 * An Israeli ID number, padded to nine digits, or '' when it is not one.
	 *
	 * Checked against its check digit, so a digit typed wrong is caught at
	 * the door rather than found by whoever tries to use it later.
	 *
	 * @param string $raw As typed.
	 * @return string
	 */
	public static function normalize_id( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits || strlen( $digits ) > 9 || strlen( $raw ) > 20 ) {
			return '';
		}

		$id = str_pad( $digits, 9, '0', STR_PAD_LEFT );
		if ( '000000000' === $id ) {
			return '';
		}

		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$n    = (int) $id[ $i ] * ( 1 + ( $i % 2 ) );
			$sum += $n > 9 ? $n - 9 : $n;
		}

		return 0 === $sum % 10 ? $id : '';
	}

	/**
	 * An Israeli phone number as ten (or nine) local digits, or '' when it is
	 * not one. +972 / 972 is turned back into the leading 0.
	 *
	 * @param string $raw As typed.
	 * @return string
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 0 === strpos( $digits, '972' ) ) {
			$digits = '0' . substr( $digits, 3 );
		}
		return preg_match( '/^0\d{8,9}$/', $digits ) ? $digits : '';
	}

	/**
	 * The token that ties a browser to the lead row it started.
	 *
	 * The row id alone would let anyone post answers onto anybody's row by
	 * counting; the ID number in the hash means the token is only known to
	 * whoever logged in with it.
	 *
	 * @param int    $lead_id The row.
	 * @param string $id      The normalized ID number.
	 * @return string
	 */
	public static function token( int $lead_id, string $id ): string {
		return wp_hash( 'kivun_holland|' . $lead_id . '|' . $id );
	}

	// ── The lead row ──────────────────────────────────────────────────────────

	/**
	 * What is stored in the row's `answers` column.
	 *
	 * The shape the landing pages already use — a list of key / label /
	 * value — so the CSV export gives the ID number, the code and each score
	 * a column of its own without knowing anything about this questionnaire.
	 * The raw answers, whether it was submitted and the save's sequence ride
	 * along without a label, which is what keeps them out of the export's
	 * columns.
	 *
	 * Finished means submitted, not merely complete. The autosave can carry
	 * all 216 answers while the person is still looking them over; until
	 * they press "finish" the answers stay theirs to change.
	 *
	 * @param string $id        Normalized ID number.
	 * @param string $responses Normalized answers.
	 * @param bool   $done      Whether it was submitted.
	 * @param int    $seq       The save's sequence number.
	 * @param string $done_at   When it was submitted (site time, Y-m-d H:i:s).
	 * @return array<int,array<string,string>>
	 */
	public static function stored_answers( string $id, string $responses, bool $done = false, int $seq = 0, string $done_at = '' ): array {
		$done = $done && self::answered( $responses ) === self::total();
		$out  = array(
			array(
				'key'   => 'holland_id',
				'label' => __( 'תעודת זהות', 'kivun' ),
				'value' => $id,
			),
			array(
				'key'   => 'holland_progress',
				'label' => __( 'שאלון הולנד', 'kivun' ),
				'value' => self::progress_label( $responses, $done ),
			),
		);

		if ( $done ) {
			$score = self::score( $responses );
			$info  = self::type_info();
			$out[] = array(
				'key'   => 'holland_code',
				'label' => __( 'קוד הולנד', 'kivun' ),
				'value' => '' !== $score['code'] ? $score['code'] : '—',
			);
			foreach ( self::TYPES as $type ) {
				$out[] = array(
					'key'   => 'holland_' . $type,
					'label' => $info[ $type ]['name'] . ' (' . $type . ')',
					'value' => (string) $score['totals'][ $type ],
				);
			}
		}

		$out[] = array(
			'key'   => 'holland_responses',
			'value' => $responses,
		);
		$out[] = array(
			'key'   => 'holland_done',
			'value' => $done ? '1' : '0',
		);
		$out[] = array(
			'key'   => 'holland_seq',
			'value' => (string) $seq,
		);
		if ( $done && '' !== $done_at ) {
			$out[] = array(
				'key'   => 'holland_done_at',
				'value' => $done_at,
			);
		}

		return $out;
	}

	/**
	 * Read the ID number and the answers back out of a stored row.
	 *
	 * @param string $stored The `answers` column.
	 * @return array{id:string,responses:string,done:bool,seq:int,done_at:string}
	 */
	public static function read_stored( string $stored ): array {
		$out     = array(
			'id'        => '',
			'responses' => self::normalize_responses( '' ),
			'done'      => false,
			'seq'       => 0,
			'done_at'   => '',
		);
		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return $out;
		}
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'] ) ) {
				continue;
			}
			if ( 'holland_id' === $entry['key'] ) {
				$out['id'] = (string) ( $entry['value'] ?? '' );
			} elseif ( 'holland_responses' === $entry['key'] ) {
				$out['responses'] = self::normalize_responses( (string) ( $entry['value'] ?? '' ) );
			} elseif ( 'holland_done' === $entry['key'] ) {
				$out['done'] = '1' === (string) ( $entry['value'] ?? '' );
			} elseif ( 'holland_seq' === $entry['key'] ) {
				$out['seq'] = (int) ( $entry['value'] ?? 0 );
			} elseif ( 'holland_done_at' === $entry['key'] ) {
				$out['done_at'] = (string) ( $entry['value'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * "Finished" or "in progress, N of 216", for the console and the export.
	 *
	 * @param string $responses Normalized answers.
	 * @param bool   $done      Whether it was submitted.
	 * @return string
	 */
	public static function progress_label( string $responses, bool $done = false ): string {
		$answered = self::answered( $responses );
		$total    = self::total();
		if ( $done && $answered === $total ) {
			return __( 'הושלם', 'kivun' );
		}
		/* translators: 1: questions answered, 2: questions in all. */
		return sprintf( __( 'בתהליך — %1$d מתוך %2$d', 'kivun' ), $answered, $total );
	}

	/**
	 * The lead's message: what the console shows under the row.
	 *
	 * Written as "label: value" lines, which the leads table already lays out
	 * as a question and its answer.
	 *
	 * @param string $id        Normalized ID number.
	 * @param string $responses Normalized answers.
	 * @param bool   $done      Whether it was submitted.
	 * @return string
	 */
	public static function message( string $id, string $responses, bool $done = false ): string {
		$done  = $done && self::answered( $responses ) === self::total();
		$lines = array(
			__( 'תעודת זהות', 'kivun' ) . ': ' . $id,
			__( 'שאלון הולנד', 'kivun' ) . ': ' . self::progress_label( $responses, $done ),
		);

		if ( $done ) {
			$score  = self::score( $responses );
			$ranked = self::rank( $score['totals'] );

			$lines[] = __( 'קוד הולנד', 'kivun' ) . ': ' . ( '' !== $score['code'] ? $score['code'] : '—' ) . ( $score['tie'] ? ' ' . __( '(שוויון במקום השלישי)', 'kivun' ) : '' );

			$parts = array();
			foreach ( $ranked as $type ) {
				$parts[] = $type . ' ' . $score['totals'][ $type ];
			}
			/* translators: %d: the highest possible score for a type. */
			$lines[] = sprintf( __( 'ציונים (מתוך %d)', 'kivun' ), $score['max'] ) . ': ' . implode( ' · ', $parts );

			foreach ( self::sections() as $section => $meta ) {
				$parts = array();
				foreach ( self::TYPES as $type ) {
					$parts[] = $type . ' ' . $score['sections'][ $section ][ $type ];
				}
				$lines[] = $meta['title'] . ': ' . implode( ' · ', $parts );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * The unfinished row this person already has, if any.
	 *
	 * Matched on the phone and the ID number together. A finished row is
	 * never reopened — taking the questionnaire again is a new row, so the
	 * first result is still there to compare with.
	 *
	 * @param string $id    Normalized ID number.
	 * @param string $phone Normalized phone.
	 * @return array{lead_id:int,responses:string}|null
	 */
	private static function find_open( string $id, string $phone ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, answers FROM {$wpdb->prefix}kivun_registrations
				 WHERE type = %s AND phone = %s AND answers LIKE %s
				 ORDER BY id DESC LIMIT 5",
				self::LEAD_TYPE,
				$phone,
				'%' . $wpdb->esc_like( '"value":"' . $id . '"' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$stored = self::read_stored( (string) $row->answers );
			if ( $stored['id'] === $id && ! $stored['done'] ) {
				return array(
					'lead_id'   => (int) $row->id,
					'responses' => $stored['responses'],
				);
			}
		}

		return null;
	}

	// ── Endpoints ─────────────────────────────────────────────────────────────

	/**
	 * Log in: check the details, and open (or reopen) the lead row.
	 *
	 * @return void
	 */
	public static function ajax_start(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$phone_raw = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$id_raw    = sanitize_text_field( wp_unslash( $_POST['id_number'] ?? '' ) );
		$phone     = self::normalize_phone( $phone_raw );
		$id        = self::normalize_id( $id_raw );
		$page_id   = absint( $_POST['page'] ?? 0 );

		// The wording is the design handoff's: "יש למלא" for an empty field,
		// "אינו תקין" for one filled in wrong.
		$errors = array();
		if ( '' === trim( $name ) ) {
			$errors['name'] = __( 'יש למלא שם מלא', 'kivun' );
		} elseif ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'השם אינו תקין', 'kivun' );
		}
		if ( '' === trim( $phone_raw ) ) {
			$errors['phone'] = __( 'יש למלא מספר טלפון', 'kivun' );
		} elseif ( '' === $phone ) {
			$errors['phone'] = __( 'מספר הטלפון אינו תקין', 'kivun' );
		}
		if ( '' === trim( $id_raw ) ) {
			$errors['id_number'] = __( 'יש למלא תעודת זהות', 'kivun' );
		} elseif ( '' === $id ) {
			$errors['id_number'] = __( 'מספר תעודת הזהות אינו תקין', 'kivun' );
		}
		if ( Kivun_Privacy_Consent::asked() && ! Kivun_Privacy_Consent::given( $_POST ) ) {
			$errors['privacy_consent'] = __( 'יש לאשר את מדיניות הפרטיות', 'kivun' );
		}
		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'יש לתקן את השדות המסומנים כדי להמשיך.', 'kivun' ),
					'fields'  => $errors,
				)
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivun_registrations';

		$open = self::find_open( $id, $phone );
		if ( $open ) {
			// The name as typed today; the rest of the row stays as it was.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'name' => $name ), array( 'id' => $open['lead_id'] ), array( '%s' ), array( '%d' ) );

			wp_send_json_success(
				array(
					'lead'      => $open['lead_id'],
					'token'     => self::token( $open['lead_id'], $id ),
					'responses' => $open['responses'],
				)
			);
		}

		$responses = self::normalize_responses( '' );
		$title     = $page_id ? (string) get_the_title( $page_id ) : '';
		$row       = array(
			'course_id'       => $page_id,
			'name'            => $name,
			'email'           => '',
			'phone'           => $phone,
			'privacy_consent' => Kivun_Privacy_Consent::given( $_POST ) ? 1 : 0,
			'message'         => self::message( $id, $responses ),
			'source'          => Kivun_Utm::append_source( '' !== $title ? $title : __( 'שאלון הולנד', 'kivun' ) ),
			'status'          => 'new_lead',
			'type'            => self::LEAD_TYPE,
			'answers'         => (string) wp_json_encode( self::stored_answers( $id, $responses ) ),
			'created_at'      => current_time( 'mysql' ),
		);
		$integers  = array( 'course_id', 'privacy_consent' );
		$fmt       = array();
		foreach ( array_keys( $row ) as $column ) {
			$fmt[] = in_array( $column, $integers, true ) ? '%d' : '%s';
		}
		foreach ( Kivun_Utm::columns() as $column => $value ) {
			$row[ $column ] = $value;
			$fmt[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $table, $row, $fmt );
		$lead_id = (int) $wpdb->insert_id;

		if ( $lead_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'אירעה שגיאה, נסה שוב.', 'kivun' ) ) );
		}

		Kivun_Coordinators::attach_to_lead( $lead_id, $page_id );

		/**
		 * Fires when somebody logs in to the Holland questionnaire for the
		 * first time (not when they come back to finish).
		 *
		 * @param int    $lead_id The lead row.
		 * @param string $name    Their name.
		 * @param string $phone   Their phone, normalized.
		 */
		do_action( 'kivun_holland_started', $lead_id, $name, $phone );

		wp_send_json_success(
			array(
				'lead'      => $lead_id,
				'token'     => self::token( $lead_id, $id ),
				'responses' => $responses,
			)
		);
	}

	/**
	 * Save the answers so far; on "finish", with every answer there, close
	 * the questionnaire. The result is not sent back — the participant sees a
	 * thank-you, and the representative prints the report.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$lead_id = absint( $_POST['lead'] ?? 0 );
		$token   = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$posted  = self::normalize_responses( sanitize_text_field( wp_unslash( $_POST['responses'] ?? '' ) ) );
		$finish  = ! empty( $_POST['finish'] );
		$seq     = absint( $_POST['seq'] ?? 0 );

		global $wpdb;
		$table = $wpdb->prefix . 'kivun_registrations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $lead_id ? $wpdb->get_row(
			$wpdb->prepare( "SELECT id, answers FROM {$table} WHERE id = %d AND type = %s", $lead_id, self::LEAD_TYPE ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$stored = $row ? self::read_stored( (string) $row->answers ) : null;
		if ( ! $stored || '' === $stored['id'] || ! hash_equals( self::token( $lead_id, $stored['id'] ), $token ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'החיבור לשאלון פג. נא להתחבר שוב עם אותם פרטים — התשובות שנשמרו לא יאבדו.', 'kivun' ),
					'relogin' => true,
				)
			);
		}

		// A finished questionnaire is a record. Posting to it again changes
		// nothing, and is answered as finished.
		if ( $stored['done'] ) {
			wp_send_json_success( array( 'done' => true ) );
		}

		// Saves can arrive out of order — an autosave still in flight when
		// the next one leaves, or the one sent as the tab closes. An older
		// snapshot must not overwrite a newer one, so each carries the time
		// it left and anything older than what is stored is dropped.
		if ( ! $finish && $seq > 0 && $seq < $stored['seq'] ) {
			wp_send_json_success( array( 'saved' => self::answered( $stored['responses'] ) ) );
		}
		$seq = max( $seq, $stored['seq'] );

		if ( $finish && self::answered( $posted ) < self::total() ) {
			wp_send_json_error(
				array(
					/* translators: %d: how many questions are still unanswered. */
					'message' => sprintf( __( 'נותרו %d שאלות ללא תשובה.', 'kivun' ), self::total() - self::answered( $posted ) ),
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'message' => self::message( $stored['id'], $posted, $finish ),
				'answers' => (string) wp_json_encode( self::stored_answers( $stored['id'], $posted, $finish, $seq, $finish ? (string) current_time( 'mysql' ) : '' ) ),
			),
			array( 'id' => $lead_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Every answer there but "finish" not pressed: saved, and still open.
		if ( ! $finish ) {
			wp_send_json_success( array( 'saved' => self::answered( $posted ) ) );
		}

		/**
		 * Fires once, when somebody submits the last answers.
		 *
		 * @param int   $lead_id The lead row.
		 * @param array $score   The result, as score() returns it.
		 */
		do_action( 'kivun_holland_completed', $lead_id, self::score( $posted ) );

		// The participant is thanked, not shown the result: the representative
		// goes through it with them, from the printed report.
		wp_send_json_success( array( 'done' => true ) );
	}

	// ── The page ──────────────────────────────────────────────────────────────

	/**
	 * The shortcode: the whole questionnaire, from the login to the result.
	 *
	 * @return string
	 */
	public static function render(): string {
		// The page carries a nonce and answers that are somebody's own; a
		// cached copy would serve both to the next visitor.
		Kivun_Core::no_cache();
		self::enqueue();

		ob_start();
		kivun_get_template(
			'holland/questionnaire.php',
			array(
				'page_id'  => (int) get_the_ID(),
				'sections' => self::sections(),
				'order'    => self::asking_order(),
				'items'    => self::items(),
				'total'    => self::total(),
				'per_page' => self::PAGE_SIZE,
			)
		);
		return Kivun_Content_Creator::flatten_output( (string) ob_get_clean() );
	}

	/**
	 * Everything the report shows, from a stored lead row.
	 *
	 * @param object $row The lead row (name, phone, created_at, answers).
	 * @return array<string,mixed>
	 */
	public static function report_data( $row ): array {
		$stored = self::read_stored( (string) ( $row->answers ?? '' ) );
		$score  = self::score( $stored['responses'] );
		$ranked = self::rank( $score['totals'] );
		$top    = '' === $score['code'] ? array() : str_split( $score['code'] );

		// The professions the questionnaire itself asked about, by type, the
		// ones answered "yes" first.
		$jobs = array();
		foreach ( self::items() as $num => $item ) {
			if ( 'professions' !== $item['section'] ) {
				continue;
			}
			$jobs[ $item['type'] ][] = array(
				'text' => $item['text'],
				'yes'  => 'y' === ( $stored['responses'][ $num - 1 ] ?? '' ),
			);
		}
		foreach ( $jobs as $type => $list ) {
			usort( $list, static fn( array $a, array $b ): int => (int) $b['yes'] <=> (int) $a['yes'] );
			$jobs[ $type ] = $list;
		}

		$when = '' !== $stored['done_at'] ? $stored['done_at'] : (string) ( $row->created_at ?? '' );

		return array(
			'name'     => (string) ( $row->name ?? '' ),
			'phone'    => (string) ( $row->phone ?? '' ),
			'id'       => $stored['id'],
			'date'     => '' !== $when ? kivun_local_date( $when, 'j.n.Y' ) : '',
			'done'     => $stored['done'],
			'score'    => $score,
			'ranked'   => $ranked,
			'top'      => $top,
			'rest'     => array_values( array_diff( $ranked, $top ) ),
			'jobs'     => $jobs,
			'info'     => self::type_info(),
			'colors'   => self::type_colors(),
			'intro'    => self::results_intro(),
			'sections' => self::sections(),
			'title'    => self::title(),
			'logo'     => self::logo_url(),
		);
	}

	/**
	 * The centre's logo, shipped with the plugin.
	 *
	 * @return string
	 */
	public static function logo_url(): string {
		return KIVUN_URL . 'assets/images/kivun-logo.png';
	}

	/**
	 * The address of a lead's printable report.
	 *
	 * @param int $lead_id The lead row.
	 * @return string
	 */
	public static function report_url( int $lead_id ): string {
		return add_query_arg(
			array(
				'action'   => 'kivun_holland_report',
				'lead'     => $lead_id,
				'_wpnonce' => wp_create_nonce( 'kivun_holland_report' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Whether a lead row is a finished questionnaire, with a report to print.
	 *
	 * @param object $row The lead row.
	 * @return bool
	 */
	public static function has_report( $row ): bool {
		return self::LEAD_TYPE === (string) ( $row->type ?? '' )
			&& self::read_stored( (string) ( $row->answers ?? '' ) )['done'];
	}

	/**
	 * The report as a page of its own: A4, ready to print.
	 *
	 * For the people who handle the leads, and only them — it carries the
	 * participant's name, phone and ID number.
	 *
	 * @return void
	 */
	public static function ajax_report(): void {
		if ( ! Kivun_Content_Creator::can_manage_leads() ) {
			wp_die( esc_html__( 'אין לך הרשאה לצפות בדוח.', 'kivun' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kivun_holland_report' );

		$lead_id = absint( $_GET['lead'] ?? 0 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $lead_id ? $wpdb->get_row(
			$wpdb->prepare( "SELECT id, name, phone, created_at, answers, type FROM {$wpdb->prefix}kivun_registrations WHERE id = %d AND type = %s", $lead_id, self::LEAD_TYPE )
		) : null;

		if ( ! $row || ! self::has_report( $row ) ) {
			wp_die( esc_html__( 'הדוח לא נמצא, או שהשאלון עדיין לא הושלם.', 'kivun' ), '', array( 'response' => 404 ) );
		}

		Kivun_Core::no_cache();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::report_html( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		exit;
	}

	/**
	 * The report page's markup.
	 *
	 * @param object $row The lead row.
	 * @return string
	 */
	public static function report_html( $row ): string {
		ob_start();
		kivun_get_template( 'holland/report.php', self::report_data( $row ) );
		return (string) ob_get_clean();
	}
}
