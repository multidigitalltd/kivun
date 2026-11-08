<?php
/**
 * Template: the Holland questionnaire — login, the three parts, and a place
 * for the result.
 *
 * Every screen is in the markup from the start and the script shows one at a
 * time, so the page still reads top to bottom without it and nothing has to
 * be fetched between questions.
 *
 * Expects $page_id, $sections, $order, $items, $total and $per_page.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_hl_uid     = 'kivun-hl-' . (int) $page_id;
$kivun_hl_sec_num = 0;
$kivun_hl_running = 0;
$kivun_hl_parts   = count( $sections );
?>
<div class="kivun-holland" dir="rtl" data-total="<?php echo esc_attr( (string) $total ); ?>" data-page="<?php echo esc_attr( (string) $page_id ); ?>">

	<div class="kivun-hl-card kivun-hl-login" data-hl-login>
		<h2 class="kivun-hl-title"><?php esc_html_e( 'שאלון נטיות מקצועיות', 'kivun' ); ?></h2>
		<p class="kivun-hl-lead"><?php echo esc_html( Kivun_Holland::intro() ); ?></p>

		<ul class="kivun-hl-facts">
			<li class="kivun-hl-facts__item">
			<?php
			/* translators: %d: number of questions. */
			echo esc_html( sprintf( __( '%d שאלות של כן / לא', 'kivun' ), (int) $total ) );
			?>
			</li>
			<li class="kivun-hl-facts__item">
			<?php
			/* translators: %d: number of parts. */
			echo esc_html( sprintf( __( '%d חלקים: פעילויות, כשרים ומקצועות', 'kivun' ), (int) $kivun_hl_parts ) );
			?>
			</li>
			<li class="kivun-hl-facts__item"><?php esc_html_e( 'התוצאות מוצגות מיד בסיום', 'kivun' ); ?></li>
		</ul>

		<form class="kivun-hl-form" data-hl-form novalidate>
			<p class="kivun-hl-form__title"><?php esc_html_e( 'כניסה לשאלון', 'kivun' ); ?></p>

			<div class="kivun-hl-field">
				<label class="kivun-hl-field__label" for="<?php echo esc_attr( $kivun_hl_uid ); ?>-name"><?php esc_html_e( 'שם מלא', 'kivun' ); ?> <span class="kivun-hl-req" aria-hidden="true">*</span></label>
				<input class="kivun-hl-field__input" type="text" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-name" name="name" required autocomplete="name" maxlength="100" aria-describedby="<?php echo esc_attr( $kivun_hl_uid ); ?>-name-err">
				<span class="kivun-hl-field__error" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-name-err" data-hl-error="name"></span>
			</div>

			<div class="kivun-hl-field">
				<label class="kivun-hl-field__label" for="<?php echo esc_attr( $kivun_hl_uid ); ?>-phone"><?php esc_html_e( 'טלפון', 'kivun' ); ?> <span class="kivun-hl-req" aria-hidden="true">*</span></label>
				<input class="kivun-hl-field__input" type="tel" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-phone" name="phone" required autocomplete="tel" inputmode="tel" dir="ltr" maxlength="20" aria-describedby="<?php echo esc_attr( $kivun_hl_uid ); ?>-phone-err">
				<span class="kivun-hl-field__error" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-phone-err" data-hl-error="phone"></span>
			</div>

			<div class="kivun-hl-field">
				<label class="kivun-hl-field__label" for="<?php echo esc_attr( $kivun_hl_uid ); ?>-id"><?php esc_html_e( 'תעודת זהות', 'kivun' ); ?> <span class="kivun-hl-req" aria-hidden="true">*</span></label>
				<input class="kivun-hl-field__input" type="text" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-id" name="id_number" required inputmode="numeric" autocomplete="off" dir="ltr" maxlength="9" pattern="[0-9]*" aria-describedby="<?php echo esc_attr( $kivun_hl_uid ); ?>-id-err">
				<span class="kivun-hl-field__error" id="<?php echo esc_attr( $kivun_hl_uid ); ?>-id-err" data-hl-error="id_number"></span>
			</div>

			<?php if ( Kivun_Privacy_Consent::asked() ) : ?>
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
				<span class="kivun-hl-field__error" data-hl-error="privacy_consent"></span>
			<?php endif; ?>

			<p class="kivun-hl-hint"><?php esc_html_e( 'הפרטים נדרשים כדי לשמור את התשובות. אם תצא באמצע, היכנס שוב עם אותו טלפון ותעודת זהות ותמשיך מאותה נקודה.', 'kivun' ); ?></p>

			<button type="submit" class="kivun-hl-btn kivun-hl-btn--primary" data-hl-login-btn><?php esc_html_e( 'כניסה לשאלון', 'kivun' ); ?></button>
			<p class="kivun-hl-alert" role="alert" data-hl-alert hidden></p>
		</form>
	</div>

	<div class="kivun-hl-card kivun-hl-quiz" data-hl-quiz hidden>
		<div class="kivun-hl-progress">
			<div class="kivun-hl-progress__row">
				<span class="kivun-hl-progress__part" data-hl-part></span>
				<span class="kivun-hl-progress__count">
				<?php esc_html_e( 'ענית על', 'kivun' ); ?>
				<span class="kivun-hl-progress__num" data-hl-answered>0</span>
				<?php
				/* translators: %d: total questions. */
				echo esc_html( sprintf( __( 'מתוך %d', 'kivun' ), (int) $total ) );
				?>
				</span>
			</div>
			<div class="kivun-hl-progress__track" aria-hidden="true"><span class="kivun-hl-progress__fill" data-hl-fill></span></div>
			<p class="kivun-hl-swipe-hint" aria-hidden="true"><?php esc_html_e( 'אפשר גם להחליק את השאלה: ימינה = כן, שמאלה = לא', 'kivun' ); ?></p>
		</div>

		<?php foreach ( $sections as $kivun_hl_key => $kivun_hl_section ) : ?>
			<?php
			++$kivun_hl_sec_num;
			$kivun_hl_pages = array_chunk( $order[ $kivun_hl_key ], (int) $per_page );
			/* translators: 1: part number, 2: number of parts, 3: part title. */
			$kivun_hl_label = sprintf( __( 'חלק %1$d מתוך %2$d: %3$s', 'kivun' ), $kivun_hl_sec_num, $kivun_hl_parts, $kivun_hl_section['title'] );
			?>
			<div class="kivun-hl-screen kivun-hl-intro" data-hl-screen data-hl-label="<?php echo esc_attr( $kivun_hl_label ); ?>" hidden>
				<p class="kivun-hl-intro__step"><?php echo esc_html( $kivun_hl_label ); ?></p>
				<h3 class="kivun-hl-intro__title" tabindex="-1" data-hl-focus><?php echo esc_html( $kivun_hl_section['title'] ); ?></h3>
				<p class="kivun-hl-intro__text"><?php echo esc_html( $kivun_hl_section['instructions'] ); ?></p>
				<div class="kivun-hl-nav">
					<?php if ( $kivun_hl_sec_num > 1 ) : ?>
						<button type="button" class="kivun-hl-btn kivun-hl-btn--ghost" data-hl-prev><?php esc_html_e( 'הקודם', 'kivun' ); ?></button>
					<?php endif; ?>
					<button type="button" class="kivun-hl-btn kivun-hl-btn--primary" data-hl-next><?php esc_html_e( 'התחלה', 'kivun' ); ?></button>
				</div>
			</div>

			<?php foreach ( $kivun_hl_pages as $kivun_hl_page_i => $kivun_hl_nums ) : ?>
				<?php
				$kivun_hl_last = ( $kivun_hl_sec_num === $kivun_hl_parts && count( $kivun_hl_pages ) - 1 === $kivun_hl_page_i );
				$kivun_hl_one  = ( 1 === count( $kivun_hl_nums ) );
				?>
				<div class="kivun-hl-screen kivun-hl-page<?php echo $kivun_hl_one ? ' kivun-hl-page--single' : ''; ?>" data-hl-screen data-hl-label="<?php echo esc_attr( $kivun_hl_label ); ?>" hidden>
					<div class="kivun-hl-page__head">
						<h3 class="kivun-hl-page__title" tabindex="-1" data-hl-focus>
						<?php
						if ( $kivun_hl_one ) {
							/* translators: 1: part title, 2: question number within the part, 3: questions in the part. */
							echo esc_html( sprintf( __( '%1$s · שאלה %2$d מתוך %3$d', 'kivun' ), $kivun_hl_section['title'], $kivun_hl_page_i + 1, count( $kivun_hl_pages ) ) );
						} else {
							/* translators: 1: part title, 2: page number, 3: pages in the part. */
							echo esc_html( sprintf( __( '%1$s · עמוד %2$d מתוך %3$d', 'kivun' ), $kivun_hl_section['title'], $kivun_hl_page_i + 1, count( $kivun_hl_pages ) ) );
						}
						?>
						</h3>
						<p class="kivun-hl-page__hint"><?php echo esc_html( $kivun_hl_section['instructions'] ); ?></p>
					</div>

					<div class="kivun-hl-items">
						<?php foreach ( $kivun_hl_nums as $kivun_hl_num ) : ?>
							<?php ++$kivun_hl_running; ?>
							<fieldset class="kivun-hl-item" data-hl-item="<?php echo esc_attr( (string) $kivun_hl_num ); ?>">
								<legend class="kivun-hl-item__text">
									<?php if ( ! $kivun_hl_one ) : ?>
										<span class="kivun-hl-item__num"><?php echo esc_html( (string) $kivun_hl_running ); ?>.</span>
									<?php endif; ?>
									<?php echo esc_html( $items[ $kivun_hl_num ]['text'] ); ?>
								</legend>
								<div class="kivun-hl-item__opts">
									<label class="kivun-hl-opt kivun-hl-opt--yes"><input type="radio" name="kivun_hl_<?php echo esc_attr( (string) $kivun_hl_num ); ?>" value="y"><span class="kivun-hl-opt__text"><?php esc_html_e( 'כן', 'kivun' ); ?></span></label>
									<label class="kivun-hl-opt kivun-hl-opt--no"><input type="radio" name="kivun_hl_<?php echo esc_attr( (string) $kivun_hl_num ); ?>" value="n"><span class="kivun-hl-opt__text"><?php esc_html_e( 'לא', 'kivun' ); ?></span></label>
								</div>
							</fieldset>
						<?php endforeach; ?>
					</div>

					<p class="kivun-hl-alert" role="alert" data-hl-page-alert hidden></p>
					<div class="kivun-hl-nav">
						<button type="button" class="kivun-hl-btn kivun-hl-btn--ghost" data-hl-prev><?php esc_html_e( 'הקודם', 'kivun' ); ?></button>
						<?php if ( $kivun_hl_last ) : ?>
							<button type="button" class="kivun-hl-btn kivun-hl-btn--primary" data-hl-next data-hl-finish><?php esc_html_e( 'סיום וקבלת התוצאות', 'kivun' ); ?></button>
						<?php else : ?>
							<button type="button" class="kivun-hl-btn kivun-hl-btn--primary" data-hl-next><?php esc_html_e( 'הבא', 'kivun' ); ?></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>

	<div class="kivun-hl-results" data-hl-results aria-live="polite" hidden></div>
</div>
