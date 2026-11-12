<?php
/**
 * The arithmetic: which Kerala solar month and day a Gregorian date falls on,
 * and which KaliYuga year.
 *
 * A solar month begins when the Sun enters a sidereal sign (a sankranti). The
 * Sun's position comes from VSOP87, referred to the stars with the Lahiri
 * (Chitrapaksha) ayanamsa. The Kerala rule then decides the first day: divide
 * the daytime, sunrise to sunset, into five parts; if the sankranti falls
 * within the first three, the month begins that day, otherwise the next day.
 * A sankranti between midnight and sunrise falls in the night of the day
 * before, after its three fifths, so that month begins on the civil date of
 * the sankranti.
 *
 * The KaliYuga year changes at Vishu, the start of Mesha. The year that began
 * at Vishu in April 2026 is Kali 5127.
 *
 * Checked against Swiss Ephemeris (Lahiri) for every sankranti from 1900 to
 * 2600: sankranti times within 4 seconds, the first-day cutoff within half a
 * second, and all 8,409 month starts on the same day. See tests/.
 *
 * @package AindramVedicCalendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions; no WordPress calls, so the tests can run them in plain PHP.
 */
final class Aindram_Vedic_Calendar_Astronomy {

	/**
	 * Kerala's clock, UTC+5:30, as a fraction of a day.
	 */
	const IST = 0.22916666666666666;

	/**
	 * Mean daily motion of the Sun, in degrees.
	 */
	const SUN_RATE = 0.9856;

	/**
	 * VSOP87 terms, loaded on first use.
	 *
	 * @var array|null
	 */
	private static $vsop = null;

	/**
	 * Delta T table, loaded on first use.
	 *
	 * @var array|null
	 */
	private static $delta_t = null;

	/**
	 * The solar date for a Gregorian date.
	 *
	 * @param int   $year      Gregorian year.
	 * @param int   $month     1-12.
	 * @param int   $day       1-31.
	 * @param float $latitude  Where sunrise and sunset are taken, degrees north.
	 * @param float $longitude Degrees east.
	 * @return array {
	 *     @type int    $year        KaliYuga year.
	 *     @type int    $month       0 for Mesha through 11 for Meena.
	 *     @type int    $day         Day of the solar month, from 1.
	 *     @type string $month_start Y-m-d of that month's first day.
	 * }
	 */
	public static function solar_date( $year, $month, $day, $latitude, $longitude ) {
		$date   = self::jd_from_ymd( $year, $month, $day );
		$cutoff = self::cutoff( $date, $latitude, $longitude );

		// Whatever sign the Sun is in at this day's cutoff is the month in force:
		// it entered that sign at or before the cutoff, so its month began on or
		// before this date.
		$sign      = (int) floor( self::sidereal_sun( $cutoff ) / 30 );
		$sankranti = self::sankranti_before( $cutoff, $sign );
		$start     = self::ist_date( $sankranti );
		if ( $sankranti > self::cutoff( $start, $latitude, $longitude ) ) {
			++$start;
		}

		// Mesha begins between mid- and late April from 1900 to 2700 (it moves a
		// day every 70 years or so), so stepping back $sign months from this
		// month's start lands in that April, well clear of New Year.
		$mesha = self::ymd_from_jd( $start - $sign * 30.4368 );

		return array(
			'year'        => $mesha[0] + 3101,
			'month'       => $sign,
			'day'         => (int) round( $date - $start ) + 1,
			'month_start' => vsprintf( '%04d-%02d-%02d', self::ymd_from_jd( $start ) ),
		);
	}

	/**
	 * The moment the Sun's sidereal longitude last reached 30 * $sign degrees,
	 * at or before $jd.
	 *
	 * @param float $jd   Julian day, UT.
	 * @param int   $sign 0-11.
	 * @return float Julian day, UT.
	 */
	public static function sankranti_before( $jd, $sign ) {
		$target = 30 * $sign;
		$t      = $jd - self::wrap360( self::sidereal_sun( $jd ) - $target ) / self::SUN_RATE;
		for ( $i = 0; $i < 8; $i++ ) {
			$off = self::wrap180( self::sidereal_sun( $t ) - $target );
			$t  -= $off / self::SUN_RATE;
			if ( abs( $off ) < 1e-7 ) {
				break;
			}
		}
		return $t;
	}

