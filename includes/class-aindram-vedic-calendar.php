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
 * @package AindramVedicCalendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * The front end.
 */
final class Aindram_Vedic_Calendar {

	const OPTION = 'aindram_vedic_calendar';

	/**
	 * U+2063 INVISIBLE SEPARATOR, which opens and closes a mark. Between them
	 * the mark's number in binary: U+200B for 0, U+200C for 1.
	 */
	const MARK = "\u{2063}";

	const MARK_PATTERN = '/\x{2063}([\x{200B}\x{200C}]+)\x{2063}/u';

	/**
	 * Where the date links to, when the site has chosen to link it.
	 */
	const LINK = 'https://wordpress.org/plugins/aindram-vedic-calendar/';

	/**
	 * The one instance.
	 *
	 * @var Aindram_Vedic_Calendar|null
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
	 * @return Aindram_Vedic_Calendar
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
	 * Out of the box: posts and pages, and no link. A link on the public site is
	 * the site owner's choice to make, so it is off until they turn it on.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types' => array( 'post', 'page' ),
			'format'     => '{month} {day}, KaliYuga {year}',
			'link'       => false,
		);
	}

	/**
	 * The address the date links to, or '' when the site has not chosen to link
	 * it.
	 *
	 * @return string
	 */
	public static function link_url() {
		if ( empty( self::options()['link'] ) ) {
			return '';
		}

		/**
		 * Filters where the Vedic date links to, when linking is on. Return ''
		 * for no link.
		 *
		 * @param string $url The plugin's page on WordPress.org.
		 */
		return (string) apply_filters( 'aindram_vedic_calendar_link', self::LINK );
	}

	/**
	 * The twelve solar months, Mesha first: the KaliYuga year begins with
	 * Mesha, at Vishu.
	 *
	 * @return string[]
	 */
	public static function month_names() {
		return array(
			_x( 'Mesha', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Vrishabha', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Mithuna', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Karkataka', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Simha', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Kanya', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Tula', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Vrishchika', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Dhanu', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Makara', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Kumbha', 'solar month', 'aindram-vedic-calendar' ),
			_x( 'Meena', 'solar month', 'aindram-vedic-calendar' ),
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
	 * [aindram_vedic_date] prints the Vedic date of the current post, or of
	 * date="YYYY-MM-DD", or of date="today".
	 */
	public function register_shortcode() {
		add_shortcode( 'aindram_vedic_date', array( $this, 'shortcode' ) );
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
		wp_register_style( 'aindram-vedic-calendar', false, array(), AINDRAM_VEDIC_CALENDAR_VERSION );
		wp_enqueue_style( 'aindram-vedic-calendar' );
		wp_add_inline_style( 'aindram-vedic-calendar', '.aindram-vedic-calendar-date{display:block;font-size:.85em;line-height:1.5;opacity:.85}a.aindram-vedic-calendar-date{color:inherit;text-decoration:none}a.aindram-vedic-calendar-date:hover,a.aindram-vedic-calendar-date:focus{text-decoration:underline}' );
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
		$at += 7;

		// With "Link to post" on, the time sits inside the block's own link. The
		// Vedic date goes after that link when it is to be a link itself, since
		// links cannot nest; otherwise it stays where it was, inside.
		if ( self::inside_link( substr( $content, 0, $at ) ) ) {
			$close = stripos( $content, '</a>', $at );
			if ( '' === self::link_url() || false === $close ) {
				return substr_replace( $content, $this->html( $m[1], false ), $at, 0 );
			}
			$at = $close + 4;
		}
		return substr_replace( $content, $this->html( $m[1] ), $at, 0 );
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
		);
		foreach ( $steps as $pattern => $callback ) {
			$done = preg_replace_callback( $pattern, $callback, $html );
			if ( null === $done ) {
				return $this->strip_marks( $html );
			}
			$html = $done;
		}

		// What is left is marks in text. Walk them with the links around them:
		// most themes make the date a link to the post, and links cannot nest.
		// So when the Vedic date is to be a link, one inside a theme's link is
		// moved to just after it; when it is not, it stays where it is.
		$pieces = preg_split( '#(<a\b[^>]*>|</a\s*>|\x{2063}[\x{200B}\x{200C}]+\x{2063})#iu', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $pieces ) {
			return $this->strip_marks( $html );
		}
		$linking = '' !== self::link_url();
		$depth   = 0;
		$waiting = array();
		foreach ( $pieces as $i => $piece ) {
			if ( 1 !== $i % 2 ) {
				continue;
			}
			if ( self::MARK === substr( $piece, 0, 3 ) ) {
				if ( 0 === $depth || ! $linking ) {
					$pieces[ $i ] = $this->mark_html( $piece, 0 === $depth );
				} else {
					$waiting[ $i ] = $piece;
					$pieces[ $i ]  = '';
				}
			} elseif ( '/' === $piece[1] ) {
				$depth = max( 0, $depth - 1 );
				if ( 0 === $depth && $waiting ) {
					foreach ( $waiting as $mark ) {
						$pieces[ $i ] .= $this->mark_html( $mark );
					}
					$waiting = array();
				}
			} else {
				++$depth;
			}
		}
		// A link this piece of HTML never closes: leave those dates in place.
		foreach ( $waiting as $i => $mark ) {
			$pieces[ $i ] = $this->mark_html( $mark, false );
		}
		return implode( '', $pieces );
	}

	/**
	 * Whether the end of a piece of HTML is inside a link.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	private static function inside_link( $html ) {
		return preg_match_all( '#<a\b[^>]*>#i', $html ) > preg_match_all( '#</a\s*>#i', $html );
	}

	/**
	 * A mark, as HTML.
	 *
	 * @param string $mark     The mark.
	 * @param bool   $linkable False where the mark stands inside a link.
	 * @return string
	 */
	public function mark_html( $mark, $linkable = true ) {
		if ( ! preg_match( self::MARK_PATTERN, $mark, $m ) ) {
			return '';
		}
		$number = bindec( strtr( $m[1], array( "\u{200B}" => '0', "\u{200C}" => '1' ) ) );
		return isset( $this->marks[ $number ] ) ? $this->html( $this->marks[ $number ], $linkable ) : '';
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
	 * [aindram_vedic_date], [aindram_vedic_date date="today"], [aindram_vedic_date date="2026-04-14"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'date' => '' ), $atts, 'aindram_vedic_date' );
		$date = trim( (string) $atts['date'] );
		if ( 'today' === $date ) {
			$date = current_time( 'Y-m-d' );
		} elseif ( '' === $date ) {
			$date = get_post_time( 'Y-m-d' );
		}
		return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $this->html( $date ) : '';
	}

