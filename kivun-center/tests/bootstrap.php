<?php
/**
 * Test bootstrap: just enough WordPress for the plugin's own logic to run.
 *
 * The units under test are the ones that have actually gone wrong on this
 * site — date parsing, phone matching, the coordinator rota, filter scoping,
 * campaign links. None of them need a database or a WordPress install; they
 * need the handful of helpers WordPress would have provided. Those are stubbed
 * here, faithfully enough that a test failing means the plugin is wrong rather
 * than the stub is.
 *
 * @package Kivun
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'KIVUN_VERSION', 'test' );
define( 'KIVUN_DIR', dirname( __DIR__ ) . '/' );
define( 'KIVUN_URL', 'https://example.test/wp-content/plugins/kivun-center/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PHP_INT_MAX_SAFE', PHP_INT_MAX );

/**
 * Everything the stubs let a test set up and inspect.
 *
 * Reset between tests by Kivun_Test_State::reset(), so one test's options
 * cannot leak into the next.
 */
final class Kivun_Test_State {

	/**
	 * Stored options, keyed by name.
	 *
	 * @var array<string,mixed>
	 */
	public static array $options = array();

	/**
	 * Post meta, keyed by post id then meta key.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $meta = array();

	/**
	 * Posts, keyed by id: each an array of post fields.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $posts = array();

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<string,bool>
	 */
	public static array $caps = array();

	/**
	 * Mail accepted for delivery, newest last.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $mail = array();

	/**
	 * The site's timezone for the run.
	 */
	public static string $timezone = 'Asia/Jerusalem';

	/**
	 * Forget everything, so tests do not leak into one another.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$options  = array();
		self::$meta     = array();
		self::$posts    = array();
		self::$caps     = array();
		self::$mail     = array();
		self::$timezone = 'Asia/Jerusalem';
	}
}

// ── Escaping and sanitising ───────────────────────────────────────────────────

/**
 * Escape for HTML output.
 *
 * @param mixed $text The text.
 * @return string
 */
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape for an attribute.
 *
 * @param mixed $text The text.
 * @return string
 */
function esc_attr( $text ): string {
	return esc_html( $text );
}

/**
 * Escape a URL for output.
 *
 * @param mixed $url The URL.
 * @return string
 */
function esc_url( $url ): string {
	return esc_html( $url );
}

/**
 * Escape a URL for storage.
 *
 * @param mixed $url The URL.
 * @return string
 */
function esc_url_raw( $url ): string {
	return (string) $url;
}

/**
 * Escape textarea content.
 *
 * @param mixed $text The text.
 * @return string
 */
function esc_textarea( $text ): string {
	return esc_html( $text );
}

/**
 * Strip tags and line breaks from a single-line field.
 *
 * @param mixed $str The value.
 * @return string
 */
function sanitize_text_field( $str ): string {
	$str = (string) $str;
	$str = wp_strip_all_tags( $str );
	$str = str_replace( array( "\r", "\n", "\t" ), ' ', $str );

	// Core drops percent-encoded sequences here. Reproduced exactly, because a
	// bug this site actually hit depended on it.
	while ( preg_match( '/%[a-f0-9]{2}/i', $str, $match ) ) {
		$str = str_replace( $match[0], '', $str );
	}

	return trim( preg_replace( '/ +/', ' ', $str ) );
}

/**
 * Strip tags from a multi-line field, keeping line breaks.
 *
 * @param mixed $str The value.
 * @return string
 */
function sanitize_textarea_field( $str ): string {
	$str = wp_strip_all_tags( (string) $str );

	while ( preg_match( '/%[a-f0-9]{2}/i', $str, $match ) ) {
		$str = str_replace( $match[0], '', $str );
	}

	return trim( $str );
}

/**
 * Remove every tag from a string.
 *
 * @param string $text The text.
 * @return string
 */
function wp_strip_all_tags( string $text ): string {
	return trim( wp_kses_no_tags( $text ) );
}

/**
 * Strip tags, including the contents of script and style.
 *
 * @param string $text The text.
 * @return string
 */
function wp_kses_no_tags( string $text ): string {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	return (string) wp_strip_tags_only( (string) $text );
}

/**
 * Plain tag stripping.
 *
 * @param string $text The text.
 * @return string
 */
function wp_strip_tags_only( string $text ): string {
	return strip_tags( $text );
}

/**
 * Reduce a value to lowercase letters, digits, dashes and underscores.
 *
 * @param mixed $key The value.
 * @return string
 */
function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Clean an email address.
 *
 * @param mixed $email The value.
 * @return string
 */
function sanitize_email( $email ): string {
	return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}

/**
 * Clean a username.
 *
 * @param string $username The value.
 * @param bool   $strict   Whether to be strict.
 * @return string
 */
function sanitize_user( string $username, bool $strict = false ): string {
	unset( $strict );
	return preg_replace( '/[^a-zA-Z0-9 _.\-@]/', '', $username );
}

/**
 * Whether a value is an email address.
 *
 * @param mixed $email The value.
 * @return bool
 */
function is_email( $email ): bool {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

/**
 * Remove slashes added by the request layer.
 *
 * @param mixed $value The value.
 * @return mixed
 */
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * A non-negative integer.
 *
 * @param mixed $value The value.
 * @return int
 */
function absint( $value ): int {
	return abs( (int) $value );
}

// ── Translation ───────────────────────────────────────────────────────────────

/**
 * Translate a string.
 *
 * @param string $text   The text.
 * @param string $domain The text domain.
 * @return string
 */
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

/**
 * Translate and escape.
 *
 * @param string $text   The text.
 * @param string $domain The text domain.
 * @return string
 */
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( __( $text, $domain ) );
}

/**
 * Pick a singular or plural form.
 *
 * @param string $single The singular.
 * @param string $plural The plural.
 * @param int    $number The count.
 * @param string $domain The text domain.
 * @return string
 */
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	unset( $domain );
	return 1 === $number ? $single : $plural;
}

