<?php
/** Persian labels for all public appointment-domain values. */
if (! defined('ABSPATH')) exit;

final class Luna_Appointments_I18n {
	public static function booking_status($status) {
		$key = sanitize_key((string) $status);
		$labels = array(
			'pending' => 'در انتظار', 'pending_payment' => 'در انتظار پرداخت',
			'payment_review' => 'در انتظار بررسی پرداخت', 'consultation_pending' => 'در انتظار مشاوره',
			'confirmed' => 'تایید شده', 'completed' => 'انجام شده', 'done' => 'انجام شده',
			'no_show' => 'عدم مراجعه', 'cancelled' => 'لغو شده', 'failed' => 'ناموفق',
			'refunded' => 'مرجوع شده', 'conflict' => 'نیازمند بررسی تداخل',
		);
		return isset($labels[ $key ]) ? __($labels[ $key ], 'luna-appointments') : __('نامشخص', 'luna-appointments');
	}

	public static function payment_status($status) {
		$key = sanitize_key((string) $status);
		$labels = array(
			'pending' => 'در انتظار پرداخت', 'unpaid' => 'پرداخت نشده',
			'authorized' => 'در انتظار تایید پرداخت', 'paid' => 'پرداخت شده',
			'deposit_paid' => 'هزینه اولیه پرداخت شده؛ مانده در انتظار تسویه',
			'not_required' => 'بدون نیاز به پرداخت', 'failed' => 'پرداخت ناموفق',
			'cancelled' => 'پرداخت لغو شده', 'partial_refund' => 'بازپرداخت جزئی', 'partially_refunded' => 'بازپرداخت جزئی',
			'refunded' => 'بازپرداخت شده',
		);
		return isset($labels[ $key ]) ? __($labels[ $key ], 'luna-appointments') : __('نامشخص', 'luna-appointments');
	}

	public static function payment_method($method, $fallback = '') {
		$key = sanitize_key((string) $method);
		$labels = array(
			'cod' => 'پرداخت در محل', 'bacs' => 'انتقال بانکی مستقیم', 'cheque' => 'پرداخت با چک',
			'zarinpal' => 'پرداخت امن زرین‌پال', 'zibal' => 'پرداخت امن زیبال', 'idpay' => 'پرداخت امن آی‌دی‌پی',
			'onsite' => 'پرداخت در مرکز', 'consultation' => 'بدون پرداخت؛ نیازمند مشاوره', 'wallet' => 'کیف پول',
		);
		if (isset($labels[ $key ])) return __($labels[ $key ], 'luna-appointments');
		$aliases = array('cash on delivery' => 'cod', 'direct bank transfer' => 'bacs', 'pay at salon' => 'onsite');
		$lookup = strtolower(trim(wp_strip_all_tags((string) $fallback)));
		return isset($aliases[ $lookup ]) ? __($labels[ $aliases[ $lookup ] ], 'luna-appointments') : (trim((string) $fallback) ?: __('روش پرداخت', 'luna-appointments'));
	}

	public static function combined_status($booking_status, $payment_status = '') {
		$booking = self::booking_status($booking_status);
		return '' !== sanitize_key((string) $payment_status) ? $booking . ' / ' . self::payment_status($payment_status) : $booking;
	}
}