	/**
	 * The end of the first three fifths of daytime on a civil date in Kerala.
	 *
	 * @param float $date      Julian day at 0h UT of the date.
	 * @param float $latitude  Degrees north.
	 * @param float $longitude Degrees east.
	 * @return float Julian day, UT.
	 */
	public static function cutoff( $date, $latitude, $longitude ) {
		list( $rise, $set ) = self::rise_set( $date, $latitude, $longitude );
		return $rise + 0.6 * ( $set - $rise );
	}

	/**
	 * Sunrise and sunset: the upper limb on the horizon, with the refraction
	 * Swiss Ephemeris applies at 1013.25 hPa and 27 C (31.29') and the Sun's
	 * semidiameter that day (15.73' to 16.27'), as the almanacs reckon it.
	 *
	 * @param float $date      Julian day at 0h UT of the date. For India the
	 *                         UT date and the local date agree at local noon.
	 * @param float $latitude  Degrees north.
	 * @param float $longitude Degrees east.
	 * @return float[] array( sunrise, sunset ), Julian days UT.
	 */
	public static function rise_set( $date, $latitude, $longitude ) {
		$phi = deg2rad( $latitude );
		$out = array();
		foreach ( array( -1, 1 ) as $side ) {
			$t = $date + 0.5 - $longitude / 360;
			for ( $i = 0; $i < 3; $i++ ) {
				list( $dec, $eot, $r ) = self::sun_low( $t );
				$d     = deg2rad( $dec );
				$h0    = deg2rad( -( 31.29 + 15.994 / $r ) / 60 );
				$cos_h = ( sin( $h0 ) - sin( $phi ) * sin( $d ) ) / ( cos( $phi ) * cos( $d ) );
				$h     = rad2deg( acos( max( -1.0, min( 1.0, $cos_h ) ) ) );
				$t     = $date + ( 720 - 4 * $longitude - $eot + $side * 4 * $h ) / 1440;
			}
			$out[] = $t;
		}
		return $out;
	}

	/**
	 * The Sun's apparent declination and the equation of time, from the same
	 * longitude the months are reckoned with, plus nutation (Meeus, Astronomical
	 * Algorithms, ch. 22, 25 and 28).
	 *
	 * @param float $jd Julian day, UT.
	 * @return float[] array( declination in degrees, equation of time in minutes,
	 *                 distance in astronomical units ).
	 */
	public static function sun_low( $jd ) {
		$tau   = ( $jd + self::delta_t( 2000 + ( $jd - 2451545 ) / 365.25 ) / 86400 - 2451545 ) / 365250;
		$t     = 10 * $tau;
		$l0    = 280.4664567 + ( ( ( ( -$tau / 2000000 - 1 / 15300 ) * $tau + 1 / 49931 ) * $tau + 0.03032028 ) * $tau + 360007.6982779 ) * $tau;
		$m     = deg2rad( 357.52911 + 35999.05029 * $t );
		$omega = deg2rad( 125.04452 - 1934.136261 * $t );
		$ls    = deg2rad( 2 * ( 280.4665 + 36000.7698 * $t ) );
		$lm    = deg2rad( 2 * ( 218.3165 + 481267.8813 * $t ) );
		$dpsi  = ( -17.20 * sin( $omega ) - 1.32 * sin( $ls ) - 0.23 * sin( $lm ) + 0.21 * sin( 2 * $omega ) ) / 3600;
		$deps  = ( 9.20 * cos( $omega ) + 0.57 * cos( $ls ) + 0.10 * cos( $lm ) - 0.09 * cos( 2 * $omega ) ) / 3600;
		$eps   = deg2rad( 23.439291 - 0.0130042 * $t + $deps );
		$lam   = deg2rad( self::tropical_sun( $jd ) + $dpsi );
		$ra    = self::wrap360( rad2deg( atan2( cos( $eps ) * sin( $lam ), cos( $lam ) ) ) );
		$dec   = rad2deg( asin( sin( $eps ) * sin( $lam ) ) );
		$r     = 1.000140 - 0.016708 * cos( $m ) - 0.000141 * cos( 2 * $m );
		return array( $dec, 4 * self::wrap180( $l0 - 0.0057183 - $ra + $dpsi * cos( $eps ) ), $r );
	}

