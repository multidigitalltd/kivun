<?php
/**
 * Filtering the leads list by gender.
 *
 * The stored value is whatever the form offered — "גבר", "זכר", "אישה",
 * "Female" — so the filter matches words rather than an exact string, from
 * the same list the rota splits candidates by. If the two ever disagreed,
 * the screen would be reporting a split that did not happen.
 *
 * The order of that list is the whole difficulty: "male" is inside "female",
 * "man" is inside "woman", and "נער" is inside "נערה". A filter for men that
 * forgot this would list women, and it would look right until somebody read
 * the names.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the gender words, the grouping, and the SQL built from them.
 */
final class GenderFilterTest extends TestCase {

	/**
	 * Values a form on this site has offered or could offer.
	 *
	 * @var array<string,string>
	 */
	private const SAMPLES = array(
		'גבר'    => 'male',
		'זכר'    => 'male',
		'בחור'   => 'male',
		'נער'    => 'male',
		'בן'     => 'male',
		'Male'   => 'male',
		'man'    => 'male',
		'boy'    => 'male',
		'אישה'   => 'female',
		'אשה'    => 'female',
		'נקבה'   => 'female',
		'בחורה'  => 'female',
		'נערה'   => 'female',
		'בת'     => 'female',
		'Female' => 'female',
		'woman'  => 'female',
		'girl'   => 'female',
		'אחר'    => '',
		'x'      => '',
	);

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Decide, the way MySQL would, whether a value satisfies the fragment.
	 *
	 * LIKE '%word%' is a case-insensitive substring test, and NOT inverts a
	 * parenthesised group — that is all these fragments use.
	 *
	 * @param string $sql   The fragment from gender_where().
	 * @param string $value The stored gender.
	 * @return bool
	 */
	private function satisfies( string $sql, string $value ): bool {
		// "( a LIKE '%x%' OR … ) AND NOT ( … )" — split on the one NOT.
		$parts    = explode( ' AND NOT ', $sql, 2 );
		$positive = $this->any_like( $parts[0], $value );

		if ( isset( $parts[1] ) ) {
			return $positive && ! $this->any_like( $parts[1], $value );
		}

		return $positive;
	}

	/**
	 * Whether any LIKE in a group matches.
	 *
	 * @param string $group The parenthesised OR group.
	 * @param string $value The stored gender.
	 * @return bool
	 */
	private function any_like( string $group, string $value ): bool {
		preg_match_all( "/LIKE '%(.+?)%'/u", $group, $found );

		foreach ( $found[1] as $word ) {
			if ( false !== mb_stripos( $value, $word ) ) {
				return true;
			}
		}

		return false;
	}

	// ── The grouping itself ───────────────────────────────────────────────────

	/**
	 * Every word a form might use lands in the right group. Rewriting
	 * group_of() to read the shared list must not have changed a single one.
	 *
	 * @return void
	 */
	public function test_every_sample_falls_in_the_right_group(): void {
		foreach ( self::SAMPLES as $value => $expected ) {
			$this->assertSame( $expected, Kivun_Coordinators::group_of( (string) $value ), (string) $value );
		}
	}

	/**
	 * The words that contain one another, named on their own because they are
	 * the ones that break: each of these would read as male on a careless
	 * substring match.
	 *
	 * @return void
	 */
	public function test_the_words_that_contain_a_male_word_are_still_female(): void {
		foreach ( array( 'Female', 'woman', 'נערה' ) as $value ) {
			$this->assertSame( 'female', Kivun_Coordinators::group_of( $value ), $value );
		}
	}

	/**
	 * Nothing said means no group, which is what leaves a lead unplaced.
	 *
	 * @return void
	 */
	public function test_nothing_said_is_no_group(): void {
		$this->assertSame( '', Kivun_Coordinators::group_of( '' ) );
		$this->assertSame( '', Kivun_Coordinators::group_of( '   ' ) );
	}

	// ── The SQL built from the same words ─────────────────────────────────────

	/**
	 * The filter and the split agree about every value. This is the property
	 * worth having: whatever the rota called a man, the "men" filter lists,
	 * and nothing else.
	 *
	 * @return void
	 */
	public function test_the_filter_agrees_with_the_split_on_every_sample(): void {
		$male   = Kivun_Coordinators::gender_where( 'male', 'gender' );
		$female = Kivun_Coordinators::gender_where( 'female', 'gender' );

		foreach ( self::SAMPLES as $value => $group ) {
			$value = (string) $value;

			$this->assertSame(
				'male' === $group,
				$this->satisfies( $male, $value ),
				"\"{$value}\" is {$group} to the rota but the men's filter disagrees"
			);
			$this->assertSame(
				'female' === $group,
				$this->satisfies( $female, $value ),
				"\"{$value}\" is {$group} to the rota but the women's filter disagrees"
			);
		}
	}

	/**
	 * The male fragment carries the exclusion at all. Checked directly as
	 * well as through the samples, so removing it fails loudly rather than
	 * only through a value somebody might later delete from the list.
	 *
	 * @return void
	 */
	public function test_the_male_fragment_excludes_the_female_words(): void {
		$this->assertStringContainsString( 'AND NOT', Kivun_Coordinators::gender_where( 'male', 'gender' ) );
		$this->assertStringNotContainsString( 'AND NOT', Kivun_Coordinators::gender_where( 'female', 'gender' ) );
	}

	/**
	 * "No gender" selects exactly the leads the rota could not place —
	 * blank, and whitespace, which looks blank on screen and is not.
	 *
	 * @return void
	 */
	public function test_no_gender_selects_the_unplaced(): void {
		$this->assertSame( "TRIM( r.gender ) = ''", Kivun_Coordinators::gender_where( 'none', 'r.gender' ) );
	}

	/**
	 * Anything else selects nothing rather than everything — a filter that
	 * silently stopped filtering would be worse than one that errored.
	 *
	 * @return void
	 */
	public function test_an_unknown_group_builds_nothing(): void {
		$this->assertSame( '', Kivun_Coordinators::gender_where( 'other', 'r.gender' ) );
		$this->assertSame( '', Kivun_Coordinators::gender_where( '', 'r.gender' ) );
	}

	/**
	 * The column is used as given, so the fragment works against a joined
	 * table as well as a bare one.
	 *
	 * @return void
	 */
	public function test_the_column_is_the_one_asked_for(): void {
		$this->assertStringContainsString( 'r.gender LIKE', Kivun_Coordinators::gender_where( 'female', 'r.gender' ) );
	}

	/**
	 * A column name cannot be a placeholder, so it is interpolated — and
	 * anything that is not an identifier builds nothing rather than going
	 * into a query.
	 *
	 * @return void
	 */
	public function test_a_column_that_is_not_an_identifier_builds_nothing(): void {
		foreach ( array( "gender' OR 1=1 --", 'r.gender; DROP TABLE x', 'gender )', '' ) as $column ) {
			$this->assertSame( '', Kivun_Coordinators::gender_where( 'female', $column ), $column );
			$this->assertSame( '', Kivun_Coordinators::gender_where( 'none', $column ), $column );
		}
	}

	/**
	 * One list, read by both. A second copy would drift, and the drift would
	 * show up as a split the screen reports wrongly.
	 *
	 * @return void
	 */
	public function test_both_readers_use_one_list_of_words(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-coordinators.php' );

		$this->assertSame(
			1,
			substr_count( $source, "'נקבה'" ),
			'the gender words are written out more than once, and the copies will drift'
		);
	}
}
