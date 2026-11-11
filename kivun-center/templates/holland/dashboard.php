<?php
/**
 * Template: the Holland questionnaire's dashboard ([kivun_holland_dashboard]).
 *
 * The questionnaire's own card and colours, wider, with the participants in
 * a table: who, when, how far, their code, and the report to print.
 *
 * Expects $view ('gate' or 'list'). The gate: $message and $login. The
 * list: $rows, $req, $page, $pages, $found, $stats, $base, $export, $total,
 * $colors and $logo.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_logo = Kivun_Holland::logo_url();
?>
<div class="kivun-holland kivun-hl-dash" dir="rtl">
	<div class="kivun-hl-card">
		<div class="kivun-hl-dash__head">
			<div class="kivun-hl-dash__titles">
				<h1 class="kivun-hl-dash__title"><?php esc_html_e( 'שאלוני הולנד', 'kivun' ); ?></h1>
				<p class="kivun-hl-dash__sub"><?php esc_html_e( 'תוצאות המשתתפים והדוחות להדפסה', 'kivun' ); ?></p>
			</div>
			<img class="kivun-hl-logo__img" src="<?php echo esc_url( $kivun_hl_logo ); ?>" alt="<?php esc_attr_e( 'מרכזי הכוון לתעסוקה ולימודים – כיוון ירושלים', 'kivun' ); ?>" width="500" height="200">
		</div>

		<?php if ( 'gate' === $view ) : ?>
			<div class="kivun-hl-dash__gate">
				<p class="kivun-hl-dash__msg"><?php echo esc_html( $message ); ?></p>
				<?php if ( '' !== $login ) : ?>
					<a class="kivun-hl-btn kivun-hl-btn--primary" href="<?php echo esc_url( $login ); ?>"><span class="kivun-hl-btn__in"><?php esc_html_e( 'התחברות', 'kivun' ); ?> <span class="kivun-hl-arrow" aria-hidden="true">←</span></span></a>
				<?php endif; ?>
			</div>
		<?php else : ?>

			<div class="kivun-hl-band kivun-hl-dash__stats">
				<?php
				foreach ( array(
					array( $stats['all'], __( 'משתתפים', 'kivun' ), '' ),
					array( $stats['done'], __( 'השלימו את השאלון', 'kivun' ), 'done' ),
					array( $stats['open'], __( 'עדיין בתהליך', 'kivun' ), 'open' ),
				) as $kivun_hl_stat ) :
					?>
					<a class="kivun-hl-dash__stat<?php echo $req['state'] === $kivun_hl_stat[2] ? ' is-current' : ''; ?>" href="<?php echo esc_url( '' === $kivun_hl_stat[2] ? $base : add_query_arg( 'hl_state', $kivun_hl_stat[2], $base ) ); ?>">
						<span class="kivun-hl-dash__num"><?php echo esc_html( number_format_i18n( (int) $kivun_hl_stat[0] ) ); ?></span>
						<span class="kivun-hl-dash__label"><?php echo esc_html( $kivun_hl_stat[1] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="kivun-hl-dash__body">
				<form class="kivun-hl-dash__filters" method="get" action="<?php echo esc_url( $base ); ?>">
					<?php
					// A page addressed by query string (?page_id=12) keeps it.
					wp_parse_str( (string) wp_parse_url( $base, PHP_URL_QUERY ), $kivun_hl_keep );
					foreach ( $kivun_hl_keep as $kivun_hl_k => $kivun_hl_v ) :
						if ( is_string( $kivun_hl_v ) ) :
							?>
							<input type="hidden" name="<?php echo esc_attr( $kivun_hl_k ); ?>" value="<?php echo esc_attr( $kivun_hl_v ); ?>">
							<?php
						endif;
					endforeach;
					?>
					<input class="kivun-hl-dash__search" type="search" name="hl_q" value="<?php echo esc_attr( $req['q'] ); ?>" placeholder="<?php esc_attr_e( 'חיפוש לפי שם, טלפון או תעודת זהות', 'kivun' ); ?>" aria-label="<?php esc_attr_e( 'חיפוש', 'kivun' ); ?>">
					<select class="kivun-hl-dash__select" name="hl_state" aria-label="<?php esc_attr_e( 'מצב השאלון', 'kivun' ); ?>">
						<option value=""><?php esc_html_e( 'כל השאלונים', 'kivun' ); ?></option>
						<option value="done"<?php selected( $req['state'], 'done' ); ?>><?php esc_html_e( 'הושלמו', 'kivun' ); ?></option>
						<option value="open"<?php selected( $req['state'], 'open' ); ?>><?php esc_html_e( 'בתהליך', 'kivun' ); ?></option>
					</select>
					<button type="submit" class="kivun-hl-btn kivun-hl-btn--primary kivun-hl-dash__go"><span class="kivun-hl-btn__in"><?php esc_html_e( 'חיפוש', 'kivun' ); ?></span></button>
					<a class="kivun-hl-dash__export" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'ייצוא לאקסל', 'kivun' ); ?></a>
				</form>

				<?php if ( $rows ) : ?>
					<div class="kivun-hl-dash__wrap">
						<table class="kivun-hl-dash__table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'שם', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'טלפון', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'תעודת זהות', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'נכנס/ה', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'מצב', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'קוד הולנד', 'kivun' ); ?></th>
									<th scope="col"><?php esc_html_e( 'דוח', 'kivun' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $kivun_hl_row ) : ?>
									<tr>
										<td class="kivun-hl-dash__name"><?php echo esc_html( $kivun_hl_row['name'] ); ?></td>
										<td><a class="kivun-hl-dash__tel" href="tel:<?php echo esc_attr( $kivun_hl_row['phone'] ); ?>" dir="ltr"><?php echo esc_html( $kivun_hl_row['phone'] ); ?></a></td>
										<td dir="ltr" class="kivun-hl-dash__idno"><?php echo esc_html( $kivun_hl_row['id_no'] ); ?></td>
										<td><?php echo esc_html( $kivun_hl_row['started'] ); ?></td>
										<td>
											<?php if ( $kivun_hl_row['done'] ) : ?>
												<span class="kivun-hl-dash__state is-done">
												<?php
												/* translators: %s: the day it was finished. */
												echo esc_html( '' !== $kivun_hl_row['finished'] ? sprintf( __( 'הושלם %s', 'kivun' ), $kivun_hl_row['finished'] ) : __( 'הושלם', 'kivun' ) );
												?>
												</span>
											<?php else : ?>
												<span class="kivun-hl-dash__state">
												<?php
												/* translators: 1: answers so far, 2: questions in all. */
												echo esc_html( sprintf( __( 'בתהליך · %1$d/%2$d', 'kivun' ), (int) $kivun_hl_row['answered'], (int) $total ) );
												?>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( '' !== $kivun_hl_row['code'] ) : ?>
												<span class="kivun-hl-dash__code" dir="ltr">
													<?php foreach ( str_split( $kivun_hl_row['code'] ) as $kivun_hl_t ) : ?>
														<span class="kivun-hl-dash__l" style="<?php echo esc_attr( sprintf( '--c:%s;--fg:%s', $colors[ $kivun_hl_t ]['c'], $colors[ $kivun_hl_t ]['fg'] ) ); ?>"><?php echo esc_html( $kivun_hl_t ); ?></span>
													<?php endforeach; ?>
												</span>
											<?php else : ?>
												<span class="kivun-hl-dash__none" aria-hidden="true">—</span>
											<?php endif; ?>
										</td>
										<td class="kivun-hl-dash__act">
											<?php if ( '' !== $kivun_hl_row['report'] ) : ?>
												<a class="kivun-hl-dash__report" href="<?php echo esc_url( $kivun_hl_row['report'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'דוח להדפסה', 'kivun' ); ?></a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="kivun-hl-dash__empty"><?php echo esc_html( '' !== $req['q'] || '' !== $req['state'] ? __( 'לא נמצאו שאלונים שמתאימים לחיפוש.', 'kivun' ) : __( 'עדיין אין משתתפים בשאלון.', 'kivun' ) ); ?></p>
				<?php endif; ?>

				<?php if ( $pages > 1 ) : ?>
					<nav class="kivun-hl-dash__pages" aria-label="<?php esc_attr_e( 'עמודים', 'kivun' ); ?>">
						<?php
						$kivun_hl_args = array_filter(
							array(
								'hl_q'     => $req['q'],
								'hl_state' => $req['state'],
							)
						);
						for ( $kivun_hl_p = 1; $kivun_hl_p <= $pages; $kivun_hl_p++ ) :
							$kivun_hl_url = add_query_arg( array_merge( $kivun_hl_args, array( 'hl_page' => $kivun_hl_p ) ), $base );
							?>
							<a class="kivun-hl-dash__page<?php echo $kivun_hl_p === $page ? ' is-current' : ''; ?>" href="<?php echo esc_url( $kivun_hl_url ); ?>"<?php echo $kivun_hl_p === $page ? ' aria-current="page"' : ''; ?>><?php echo esc_html( (string) $kivun_hl_p ); ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