	/**
	 * The Sun's sidereal longitude, Lahiri ayanamsa.
	 *
	 * Nutation is left out on both sides: it moves the equinox and the ayanamsa
	 * alike, so the sidereal longitude does not contain it.
	 *
	 * @param float $jd Julian day, UT.
	 * @return float Degrees, 0 to 360.
	 */
	public static function sidereal_sun( $jd ) {
		$jde = $jd + self::delta_t( 2000 + ( $jd - 2451545 ) / 365.25 ) / 86400;
		return self::wrap360( self::tropical_sun( $jd ) - self::lahiri( $jde ) );
	}

	/**
	 * The Sun's geocentric longitude from the mean equinox of date (IAU 2006),
	 * with aberration, without nutation.
	 *
	 * @param float $jd Julian day, UT.
	 * @return float Degrees, 0 to 360.
	 */
	public static function tropical_sun( $jd ) {
		if ( null === self::$vsop ) {
			self::$vsop = require __DIR__ . '/vsop87-earth-l.php';
		}
		$jde = $jd + self::delta_t( 2000 + ( $jd - 2451545 ) / 365.25 ) / 86400;
		$tau = ( $jde - 2451545 ) / 365250;

		$l   = 0.0;
		$pow = 1.0;
		foreach ( self::$vsop as $terms ) {
			$sum = 0.0;
			foreach ( $terms as $term ) {
				$sum += $term[0] * cos( $term[1] + $term[2] * $tau );
			}
			$l   += $sum * $pow;
			$pow *= $tau;
		}

		// Geocentric, then the FK5 correction and aberration (20.4898" / R).
		$m      = deg2rad( 357.52911 + 359990.5029 * $tau );
		$r      = 1.000140 - 0.016708 * cos( $m ) - 0.000141 * cos( 2 * $m );
		$lambda = rad2deg( $l * 1e-8 ) + 180 + ( -0.09033 - 20.4898 / $r ) / 3600;

		// VSOP87D's equinox of date moves with Lieske's (1977) precession, the
		// ayanamsa with IAU 2006's, which is 0.3" a century slower. Without this
		// the two drift apart: two arcseconds, a minute of sankranti, by 2600.
		$t       = $tau * 10;
		$lambda += ( self::precession( $t ) - ( ( -0.000006 * $t + 1.11113 ) * $t + 5029.0966 ) * $t ) / 3600;

		return self::wrap360( $lambda );
	}

	/**
	 * The mean Lahiri ayanamsa: 23 deg 14' 44.02" at 1956 March 21, 0h TT, the
	 * Indian Calendar Reform Committee's value as Swiss Ephemeris 2.10 gives it
	 * without nutation, carried by the IAU 2006 general precession in longitude.
	 *
	 * @param float $jde Julian ephemeris day.
	 * @return float Degrees.
	 */
	public static function lahiri( $jde ) {
		$t  = ( $jde - 2451545 ) / 36525;
		$t0 = ( 2435553.5 - 2451545 ) / 36525;
		return 23.245560955 + ( self::precession( $t ) - self::precession( $t0 ) ) / 3600;
	}

