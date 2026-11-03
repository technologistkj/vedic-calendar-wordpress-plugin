<?php
/**
 * Settings > Vedic Calendar.
 *
 * @package VedicCalendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings page.
 */
final class Vedic_Calendar_Settings {

	const PAGE = 'vedic-calendar';

	/**
	 * The one instance.
	 *
	 * @var Vedic_Calendar_Settings|null
	 */
	private static $instance = null;

	/**
	 * The settings page, created once.
	 *
	 * @return Vedic_Calendar_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VEDIC_CALENDAR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings > Vedic Calendar.
	 */
	public function menu() {
		add_options_page(
			__( 'Vedic Calendar', 'vedic-calendar' ),
			__( 'Vedic Calendar', 'vedic-calendar' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * A Settings link beside Deactivate.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vedic-calendar' ) . '</a>' );
		return $links;
	}

	/**
	 * The option and its fields.
	 */
	public function register() {
		register_setting(
			self::PAGE,
			Vedic_Calendar::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Vedic_Calendar::defaults(),
			)
		);

		add_settings_section( 'dvc_main', '', array( $this, 'section' ), self::PAGE );
		add_settings_field( 'post_types', __( 'Show on', 'vedic-calendar' ), array( $this, 'field_post_types' ), self::PAGE, 'dvc_main' );
		add_settings_field( 'format', __( 'Format', 'vedic-calendar' ), array( $this, 'field_format' ), self::PAGE, 'dvc_main', array( 'label_for' => 'dvc-format' ) );
	}

	/**
	 * Post types that have a front end, other than attachments.
	 *
	 * @return WP_Post_Type[]
	 */
	private function post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Cleans what the form sent.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$types = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$types = array_values( array_intersect( array_keys( $this->post_types() ), $types ) );

		$format = isset( $input['format'] ) ? sanitize_text_field( $input['format'] ) : '';
		if ( '' === $format ) {
			$format = Vedic_Calendar::defaults()['format'];
		}

		return array(
			'post_types' => $types,
			'format'     => $format,
		);
	}

	/**
	 * What the plugin shows, and how it reckons it.
	 */
	public function section() {
		echo '<p>' . esc_html__( 'Below the date a post or page shows, a second line gives the same day in the Vedic calendar: the Kerala solar month and day, and the KaliYuga year.', 'vedic-calendar' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:2em">';
		echo '<li>' . esc_html__( 'A month begins when the Sun enters a sidereal sign (Lahiri ayanamsa). The months carry the Sanskrit names of their signs: Mesha, Vrishabha, Mithuna, Karkataka, Simha, Kanya, Tula, Vrishchika, Dhanu, Makara, Kumbha, Meena.', 'vedic-calendar' ) . '</li>';
		echo '<li>' . esc_html__( 'Kerala’s rule decides the first day: if the Sun enters the sign within the first three fifths of the daytime, the month begins that day; otherwise it begins the next day.', 'vedic-calendar' ) . '</li>';
		echo '<li>' . esc_html__( 'The KaliYuga year begins at Vishu, the first day of Mesha.', 'vedic-calendar' ) . '</li>';
		echo '</ul>';
		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Today:', 'vedic-calendar' ),
			esc_html( wp_date( get_option( 'date_format' ) ) . ' — ' . Vedic_Calendar::label( current_time( 'Y-m-d' ) ) )
		);
	}

	/**
	 * Which post types get the line.
	 */
	public function field_post_types() {
		$o = Vedic_Calendar::options();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Show on', 'vedic-calendar' ) . '</legend>';
		foreach ( $this->post_types() as $name => $type ) {
			printf(
				'<label style="display:inline-block;margin:0 1.5em .4em 0"><input type="checkbox" name="%1$s[post_types][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( Vedic_Calendar::OPTION ),
				esc_attr( $name ),
				checked( in_array( $name, (array) $o['post_types'], true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * How the line is written.
	 */
	public function field_format() {
		$o = Vedic_Calendar::options();
		printf(
			'<input type="text" class="regular-text" id="dvc-format" name="%1$s[format]" value="%2$s">',
			esc_attr( Vedic_Calendar::OPTION ),
			esc_attr( $o['format'] )
		);
		echo '<p class="description">' . esc_html__( '{month} is the month, {day} the day of the month, {year} the KaliYuga year.', 'vedic-calendar' ) . '</p>';
	}

	/**
	 * The page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1><form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
	}
}
