<?php
/**
 * The picture a shared link shows.
 *
 * A page can be given an image that never appears on the page itself and is
 * seen only in the card WhatsApp, Facebook and the rest draw when somebody
 * pastes the link. That is an Open Graph image, and the whole feature is two
 * questions: which image, and who is allowed to say so.
 *
 * The second one matters more than it looks. If the site runs an SEO plugin,
 * that plugin already writes `og:image`, and writing a second tag does not
 * override it — it leaves two in the head and lets each network pick one.
 * So where a plugin we know is active, the image is handed to it through its
 * own filter and it stays the only voice; where none is, these tags are
 * written here. An SEO plugin nobody has taught us about would still get a
 * duplicate, so `kivun_share_image_output` turns ours off in one line.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the share image and makes sure exactly one tag names it.
 */
class Kivun_Share_Image {

	/**
	 * Post meta holding the chosen attachment.
	 */
	public const META = '_kivun_share_image';

	/**
	 * The content types a share image can be set on.
	 *
	 * @return array<int,string>
	 */
	public static function types(): array {
		return array( 'kivun_course', 'kivun_workshop', 'kivun_session', 'kivun_event' );
	}

	/**
	 * Hook the output and the handovers.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Yoast and Rank Math each take the image through a filter. Handing
		// it over leaves one tag, theirs, saying what we chose.
		add_filter( 'wpseo_opengraph_image', array( __CLASS__, 'filter_url' ) );
		add_filter( 'wpseo_twitter_image', array( __CLASS__, 'filter_url' ) );
		add_filter( 'rank_math/opengraph/facebook/image', array( __CLASS__, 'filter_url' ) );
		add_filter( 'rank_math/opengraph/twitter/image', array( __CLASS__, 'filter_url' ) );
		add_filter( 'seopress_social_og_thumb', array( __CLASS__, 'filter_url' ) );

		add_action( 'wp_head', array( __CLASS__, 'tags' ), 5 );
	}

	/**
	 * The attachment this post shares, or 0.
	 *
	 * Falls back to the featured image, because a page with a picture on it
	 * and none in its link card is a worse answer than reusing the one it
	 * already has.
	 *
	 * @param int $post_id The post.
	 * @return int
	 */
	public static function attachment( int $post_id ): int {
		$chosen = (int) get_post_meta( $post_id, self::META, true );

		return $chosen ? $chosen : (int) get_post_thumbnail_id( $post_id );
	}

	/**
	 * Its address.
	 *
	 * @param int $post_id The post.
	 * @return string Empty when the post has neither image.
	 */
	public static function url( int $post_id ): string {
		$id = self::attachment( $post_id );
		if ( ! $id ) {
			return '';
		}

		return (string) wp_get_attachment_image_url( $id, 'full' );
	}

	/**
	 * The post currently being viewed, if it is one of ours.
	 *
	 * @return int
	 */
	private static function current(): int {
		if ( ! is_singular( self::types() ) ) {
			return 0;
		}

		return (int) get_queried_object_id();
	}

	/**
	 * Hand the address to an SEO plugin that asked for it.
	 *
	 * Only where this page has one of its own: a page with nothing chosen
	 * should keep whatever the plugin worked out for itself rather than be
	 * handed an empty string.
	 *
	 * @param mixed $image Whatever the plugin had.
	 * @return mixed
	 */
	public static function filter_url( $image ) {
		$post_id = self::current();
		if ( ! $post_id ) {
			return $image;
		}

		$own = (int) get_post_meta( $post_id, self::META, true );
		if ( ! $own ) {
			return $image;
		}

		$url = (string) wp_get_attachment_image_url( $own, 'full' );

		return '' !== $url ? $url : $image;
	}

	/**
	 * Whether an SEO plugin we hand over to is running.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| class_exists( 'RankMath' );
	}

	/**
	 * Write the tags, where nobody else is writing them.
	 *
	 * @return void
	 */
	public static function tags(): void {
		/**
		 * Whether the plugin should write its own Open Graph tags.
		 *
		 * Returning false leaves the head to an SEO plugin this code does not
		 * recognise, which would otherwise end up with two `og:image` tags.
		 *
		 * @param bool $write Whether to write them.
		 */
		if ( ! apply_filters( 'kivun_share_image_output', ! self::seo_plugin_active() ) ) {
			return;
		}

		$post_id = self::current();
		if ( ! $post_id ) {
			return;
		}

		$url = self::url( $post_id );
		if ( '' === $url ) {
			return;
		}

		printf(
			"<meta property=\"og:image\" content=\"%s\">\n<meta name=\"twitter:image\" content=\"%s\">\n<meta name=\"twitter:card\" content=\"summary_large_image\">\n",
			esc_url( $url ),
			esc_url( $url )
		);
	}
}
