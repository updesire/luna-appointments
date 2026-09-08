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

	/**
	 * Return the native WooCommerce BACS instructions and accounts markup.
	 *
	 * Custom checkout renderers do not execute WooCommerce's thank-you hooks,
	 * so consumers can use this public method through Luna_Appointments_API.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_thankyou_markup($order) {
		if (! $order instanceof WC_Order || 'bacs' !== (string) $order->get_payment_method()) {
			return '';
		}

		$gateway = self::get_gateway();
		if (! $gateway instanceof WC_Payment_Gateway || ! method_exists($gateway, 'thankyou_page')) {
			return '';
		}

		add_filter('woocommerce_bacs_account_fields', array(__CLASS__, 'localize_account_fields'), 20, 2);
		ob_start();
		$gateway->thankyou_page($order->get_id());
		$markup = (string) ob_get_clean();
		remove_filter('woocommerce_bacs_account_fields', array(__CLASS__, 'localize_account_fields'), 20);

		return $markup;
	}

	/** Localize the important Iranian transfer fields. */
	public static function localize_account_fields($fields, $order_id) {
		unset($order_id);
		if (! is_array($fields)) {
			return $fields;
		}

		if (isset($fields['bank_name'])) {
			$fields['bank_name']['label'] = __('نام بانک', 'luna-appointments');
		}
		if (isset($fields['account_number'])) {
			$fields['account_number']['label'] = __('شماره کارت / حساب', 'luna-appointments');
		}
		if (isset($fields['sort_code'])) {
			$fields['sort_code']['label'] = __('کد شعبه', 'luna-appointments');
		}
		if (isset($fields['iban'])) {
			$fields['iban']['label'] = __('شماره شبا', 'luna-appointments');
		}
		if (isset($fields['bic'])) {
			$fields['bic']['label'] = __('کد بانکی', 'luna-appointments');
		}

		return $fields;
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
