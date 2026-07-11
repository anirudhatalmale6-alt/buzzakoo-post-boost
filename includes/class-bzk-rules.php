<?php
/**
 * Permission + rate-limit rules. One place decides whether a boost is allowed,
 * so the button, the REST endpoint and any programmatic call agree.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Rules {

	/**
	 * @return true|WP_Error true if $user_id may boost this item right now.
	 */
	public static function can_boost( $object_type, $object_id, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user_id = (int) $user_id;

		if ( ! self::type_enabled( $object_type ) ) {
			return new WP_Error( 'bzk_disabled', __( 'Boosting is switched off for this kind of item.', 'buzzakoo-boost' ) );
		}

		if ( ! self::object_exists( $object_type, $object_id ) ) {
			return new WP_Error( 'bzk_missing', __( 'That item no longer exists.', 'buzzakoo-boost' ) );
		}

		// Administrators bypass permission checks (but still see the UI state).
		$is_admin = $user_id && user_can( $user_id, 'manage_options' );

		if ( ! $is_admin && ! self::user_may_boost( $object_type, $object_id, $user_id ) ) {
			return new WP_Error(
				'bzk_forbidden',
				$user_id
					? __( 'You are not allowed to boost this.', 'buzzakoo-boost' )
					: __( 'Please log in to boost.', 'buzzakoo-boost' )
			);
		}

		/*
		 * Paid mode. Boosting is a purchase, so the free path is closed here and the caller
		 * is sent to checkout instead. Admins keep boosting free — they need to be able to
		 * pin things and fix mistakes without paying their own site.
		 */
		if ( ! $is_admin && BZK_Woo::is_paid() ) {
			// "Boost your own ad" — you may only buy a boost for something that is yours.
			if ( BZK_Settings::get( 'paid_author_only' ) && ! self::is_author( $object_type, $object_id, $user_id ) ) {
				return new WP_Error( 'bzk_not_yours', __( 'You can only boost your own posts.', 'buzzakoo-boost' ) );
			}

			return new WP_Error(
				'bzk_payment_required',
				__( 'Boosting this requires payment.', 'buzzakoo-boost' ),
				array( 'checkout_url' => BZK_Woo::checkout_url( $object_type, $object_id ) )
			);
		}

		// Lifetime cap for this item.
		$max = (int) BZK_Settings::get( 'max_boosts_per_item' );
		if ( $max > 0 && BZK_Store::lifetime_count( $object_type, $object_id ) >= $max ) {
			return new WP_Error(
				'bzk_max_reached',
				sprintf(
					/* translators: %d: maximum number of boosts. */
					__( 'This item has already been boosted the maximum of %d times.', 'buzzakoo-boost' ),
					$max
				)
			);
		}

		// Cooldown on the item itself.
		$remaining = self::item_cooldown_remaining( $object_type, $object_id );
		if ( $remaining > 0 ) {
			return new WP_Error(
				'bzk_cooldown',
				sprintf(
					/* translators: %s: human readable time, e.g. "12 minutes". */
					__( 'This item can be boosted again in %s.', 'buzzakoo-boost' ),
					self::human_time( $remaining )
				),
				array( 'retry_after' => $remaining )
			);
		}

		// Cooldown on the user (admins exempt — they need to be able to fix things).
		if ( ! $is_admin ) {
			$remaining = self::user_cooldown_remaining( $user_id );
			if ( $remaining > 0 ) {
				return new WP_Error(
					'bzk_user_cooldown',
					sprintf(
						/* translators: %s: human readable time, e.g. "12 minutes". */
						__( 'You can boost again in %s.', 'buzzakoo-boost' ),
						self::human_time( $remaining )
					),
					array( 'retry_after' => $remaining )
				);
			}
		}

		/**
		 * Final say on whether a boost is permitted.
		 *
		 * @param true|WP_Error $allowed
		 */
		return apply_filters( 'bzk_can_boost', true, $object_type, $object_id, $user_id );
	}

	public static function type_enabled( $object_type ) {
		switch ( $object_type ) {
			case 'activity':
				return (bool) BZK_Settings::get( 'enable_activity' ) && BZK_Activity::available();
			case 'post':
				return (bool) BZK_Settings::get( 'enable_posts' );
			case 'topic':
				return (bool) BZK_Settings::get( 'enable_bbpress' ) && BZK_BBPress::available();
		}
		return false;
	}

	public static function object_exists( $object_type, $object_id ) {
		$object_id = (int) $object_id;

		if ( 'activity' === $object_type ) {
			if ( ! function_exists( 'bp_activity_get_specific' ) ) {
				return false;
			}
			$found = bp_activity_get_specific(
				array(
					'activity_ids'     => array( $object_id ),
					'display_comments' => false,
				)
			);
			return ! empty( $found['activities'] );
		}

		$post = get_post( $object_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( 'topic' === $object_type ) {
			return function_exists( 'bbp_get_topic_post_type' ) && bbp_get_topic_post_type() === $post->post_type;
		}

		return in_array( $post->post_type, (array) BZK_Settings::get( 'post_types' ), true );
	}

	/**
	 * Role / authorship / guest rules from the settings panel.
	 */
	private static function user_may_boost( $object_type, $object_id, $user_id ) {
		if ( ! $user_id ) {
			return (bool) BZK_Settings::get( 'allow_guests' );
		}

		if ( BZK_Settings::get( 'allow_author' ) && self::is_author( $object_type, $object_id, $user_id ) ) {
			return true;
		}

		$allowed = (array) BZK_Settings::get( 'allow_roles' );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return (bool) array_intersect( $allowed, (array) $user->roles );
	}

	public static function is_author( $object_type, $object_id, $user_id ) {
		if ( 'activity' === $object_type ) {
			if ( ! function_exists( 'bp_activity_get_specific' ) ) {
				return false;
			}
			$found = bp_activity_get_specific(
				array(
					'activity_ids'     => array( (int) $object_id ),
					'display_comments' => false,
				)
			);
			if ( empty( $found['activities'][0] ) ) {
				return false;
			}
			return (int) $found['activities'][0]->user_id === (int) $user_id;
		}

		$post = get_post( (int) $object_id );
		return $post && (int) $post->post_author === (int) $user_id;
	}

	public static function item_cooldown_remaining( $object_type, $object_id ) {
		$minutes = (int) BZK_Settings::get( 'cooldown_minutes' );
		if ( $minutes <= 0 ) {
			return 0;
		}
		$last = BZK_Store::last_boost_time( $object_type, $object_id );
		if ( ! $last ) {
			return 0;
		}
		return max( 0, ( $last + $minutes * MINUTE_IN_SECONDS ) - time() );
	}

	public static function user_cooldown_remaining( $user_id ) {
		$minutes = (int) BZK_Settings::get( 'user_cooldown_minutes' );
		if ( $minutes <= 0 ) {
			return 0;
		}
		$last = BZK_Store::last_boost_time_by_user( (int) $user_id, $user_id ? '' : self::client_ip() );
		if ( ! $last ) {
			return 0;
		}
		return max( 0, ( $last + $minutes * MINUTE_IN_SECONDS ) - time() );
	}

	public static function human_time( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds. */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'buzzakoo-boost' ), $seconds );
		}
		return human_time_diff( time(), time() + $seconds );
	}

	/**
	 * Best-effort client IP, used only to rate-limit logged-out boosters.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		// Behind Cloudflare / a proxy the real address arrives in a header.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = wp_unslash( $_SERVER[ $header ] );
				break;
			}
		}

		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		return substr( (string) $ip, 0, 100 );
	}
}