	/**
	 * The line as HTML: a link to the plugin's page when the site has turned
	 * that on and the line is not already inside a link.
	 *
	 * @param string $ymd      Y-m-d.
	 * @param bool   $linkable False where the line stands inside a link.
	 * @return string
	 */
	private function html( $ymd, $linkable = true ) {
		$label = self::label( $ymd );
		if ( '' === $label ) {
			return '';
		}
		$url = $linkable ? self::link_url() : '';
		if ( '' === $url ) {
			return '<span class="aindram-vedic-calendar-date">' . esc_html( $label ) . '</span>';
		}
		return sprintf(
			'<a class="aindram-vedic-calendar-date" href="%1$s" target="_blank" rel="noopener" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'About Aindram Vedic Calendar, and how to add it to your own site', 'aindram-vedic-calendar' ),
			esc_html( $label )
		);
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
		$label = (string) apply_filters( 'aindram_vedic_calendar_label', $label, $solar, $ymd );

		$self->labels[ $ymd ] = $label;
		return $label;
	}

	/**
	 * The solar date of a Gregorian date, cached.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array|null See Aindram_Vedic_Calendar_Astronomy::solar_date().
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
			'aindram_vedic_calendar_location',
			array(
				'latitude'  => 8.5241,
				'longitude' => 76.9366,
			)
		);
		$lat = isset( $place['latitude'] ) ? (float) $place['latitude'] : 8.5241;
		$lon = isset( $place['longitude'] ) ? (float) $place['longitude'] : 76.9366;

		$key   = $ymd . '|' . $lat . '|' . $lon;
		$solar = wp_cache_get( $key, 'aindram_vedic_calendar' );
		if ( ! is_array( $solar ) ) {
			$solar = Aindram_Vedic_Calendar_Astronomy::solar_date( (int) $m[1], (int) $m[2], (int) $m[3], $lat, $lon );
			wp_cache_set( $key, $solar, 'aindram_vedic_calendar', DAY_IN_SECONDS );
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
function aindram_vedic_calendar_date( $ymd = null ) {
	if ( null === $ymd ) {
		$ymd = get_post_time( 'Y-m-d' );
	}
	return is_string( $ymd ) ? Aindram_Vedic_Calendar::label( $ymd ) : '';
}
