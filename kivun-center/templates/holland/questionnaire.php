<?php
/**
 * Template: the Holland questionnaire — login, the three parts, and the
 * thank-you at the end.
 *
 * Built to the design handoff "design_handoff_holland_v2" (Holland v2.dc.html).
 * Every screen is in the markup from the start and the script shows one at a
 * time. One card holds them all: the logo strip, then whichever screen is
 * current. The question screens share one progress band; a part's opening
 * screen carries its own band with the three-part journey.
 *
 * The participant is not shown a result: the last screen thanks them, and the
 * representative prints the report from the leads table.
 *
 * Expects $page_id, $sections, $order, $items, $total and $per_page.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_uid     = 'kivun-hl-' . (int) $page_id;
$kivun_hl_parts   = count( $sections );
$kivun_hl_keys    = array_keys( $sections );
$kivun_hl_running = 0;

/*
 * The three parts as steps: done, current or still to come. Drawn on the
 * server for a part's opening screen; on the question screens the script
 * sets the same classes as the person moves on.
 */
$kivun_hl_steps = static function ( int $current, string $size ) use ( $sections, $kivun_hl_keys ): void {
	?>
	<ol class="kivun-hl-steps kivun-hl-steps--<?php echo esc_attr( $size ); ?>" data-hl-steps>
		<?php foreach ( $kivun_hl_keys as $kivun_hl_i => $kivun_hl_key ) : ?>
			<?php
			if ( $kivun_hl_i < $current ) {
				$kivun_hl_state = 'is-done';
			} elseif ( $kivun_hl_i === $current ) {
				$kivun_hl_state = 'is-active';
			} else {
				$kivun_hl_state = 'is-future';
			}
			?>
			<li class="kivun-hl-steps__step <?php echo esc_attr( $kivun_hl_state ); ?>"<?php echo $kivun_hl_i === $current ? ' aria-current="step"' : ''; ?>>
				<span class="kivun-hl-steps__col">
					<span class="kivun-hl-steps__dot" aria-hidden="true"><span class="kivun-hl-steps__num"><?php echo esc_html( (string) ( $kivun_hl_i + 1 ) ); ?></span><span class="kivun-hl-steps__tick">✓</span></span>
					<span class="kivun-hl-steps__label"><?php echo esc_html( $sections[ $kivun_hl_key ]['title'] ); ?></span>
				</span>
				<?php if ( $kivun_hl_i < count( $kivun_hl_keys ) - 1 ) : ?>
					<span class="kivun-hl-steps__line" aria-hidden="true"></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
};
?>
<div class="kivun-holland" dir="rtl" data-total="<?php echo esc_attr( (string) $total ); ?>" data-page="<?php echo esc_attr( (string) $page_id ); ?>">
	<div class="kivun-hl-card">
		<div class="kivun-hl-logo"><img class="kivun-hl-logo__img" src="<?php echo esc_url( Kivun_Holland::logo_url() ); ?>" alt="<?php esc_attr_e( 'מרכזי הכוון לתעסוקה ולימודים – כיוון ירושלים', 'kivun' ); ?>" width="500" height="200"></div>

		<?php // ── Login ─────────────────────────────────────────────────── ?>
		<div class="kivun-hl-login" data-hl-login>
			<div class="kivun-hl-band kivun-hl-band--login">
				<h2 class="kivun-hl-title"><?php echo esc_html( Kivun_Holland::title() ); ?></h2>
				<p class="kivun-hl-lead"><?php echo esc_html( Kivun_Holland::intro() ); ?></p>
			</div>

			<form class="kivun-hl-body kivun-hl-form" data-hl-form novalidate>
				<div class="kivun-hl-form__head">
					<span class="kivun-hl-form__title"><?php esc_html_e( 'נתחיל בכמה פרטים', 'kivun' ); ?></span>
					<span class="kivun-hl-form__sub"><?php esc_html_e( 'מלא את הפרטים שלך כדי להתחיל בשאלון.', 'kivun' ); ?></span>
				</div>

				<div class="kivun-hl-fields">
					<?php
					$kivun_hl_fields = array(
						'name'      => array( __( 'שם מלא', 'kivun' ), 'text', 'name', 'kivun-hl-field--wide', '', 100, '' ),
						'phone'     => array( __( 'טלפון', 'kivun' ), 'tel', 'tel', '', '050-0000000', 20, 'tel' ),
						'id_number' => array( __( 'תעודת זהות', 'kivun' ), 'text', 'off', '', __( '9 ספרות', 'kivun' ), 9, 'numeric' ),
					);
					foreach ( $kivun_hl_fields as $kivun_hl_name => $kivun_hl_f ) :
						$kivun_hl_id = $kivun_hl_uid . '-' . $kivun_hl_name;
						?>
						<div class="kivun-hl-field <?php echo esc_attr( $kivun_hl_f[3] ); ?>">
							<label class="kivun-hl-field__label" for="<?php echo esc_attr( $kivun_hl_id ); ?>"><?php echo esc_html( $kivun_hl_f[0] ); ?></label>
							<input class="kivun-hl-field__input" type="<?php echo esc_attr( $kivun_hl_f[1] ); ?>" id="<?php echo esc_attr( $kivun_hl_id ); ?>" name="<?php echo esc_attr( $kivun_hl_name ); ?>" required autocomplete="<?php echo esc_attr( $kivun_hl_f[2] ); ?>" maxlength="<?php echo esc_attr( (string) $kivun_hl_f[5] ); ?>"<?php echo '' !== $kivun_hl_f[4] ? ' placeholder="' . esc_attr( $kivun_hl_f[4] ) . '"' : ''; ?><?php echo '' !== $kivun_hl_f[6] ? ' inputmode="' . esc_attr( $kivun_hl_f[6] ) . '"' : ''; ?> aria-describedby="<?php echo esc_attr( $kivun_hl_id ); ?>-err">
							<span class="kivun-hl-field__error" id="<?php echo esc_attr( $kivun_hl_id ); ?>-err" data-hl-error="<?php echo esc_attr( $kivun_hl_name ); ?>" hidden><span class="kivun-hl-bang" aria-hidden="true">!</span><span class="kivun-hl-field__msg" data-hl-msg></span></span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="kivun-hl-info">
					<span class="kivun-hl-info__icon" aria-hidden="true">i</span>
					<p class="kivun-hl-info__text"><?php esc_html_e( 'הפרטים נדרשים כדי לשמור את התשובות. אם תצא באמצע, היכנס שוב עם אותו טלפון ותעודת זהות ותמשיך מאותה נקודה.', 'kivun' ); ?></p>
				</div>

				<?php if ( Kivun_Privacy_Consent::asked() ) : ?>
					<div class="kivun-hl-field">
						<label class="kivun-hl-consent">
							<input type="checkbox" name="privacy_consent" value="1" required>
							<span class="kivun-hl-consent__text">
							<?php
							echo wp_kses(
								Kivun_Privacy_Consent::label(),
								array(
									'a' => array(
										'href'   => true,
										'target' => true,
										'rel'    => true,
									),
								)
							);
							?>
							</span>
						</label>
						<span class="kivun-hl-field__error" data-hl-error="privacy_consent" hidden><span class="kivun-hl-bang" aria-hidden="true">!</span><span class="kivun-hl-field__msg" data-hl-msg></span></span>
					</div>
				<?php endif; ?>

				<button type="submit" class="kivun-hl-btn kivun-hl-btn--primary kivun-hl-btn--block" data-hl-login-btn><span class="kivun-hl-btn__in"><?php esc_html_e( 'כניסה לשאלון', 'kivun' ); ?> <span class="kivun-hl-arrow" aria-hidden="true">←</span></span></button>
				<p class="kivun-hl-alert kivun-hl-alert--center" role="alert" data-hl-alert hidden></p>
			</form>
		</div>

		<?php // ── The questionnaire ─────────────────────────────────────── ?>
		<div class="kivun-hl-quiz" data-hl-quiz hidden>

			<div class="kivun-hl-band kivun-hl-band--progress" data-hl-progress hidden>
				<div class="kivun-hl-progress__row">
					<?php $kivun_hl_steps( 0, 'sm' ); ?>
					<span class="kivun-hl-progress__part" data-hl-part></span>
					<span class="kivun-hl-progress__count">
						<?php esc_html_e( 'ענית על', 'kivun' ); ?>
						<b class="kivun-hl-progress__num" data-hl-answered>0</b>
						<?php
						/* translators: %d: total questions. */
						echo esc_html( sprintf( __( 'מתוך %d', 'kivun' ), (int) $total ) );
						?>
					</span>
				</div>
				<div class="kivun-hl-progress__track" aria-hidden="true"><span class="kivun-hl-progress__fill" data-hl-fill></span></div>
			</div>

			<?php foreach ( $kivun_hl_keys as $kivun_hl_sec_i => $kivun_hl_key ) : ?>
				<?php
				$kivun_hl_section = $sections[ $kivun_hl_key ];
				$kivun_hl_pages   = array_chunk( $order[ $kivun_hl_key ], (int) $per_page );
				/* translators: 1: part number, 2: number of parts. */
				$kivun_hl_of    = sprintf( __( 'חלק %1$d מתוך %2$d', 'kivun' ), $kivun_hl_sec_i + 1, $kivun_hl_parts );
				$kivun_hl_label = $kivun_hl_of . ' · ' . $kivun_hl_section['title'];
				?>

				<div class="kivun-hl-screen kivun-hl-intro" data-hl-screen data-hl-intro data-hl-part-index="<?php echo esc_attr( (string) $kivun_hl_sec_i ); ?>" data-hl-label="<?php echo esc_attr( $kivun_hl_label ); ?>" hidden>
					<div class="kivun-hl-band kivun-hl-band--journey">
						<?php $kivun_hl_steps( $kivun_hl_sec_i, 'lg' ); ?>
					</div>
					<div class="kivun-hl-body">
						<span class="kivun-hl-intro__of"><?php echo esc_html( $kivun_hl_of ); ?></span>
						<h3 class="kivun-hl-intro__title" tabindex="-1" data-hl-focus><?php echo esc_html( $kivun_hl_section['title'] ); ?></h3>
						<div class="kivun-hl-rules">
							<div class="kivun-hl-rule kivun-hl-rule--yes">
								<span class="kivun-hl-rule__icon" aria-hidden="true">✓</span>
								<p class="kivun-hl-rule__text"><?php echo esc_html( $kivun_hl_section['yes'] ); ?></p>
							</div>
							<div class="kivun-hl-rule kivun-hl-rule--no">
								<span class="kivun-hl-rule__icon" aria-hidden="true">✕</span>
								<p class="kivun-hl-rule__text"><?php echo esc_html( $kivun_hl_section['no'] ); ?></p>
							</div>
						</div>
						<div class="kivun-hl-nav kivun-hl-nav--intro">
							<?php if ( $kivun_hl_sec_i > 0 ) : ?>
								<button type="button" class="kivun-hl-btn kivun-hl-btn--text" data-hl-prev><span class="kivun-hl-btn__in"><span class="kivun-hl-arrow" aria-hidden="true">→</span> <?php esc_html_e( 'הקודם', 'kivun' ); ?></span></button>
							<?php endif; ?>
							<button type="button" class="kivun-hl-btn kivun-hl-btn--primary kivun-hl-btn--start" data-hl-next><span class="kivun-hl-btn__in"><?php esc_html_e( 'התחלה', 'kivun' ); ?> <span class="kivun-hl-arrow" aria-hidden="true">←</span></span></button>
						</div>
					</div>
				</div>

				<?php foreach ( $kivun_hl_pages as $kivun_hl_page_i => $kivun_hl_nums ) : ?>
					<?php
					$kivun_hl_last = ( $kivun_hl_sec_i === $kivun_hl_parts - 1 && count( $kivun_hl_pages ) - 1 === $kivun_hl_page_i );
					$kivun_hl_one  = ( 1 === count( $kivun_hl_nums ) );
					/* translators: 1: question number within the part, 2: questions in the part. */
					$kivun_hl_qof = sprintf( __( 'שאלה %1$d מתוך %2$d', 'kivun' ), $kivun_hl_page_i + 1, count( $kivun_hl_pages ) );
					?>
					<div class="kivun-hl-screen kivun-hl-page<?php echo $kivun_hl_one ? ' kivun-hl-page--single' : ''; ?>" data-hl-screen data-hl-part-index="<?php echo esc_attr( (string) $kivun_hl_sec_i ); ?>" data-hl-label="<?php echo esc_attr( $kivun_hl_label ); ?>" hidden>
						<div class="kivun-hl-body kivun-hl-body--question">
							<div class="kivun-hl-qhead">
								<span class="kivun-hl-pill"><span class="kivun-hl-pill__part"><?php echo esc_html( $kivun_hl_section['title'] ); ?> · </span><?php echo esc_html( $kivun_hl_qof ); ?></span>
								<span class="kivun-hl-qhead__rules"><span class="kivun-hl-qhead__rule"><?php echo esc_html( $kivun_hl_section['yes'] ); ?></span><span class="kivun-hl-qhead__rule"><?php echo esc_html( $kivun_hl_section['no'] ); ?></span></span>
							</div>

							<?php foreach ( $kivun_hl_nums as $kivun_hl_num ) : ?>
								<?php
								++$kivun_hl_running;
								$kivun_hl_qid = $kivun_hl_uid . '-q' . $kivun_hl_num;
								?>
								<div class="kivun-hl-item" role="radiogroup" aria-labelledby="<?php echo esc_attr( $kivun_hl_qid ); ?>" data-hl-item="<?php echo esc_attr( (string) $kivun_hl_num ); ?>">
									<div class="kivun-hl-stage" data-hl-stage>
										<span class="kivun-hl-stamp kivun-hl-stamp--yes" aria-hidden="true"><?php esc_html_e( 'כן', 'kivun' ); ?> ✓</span>
										<span class="kivun-hl-stamp kivun-hl-stamp--no" aria-hidden="true"><?php esc_html_e( 'לא', 'kivun' ); ?> ✕</span>
										<span class="kivun-hl-stage__text" id="<?php echo esc_attr( $kivun_hl_qid ); ?>" tabindex="-1" data-hl-focus><?php echo esc_html( $items[ $kivun_hl_num ]['text'] ); ?></span>
										<span class="kivun-hl-stage__hint" aria-hidden="true">→ <?php esc_html_e( 'החלק ימינה ל„כן" · שמאלה ל„לא"', 'kivun' ); ?> ←</span>
									</div>
									<div class="kivun-hl-opts">
										<label class="kivun-hl-opt kivun-hl-opt--yes"><input class="kivun-hl-opt__input" type="radio" name="kivun_hl_<?php echo esc_attr( (string) $kivun_hl_num ); ?>" value="y"><span class="kivun-hl-opt__box"><span class="kivun-hl-opt__icon" aria-hidden="true">✓</span><span class="kivun-hl-opt__text"><?php esc_html_e( 'כן', 'kivun' ); ?></span></span></label>
										<label class="kivun-hl-opt kivun-hl-opt--no"><input class="kivun-hl-opt__input" type="radio" name="kivun_hl_<?php echo esc_attr( (string) $kivun_hl_num ); ?>" value="n"><span class="kivun-hl-opt__box"><span class="kivun-hl-opt__icon" aria-hidden="true">✕</span><span class="kivun-hl-opt__text"><?php esc_html_e( 'לא', 'kivun' ); ?></span></span></label>
									</div>
								</div>
							<?php endforeach; ?>

							<p class="kivun-hl-alert" role="alert" data-hl-page-alert hidden></p>
							<div class="kivun-hl-nav">
								<button type="button" class="kivun-hl-btn kivun-hl-btn--text" data-hl-prev><span class="kivun-hl-btn__in"><span class="kivun-hl-arrow" aria-hidden="true">→</span> <?php esc_html_e( 'הקודם', 'kivun' ); ?></span></button>
								<?php if ( $kivun_hl_last ) : ?>
									<button type="button" class="kivun-hl-btn kivun-hl-btn--primary kivun-hl-btn--next" data-hl-next data-hl-finish><?php esc_html_e( 'סיום השאלון', 'kivun' ); ?> <span class="kivun-hl-arrow" aria-hidden="true">←</span></button>
								<?php else : ?>
									<button type="button" class="kivun-hl-btn kivun-hl-btn--primary kivun-hl-btn--next" data-hl-next><?php esc_html_e( 'הבא', 'kivun' ); ?> <span class="kivun-hl-arrow" aria-hidden="true">←</span></button>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>

		<?php // ── Thank you ─────────────────────────────────────────────── ?>
		<div class="kivun-hl-thanks" data-hl-thanks hidden>
			<div class="kivun-hl-band kivun-hl-band--thanks">
				<span class="kivun-hl-thanks__check" aria-hidden="true">✓</span>
				<h2 class="kivun-hl-thanks__title" tabindex="-1" data-hl-focus><?php esc_html_e( 'תודה רבה על ההשתתפות!', 'kivun' ); ?></h2>
				<p class="kivun-hl-thanks__text"><?php esc_html_e( 'סיימת לענות על כל השאלות בשאלון, והתשובות שלך נשמרו.', 'kivun' ); ?></p>
			</div>
			<div class="kivun-hl-body kivun-hl-body--thanks">
				<div class="kivun-hl-info kivun-hl-info--lg">
					<span class="kivun-hl-info__icon" aria-hidden="true">i</span>
					<p class="kivun-hl-info__text"><?php esc_html_e( 'התוצאות הועברו לנציג מרכז הכוון. הנציג יעבור עליהן וימסור לך אותן מודפסות, יחד עם הסבר.', 'kivun' ); ?></p>
				</div>
			</div>
		</div>
	</div>
</div>
