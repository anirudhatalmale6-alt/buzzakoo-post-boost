<?php
/**
 * Paid boosts, through the site's existing WooCommerce checkout.
 *
 * The whole design rests on one rule: a boost is applied when WooCommerce says the money
 * has ARRIVED — never when the user clicks "Boost", and never when the order is merely
 * created. An order sitting in "pending" or "failed" has not paid for anything.
 *
 * Flow:
 *   click Boost -> add a boost package product to the cart, tagged with the item being
 *   boosted -> normal Woo checkout -> on payment_complete / processing / completed, the
 *   boost is applied for the package's duration -> on refund or cancel it is pulled again.
 *
 * The item being boosted rides along as cart item data, then as ORDER LINE ITEM meta, so
 * it survives the whole checkout (including gateways that bounce the user off-site and
 * back, and IPN callbacks that arrive with no session and no logged-in user).
 */

defined( 'ABSPATH' ) || exit;

class BZK_Woo {

	/** Order-item meta keys. Leading underscore keeps them hidden from the customer's order view. */
	const ITEM_TYPE     = '_bzk_type';
	const ITEM_ID       = '_bzk_id';
	const ITEM_DURATION = '_bzk_duration';
	const ITEM_APPLIED  = '_bzk_applied';

	/** Marks the WC product that represents a boost package. */
	const PRODUCT_FLAG = '_bzk_boost_package';

	public static function available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Are boosts charged for?
	 */
	public static function is_paid() {
		return self::available() && (bool) BZK_Settings::get( 'paid_boosts' );
	}

