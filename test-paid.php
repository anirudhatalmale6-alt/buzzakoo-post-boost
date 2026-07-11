<?php
/**
 * Paid-boost money path. The rule under test: a boost must appear ONLY when the order
 * is genuinely paid, and must disappear again if the money goes back.
 *
 * Run with: wp eval-file test-paid.php
 */

global $wpdb;
$wpdb->query( "DELETE FROM wp_bzk_boosts" );
$wpdb->query( "DELETE FROM wp_bzk_boost_log" );
wp_cache_flush();

function ok( $label, $cond = null ) {
	static $pass = 0, $fail = 0;
	if ( null === $cond ) { return array( $pass, $fail ); }
	if ( $cond ) { echo "  PASS  $label\n"; $pass++; }
	else { echo "  FAIL  $label\n"; $fail++; }
}

// A seller and their ad.
$seller = get_user_by( 'login', 'seller' );
if ( ! $seller ) {
	$seller = get_userdata( wp_insert_user( array( 'user_login' => 'seller', 'user_pass' => 'x', 'role' => 'subscriber' ) ) );
}
// Deliberately OLD: if the ad were the newest post it would sit first anyway and
// "boosted renders first" would prove nothing.
$old   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
$ad_id = wp_insert_post( array(
	'post_title'    => 'Seller ad ' . wp_rand( 1000, 9999 ),
	'post_status'   => 'publish',
	'post_type'     => 'post',
	'post_author'   => $seller->ID,
	'post_content'  => 'ad',
	'post_date'     => $old,
	'post_date_gmt' => $old,
) );

$boosted = function () use ( $ad_id ) {
	return (bool) BZK_Store::get_boost( 'post', $ad_id );
};

echo "\n=== Paid boost: the money path ===\n";

// Turn on paid mode with a 48h / $7 package.
$s = get_option( 'bzk_boost_settings' );
$s['paid_boosts']      = 1;
$s['paid_author_only'] = 1;
$s['packages']         = array(
	array( 'label' => 'Boost — 48 hours', 'price' => '7', 'duration_hours' => 48, 'product_id' => 0 ),
);
update_option( 'bzk_boost_settings', $s );
BZK_Woo::sync_products();

$pkg = BZK_Woo::get_package( 0 );
ok( 'package synced to a WooCommerce product', ! empty( $pkg['product_id'] ) && wc_get_product( $pkg['product_id'] ) );

$product = wc_get_product( $pkg['product_id'] );
ok( 'boost product is hidden from the shop catalog', 'hidden' === $product->get_catalog_visibility() );
ok( 'boost product is virtual (no shipping)', $product->is_virtual() );

// --- the free path must now be CLOSED ---
wp_set_current_user( $seller->ID );
$r = BZK_Store::boost( 'post', $ad_id );
ok( 'free boost refused in paid mode', is_wp_error( $r ) && 'bzk_payment_required' === $r->get_error_code() );
ok( 'refusal carries a checkout URL', is_wp_error( $r ) && ! empty( $r->get_error_data()['checkout_url'] ) );
ok( 'nothing boosted just by clicking', ! $boosted() );

// --- someone else's ad ---
$other = get_user_by( 'login', 'subby' );
if ( $other ) {
	wp_set_current_user( $other->ID );
	$r2 = BZK_Store::boost( 'post', $ad_id );
	ok( "cannot buy a boost for someone else's ad", is_wp_error( $r2 ) && 'bzk_not_yours' === $r2->get_error_code() );
}
wp_set_current_user( $seller->ID );

// --- build an order the way checkout does ---
$order = wc_create_order( array( 'customer_id' => $seller->ID ) );
$item  = new WC_Order_Item_Product();
$item->set_product( $product );
$item->set_quantity( 1 );
$item->add_meta_data( BZK_Woo::ITEM_TYPE, 'post', true );
$item->add_meta_data( BZK_Woo::ITEM_ID, $ad_id, true );
$item->add_meta_data( BZK_Woo::ITEM_DURATION, 48, true );
$order->add_item( $item );
$order->calculate_totals();
$order->save();

// --- UNPAID: pending / failed must NOT boost ---
$order->update_status( 'pending' );
ok( 'PENDING order does not boost', ! $boosted() );

$order->update_status( 'failed' );
ok( 'FAILED order does not boost', ! $boosted() );

// --- PAID ---
$order->update_status( 'processing' );
wp_cache_flush();
$b = BZK_Store::get_boost( 'post', $ad_id );
ok( 'PAID order applies the boost', (bool) $b );

if ( $b ) {
	$hours = ( BZK_Store::to_timestamp( $b->expires_at ) - time() ) / 3600;
	ok( 'boost uses the PACKAGE duration (48h, not the default 24h)', $hours > 47 && $hours <= 48.1 );
}

// The boosted ad must actually be on top of the feed.
$q = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 5 ) );
ok( 'paid-boosted ad renders first', ! empty( $q->posts ) && (int) $q->posts[0]->ID === (int) $ad_id );

// --- idempotency: gateways fire callbacks more than once ---
$count_before = (int) BZK_Store::get_boost( 'post', $ad_id )->boost_count;
BZK_Woo::apply_order( $order->get_id() );
BZK_Woo::apply_order( $order->get_id() );
$order->update_status( 'completed' );
$count_after = (int) BZK_Store::get_boost( 'post', $ad_id )->boost_count;
ok( 'repeated payment callbacks do not double-boost', $count_before === $count_after );

// --- REFUND: money goes back, boost goes away ---
$order->update_status( 'refunded' );
wp_cache_flush();
ok( 'REFUNDED order removes the boost', ! $boosted() );

$q2 = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 5 ) );
ok( 'ad drops back out of first place after refund', empty( $q2->posts ) || (int) $q2->posts[0]->ID !== (int) $ad_id );

// --- admins still boost free ---
wp_set_current_user( 1 );
$r3 = BZK_Store::boost( 'post', $ad_id );
ok( 'admin boosts free even in paid mode', true === $r3 );

// reset
$s['paid_boosts'] = 0;
update_option( 'bzk_boost_settings', $s );

list( $pass, $fail ) = ok( '' );
echo "\n  PASSED: $pass   FAILED: $fail\n";
