<?php
/**
 * The Holland questionnaire: its content, its scoring, and the lead row it
 * keeps.
 *
 * What has to hold is the client's questionnaire as they wrote it — 216
 * items, twelve of each type in each of the three parts — scored the way the
 * method scores it: a "yes" is a point to the item's type, each type out of
 * 36, the three highest make the code. And that what is stored on the lead
 * is enough for the console and the export to show it, without either of
 * them knowing what a Holland code is.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Holland.
 */
final class HollandTest extends TestCase {

	/**
	 * A valid ID number, by its check digit.
	 */
	const ID = '123456782';

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
		$_POST = array();
	}

	/**
	 * Leave no request behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
	}

	/**
	 * Answers where the given item numbers are "yes" and the rest "no".
	 *
	 * @param array<int,int> $yes Item numbers.
	 * @return string
	 */
	private function answers( array $yes ): string {
		$out = str_repeat( 'n', Kivun_Holland::total() );
		foreach ( $yes as $num ) {
			$out[ $num - 1 ] = 'y';
		}
		return $out;
	}

	/**
	 * Run an endpoint and return what it sent.
	 *
	 * @param string $method ajax_start or ajax_save.
	 * @return Kivun_Test_Json
	 */
	private function call( string $method ): Kivun_Test_Json {
		try {
			call_user_func( array( 'Kivun_Holland', $method ) );
		} catch ( Kivun_Test_Json $sent ) {
			return $sent;
		}
		$this->fail( $method . ' sent nothing' );
	}

	// ── The questionnaire as the client wrote it ──────────────────────────────

	/**
	 * 216 items, twelve of each type in each part, numbered the way the
	 * spreadsheet numbers them.
	 *
	 * @return void
	 */
	public function test_every_part_has_twelve_items_of_each_type(): void {
		$items = Kivun_Holland::items();
		$this->assertCount( 216, $items );
		$this->assertSame( range( 1, 216 ), array_keys( $items ) );

		$count = array();
		foreach ( $items as $item ) {
			$count[ $item['section'] ][ $item['type'] ] = ( $count[ $item['section'] ][ $item['type'] ] ?? 0 ) + 1;
			$this->assertNotSame( '', trim( $item['text'] ) );
			$this->assertSame( trim( $item['text'] ), $item['text'], 'no stray whitespace from the spreadsheet' );
		}
		foreach ( array_keys( Kivun_Holland::sections() ) as $section ) {
			$this->assertSame( array_fill_keys( Kivun_Holland::TYPES, 12 ), $count[ $section ] );
		}

		// Spot checks against the sheet: the first and last item of each part.
		$this->assertSame( array( 'activities', 'R', 'לגזום דשא ושיחים בגינה' ), array_values( $items[1] ) );
		$this->assertSame( array( 'activities', 'C', 'להקליד נתונים אישיים ולבסס נתונים במחשב' ), array_values( $items[72] ) );
		$this->assertSame( array( 'skills', 'R', 'אני יכול לבנות גדר או פרגולה מעץ' ), array_values( $items[73] ) );
		$this->assertSame( array( 'skills', 'C', 'אני יכול לבצע הגהה מדויקת למסמך' ), array_values( $items[144] ) );
		$this->assertSame( array( 'professions', 'R', 'מנופאי' ), array_values( $items[145] ) );
		$this->assertSame( array( 'professions', 'I', 'חוקר מדעי הסביבה' ), array_values( $items[157] ) );
		$this->assertSame( array( 'professions', 'C', 'מומחה לבקרת איכות' ), array_values( $items[216] ) );
	}

	/**
	 * Asked a row of the paper table at a time: every run of six is one
	 * item of each type, and every item is asked exactly once.
	 *
	 * @return void
	 */
	public function test_the_types_are_mixed_the_way_the_paper_mixes_them(): void {
		$items = Kivun_Holland::items();
		$all   = array();
		foreach ( Kivun_Holland::asking_order() as $section => $nums ) {
			$this->assertCount( 72, $nums );
			foreach ( array_chunk( $nums, 6 ) as $row ) {
				$types = array_map( static fn( int $n ): string => $items[ $n ]['type'], $row );
				$this->assertSame( Kivun_Holland::TYPES, $types );
				foreach ( $row as $n ) {
					$this->assertSame( $section, $items[ $n ]['section'] );
				}
			}
			$all = array_merge( $all, $nums );
		}
		sort( $all );
		$this->assertSame( range( 1, 216 ), $all );
	}

	// ── Scoring ───────────────────────────────────────────────────────────────

	/**
	 * A "yes" is a point to its type in its part; the code is the three
	 * highest.
	 *
	 * @return void
	 */
	public function test_each_yes_is_one_point_to_its_type(): void {
		// S: all 36. E: the activities and skills (24). A: activities (12).
		$yes = array_merge(
			range( 37, 48 ),
			range( 109, 120 ),
			range( 181, 192 ),
			range( 49, 60 ),
			range( 121, 132 ),
			range( 25, 36 ),
			array( 1, 2 )
		);

		$score = Kivun_Holland::score( $this->answers( $yes ) );

		$this->assertSame(
			array(
				'R' => 2,
				'I' => 0,
				'A' => 12,
				'S' => 36,
				'E' => 24,
				'C' => 0,
			),
			$score['totals']
		);
		$this->assertSame( 'SEA', $score['code'] );
		$this->assertSame( 36, $score['max'] );
		$this->assertSame( 12, $score['sections']['skills']['S'] );
		$this->assertSame( 0, $score['sections']['professions']['E'] );
		$this->assertSame( 12, $score['sections']['activities']['A'] );
	}

	/**
	 * The code is three letters, never four. Equal totals are told apart by
	 * the professions part, then activities, then skills, and only then by
	 * R I A S E C order.
	 *
	 * @return void
	 */
	public function test_the_code_is_always_three_letters(): void {
		// Everything equal everywhere: the fixed order is all that is left.
		$all = Kivun_Holland::score( str_repeat( 'y', 216 ) );
		$this->assertSame( 'RIA', $all['code'] );
		$this->assertArrayNotHasKey( 'tie', $all );

		// S 20, A 12 + 1, then E and C level on 12. E's twelve are all
		// activities, C's all professions: the professions part decides.
		$yes   = array_merge( range( 37, 48 ), range( 109, 116 ), range( 25, 36 ), array( 97 ), range( 49, 60 ), range( 205, 216 ) );
		$score = Kivun_Holland::score( $this->answers( $yes ) );
		$this->assertSame( 12, $score['totals']['E'] );
		$this->assertSame( 12, $score['totals']['C'] );
		$this->assertSame( 'SAC', $score['code'] );
		$this->assertSame( array( 'S', 'A', 'C', 'E', 'R', 'I' ), Kivun_Holland::rank( $score['totals'], $score['sections'] ) );
		$this->assertStringContainsString( "קוד הולנד: SAC\n", Kivun_Holland::message( self::ID, $this->answers( $yes ), true ) );

		// Level on professions as well (6 each): activities decide — E has
		// six there, C none (its other six are skills).
		$yes   = array_merge( range( 37, 48 ), range( 109, 116 ), range( 25, 36 ), array( 97 ), range( 49, 54 ), range( 193, 198 ), range( 205, 210 ), range( 133, 138 ) );
		$score = Kivun_Holland::score( $this->answers( $yes ) );
		$this->assertSame( $score['totals']['E'], $score['totals']['C'] );
		$this->assertSame( 'SAE', $score['code'] );
	}

	/**
	 * A type nobody said "yes" to is no tendency, and never fills the code
	 * out to three letters.
	 *
	 * @return void
	 */
	public function test_a_type_with_no_yes_is_not_in_the_code(): void {
		$this->assertSame( 'S', Kivun_Holland::score( $this->answers( range( 37, 48 ) ) )['code'] );
		$this->assertSame( 'SE', Kivun_Holland::score( $this->answers( array( 37, 38, 49 ) ) )['code'] );

		$none = Kivun_Holland::score( str_repeat( 'n', 216 ) );
		$this->assertSame( '', $none['code'] );
		$this->assertStringContainsString( 'קוד הולנד: —', Kivun_Holland::message( self::ID, str_repeat( 'n', 216 ), true ) );
	}

	/**
	 * Whatever is posted, the answers are exactly 216 of y / n / -.
	 *
	 * @return void
	 */
	public function test_posted_answers_are_normalized(): void {
		$this->assertSame( str_repeat( '-', 216 ), Kivun_Holland::normalize_responses( '' ) );
		$this->assertSame( str_repeat( '-', 216 ), Kivun_Holland::normalize_responses( array( 'y' ) ) );

		$mixed = Kivun_Holland::normalize_responses( 'yNx1n' . str_repeat( 'y', 300 ) );
		$this->assertSame( 216, strlen( $mixed ) );
		$this->assertSame( 'y---n', substr( $mixed, 0, 5 ) );
		$this->assertSame( 213, Kivun_Holland::answered( $mixed ) );
	}

	// ── Who is answering ──────────────────────────────────────────────────────

	/**
	 * ID numbers are checked against their check digit and padded to nine.
	 *
	 * @return void
	 */
	public function test_id_numbers_are_checked(): void {
		$this->assertSame( self::ID, Kivun_Holland::normalize_id( self::ID ) );
		$this->assertSame( self::ID, Kivun_Holland::normalize_id( ' 12345678-2 ' ) );
		$this->assertSame( '000000018', Kivun_Holland::normalize_id( '18' ) );

		$this->assertSame( '', Kivun_Holland::normalize_id( '123456789' ), 'wrong check digit' );
		$this->assertSame( '', Kivun_Holland::normalize_id( '000000000' ) );
		$this->assertSame( '', Kivun_Holland::normalize_id( '1234567890' ), 'ten digits' );
		$this->assertSame( '', Kivun_Holland::normalize_id( '' ) );
	}

	/**
	 * Phones come back as local digits, from any of the ways they are typed.
	 *
	 * @return void
	 */
	public function test_phones_are_normalized(): void {
		$this->assertSame( '0501234567', Kivun_Holland::normalize_phone( '050-1234567' ) );
		$this->assertSame( '0501234567', Kivun_Holland::normalize_phone( '+972 50 123 4567' ) );
		$this->assertSame( '021234567', Kivun_Holland::normalize_phone( '02-1234567' ) );
		$this->assertSame( '', Kivun_Holland::normalize_phone( '12345' ) );
		$this->assertSame( '', Kivun_Holland::normalize_phone( '5012345678' ) );
	}

	// ── What the lead keeps ───────────────────────────────────────────────────

	/**
	 * The stored answers are in the shape the export already reads, the raw
	 * answers among them without a label, and read back as they went in.
	 *
	 * @return void
	 */
	public function test_stored_answers_round_trip_and_suit_the_export(): void {
		$responses = $this->answers( range( 37, 48 ) );
		$stored    = Kivun_Holland::stored_answers( self::ID, $responses, true, 5 );

		$labelled = array();
		foreach ( $stored as $entry ) {
			$this->assertArrayHasKey( 'key', $entry );
			if ( isset( $entry['label'] ) ) {
				$labelled[ $entry['key'] ] = $entry['value'];
			} else {
				$this->assertContains( $entry['key'], array( 'holland_responses', 'holland_done', 'holland_seq' ), 'only the bookkeeping goes unlabelled' );
			}
		}

		$this->assertSame( self::ID, $labelled['holland_id'] );
		$this->assertSame( 'הושלם', $labelled['holland_progress'] );
		$this->assertSame( 'S', $labelled['holland_code'] );
		$this->assertSame( '12', $labelled['holland_S'] );

		$back = Kivun_Holland::read_stored( (string) wp_json_encode( $stored ) );
		$this->assertSame( self::ID, $back['id'] );
		$this->assertSame( $responses, $back['responses'] );
		$this->assertTrue( $back['done'] );
		$this->assertSame( 5, $back['seq'] );

		// Every answer there but not submitted: still open, no code.
		$open = Kivun_Holland::stored_answers( self::ID, $responses );
		$this->assertNotContains( 'holland_code', array_column( $open, 'key' ) );
		$this->assertSame( 'בתהליך — 216 מתוך 216', $open[1]['value'] );
		$this->assertFalse( Kivun_Holland::read_stored( (string) wp_json_encode( $open ) )['done'] );

		// Unfinished: no code, and the progress says how far.
		$partial  = str_repeat( 'y', 40 ) . str_repeat( '-', 176 );
		$keys     = array_column( Kivun_Holland::stored_answers( self::ID, $partial ), 'key' );
		$progress = Kivun_Holland::stored_answers( self::ID, $partial )[1]['value'];
		$this->assertNotContains( 'holland_code', $keys );
		$this->assertSame( 'בתהליך — 40 מתוך 216', $progress );
	}

	/**
	 * The message is "label: value" lines, which the leads table lays out as
	 * questions and answers.
	 *
	 * @return void
	 */
	public function test_the_message_reads_as_answers_in_the_console(): void {
		$message = Kivun_Holland::message( self::ID, $this->answers( range( 37, 48 ) ), true );
		$pairs   = Kivun_Content_Creator::lead_answers( $message );
		$labels  = array_column( $pairs, 0 );

		$this->assertSame( array( 'תעודת זהות', 'שאלון הולנד', 'קוד הולנד', 'ציונים (מתוך 36)', 'פעילויות', 'כישורים', 'מקצועות' ), $labels );
		$this->assertSame( 'S', $pairs[2][1] );
		$this->assertStringStartsWith( 'S 12 · ', $pairs[3][1] );
		$this->assertSame( 'R 0 · I 0 · A 0 · S 12 · E 0 · C 0', $pairs[4][1] );
	}

	// ── Logging in ────────────────────────────────────────────────────────────

	/**
	 * Every detail is required and checked, and each refusal is put against
	 * its field.
	 *
	 * @return void
	 */
	public function test_login_refuses_missing_or_wrong_details(): void {
		$_POST = array(
			'name'      => 'א',
			'phone'     => '123',
			'id_number' => '123456789',
		);
		$sent = $this->call( 'ajax_start' );

		$this->assertFalse( $sent->ok );
		$this->assertSame( array( 'name', 'phone', 'id_number' ), array_keys( $sent->data['fields'] ) );
		$this->assertSame( array(), Kivun_Test_State::$inserts, 'nothing is written for a refused login' );
	}

	/**
	 * A first login writes the lead at once, filed as a Holland lead with the
	 * phone normalized and the ID number in its answers.
	 *
	 * @return void
	 */
	public function test_first_login_files_a_lead(): void {
		Kivun_Test_State::$posts[9] = array( 'post_title' => 'שאלון הולנד' );
		$_POST                      = array(
			'name'      => 'ישראל ישראלי',
			'phone'     => '050-123-4567',
			'id_number' => self::ID,
			'page'      => '9',
		);
		$sent = $this->call( 'ajax_start' );

		$this->assertTrue( $sent->ok );
		$this->assertCount( 1, Kivun_Test_State::$inserts );

		$row = Kivun_Test_State::$inserts[0]['data'];
		$this->assertSame( 'wp_kivun_registrations', Kivun_Test_State::$inserts[0]['table'] );
		$this->assertSame( 'holland', $row['type'] );
		$this->assertSame( '0501234567', $row['phone'] );
		$this->assertSame( 9, $row['course_id'] );
		$this->assertSame( 'ישראל ישראלי', $row['name'] );
		$this->assertSame( self::ID, Kivun_Holland::read_stored( $row['answers'] )['id'] );
		$this->assertStringContainsString( 'בתהליך — 0 מתוך 216', $row['message'] );

		// Formats match the columns one for one.
		$this->assertCount( count( $row ), Kivun_Test_State::$inserts[0]['format'] );

		$this->assertSame( 101, $sent->data['lead'] );
		$this->assertSame( Kivun_Holland::token( 101, self::ID ), $sent->data['token'] );
		$this->assertSame( str_repeat( '-', 216 ), $sent->data['responses'] );
	}

	/**
	 * Coming back with the same phone and ID number reopens the unfinished
	 * row and hands back the answers so far.
	 *
	 * @return void
	 */
	public function test_coming_back_continues_where_they_stopped(): void {
		$so_far = str_repeat( 'y', 30 ) . str_repeat( '-', 186 );

		$GLOBALS['wpdb']->results_handler = static function ( string $sql ) use ( $so_far ): array {
			if ( false === strpos( $sql, "'0501234567'" ) ) {
				return array();
			}
			return array(
				(object) array(
					'id'      => 55,
					'answers' => (string) wp_json_encode( Kivun_Holland::stored_answers( self::ID, $so_far ) ),
				),
			);
		};

		$_POST = array(
			'name'      => 'ישראל',
			'phone'     => '0501234567',
			'id_number' => self::ID,
		);
		$sent  = $this->call( 'ajax_start' );

		$this->assertTrue( $sent->ok );
		$this->assertSame( 55, $sent->data['lead'] );
		$this->assertSame( $so_far, $sent->data['responses'] );
		$this->assertSame( array(), Kivun_Test_State::$inserts, 'no second row' );

		// Someone else with the same phone does not get this row.
		$_POST['id_number'] = '000000018';
		$other              = $this->call( 'ajax_start' );
		$this->assertNotSame( 55, $other->data['lead'] );
		$this->assertCount( 1, Kivun_Test_State::$inserts );
	}

	// ── Saving and finishing ──────────────────────────────────────────────────

	/**
	 * Point the row reads at a stored row.
	 *
	 * @param int    $id        The row id.
	 * @param string $responses Its stored answers.
	 * @param bool   $done      Whether it was submitted.
	 * @param int    $seq       The last save's sequence number.
	 * @return void
	 */
	private function stored_row( int $id, string $responses, bool $done = false, int $seq = 0 ): void {
		$GLOBALS['wpdb']->row_handler = static function ( string $sql ) use ( $id, $responses, $done, $seq ) {
			if ( false === strpos( $sql, 'WHERE id = ' . $id . ' ' ) ) {
				return null;
			}
			return (object) array(
				'id'      => $id,
				'name'    => 'ישראל ישראלי',
				'phone'   => '0501234567',
				'answers' => (string) wp_json_encode( Kivun_Holland::stored_answers( self::ID, $responses, $done, $seq ) ),
			);
		};
	}

	/**
	 * Without the token from the login, nothing is written.
	 *
	 * @return void
	 */
	public function test_saving_needs_the_token(): void {
		$this->stored_row( 7, str_repeat( '-', 216 ) );
		$_POST = array(
			'lead'      => '7',
			'token'     => Kivun_Holland::token( 7, '000000018' ),
			'responses' => str_repeat( 'y', 216 ),
			'finish'    => '1',
		);
		$sent  = $this->call( 'ajax_save' );

		$this->assertFalse( $sent->ok );
		$this->assertTrue( $sent->data['relogin'] );
		$this->assertSame( array(), Kivun_Test_State::$updates );
	}

	/**
	 * Partial answers are saved; finishing with gaps is refused; finishing
	 * complete writes the result — and sends none of it back: the participant
	 * is thanked, and the representative prints the report.
	 *
	 * @return void
	 */
	public function test_saving_then_finishing(): void {
		$this->stored_row( 7, str_repeat( '-', 216 ) );
		$token = Kivun_Holland::token( 7, self::ID );

		$_POST = array(
			'lead'      => '7',
			'token'     => $token,
			'responses' => str_repeat( 'n', 50 ) . str_repeat( '-', 166 ),
		);
		$sent  = $this->call( 'ajax_save' );
		$this->assertTrue( $sent->ok );
		$this->assertSame( 50, $sent->data['saved'] );
		$this->assertStringContainsString( 'בתהליך — 50 מתוך 216', Kivun_Test_State::$updates[0]['data']['message'] );

		$_POST['finish'] = '1';
		$sent            = $this->call( 'ajax_save' );
		$this->assertFalse( $sent->ok );
		$this->assertSame( 'נותרו 166 שאלות ללא תשובה.', $sent->data['message'] );
		$this->assertCount( 1, Kivun_Test_State::$updates, 'a refused finish writes nothing' );

		$_POST['responses'] = $this->answers( range( 61, 72 ) );
		$sent               = $this->call( 'ajax_save' );
		$this->assertTrue( $sent->ok );
		$this->assertSame( array( 'done' => true ), $sent->data, 'no result goes back to the participant' );

		$written = Kivun_Test_State::$updates[1]['data'];
		$this->assertStringContainsString( 'קוד הולנד: C', $written['message'] );
		$this->assertSame( array( 'id' => 7 ), Kivun_Test_State::$updates[1]['where'] );
		$this->assertSame( array(), Kivun_Test_State::$templates, 'no result page is drawn for them' );

		$stored = Kivun_Holland::read_stored( $written['answers'] );
		$this->assertTrue( $stored['done'] );
		$this->assertNotSame( '', $stored['done_at'], 'when it was finished is kept for the report' );
		$this->assertSame( array(), Kivun_Test_State::$mail, 'with no address anywhere, nobody is written to' );
	}

	/**
	 * Finishing tells the representative: the name, the phone, the code and
	 * a button to the report — through a link with no nonce in it, since a
	 * nonce belongs to whoever it was made for. The ID number stays out.
	 *
	 * @return void
	 */
	public function test_finishing_emails_the_representative(): void {
		Kivun_Test_State::$options['kivun_settings'] = array(
			'admin_email'          => 'general@example.test',
			'holland_notify_email' => 'rep@example.test, second@example.test',
		);
		$this->stored_row( 7, str_repeat( '-', 216 ) );
		$_POST = array(
			'lead'      => '7',
			'token'     => Kivun_Holland::token( 7, self::ID ),
			'responses' => $this->answers( range( 61, 72 ) ),
			'finish'    => '1',
		);
		$this->assertTrue( $this->call( 'ajax_save' )->ok );

		$this->assertCount( 1, Kivun_Test_State::$mail );
		$mail = Kivun_Test_State::$mail[0];
		$this->assertSame( 'rep@example.test, second@example.test', $mail['to'] );
		$this->assertStringContainsString( 'שאלון הולנד הושלם — ישראל ישראלי', $mail['subject'] );
		$this->assertStringContainsString( '0501234567', $mail['message'] );
		$this->assertStringContainsString( 'קוד הולנד:</strong> C', $mail['message'] );
		$this->assertStringContainsString( 'פתיחת הדוח להדפסה', $mail['message'] );
		$this->assertStringContainsString( 'admin-post.php?action=kivun_holland_report_open&amp;lead=7', $mail['message'] );
		$this->assertStringNotContainsString( '_wpnonce', $mail['message'] );
		$this->assertStringNotContainsString( self::ID, $mail['message'], 'the ID number is not emailed' );

		// Autosaves tell nobody.
		Kivun_Test_State::$mail = array();
		unset( $_POST['finish'] );
		$_POST['responses'] = str_repeat( 'n', 30 ) . str_repeat( '-', 186 );
		$this->call( 'ajax_save' );
		$this->assertSame( array(), Kivun_Test_State::$mail );
	}

	/**
	 * Who is told: the questionnaire's own setting, or else the general
	 * notifications address, or else the site's. Only valid addresses.
	 *
	 * @return void
	 */
	public function test_who_is_told(): void {
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), Kivun_Holland::email_list( " a@example.test,\nb@example.test; not-an-address A@EXAMPLE.TEST" ) );

		Kivun_Test_State::$options['admin_email'] = 'site@example.test';
		$this->assertSame( array( 'site@example.test' ), Kivun_Holland::notify_recipients() );

		Kivun_Test_State::$options['kivun_settings'] = array( 'admin_email' => 'general@example.test' );
		$this->assertSame( array( 'general@example.test' ), Kivun_Holland::notify_recipients() );

		Kivun_Test_State::$options['kivun_settings']['holland_notify_email'] = 'rep@example.test';
		$this->assertSame( array( 'rep@example.test' ), Kivun_Holland::notify_recipients() );
	}

	/**
	 * A finished questionnaire is a record: posting to it again changes
	 * nothing and is answered as finished.
	 *
	 * @return void
	 */
	public function test_a_finished_questionnaire_is_not_rewritten(): void {
		$this->stored_row( 7, $this->answers( range( 37, 48 ) ), true );
		$_POST = array(
			'lead'      => '7',
			'token'     => Kivun_Holland::token( 7, self::ID ),
			'responses' => str_repeat( 'y', 216 ),
			'finish'    => '1',
		);
		$sent  = $this->call( 'ajax_save' );

		$this->assertTrue( $sent->ok );
		$this->assertSame( array( 'done' => true ), $sent->data );
		$this->assertSame( array(), Kivun_Test_State::$updates );
	}

	/**
	 * An autosave that happens to carry all 216 answers does not close the
	 * questionnaire: the answers can still change until "finish" is pressed,
	 * and the result is scored from what was submitted.
	 *
	 * @return void
	 */
	public function test_a_complete_autosave_does_not_finish(): void {
		$this->stored_row( 7, str_repeat( '-', 216 ) );
		$_POST = array(
			'lead'      => '7',
			'token'     => Kivun_Holland::token( 7, self::ID ),
			'responses' => $this->answers( range( 61, 72 ) ),
		);
		$sent  = $this->call( 'ajax_save' );

		$this->assertTrue( $sent->ok );
		$this->assertSame( 216, $sent->data['saved'] );
		$this->assertArrayNotHasKey( 'html', $sent->data );

		$written = Kivun_Test_State::$updates[0]['data'];
		$this->assertStringNotContainsString( 'קוד הולנד', $written['message'] );
		$this->assertFalse( Kivun_Holland::read_stored( $written['answers'] )['done'] );

		// The row is as the autosave left it; a changed answer and "finish"
		// are both taken, and the result is scored from the changed answers.
		$this->stored_row( 7, $this->answers( range( 61, 72 ) ) );
		$_POST['responses'] = $this->answers( range( 37, 48 ) );
		$_POST['finish']    = '1';
		$sent               = $this->call( 'ajax_save' );

		$this->assertTrue( $sent->ok );
		$this->assertTrue( $sent->data['done'] );
		$this->assertStringContainsString( 'קוד הולנד: S', Kivun_Test_State::$updates[1]['data']['message'] );
		$this->assertTrue( Kivun_Holland::read_stored( Kivun_Test_State::$updates[1]['data']['answers'] )['done'] );
	}

	/**
	 * A save older than the one already stored is dropped, so a snapshot
	 * that arrives late cannot undo newer answers.
	 *
	 * @return void
	 */
	public function test_an_older_save_does_not_overwrite_a_newer_one(): void {
		$this->stored_row( 7, str_repeat( 'y', 40 ) . str_repeat( '-', 176 ), false, 2000 );
		$_POST = array(
			'lead'      => '7',
			'token'     => Kivun_Holland::token( 7, self::ID ),
			'responses' => str_repeat( 'y', 20 ) . str_repeat( '-', 196 ),
			'seq'       => '1000',
		);
		$sent  = $this->call( 'ajax_save' );

		$this->assertTrue( $sent->ok );
		$this->assertSame( 40, $sent->data['saved'] );
		$this->assertSame( array(), Kivun_Test_State::$updates );

		$_POST['seq']       = '3000';
		$_POST['responses'] = str_repeat( 'y', 50 ) . str_repeat( '-', 166 );
		$this->call( 'ajax_save' );
		$this->assertSame( 3000, Kivun_Holland::read_stored( Kivun_Test_State::$updates[0]['data']['answers'] )['seq'] );
	}

	/**
	 * A submitted questionnaire is never reopened by logging in again; that
	 * starts a new row.
	 *
	 * @return void
	 */
	public function test_logging_in_after_finishing_starts_afresh(): void {
		$GLOBALS['wpdb']->results_handler = static fn(): array => array(
			(object) array(
				'id'      => 55,
				'answers' => (string) wp_json_encode( Kivun_Holland::stored_answers( self::ID, str_repeat( 'y', 216 ), true ) ),
			),
		);
		$_POST = array(
			'name'      => 'ישראל',
			'phone'     => '0501234567',
			'id_number' => self::ID,
		);
		$sent  = $this->call( 'ajax_start' );

		$this->assertNotSame( 55, $sent->data['lead'] );
		$this->assertCount( 1, Kivun_Test_State::$inserts );
	}

	// ── The page ──────────────────────────────────────────────────────────────

	/**
	 * Render a template the way kivun_get_template() would.
	 *
	 * @param string              $template Relative path.
	 * @param array<string,mixed> $vars     Its variables.
	 * @return string
	 */
	private function render( string $template, array $vars ): string {
		ob_start();
		( static function () use ( $template, $vars ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include KIVUN_DIR . 'templates/' . $template;
		} )();
		return Kivun_Content_Creator::flatten_output( (string) ob_get_clean() );
	}

	/**
	 * The questionnaire draws every item once, each with a yes and a no, and
	 * nothing in it that a page builder's wpautop() would take apart.
	 *
	 * @return void
	 */
	public function test_the_page_asks_every_item_once(): void {
		$html = $this->render(
			'holland/questionnaire.php',
			array(
				'page_id'  => 9,
				'sections' => Kivun_Holland::sections(),
				'order'    => Kivun_Holland::asking_order(),
				'items'    => Kivun_Holland::items(),
				'total'    => 216,
				'per_page' => Kivun_Holland::PAGE_SIZE,
			)
		);

		$this->assertSame( 216, substr_count( $html, 'data-hl-item=' ) );
		$this->assertSame( 216, substr_count( $html, 'value="y"' ) );
		$this->assertSame( 216, substr_count( $html, 'value="n"' ) );
		for ( $n = 1; $n <= 216; $n++ ) {
			$this->assertSame( 2, substr_count( $html, 'name="kivun_hl_' . $n . '"' ), 'item ' . $n );
		}

		// Three intros, and every question a screen of its own.
		$this->assertSame( 3 + 216, substr_count( $html, 'data-hl-screen' ) );
		$this->assertSame( 216, substr_count( $html, 'kivun-hl-page--single' ) );
		$this->assertStringContainsString( '<span class="kivun-hl-qhead__part">פעילויות</span>', $html );
		$this->assertStringContainsString( 'שאלה <b class="kivun-hl-qhead__num">1</b> מתוך 72', $html );
		$this->assertStringContainsString( 'שאלה <b class="kivun-hl-qhead__num">72</b> מתוך 72', $html );

		// The login: the title over the model, and the opening as two paragraphs, word for word.
		$this->assertStringContainsString( '<h2 class="kivun-hl-title">שאלון הכוון תעסוקתי</h2>', $html );
		$this->assertStringContainsString( '<p class="kivun-hl-subtitle">מודל הולנד</p>', $html );
		$this->assertSame( 2, substr_count( $html, '<p class="kivun-hl-lead">' ) );
		$this->assertStringContainsString( '<p class="kivun-hl-lead">השאלון נועד לבדוק את הנטיות המקצועיות שלך ולסייע בידך בבחירה ובהכוון מקצועי.</p>', $html );
		$this->assertStringContainsString( '<p class="kivun-hl-lead">אנא קרא את ההוראות בדייקנות והקפד לענות על כל השאלות.</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-hl-finish' ) );
		$this->assertStringContainsString( 'סיום השאלון', $html );

		// Each part opens with its own journey and its two instructions, word
		// for word from the sheet as the design splits them.
		$this->assertSame( 3, substr_count( $html, 'data-hl-intro' ) );
		$this->assertSame( 3, substr_count( $html, 'kivun-hl-steps--lg' ) );
		$this->assertStringContainsString( 'בחר ב"לא" את הפעילויות שמעולם לא בצעת או שבצעת באופן גרוע.', html_entity_decode( $html ) );
		$this->assertSame( 216, substr_count( $html, 'data-hl-stage' ) );

		// The end is a thank-you; there is no result on the page at all.
		$this->assertStringContainsString( 'data-hl-thanks', $html );
		$this->assertStringContainsString( 'תודה רבה על ההשתתפות!', $html );
		$this->assertStringNotContainsString( 'קוד הולנד', $html );
		$this->assertStringContainsString( 'assets/images/kivun-logo.png', $html );

		// The rules from wpautop(): every paragraph of ours has a class, and
		// no block sits inside a span or a label.
		$this->assertDoesNotMatchRegularExpression( '/<p(\s(?![^>]*class=)[^>]*)?>/', $html );
		$this->assertDoesNotMatchRegularExpression( '#<(span|label)\b[^>]*>(?:(?!</\1>).)*<(div|p|form|fieldset)\b#s', $html );

		$this->assertStringContainsString( 'name="id_number"', $html );
		$this->assertStringContainsString( 'name="phone"', $html );
		$this->assertStringContainsString( 'name="name"', $html );
	}

	// ── The page around it ────────────────────────────────────────────────────

	/**
	 * The page with the shortcode is recognised whether the shortcode is in
	 * its content or in an Elementor Shortcode widget — that is how it gets
	 * the page without the site's header and footer.
	 *
	 * @return void
	 */
	public function test_the_questionnaire_page_is_recognised(): void {
		$in_content = (object) array(
			'ID'           => 21,
			'post_content' => 'פתיחה [kivun_holland] סוף',
		);
		$in_builder = (object) array(
			'ID'           => 22,
			'post_content' => '',
		);
		Kivun_Test_State::$meta[22]['_elementor_data'] = '[{"widgetType":"shortcode","settings":{"shortcode":"[kivun_holland]"}}]';
		$other = (object) array(
			'ID'           => 23,
			'post_content' => '[kivun_jobs]',
		);

		$this->assertTrue( Kivun_Holland::page_has_questionnaire( $in_content ) );
		$this->assertTrue( Kivun_Holland::page_has_questionnaire( $in_builder ) );
		$this->assertFalse( Kivun_Holland::page_has_questionnaire( $other ) );
		$this->assertFalse( Kivun_Holland::page_has_questionnaire( null ) );
	}

	/**
	 * The page's own template draws the content and WordPress's head and
	 * footer hooks — what styles and scripts need — and none of the theme's
	 * header, footer or title.
	 *
	 * @return void
	 */
	public function test_the_page_has_no_site_header_or_footer(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'templates/holland/blank-page.php' );

		foreach ( array( 'wp_head()', 'wp_body_open()', 'the_content()', 'wp_footer()', "body_class( 'kivun-holland-page' )" ) as $needed ) {
			$this->assertStringContainsString( $needed, $tpl );
		}
		foreach ( array( 'get_header', 'get_footer', 'the_title', 'get_template_part' ) as $left_out ) {
			$this->assertStringNotContainsString( $left_out, $tpl );
		}
	}

	// ── The representative's report ───────────────────────────────────────────

	/**
	 * A finished lead row, as the report reads it.
	 *
	 * @param array<int,int> $yes  Item numbers answered "yes".
	 * @param bool           $done Whether it was submitted.
	 * @return object
	 */
	private function lead_row( array $yes, bool $done = true ): object {
		return (object) array(
			'id'         => 7,
			'type'       => 'holland',
			'name'       => 'ישראל ישראלי',
			'phone'      => '0501234567',
			'created_at' => '2026-11-08 10:00:00',
			'answers'    => (string) wp_json_encode( Kivun_Holland::stored_answers( self::ID, $this->answers( $yes ), $done, 0, '2026-11-09 12:30:00' ) ),
		);
	}

	/**
	 * Render the report for a set of "yes" answers.
	 *
	 * @param array<int,int> $yes Item numbers answered "yes".
	 * @return string
	 */
	private function report( array $yes ): string {
		require_once KIVUN_DIR . 'includes/functions.php';
		Kivun_Holland::report_html( $this->lead_row( $yes ) );
		$call = end( Kivun_Test_State::$templates );
		$this->assertSame( 'holland/report.php', $call['template'] );
		ob_start();
		( static function () use ( $call ) {
			extract( $call['vars'], EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include KIVUN_DIR . 'templates/' . $call['template'];
		} )();
		return (string) ob_get_clean();
	}

	/**
	 * Only a finished Holland questionnaire has a report.
	 *
	 * @return void
	 */
	public function test_only_a_finished_questionnaire_has_a_report(): void {
		$this->assertTrue( Kivun_Holland::has_report( $this->lead_row( range( 37, 48 ) ) ) );
		$this->assertFalse( Kivun_Holland::has_report( $this->lead_row( range( 37, 48 ), false ) ) );

		$other       = $this->lead_row( range( 37, 48 ) );
		$other->type = 'form';
		$this->assertFalse( Kivun_Holland::has_report( $other ) );

		$url = Kivun_Holland::report_url( 7 );
		$this->assertStringStartsWith( 'https://example.test/wp-admin/admin-ajax.php?', $url );
		$this->assertStringContainsString( 'action=kivun_holland_report', $url );
		$this->assertStringContainsString( 'lead=7', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * The report carries a name, a phone and an ID number: only the people
	 * who handle the leads may open it.
	 *
	 * @return void
	 */
	public function test_the_report_is_for_staff_only(): void {
		$_GET = array( 'lead' => '7' );
		kivun_test_set_caps( array() );
		try {
			Kivun_Holland::ajax_report();
			$this->fail( 'the report opened without the leads capability' );
		} catch ( Kivun_Test_Die $died ) {
			$this->assertSame( 403, $died->status );
		}

		// Staff, but the row is not a finished questionnaire.
		kivun_test_set_caps( array( 'edit_others_posts' ) );
		$GLOBALS['wpdb']->row_handler = fn() => $this->lead_row( range( 37, 48 ), false );
		try {
			Kivun_Holland::ajax_report();
			$this->fail( 'an unfinished questionnaire has no report' );
		} catch ( Kivun_Test_Die $died ) {
			$this->assertSame( 404, $died->status );
		}
		$_GET = array();
	}

	/**
	 * The report, in the order of the client's document: (1) tendencies and
	 * the Holland code, (2) the three-letter code with a line on each of its
	 * domains, (3) the table and the chart, (4) all six types explained.
	 *
	 * @return void
	 */
	public function test_the_report(): void {
		// S 25, E 12, A 6.
		$html = $this->report( array_merge( range( 37, 48 ), range( 109, 120 ), array( 183 ), range( 49, 60 ), range( 25, 30 ) ) );
		$text = html_entity_decode( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );

		$this->assertStringContainsString( '<!doctype html>', $html );
		$this->assertStringContainsString( 'שאלון הכוון תעסוקתי – מודל הולנד', $text );
		$this->assertStringContainsString( 'ישראל ישראלי', $text );
		$this->assertStringContainsString( '0501234567', $text );
		$this->assertStringContainsString( self::ID, $text );
		$this->assertStringContainsString( '9.11.2026', $text, 'the day it was finished, not the day it was started' );

		// The four parts, in order.
		$at = array_map(
			static fn( string $needle ): int => (int) strpos( $text, $needle ),
			array( 'נטיות תעסוקתיות נטיות מקצועיות משקפות', 'להלן הקוד SEA', 'פירוט הבחירות', 'הסבר על נטיות תעסוקתיות', 'אפיונים של תחומים מקצועיים' )
		);
		$this->assertNotContains( 0, $at );
		$sorted = $at;
		sort( $sorted );
		$this->assertSame( $sorted, $at );

		// 1: the client's words, bold where the document is bold.
		$this->assertStringContainsString( 'הקוד מציג את <b>שלוש הנטיות החזקות ביותר שלך</b> לפי סדר העוצמה שלהן', $html );
		$this->assertStringContainsString( 'משמש כ<b>מצפן אישי</b>.', $html );

		// 2: three tiles, S E A in their colours, and a line on each domain.
		preg_match_all( '#<div class="r-tile" style="--c:(\#[0-9a-f]{6});[^"]*"><div class="r-tile__box">([RIASEC])</div>#', $html, $tiles );
		$this->assertSame( array( 'S', 'E', 'A' ), $tiles[2] );
		$this->assertSame( array( '#e8b400', '#d6304f', '#e06b12' ), $tiles[1] );
		$this->assertStringContainsString( 'נטיותיך המקצועיות ממוקדות בשלושה תחומים מרכזיים: תחום חברתי ותחום יוזמתי ותחום אמנותי', $text );
		$this->assertSame( 3, substr_count( $html, 'class="r-rest__row"' ) );
		$this->assertStringContainsString( 'תחום אמנותי (נוטה לביטוי עצמי, יצירה אמנותית, שימוש בדמיון, ביטוי של רגשות ופעילויות אינדיבידואליסטיות)', $text );
		$this->assertStringContainsString( 'לפירוט התחומים ומשמעותם ראה בהמשך', $text );

		// 3: the table with a total column, and six columns in R I A S E C
		// order with the code's three marked.
		$this->assertStringContainsString( 'כישורים', $text );
		$this->assertStringContainsString( '<td>' . ( 25 + 12 + 6 ) . '</td>', $html, 'the grand total' );
		preg_match_all( '#<li class="r-col( is-top)?" style="[^"]*">\s*<span class="r-col__pct">(\d+)%#', $html, $cols );
		$this->assertSame( array( '', '', ' is-top', ' is-top', ' is-top', '' ), $cols[1] );
		$this->assertSame( array( '0', '0', '17', '69', '33', '0' ), $cols[2] );
		$this->assertStringContainsString( '25/36', $text );

		// 4: a card for every type, whatever the code.
		$this->assertSame( 6, substr_count( $html, 'class="r-card"' ) );
		foreach ( Kivun_Holland::type_info() as $info ) {
			$this->assertStringContainsString( $info['about'], $text );
			$this->assertStringContainsString( 'אפיונים: ' . $info['traits'], $text );
			$this->assertStringContainsString( 'מקצועות: ' . $info['fields'], $text );
		}
	}

	/**
	 * A tie for third still gives three tiles. Fewer types with a "yes"
	 * than three: as many as there are. No "yes" at all: six dashed squares
	 * and "no profile" — and still the explanation of every type.
	 *
	 * @return void
	 */
	public function test_the_report_for_ties_and_for_nothing(): void {
		$tie = $this->report( array_merge( range( 37, 48 ), range( 109, 116 ), range( 25, 36 ), array( 97 ), range( 49, 60 ), range( 205, 216 ) ) );
		$this->assertSame( 3, substr_count( $tie, 'class="r-tile"' ) );
		$this->assertStringContainsString( 'להלן הקוד', $tie );
		$this->assertStringNotContainsString( 'שוויון', $tie );

		$two = $this->report( array( 37, 38, 49 ) );
		$this->assertSame( 2, substr_count( $two, 'class="r-tile"' ) );
		$this->assertStringContainsString( 'ממוקדות בשני תחומים מרכזיים: תחום חברתי ותחום יוזמתי', $two );

		$none = $this->report( array() );
		$this->assertSame( 0, substr_count( $none, 'class="r-tile"' ) );
		$this->assertSame( 6, substr_count( $none, 'class="r-tile r-tile--empty"' ) );
		$this->assertStringContainsString( 'לא נבנה פרופיל', $none );
		$this->assertSame( 0, substr_count( $none, 'class="r-rest__row"' ) );
		$this->assertSame( 6, substr_count( $none, 'class="r-card"' ) );
	}

	/**
	 * A row saved under the old rules — a four-letter code — reads as the
	 * dashboard and the report show it once refreshed; other rows pass
	 * through untouched.
	 *
	 * @return void
	 */
	public function test_an_old_row_is_refreshed(): void {
		$responses = $this->answers( array( 1, 13, 25, 37 ) );
		$stored    = Kivun_Holland::stored_answers( self::ID, $responses, true, 4, '2026-11-01 10:00:00' );
		foreach ( $stored as $i => $entry ) {
			if ( 'holland_code' === $entry['key'] ) {
				$stored[ $i ]['value'] = 'RIAS';
			}
		}
		$row = array(
			'type'    => 'holland',
			'message' => "תעודת זהות: 000000018\nקוד הולנד: RIAS (שוויון במקום השלישי)",
			'answers' => (string) wp_json_encode( $stored ),
		);

		$fresh = Kivun_Holland::refresh_row( $row );
		$this->assertStringContainsString( 'קוד הולנד: RIA' . "\n", $fresh['message'] );
		$this->assertStringNotContainsString( 'RIAS', $fresh['message'] . $fresh['answers'] );
		$again = Kivun_Holland::read_stored( $fresh['answers'] );
		$this->assertSame( $responses, $again['responses'] );
		$this->assertSame( 4, $again['seq'] );
		$this->assertSame( '2026-11-01 10:00:00', $again['done_at'] );

		$other = array(
			'type'    => 'form',
			'message' => 'x',
		);
		$this->assertSame( $other, Kivun_Holland::refresh_row( $other ) );
	}
}
