<?php
/**
 * BuddyPress activity stream integration — the main Buzzakoo feed.
 *
 * BuddyPress does not build the activity stream with WP_Query. It has its own table
 * (wp_bp_activity) and hand-built SQL, so reordering it means rewriting that SQL.
 *
 * The generated statement looks like this:
 *
 *   SELECT DISTINCT a.id FROM wp_bp_activity a {join} WHERE ... ORDER BY a.date_recorded DESC, a.id DESC LIMIT 0, 21
 *
 * Two things matter:
 *
 *  - It is SELECT DISTINCT. MySQL refuses to ORDER BY a column that is not in the
 *    select list when DISTINCT is used (error 3065), so the boost timestamp has to be
 *    added to the SELECT as an alias and the ORDER BY has to sort on that alias.
 *  - We only touch the query when it is sorted the default way (newest first). A query
 *    that explicitly asks for a different order is left completely alone.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Activity {

	/** Set for the duration of one BP query so the JOIN filter knows to fire. */
	private static $applies = false;

	public static function available() {
		return function_exists( 'bp_is_active' ) && bp_is_active( 'activity' );
	}

	public static function init() {
		if ( ! self::available() ) {
			return;
		}

		if ( BZK_Settings::get( 'enable_activity' ) ) {
			add_filter( 'bp_activity_get_join_sql', array( __CLASS__, 'join_sql' ), 10, 2 );
			add_filter( 'bp_activity_paged_activities_sql', array( __CLASS__, 'paged_sql' ), 10, 2 );
		}

		// Render the Boost button in the activity entry's action bar.
		add_action( 'bp_activity_entry_meta', array( __CLASS__, 'render_button' ) );
	}

	/**
	 * Should boost ordering apply to this particular BP query?
	 */
	private static function applies_to( $r ) {
		// Only the default "newest first" ordering. Anything else is intentional.
		$order_by = isset( $r['order_by'] ) ? $r['order_by'] : 'date_recorded';
		$sort     = isset( $r['sort'] ) ? strtoupper( $r['sort'] ) : 'DESC';

		if ( 'date_recorded' !== $order_by || 'DESC' !== $sort ) {
			return false;
		}

		// Single-item lookups and comment threads don't need reordering.
		if ( ! empty( $r['in'] ) || ! empty( $r['count_total_only'] ) ) {
			return false;
		}

		/**
		 * Opt a specific activity query in or out of boost ordering.
		 *
		 * @param bool  $applies
		 * @param array $r BP_Activity_Activity::get() args.
		 */
		return (bool) apply_filters( 'bzk_apply_to_activity_query', true, $r );
	}

	/**
	 * Add the LEFT JOIN onto the boost table.
	 *
	 * BP puts this fragment straight after "FROM wp_bp_activity a", which is exactly
	 * where a JOIN belongs.
	 */
	public static function join_sql( $join_sql, $r ) {
		self::$applies = self::applies_to( $r );

		if ( ! self::$applies ) {
			return $join_sql;
		}

		$table  = BZK_Install::boosts_table();
		$active = BZK_Store::active_clause( 'bzk' );

		return $join_sql . " LEFT JOIN {$table} bzk ON bzk.object_id = a.id AND bzk.object_type = 'activity' AND {$active} ";
	}

	/**
	 * Rewrite the SELECT and the ORDER BY so boosted activities come first.
	 */
	public static function paged_sql( $sql, $r ) {
		if ( ! self::$applies || false === strpos( $sql, 'bzk.' ) ) {
			// The JOIN didn't go in (someone else filtered it away) — don't touch ordering.
			return $sql;
		}

		/*
		 * SELECT DISTINCT a.id  ->  SELECT DISTINCT a.id, COALESCE(...) AS bzk_boosted_at
		 *
		 * $wpdb->get_col() reads column 0, so a.id must stay first. The extra column
		 * cannot duplicate rows: (object_type, object_id) is the boost table's primary key.
		 */
		$select_pattern = '/^\s*SELECT\s+DISTINCT\s+a\.id/i';
		if ( ! preg_match( $select_pattern, $sql ) ) {
			// BP changed its SELECT shape (or another plugin rewrote it) — bail out rather than
			// produce broken SQL. The feed keeps working, just without boost ordering.
			return $sql;
		}

		$sql = preg_replace(
			$select_pattern,
			"SELECT DISTINCT a.id, COALESCE(bzk.boosted_at, '1000-01-01 00:00:00') AS bzk_boosted_at",
			$sql,
			1
		);

		// ORDER BY a.date_recorded DESC -> ORDER BY bzk_boosted_at DESC, a.date_recorded DESC
		$sql = preg_replace(
			'/\bORDER BY\b/i',
			'ORDER BY bzk_boosted_at DESC,',
			$sql,
			1
		);

		return $sql;
	}

	/**
	 * Boost button inside the activity entry meta bar (next to Comment / Like).
	 */
	public static function render_button() {
		if ( ! BZK_Settings::get( 'enable_activity' ) || ! function_exists( 'bp_get_activity_id' ) ) {
			return;
		}

		$activity_id = (int) bp_get_activity_id();
		if ( ! $activity_id ) {
			return;
		}

		// Activity comments are not feed items; nothing to bump.
		if ( function_exists( 'bp_get_activity_type' ) && 'activity_comment' === bp_get_activity_type() ) {
			return;
		}

		echo BZK_UI::get_button( 'activity', $activity_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in BZK_UI.
	}
}
