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
	 * Items shown on one page of the questionnaire.
	 */
	const PAGE_SIZE = 12;

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
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$haystack = (string) $post->post_content . (string) get_post_meta( $post->ID, '_elementor_data', true );
		if ( false !== strpos( $haystack, '[kivun_holland' ) ) {
			self::enqueue();
		}
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
	 * The three parts, with the instructions the spreadsheet gives each.
	 *
	 * @return array<string,array{title:string,instructions:string}>
	 */
	public static function sections(): array {
		return array(
			'activities'  => array(
				'title'        => __( 'פעילויות', 'kivun' ),
				'instructions' => __( 'בחר ב"כן" עבור פעילויות שהנך אוהב לעשות או שיש לך עניין ללמוד לעשות. בחר ב"לא" עבור פעילויות שאינך אוהב לעשות או שאין לך כל יחס אליהן ואין לך כל עניין ללמוד לעשות.', 'kivun' ),
			),
			'skills'      => array(
				'title'        => __( 'כשרים', 'kivun' ),
				'instructions' => __( 'בחר ב"כן" את הפעילויות שהנך יכול לבצע היטב או במומחיות. בחר ב"לא" את הפעילויות שמעולם לא ביצעת או שביצעת באופן גרוע.', 'kivun' ),
			),
			'professions' => array(
				'title'        => __( 'מקצועות', 'kivun' ),
				'instructions' => __( 'בחר ב"כן" את המקצועות שמעניינים או מושכים אותך. בחר ב"לא" את המקצועות שאינך מחבב או שאתה מוצא אותם כלא מעניינים.', 'kivun' ),
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

		return array(
			'totals'   => $totals,
			'sections' => $sections,
			'code'     => implode( '', array_slice( $ranked, 0, 3 ) ),
			// The third letter is a choice, not a finding, when the fourth
			// type scored the same. Said on the page rather than hidden.
			'tie'      => $totals[ $ranked[2] ] === $totals[ $ranked[3] ],
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
	 * The raw answers ride along without a label, which is what keeps them
	 * out of the export's columns.
	 *
	 * @param string $id        Normalized ID number.
	 * @param string $responses Normalized answers.
	 * @return array<int,array<string,string>>
	 */
	public static function stored_answers( string $id, string $responses ): array {
		$done = self::answered( $responses ) === self::total();
		$out  = array(
			array(
				'key'   => 'holland_id',
				'label' => __( 'תעודת זהות', 'kivun' ),
				'value' => $id,
			),
			array(
				'key'   => 'holland_progress',
				'label' => __( 'שאלון הולנד', 'kivun' ),
				'value' => self::progress_label( $responses ),
			),
		);

		if ( $done ) {
			$score = self::score( $responses );
			$info  = self::type_info();
			$out[] = array(
				'key'   => 'holland_code',
				'label' => __( 'קוד הולנד', 'kivun' ),
				'value' => $score['code'],
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

		return $out;
	}

	/**
	 * Read the ID number and the answers back out of a stored row.
	 *
	 * @param string $stored The `answers` column.
	 * @return array{id:string,responses:string}
	 */
	public static function read_stored( string $stored ): array {
		$out     = array(
			'id'        => '',
			'responses' => self::normalize_responses( '' ),
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
			}
		}
		return $out;
	}

	/**
	 * "Finished" or "in progress, N of 216", for the console and the export.
	 *
	 * @param string $responses Normalized answers.
	 * @return string
	 */
	public static function progress_label( string $responses ): string {
		$answered = self::answered( $responses );
		$total    = self::total();
		if ( $answered === $total ) {
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
	 * @return string
	 */
	public static function message( string $id, string $responses ): string {
		$lines = array(
			__( 'תעודת זהות', 'kivun' ) . ': ' . $id,
			__( 'שאלון הולנד', 'kivun' ) . ': ' . self::progress_label( $responses ),
		);

		if ( self::answered( $responses ) === self::total() ) {
			$score  = self::score( $responses );
			$ranked = self::rank( $score['totals'] );

			$lines[] = __( 'קוד הולנד', 'kivun' ) . ': ' . $score['code'];

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
			if ( $stored['id'] === $id && self::answered( $stored['responses'] ) < self::total() ) {
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

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$phone   = self::normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		$id      = self::normalize_id( sanitize_text_field( wp_unslash( $_POST['id_number'] ?? '' ) ) );
		$page_id = absint( $_POST['page'] ?? 0 );

		$errors = array();
		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'נא למלא שם מלא.', 'kivun' );
		}
		if ( '' === $phone ) {
			$errors['phone'] = __( 'מספר הטלפון אינו תקין.', 'kivun' );
		}
		if ( '' === $id ) {
			$errors['id_number'] = __( 'מספר תעודת הזהות אינו תקין.', 'kivun' );
		}
		if ( Kivun_Privacy_Consent::asked() && ! Kivun_Privacy_Consent::given( $_POST ) ) {
			$errors['privacy_consent'] = __( 'יש לאשר את מדיניות הפרטיות.', 'kivun' );
		}
		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'נא לתקן את הפרטים המסומנים.', 'kivun' ),
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
	 * Save the answers so far; when they are all there, score them and
	 * return the result page.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$lead_id = absint( $_POST['lead'] ?? 0 );
		$token   = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$posted  = self::normalize_responses( sanitize_text_field( wp_unslash( $_POST['responses'] ?? '' ) ) );
		$finish  = ! empty( $_POST['finish'] );

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
		// nothing; the result is handed back as it was.
		if ( self::answered( $stored['responses'] ) === self::total() ) {
			wp_send_json_success( array( 'html' => self::results_html( $stored['responses'] ) ) );
		}

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
				'message' => self::message( $stored['id'], $posted ),
				'answers' => (string) wp_json_encode( self::stored_answers( $stored['id'], $posted ) ),
			),
			array( 'id' => $lead_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( self::answered( $posted ) < self::total() ) {
			wp_send_json_success( array( 'saved' => self::answered( $posted ) ) );
		}

		/**
		 * Fires once, when somebody answers the last question.
		 *
		 * @param int   $lead_id The lead row.
		 * @param array $score   The result, as score() returns it.
		 */
		do_action( 'kivun_holland_completed', $lead_id, self::score( $posted ) );

		wp_send_json_success( array( 'html' => self::results_html( $posted ) ) );
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
	 * The result page, drawn on the server so its words live in one place.
	 *
	 * @param string $responses A complete set of answers.
	 * @return string
	 */
	public static function results_html( string $responses ): string {
		$score  = self::score( $responses );
		$info   = self::type_info();
		$ranked = self::rank( $score['totals'] );

		ob_start();
		kivun_get_template(
			'holland/results.php',
			array(
				'score'    => $score,
				'info'     => $info,
				'ranked'   => $ranked,
				'top'      => array_slice( $ranked, 0, 3 ),
				'intro'    => self::results_intro(),
				'sections' => self::sections(),
			)
		);
		return Kivun_Content_Creator::flatten_output( (string) ob_get_clean() );
	}
}
