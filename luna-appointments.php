<?php
/**
 * Plugin Name: Luna Appointments
 * Plugin URI: https://rocketzi.com
 * Description: Independent appointment, service, specialist, aftercare, and specialist-app domain for Luna.
 * Version: 1.3.3
 * Author: Soran
 * Text Domain: luna-appointments
 * Requires Plugins: woocommerce
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

define('LUNA_APPOINTMENTS_VERSION', '1.3.3');
define('LUNA_APPOINTMENTS_PATH', plugin_dir_path(__FILE__));
define('LUNA_APPOINTMENTS_URL', plugin_dir_url(__FILE__));

require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-i18n.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-date.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-specialists.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-services.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-service-packages.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-bookings-table.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-bookings.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-bookings-admin.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-care-plans.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-specialist-pwa.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-api.php';
require_once LUNA_APPOINTMENTS_PATH . 'includes/class-luna-appointments-compat.php';

/**
 * Boot the appointment domain exactly once.
 */
function luna_appointments_boot() {
	static $booted = false;
	if ($booted) {
		return;
	}
	$booted = true;
	load_plugin_textdomain('luna-appointments', false, dirname(plugin_basename(__FILE__)) . '/languages');

	Luna_Appointments_Specialists::boot();
	Luna_Appointments_Services::boot();
	Luna_Appointments_Service_Packages::boot();
	Luna_Appointments_Bookings_Table::boot();
	Luna_Appointments_Bookings::boot();
	Luna_Appointments_Care_Plans::boot();
	Luna_Appointments_Specialist_PWA::boot();
	do_action('luna_appointments_ready', Luna_Appointments_API::VERSION);
}
add_action('plugins_loaded', 'luna_appointments_boot', 5);

/**
 * Install the domain schema without touching existing booking records.
 */
function luna_appointments_activate() {
	Luna_Appointments_Bookings_Table::install();
	Luna_Appointments_Specialists::register_specialist_role();
	Luna_Appointments_Service_Packages::register_post_types();
	flush_rewrite_rules(false);
}
register_activation_hook(__FILE__, 'luna_appointments_activate');

function luna_appointments_deactivate() {
	$timestamp = wp_next_scheduled('luna_appointments_repair_interrupted_bookings');
	while ($timestamp) {
		wp_unschedule_event($timestamp, 'luna_appointments_repair_interrupted_bookings');
		$timestamp = wp_next_scheduled('luna_appointments_repair_interrupted_bookings');
	}
}
register_deactivation_hook(__FILE__, 'luna_appointments_deactivate');
