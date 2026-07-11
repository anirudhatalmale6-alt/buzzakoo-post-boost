<?php
/**
 * Plugin Name:       Buzzakoo Post Boost
 * Plugin URI:        https://buzzakoo.com/
 * Description:       Lets users "boost" (bump) an item so it jumps back to the top of the public feeds — BuddyPress activity stream, WordPress post archives and bbPress topics. Boost state is stored in the database, so it survives page refreshes and cache flushes.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anirudha Talmale
 * License:           GPL-2.0-or-later
 * Text Domain:       buzzakoo-boost
 */

defined( 'ABSPATH' ) || exit;

define( 'BZK_BOOST_VERSION', '1.2.0' );
define( 'BZK_BOOST_FILE', __FILE__ );
define( 'BZK_BOOST_DIR', plugin_dir_path( __FILE__ ) );
define( 'BZK_BOOST_URL', plugin_dir_url( __FILE__ ) );

require_once BZK_BOOST_DIR . 'includes/class-bzk-install.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-settings.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-store.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-rules.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-cache.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-rest.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-ui.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-activity.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-posts.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-bbpress.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-woo.php';
require_once BZK_BOOST_DIR . 'includes/class-bzk-admin.php';

register_activation_hook( __FILE__, array( 'BZK_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BZK_Install', 'deactivate' ) );

/**
 * Boot the plugin once all other plugins (BuddyPress, bbPress) are loaded,
 * so we can reliably detect which integrations to switch on.
 */
function bzk_boost_init() {
	BZK_Install::maybe_upgrade();

	BZK_Rest::init();
	BZK_UI::init();
	BZK_Admin::init();
	BZK_Settings::init();

	// Feed integrations — each one no-ops if its host plugin is absent.
	BZK_Activity::init();
	BZK_Posts::init();
	BZK_BBPress::init();

	// Paid boosts through WooCommerce (no-ops if WooCommerce isn't active).
	BZK_Woo::init();

	// Housekeeping for expired boosts.
	add_action( 'bzk_boost_cleanup', array( 'BZK_Store', 'purge_expired' ) );
}
add_action( 'plugins_loaded', 'bzk_boost_init', 20 );

/**
 * Template tag — echo a boost button anywhere in a theme.
 *
 * Example: <?php if ( function_exists( 'bzk_boost_button' ) ) bzk_boost_button( 'post', get_the_ID() ); ?>
 *
 * @param string $object_type activity|post|topic
 * @param int    $object_id   ID of the item.
 */
function bzk_boost_button( $object_type = 'post', $object_id = 0 ) {
	if ( ! $object_id && 'post' === $object_type ) {
		$object_id = get_the_ID();
	}
	echo BZK_UI::get_button( $object_type, (int) $object_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped in BZK_UI.
}

/**
 * Programmatic boost. Returns true on success, WP_Error on failure.
 *
 * @param string $object_type activity|post|topic
 * @param int    $object_id   ID of the item.
 * @param int    $user_id     Optional user performing the boost.
 * @return true|WP_Error
 */
function bzk_boost( $object_type, $object_id, $user_id = 0 ) {
	return BZK_Store::boost( $object_type, (int) $object_id, (int) $user_id );
}
