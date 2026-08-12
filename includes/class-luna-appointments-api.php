<?php
/**
 * Stable public contract for integrations with Luna Appointments.
 *
 * Consumers must use this facade and its hooks instead of domain internals.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Luna_Appointments_API {
	const VERSION = '1.0';
	const EVENT_BOOKING_CREATED = 'luna_appointments_booking_created';
	const EVENT_BOOKING_UPDATED = 'luna_appointments_booking_updated';
	const EVENT_BOOKING_TRANSITION = 'luna_appointments_booking_status_transition';
	const EVENT_FINANCE_RELEASE = 'luna_appointments_release_booking_finance_commit';
	const EVENT_FINANCE_COMMITTED = 'luna_appointments_booking_finance_committed';
	const FILTER_FINANCE_QUOTE = 'luna_appointments_booking_finance_quote';
	const FILTER_FINANCE_PREPARE = 'luna_appointments_prepare_booking_finance_commit';
	const FILTER_FRONTEND_CONFIG = 'luna_appointments_booking_frontend_config';
	const EVENT_CONSULTATION_FINANCE_NOTIFICATION = 'luna_appointments_consultation_finance_notification';

	public static function get_booking($booking_id) {
		return Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
	}

	public static function get_booking_with_context($booking_id) {
		return Luna_Appointments_Bookings_Table::get_booking_with_context((int) $booking_id);
	}

	public static function booking_history($booking_id, $limit = 100) {
		return Luna_Appointments_Bookings_Table::get_booking_history((int) $booking_id, (int) $limit);
	}

	public static function booking_report($args = array()) {
		return Luna_Appointments_Bookings_Table::get_report_summary(is_array($args) ? $args : array());
	}

	public static function booking_status_label($status) {
		return Luna_Appointments_I18n::booking_status($status);
	}

	public static function payment_status_label($status) {
		return Luna_Appointments_I18n::payment_status($status);
	}

	public static function payment_method_label($method, $fallback = '') {
		return Luna_Appointments_I18n::payment_method($method, $fallback);
	}

	public static function query_specialist_bookings($specialist_id, $args = array()) {
		return Luna_Appointments_Bookings_Table::query_bookings_for_specialist((int) $specialist_id, is_array($args) ? $args : array());
	}

	public static function current_user_is_specialist() {
		return Luna_Appointments_Specialists::current_user_is_specialist();
	}

	public static function current_specialist_id() {
		return (int) Luna_Appointments_Specialists::get_current_user_specialist_id();
	}

	public static function specialist_user_id($specialist_id) {
		return (int) get_post_meta((int) $specialist_id, Luna_Appointments_Specialists::USER_LINK_META, true);
	}

	public static function specialist_payload($specialist_id) {
		return Luna_Appointments_Specialists::get_public_payload((int) $specialist_id);
	}

	public static function specialist_app_url() {
		return (string) Luna_Appointments_Specialist_PWA::app_url();
	}

	public static function specialist_frontend_url() {
		return (string) Luna_Appointments_Specialists::get_specialist_frontend_url();
	}

	public static function service_meta($service_id) {
		return Luna_Appointments_Services::get_service_meta_values((int) $service_id);
	}

	public static function consultation_finance_plan($service_id) {
		return class_exists('Luna_Appointments_Consultation_Finance') ? Luna_Appointments_Consultation_Finance::service_plan((int) $service_id) : array();
	}

	public static function consultation_finance_summary($booking_id) {
		return class_exists('Luna_Appointments_Consultation_Finance') ? Luna_Appointments_Consultation_Finance::summary((int) $booking_id) : array();
	}

	public static function is_booking_order_pay_context($order) {
		return Luna_Appointments_Bookings::is_booking_order_pay_context($order);
	}

	public static function booking_order_pay_gateway($order) {
		return Luna_Appointments_Bookings::get_booking_order_pay_gateway($order);
	}

	public static function should_auto_submit_booking_order_pay($order) {
		return Luna_Appointments_Bookings::should_auto_submit_booking_order_pay($order);
	}

	public static function is_available() {
		return true;
	}

	public static function settings() {
		$settings = apply_filters('luna_appointments_settings', array());
		return is_array($settings) ? $settings : array();
	}
}
