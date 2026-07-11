<?php
/**
 * Settings storage + admin settings screen.
 */

defined( 'ABSPATH' ) || exit;

class BZK_Settings {

	const OPTION = 'bzk_boost_settings';

	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			// Where boosting is switched on.
			'enable_activity'      => 1,   // BuddyPress activity stream (the main Buzzakoo feed).
			'enable_posts'         => 1,   // WordPress posts / archives.
			'enable_bbpress'       => 0,   // bbPress forum topics.
			'post_types'           => array( 'post' ),

			// Who may boost.
			'allow_roles'          => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'allow_author'         => 1,   // The item's own author may always boost it.
			'allow_guests'         => 0,   // Logged-out visitors.

			// Rules.
			'boost_duration_hours' => 24,  // 0 = never expires (stays until another item is boosted above it).
			'cooldown_minutes'     => 60,  // Per item: minimum gap between two boosts of the SAME item.
			'user_cooldown_minutes'=> 0,   // Per user: minimum gap between any two boosts by the same user.
			'max_boosts_per_item'  => 0,   // 0 = unlimited.
			'max_boosted_items'    => 0,   // 0 = unlimited concurrent boosted items; N = only newest N stay boosted.

			// Presentation.
			'button_label'         => 'Boost',
			'button_label_done'    => 'Boosted',
			'button_class'         => '',  // Extra theme classes, e.g. SocialV button classes.
			'show_count'           => 1,
			'post_button_position' => 'after', // after|before|none (inside the_content).
			'above_sticky'         => 0,   // Boosted posts rank above WP sticky posts.
			'purge_cache'          => 1,   // Ask caching plugins to flush after a boost.
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		update_option( self::OPTION, array_merge( self::defaults(), $existing ) );
		self::$cache = null;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$cache = array_merge( self::defaults(), $saved );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function register() {
		register_setting(
			'bzk_boost_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = self::all();

		$checkboxes = array( 'enable_activity', 'enable_posts', 'enable_bbpress', 'allow_author', 'allow_guests', 'show_count', 'above_sticky', 'purge_cache' );
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$ints = array( 'boost_duration_hours', 'cooldown_minutes', 'user_cooldown_minutes', 'max_boosts_per_item', 'max_boosted_items' );
		foreach ( $ints as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : $defaults[ $key ];
		}

		$out['button_label']      = isset( $input['button_label'] ) ? sanitize_text_field( $input['button_label'] ) : $defaults['button_label'];
		$out['button_label_done'] = isset( $input['button_label_done'] ) ? sanitize_text_field( $input['button_label_done'] ) : $defaults['button_label_done'];
		$out['button_class']      = isset( $input['button_class'] ) ? sanitize_text_field( $input['button_class'] ) : '';

		$position                     = isset( $input['post_button_position'] ) ? $input['post_button_position'] : 'after';
		$out['post_button_position']  = in_array( $position, array( 'after', 'before', 'none' ), true ) ? $position : 'after';

		$roles              = isset( $input['allow_roles'] ) && is_array( $input['allow_roles'] ) ? $input['allow_roles'] : array();
		$out['allow_roles'] = array_values( array_intersect( array_keys( self::roles() ), array_map( 'sanitize_key', $roles ) ) );

		$types              = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$out['post_types']  = array_values( array_intersect( array_keys( self::post_types() ), array_map( 'sanitize_key', $types ) ) );

		self::$cache = null;

		// Boost ordering changed — drop any cached feed output.
		BZK_Cache::purge();

		return $out;
	}

	public static function roles() {
		$roles = array();
		if ( ! function_exists( 'wp_roles' ) ) {
			return $roles;
		}
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[ $key ] = $role['name'];
		}
		return $roles;
	}

