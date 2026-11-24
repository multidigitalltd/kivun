<?php
/**
 * The city a visitor lives in, chosen from a list.
 *
 * Every public form that asks for a city offers the same list, with "אחר"
 * last. A list rather than a text box, because the same town typed five ways
 * is five towns in the leads table's city filter and in every report drawn
 * from it.
 *
 * Choosing "אחר" opens a free text box, and what is typed there is what the
 * lead is stored with. A column full of the word "אחר" would be a filter that
 * filters nothing; the word is kept only when the box was left empty, so the
 * row still says the visitor answered.
 *
 * Not used for the "יישוב" of a course, event or job in the console: that is
 * where the thing takes place, it is matched against Mercaz Kivun's own list
 * of places for the distance search, and a job in a town that is not on this
 * list is still a job.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * The list, the field that offers it, and the value a submission resolves to.
 */
final class Kivun_Cities {

	/**
	 * The last choice, which opens the free text box.
	 */
	const OTHER = 'אחר';

	/**
	 * The cities offered, in the order they are shown. "אחר" is not one of
	 * them: the field adds it after the last.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		$cities = array(
			'ירושלים',
			'קרית יערים',
			'גבעת זאב',
			'תל ציון',
			'כוכב יעקב',
			'מעלה אדומים',
			'מבשרת ציון',
			'אדם',
			'מעלה עמוס',
			'בני ברק',
			'אלעד',
			'מודיעין עילית',
			'חיפה',
			'עפולה',
			'פתח תקווה',
			'ביתר עילית',
			'אשדוד',
			'טבריה',
		);

		/**
		 * The cities the public forms offer, in the order they are shown.
		 *
		 * "אחר" is added after them by the field itself; a filter that lists
		 * it too would show it twice, so it is taken out here.
		 *
		 * @param array<int,string> $cities The cities.
		 */
		$cities = (array) apply_filters( 'kivun_cities', $cities );

		$out = array();
		foreach ( $cities as $city ) {
			$city = trim( (string) $city );
			if ( '' !== $city && self::OTHER !== $city && ! in_array( $city, $out, true ) ) {
				$out[] = $city;
			}
		}
		return $out;
	}

	/**
	 * The city a submission is stored with.
	 *
	 * @param string $chosen What the list was set to.
	 * @param string $other  What was typed in the box "אחר" opens.
	 * @return string The chosen city, the typed one when "אחר" was chosen, or
	 *                "אחר" itself when the box was left empty.
	 */
	public static function resolve( string $chosen, string $other ): string {
		$chosen = trim( $chosen );
		if ( self::OTHER !== $chosen ) {
			return $chosen;
		}

		$other = trim( $other );
		return '' !== $other ? $other : self::OTHER;
	}

	/**
	 * The list and the free text box beside it, for a form to place under its
	 * own label.
	 *
	 * Written on one line: a page builder that runs wpautop() turns a newline
	 * between two tags into a `<br />`. The box is posted as `{$name}_other`,
	 * and is disabled while hidden so a city typed and then abandoned for one
	 * on the list is not sent along with it.
	 *
	 * @param string $name    The name the list is posted under.
	 * @param string $id      The list's id, for a label's `for`; '' for none.
	 * @param string $classes Extra classes for the list.
	 * @return string The markup.
	 */
	public static function field( string $name = 'city', string $id = '', string $classes = '' ): string {
		$options = '<option value="">' . esc_html__( 'בחירת עיר', 'kivun' ) . '</option>';
		foreach ( self::all() as $city ) {
			$options .= '<option value="' . esc_attr( $city ) . '">' . esc_html( $city ) . '</option>';
		}
		$options .= '<option value="' . esc_attr( self::OTHER ) . '">' . esc_html( self::OTHER ) . '</option>';

		$select = sprintf(
			'<select name="%1$s"%2$s class="%3$s" autocomplete="address-level2" data-kivun-city="%4$s">%5$s</select>',
			esc_attr( $name ),
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : '',
			esc_attr( trim( 'kivun-city-select ' . $classes ) ),
			esc_attr( self::OTHER ),
			$options
		);

		$other = sprintf(
			'<input type="text" name="%1$s" class="kivun-city-other" maxlength="100" aria-label="%2$s" placeholder="%3$s" hidden disabled>',
			esc_attr( $name . '_other' ),
			esc_attr__( 'שם העיר', 'kivun' ),
			esc_attr__( 'כתבו את שם העיר', 'kivun' )
		);

		return $select . $other;
	}
}
