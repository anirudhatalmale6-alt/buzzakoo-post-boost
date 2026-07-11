<?php
/**
 * Admin: menu, the "currently boosted" list, and Boost/Un-boost row actions
 * on the standard posts list table.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bzk_unboost', array( __CLASS__, 'handle_unboost' ) );
		add_action( 'admin_post_bzk_boost', array( __CLASS__, 'handle_boost' ) );

		// Row actions on post list tables.
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );

		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Post Boost', 'buzzakoo-boost' ),
			__( 'Post Boost', 'buzzakoo-boost' ),
			'manage_options',
			'bzk-boost',
			array( 'BZK_Settings', 'render_page' ),
			'dashicons-arrow-up-alt',
			58
		);

		add_submenu_page(
			'bzk-boost',
			__( 'Settings', 'buzzakoo-boost' ),
			__( 'Settings', 'buzzakoo-boost' ),
			'manage_options',
			'bzk-boost',
			array( 'BZK_Settings', 'render_page' )
		);

		add_submenu_page(
			'bzk-boost',
			__( 'Currently boosted', 'buzzakoo-boost' ),
			__( 'Currently boosted', 'buzzakoo-boost' ),
			'manage_options',
			'bzk-boost-active',
			array( __CLASS__, 'render_active' )
		);
	}

	/**
	 * Short human label for an item — used in the cart, at checkout and in order notes,
	 * so the buyer can see what they're paying to boost.
	 */
	public static function item_label( $type, $id ) {
		$info = self::describe( $type, $id );
		return $info['label'];
	}

	/**
	 * Human label + link for a boosted item, whatever kind it is.
	 */
	private static function describe( $type, $id ) {
		if ( 'activity' === $type ) {
			if ( function_exists( 'bp_activity_get_specific' ) ) {
				$found = bp_activity_get_specific(
					array(
						'activity_ids'     => array( (int) $id ),
						'display_comments' => false,
					)
				);
				if ( ! empty( $found['activities'][0] ) ) {
					$activity = $found['activities'][0];
					$excerpt  = wp_trim_words( wp_strip_all_tags( $activity->content ), 12, '…' );
					if ( ! $excerpt ) {
						$excerpt = wp_trim_words( wp_strip_all_tags( $activity->action ), 12, '…' );
					}
					return array(
						'label' => $excerpt ? $excerpt : sprintf( __( 'Activity #%d', 'buzzakoo-boost' ), $id ),
						'link'  => function_exists( 'bp_activity_get_permalink' ) ? bp_activity_get_permalink( $id ) : '',
						'who'   => get_the_author_meta( 'display_name', $activity->user_id ),
					);
				}
			}
			return array(
				'label' => sprintf( __( 'Activity #%d (deleted)', 'buzzakoo-boost' ), $id ),
				'link'  => '',
				'who'   => '',
			);
		}

		$post = get_post( (int) $id );
		if ( ! $post ) {
			return array(
				'label' => sprintf( __( 'Item #%d (deleted)', 'buzzakoo-boost' ), $id ),
				'link'  => '',
				'who'   => '',
			);
		}

		return array(
			'label' => get_the_title( $post ),
			'link'  => get_permalink( $post ),
			'who'   => get_the_author_meta( 'display_name', $post->post_author ),
		);
	}

	public static function render_active() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rows = BZK_Store::list_boosted();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Currently boosted', 'buzzakoo-boost' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Everything that is pinned to the top of a feed right now. Removing a boost sends the item straight back to its normal date position.', 'buzzakoo-boost' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:110px;"><?php esc_html_e( 'Type', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:150px;"><?php esc_html_e( 'Author', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Boosted', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Expires', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:70px;"><?php esc_html_e( 'Times', 'buzzakoo-boost' ); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e( 'Action', 'buzzakoo-boost' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nothing is boosted at the moment.', 'buzzakoo-boost' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$info    = self::describe( $row->object_type, $row->object_id );
						$boosted = BZK_Store::to_timestamp( $row->boosted_at );
						$expires = BZK_Store::to_timestamp( $row->expires_at );

						$unboost = wp_nonce_url(
							admin_url( 'admin-post.php?action=bzk_unboost&type=' . rawurlencode( $row->object_type ) . '&id=' . (int) $row->object_id ),
							'bzk_unboost_' . $row->object_type . '_' . $row->object_id
						);
						?>
						<tr>
							<td>
								<strong>
									<?php if ( $info['link'] ) : ?>
										<a href="<?php echo esc_url( $info['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $info['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $info['label'] ); ?>
									<?php endif; ?>
								</strong>
							</td>
							<td><?php echo esc_html( $row->object_type ); ?></td>
							<td><?php echo esc_html( $info['who'] ); ?></td>
							<td>
								<?php
								/* translators: %s: human time difference, e.g. "5 mins". */
								printf( esc_html__( '%s ago', 'buzzakoo-boost' ), esc_html( human_time_diff( $boosted, time() ) ) );
								?>
							</td>
							<td>
								<?php
								if ( ! $expires ) {
									esc_html_e( 'Never', 'buzzakoo-boost' );
								} else {
									/* translators: %s: human time difference. */
									printf( esc_html__( 'in %s', 'buzzakoo-boost' ), esc_html( human_time_diff( time(), $expires ) ) );
								}
								?>
							</td>
							<td><?php echo (int) $row->boost_count; ?></td>
							<td>
								<a href="<?php echo esc_url( $unboost ); ?>" class="button button-small"><?php esc_html_e( 'Un-boost', 'buzzakoo-boost' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * "Boost" / "Un-boost" links on the posts list table.
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$type = 'post';
		if ( BZK_BBPress::available() && bbp_get_topic_post_type() === $post->post_type ) {
			$type = 'topic';
		} elseif ( ! in_array( $post->post_type, (array) BZK_Settings::get( 'post_types' ), true ) ) {
			return $actions;
		}

		if ( ! BZK_Rules::type_enabled( $type ) ) {
			return $actions;
		}

		$boosted = BZK_Store::get_boost( $type, $post->ID );

		if ( $boosted ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bzk_unboost&type=' . $type . '&id=' . $post->ID ),
				'bzk_unboost_' . $type . '_' . $post->ID
			);
			$actions['bzk_unboost'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Un-boost', 'buzzakoo-boost' ) . '</a>';
		} else {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bzk_boost&type=' . $type . '&id=' . $post->ID ),
				'bzk_boost_' . $type . '_' . $post->ID
			);
			$actions['bzk_boost'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Boost', 'buzzakoo-boost' ) . '</a>';
		}

		return $actions;
	}

	public static function handle_boost() {
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( 'bzk_boost_' . $type . '_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'buzzakoo-boost' ) );
		}

		$result = BZK_Store::boost( $type, $id );
		$status = is_wp_error( $result ) ? 'error' : 'boosted';

		self::redirect_back( $status, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public static function handle_unboost() {
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( 'bzk_unboost_' . $type . '_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'buzzakoo-boost' ) );
		}

		BZK_Store::unboost( $type, $id );

		self::redirect_back( 'unboosted', '' );
	}

	private static function redirect_back( $status, $message ) {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=bzk-boost-active' );
		}

		$back = add_query_arg( 'bzk_status', $status, $back );
		if ( $message ) {
			$back = add_query_arg( 'bzk_msg', rawurlencode( $message ), $back );
		}

		wp_safe_redirect( $back );
		exit;
	}

	public static function notices() {
		if ( empty( $_GET['bzk_status'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['bzk_status'] ) );

		if ( 'error' === $status ) {
			$message = isset( $_GET['bzk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bzk_msg'] ) ) : __( 'Could not boost that item.', 'buzzakoo-boost' );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			return;
		}

		$messages = array(
			'boosted'   => __( 'Boosted — it is now at the top of the feed.', 'buzzakoo-boost' ),
			'unboosted' => __( 'Boost removed. The item is back in its normal position.', 'buzzakoo-boost' ),
		);

		if ( isset( $messages[ $status ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $status ] ) );
		}
	}
}