	public static function post_types() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$types[ $type->name ] = $type->labels->singular_name;
		}
		return $types;
	}

	/**
	 * Renders the settings screen (registered as a submenu in BZK_Admin).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post Boost', 'buzzakoo-boost' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Boosted items jump to the top of the feeds you enable below. Boost state lives in the database, so it survives page refreshes and cache flushes.', 'buzzakoo-boost' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'bzk_boost_group' ); ?>
				<?php $name = self::OPTION; ?>

				<h2 class="title"><?php esc_html_e( 'Where boosting applies', 'buzzakoo-boost' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Feeds', 'buzzakoo-boost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_activity]" value="1" <?php checked( $s['enable_activity'] ); ?> <?php disabled( ! BZK_Activity::available() ); ?> />
								<?php esc_html_e( 'BuddyPress activity stream (the main site feed)', 'buzzakoo-boost' ); ?>
							</label>
							<?php if ( ! BZK_Activity::available() ) : ?>
								<em><?php esc_html_e( '— BuddyPress activity component not active', 'buzzakoo-boost' ); ?></em>
							<?php endif; ?>
							<br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_posts]" value="1" <?php checked( $s['enable_posts'] ); ?> />
								<?php esc_html_e( 'WordPress posts (blog, archives, category / tag / search loops)', 'buzzakoo-boost' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_bbpress]" value="1" <?php checked( $s['enable_bbpress'] ); ?> <?php disabled( ! BZK_BBPress::available() ); ?> />
								<?php esc_html_e( 'bbPress forum topics (classic forum bump)', 'buzzakoo-boost' ); ?>
							</label>
							<?php if ( ! BZK_BBPress::available() ) : ?>
								<em><?php esc_html_e( '— bbPress not active', 'buzzakoo-boost' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'buzzakoo-boost' ); ?></th>
						<td>
							<?php foreach ( self::post_types() as $key => $label ) : ?>
								<label style="display:inline-block;min-width:180px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['post_types'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Which post types show a Boost button and take part in boost ordering.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Who may boost', 'buzzakoo-boost' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'buzzakoo-boost' ); ?></th>
						<td>
							<?php foreach ( self::roles() as $key => $label ) : ?>
								<label style="display:inline-block;min-width:180px;">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['allow_roles'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extra rules', 'buzzakoo-boost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_author]" value="1" <?php checked( $s['allow_author'] ); ?> />
								<?php esc_html_e( 'An item\'s own author may always boost it (even if their role is unchecked above)', 'buzzakoo-boost' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_guests]" value="1" <?php checked( $s['allow_guests'] ); ?> />
								<?php esc_html_e( 'Allow logged-out visitors to boost (rate-limited by IP)', 'buzzakoo-boost' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Boost rules', 'buzzakoo-boost' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzk_duration"><?php esc_html_e( 'Boost lasts for', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="number" min="0" id="bzk_duration" name="<?php echo esc_attr( $name ); ?>[boost_duration_hours]" value="<?php echo esc_attr( $s['boost_duration_hours'] ); ?>" class="small-text" />
							<?php esc_html_e( 'hours', 'buzzakoo-boost' ); ?>
							<p class="description"><?php esc_html_e( '0 = the boost never expires; the item stays pinned until something else is boosted above it. When a boost expires the item silently returns to its normal date position.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_cooldown"><?php esc_html_e( 'Cooldown per item', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="number" min="0" id="bzk_cooldown" name="<?php echo esc_attr( $name ); ?>[cooldown_minutes]" value="<?php echo esc_attr( $s['cooldown_minutes'] ); ?>" class="small-text" />
							<?php esc_html_e( 'minutes', 'buzzakoo-boost' ); ?>
							<p class="description"><?php esc_html_e( 'Minimum gap between two boosts of the same item. 0 = no cooldown.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_user_cooldown"><?php esc_html_e( 'Cooldown per user', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="number" min="0" id="bzk_user_cooldown" name="<?php echo esc_attr( $name ); ?>[user_cooldown_minutes]" value="<?php echo esc_attr( $s['user_cooldown_minutes'] ); ?>" class="small-text" />
							<?php esc_html_e( 'minutes', 'buzzakoo-boost' ); ?>
							<p class="description"><?php esc_html_e( 'Minimum gap between any two boosts by the same user, across all items. 0 = no limit.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_max_item"><?php esc_html_e( 'Maximum boosts per item', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="number" min="0" id="bzk_max_item" name="<?php echo esc_attr( $name ); ?>[max_boosts_per_item]" value="<?php echo esc_attr( $s['max_boosts_per_item'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Lifetime cap on how many times a single item can ever be boosted. 0 = unlimited.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_max_boosted"><?php esc_html_e( 'Maximum boosted items at once', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="number" min="0" id="bzk_max_boosted" name="<?php echo esc_attr( $name ); ?>[max_boosted_items]" value="<?php echo esc_attr( $s['max_boosted_items'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Keeps only the N most recently boosted items pinned; older boosts drop off. 0 = unlimited. Set to 1 for a single "top slot".', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Button &amp; display', 'buzzakoo-boost' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bzk_label"><?php esc_html_e( 'Button label', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="text" id="bzk_label" name="<?php echo esc_attr( $name ); ?>[button_label]" value="<?php echo esc_attr( $s['button_label'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_label_done"><?php esc_html_e( 'Label once boosted', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="text" id="bzk_label_done" name="<?php echo esc_attr( $name ); ?>[button_label_done]" value="<?php echo esc_attr( $s['button_label_done'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_class"><?php esc_html_e( 'Extra CSS classes', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<input type="text" id="bzk_class" name="<?php echo esc_attr( $name ); ?>[button_class]" value="<?php echo esc_attr( $s['button_class'] ); ?>" class="regular-text" placeholder="e.g. btn btn-primary" />
							<p class="description"><?php esc_html_e( 'Added to the button so it can inherit your theme\'s button styling.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Boost count', 'buzzakoo-boost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_count]" value="1" <?php checked( $s['show_count'] ); ?> />
								<?php esc_html_e( 'Show how many times an item has been boosted', 'buzzakoo-boost' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bzk_pos"><?php esc_html_e( 'Button position on posts', 'buzzakoo-boost' ); ?></label></th>
						<td>
							<select id="bzk_pos" name="<?php echo esc_attr( $name ); ?>[post_button_position]">
								<option value="after" <?php selected( $s['post_button_position'], 'after' ); ?>><?php esc_html_e( 'After the post content', 'buzzakoo-boost' ); ?></option>
								<option value="before" <?php selected( $s['post_button_position'], 'before' ); ?>><?php esc_html_e( 'Before the post content', 'buzzakoo-boost' ); ?></option>
								<option value="none" <?php selected( $s['post_button_position'], 'none' ); ?>><?php esc_html_e( 'Do not add automatically (I will place it myself)', 'buzzakoo-boost' ); ?></option>
							</select>
							<p class="description">
								<?php
								echo wp_kses(
									__( 'Place it manually with the shortcode <code>[buzzakoo_boost]</code> or <code>&lt;?php bzk_boost_button( "post", get_the_ID() ); ?&gt;</code> in a template.', 'buzzakoo-boost' ),
									array( 'code' => array() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sticky posts', 'buzzakoo-boost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[above_sticky]" value="1" <?php checked( $s['above_sticky'] ); ?> />
								<?php esc_html_e( 'Boosted posts rank ABOVE sticky posts', 'buzzakoo-boost' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off (default): WordPress sticky posts stay on top, boosted items come next. On: a boost outranks sticky.', 'buzzakoo-boost' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Caching', 'buzzakoo-boost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[purge_cache]" value="1" <?php checked( $s['purge_cache'] ); ?> />
								<?php esc_html_e( 'Flush page caches after a boost', 'buzzakoo-boost' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Detected cache plugins:', 'buzzakoo-boost' ); ?>
								<strong><?php echo esc_html( BZK_Cache::detected_label() ); ?></strong>.
								<?php esc_html_e( 'The button always reads its live state over the REST API, so it stays correct even on a fully cached page.', 'buzzakoo-boost' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
