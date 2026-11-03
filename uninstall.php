<?php
/**
 * Removes the plugin's settings when it is deleted.
 *
 * @package DheeVedicCalendar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $dhee_vedic_calendar_site ) {
		switch_to_blog( $dhee_vedic_calendar_site );
		delete_option( 'dhee_vedic_calendar' );
		restore_current_blog();
	}
} else {
	delete_option( 'dhee_vedic_calendar' );
}
