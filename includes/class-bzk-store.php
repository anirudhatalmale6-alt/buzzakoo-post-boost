<?php
/**
 * All reads/writes of boost state.
 *
 * Every timestamp in these tables is UTC, and every comparison is done against
 * MySQL's UTC_TIMESTAMP(), so boost expiry does not drift with the site timezone.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Store {

	const TYPES = array( 'activity', 'post', 'topic' );

	public static function valid_type( $type ) {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Current UTC time with microseconds, e.g. "2026-07-11 05:15:01.123456".
	 *
	 * Second-resolution timestamps tie when two boosts land in the same second, which
	 * makes "most recently boosted wins" unreliable on a busy feed.
	 */
	public static function now_utc() {
		$mt   = microtime( true );
		$sec  = (int) $mt;
		$usec = (int) round( ( $mt - $sec ) * 1000000 );

		// Rounding can carry into the next second.
		if ( $usec > 999999 ) {
			$usec = 0;
			++$sec;
		}

		return gmdate( 'Y-m-d H:i:s', $sec ) . '.' . str_pad( (string) $usec, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse a stored UTC datetime (with or without a fractional part) to a unix timestamp.
	 * strtotime() is unreliable with fractional seconds, so the fraction is dropped first.
	 */
	public static function to_timestamp( $mysql_datetime ) {
		if ( ! $mysql_datetime ) {
			return 0;
		}
		return (int) strtotime( substr( (string) $mysql_datetime, 0, 19 ) . ' UTC' );
	}

	/**
	 * SQL fragment matching a boost row that is still live (not expired).
	 * Used by every feed integration so "is boosted" means the same thing everywhere.
	 */
	public static function active_clause( $alias = 'bzk' ) {
		return "({$alias}.expires_at IS NULL OR {$alias}.expires_at > UTC_TIMESTAMP())";
	}

	/**
	 * Boost an item. Enforces every rule in the settings panel.
	 *
	 * @param array $args {
	 *     @type bool $bypass_rules   Skip permission/cooldown/cap checks. Used by the PAID
	 *                                path only: those rules gate the free boost, and refusing
	 *                                a boost somebody has just paid for would be theft.
	 *     @type int  $duration_hours Override the configured boost duration (paid packages
	 *                                each carry their own).
	 * }
	 * @return true|WP_Error
	 */
	public static function boost( $object_type, $object_id, $user_id = 0, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'bypass_rules'   => false,
				'duration_hours' => null,
			)
		);

		if ( ! self::valid_type( $object_type ) ) {
			return new WP_Error( 'bzk_bad_type', __( 'Unknown item type.', 'buzzakoo-boost' ) );
		}
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return new WP_Error( 'bzk_bad_id', __( 'Unknown item.', 'buzzakoo-boost' ) );
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( $args['bypass_rules'] ) {
			// Even on the paid path, refuse to boost something that no longer exists.
			if ( ! BZK_Rules::object_exists( $object_type, $object_id ) ) {
				return new WP_Error( 'bzk_missing', __( 'That item no longer exists.', 'buzzakoo-boost' ) );
			}
		} else {
			$allowed = BZK_Rules::can_boost( $object_type, $object_id, $user_id );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$duration = null === $args['duration_hours']
			? (int) BZK_Settings::get( 'boost_duration_hours' )
			: (int) $args['duration_hours'];

		$now     = self::now_utc();
		$expires = $duration > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $duration * HOUR_IN_SECONDS ) ) : null;

		$table = BZK_Install::boosts_table();

		/*
		 * Single statement, so two people hitting Boost at the same moment can't both
		 * insert and duplicate the row — the PK collides and we just bump the counter.
		 *
		 * expires_at must be a real NULL for "never expires"; $wpdb->prepare() would
		 * turn a PHP null into an empty string, so that case gets its own statement
		 * with a NULL literal rather than a placeholder.
		 */
		if ( null === $expires ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (object_type, object_id, boosted_at, expires_at, boost_count, last_user_id)
				 VALUES (%s, %d, %s, NULL, 1, %d)
				 ON DUPLICATE KEY UPDATE
				   boosted_at = VALUES(boosted_at),
				   expires_at = VALUES(expires_at),
				   boost_count = boost_count + 1,
				   last_user_id = VALUES(last_user_id)",
				$object_type,
				$object_id,
				$now,
				$user_id
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		} else {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (object_type, object_id, boosted_at, expires_at, boost_count, last_user_id)
				 VALUES (%s, %d, %s, %s, 1, %d)
				 ON DUPLICATE KEY UPDATE
				   boosted_at = VALUES(boosted_at),
				   expires_at = VALUES(expires_at),
				   boost_count = boost_count + 1,
				   last_user_id = VALUES(last_user_id)",
				$object_type,
				$object_id,
				$now,
				$expires,
				$user_id
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( false === $result ) {
			return new WP_Error( 'bzk_db', __( 'Could not save the boost.', 'buzzakoo-boost' ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			BZK_Install::log_table(),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $user_id,
				'boosted_at'  => $now,
				'ip'          => BZK_Rules::client_ip(),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		self::enforce_max_boosted( $object_type );

		BZK_Cache::purge();

		/**
		 * Fires after an item has been successfully boosted.
		 */
		do_action( 'bzk_boosted', $object_type, $object_id, $user_id );

		return true;
	}

	/**
	 * Remove a boost (admin action). The item drops back to its natural position.
	 */
	public static function unboost( $object_type, $object_id ) {
		global $wpdb;

		if ( ! self::valid_type( $object_type ) ) {
			return new WP_Error( 'bzk_bad_type', __( 'Unknown item type.', 'buzzakoo-boost' ) );
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			BZK_Install::boosts_table(),
			array(
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
			),
			array( '%s', '%d' )
		);

		BZK_Cache::purge();
		do_action( 'bzk_unboosted', $object_type, (int) $object_id );

		return true;
	}

	/**
	 * If "maximum boosted items at once" is set, keep only the newest N per type.
	 */
	private static function enforce_max_boosted( $object_type ) {
		global $wpdb;

		$max = (int) BZK_Settings::get( 'max_boosted_items' );
		if ( $max <= 0 ) {
			return;
		}

		$table = BZK_Install::boosts_table();
		$clause = self::active_clause( 'b' );

		// Grab the IDs that should survive, then delete every other live boost of this type.
		$keep = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT b.object_id FROM {$table} b
				 WHERE b.object_type = %s AND {$clause}
				 ORDER BY b.boosted_at DESC, b.object_id DESC
				 LIMIT %d",
				$object_type,
				$max
			)
		);

		if ( empty( $keep ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%d' ) );
		$params       = array_merge( array( $object_type ), array_map( 'intval', $keep ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE object_type = %s AND object_id NOT IN ({$placeholders})",
				$params
			)
		);
	}

	/**
	 * Current live boost row for an item, or null.
	 */
	public static function get_boost( $object_type, $object_id ) {
		global $wpdb;

		$table  = BZK_Install::boosts_table();
		$clause = self::active_clause( 'b' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT b.* FROM {$table} b WHERE b.object_type = %s AND b.object_id = %d AND {$clause}",
				$object_type,
				(int) $object_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Lifetime boost count for an item (survives expiry — used for max_boosts_per_item).
	 */
	public static function lifetime_count( $object_type, $object_id ) {
		global $wpdb;
		$log = BZK_Install::log_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log} WHERE object_type = %s AND object_id = %d",
				$object_type,
				(int) $object_id
			)
		);
	}

	/**
	 * Timestamp (UTC, unix) of the last boost of this item, or 0.
	 */
	public static function last_boost_time( $object_type, $object_id ) {
		global $wpdb;
		$log = BZK_Install::log_table();

		$when = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT boosted_at FROM {$log} WHERE object_type = %s AND object_id = %d ORDER BY boosted_at DESC LIMIT 1",
				$object_type,
				(int) $object_id
			)
		);

		return self::to_timestamp( $when );
	}

	/**
	 * Timestamp (UTC, unix) of this user's / IP's last boost of anything, or 0.
	 */
	public static function last_boost_time_by_user( $user_id, $ip = '' ) {
		global $wpdb;
		$log = BZK_Install::log_table();

		if ( $user_id > 0 ) {
			$when = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare( "SELECT boosted_at FROM {$log} WHERE user_id = %d ORDER BY boosted_at DESC LIMIT 1", $user_id )
			);
		} elseif ( $ip ) {
			$when = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare( "SELECT boosted_at FROM {$log} WHERE user_id = 0 AND ip = %s ORDER BY boosted_at DESC LIMIT 1", $ip )
			);
		} else {
			return 0;
		}

		return self::to_timestamp( $when );
	}

	/**
	 * All currently boosted items, newest boost first (admin screen).
	 */
	public static function list_boosted( $object_type = '', $limit = 200 ) {
		global $wpdb;

		$table  = BZK_Install::boosts_table();
		$clause = self::active_clause( 'b' );

		if ( $object_type && self::valid_type( $object_type ) ) {
			return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT b.* FROM {$table} b WHERE b.object_type = %s AND {$clause} ORDER BY b.boosted_at DESC LIMIT %d",
					$object_type,
					(int) $limit
				)
			);
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT b.* FROM {$table} b WHERE {$clause} ORDER BY b.boosted_at DESC LIMIT %d",
				(int) $limit
			)
		);
	}

	/**
	 * Hourly cron: drop rows whose boost window has closed.
	 *
	 * Ordering already ignores expired rows, so this is pure housekeeping —
	 * correctness does not depend on the cron ever running.
	 */
	public static function purge_expired() {
		global $wpdb;
		$table = BZK_Install::boosts_table();

		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( $deleted ) {
			BZK_Cache::purge();
		}
	}
}
