<?php
/** Temporary class aliases for integrations written before Appointments 1.1. */
if (! defined('ABSPATH')) {
	exit;
}

$luna_appointments_legacy_classes = array(
	'Luna_Builder_Specialists'    => 'Luna_Appointments_Specialists',
	'Luna_Builder_Services'       => 'Luna_Appointments_Services',
	'Luna_Builder_Bookings_Table' => 'Luna_Appointments_Bookings_Table',
	'Luna_Builder_Bookings'       => 'Luna_Appointments_Bookings',
	'Luna_Builder_Bookings_Admin' => 'Luna_Appointments_Bookings_Admin',
	'Luna_Builder_Care_Plans'     => 'Luna_Appointments_Care_Plans',
	'Luna_Builder_Specialist_PWA' => 'Luna_Appointments_Specialist_PWA',
);

foreach ($luna_appointments_legacy_classes as $legacy => $current) {
	if (class_exists($current, false) && ! class_exists($legacy, false)) {
		class_alias($current, $legacy);
	}
}
unset($luna_appointments_legacy_classes, $legacy, $current);

// Forward the new domain events to old hook names for third-party compatibility.
$luna_appointments_legacy_actions = array(
	'luna_appointments_booking_created'                => 'luna_builder_booking_created',
	'luna_appointments_booking_updated'                => 'luna_builder_booking_updated',
	'luna_appointments_booking_status_transition'      => 'luna_builder_booking_status_transition',
	'luna_appointments_release_booking_finance_commit' => 'luna_builder_release_booking_finance_commit',
	'luna_appointments_booking_finance_committed'      => 'luna_builder_booking_finance_committed',
);
foreach ($luna_appointments_legacy_actions as $current_hook => $legacy_hook) {
	add_action($current_hook, static function (...$args) use ($legacy_hook) {
		do_action_ref_array($legacy_hook, $args);
	}, PHP_INT_MAX, 20);
}

$luna_appointments_legacy_filters = array(
	'luna_appointments_booking_frontend_config'       => 'luna_builder_booking_frontend_config',
	'luna_appointments_booking_finance_quote'         => 'luna_builder_booking_finance_quote',
	'luna_appointments_prepare_booking_finance_commit' => 'luna_builder_prepare_booking_finance_commit',
);
foreach ($luna_appointments_legacy_filters as $current_hook => $legacy_hook) {
	add_filter($current_hook, static function ($value, ...$args) use ($legacy_hook) {
		return apply_filters_ref_array($legacy_hook, array_merge(array($value), $args));
	}, PHP_INT_MAX, 20);
}
unset($luna_appointments_legacy_actions, $luna_appointments_legacy_filters, $current_hook, $legacy_hook);
