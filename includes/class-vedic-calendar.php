<?php
/**
 * Puts the Vedic date on its own line below the date a theme shows on a post
 * or page.
 *
 * Themes print dates in too many ways to target any one of them, and most
 * escape what get_the_date() returns, so HTML added there would be printed as
 * text. So the date filters only append an invisible mark, a few zero-width
 * characters that survive escaping, and the mark is turned into HTML later,
 * where it is known to be text and not an attribute:
 *
 * - inside a block (the Date block, Latest Posts, any block that shows a post
 *   date), when the block has been rendered;
 * - in a classic theme, in an output buffer held open for the length of each
 *   loop.
 *
 * A mark inside a tag, a script, a style, a textarea or a title is dropped, so
 * datetime attributes, schema markup and page titles keep the plain date. A
 * mark that escapes both — a theme that keeps a date and prints it after its
 * loop has ended — stays invisible.
 *
 * @package VedicCalendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * The front end.
 */
final class Vedic_Calendar {

	const OPTION = 'vedic_calendar';

	/**
	 * U+2063 INVISIBLE SEPARATOR, which opens and closes a mark. Between them
	 * the mark's number in binary: U+200B for 0, U+200C for 1.
	 */
	const MARK = "\u{2063}";

	const MARK_PATTERN = '/\x{2063}([\x{200B}\x{200C}]+)\x{2063}/u';

	/**
	 * The one instance.
	 *
	 * @var Vedic_Calendar|null
	 */
	private static $instance = null;

	/**
	 * The date behind each mark handed out on this request, by mark number.
	 *
	 * @var string[]
	 */
	private $marks = array();

	/**
	 * Output-buffer levels opened at loop_start and not yet closed.
	 *
	 * @var int[]
	 */
	private $buffers = array();

	/**
	 * Labels already worked out on this request, by Y-m-d.
	 *
	 * @var string[]
	 */
	private $labels = array();

