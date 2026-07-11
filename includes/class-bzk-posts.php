<?php
/**
 * WordPress posts / archives integration.
 *
 * Reordering happens in SQL (posts_clauses) rather than by shuffling the array of
 * posts afterwards, so pagination stays correct: a boosted post on "page 1" is really
 * first in the result set, not just moved to the top of a page that was already fetched.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Posts {

	public static function init() {
		if ( ! BZK_Settings::get( 'enable_posts' ) ) {
			return;
		}

		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 20, 2 );

		// Button inside the post content.
		add_filter( 'the_content', array( __CLASS__, 'append_button' ), 20 );

		add_shortcode( 'buzzakoo_boost', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Which queries get boost ordering.
	 */
	private static function applies_to( WP_Query $q ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		// Never reorder a single post, a feed of one, or a query that asked for a specific order.
		if ( $q->is_singular() || $q->is_admin ) {
			return false;
		}

		// Respect an explicit orderby — if the site asked for "title ASC", honour it.
		$orderby = $q->get( 'orderby' );
		if ( ! empty( $orderby ) && ! in_array( $orderby, array( 'date', 'post_date', '' ), true ) ) {
			// bbPress topic loops order by meta; those are handled by BZK_BBPress opting in below.
			if ( ! apply_filters( 'bzk_force_post_order', false, $q ) ) {
				return false;
			}
		}

		// Only the post types the client enabled.
		$types = (array) $q->get( 'post_type' );
		$types = array_filter( $types );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		$enabled = (array) BZK_Settings::get( 'post_types' );

		// bbPress topics are their own post type and are governed by their own setting,
		// not by the "post types" list.
		if ( BZK_Settings::get( 'enable_bbpress' ) && BZK_BBPress::available() ) {
			$enabled[] = bbp_get_topic_post_type();
		}

		if ( ! array_intersect( $types, $enabled ) ) {
			return false;
		}

		/**
		 * Opt a specific WP_Query in or out of boost ordering.
		 */
		return (bool) apply_filters( 'bzk_apply_to_post_query', true, $q );
	}

	/**
	 * Prepend the boost ranking to whatever ORDER BY the query already had, so the
	 * site's existing ordering (date, menu_order, meta, …) still applies underneath.
	 */
	public static function clauses( $clauses, $q ) {
		global $wpdb;

		if ( ! $q instanceof WP_Query || ! self::applies_to( $q ) ) {
			return $clauses;
		}

		$table  = BZK_Install::boosts_table();
		$active = BZK_Store::active_clause( 'bzk' );

		$clauses['join'] .= " LEFT JOIN {$table} bzk ON bzk.object_id = {$wpdb->posts}.ID AND bzk.object_type IN ('post','topic') AND {$active} ";

		/*
		 * A single column does the whole job: MySQL sorts NULLs last under DESC, so every
		 * boosted row (non-NULL boosted_at) lands ahead of every un-boosted one, and among
		 * boosted rows the most recent boost wins.
		 *
		 * Deliberately NOT "(boosted_at IS NOT NULL) DESC, boosted_at DESC" — that first
		 * term is an expression, and MySQL rejects ORDER BY on an expression that isn't in
		 * the select list whenever the query uses SELECT DISTINCT (error 3065). Taxonomy
		 * archives do use DISTINCT, so that form would fatal on exactly the category pages
		 * this feature is for.
		 */
		$boost_order = 'bzk.boosted_at DESC';

		$existing = isset( $clauses['orderby'] ) ? trim( $clauses['orderby'] ) : '';

		$clauses['orderby'] = $existing
			? $boost_order . ', ' . $existing
			: $boost_order . ", {$wpdb->posts}.post_date DESC";

		// Under DISTINCT the sorted column must also appear in the select list.
		if ( ! empty( $clauses['distinct'] ) && false !== stripos( $clauses['distinct'], 'DISTINCT' ) ) {
			$clauses['fields'] .= ', bzk.boosted_at AS bzk_boosted_at';
		}

		/*
		 * WordPress pins sticky posts to the front *after* the query runs, so by default
		 * sticky still outranks a boost. If the client wants a boost to beat sticky, the
		 * simplest correct move is to tell this query to stop treating sticky as special.
		 */
		if ( BZK_Settings::get( 'above_sticky' ) && ! $q->get( 'ignore_sticky_posts' ) ) {
			$q->set( 'ignore_sticky_posts', true );
		}

		return $clauses;
	}

	/**
	 * Add the Boost button to post content, per the position setting.
	 */
	public static function append_button( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$position = BZK_Settings::get( 'post_button_position' );
		if ( 'none' === $position ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, (array) BZK_Settings::get( 'post_types' ), true ) ) {
			return $content;
		}

		$button = BZK_UI::get_button( 'post', $post->ID );
		if ( ! $button ) {
			return $content;
		}

		return 'before' === $position ? $button . $content : $content . $button;
	}

	/**
	 * [buzzakoo_boost] or [buzzakoo_boost id="123" type="post"]
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'type' => 'post',
			),
			$atts,
			'buzzakoo_boost'
		);

		$id = (int) $atts['id'];
		if ( ! $id ) {
			$id = get_the_ID();
		}
		if ( ! $id ) {
			return '';
		}

		return BZK_UI::get_button( sanitize_key( $atts['type'] ), $id );
	}
}
