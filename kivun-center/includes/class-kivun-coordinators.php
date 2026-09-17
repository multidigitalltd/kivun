<?php
/**
 * Shares incoming candidates between the coordinators who handle them.
 *
 * Candidates are split by the gender they gave, and within each group by a
 * quota per coordinator — a half each, or a quarter and three quarters. The
 * split is kept honest over time rather than left to chance: each submission
 * goes to whoever is furthest behind their share, so a run of five never all
 * land on the same desk.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes a submission to one coordinator, fairly.
 */
class Kivun_Coordinators {

	/**
	 * Where the running balance between coordinators is kept.
	 */
	const STATE = 'kivun_coordinator_state';

	/**
	 * The gender groups, and the setting each one's roster is stored under.
	 *
	 * @return array<string,string>
	 */
	public static function groups(): array {
		return array(
			'male'   => 'coordinators_male',
			'female' => 'coordinators_female',
		);
	}

	/**
	 * Which group a submitted gender belongs to.
	 *
	 * The value comes from whatever the form offers, so it is matched on the
	 * words people actually pick rather than on an exact string.
	 *
	 * @param string $gender The submitted value.
	 * @return string 'male', 'female', or '' when it says neither.
	 */
	public static function group_of( string $gender ): string {
		$gender = trim( mb_strtolower( $gender ) );
		if ( '' === $gender ) {
			return '';
		}

		// Checked before the male words: "אישה" and "בת" contain none of them,
		// but a form may well offer "בן/בת" as one label.
		if ( preg_match( '/female|woman|girl|אישה|אשה|נקבה|בחורה|נערה|בת/u', $gender ) ) {
			return 'female';
		}
		if ( preg_match( '/male|man|boy|גבר|זכר|בחור|נער|בן/u', $gender ) ) {
			return 'male';
		}

		return '';
	}

	/**
	 * One group's roster: address => quota.
	 *
	 * Typed in settings as "address = share" per line, so a coordinator can be
	 * added or a share changed without a release. A line with no share counts
	 * as one.
	 *
	 * @param string $group 'male' or 'female'.
	 * @return array<string,int>
	 */
	public static function roster( string $group ): array {
		$key = self::groups()[ $group ] ?? '';
		if ( '' === $key ) {
			return array();
		}

		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) Kivun_Admin_Settings::get( $key, '' ) ) as $line ) {
			$record = self::parse_line( (string) $line );
			if ( $record ) {
				$out[ $record['email'] ] = $record;
			}
		}

		return $out;
	}

	/**
	 * Read one line of a roster.
	 *
	 * A coordinator is written as their address, and — for the sign-off on the
	 * letter the candidate receives — optionally their name and phone number,
	 * separated by commas. The share goes after an "=".
	 *
	 *     dasi@kivun.org.il = 3/4
	 *     דסי, dasi@kivun.org.il, 02-6456222 = 3/4
	 *
	 * The parts are told apart by what they look like rather than by their
	 * position, so the order they are typed in does not matter and the older
	 * address-only lines keep working untouched.
	 *
	 * @param string $line One line of the setting.
	 * @return array{email:string,weight:int,name:string,phone:string}|null
	 */
	private static function parse_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}

		$weight = 1;
		if ( str_contains( $line, '=' ) ) {
			list( $line, $share ) = array_map( 'trim', explode( '=', $line, 2 ) );

			// "1/4" is how the share was asked for, and how anyone would write
			// it; it means one part against the other lines' parts.
			$weight = preg_match( '#^(\d+)\s*/\s*(\d+)$#', $share, $m ) ? (int) $m[1] : (int) $share;
		}

		$record = array(
			'email'  => '',
			'weight' => $weight,
			'name'   => '',
			'phone'  => '',
		);

		foreach ( preg_split( '/[,|]/', $line ) as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}

			if ( '' === $record['email'] && is_email( $part ) ) {
				$record['email'] = sanitize_email( $part );
			} elseif ( '' === $record['phone'] && preg_match( '/^[\d\-+()\s]{7,}$/', $part ) ) {
				$record['phone'] = sanitize_text_field( $part );
			} elseif ( '' === $record['name'] ) {
				$record['name'] = sanitize_text_field( $part );
			}
		}

		return ( '' !== $record['email'] && $record['weight'] > 0 ) ? $record : null;
	}

	/**
	 * Choose the coordinator whose turn it is, and record that it was taken.
	 *
	 * Smooth weighted round-robin: every coordinator's credit grows by their
	 * quota, the one with the most credit is picked, and pays the total back.
	 * With quotas of one and three that deals out one, three, one, three —
	 * spread through the run rather than three in a row, and self-correcting
	 * if the roster changes halfway.
	 *
	 * @param string $group 'male' or 'female'.
	 * @return string The chosen address, or '' when the group has no roster.
	 */
	public static function next( string $group ): array {
		$roster = self::roster( $group );
		if ( ! $roster ) {
			return array();
		}

		$state  = get_option( self::STATE, array() );
		$state  = is_array( $state ) ? $state : array();
		$credit = isset( $state[ $group ] ) && is_array( $state[ $group ] ) ? $state[ $group ] : array();

		// Anyone no longer on the roster loses their credit; a newcomer starts
		// level rather than owed a backlog.
		$credit = array_intersect_key( $credit, $roster );

		$total  = array_sum( array_column( $roster, 'weight' ) );
		$chosen = '';
		$best   = null;

		foreach ( $roster as $email => $record ) {
			$credit[ $email ] = (int) ( $credit[ $email ] ?? 0 ) + $record['weight'];
			if ( null === $best || $credit[ $email ] > $best ) {
				$best   = $credit[ $email ];
				$chosen = $email;
			}
		}

		$credit[ $chosen ] -= $total;

		$state[ $group ] = $credit;
		update_option( self::STATE, $state, false );

		return $roster[ $chosen ];
	}

	/**
	 * The coordinator whose turn it is, given what the candidate said about
	 * gender — the one who will be sent the application and who signs the
	 * letter back.
	 *
	 * Taking a turn is recorded, so this is called once per submission and the
	 * answer passed around, never called again for the same one.
	 *
	 * @param string $gender The submitted gender value.
	 * @return array{email:string,weight:int,name:string,phone:string}|array{} Empty when there is no roster for them.
	 */
	public static function pick( string $gender ): array {
		$group = self::group_of( $gender );

		return '' !== $group ? self::next( $group ) : array();
	}

	/**
	 * The addresses a submission should reach, given what it said about gender.
	 *
	 * @param string $gender The submitted gender value.
	 * @return array<int,string>
	 */
	public static function recipients( string $gender ): array {
		$coordinator = self::pick( $gender );

		return $coordinator ? array( $coordinator['email'] ) : array();
	}
}
