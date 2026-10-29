<?php
/**
 * Template: a landing page's own form.
 *
 * The built-in fields first, in the order the fixed form has always shown
 * them, then the questions this page asks. A visitor sees one form; the line
 * between them matters only to what the lead is stored as.
 *
 * Expects $post_id and $post_type.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$kivun_fields   = Kivun_Custom_Forms::fields( $post_id );
$kivun_builtins = Kivun_Custom_Forms::builtins( $post_id );
$kivun_asks     = static fn( string $key ): bool => in_array( $key, $kivun_builtins, true );
?>
<?php
// No heading of ours. This form is placed inside a design that already has
// one, and a second title under the client's own read as a form bolted on
// rather than part of the page. The chrome goes with it: `--plain` drops the
// panel and lets the page's own styling reach the fields.
?>
<div class="kivun-interest kivun-interest--plain" id="kivun-interest-<?php echo esc_attr( (string) $post_id ); ?>">
	<form class="kivun-lead-form" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( (string) $post_type ); ?>">

		<?php // Never switchable: a lead with neither is a row nobody can act on. ?>
		<div class="kivun-form-row">
			<label for="kivun-cf-name-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
			<input type="text" id="kivun-cf-name-<?php echo esc_attr( (string) $post_id ); ?>" name="name" required autocomplete="name">
		</div>

		<div class="kivun-form-row">
			<label for="kivun-cf-phone-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'טלפון *', 'kivun' ); ?></label>
			<input type="tel" id="kivun-cf-phone-<?php echo esc_attr( (string) $post_id ); ?>" name="phone" required autocomplete="tel">
		</div>

		<?php if ( $kivun_asks( 'email' ) ) : ?>
			<div class="kivun-form-row">
				<label for="kivun-cf-email-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'אימייל', 'kivun' ); ?></label>
				<input type="email" id="kivun-cf-email-<?php echo esc_attr( (string) $post_id ); ?>" name="email" autocomplete="email">
			</div>
		<?php endif; ?>

		<?php if ( $kivun_asks( 'city' ) ) : ?>
			<div class="kivun-form-row">
				<label for="kivun-cf-city-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'עיר', 'kivun' ); ?></label>
				<input type="text" id="kivun-cf-city-<?php echo esc_attr( (string) $post_id ); ?>" name="city" autocomplete="address-level2">
			</div>
		<?php endif; ?>

		<?php if ( $kivun_asks( 'gender' ) ) : ?>
			<div class="kivun-form-row">
				<label for="kivun-cf-gender-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'מגדר', 'kivun' ); ?></label>
				<select id="kivun-cf-gender-<?php echo esc_attr( (string) $post_id ); ?>" name="gender">
					<option value=""><?php esc_html_e( 'בחר/י', 'kivun' ); ?></option>
					<option value="זכר"><?php esc_html_e( 'זכר', 'kivun' ); ?></option>
					<option value="נקבה"><?php esc_html_e( 'נקבה', 'kivun' ); ?></option>
					<option value="אחר"><?php esc_html_e( 'אחר', 'kivun' ); ?></option>
				</select>
			</div>
		<?php endif; ?>

		<?php
		// This page's own questions. The name carries the kivun_q[] prefix so
		// the handler can tell an answer from a built-in field without
		// guessing, and so a question keyed "name" could never overwrite the
		// visitor's name.
		foreach ( $kivun_fields as $kivun_i => $kivun_field ) :
			$kivun_id   = 'kivun-cf-' . (int) $post_id . '-' . $kivun_i;
			$kivun_name = 'kivun_q[' . $kivun_field['key'] . ']';
			$kivun_star = $kivun_field['required'] ? ' *' : '';
			?>
			<?php if ( 'consent' === $kivun_field['type'] ) : ?>
				<label class="kivun-consent-row">
					<input type="checkbox" name="<?php echo esc_attr( $kivun_name ); ?>" value="1" <?php echo $kivun_field['required'] ? 'required' : ''; ?>>
					<span><?php echo esc_html( $kivun_field['label'] ); ?></span>
				</label>

			<?php elseif ( 'radio' === $kivun_field['type'] || 'checkbox' === $kivun_field['type'] ) : ?>
				<fieldset class="kivun-form-row kivun-cf-choices">
					<legend><?php echo esc_html( $kivun_field['label'] . $kivun_star ); ?></legend>
					<?php foreach ( $kivun_field['options'] as $kivun_o => $kivun_option ) : ?>
						<label class="kivun-cf-choice">
							<input
								type="<?php echo 'radio' === $kivun_field['type'] ? 'radio' : 'checkbox'; ?>"
								name="<?php echo esc_attr( 'radio' === $kivun_field['type'] ? $kivun_name : $kivun_name . '[]' ); ?>"
								value="<?php echo esc_attr( $kivun_option ); ?>"
								<?php echo ( $kivun_field['required'] && 'radio' === $kivun_field['type'] && 0 === $kivun_o ) ? 'required' : ''; ?>
							>
							<span><?php echo esc_html( $kivun_option ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>

			<?php else : ?>
				<div class="kivun-form-row">
					<label for="<?php echo esc_attr( $kivun_id ); ?>"><?php echo esc_html( $kivun_field['label'] . $kivun_star ); ?></label>
					<?php if ( 'textarea' === $kivun_field['type'] ) : ?>
						<textarea id="<?php echo esc_attr( $kivun_id ); ?>" name="<?php echo esc_attr( $kivun_name ); ?>" rows="3" <?php echo $kivun_field['required'] ? 'required' : ''; ?>></textarea>
					<?php elseif ( 'select' === $kivun_field['type'] ) : ?>
						<select id="<?php echo esc_attr( $kivun_id ); ?>" name="<?php echo esc_attr( $kivun_name ); ?>" <?php echo $kivun_field['required'] ? 'required' : ''; ?>>
							<option value=""><?php esc_html_e( 'בחר/י', 'kivun' ); ?></option>
							<?php foreach ( $kivun_field['options'] as $kivun_option ) : ?>
								<option value="<?php echo esc_attr( $kivun_option ); ?>"><?php echo esc_html( $kivun_option ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input
							type="<?php echo esc_attr( 'date' === $kivun_field['type'] ? 'date' : ( 'number' === $kivun_field['type'] ? 'number' : 'text' ) ); ?>"
							id="<?php echo esc_attr( $kivun_id ); ?>"
							name="<?php echo esc_attr( $kivun_name ); ?>"
							<?php echo 'date' === $kivun_field['type'] ? 'dir="ltr"' : ''; ?>
							<?php echo 'number' === $kivun_field['type'] ? 'inputmode="numeric"' : ''; ?>
							<?php echo $kivun_field['required'] ? 'required' : ''; ?>
						>
						<?php
						// A number box refuses a letter without saying so — the
						// key is pressed and nothing appears, which reads as a
						// broken field rather than a rejected character. The
						// script fills this in when that happens.
						?>
						<?php if ( 'number' === $kivun_field['type'] ) : ?>
							<span class="kivun-field-note" role="status" aria-live="polite" hidden></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $kivun_asks( 'message' ) ) : ?>
			<div class="kivun-form-row">
				<label for="kivun-cf-message-<?php echo esc_attr( (string) $post_id ); ?>"><?php esc_html_e( 'הערות / שאלות', 'kivun' ); ?></label>
				<textarea id="kivun-cf-message-<?php echo esc_attr( (string) $post_id ); ?>" name="message" rows="3"></textarea>
			</div>
		<?php endif; ?>

		<?php if ( $kivun_asks( 'marketing_consent' ) ) : ?>
			<label class="kivun-consent-row">
				<input type="checkbox" name="marketing_consent" value="1">
				<span><?php esc_html_e( 'אני מאשר/ת קבלת דיוור ותכנים שיווקיים.', 'kivun' ); ?></span>
			</label>
		<?php endif; ?>

		<?php
		// Last, under everything it is an agreement about. Required when the
		// site asks it at all — a privacy consent that can be left unticked
		// collects nothing but the appearance of one. The sentence and its
		// links come from one place, so the forms cannot word the same
		// agreement differently.
		?>
		<?php if ( Kivun_Privacy_Consent::asked() ) : ?>
			<label class="kivun-consent-row kivun-consent-row--required">
				<input type="checkbox" name="privacy_consent" value="1" required>
				<span>
				<?php
				echo wp_kses(
					Kivun_Privacy_Consent::label(),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
						</span>
			</label>
		<?php endif; ?>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>
		<button type="submit" class="kivun-btn kivun-btn--primary kivun-lead-submit"><?php esc_html_e( 'שליחה', 'kivun' ); ?></button>
	</form>
</div>
