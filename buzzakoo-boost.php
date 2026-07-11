<?php
/**
 * Plugin Name:       Buzzakoo Post Boost
 * Plugin URI:        https://buzzakoo.com/
 * Description:       Lets users "boost" (bump) an item so it jumps back to the top of the public feeds — BuddyPress activity stream, WordPress post archives and bbPress topics. Boost state is stored in the database, so it survives page refreshes and cache flushes.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anirudha Talmale
 * License:           GPL-2.0-or-later
 * Text Domain:       buzzakoo-boost
 *
 * This file deliberately declares NO functions of its own — see includes/functions.php.
 *
 * @package buzzakoo-boost
 */

defined( 'ABSPATH' ) || exit;

/**
 * If a second copy of this plugin is installed (an extra download landing in a
 * differently-named folder), it must stand down rather than redeclare everything
 * and take the whole site down with a fatal. Because the guard returns before the
 * requires below, the duplicate copy loads no classes and no functions at all.
 */
if ( defined( 'BZK_BOOST_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>Buzzakoo Post Boost:</strong> '
				. esc_html__( 'Two copies of this plugin are installed. This copy is switched off and doing nothing — your boosts still work. Delete the duplicate under Plugins to tidy up.', 'buzzakoo-boost' )
				. '</p></div>';
		}
	);
	return;
}

define( 'BZK_BOOST_VERSION', '1.2.1' );
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
require_once BZK_BOOST_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'BZK_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BZK_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'bzk_boost_init', 20 );