/**
 * Format a number for display.
 *
 * @param float|int $number   The number.
 * @param int       $decimals How many decimals.
 * @return string
 */
function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

/**
 * Apply filters — the stub returns the value unchanged unless a test hooked it.
 *
 * @param string $tag   The filter name.
 * @param mixed  $value The value.
 * @param mixed  ...$args Further arguments.
 * @return mixed
 */
function apply_filters( string $tag, $value, ...$args ) {
	global $kivun_test_filters;

	if ( empty( $kivun_test_filters[ $tag ] ) ) {
		return $value;
	}

	foreach ( $kivun_test_filters[ $tag ] as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

/**
 * Register a filter, for a test that needs one.
 *
 * @param string   $tag      The filter name.
 * @param callable $callback The callback.
 * @return void
 */
function add_filter( string $tag, callable $callback ): void {
	global $kivun_test_filters;
	$kivun_test_filters[ $tag ][] = $callback;
}

/**
 * Register an action. Nothing listens in the stubs.
 *
 * @param mixed ...$args Ignored.
 * @return void
 */
function add_action( ...$args ): void {
	unset( $args );
}

/**
 * Fire an action. Nothing listens in the stubs.
 *
 * @param mixed ...$args Ignored.
 * @return void
 */
function do_action( ...$args ): void {
	unset( $args );
}

// ── Options, posts and meta ───────────────────────────────────────────────────

/**
 * Read an option.
 *
 * @param string $name    The option name.
 * @param mixed  $default_value What to return when it is not set.
 * @return mixed
 */
function get_option( string $name, $default_value = false ) {
	return Kivun_Test_State::$options[ $name ] ?? $default_value;
}

/**
 * Write an option.
 *
 * @param string $name     The option name.
 * @param mixed  $value    The value.
 * @param bool   $autoload Ignored.
 * @return bool
 */
function update_option( string $name, $value, bool $autoload = true ): bool {
	unset( $autoload );
	Kivun_Test_State::$options[ $name ] = $value;
	return true;
}

/**
 * Read post meta.
 *
 * @param int    $post_id The post.
 * @param string $key     The meta key.
 * @param bool   $single  Whether to return a single value.
 * @return mixed
 */
function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	$value = Kivun_Test_State::$meta[ $post_id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}

/**
 * Write post meta.
 *
 * @param int    $post_id The post.
 * @param string $key     The meta key.
 * @param mixed  $value   The value.
 * @return bool
 */
function update_post_meta( int $post_id, string $key, $value ): bool {
	Kivun_Test_State::$meta[ $post_id ][ $key ] = $value;
	return true;
}

/**
 * A post's type.
 *
 * @param int $post_id The post.
 * @return string|false
 */
function get_post_type( int $post_id = 0 ) {
	return Kivun_Test_State::$posts[ $post_id ]['post_type'] ?? false;
}

/**
 * A post's title.
 *
 * @param int $post_id The post.
 * @return string
 */
function get_the_title( int $post_id = 0 ): string {
	return (string) ( Kivun_Test_State::$posts[ $post_id ]['post_title'] ?? '' );
}

/**
 * A single post field.
 *
 * @param string $field   The field name.
 * @param int    $post_id The post.
 * @return string
 */
function get_post_field( string $field, int $post_id = 0 ): string {
	return (string) ( Kivun_Test_State::$posts[ $post_id ][ $field ] ?? '' );
}

/**
 * A post's permalink.
 *
 * @param int $post_id The post.
 * @return string
 */
function get_permalink( int $post_id = 0 ): string {
	return (string) ( Kivun_Test_State::$posts[ $post_id ]['permalink'] ?? '' );
}

/**
 * Resolve a URL to a post id, by the permalinks the test registered.
 *
 * @param string $url The URL.
 * @return int
 */
function url_to_postid( string $url ): int {
	$path = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

	foreach ( Kivun_Test_State::$posts as $id => $post ) {
		if ( empty( $post['permalink'] ) ) {
			continue;
		}
		if ( rtrim( (string) wp_parse_url( $post['permalink'], PHP_URL_PATH ), '/' ) === $path ) {
			return (int) $id;
		}
	}

	return 0;
}

/**
 * The archive link for a post type.
 *
 * @param string $post_type The post type.
 * @return string|false
 */
function get_post_type_archive_link( string $post_type ) {
	return Kivun_Test_State::$options[ 'archive_' . $post_type ] ?? false;
}

/**
 * Posts matching a query — only the arguments the plugin actually uses.
 *
 * @param array<string,mixed> $args The query.
 * @return array<int,mixed>
 */
function get_posts( array $args = array() ): array {
	$types  = (array) ( $args['post_type'] ?? array() );
	$key    = $args['meta_key'] ?? '';
	$value  = $args['meta_value'] ?? null;
	$fields = $args['fields'] ?? '';
	$found  = array();

	foreach ( Kivun_Test_State::$posts as $id => $post ) {
		if ( $types && ! in_array( $post['post_type'] ?? '', $types, true ) ) {
			continue;
		}
		if ( '' !== $key && ( Kivun_Test_State::$meta[ $id ][ $key ] ?? null ) !== $value ) {
			continue;
		}
		$found[] = 'ids' === $fields ? (int) $id : (object) array_merge( array( 'ID' => (int) $id ), $post );
	}

	return $found;
}

/**
 * Whether a string contains a shortcode.
 *
 * @param string $content The content.
 * @param string $tag     The shortcode tag.
 * @return bool
 */
function has_shortcode( string $content, string $tag ): bool {
	return (bool) preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', $content );
}

// ── Users and capabilities ────────────────────────────────────────────────────

/**
 * Whether the current user has a capability.
 *
 * @param string $capability The capability.
 * @return bool
 */
function current_user_can( string $capability ): bool {
	return ! empty( Kivun_Test_State::$caps[ $capability ] );
}

/**
 * Give the current user a set of capabilities, for one test.
 *
 * @param array<int,string> $caps The capabilities.
 * @return void
 */
function kivun_test_set_caps( array $caps ): void {
	Kivun_Test_State::$caps = array_fill_keys( $caps, true );
}

// ── Time ──────────────────────────────────────────────────────────────────────

/**
 * The site's timezone.
 *
 * @return DateTimeZone
 */
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( Kivun_Test_State::$timezone );
}

/**
 * Format a timestamp in the site's timezone.
 *
 * @param string   $format    The format.
 * @param int|null $timestamp The timestamp, or null for now.
 * @return string
 */
function wp_date( string $format, ?int $timestamp = null ): string {
	$when = new DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
	return $when->setTimezone( wp_timezone() )->format( $format );
}

/**
 * The current time, in the site's timezone.
 *
 * @param string $type 'mysql' or 'timestamp'.
 * @return string|int
 */
function current_time( string $type = 'mysql' ) {
	return 'timestamp' === $type ? time() : wp_date( 'Y-m-d H:i:s' );
}

// ── URLs ──────────────────────────────────────────────────────────────────────

/**
 * Parse a URL.
 *
 * @param string $url       The URL.
 * @param int    $component Which part, or -1 for all.
 * @return mixed
 */
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * The site's home URL.
 *
 * @param string $path A path to append.
 * @return string
 */
function home_url( string $path = '/' ): string {
	return 'https://example.test' . $path;
}

/**
 * Add query arguments to a URL.
 *
 * @param array<string,mixed> $args The arguments.
 * @param string              $url  The URL.
 * @return string
 */
function add_query_arg( array $args, string $url = '' ): string {
	$parts = wp_parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	$query = array_merge( $query, $args );
	$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'example.test' ) . ( $parts['path'] ?? '/' );

	return $base . ( $query ? '?' . http_build_query( $query ) : '' );
}

