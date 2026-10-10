<?php
/**
 * "Duplicate" action for courses, sessions and landing pages.
 *
 * Adds a Duplicate row action to the admin list of the content post types and
 * clones the post (content, excerpt, meta, taxonomies and featured image) as a
 * fresh draft. The copy is standalone — it is not linked to the original's
 * unified content group.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles duplicating Kivun content posts.
 */
class Kivun_Duplicate {

	/**
	 * Post types that can be duplicated.
	 *
	 * @return string[]
	 */
	private static function types(): array {
		return array( 'kivun_course', 'kivun_workshop', 'kivun_session' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_post_kivun_duplicate', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Add a "Duplicate" link to the row actions of the content post types.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    The post the row belongs to.
	 * @return array
	 */
	public static function row_action( $actions, $post ): array {
		if ( is_object( $post ) && in_array( $post->post_type, self::types(), true ) && current_user_can( 'edit_posts' ) ) {
			$url                        = wp_nonce_url(
				admin_url( 'admin-post.php?action=kivun_duplicate&post=' . (int) $post->ID ),
				'kivun_duplicate_' . (int) $post->ID
			);
			$actions['kivun_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'שכפל', 'kivun' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Create the duplicate and redirect to edit it.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'kivun_duplicate_' . $id );

		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) {
			wp_die( esc_html__( 'לא ניתן לשכפל פריט זה.', 'kivun' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לשכפל פריט זה.', 'kivun' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				/* translators: %s: original post title. */
				'post_title'   => sprintf( __( '%s (עותק)', 'kivun' ), $post->post_title ),
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		// Copy meta (skip editor-internal keys and the content-group link).
		$skip = array( '_edit_lock', '_edit_last', '_kivun_content_group' );
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $key, maybe_unserialize( wp_slash( $value ) ) );
			}
		}

		// Copy taxonomies.
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		// Copy the featured image.
		$thumb = get_post_thumbnail_id( $id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, (int) $thumb );
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
		exit;
	}
}
