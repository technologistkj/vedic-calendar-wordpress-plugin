<?php
/**
 * Checks Aindram_Vedic_Calendar_Astronomy against the Swiss Ephemeris fixture and
 * the published Kerala calendar. Plain PHP, no WordPress:
 *
 *     docker run --rm -v "$PWD":/p -w /p php:7.2-cli php tests/test-astronomy.php
 *
 * For every month start in fixtures/month-starts.txt (1900-2100), that date must
 * be day 1 of that month in that Kali year, and the day before must be the last
 * day of the month before. Exits non-zero on any failure.
 *
 * @package AindramVedicCalendar
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-aindram-vedic-calendar-astronomy.php';

$lat      = 8.5241;
$lon      = 76.9366;
$failures = array();
$checked  = 0;
$started  = microtime( true );

$lines = file( __DIR__ . '/fixtures/month-starts.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$prev  = null;
foreach ( $lines as $line ) {
	if ( '#' === $line[0] ) {
		continue;
	}
	$f                = preg_split( '/\s+/', trim( $line ) );
	list( $y, $m, $d ) = array_map( 'intval', explode( '-', $f[0] ) );
	$sign             = (int) $f[1];
	$kali             = (int) $f[2];

	$got = Aindram_Vedic_Calendar_Astronomy::solar_date( $y, $m, $d, $lat, $lon );
	++$checked;
	if ( 1 !== $got['day'] || $sign !== $got['month'] || $kali !== $got['year'] ) {
		$failures[] = sprintf( '%s: want sign %d day 1 Kali %d, got sign %d day %d Kali %d (cutoff margin %s min)', $f[0], $sign, $kali, $got['month'], $got['day'], $got['year'], $f[4] );
	}

	// The day before closes the previous month, whose length the fixture gives.
	if ( null !== $prev ) {
		$jd     = Aindram_Vedic_Calendar_Astronomy::jd_from_ymd( $y, $m, $d ) - 1;
		$before = Aindram_Vedic_Calendar_Astronomy::ymd_from_jd( $jd );
		$length = (int) round( $jd - $prev['jd'] ) + 1;
		$got    = Aindram_Vedic_Calendar_Astronomy::solar_date( $before[0], $before[1], $before[2], $lat, $lon );
		++$checked;
		if ( $got['day'] !== $length || $got['month'] !== $prev['sign'] || $got['year'] !== $prev['kali'] ) {
			$failures[] = sprintf( '%04d-%02d-%02d: want sign %d day %d Kali %d, got sign %d day %d Kali %d', $before[0], $before[1], $before[2], $prev['sign'], $length, $prev['kali'], $got['month'], $got['day'], $got['year'] );
		}
		if ( $length < 29 || $length > 32 ) {
			$failures[] = sprintf( 'month starting %s has %d days', $prev['date'], $length );
		}
	}
	$prev = array(
		'date' => $f[0],
		'jd'   => Aindram_Vedic_Calendar_Astronomy::jd_from_ymd( $y, $m, $d ),
		'sign' => $sign,
		'kali' => $kali,
	);
}
$elapsed = microtime( true ) - $started;

foreach ( file( __DIR__ . '/fixtures/published.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	if ( '#' === $line[0] ) {
		continue;
	}
	list( $date, $sign, $kali ) = preg_split( '/\s+/', trim( $line ) );
	list( $y, $m, $d )          = array_map( 'intval', explode( '-', $date ) );
	$got                        = Aindram_Vedic_Calendar_Astronomy::solar_date( $y, $m, $d, $lat, $lon );
	++$checked;
	if ( 1 !== $got['day'] || (int) $sign !== $got['month'] || (int) $kali !== $got['year'] ) {
		$failures[] = sprintf( 'published %s: want sign %d day 1 Kali %d, got sign %d day %d Kali %d', $date, $sign, $kali, $got['month'], $got['day'], $got['year'] );
	}
}

// Round trip of the calendar arithmetic over 1800-2200.
for ( $jd = 2378496.5; $jd < 2524593.5; $jd += 1 ) {
	list( $y, $m, $d ) = Aindram_Vedic_Calendar_Astronomy::ymd_from_jd( $jd );
	if ( Aindram_Vedic_Calendar_Astronomy::jd_from_ymd( $y, $m, $d ) !== $jd || ! checkdate( $m, $d, $y ) ) {
		$failures[] = "date round trip fails at JD $jd";
		break;
	}
}

$today = Aindram_Vedic_Calendar_Astronomy::solar_date( 2026, 11, 3, $lat, $lon );
printf( "PHP %s: %d dates checked, %d failures, %.2f ms per date; 2026-11-03 = sign %d day %d Kali %d\n", PHP_VERSION, $checked, count( $failures ), 1000 * $elapsed / max( 1, $checked - 17 ), $today['month'], $today['day'], $today['year'] );
foreach ( array_slice( $failures, 0, 20 ) as $failure ) {
	echo '  FAIL ', $failure, "\n";
}
exit( $failures ? 1 : 0 );
