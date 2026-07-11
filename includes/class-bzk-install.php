<?php
/**
 * Database schema + activation / upgrade routines.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Install {

	const DB_VERSION_OPTION = 'bzk_boost_db_version';
	const DB_VERSION        = '1.0.1';

	/**
	 * Current boost state, one row per boosted item.
	 */
	public static function boosts_table() {
		global $wpdb;
		return $wpdb->prefix . 'bzk_boosts';
	}

	/**
	 * Append-only history of boost events (drives cooldowns and per-item limits).
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'bzk_boost_log';
	}

	public static function activate() {
		self::create_tables();
		BZK_Settings::install_defaults();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		if ( ! wp_next_scheduled( 'bzk_boost_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bzk_boost_cleanup' );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'bzk_boost_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'bzk_boost_cleanup' );
		}
	}

	/**
	 * Runs on every load; cheap option check, only does work when the version moves.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		BZK_Settings::install_defaults();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$boosts  = self::boosts_table();
		$log     = self::log_table();

		/*
		 * object_type + object_id is the primary key: an item is either currently
		 * boosted (one row) or it is not. Ordering reads this table via a LEFT JOIN,
		 * so boosted_at / expires_at are indexed.
		 *
		 * boosted_at is datetime(6) — microseconds, not seconds. On a busy feed two
		 * people can easily boost within the same second, and at one-second resolution
		 * those boosts tie: the feed then falls back to date order and "most recently
		 * boosted wins" quietly stops being true. Microseconds keep boost order exact.
		 */
		$sql_boosts = "CREATE TABLE {$boosts} (
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			boosted_at datetime(6) NOT NULL,
			expires_at datetime DEFAULT NULL,
			boost_count int(10) unsigned NOT NULL DEFAULT 1,
			last_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (object_type,object_id),
			KEY boosted_at (boosted_at),
			KEY expires_at (expires_at),
			KEY type_boosted (object_type,boosted_at)
		) {$charset};";

		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			boosted_at datetime(6) NOT NULL,
			ip varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY object (object_type,object_id,boosted_at),
			KEY user_time (user_id,boosted_at)
		) {$charset};";

		dbDelta( $sql_boosts );
		dbDelta( $sql_log );
	}
}
