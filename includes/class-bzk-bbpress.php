<?php
/**
 * bbPress forum topic integration — the classic forum "bump".
 *
 * bbPress topic loops are ordinary WP_Query runs (ordered by the _bbp_last_active_time
 * meta key), so BZK_Posts::clauses() already does the reordering — it just has to be
 * told not to bail out when it sees a non-date "orderby". That's what the
 * bzk_force_post_order filter below is for.
 */

defined( 'ABSPATH' ) || exit;

class BZK_BBPress {

	public static function available() {
		return function_exists( 'bbp_get_topic_post_type' );
	}

	public static function init() {
		if ( ! self::available() || ! BZK_Settings::get( 'enable_bbpress' ) ) {
			return;
		}

		// Let topic loops keep their meta ordering, but rank boosted topics above it.
		add_filter( 'bzk_force_post_order', array( __CLASS__, 'force_order' ), 10, 2 );

		// bbPress topic loops are secondary queries; make sure the post type check passes.
		add_filter( 'bzk_apply_to_post_query', array( __CLASS__, 'apply_to_query' ), 10, 2 );

		// Button under the topic title in the topic list, and on the single topic page.
		add_action( 'bbp_theme_after_topic_title', array( __CLASS__, 'render_button' ) );
	}

	public static function force_order( $force, $q ) {
		return self::is_topic_query( $q ) ? true : $force;
	}

	public static function apply_to_query( $applies, $q ) {
		return self::is_topic_query( $q ) ? true : $applies;
	}

	private static function is_topic_query( $q ) {
		if ( ! $q instanceof WP_Query ) {
			return false;
		}
		$types = (array) $q->get( 'post_type' );
		return in_array( bbp_get_topic_post_type(), array_filter( $types ), true );
	}

	public static function render_button() {
		if ( ! function_exists( 'bbp_get_topic_id' ) ) {
			return;
		}
		$topic_id = (int) bbp_get_topic_id();
		if ( ! $topic_id ) {
			return;
		}
		echo BZK_UI::get_button( 'topic', $topic_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in BZK_UI.
	}
}
