<?php
/**
 * Template: the Holland questionnaire's report, for the representative.
 *
 * A page of its own, A4 and ready to print, drawn to the design handoff
 * "design_handoff_holland_v2" (Holland Results Print.dc.html). The styles are
 * here rather than in the plugin's stylesheets because this page loads
 * nothing else: it is not drawn inside the site's theme, so there is no
 * header, footer or reset to fight.
 *
 * The content and its order are the client's results document: (1) what
 * occupational tendencies and the Holland code are, (2) the three-letter
 * code with a short line on each of its domains, (3) every score, as a
 * table and a chart, and (4) the detailed explanation of all six types.
 *
 * Expects $name, $phone, $id, $date, $score, $top, $sentence, $info,
 * $colors, $intro, $explain, $sections, $title and $logo.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_max = max( 1, (int) $score['max'] );
$kivun_hl_var = static function ( string $t ) use ( $colors ): string {
	return sprintf( '--c:%s;--t:%s;--tint:%s;--fg:%s', $colors[ $t ]['c'], $colors[ $t ]['t'], $colors[ $t ]['tint'], $colors[ $t ]['fg'] );
};
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>
<?php
/* translators: 1: report title, 2: participant's name. */
echo esc_html( sprintf( __( '%1$s – %2$s', 'kivun' ), $title, $name ) );
?>
</title>
<style>
@page { size: A4; margin: 14mm; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
html { background: #f3f1f1; }
body {
	margin: 0;
	padding: 32px 16px;
	font-family: 'Ploni', 'Ploni ML v2 AAA', 'Heebo', 'Assistant', sans-serif;
	font-size: 13.5px;
	line-height: 1.6;
	color: #232323;
	direction: rtl;
	text-align: right;
}
.r-sheet {
	max-width: 210mm;
	margin: 0 auto;
	padding: 14mm;
	background: #fff;
	box-shadow: 0 1px 2px rgba(60, 20, 30, .04), 0 18px 48px -16px rgba(60, 20, 30, .16);
}
.r-toolbar { max-width: 210mm; margin: 0 auto 16px; display: flex; justify-content: flex-start; gap: 10px; }
.r-print {
	display: inline-flex; align-items: center; gap: 8px; height: 48px; padding: 0 28px; border: 0; border-radius: 999px;
	background: #de2952; color: #fff; font: inherit; font-size: 17px; font-weight: 700; cursor: pointer;
	box-shadow: 0 12px 26px -12px rgba(222, 41, 82, .75);
}
.r-print:hover { background: #c41f45; }
.r-print:focus-visible { outline: 2px solid #232323; outline-offset: 3px; }
p { margin: 0; }

.r-head { display: flex; justify-content: space-between; align-items: center; padding-bottom: 14px; border-bottom: 2px solid #de2952; }
.r-head img { display: block; width: auto; height: 62px; margin: -6px -8px -6px 0; }
.r-title { margin: 22px 0 0; font-size: 28px; font-weight: 800; line-height: 1.2; }

.r-person {
	display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 18px;
	border: 1px solid #ece7e8; border-radius: 12px; overflow: hidden; break-inside: avoid;
}
.r-person__cell { display: flex; flex-direction: column; gap: 2px; padding: 10px 14px; border-left: 1px solid #ece7e8; }
.r-person__cell:last-child { border-left: 0; }
.r-person__k { font-size: 11.5px; font-weight: 600; color: #6b6468; }
.r-person__v { font-size: 15px; font-weight: 700; }

.r-profile {
	display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 22px; padding: 20px 22px;
	border: 1px solid #f6e3e8; border-radius: 16px; background: #fdf5f7; break-inside: avoid;
}
.r-tiles { display: flex; gap: 12px; }
.r-tile { display: flex; flex-direction: column; align-items: center; gap: 6px; width: 84px; }
.r-tile__box {
	display: flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 18px;
	background: var(--c); color: var(--fg); font-size: 36px; font-weight: 800; line-height: 1;
}
.r-tile--empty .r-tile__box { border: 2px dashed #d6cfd1; background: #fff; color: #b5aeb1; }
.r-tile__name { font-size: 11.5px; font-weight: 700; line-height: 1.3; text-align: center; }
.r-profile__note { font-size: 13px; font-weight: 600; color: #4d474a; text-align: center; }
.r-profile__none { font-size: 15px; font-weight: 800; }

.r-intro { display: flex; flex-direction: column; gap: 8px; margin-top: 20px; }

.r-chart {
	display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 2px; margin-top: 22px; padding: 18px 14px 14px;
	border: 1px solid #ece7e8; border-radius: 16px; break-inside: avoid; list-style: none;
}
.r-col { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; }
.r-col__pct { font-size: 13px; font-weight: 800; color: #6b6468; }
.r-col.is-top .r-col__pct { color: var(--t); }
.r-col__track { display: flex; align-items: flex-end; width: 40px; height: 120px; overflow: hidden; border-radius: 10px; background: #f0eaeb; }
.r-col__bar { width: 100%; border-radius: 10px; background: #d6cfd1; }
.r-col.is-top .r-col__bar { background: var(--c); }
.r-col__l { margin-top: 2px; font-size: 16px; font-weight: 800; }
.r-col__name { font-size: 11px; font-weight: 600; line-height: 1.3; white-space: nowrap; }
.r-col__n { font-size: 11px; color: #6b6468; white-space: nowrap; }

.r-cards { display: flex; flex-direction: column; gap: 14px; }
.r-explain { break-before: page; }
.r-explain > .r-h2:first-child { margin-top: 0; }
.r-more { margin-top: 10px; font-size: 13px; font-weight: 600; color: #6b6468; }
.r-card { border: 1px solid #ece7e8; border-radius: 16px; break-inside: avoid; }
.r-card__head { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 15px 15px 0 0; background: var(--tint); }
.r-card__l {
	display: flex; flex: none; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px;
	background: var(--c); color: var(--fg); font-size: 22px; font-weight: 800;
}
.r-card__name { font-size: 17px; font-weight: 800; }
.r-iso { unicode-bidi: isolate; }
.r-card__body { display: flex; flex-direction: column; gap: 8px; padding: 12px 16px 14px; }
.r-card__boxes { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.r-card__box { padding: 8px 12px; border-radius: 10px; background: #fbf8f8; }
.r-h2 { margin: 26px 0 10px; font-size: 18px; font-weight: 800; }
.r-rest { display: flex; flex-direction: column; gap: 8px; }
.r-rest__row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0ebec; break-inside: avoid; }
.r-rest__l {
	display: flex; flex: none; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px;
	background: var(--c); color: var(--fg); font-size: 15px; font-weight: 800;
}

.r-table-wrap { margin-top: 26px; break-inside: avoid; }
.r-table-wrap .r-h2 { margin-top: 0; }
table.r-table { width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid #ece7e8; border-radius: 12px; overflow: hidden; font-size: 13px; }
.r-table th, .r-table td { padding: 8px 4px; border: none; background: #fff; text-align: center; }
.r-table thead th { border-bottom: 1px solid #ece7e8; }
.r-table th.r-table__k { padding: 8px 12px; font-weight: 700; color: #6b6468; text-align: right; }
.r-table tbody td { border-bottom: 1px solid #f3efef; }
.r-table tbody td.r-table__k { padding: 8px 12px; font-weight: 600; text-align: right; }
.r-table tbody tr.r-table__total td { border-bottom: 0; background: #fbf8f8; font-weight: 800; }
.r-table__l {
	display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 7px;
	background: var(--c); color: var(--fg); font-weight: 800;
}

@media print {
	html, body { background: #fff; }
	body { padding: 0; }
	.r-toolbar { display: none; }
	.r-sheet { max-width: none; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<div class="r-toolbar"><button type="button" class="r-print" onclick="window.print()"><?php esc_html_e( 'הדפסה', 'kivun' ); ?></button></div>
<main class="r-sheet" lang="he" dir="rtl">

	<div class="r-head"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php esc_attr_e( 'מרכזי הכוון לתעסוקה ולימודים – כיוון ירושלים', 'kivun' ); ?>"></div>

	<h1 class="r-title"><?php echo esc_html( $title ); ?></h1>

	<div class="r-person">
		<?php
		foreach ( array(
			__( 'שם מלא', 'kivun' )      => $name,
			__( 'טלפון', 'kivun' )       => $phone,
			__( 'תעודת זהות', 'kivun' )  => $id,
			__( 'תאריך מילוי', 'kivun' ) => $date,
		) as $kivun_hl_k => $kivun_hl_v ) :
			?>
			<div class="r-person__cell"><span class="r-person__k"><?php echo esc_html( $kivun_hl_k ); ?></span><span class="r-person__v" dir="auto"><?php echo esc_html( $kivun_hl_v ); ?></span></div>
		<?php endforeach; ?>
	</div>

	<?php // ── 1. Occupational tendencies and the Holland code ───────────── ?>
	<h2 class="r-h2"><?php esc_html_e( 'נטיות תעסוקתיות', 'kivun' ); ?></h2>
	<div class="r-intro">
		<?php foreach ( $intro as $kivun_hl_para ) : ?>
			<p class="r-intro__p"><?php echo wp_kses( $kivun_hl_para, array( 'b' => array() ) ); ?></p>
		<?php endforeach; ?>
	</div>

	<?php // ── 2. The code, and a line on each of its domains ──────────── ?>
	<div class="r-profile">
		<?php if ( $top ) : ?>
			<p class="r-profile__none">
				<?php esc_html_e( 'להלן הקוד', 'kivun' ); ?>
				<span class="r-iso" dir="ltr"><?php echo esc_html( implode( '', $top ) ); ?></span>
			</p>
			<div class="r-tiles">
				<?php foreach ( $top as $kivun_hl_t ) : ?>
					<div class="r-tile" style="<?php echo esc_attr( $kivun_hl_var( $kivun_hl_t ) ); ?>"><div class="r-tile__box"><?php echo esc_html( $kivun_hl_t ); ?></div><span class="r-tile__name"><?php echo esc_html( $info[ $kivun_hl_t ]['domain'] ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<p class="r-profile__note"><?php echo esc_html( $sentence ); ?></p>
		<?php else : ?>
			<div class="r-tiles">
				<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
					<div class="r-tile r-tile--empty"><div class="r-tile__box"><?php echo esc_html( $kivun_hl_t ); ?></div></div>
				<?php endforeach; ?>
			</div>
			<p class="r-profile__none"><?php esc_html_e( 'לא נבנה פרופיל', 'kivun' ); ?></p>
			<p class="r-profile__note"><?php esc_html_e( 'לא סומנו תשובות "כן", ולכן אין תחום בולט.', 'kivun' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $top ) : ?>
		<div class="r-rest">
			<?php foreach ( $top as $kivun_hl_t ) : ?>
				<div class="r-rest__row" style="<?php echo esc_attr( $kivun_hl_var( $kivun_hl_t ) ); ?>">
					<span class="r-rest__l"><?php echo esc_html( $kivun_hl_t ); ?></span>
					<div class="r-rest__text"><b><?php echo esc_html( __( 'תחום', 'kivun' ) . ' ' . $info[ $kivun_hl_t ]['domain'] ); ?></b> (<?php echo esc_html( rtrim( $info[ $kivun_hl_t ]['about'], '.' ) ); ?>)</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="r-more"><?php esc_html_e( 'לפירוט התחומים ומשמעותם ראה בהמשך', 'kivun' ); ?></p>
	<?php endif; ?>

	<?php // ── 3. Every score: the table, then the chart ───────────────── ?>
	<div class="r-table-wrap">
		<h2 class="r-h2"><?php esc_html_e( 'פירוט הבחירות', 'kivun' ); ?></h2>
		<table class="r-table">
			<thead>
				<tr>
					<th class="r-table__k" scope="col"><?php esc_html_e( 'חלק', 'kivun' ); ?></th>
					<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
						<th scope="col" title="<?php echo esc_attr( $info[ $kivun_hl_t ]['domain'] ); ?>"><span class="r-table__l" style="<?php echo esc_attr( $kivun_hl_var( $kivun_hl_t ) ); ?>"><?php echo esc_html( $kivun_hl_t ); ?></span></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'סה"כ', 'kivun' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sections as $kivun_hl_key => $kivun_hl_section ) : ?>
					<tr>
						<td class="r-table__k"><?php echo esc_html( $kivun_hl_section['title'] ); ?></td>
						<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
							<td><?php echo esc_html( (string) $score['sections'][ $kivun_hl_key ][ $kivun_hl_t ] ); ?></td>
						<?php endforeach; ?>
						<td><?php echo esc_html( (string) array_sum( $score['sections'][ $kivun_hl_key ] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="r-table__total">
					<td class="r-table__k"><?php esc_html_e( 'סה"כ', 'kivun' ); ?></td>
					<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
						<td><?php echo esc_html( (string) $score['totals'][ $kivun_hl_t ] ); ?></td>
					<?php endforeach; ?>
					<td><?php echo esc_html( (string) array_sum( $score['totals'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<ul class="r-chart">
		<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
			<?php $kivun_hl_n = (int) $score['totals'][ $kivun_hl_t ]; ?>
			<li class="r-col<?php echo in_array( $kivun_hl_t, $top, true ) ? ' is-top' : ''; ?>" style="<?php echo esc_attr( $kivun_hl_var( $kivun_hl_t ) ); ?>">
				<span class="r-col__pct"><?php echo esc_html( (int) round( 100 * $kivun_hl_n / $kivun_hl_max ) . '%' ); ?></span>
				<span class="r-col__track"><span class="r-col__bar" style="height:<?php echo esc_attr( (string) (int) round( 120 * $kivun_hl_n / $kivun_hl_max ) ); ?>px"></span></span>
				<span class="r-col__l"><?php echo esc_html( $kivun_hl_t ); ?></span>
				<span class="r-col__name"><?php echo esc_html( $info[ $kivun_hl_t ]['domain'] ); ?></span>
				<span class="r-col__n" dir="ltr"><?php echo esc_html( $kivun_hl_n . '/' . $kivun_hl_max ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php // ── 4. The six types, explained ─────────────────────────────── ?>
	<div class="r-explain">
		<h2 class="r-h2"><?php esc_html_e( 'הסבר על נטיות תעסוקתיות', 'kivun' ); ?></h2>
		<div class="r-intro">
			<?php foreach ( $explain as $kivun_hl_para ) : ?>
				<p class="r-intro__p"><?php echo esc_html( $kivun_hl_para ); ?></p>
			<?php endforeach; ?>
		</div>

		<h2 class="r-h2"><?php esc_html_e( 'אפיונים של תחומים מקצועיים', 'kivun' ); ?></h2>
		<div class="r-cards">
			<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
				<div class="r-card" style="<?php echo esc_attr( $kivun_hl_var( $kivun_hl_t ) ); ?>">
					<div class="r-card__head">
						<div class="r-card__l"><?php echo esc_html( $kivun_hl_t ); ?></div>
						<span class="r-card__name"><span class="r-iso" dir="ltr">(<?php echo esc_html( $kivun_hl_t ); ?>)</span> <?php echo esc_html( $info[ $kivun_hl_t ]['name'] ); ?></span>
					</div>
					<div class="r-card__body">
						<p class="r-card__about"><?php echo esc_html( $info[ $kivun_hl_t ]['about'] ); ?></p>
						<div class="r-card__boxes">
							<div class="r-card__box"><b><?php esc_html_e( 'אפיונים:', 'kivun' ); ?> </b><?php echo esc_html( $info[ $kivun_hl_t ]['traits'] ); ?></div>
							<div class="r-card__box"><b><?php esc_html_e( 'מקצועות:', 'kivun' ); ?> </b><?php echo esc_html( $info[ $kivun_hl_t ]['fields'] ); ?></div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

</main>
</body>
</html>
