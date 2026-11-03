<?php
/**
 * Plugin Name:       Vedic Calendar
 * Plugin URI:        https://github.com/technologistkj/vedic-calendar-wordpress-plugin
 * Description:       Shows the Vedic date below the date of every post and page: the Kerala solar month by its Sanskrit name, the day of the month, and the KaliYuga year, which begins at Vishu.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.2
 * Author:            Aindram
 * Author URI:        https://profiles.wordpress.org/aindram/
 * License:           GPL-2.0-only
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       vedic-calendar
 *
 * Copyright (C) 2026 Aindram.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 only, as
 * published by the Free Software Foundation. It is distributed WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See LICENSE.txt for the full licence.
 *
 * @package VedicCalendar
 */

defined( 'ABSPATH' ) || exit;

define( 'VEDIC_CALENDAR_VERSION', '1.0.0' );
define( 'VEDIC_CALENDAR_FILE', __FILE__ );
define( 'VEDIC_CALENDAR_DIR', plugin_dir_path( __FILE__ ) );

require_once VEDIC_CALENDAR_DIR . 'includes/class-vedic-calendar-astronomy.php';
require_once VEDIC_CALENDAR_DIR . 'includes/class-vedic-calendar.php';
require_once VEDIC_CALENDAR_DIR . 'includes/class-vedic-calendar-settings.php';

Vedic_Calendar::instance();

if ( is_admin() ) {
	Vedic_Calendar_Settings::instance();
}
