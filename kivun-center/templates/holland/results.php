<?php
/**
 * Template: the Holland questionnaire's result.
 *
 * The profile first, then a column for each of the six types, then what the
 * leading types mean — each with the professions from the questionnaire that
 * belong to it — and the part-by-part breakdown for whoever goes through it
 * with them.
 *
 * Each type keeps one colour throughout (R green, I purple, A orange,
 * S yellow, E red, C blue), so a letter in the profile, its column and its
 * card read as the same thing. Only the leading types are in colour; the
 * rest are grey, so the eye goes to what matters.
 *
 * Expects $score, $info, $ranked, $top, $jobs, $intro and $sections.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_max = max( 1, (int) $score['max'] );
?>
<div class="kivun-hl-card kivun-hl-result">
	<h2 class="kivun-hl-title" tabindex="-1" data-hl-focus><?php esc_html_e( 'התוצאות שלך', 'kivun' ); ?></h2>

	<div class="kivun-hl-code">
		<p class="kivun-hl-code__label"><?php esc_html_e( 'הפרופיל שלך', 'kivun' ); ?></p>
		<?php if ( $top ) : ?>
			<p class="kivun-hl-code__value" dir="ltr">
				<?php foreach ( $top as $kivun_hl_t ) : ?>
					<span class="kivun-hl-code__letter kivun-hl-t--<?php echo esc_attr( $kivun_hl_t ); ?>"><?php echo esc_html( $kivun_hl_t ); ?></span>
				<?php endforeach; ?>
			</p>
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
		<?php else : ?>
			<p class="kivun-hl-code__names"><?php esc_html_e( 'לא סומנו תשובות "כן", ולכן אין תחום בולט.', 'kivun' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $score['tie'] ) ) : ?>
		<p class="kivun-hl-note">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: the tied types' names, 2: their shared score. */
				__( 'שים לב: %1$s קיבלו ניקוד זהה (%2$d) במקום השלישי, ולכן כולם חלק מהפרופיל שלך.', 'kivun' ),
				implode( ' ו', array_map( static fn( string $t ): string => $info[ $t ]['name'], array_slice( $top, 2 ) ) ),
				(int) $score['totals'][ $top[2] ]
			)
		);
		?>
		</p>
	<?php endif; ?>

	<?php foreach ( $intro as $kivun_hl_para ) : ?>
		<p class="kivun-hl-text"><?php echo esc_html( $kivun_hl_para ); ?></p>
	<?php endforeach; ?>

	<h3 class="kivun-hl-subtitle"><?php esc_html_e( 'הניקוד שלך בכל תחום', 'kivun' ); ?></h3>
	<ul class="kivun-hl-chart">
		<?php foreach ( Kivun_Holland::TYPES as $kivun_hl_t ) : ?>
			<?php
			$kivun_hl_n   = (int) $score['totals'][ $kivun_hl_t ];
			$kivun_hl_pct = (int) round( 100 * $kivun_hl_n / $kivun_hl_max );
			?>
			<li class="kivun-hl-chart__col kivun-hl-t--<?php echo esc_attr( $kivun_hl_t ); ?><?php echo in_array( $kivun_hl_t, $top, true ) ? ' is-top' : ''; ?>">
				<span class="kivun-hl-chart__value">
				<?php echo esc_html( $kivun_hl_pct . '%' ); ?>
				</span>
				<span class="kivun-hl-chart__track" aria-hidden="true"><span class="kivun-hl-chart__bar" style="height:<?php echo esc_attr( (string) max( $kivun_hl_pct, 2 ) ); ?>%"></span></span>
				<span class="kivun-hl-chart__letter"><?php echo esc_html( $kivun_hl_t ); ?></span>
				<span class="kivun-hl-chart__name"><?php echo esc_html( $info[ $kivun_hl_t ]['name'] ); ?></span>
				<span class="kivun-hl-chart__num">
				<?php
				/* translators: 1: score, 2: highest possible score. */
				echo esc_html( sprintf( __( '%1$d מתוך %2$d', 'kivun' ), $kivun_hl_n, $kivun_hl_max ) );
				?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $top ) : ?>
		<h3 class="kivun-hl-subtitle"><?php esc_html_e( 'התחומים הבולטים אצלך', 'kivun' ); ?></h3>
		<?php foreach ( $top as $kivun_hl_i => $kivun_hl_t ) : ?>
			<?php
			$kivun_hl_jobs = $jobs[ $kivun_hl_t ] ?? array();
			$kivun_hl_yes  = count( array_filter( array_column( $kivun_hl_jobs, 'yes' ) ) );
			?>
			<div class="kivun-hl-type kivun-hl-t--<?php echo esc_attr( $kivun_hl_t ); ?>">
				<h4 class="kivun-hl-type__title"><span class="kivun-hl-type__rank"><?php echo esc_html( $kivun_hl_t ); ?></span> <?php echo esc_html( $info[ $kivun_hl_t ]['name'] ); ?></h4>
				<p class="kivun-hl-type__text"><?php echo esc_html( $info[ $kivun_hl_t ]['about'] ); ?></p>
				<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'מאפיינים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['traits'] ); ?></p>
				<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'כיוונים מתאימים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['fields'] ); ?></p>
				<?php if ( $kivun_hl_jobs ) : ?>
					<details class="kivun-hl-jobs"<?php echo 0 === $kivun_hl_i ? ' open' : ''; ?>>
						<summary class="kivun-hl-jobs__summary">
						<?php
						if ( $kivun_hl_yes ) {
							/* translators: %d: how many of the professions were marked "yes". */
							echo esc_html( sprintf( _n( 'מקצועות בתחום הזה · סימנת %d', 'מקצועות בתחום הזה · סימנת %d', $kivun_hl_yes, 'kivun' ), $kivun_hl_yes ) );
						} else {
							esc_html_e( 'מקצועות בתחום הזה', 'kivun' );
						}
						?>
						</summary>
						<ul class="kivun-hl-jobs__list">
							<?php foreach ( $kivun_hl_jobs as $kivun_hl_job ) : ?>
								<li class="kivun-hl-jobs__item<?php echo $kivun_hl_job['yes'] ? ' is-yes' : ''; ?>"><?php echo esc_html( $kivun_hl_job['text'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php $kivun_hl_rest = array_values( array_diff( $ranked, $top ) ); ?>
	<?php if ( $kivun_hl_rest ) : ?>
		<details class="kivun-hl-more">
			<summary class="kivun-hl-more__summary"><?php esc_html_e( 'שאר התחומים', 'kivun' ); ?></summary>
			<?php foreach ( $kivun_hl_rest as $kivun_hl_t ) : ?>
				<div class="kivun-hl-type kivun-hl-type--muted">
					<h4 class="kivun-hl-type__title"><?php echo esc_html( $info[ $kivun_hl_t ]['name'] . ' (' . $kivun_hl_t . ')' ); ?></h4>
					<p class="kivun-hl-type__text"><?php echo esc_html( $info[ $kivun_hl_t ]['about'] ); ?></p>
					<p class="kivun-hl-type__text"><strong><?php esc_html_e( 'כיוונים מתאימים:', 'kivun' ); ?></strong> <?php echo esc_html( $info[ $kivun_hl_t ]['fields'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>

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
