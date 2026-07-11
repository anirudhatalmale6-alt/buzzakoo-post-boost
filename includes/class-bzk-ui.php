<?php
/**
 * Front-end button markup + assets.
 *
 * The button is rendered server-side but deliberately ships in a NEUTRAL state:
 * the real state (boosted? on cooldown? how many boosts?) is fetched over REST
 * as soon as the page loads. That is what makes it safe under a full-page cache —
 * a cached page can never show a stale "Boosted" label, because the label is filled
 * in client-side from live data.
 */

defined( 'ABSPATH' ) || exit;

class BZK_UI {

	/** Items rendered on this page, so we can ask for all their states in one request. */
	private static $rendered = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_state_bootstrap' ), 5 );
	}

	public static function enqueue() {
		wp_register_style( 'bzk-boost', BZK_BOOST_URL . 'assets/boost.css', array(), BZK_BOOST_VERSION );
		wp_register_script( 'bzk-boost', BZK_BOOST_URL . 'assets/boost.js', array(), BZK_BOOST_VERSION, true );

		wp_localize_script(
			'bzk-boost',
			'BZK_BOOST',
			array(
				'root'      => esc_url_raw( rest_url( 'buzzakoo-boost/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'loggedIn'  => is_user_logged_in(),
				'showCount' => (bool) BZK_Settings::get( 'show_count' ),
				'i18n'      => array(
					'boost'    => BZK_Settings::get( 'button_label' ),
					'boosted'  => BZK_Settings::get( 'button_label_done' ),
					'working'  => __( 'Boosting…', 'buzzakoo-boost' ),
					'error'    => __( 'Could not boost. Please try again.', 'buzzakoo-boost' ),
					'login'    => __( 'Please log in to boost.', 'buzzakoo-boost' ),
					'bumped'   => __( 'Boosted — this is now at the top of the feed.', 'buzzakoo-boost' ),
				),
			)
		);

		wp_enqueue_style( 'bzk-boost' );
		wp_enqueue_script( 'bzk-boost' );
	}

	/**
	 * Build a boost button. Returns '' when the item can't be boosted at all.
	 */
	public static function get_button( $object_type, $object_id ) {
		$object_id = (int) $object_id;

		if ( ! BZK_Store::valid_type( $object_type ) || $object_id <= 0 ) {
			return '';
		}
		if ( ! BZK_Rules::type_enabled( $object_type ) ) {
			return '';
		}

		// Hide entirely from logged-out visitors unless guests may boost.
		if ( ! is_user_logged_in() && ! BZK_Settings::get( 'allow_guests' ) ) {
			return '';
		}

		self::$rendered[] = $object_type . ':' . $object_id;

		$classes = array( 'bzk-boost-btn' );
		$extra   = trim( (string) BZK_Settings::get( 'button_class' ) );
		if ( $extra ) {
			$classes = array_merge( $classes, preg_split( '/\s+/', $extra ) );
		}

		$label = (string) BZK_Settings::get( 'button_label' );

		$html  = '<span class="bzk-boost-wrap" data-bzk-type="' . esc_attr( $object_type ) . '" data-bzk-id="' . esc_attr( $object_id ) . '">';
		$html .= '<button type="button" class="' . esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ) . '" '
			. 'data-bzk-type="' . esc_attr( $object_type ) . '" '
			. 'data-bzk-id="' . esc_attr( $object_id ) . '" '
			. 'aria-live="polite" disabled>'
			. '<span class="bzk-boost-icon" aria-hidden="true">' . self::icon() . '</span>'
			. '<span class="bzk-boost-label">' . esc_html( $label ) . '</span>'
			. '<span class="bzk-boost-count" hidden></span>'
			. '</button>';
		$html .= '<span class="bzk-boost-msg" role="status"></span>';
		$html .= '</span>';

		/**
		 * Filter the boost button markup (e.g. to match a theme exactly).
		 */
		return apply_filters( 'bzk_boost_button_html', $html, $object_type, $object_id );
	}

	private static function icon() {
		// Simple upward chevron — inherits currentColor, so it takes the theme's colour.
		return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
	}

	/**
	 * Tell the JS which items are on this page so it can fetch all their states at once.
	 */
	public static function print_state_bootstrap() {
		if ( empty( self::$rendered ) ) {
			return;
		}
		$items = array_values( array_unique( self::$rendered ) );

		printf(
			'<script type="application/json" id="bzk-boost-items">%s</script>',
			wp_json_encode( $items )
		);
	}
}
