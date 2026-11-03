<?php
/**
 * Plugin Name:       Dhee Vedic Calendar
 * Description:       Shows the Vedic date below the date of every post and page: the Kerala solar month by its Sanskrit name, the day of the month, and the Kali Yuga year, which begins at Vishu.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.2
 * Author:            DheeYantra
 * Author URI:        https://dheeyantra.com/
 * License:           GPL-2.0-only
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       dhee-vedic-calendar
 * Domain Path:       /languages
 *
 * Copyright (C) 2026 DheeYantra Research Labs Private Limited.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 only, as
 * published by the Free Software Foundation. It is distributed WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See LICENSE.txt for the full licence.
 *
 * @package DheeVedicCalendar
 */

defined( 'ABSPATH' ) || exit;

define( 'DHEE_VEDIC_CALENDAR_VERSION', '1.0.0' );
define( 'DHEE_VEDIC_CALENDAR_FILE', __FILE__ );
define( 'DHEE_VEDIC_CALENDAR_DIR', plugin_dir_path( __FILE__ ) );

require_once DHEE_VEDIC_CALENDAR_DIR . 'includes/class-dhee-vedic-calendar-astronomy.php';
require_once DHEE_VEDIC_CALENDAR_DIR . 'includes/class-dhee-vedic-calendar.php';
require_once DHEE_VEDIC_CALENDAR_DIR . 'includes/class-dhee-vedic-calendar-settings.php';

Dhee_Vedic_Calendar::instance();

if ( is_admin() ) {
	Dhee_Vedic_Calendar_Settings::instance();
}
