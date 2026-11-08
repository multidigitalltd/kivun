<?php
/**
 * Template: the Holland questionnaire's result.
 *
 * The code first, then every type's score, then what the three leading types
 * mean, and the part-by-part breakdown for whoever goes through it with them.
 *
 * Expects $score, $info, $ranked, $top, $intro and $sections.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_max = max( 1, (int) $score['max'] );
?>
<div class="kivun-hl-card kivun-hl-result">
	<h2 class="kivun-hl-title" tabindex="-1" data-hl-focus><?php esc_html_e( 'התוצאות שלך', 'kivun' ); ?></h2>

	<div class="kivun-hl-code">
		<p class="kivun-hl-code__label"><?php esc_html_e( 'קוד הולנד שלך', 'kivun' ); ?></p>
		<p class="kivun-hl-code__value" dir="ltr"><?php echo esc_html( implode( ' ', str_split( (string) $score['code'] ) ) ); ?></p>
		<p class="kivun-hl-code__names">
		<?php
		echo esc_html(
			implode(
				' · ',
				array_map(
					static fn( string $t ): string => $info[ $t ]['name'],
					$top
				)
			)
		);
		?>
		</p>
	</div>

	<?php if ( ! empty( $score['tie'] ) ) : ?>
		<p class="kivun-hl-note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: third type's name, 2: fourth type's name, 3: their shared score. */
				__( 'שים לב: %1$s ו%2$s קיבלו ניקוד זהה (%3$d), ולכן שניהם רלוונטיים כמקום השלישי.', 'kivun' ),
				$info[ $ranked[2] ]['name'],
				$info[ $ranked[3] ]['name'],
				(int) $score['totals'][ $ranked[2] ]
			)
		);
		?>
		</p>
	<?php endif; ?>

	<?php foreach ( $intro as $kivun_hl_para ) : ?>
		<p class="kivun-hl-text"><?php echo esc_html( $kivun_hl_para ); ?></p>
	<?php endforeach; ?>

	<h3 class="kivun-hl-subtitle"><?php esc_html_e( 'הניקוד שלך בכל תחום', 'kivun' ); ?></h3>
	<ul class="kivun-hl-bars">
		<?php foreach ( $ranked as $kivun_hl_t ) : ?>
			<?php
			$kivun_hl_n   = (int) $score['totals'][ $kivun_hl_t ];
			$kivun_hl_pct = (int) round( 100 * $kivun_hl_n / $kivun_hl_max );
			?>
			<li class="kivun-hl-bars__row<?php echo in_array( $kivun_hl_t, $top, true ) ? ' is-top' : ''; ?>">
				<span class="kivun-hl-bars__name"><?php echo esc_html( $info[ $kivun_hl_t ]['name'] . ' (' . $kivun_hl_t . ')' ); ?></span>
				<span class="kivun-hl-bars__track" aria-hidden="true"><span class="kivun-hl-bars__fill" style="width:<?php echo esc_attr( (string) $kivun_hl_pct ); ?>%"></span></span>
				<span class="kivun-hl-bars__num">
				<?php
				/* translators: 1: score, 2: highest possible score. */
				echo esc_html( sprintf( __( '%1$d מתוך %2$d', 'kivun' ), $kivun_hl_n, $kivun_hl_max ) );
				?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<h3 class="kivun-hl-subtitle"><?php esc_html_e( 'שלושת התחומים הבולטים אצלך', 'kivun' ); ?></h3>
	<?php foreach ( $top as $kivun_hl_i => $kivun_hl_t ) : ?>
		<div class="kivun-hl-type">
			<h4 class="kivun-hl-type__title"><span class="kivun-hl-type__rank"><?php echo esc_html( (string) ( $kivun_hl_i + 1 ) ); ?></span> <?php echo esc_html( $info[ $kivun_hl_t ]['name'] . ' (' . $kivun_hl_t . ')' ); ?></h4>
			<p class="kivun-hl-type__text"><?php echo esc_html( $info[ $kivun_hl_t ]['about'] ); ?></p>
			<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'מאפיינים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['traits'] ); ?></p>
			<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'כיוונים מתאימים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['fields'] ); ?></p>
		</div>
	<?php endforeach; ?>

	<details class="kivun-hl-more">
		<summary class="kivun-hl-more__summary"><?php esc_html_e( 'שאר התחומים', 'kivun' ); ?></summary>
		<?php foreach ( array_slice( $ranked, 3 ) as $kivun_hl_t ) : ?>
			<div class="kivun-hl-type kivun-hl-type--muted">
				<h4 class="kivun-hl-type__title"><?php echo esc_html( $info[ $kivun_hl_t ]['name'] . ' (' . $kivun_hl_t . ')' ); ?></h4>
				<p class="kivun-hl-type__text"><?php echo esc_html( $info[ $kivun_hl_t ]['about'] ); ?></p>
				<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'כיוונים מתאימים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['fields'] ); ?></p>
			</div>
		<?php endforeach; ?>
	</details>

	<h3 class="kivun-hl-subtitle"><?php esc_html_e( 'פירוט לפי חלקי השאלון', 'kivun' ); ?></h3>
	<div class="kivun-hl-table-wrap">
		<table class="kivun-hl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'חלק', 'kivun' ); ?></th>
					<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
						<th scope="col" title="<?php echo esc_attr( $info[ $kivun_hl_t ]['name'] ); ?>"><?php echo esc_html( $kivun_hl_t ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sections as $kivun_hl_key => $kivun_hl_section ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $kivun_hl_section['title'] ); ?></th>
						<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
							<td><?php echo esc_html( (string) $score['sections'][ $kivun_hl_key ][ $kivun_hl_t ] ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				<tr class="kivun-hl-table__total">
					<th scope="row"><?php esc_html_e( 'סה"כ', 'kivun' ); ?></th>
					<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
						<td><?php echo esc_html( (string) $score['totals'][ $kivun_hl_t ] ); ?></td>
					<?php endforeach; ?>
				</tr>
			</tbody>
		</table>
	</div>

	<p class="kivun-hl-text kivun-hl-text--muted"><?php esc_html_e( 'התוצאות נשמרו אצל צוות מרכז כיוון, שיוכל להמשיך וללוות אותך בבחירת הכיוון המקצועי.', 'kivun' ); ?></p>

	<div class="kivun-hl-nav kivun-hl-nav--end">
		<button type="button" class="kivun-hl-btn kivun-hl-btn--ghost" data-hl-print><?php esc_html_e( 'הדפסת התוצאות', 'kivun' ); ?></button>
	</div>
</div>
