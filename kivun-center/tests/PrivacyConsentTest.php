<?php
/**
 * The agreement a visitor gives before their details are taken.
 *
 * What these hold still is the boundary either side of it: it appears only
 * where a site has turned it on *and* named the document being agreed to, and
 * where it appears it cannot be skipped — not by leaving the box unticked,
 * and not by posting around the form that drew it.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * When the consent is asked, what it says, and whether it can be avoided.
 */
final class PrivacyConsentTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Put the plugin's settings into a given state.
	 *
	 * @param array $settings The settings to store.
	 * @return void
	 */
	private function settings( array $settings ): void {
		Kivun_Test_State::$options['kivun_settings'] = $settings;
	}

	// ── When it is asked ─────────────────────────────────────────────────────

	/**
	 * Nothing is asked of a site that has not turned it on.
	 *
	 * @return void
	 */
	public function test_a_site_that_has_not_asked_for_it_is_unchanged(): void {
		$this->settings( array() );

		$this->assertFalse( Kivun_Privacy_Consent::enabled() );
		$this->assertFalse( Kivun_Privacy_Consent::asked() );
	}

	/**
	 * Turned on but pointing nowhere draws nothing.
	 *
	 * This is the one state that must not reach a visitor: a required box
	 * asking them to agree to a document the site has not named. It fails
	 * closed, so a half-finished setting cannot block every enquiry on the
	 * site.
	 *
	 * @return void
	 */
	public function test_turned_on_with_no_document_named_asks_nothing(): void {
		$this->settings( array( 'privacy_consent_enabled' => true ) );

		$this->assertTrue( Kivun_Privacy_Consent::enabled(), 'the setting did not take' );
		$this->assertFalse( Kivun_Privacy_Consent::asked(), 'a consent was demanded with nothing to read' );
	}

	/**
	 * Turned on and pointed at a policy is asked.
	 *
	 * @return void
	 */
	public function test_turned_on_and_pointed_at_a_policy_is_asked(): void {
		$this->settings(
			array(
				'privacy_consent_enabled' => true,
				'privacy_url'             => 'https://example.org/privacy',
			)
		);

		$this->assertTrue( Kivun_Privacy_Consent::asked() );
	}

	// ── What it says ─────────────────────────────────────────────────────────

	/**
	 * The sentence links to the policy.
	 *
	 * @return void
	 */
	public function test_the_sentence_links_to_the_policy(): void {
		$this->settings(
			array(
				'privacy_consent_enabled' => true,
				'privacy_url'             => 'https://example.org/privacy',
			)
		);

		$label = Kivun_Privacy_Consent::label();
		$this->assertStringContainsString( 'https://example.org/privacy', $label );
		$this->assertStringContainsString( 'מדיניות הפרטיות', $label );
	}

	/**
	 * Terms are named only when the site has any.
	 *
	 * A sentence promising terms of use that link nowhere is worse than one
	 * that never mentions them.
	 *
	 * @return void
	 */
	public function test_terms_are_named_only_when_the_site_has_them(): void {
		$this->settings(
			array(
				'privacy_consent_enabled' => true,
				'privacy_url'             => 'https://example.org/privacy',
			)
		);
		$this->assertStringNotContainsString( 'תנאי השימוש', Kivun_Privacy_Consent::label() );

		$this->settings(
			array(
				'privacy_consent_enabled' => true,
				'privacy_url'             => 'https://example.org/privacy',
				'terms_url'               => 'https://example.org/terms',
			)
		);
		$label = Kivun_Privacy_Consent::label();
		$this->assertStringContainsString( 'תנאי השימוש', $label );
		$this->assertStringContainsString( 'https://example.org/terms', $label );
	}

	/**
	 * A link the client typed cannot become markup.
	 *
	 * The addresses are a setting, and a setting is something a person types.
	 *
	 * @return void
	 */
	public function test_a_typed_address_cannot_become_markup(): void {
		$this->settings(
			array(
				'privacy_consent_enabled' => true,
				'privacy_url'             => 'https://example.org/p"><script>alert(1)</script>',
			)
		);

		$label = Kivun_Privacy_Consent::label();
		$this->assertStringNotContainsString( '<script>', $label );
	}

	// ── Whether it can be avoided ────────────────────────────────────────────

	/**
	 * An unticked box is not an agreement.
	 *
	 * @return void
	 */
	public function test_an_unticked_box_is_not_an_agreement(): void {
		$this->assertFalse( Kivun_Privacy_Consent::given( array() ) );
		$this->assertFalse( Kivun_Privacy_Consent::given( array( 'privacy_consent' => '' ) ) );
		$this->assertFalse( Kivun_Privacy_Consent::given( array( 'privacy_consent' => '0' ) ) );
	}

	/**
	 * A ticked one is.
	 *
	 * @return void
	 */
	public function test_a_ticked_box_is_an_agreement(): void {
		$this->assertTrue( Kivun_Privacy_Consent::given( array( 'privacy_consent' => '1' ) ) );
	}

	/**
	 * The submission is refused on the server, not only in the browser.
	 *
	 * `required` in the markup is a convenience for the person filling the
	 * form in; anything posting to the endpoint directly never saw it. A
	 * consent that can be skipped by posting around the form is not a record
	 * of anything.
	 *
	 * @return void
	 */
	public function test_the_submission_is_refused_on_the_server(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );

		$at = strpos( $src, 'function ajax_submit_lead' );
		$this->assertNotFalse( $at, 'the submission handler moved' );
		$body = substr( $src, $at, 2500 );

		$this->assertStringContainsString( 'Kivun_Privacy_Consent::asked()', $body );
		$this->assertStringContainsString( 'Kivun_Privacy_Consent::given(', $body );

		// And before the lead is written, not after.
		$check = strpos( $body, 'Kivun_Privacy_Consent::given(' );
		$write = strpos( $body, 'self::save_lead(' );
		$this->assertNotFalse( $check );
		if ( false !== $write ) {
			$this->assertLessThan( $write, $check, 'the lead is filed before the agreement is checked' );
		}
	}

	/**
	 * Every form that posts to that handler collects the agreement.
	 *
	 * The refusal is in one handler, and three templates post to it — the two
	 * lead forms and the event registration, which says so in its own
	 * docblock. I added the field to two of them and told the client the
	 * third was a separate path. It is not: turning the setting on would have
	 * refused every event registration on the site, because that form sends
	 * no such field however it is filled in.
	 *
	 * So this does not name the templates. It finds whatever posts to the
	 * shared handler and holds all of them to the same rule, which is the
	 * only version of this test that would have caught the one I missed.
	 *
	 * @return void
	 */
	public function test_every_form_on_that_handler_collects_it(): void {
		$dir   = KIVUN_DIR . 'templates';
		$found = array();

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$src = (string) file_get_contents( $file->getPathname() );
			if ( false === strpos( $src, 'kivun-lead-form' ) ) {
				continue;
			}

			$found[] = str_replace( $dir . '/', '', $file->getPathname() );
			$this->assertStringContainsString(
				'privacy_consent',
				$src,
				$file->getFilename() . ' posts to the lead handler but sends no agreement, so every submission from it would be refused'
			);
		}

		$this->assertGreaterThanOrEqual( 3, count( $found ), 'the forms moved; this test found almost none of them' );
	}

	// ── What is kept ─────────────────────────────────────────────────────────

	/**
	 * The agreement is a column of its own, not a line in the message.
	 *
	 * Asked for later — who agreed, and when — it has to be something the
	 * database can answer rather than something a person reads off a note.
	 *
	 * @return void
	 */
	public function test_the_agreement_is_kept_as_a_column(): void {
		$installer = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );

		$this->assertStringContainsString( 'privacy_consent', $installer );
		// Both the fresh table and the safety net that adds columns to an
		// install that already exists — a column only in the CREATE never
		// reaches a site that has been running for a year.
		$this->assertSame(
			2,
			substr_count( $installer, 'privacy_consent' ),
			'the column is in only one of the two places an install can be built from'
		);
	}

	/**
	 * And it is written as a number, like the consent beside it.
	 *
	 * The formats are positional: a column added without its placeholder
	 * shifts every value after it onto the wrong column, and $wpdb writes
	 * that without complaint.
	 *
	 * @return void
	 */
	public function test_the_agreement_is_written_as_a_number(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );

		$this->assertStringContainsString(
			"array( 'course_id', 'marketing_consent', 'privacy_consent' )",
			$src,
			'the agreement would be written as text into an integer column'
		);
	}

	/**
	 * It reaches the export, where the record is actually pulled from.
	 *
	 * @return void
	 */
	public function test_it_reaches_the_export(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		$this->assertStringContainsString( "'אישור פרטיות'", $src, 'no heading for it' );
		$this->assertStringContainsString( "empty( \$r['privacy_consent'] )", $src, 'no cell under the heading' );
	}
}