/**
 * Remove a trailing slash.
 *
 * @param string $string_value The value.
 * @return string
 */
function untrailingslashit( string $string_value ): string {
	return rtrim( $string_value, '/\\' );
}

/**
 * Add a trailing slash.
 *
 * @param string $string_value The value.
 * @return string
 */
function trailingslashit( string $string_value ): string {
	return untrailingslashit( $string_value ) . '/';
}

// ── Site information ──────────────────────────────────────────────────────────

/**
 * A piece of site information.
 *
 * @param string $show Which piece.
 * @return string
 */
function get_bloginfo( string $show = '' ): string {
	if ( 'language' === $show ) {
		return 'he-IL';
	}
	return (string) ( Kivun_Test_State::$options[ 'blog_' . $show ] ?? 'מרכז כיוון' );
}

/**
 * A theme modification.
 *
 * @param string $name          The setting.
 * @param mixed  $default_value The fallback.
 * @return mixed
 */
function get_theme_mod( string $name, $default_value = false ) {
	return Kivun_Test_State::$options[ 'theme_mod_' . $name ] ?? $default_value;
}

/**
 * An attachment's image URL.
 *
 * @param int    $attachment_id The attachment.
 * @param string $size          The size.
 * @return string|false
 */
function wp_get_attachment_image_url( int $attachment_id, string $size = 'thumbnail' ) {
	unset( $size );
	return Kivun_Test_State::$options[ 'attachment_' . $attachment_id ] ?? false;
}

