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

		return array(
			'boosted'     => (bool) $boost,
			'count'       => $boost ? (int) $boost->boost_count : BZK_Store::lifetime_count( $type, $id ),
			'expires_at'  => $boost && $boost->expires_at ? mysql_to_rfc3339( $boost->expires_at ) : null,
			'can_boost'   => ! is_wp_error( $allowed ),
			'reason'      => is_wp_error( $allowed ) ? $allowed->get_error_message() : '',
			'retry_after' => max( $cooldown, $user_cd ),
		);
	}

	public static function do_boost( WP_REST_Request $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) );
		$id   = (int) $request->get_param( 'id' );

		$result = BZK_Store::boost( $type, $id );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = in_array( $code, array( 'bzk_forbidden' ), true ) ? 403
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
