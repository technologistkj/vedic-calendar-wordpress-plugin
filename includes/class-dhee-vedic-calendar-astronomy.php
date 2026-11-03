<?php
/**
 * The arithmetic: which Kerala solar month and day a Gregorian date falls on,
 * and which Kali Yuga year.
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
 * The Kali Yuga year changes at Vishu, the start of Mesha. The year that began
 * at Vishu in April 2026 is Kali 5127.
 *
 * Checked against Swiss Ephemeris (Lahiri) for every sankranti from 1900 to
 * 2100: sankranti times within 12 seconds, sunrise and sunset within 16
 * seconds, and all 2,412 month starts on the same day. See tests/.
 *
 * @package DheeVedicCalendar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions; no WordPress calls, so the tests can run them in plain PHP.
 */
final class Dhee_Vedic_Calendar_Astronomy {

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
	 * The solar date for a Gregorian date.
	 *
	 * @param int   $year      Gregorian year.
	 * @param int   $month     1-12.
	 * @param int   $day       1-31.
	 * @param float $latitude  Where sunrise and sunset are taken, degrees north.
	 * @param float $longitude Degrees east.
	 * @return array {
	 *     @type int    $year        Kali Yuga year.
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

		// Mesha begins in mid-April in every century this plugin will meet, so
		// stepping back $sign months from this month's start lands in that
		// April, well clear of New Year.
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
	 * Sunrise and sunset, upper limb on the horizon with standard refraction.
	 *
	 * @param float $date      Julian day at 0h UT of the date. For India the
	 *                         UT date and the local date agree at local noon.
	 * @param float $latitude  Degrees north.
	 * @param float $longitude Degrees east.
	 * @return float[] array( sunrise, sunset ), Julian days UT.
	 */
	public static function rise_set( $date, $latitude, $longitude ) {
		$h0  = deg2rad( -0.8333 );
		$phi = deg2rad( $latitude );
		$out = array();
		foreach ( array( -1, 1 ) as $side ) {
			$t = $date + 0.5 - $longitude / 360;
			for ( $i = 0; $i < 3; $i++ ) {
				list( $dec, $eot ) = self::sun_low( $t );
				$d     = deg2rad( $dec );
				$cos_h = ( sin( $h0 ) - sin( $phi ) * sin( $d ) ) / ( cos( $phi ) * cos( $d ) );
				$h     = rad2deg( acos( max( -1.0, min( 1.0, $cos_h ) ) ) );
				$t     = $date + ( 720 - 4 * $longitude - $eot + $side * 4 * $h ) / 1440;
			}
			$out[] = $t;
		}
		return $out;
	}

	/**
	 * The Sun's apparent declination and the equation of time, to the
	 * precision sunrise needs (Meeus, Astronomical Algorithms, ch. 25).
	 *
	 * @param float $jd Julian day.
	 * @return float[] array( declination in degrees, equation of time in minutes ).
	 */
	public static function sun_low( $jd ) {
		$t     = ( $jd - 2451545 ) / 36525;
		$l0    = fmod( 280.46646 + 36000.76983 * $t, 360 );
		$m     = deg2rad( 357.52911 + 35999.05029 * $t );
		$c     = ( 1.914602 - 0.004817 * $t ) * sin( $m ) + 0.019993 * sin( 2 * $m ) + 0.000289 * sin( 3 * $m );
		$omega = deg2rad( 125.04 - 1934.136 * $t );
		$lam   = deg2rad( $l0 + $c - 0.00569 - 0.00478 * sin( $omega ) );
		$eps   = deg2rad( 23.439291 - 0.0130042 * $t + 0.00256 * cos( $omega ) );
		$ra    = self::wrap360( rad2deg( atan2( cos( $eps ) * sin( $lam ), cos( $lam ) ) ) );
		$dec   = rad2deg( asin( sin( $eps ) * sin( $lam ) ) );
		return array( $dec, 4 * self::wrap180( $l0 - 0.0057183 - $ra ) );
	}

	/**
	 * The Sun's sidereal longitude, Lahiri ayanamsa.
	 *
	 * Measured from the mean equinox of date, with aberration. Nutation is left
	 * out on both sides: it moves the equinox and the ayanamsa alike, so the
	 * sidereal longitude does not contain it.
	 *
	 * @param float $jd Julian day, UT.
	 * @return float Degrees, 0 to 360.
	 */
	public static function sidereal_sun( $jd ) {
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

		return self::wrap360( $lambda - self::lahiri( $jde ) );
	}

	/**
	 * The mean Lahiri ayanamsa: 23 deg 14' 43.89" at 1956 March 21, 0h TT (the
	 * Indian Calendar Reform Committee's value, as Swiss Ephemeris holds it),
	 * carried by the IAU 2006 general precession in longitude.
	 *
	 * @param float $jde Julian ephemeris day.
	 * @return float Degrees.
	 */
	public static function lahiri( $jde ) {
		$t  = ( $jde - 2451545 ) / 36525;
		$t0 = ( 2435553.5 - 2451545 ) / 36525;
		return 23.245524743 + ( self::precession( $t ) - self::precession( $t0 ) ) / 3600;
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
	 * Delta T (TT - UT) in seconds. Espenak and Meeus up to 2005; after that a
	 * fit to the values Swiss Ephemeris extrapolates, which follow the Earth's
	 * rotation as observed into the 2020s (within 1.8 s to 2200). Their 2005-2050
	 * polynomial overshoots by over a minute by 2050, which would move a
	 * sankranti by a minute.
	 *
	 * @param float $y Decimal year.
	 * @return float
	 */
	public static function delta_t( $y ) {
		if ( $y >= 2005 ) {
			$t = $y - 2000;
			return 66.1233 + 0.0597325 * $t + 0.00209369 * $t * $t;
		}
		if ( $y >= 1986 ) {
			$t = $y - 2000;
			return 63.86 + 0.3345 * $t - 0.060374 * $t * $t + 0.0017275 * pow( $t, 3 ) + 0.000651814 * pow( $t, 4 ) + 0.00002373599 * pow( $t, 5 );
		}
		if ( $y >= 1961 ) {
			$t = $y - 1975;
			return 45.45 + 1.067 * $t - $t * $t / 260 - pow( $t, 3 ) / 718;
		}
		if ( $y >= 1941 ) {
			$t = $y - 1950;
			return 29.07 + 0.407 * $t - $t * $t / 233 + pow( $t, 3 ) / 2547;
		}
		if ( $y >= 1920 ) {
			$t = $y - 1920;
			return 21.20 + 0.84493 * $t - 0.076100 * $t * $t + 0.0020936 * pow( $t, 3 );
		}
		if ( $y >= 1900 ) {
			$t = $y - 1900;
			return -2.79 + 1.494119 * $t - 0.0598939 * $t * $t + 0.0061966 * pow( $t, 3 ) - 0.000197 * pow( $t, 4 );
		}
		if ( $y >= 1860 ) {
			$t = $y - 1860;
			return 7.62 + 0.5737 * $t - 0.251754 * $t * $t + 0.01680668 * pow( $t, 3 ) - 0.0004473624 * pow( $t, 4 ) + pow( $t, 5 ) / 233174;
		}
		if ( $y >= 1800 ) {
			$t = $y - 1800;
			return 13.72 - 0.332447 * $t + 0.0068612 * $t * $t + 0.0041116 * pow( $t, 3 ) - 0.00037436 * pow( $t, 4 ) + 0.0000121272 * pow( $t, 5 ) - 0.0000001699 * pow( $t, 6 ) + 0.000000000875 * pow( $t, 7 );
		}
		$u = ( $y - 1820 ) / 100;
		return -20 + 32 * $u * $u;
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