	/**
	 * General precession in longitude from J2000, arcseconds (IAU 2006).
	 *
	 * @param float $t Julian centuries from J2000, TT.
	 * @return float
	 */
	private static function precession( $t ) {
		return ( ( ( -0.000023857 * $t + 0.00007964 ) * $t + 1.1054348 ) * $t + 5028.796195 ) * $t;
	}

	/**
	 * Delta T (TT - UT) in seconds: Swiss Ephemeris's values, every two years
	 * from 1800 to 2700 (includes/delta-t.php), interpolated linearly, within
	 * 0.2 s of Swiss Ephemeris. Beyond 2700 and before 1800, the parabola it
	 * follows there. The future values are Swiss Ephemeris's extrapolation of the
	 * Earth's rotation, which is what almanacs built on it use.
	 *
	 * @param float $y Decimal year.
	 * @return float
	 */
	public static function delta_t( $y ) {
		if ( null === self::$delta_t ) {
			self::$delta_t = require __DIR__ . '/delta-t.php';
		}
		$first  = self::$delta_t['first'];
		$step   = self::$delta_t['step'];
		$values = self::$delta_t['values'];
		$n      = count( $values ) - 1;
		$last   = $first + $step * $n;
		if ( $y >= $last ) {
			$u = $y - $last;
			return $values[ $n ] + ( self::$delta_t['after'][0] + self::$delta_t['after'][1] * $u ) * $u;
		}
		if ( $y <= $first ) {
			$u = $first - $y;
			return $values[0] + ( self::$delta_t['before'][0] + self::$delta_t['before'][1] * $u ) * $u;
		}
		$x = ( $y - $first ) / $step;
		$i = min( (int) floor( $x ), $n - 1 );
		return $values[ $i ] + ( $values[ $i + 1 ] - $values[ $i ] ) * ( $x - $i );
	}

	/**
	 * The Kerala civil date a moment falls on.
	 *
	 * @param float $jd Julian day, UT.
	 * @return float Julian day at 0h UT of that date.
	 */
	public static function ist_date( $jd ) {
		return floor( $jd + self::IST + 0.5 ) - 0.5;
	}

	/**
	 * Julian day at 0h UT of a proleptic Gregorian date.
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @param int $d Day.
	 * @return float
	 */
	public static function jd_from_ymd( $y, $m, $d ) {
		if ( $m <= 2 ) {
			--$y;
			$m += 12;
		}
		$a = (int) floor( $y / 100 );
		$b = 2 - $a + (int) floor( $a / 4 );
		return floor( 365.25 * ( $y + 4716 ) ) + floor( 30.6001 * ( $m + 1 ) ) + $d + $b - 1524.5;
	}

	/**
	 * The proleptic Gregorian date of a Julian day.
	 *
	 * @param float $jd Julian day.
	 * @return int[] array( year, month, day ).
	 */
	public static function ymd_from_jd( $jd ) {
		$z     = (int) floor( $jd + 0.5 );
		$alpha = (int) floor( ( $z - 1867216.25 ) / 36524.25 );
		$a     = $z + 1 + $alpha - (int) floor( $alpha / 4 );
		$b     = $a + 1524;
		$c     = (int) floor( ( $b - 122.1 ) / 365.25 );
		$d     = (int) floor( 365.25 * $c );
		$e     = (int) floor( ( $b - $d ) / 30.6001 );
		$day   = $b - $d - (int) floor( 30.6001 * $e );
		$month = $e < 14 ? $e - 1 : $e - 13;
		$year  = $month > 2 ? $c - 4716 : $c - 4715;
		return array( $year, $month, $day );
	}

	/**
	 * An angle in [0, 360).
	 *
	 * @param float $deg Degrees.
	 * @return float
	 */
	private static function wrap360( $deg ) {
		$deg = fmod( $deg, 360 );
		return $deg < 0 ? $deg + 360 : $deg;
	}

	/**
	 * An angle in [-180, 180).
	 *
	 * @param float $deg Degrees.
	 * @return float
	 */
	private static function wrap180( $deg ) {
		return self::wrap360( $deg + 180 ) - 180;
	}
}
