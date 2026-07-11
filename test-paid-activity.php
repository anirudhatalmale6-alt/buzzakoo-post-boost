<?php
/**
 * Buzzakoo's REAL case: a business member pays to boost their own ACTIVITY post
 * (an "ad") to the top of the main feed. Pay-per-boost.
 */

global $wpdb;
$wpdb->query( "DELETE FROM wp_bzk_boosts" );
$wpdb->query( "DELETE FROM wp_bzk_boost_log" );
wp_cache_flush();

function t( $label, $cond = null ) {
	static $pass = 0, $fail = 0;
	if ( null === $cond ) { return array( $pass, $fail ); }
	if ( $cond ) { echo "  PASS  $label\n"; $pass++; } else { echo "  FAIL  $label\n"; $fail++; }
}

echo "\n=== Paid boost of an ACTIVITY post (a Buzzakoo 'ad') ===\n";

// A business member, like raoscaffolding.
$biz = get_user_by( 'login', 'raoscaffolding' );
if ( ! $biz ) {
	$biz = get_userdata( wp_insert_user( array( 'user_login' => 'raoscaffolding', 'user_pass' => 'x', 'role' => 'subscriber' ) ) );
}
$rando = get_user_by( 'login', 'subby' );

// Their ad, posted a while back so it has sunk down the feed.
$ad = bp_activity_add( array(
	'user_id'       => $biz->ID,
	'component'     => 'activity',
	'type'          => 'activity_update',
	'content'       => 'Scaffolding hire — Montgomery. Call us today!',
	'recorded_time' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ),
) );
echo "  (ad = activity #$ad, posted 10 days ago)\n";

// Pay-per-boost: one package, $5 for 24h.
$s = get_option( 'bzk_boost_settings' );
$s['enable_activity']  = 1;
$s['paid_boosts']      = 1;
$s['paid_author_only'] = 1;
$s['packages']         = array(
	array( 'label' => 'Boost — 24 hours', 'price' => '5', 'duration_hours' => 24, 'product_id' => 0 ),
);
update_option( 'bzk_boost_settings', $s );
BZK_Woo::sync_products();
$pkg     = BZK_Woo::get_package( 0 );
$product = wc_get_product( $pkg['product_id'] );

$feed = function () {
	$f = bp_activity_get( array( 'per_page' => 10, 'page' => 1 ) );
	return wp_list_pluck( $f['activities'], 'id' );
};

$before = $feed();
t( 'ad starts BELOW the top of the feed', ! empty( $before ) && (int) $before[0] !== (int) $ad );

// The business owner clicks Boost on their own ad.
wp_set_current_user( $biz->ID );
$r = BZK_Store::boost( 'activity', $ad );
t( 'clicking Boost asks for payment (does not boost)', is_wp_error( $r ) && 'bzk_payment_required' === $r->get_error_code() );

$url = is_wp_error( $r ) ? ( $r->get_error_data()['checkout_url'] ?? '' ) : '';
t( 'checkout URL targets this activity', false !== strpos( $url, 'bzk_type=activity' ) && false !== strpos( $url, 'bzk_id=' . $ad ) );

wp_cache_flush();
$mid = $feed();
t( 'feed unchanged until the money lands', $mid === $before );

// Someone else must not be able to buy a boost for this business's ad.
if ( $rando ) {
	wp_set_current_user( $rando->ID );
	$r2 = BZK_Store::boost( 'activity', $ad );
	t( "another member cannot buy a boost for someone else's ad", is_wp_error( $r2 ) && 'bzk_not_yours' === $r2->get_error_code() );
}

// The purchase, exactly as checkout would build it.
wp_set_current_user( $biz->ID );
$order = wc_create_order( array( 'customer_id' => $biz->ID ) );
$item  = new WC_Order_Item_Product();
$item->set_product( $product );
$item->set_quantity( 1 );
$item->add_meta_data( BZK_Woo::ITEM_TYPE, 'activity', true );
$item->add_meta_data( BZK_Woo::ITEM_ID, $ad, true );
$item->add_meta_data( BZK_Woo::ITEM_DURATION, 24, true );
$order->add_item( $item );
$order->calculate_totals();
$order->save();

$order->update_status( 'pending' );
wp_cache_flush();
t( 'unpaid order does not move the ad', $feed() === $before );

// Money lands.
$order->update_status( 'processing' );
wp_cache_flush();
$after = $feed();
t( 'PAID: ad jumps to the TOP of the main feed', ! empty( $after ) && (int) $after[0] === (int) $ad );

$b = BZK_Store::get_boost( 'activity', $ad );
if ( $b ) {
	$hours = ( BZK_Store::to_timestamp( $b->expires_at ) - time() ) / 3600;
	t( 'boost lasts the 24h they paid for', $hours > 23 && $hours <= 24.1 );
}

// Cart/checkout must show WHICH ad is being boosted.
$label = BZK_Admin::item_label( 'activity', $ad );
t( 'checkout shows the ad content, not just an ID', false !== stripos( $label, 'Scaffolding' ) );

// Expiry -> back to natural position, feed order intact.
$wpdb->query( "UPDATE wp_bzk_boosts SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE" );
bp_core_reset_incrementor( 'bp_activity' );
bp_core_reset_incrementor( 'bp_activity_with_last_activity' );
wp_cache_flush();
t( 'when the 24h is up the ad drops back down', $feed() === $before );

// Pay again -> boost again (pay-per-boost, repeatable).
$wpdb->query( "DELETE FROM wp_bzk_boosts" );
$order2 = wc_create_order( array( 'customer_id' => $biz->ID ) );
$item2  = new WC_Order_Item_Product();
$item2->set_product( $product );
$item2->set_quantity( 1 );
$item2->add_meta_data( BZK_Woo::ITEM_TYPE, 'activity', true );
$item2->add_meta_data( BZK_Woo::ITEM_ID, $ad, true );
$item2->add_meta_data( BZK_Woo::ITEM_DURATION, 24, true );
$order2->add_item( $item2 );
$order2->calculate_totals();
$order2->save();
$order2->update_status( 'processing' );
wp_cache_flush();
$again = $feed();
t( 'they can pay again later to re-boost (pay-per-boost)', ! empty( $again ) && (int) $again[0] === (int) $ad );

// Refund pulls it.
$order2->update_status( 'refunded' );
wp_cache_flush();
t( 'refund removes the boost again', $feed() === $before );

$s['paid_boosts'] = 0;
update_option( 'bzk_boost_settings', $s );

list( $p, $f ) = t( '' );
echo "\n  PASSED: $p   FAILED: $f\n";
