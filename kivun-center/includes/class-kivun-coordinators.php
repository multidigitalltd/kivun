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
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$weight = 1;
			if ( str_contains( $line, '=' ) ) {
				list( $line, $weight ) = array_map( 'trim', explode( '=', $line, 2 ) );

				// "1/4" is how the share was asked for, and how anyone would
				// write it; it means one part against the other lines' parts.
				if ( preg_match( '#^(\d+)\s*/\s*(\d+)$#', (string) $weight, $m ) ) {
					$weight = (int) $m[1];
				} else {
					$weight = (int) $weight;
				}
			}

			$email = sanitize_email( $line );
			if ( is_email( $email ) && $weight > 0 ) {
				$out[ $email ] = (int) $weight;
			}
		}

		return $out;
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
	public static function next( string $group ): string {
		$roster = self::roster( $group );
		if ( ! $roster ) {
			return '';
		}

		$state  = get_option( self::STATE, array() );
		$state  = is_array( $state ) ? $state : array();
		$credit = isset( $state[ $group ] ) && is_array( $state[ $group ] ) ? $state[ $group ] : array();

		// Anyone no longer on the roster loses their credit; a newcomer starts
		// level rather than owed a backlog.
		$credit = array_intersect_key( $credit, $roster );

		$total  = array_sum( $roster );
		$chosen = '';
		$best   = null;

		foreach ( $roster as $email => $weight ) {
			$credit[ $email ] = (int) ( $credit[ $email ] ?? 0 ) + $weight;
			if ( null === $best || $credit[ $email ] > $best ) {
				$best   = $credit[ $email ];
				$chosen = $email;
			}
		}

		$credit[ $chosen ] -= $total;

		$state[ $group ] = $credit;
		update_option( self::STATE, $state, false );

		return $chosen;
	}

	/**
	 * The addresses a submission should reach, given what it said about gender.
	 *
	 * @param string $gender The submitted gender value.
	 * @return array<int,string>
	 */
	public static function recipients( string $gender ): array {
		$group = self::group_of( $gender );
		if ( '' === $group ) {
			return array();
		}

		$email = self::next( $group );

		return '' !== $email ? array( $email ) : array();
	}
}
