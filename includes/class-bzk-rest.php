<?php
/**
 * REST API.
 *
 *   GET  /wp-json/buzzakoo-boost/v1/state?items=activity:12,post:34
 *   POST /wp-json/buzzakoo-boost/v1/boost    { type, id }
 *   POST /wp-json/buzzakoo-boost/v1/unboost  { type, id }   (admins)
 *
 * The state endpoint hands back a freshly minted REST nonce. That matters on a cached
 * site: the nonce embedded in a cached HTML page goes stale, and any write using it
 * would be rejected with a 403. The button therefore always writes with the nonce it
 * just got from /state, never the one baked into the page.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Rest {

	const NS = 'buzzakoo-boost/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_state' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'items' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/boost',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'do_boost' ),
				'permission_callback' => '__return_true', // Real rules live in BZK_Rules::can_boost().
				'args'                => self::item_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/unboost',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'do_unboost' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => self::item_args(),
			)
		);
	}

	private static function item_args() {
		return array(
			'type' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => array( 'BZK_Store', 'valid_type' ),
			),
			'id'   => array(
				'required' => true,
				'type'     => 'integer',
			),
		);
	}

	/**
	 * Live state for every boost button on the page, in one request.
	 */
	public static function get_state( WP_REST_Request $request ) {
		$raw   = (string) $request->get_param( 'items' );
		$items = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		// Keep the response bounded no matter what a client sends.
		$items = array_slice( array_unique( $items ), 0, 100 );

		$out = array();

		foreach ( $items as $item ) {
			$parts = explode( ':', $item );
			if ( count( $parts ) !== 2 ) {
				continue;
			}

			$type = sanitize_key( $parts[0] );
			$id   = (int) $parts[1];

			if ( ! BZK_Store::valid_type( $type ) || $id <= 0 ) {
				continue;
			}

			$out[ $type . ':' . $id ] = self::state_for( $type, $id );
		}

		$response = rest_ensure_response(
			array(
				'items' => $out,
				// Fresh nonce — see the note at the top of this file.
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	private static function state_for( $type, $id ) {
		$boost   = BZK_Store::get_boost( $type, $id );
		$allowed = BZK_Rules::can_boost( $type, $id );

		$cooldown = BZK_Rules::item_cooldown_remaining( $type, $id );
		$user_cd  = BZK_Rules::user_cooldown_remaining( get_current_user_id() );

		$is_error = is_wp_error( $allowed );
		$code     = $is_error ? $allowed->get_error_code() : '';

		/*
		 * "Payment required" is not a refusal — it's a price tag. The button stays
		 * clickable and sends the user to checkout instead of boosting.
		 */
		$needs_payment = ( 'bzk_payment_required' === $code );

		$state = array(
			'boosted'         => (bool) $boost,
			'count'           => $boost ? (int) $boost->boost_count : BZK_Store::lifetime_count( $type, $id ),
			'expires_at'      => $boost && $boost->expires_at ? mysql_to_rfc3339( $boost->expires_at ) : null,
			'can_boost'       => ! $is_error || $needs_payment,
			'reason'          => $is_error && ! $needs_payment ? $allowed->get_error_message() : '',
			'retry_after'     => max( $cooldown, $user_cd ),
			'requires_payment' => $needs_payment,
			'checkout_url'    => '',
			'price_label'     => '',
		);

		if ( $needs_payment ) {
			$data                   = $allowed->get_error_data();
			$state['checkout_url']  = isset( $data['checkout_url'] ) ? $data['checkout_url'] : '';
			$state['price_label']   = self::price_label();

			// No package configured yet — nothing to sell, so don't offer a broken button.
			if ( ! $state['checkout_url'] ) {
				$state['can_boost'] = false;
				$state['reason']    = __( 'Boosting is not available right now.', 'buzzakoo-boost' );
			}
		}

		return $state;
	}

	/**
	 * Button text for the paid path, e.g. "Boost — $5.00".
	 */
	private static function price_label() {
		$package = BZK_Woo::get_package( 0 );
		if ( ! $package ) {
			return '';
		}

		/*
		 * wc_price() returns markup with the currency symbol as an HTML entity ("&#36;").
		 * The button writes its label with textContent, so the entity has to be decoded
		 * here or the user literally reads "Boost — &#36;5.00".
		 */
		$price = function_exists( 'wc_price' )
			? html_entity_decode( wp_strip_all_tags( wc_price( (float) $package['price'] ) ), ENT_QUOTES, 'UTF-8' )
			: (string) $package['price'];

		return sprintf(
			/* translators: 1: button label e.g. "Boost", 2: price. */
			__( '%1$s — %2$s', 'buzzakoo-boost' ),
			BZK_Settings::get( 'button_label' ),
			$price
		);
	}

	public static function do_boost( WP_REST_Request $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );

		$result = BZK_Store::boost( $type, $id );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			/*
			 * Paid mode: don't boost, and don't error either — hand back the checkout URL
			 * so the button can send them to pay. The boost gets applied later, by
			 * BZK_Woo, once WooCommerce confirms the money actually arrived.
			 */
			if ( 'bzk_payment_required' === $code ) {
				$data     = $result->get_error_data();
				$response = rest_ensure_response(
					array(
						'success'          => false,
						'requires_payment' => true,
						'checkout_url'     => isset( $data['checkout_url'] ) ? $data['checkout_url'] : '',
					)
				);
				$response->header( 'Cache-Control', 'no-store, max-age=0' );
				return $response;
			}

			$status = in_array( $code, array( 'bzk_forbidden', 'bzk_not_yours' ), true ) ? 403
				: ( in_array( $code, array( 'bzk_cooldown', 'bzk_user_cooldown', 'bzk_max_reached' ), true ) ? 429 : 400 );

			$result->add_data( array( 'status' => $status ), $code );
			return $result;
		}

		$response = rest_ensure_response(
			array(
				'success' => true,
				'state'   => self::state_for( $type, $id ),
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	public static function do_unboost( WP_REST_Request $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );

		$result = BZK_Store::unboost( $type, $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'state'   => self::state_for( $type, $id ),
			)
		);
	}
}