/**
 * The site icon URL.
 *
 * @param int $size The size.
 * @return string
 */
function get_site_icon_url( int $size = 512 ): string {
	unset( $size );
	return (string) ( Kivun_Test_State::$options['site_icon'] ?? '' );
}

// ── Mail ──────────────────────────────────────────────────────────────────────

/**
 * Accept mail for delivery, recording it for the test to inspect.
 *
 * @param string|array      $to          Recipient.
 * @param string            $subject     Subject.
 * @param string            $message     Body.
 * @param string|array      $headers     Headers.
 * @param array<int,string> $attachments Attachments.
 * @return bool
 */
function wp_mail( $to, string $subject, string $message, $headers = '', array $attachments = array() ): bool {
	Kivun_Test_State::$mail[] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
	return true;
}

// ── Miscellaneous ─────────────────────────────────────────────────────────────

/**
 * A random integer.
 *
 * @param int $min Lowest.
 * @param int $max Highest.
 * @return int
 */
function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, $max ? $max : PHP_INT_MAX );
}

/**
 * JSON encoding.
 *
 * @param mixed $data    The data.
 * @param int   $options Flags.
 * @return string|false
 */
function wp_json_encode( $data, int $options = 0 ) {
	return json_encode( $data, $options | JSON_UNESCAPED_UNICODE );
}

/**
 * Whether a value is a WP_Error.
 *
 * @param mixed $thing The value.
 * @return bool
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * The minimal error object the plugin passes around.
 */
class WP_Error {

	/**
	 * The error code.
	 */
	private string $code;

	/**
	 * The message.
	 */
	private string $message;

	/**
	 * Build an error.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * The error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * The message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

// ── The plugin ────────────────────────────────────────────────────────────────

require_once KIVUN_DIR . 'admin/class-kivun-admin-settings.php';

foreach ( array(
	'class-kivun-coordinators',
	'class-kivun-utm',
	'class-kivun-mailer',
	'class-kivun-phones',
	'class-kivun-forms-router',
) as $kivun_test_class ) {
	require_once KIVUN_DIR . 'includes/' . $kivun_test_class . '.php';
}
