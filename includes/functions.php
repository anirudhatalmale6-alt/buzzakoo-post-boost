<?php
/**
 * Public functions and the boot routine.
 *
 * These live in their own file, and not in the main plugin file, on purpose.
 * PHP binds top-level function declarations when a file is COMPILED, before a
 * single line of it runs — so an "am I a duplicate copy?" guard at the top of
 * the main file cannot stop functions further down that same file from being
 * declared. Keeping them here means the duplicate copy returns at its guard and
 * never requires this file, so nothing is ever redeclared.
 *
 * @package buzzakoo-boost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boot the plugin once all other plugins (BuddyPress, bbPress, WooCommerce) are
 * loaded, so we can reliably detect which integrations to switch on.
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

/**
 * Template tag — echo a boost button anywhere in a theme.
 *
 * Example: <?php if ( function_exists( 'bzk_boost_button' ) ) bzk_boost_button( 'post', get_the_ID() ); ?>
 *
 * @param string $object_type activity|post|topic.
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
 * @param string $object_type activity|post|topic.
 * @param int    $object_id   ID of the item.
 * @param int    $user_id     Optional user performing the boost.
 * @return true|WP_Error
 */
function bzk_boost( $object_type, $object_id, $user_id = 0 ) {
	return BZK_Store::boost( $object_type, (int) $object_id, (int) $user_id );
}
