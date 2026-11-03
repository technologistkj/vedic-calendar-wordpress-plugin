=== Vedic Calendar ===
Contributors: aindram
Tags: calendar, vedic, kerala, malayalam, panchang
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Shows the Vedic date below the date of every post and page: the Kerala solar month by its Sanskrit name, the day, and the KaliYuga year.

== Description ==

Under the date your theme already shows on a post or page, Vedic Calendar adds a second line with the same day in the Vedic calendar:

> November 3, 2026
> Tula 17, KaliYuga 5127

* **Months** are Kerala's solar months, named by their signs in Sanskrit: Mesha, Vrishabha, Mithuna, Karkataka, Simha, Kanya, Tula, Vrishchika, Dhanu, Makara, Kumbha, Meena. Mesha is the month Kerala calls Medam, Simha is Chingam, and so on.
* **Years** are KaliYuga years. The year begins at Vishu, the first day of Mesha. The year that began at Vishu in April 2026 is KaliYuga 5127.

It works with block themes and classic themes, with no setup. Choose the post types and the wording in Settings > Vedic Calendar.

= How the date is reckoned =

A solar month begins when the Sun enters a sidereal sign, its sankranti. The plugin computes the Sun's position with the VSOP87 planetary theory and the Lahiri (Chitrapaksha) ayanamsa, the standard of the Indian national ephemeris.

Kerala's rule then fixes the first day of the month. The daytime, sunrise to sunset, is divided into five parts. If the sankranti falls within the first three, the month begins that day; otherwise it begins the next day. Sunrise and sunset are taken at Thiruvananthapuram.

= Accuracy =

Every month start from 1900 to 2100, 2,409 of them, was checked against Swiss Ephemeris with the same rule: all fall on the same day. Sankranti times agree within 12 seconds. The month starts also match the Malayalam calendar Kerala Tourism publishes for 2024-25.

Almanacs that use a different ayanamsa or their own reckoning of sunrise can differ by a day when a sankranti falls within minutes of the cut-off.

= Privacy =

The plugin sends nothing anywhere and stores nothing but its two settings. The dates are worked out on your server.

== Installation ==

1. Upload the `vedic-calendar` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New > Upload Plugin.
2. Activate it.
3. Optionally, visit Settings > Vedic Calendar to choose the post types and the format.

== Frequently Asked Questions ==

= My theme shows the date somewhere the line does not appear =

The line is added where the theme asks WordPress for a post's date inside the list of posts. A theme that builds its date some other way, or prints it outside that list, will not get it. Put `[vedic_date]` where you want it, or call `vedic_calendar_date()` in a template.

= Can I show the Vedic date of another day? =

`[vedic_date date="today"]` shows today's. `[vedic_date date="2026-04-14"]` shows that day's.

= Can I change the words? =

Settings > Vedic Calendar has a format field: `{month}`, `{day}` and `{year}` stand for the month, the day of the month and the KaliYuga year. The month names can be translated like any other text in the plugin. Developers can rewrite the whole line with the `vedic_calendar_label` filter.

= Can sunrise be taken somewhere other than Thiruvananthapuram? =

Yes, with the `vedic_calendar_location` filter, which takes an array with `latitude` and `longitude`. Anywhere in Kerala gives the same months on all but the rarest days.

= Does it work with caching plugins? =

Yes. The line is part of the page HTML, so a cached page carries it.

== Changelog ==

= 1.0.0 =
* First release.