	/**
	 * The front end, created once.
	 *
	 * @return Vedic_Calendar
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Settings as saved, over the defaults.
	 *
	 * @return array
	 */
	public static function options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Out of the box: posts and pages.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types' => array( 'post', 'page' ),
			'format'     => '{month} {day}, KaliYuga {year}',
		);
	}

	/**
	 * The twelve solar months, Mesha first: the KaliYuga year begins with
	 * Mesha, at Vishu.
	 *
	 * @return string[]
	 */
	public static function month_names() {
		return array(
			_x( 'Mesha', 'solar month', 'vedic-calendar' ),
			_x( 'Vrishabha', 'solar month', 'vedic-calendar' ),
			_x( 'Mithuna', 'solar month', 'vedic-calendar' ),
			_x( 'Karkataka', 'solar month', 'vedic-calendar' ),
			_x( 'Simha', 'solar month', 'vedic-calendar' ),
			_x( 'Kanya', 'solar month', 'vedic-calendar' ),
			_x( 'Tula', 'solar month', 'vedic-calendar' ),
			_x( 'Vrishchika', 'solar month', 'vedic-calendar' ),
			_x( 'Dhanu', 'solar month', 'vedic-calendar' ),
			_x( 'Makara', 'solar month', 'vedic-calendar' ),
			_x( 'Kumbha', 'solar month', 'vedic-calendar' ),
			_x( 'Meena', 'solar month', 'vedic-calendar' ),
		);
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'front' ) );
	}

	/**
	 * [vedic_date] prints the Vedic date of the current post, or of
	 * date="YYYY-MM-DD", or of date="today".
	 */
	public function register_shortcode() {
		add_shortcode( 'vedic_date', array( $this, 'shortcode' ) );
	}

	/**
	 * Front-end pages only; feeds and sitemaps keep their plain dates.
	 */
	public function front() {
		if ( is_feed() || is_robots() || is_trackback() || is_favicon() ) {
			return;
		}
		add_filter( 'get_the_date', array( $this, 'mark_date' ), 10, 3 );
		add_filter( 'get_the_time', array( $this, 'mark_date' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
		add_action( 'loop_start', array( $this, 'loop_start' ) );
		add_action( 'loop_end', array( $this, 'loop_end' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The one rule of style the line needs: it sits below the date.
	 */
	public function enqueue() {
		wp_register_style( 'vedic-calendar', false, array(), VEDIC_CALENDAR_VERSION );
		wp_enqueue_style( 'vedic-calendar' );
		wp_add_inline_style( 'vedic-calendar', '.vedic-calendar-date{display:block;font-size:.85em;line-height:1.5;opacity:.85}' );
	}

	/**
	 * Appends a mark to a post's date as a theme asks for it.
	 *
	 * @param string           $value  The date or time as formatted.
	 * @param string           $format The format asked for.
	 * @param WP_Post|int|null $post   The post.
	 * @return string
	 */
	public function mark_date( $value, $format, $post = null ) {
		if ( empty( $this->buffers ) || ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( ! $this->is_display_format( (string) $format, 'get_the_date' === current_filter() ) ) {
			return $value;
		}
		$post = get_post( $post );
		if ( ! $post || ! $this->shows_on( $post->post_type ) ) {
			return $value;
		}
		$ymd = get_post_time( 'Y-m-d', false, $post );
		if ( ! is_string( $ymd ) ) {
			return $value;
		}

		$this->marks[] = $ymd;
		$number        = decbin( count( $this->marks ) - 1 );
		return $value . self::MARK . strtr( $number, array( '0' => "\u{200B}", '1' => "\u{200C}" ) ) . self::MARK;
	}

	/**
	 * Whether a date format is one people read, as opposed to the ISO and
	 * Unix forms themes put in datetime attributes and schema.
	 *
	 * @param string $format    The format.
	 * @param bool   $is_date   True from get_the_date(), where an empty format is
	 *                          the site's date format; from get_the_time() it is
	 *                          the time format, which shows no date.
	 * @return bool
	 */
	private function is_display_format( $format, $is_date ) {
		if ( '' === $format ) {
			return $is_date;
		}
		if ( get_option( 'date_format' ) === $format ) {
			return true;
		}
		if ( in_array( $format, array( DATE_RFC2822, DATE_RSS, DATE_COOKIE, DATE_RFC7231, DATE_RFC850, DATE_RFC1036, DATE_RFC1123 ), true ) ) {
			return false;
		}
		// A month or weekday in words means a reader is meant to see it.
		return (bool) preg_match( '/(?<!\\\\)[FMlD]/', $format );
	}

	/**
	 * Whether the settings show the Vedic date on this post type.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private function shows_on( $type ) {
		return in_array( $type, (array) self::options()['post_types'], true );
	}

	/**
	 * Opens a buffer for the length of a loop.
	 */
	public function loop_start() {
		ob_start( array( $this, 'resolve' ) );
		$this->buffers[] = ob_get_level();
	}

	/**
	 * Closes the loop's buffer, if it is still the innermost one. If a theme
	 * has left a buffer of its own open inside, ours is flushed at shutdown.
	 */
	public function loop_end() {
		$level = array_pop( $this->buffers );
		if ( null !== $level && ob_get_level() === $level ) {
			ob_end_flush();
		}
	}

	/**
	 * Resolves the marks in a rendered block. The Date block is handled here
	 * whatever its date: it may show the published or the modified date.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   The parsed block.
	 * @return string
	 */
	public function render_block( $content, $block ) {
		if ( isset( $block['blockName'] ) && 'core/post-date' === $block['blockName'] ) {
			return $this->date_block( $content );
		}
		return $this->resolve( $content );
	}

	/**
	 * Adds the line after the Date block's time element, from the date in its
	 * datetime attribute, which is the date the block shows, in the site's
	 * time zone.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	private function date_block( $content ) {
		$content = $this->strip_marks( $content );
		$post    = get_post();
		if ( ! $post || ! $this->shows_on( $post->post_type ) ) {
			return $content;
		}
		if ( ! preg_match( '/<time\b[^>]*\bdatetime="(\d{4}-\d{2}-\d{2})/', $content, $m ) ) {
			return $content;
		}
		$at = strrpos( $content, '</time>' );
		if ( false === $at ) {
			return $content;
		}
		return substr_replace( $content, $this->html( $m[1] ), $at + 7, 0 );
	}

	/**
	 * Turns the marks in a piece of HTML into the Vedic date, where they stand
	 * in text, and drops them where they stand anywhere else.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function resolve( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, self::MARK ) ) {
			return $html;
		}
		$steps = array(
			'#<(script|style|textarea|title)\b[^>]*>.*?</\1\s*>#is' => array( $this, 'strip_marks_match' ),
			'/<[^>]+>/'                                              => array( $this, 'strip_marks_match' ),
			self::MARK_PATTERN                                        => array( $this, 'mark_html' ),
		);
		foreach ( $steps as $pattern => $callback ) {
			$done = preg_replace_callback( $pattern, $callback, $html );
			if ( null === $done ) {
				return $this->strip_marks( $html );
			}
			$html = $done;
		}
		return $html;
	}

	/**
	 * A mark, as HTML.
	 *
	 * @param string[] $m The mark matched.
	 * @return string
	 */
	public function mark_html( $m ) {
		$number = bindec( strtr( $m[1], array( "\u{200B}" => '0', "\u{200C}" => '1' ) ) );
		return isset( $this->marks[ $number ] ) ? $this->html( $this->marks[ $number ] ) : '';
	}

	/**
	 * A matched span of HTML with its marks dropped.
	 *
	 * @param string[] $m The match.
	 * @return string
	 */
	public function strip_marks_match( $m ) {
		return $this->strip_marks( $m[0] );
	}

	/**
	 * Drops every mark.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function strip_marks( $html ) {
		if ( false === strpos( $html, self::MARK ) ) {
			return $html;
		}
		$done = preg_replace( self::MARK_PATTERN, '', $html );
		return null === $done ? $html : $done;
	}

	/**
	 * [vedic_date], [vedic_date date="today"], [vedic_date date="2026-04-14"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'date' => '' ), $atts, 'vedic_date' );
		$date = trim( (string) $atts['date'] );
		if ( 'today' === $date ) {
			$date = current_time( 'Y-m-d' );
		} elseif ( '' === $date ) {
			$date = get_post_time( 'Y-m-d' );
		}
		return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $this->html( $date ) : '';
	}

	/**
	 * The line as HTML.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private function html( $ymd ) {
		$label = self::label( $ymd );
		return '' === $label ? '' : '<span class="vedic-calendar-date">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The Vedic date of a Gregorian date, in the format from the settings,
	 * e.g. "Tula 17, KaliYuga 5127".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string Empty for a date that does not exist.
	 */
	public static function label( $ymd ) {
		$self = self::instance();
		if ( isset( $self->labels[ $ymd ] ) ) {
			return $self->labels[ $ymd ];
		}
		$solar = self::solar_date( $ymd );
		if ( null === $solar ) {
			return '';
		}
		$names = self::month_names();
		$label = strtr(
			self::options()['format'],
			array(
				'{month}' => $names[ $solar['month'] ],
				'{day}'   => (string) $solar['day'],
				'{year}'  => (string) $solar['year'],
			)
		);

		/**
		 * Filters the Vedic date before it is shown.
		 *
		 * @param string $label The date, formatted.
		 * @param array  $solar KaliYuga year, month (0 = Mesha ... 11 = Meena),
		 *                      day, and Y-m-d of the month's first day.
		 * @param string $ymd   The Gregorian date, Y-m-d.
		 */
		$label = (string) apply_filters( 'vedic_calendar_label', $label, $solar, $ymd );

		$self->labels[ $ymd ] = $label;
		return $label;
	}

	/**
	 * The solar date of a Gregorian date, cached.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array|null See Vedic_Calendar_Astronomy::solar_date().
	 */
	public static function solar_date( $ymd ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		/**
		 * Filters where sunrise and sunset are taken for the Kerala rule that
		 * decides the first day of a month. Thiruvananthapuram by default; any
		 * place in Kerala gives the same months on all but the rarest days.
		 *
		 * @param array $place array( 'latitude' => degrees north, 'longitude' => degrees east ).
		 */
		$place = apply_filters(
			'vedic_calendar_location',
			array(
				'latitude'  => 8.5241,
				'longitude' => 76.9366,
			)
		);
		$lat = isset( $place['latitude'] ) ? (float) $place['latitude'] : 8.5241;
		$lon = isset( $place['longitude'] ) ? (float) $place['longitude'] : 76.9366;

		$key   = $ymd . '|' . $lat . '|' . $lon;
		$solar = wp_cache_get( $key, 'vedic_calendar' );
		if ( ! is_array( $solar ) ) {
			$solar = Vedic_Calendar_Astronomy::solar_date( (int) $m[1], (int) $m[2], (int) $m[3], $lat, $lon );
			wp_cache_set( $key, $solar, 'vedic_calendar', DAY_IN_SECONDS );
		}
		return $solar;
	}
}

/**
 * The Vedic date of a Gregorian date, as text, for themes.
 *
 * @param string|null $ymd Y-m-d; the current post's date when left out.
 * @return string
 */
function vedic_calendar_date( $ymd = null ) {
	if ( null === $ymd ) {
		$ymd = get_post_time( 'Y-m-d' );
	}
	return is_string( $ymd ) ? Vedic_Calendar::label( $ymd ) : '';
}
