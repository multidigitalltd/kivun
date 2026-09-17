<?php
/**
 * Template: accessibility statement aligned with ת"י 5568 / WCAG 2.2 AA.
 *
 * @package Kivun
 *
 * @var string $org_name    Organisation name.
 * @var string $email       Accessibility contact email.
 * @var string $phone       Accessibility contact phone.
 * @var string $coordinator Accessibility coordinator name.
 */

defined( 'ABSPATH' ) || exit;

$org_name    = isset( $org_name ) ? (string) $org_name : get_bloginfo( 'name' );
$email       = isset( $email ) ? (string) $email : get_option( 'admin_email' );
$phone       = isset( $phone ) ? (string) $phone : '';
$coordinator = isset( $coordinator ) ? (string) $coordinator : '';
$updated     = wp_date( get_option( 'date_format' ) );
?>
<div class="kivun-a11y-statement" dir="rtl">
	<h2><?php esc_html_e( 'הצהרת נגישות', 'kivun' ); ?></h2>

	<p>
		<?php
		printf(
			/* translators: %s: organisation name. */
			esc_html__( '%s רואה חשיבות רבה בהנגשת האתר לכלל המשתמשים, לרבות אנשים עם מוגבלות.', 'kivun' ),
			esc_html( $org_name )
		);
		?>
	</p>

	<h3><?php esc_html_e( 'רמת הנגישות באתר', 'kivun' ); ?></h3>
	<p>
		<?php
		esc_html_e(
			'אתר זה הונגש בהתאם לתקן הישראלי ת"י 5568 לנגישות תכנים באינטרנט, המבוסס על הנחיות WCAG 2.2 ברמת AA.',
			'kivun'
		);
		?>
	</p>

	<h3><?php esc_html_e( 'מה בוצע באתר', 'kivun' ); ?></h3>
	<ul>
		<li><?php esc_html_e( 'הוטמע תפריט נגישות המאפשר התאמות תצוגה אישיות.', 'kivun' ); ?></li>
		<li><?php esc_html_e( 'הוספה אפשרות להגדלה והקטנה של גודל הטקסט.', 'kivun' ); ?></li>
		<li><?php esc_html_e( 'הוספו מצבי ניגודיות גבוהה, היפוך צבעים וגווני אפור.', 'kivun' ); ?></li>
		<li><?php esc_html_e( 'נוספה אפשרות להדגשת קישורים וכותרות וסרגל קריאה.', 'kivun' ); ?></li>
		<li><?php esc_html_e( 'נוספה קישורית "דלג לתוכן" וניווט מלא באמצעות מקלדת.', 'kivun' ); ?></li>
		<li><?php esc_html_e( 'נעשה שימוש בתגיות סמנטיות ובתכונות ARIA לתמיכה בקוראי מסך.', 'kivun' ); ?></li>
	</ul>

	<h3><?php esc_html_e( 'מגבלות ידועות', 'kivun' ); ?></h3>
	<p>
		<?php
		esc_html_e(
			'אנו פועלים באופן שוטף לשיפור נגישות האתר. ייתכן שחלקים מסוימים, ובפרט תכנים של צד שלישי, טרם הונגשו במלואם. נשמח לקבל פניות בנושא ולטפל בהן בהקדם.',
			'kivun'
		);
		?>
	</p>

	<h3><?php esc_html_e( 'רכז הנגישות ופרטי יצירת קשר', 'kivun' ); ?></h3>
	<?php if ( '' !== $coordinator ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: accessibility coordinator name. */
				esc_html__( 'רכז הנגישות: %s', 'kivun' ),
				esc_html( $coordinator )
			);
			?>
		</p>
	<?php endif; ?>

	<ul>
		<?php if ( '' !== $email ) : ?>
			<li>
				<?php esc_html_e( 'דוא"ל:', 'kivun' ); ?>
				<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
			</li>
		<?php endif; ?>
		<?php if ( '' !== $phone ) : ?>
			<li>
				<?php esc_html_e( 'טלפון:', 'kivun' ); ?>
				<a href="<?php echo esc_url( 'tel:' . $phone ); ?>"><?php echo esc_html( $phone ); ?></a>
			</li>
		<?php endif; ?>
	</ul>

	<p class="kivun-a11y-statement__updated">
		<?php
		printf(
			/* translators: %s: last updated date. */
			esc_html__( 'תאריך עדכון ההצהרה: %s', 'kivun' ),
			esc_html( $updated )
		);
		?>
	</p>
</div>
