<?php
/**
 * Cache invalidation.
 *
 * Two very different caches have to be dealt with after a boost:
 *
 *  1. BuddyPress's own query cache. BP caches the list of activity IDs keyed by the
 *     *SQL string*. Our boost ordering does not change that string when boost data
 *     changes, so without resetting BP's cache incrementor a boost would not show up
 *     until something else touched the activity table. This one is not optional.
 *
 *  2. Whatever full-page cache the host runs (Buzzakoo is on Hostinger, so LiteSpeed
 *     is likely). Best-effort: we call the public purge API of whichever plugin is
 *     present. Even if a page cache is missed, the Boost button re-reads its own state
 *     over REST on page load, so it never *displays* stale state.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Cache {

	/**
	 * Called after any change to boost state.
	 */
	public static function purge() {
		self::purge_buddypress();
		self::purge_wp_query();

		if ( ! BZK_Settings::get( 'purge_cache' ) ) {
			return;
		}

		self::purge_page_caches();

		/**
		 * Hook here to purge any cache we don't know about.
		 */
		do_action( 'bzk_purge_caches' );
	}

	/**
	 * Reset BuddyPress's activity query cache. Without this, a boost would not
	 * change the feed until BP's incrementor happened to move for another reason.
	 */
	private static function purge_buddypress() {
		if ( function_exists( 'bp_core_reset_incrementor' ) ) {
			bp_core_reset_incrementor( 'bp_activity' );
			bp_core_reset_incrementor( 'bp_activity_with_last_activity' );
		}

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			// Harmless no-op on object caches that don't implement groups.
			wp_cache_flush_group( 'bp_activity' );
		}
	}

	/**
	 * Invalidate cached WP_Query results.
	 *
	 * WordPress caches the post IDs a WP_Query returns, keyed by the query args plus the
	 * "last changed" stamp of the posts cache group. A boost writes only to our own table,
	 * so that stamp would never move and an identical archive query would keep serving the
	 * OLD order from cache — the boost would appear to do nothing. This is easy to miss on
	 * a dev box with no persistent object cache and very obvious on a live site with Redis.
	 *
	 * Bumping last_changed retires every cached post query at once.
	 */
	private static function purge_wp_query() {
		wp_cache_set_last_changed( 'posts' );
	}

	private static function purge_page_caches() {
		// LiteSpeed Cache (Hostinger's default).
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// SiteGround Optimizer.
		if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			do_action( 'siteground_optimizer_flush_cache' );
		}

		// Cache Enabler.
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
		}

		// Autoptimize (page cache layer).
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}

		// Hostinger's own cache plugin, if present.
		if ( has_action( 'hostinger_cache_purge_all' ) ) {
			do_action( 'hostinger_cache_purge_all' );
		}
	}

	/**
	 * Human-readable list of the cache plugins we can see (shown in settings).
	 */
	public static function detected_label() {
		$found = array();

		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$found[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$found[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$found[] = 'WP Super Cache';
		}
		if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			$found[] = 'SiteGround Optimizer';
		}
		if ( wp_using_ext_object_cache() ) {
			$found[] = 'persistent object cache';
		}

		return $found ? implode( ', ', $found ) : __( 'none detected', 'buzzakoo-boost' );
	}
}
