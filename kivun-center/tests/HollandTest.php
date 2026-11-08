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
		$this->assertFalse( $score['tie'] );
		$this->assertSame( 36, $score['max'] );
		$this->assertSame( 12, $score['sections']['skills']['S'] );
		$this->assertSame( 0, $score['sections']['professions']['E'] );
		$this->assertSame( 12, $score['sections']['activities']['A'] );
	}

	/**
	 * Equal scores keep R I A S E C order, and a third place shared with the
	 * fourth is reported rather than decided silently.
	 *
	 * @return void
	 */
	public function test_ties_are_broken_in_riasec_order_and_flagged(): void {
		$all = Kivun_Holland::score( str_repeat( 'y', 216 ) );
		$this->assertSame( 'RIA', $all['code'] );
		$this->assertTrue( $all['tie'] );

		// S 20, A 13, then E and C level on 12: E takes third place by the
		// order, and the tie with C is reported.
		$score = Kivun_Holland::score( $this->answers( array_merge( range( 37, 48 ), range( 109, 116 ), range( 25, 36 ), array( 97 ), range( 49, 60 ), range( 61, 72 ) ) ) );
		$this->assertSame( 'SAE', $score['code'] );
		$this->assertTrue( $score['tie'] );
		$this->assertSame( array( 'S', 'A', 'E', 'C', 'R', 'I' ), Kivun_Holland::rank( $score['totals'] ) );
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
		$this->assertSame( 'SRI', $labelled['holland_code'] );
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

		$this->assertSame( array( 'תעודת זהות', 'שאלון הולנד', 'קוד הולנד', 'ציונים (מתוך 36)', 'פעילויות', 'כשרים', 'מקצועות' ), $labels );
		$this->assertSame( 'SRI', $pairs[2][1] );
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
	 * complete writes the result and returns the page.
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
		$this->assertArrayHasKey( 'html', $sent->data );

		$written = Kivun_Test_State::$updates[1]['data'];
		$this->assertStringContainsString( 'קוד הולנד: CRI', $written['message'] );
		$this->assertSame( array( 'id' => 7 ), Kivun_Test_State::$updates[1]['where'] );

		$template = end( Kivun_Test_State::$templates );
		$this->assertSame( 'holland/results.php', $template['template'] );
		$this->assertSame( array( 'C', 'R', 'I' ), $template['vars']['top'] );
	}

	/**
	 * A finished questionnaire is a record: posting to it again changes
	 * nothing and gets the same result back.
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
		$this->assertSame( array(), Kivun_Test_State::$updates );
		$this->assertSame( array( 'S', 'R', 'I' ), end( Kivun_Test_State::$templates )['vars']['top'] );
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
		$this->assertArrayHasKey( 'html', $sent->data );
		$this->assertStringContainsString( 'קוד הולנד: SRI', Kivun_Test_State::$updates[1]['data']['message'] );
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

		// Three intros and six pages a part.
		$this->assertSame( 21, substr_count( $html, 'data-hl-screen' ) );
		$this->assertSame( 1, substr_count( $html, 'data-hl-finish' ) );

		// The rules from wpautop(): every paragraph of ours has a class, and
		// no block sits inside a span or a label.
		$this->assertDoesNotMatchRegularExpression( '/<p(\s(?![^>]*class=)[^>]*)?>/', $html );
		$this->assertDoesNotMatchRegularExpression( '#<(span|label)\b[^>]*>(?:(?!</\1>).)*<(div|p|form|fieldset)\b#s', $html );

		$this->assertStringContainsString( 'name="id_number"', $html );
		$this->assertStringContainsString( 'name="phone"', $html );
		$this->assertStringContainsString( 'name="name"', $html );
	}

	/**
	 * The result shows the code, every score, and the three leading types'
	 * descriptions.
	 *
	 * @return void
	 */
	public function test_the_result_page(): void {
		$responses = $this->answers( array_merge( range( 37, 48 ), range( 109, 120 ), range( 49, 60 ), range( 25, 30 ) ) );
		Kivun_Holland::results_html( $responses );
		$call = end( Kivun_Test_State::$templates );
		$html = $this->render( $call['template'], $call['vars'] );

		$this->assertStringContainsString( 'S E A', $html );
		$this->assertStringContainsString( 'חברתי-טיפולי · יזמי-ניהולי · אמנותי-יצירתי', $html );
		$this->assertStringContainsString( '24 מתוך 36', $html );
		$this->assertStringContainsString( Kivun_Holland::type_info()['S']['about'], $html );
		$this->assertSame( 6, substr_count( $html, 'kivun-hl-bars__row' ) );
		$this->assertDoesNotMatchRegularExpression( '/<p(\s(?![^>]*class=)[^>]*)?>/', $html );
	}
}
