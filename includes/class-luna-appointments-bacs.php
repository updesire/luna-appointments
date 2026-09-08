<?php
/**
 * WooCommerce direct bank transfer integration for booking orders.
 *
 * Booking orders are created through Ajax and can reach the order-received
 * endpoint without WooCommerce having instantiated the BACS gateway during
 * that request. In that case WooCommerce's own thank-you callback is absent
 * and the saved bank accounts are never rendered.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Luna_Appointments_BACS {
	/** Register the lightweight order-received integration. */
	public static function boot() {
		add_action('woocommerce_before_thankyou', array(__CLASS__, 'prepare_thankyou_details'), 5);
	}

	/**
	 * Ensure WooCommerce's native BACS thank-you renderer is registered.
	 *
	 * The native gateway remains responsible for reading and escaping the
	 * account name, account number, bank, IBAN and BIC settings.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function prepare_thankyou_details($order_id) {
		if (! function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order((int) $order_id);
		if (! $order instanceof WC_Order || 'bacs' !== (string) $order->get_payment_method()) {
			return;
		}

		// Limit the workaround to orders created by Luna Appointments.
		if ((int) $order->get_meta('_luna_booking_id', true) <= 0) {
			return;
		}

		$gateway = self::get_gateway();
		if (! $gateway instanceof WC_Payment_Gateway) {
			return;
		}

		// Constructors normally add this callback. Register it only when the
		// order-received request did not initialize the gateway early enough.
		if (! has_action('woocommerce_thankyou_bacs', array($gateway, 'thankyou_page'))) {
			add_action('woocommerce_thankyou_bacs', array($gateway, 'thankyou_page'));
		}
	}

	/** Return the configured WooCommerce BACS gateway instance. */
	private static function get_gateway() {
		if (! function_exists('WC') || ! WC() || ! method_exists(WC(), 'payment_gateways')) {
			return null;
		}

		$manager  = WC()->payment_gateways();
		$gateways = $manager && method_exists($manager, 'payment_gateways')
			? (array) $manager->payment_gateways()
			: array();

		return isset($gateways['bacs']) && $gateways['bacs'] instanceof WC_Payment_Gateway
			? $gateways['bacs']
			: null;
	}
}