	public static function init() {
		if ( ! self::available() ) {
			return;
		}

		// Keep the package products in step with the settings.
		add_action( 'update_option_' . BZK_Settings::OPTION, array( __CLASS__, 'sync_products' ), 10, 0 );

		/*
		 * The order-lifecycle hooks are registered UNCONDITIONALLY — deliberately, and not
		 * behind is_paid().
		 *
		 * An order can be paid long after it was placed: bank transfer, cheque, a gateway
		 * IPN that arrives hours later. If the admin switches paid mode off in the meantime,
		 * a conditional registration would mean the customer's money lands and no boost is
		 * ever applied — we'd have taken payment and delivered nothing. These hooks only
		 * ever act on orders that actually carry boost line items, so leaving them on when
		 * paid mode is off costs nothing.
		 *
		 * Several apply hooks on purpose: payment_complete covers most gateways, but some
		 * never call it and simply move the order to processing/completed. apply_order() is
		 * idempotent, so overlapping hooks are harmless — the point is never to MISS a
		 * paid order.
		 */
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'apply_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'apply_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'apply_order' ) );

		// Money went back — so does the boost.
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'revoke_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'revoke_order' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'revoke_order' ) );

		if ( ! self::is_paid() ) {
			return;
		}

		// Carry the target item from the Boost click through to the order.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'capture_cart_item' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'show_cart_item' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 10, 4 );

		// One boost per item per cart, quantity always 1.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'prevent_duplicate' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_quantity', array( __CLASS__, 'force_single_quantity' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_quantity_input' ), 10, 3 );
	}

	/**
	 * Boosting the same item twice in one cart would charge for it twice and deliver one
	 * boost. Boosting two DIFFERENT items is fine and must keep working.
	 */
	public static function prevent_duplicate( $passed, $product_id, $quantity ) {
		if ( empty( $_GET['bzk_type'] ) || empty( $_GET['bzk_id'] ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $passed;
		}

		$key = sanitize_key( wp_unslash( $_GET['bzk_type'] ) ) . ':' . (int) $_GET['bzk_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['bzk_key'] ) && $cart_item['bzk_key'] === $key ) {
				wc_add_notice( __( 'That boost is already in your cart.', 'buzzakoo-boost' ), 'notice' );
				return false;
			}
		}

		return $passed;
	}

	/**
	 * A boost is a boost — you cannot buy "3 of it" for one post.
	 */
	public static function force_single_quantity( $quantity, $product_id ) {
		if ( ! empty( $_GET['bzk_type'] ) && ! empty( $_GET['bzk_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 1;
		}
		return $quantity;
	}

	/**
	 * No quantity spinner on a boost line in the cart.
	 */
	public static function lock_quantity_input( $html, $cart_item_key, $cart_item ) {
		if ( ! empty( $cart_item['bzk_key'] ) ) {
			return '1';
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Packages
	 * ------------------------------------------------------------------ */

	/**
	 * Boost packages as configured in the settings.
	 *
	 * @return array[] each: label, price, duration_hours, product_id
	 */
	public static function packages() {
		$packages = BZK_Settings::get( 'packages' );
		return is_array( $packages ) ? array_values( $packages ) : array();
	}

	public static function get_package( $index ) {
		$packages = self::packages();
		return isset( $packages[ $index ] ) ? $packages[ $index ] : null;
	}

	/**
	 * Create / update one hidden WooCommerce product per package, so checkout, tax,
	 * currency and order history all work exactly as they already do on the site.
	 */
	public static function sync_products() {
		if ( ! self::available() ) {
			return;
		}

		$settings = get_option( BZK_Settings::OPTION, array() );
		$packages = isset( $settings['packages'] ) && is_array( $settings['packages'] ) ? $settings['packages'] : array();
		$changed  = false;

		foreach ( $packages as $i => $package ) {
			$product_id = isset( $package['product_id'] ) ? (int) $package['product_id'] : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( ! $product ) {
				$product = new WC_Product_Simple();
				$changed = true;
			}

			$product->set_name( $package['label'] );
			$product->set_regular_price( (string) $package['price'] );
			$product->set_price( (string) $package['price'] );
			$product->set_virtual( true );

			/*
			 * NOT sold_individually. WooCommerce enforces that per PRODUCT, not per boosted
			 * item — so a seller boosting a second ad with the same package would be told
			 * "You cannot add another Boost to your cart". Uniqueness is enforced per ITEM
			 * instead, in prevent_duplicate() / force_single_quantity() below.
			 */
			$product->set_sold_individually( false );
			$product->set_catalog_visibility( 'hidden' ); // Never shows in the shop.
			$product->set_status( 'publish' );
			$product->update_meta_data( self::PRODUCT_FLAG, 1 );

			$new_id = $product->save();

			if ( (int) $new_id !== $product_id ) {
				$packages[ $i ]['product_id'] = (int) $new_id;
				$changed                      = true;
			}
		}

		if ( $changed ) {
			$settings['packages'] = $packages;
			// Direct write: we're already inside update_option, so don't recurse through sanitize().
			remove_action( 'update_option_' . BZK_Settings::OPTION, array( __CLASS__, 'sync_products' ) );
			update_option( BZK_Settings::OPTION, $settings );
			add_action( 'update_option_' . BZK_Settings::OPTION, array( __CLASS__, 'sync_products' ), 10, 0 );
		}
	}

	/**
	 * URL that starts the purchase for boosting a given item.
	 */
	public static function checkout_url( $object_type, $object_id, $package_index = 0 ) {
		$package = self::get_package( $package_index );
		if ( ! $package || empty( $package['product_id'] ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'add-to-cart' => (int) $package['product_id'],
				'bzk_type'    => rawurlencode( $object_type ),
				'bzk_id'      => (int) $object_id,
				'bzk_pkg'     => (int) $package_index,
			),
			wc_get_checkout_url()
		);
	}

	/* ---------------------------------------------------------------------
	 * Cart -> order
	 * ------------------------------------------------------------------ */

	public static function capture_cart_item( $cart_item_data, $product_id, $variation_id ) {
		if ( empty( $_GET['bzk_type'] ) || empty( $_GET['bzk_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $cart_item_data;
		}

		$type = sanitize_key( wp_unslash( $_GET['bzk_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id   = (int) $_GET['bzk_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pkg  = isset( $_GET['bzk_pkg'] ) ? (int) $_GET['bzk_pkg'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! BZK_Store::valid_type( $type ) || $id <= 0 ) {
			return $cart_item_data;
		}

		$package = self::get_package( $pkg );
		if ( ! $package ) {
			return $cart_item_data;
		}

		$cart_item_data['bzk_type']     = $type;
		$cart_item_data['bzk_id']       = $id;
		$cart_item_data['bzk_duration'] = (int) $package['duration_hours'];

		// Two boosts of DIFFERENT items must not merge into one cart line.
		$cart_item_data['bzk_key'] = $type . ':' . $id;

		return $cart_item_data;
	}

	/**
	 * Show the user what they're actually buying, in the cart and at checkout.
	 */
	public static function show_cart_item( $item_data, $cart_item ) {
		if ( empty( $cart_item['bzk_type'] ) || empty( $cart_item['bzk_id'] ) ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => __( 'Boosting', 'buzzakoo-boost' ),
			'value' => wp_strip_all_tags( BZK_Admin::item_label( $cart_item['bzk_type'], (int) $cart_item['bzk_id'] ) ),
		);

		return $item_data;
	}

	public static function save_order_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['bzk_type'] ) || empty( $values['bzk_id'] ) ) {
			return;
		}

		$item->add_meta_data( self::ITEM_TYPE, sanitize_key( $values['bzk_type'] ), true );
		$item->add_meta_data( self::ITEM_ID, (int) $values['bzk_id'], true );
		$item->add_meta_data( self::ITEM_DURATION, (int) $values['bzk_duration'], true );
	}

	/* ---------------------------------------------------------------------
	 * Payment -> boost
	 * ------------------------------------------------------------------ */

	/**
	 * Apply every boost paid for by this order.
	 *
	 * Idempotent: each line item is stamped once it has been applied, so the several
	 * hooks that call this (and any gateway that fires a callback twice) can't
	 * double-boost or double-extend.
	 */
	public static function apply_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Belt and braces: only ever act on an order that is genuinely paid for.
		if ( ! $order->is_paid() ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$type = $item->get_meta( self::ITEM_TYPE );
			$id   = (int) $item->get_meta( self::ITEM_ID );

			if ( ! $type || ! $id || ! BZK_Store::valid_type( $type ) ) {
				continue;
			}

			if ( $item->get_meta( self::ITEM_APPLIED ) ) {
				continue; // Already done.
			}

			$duration = (int) $item->get_meta( self::ITEM_DURATION );

			$result = BZK_Store::boost(
				$type,
				$id,
				$order->get_customer_id(),
				array(
					// They have paid. Cooldowns and role rules are for the FREE path;
					// refusing a boost someone just paid for would be theft.
					'bypass_rules'   => true,
					'duration_hours' => $duration,
				)
			);

			if ( is_wp_error( $result ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: item, 2: error message. */
						__( 'Post Boost: could not boost %1$s — %2$s', 'buzzakoo-boost' ),
						$type . ' #' . $id,
						$result->get_error_message()
					)
				);
				continue;
			}

			$item->add_meta_data( self::ITEM_APPLIED, 1, true );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: item, 2: hours. */
					__( 'Post Boost: boosted %1$s for %2$d hour(s).', 'buzzakoo-boost' ),
					$type . ' #' . $id,
					$duration
				)
			);
		}
	}

	/**
	 * Order refunded / cancelled / failed — take the boost back.
	 */
	public static function revoke_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item->get_meta( self::ITEM_APPLIED ) ) {
				continue;
			}

			$type = $item->get_meta( self::ITEM_TYPE );
			$id   = (int) $item->get_meta( self::ITEM_ID );

			if ( ! $type || ! $id ) {
				continue;
			}

			BZK_Store::unboost( $type, $id );

			$item->delete_meta_data( self::ITEM_APPLIED );
			$item->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: item. */
					__( 'Post Boost: boost removed for %s (order no longer paid).', 'buzzakoo-boost' ),
					$type . ' #' . $id
				)
			);
		}
	}
}
