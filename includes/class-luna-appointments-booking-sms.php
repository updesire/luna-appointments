<?php
/**
 * Customer SMS sent after a booking is committed.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Luna_Appointments_Booking_SMS {
	const OPTION       = 'luna_appointments_booking_sms';
	const ACTION       = 'luna_appointments_send_booking_confirmation_sms';
	const ACTION_GROUP = 'luna-appointments';
	const PAGE_SLUG    = 'luna-booking-sms';

	public static function boot() {
		add_action('luna_appointments_booking_created', array(__CLASS__, 'queue'), 30, 2);
		add_action(self::ACTION, array(__CLASS__, 'send'), 10, 2);
		add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 25);
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		add_action('add_meta_boxes_luna_booking', array(__CLASS__, 'register_status_meta_box'));
	}

	public static function defaults() {
		return array(
			'enabled'  => 'yes',
			'template' => "{name} عزیز، رزرو شما در لونا ثبت شد.\nکد: {booking_code}\nخدمت: {service}\nمتخصص: {specialist}\nزمان: {date} ساعت {time}\nوضعیت پرداخت: {payment_status}",
		);
	}

	public static function settings() {
		return wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
	}

	public static function register_settings() {
		register_setting(
			'luna_booking_sms_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize_settings($input) {
		$input = is_array($input) ? $input : array();
		return array(
			'enabled'  => ! empty($input['enabled']) ? 'yes' : 'no',
			'template' => sanitize_textarea_field($input['template'] ?? self::defaults()['template']),
		);
	}

	public static function register_admin_page() {
		add_submenu_page(
			'luna-bookings-dashboard',
			__('پیامک ثبت رزرو', 'luna-appointments'),
			__('پیامک ثبت رزرو', 'luna-appointments'),
			'edit_theme_options',
			self::PAGE_SLUG,
			array(__CLASS__, 'render_admin_page')
		);
	}

	public static function render_admin_page() {
		if (! current_user_can('edit_theme_options')) {
			wp_die(esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'luna-appointments'));
		}
		$settings  = self::settings();
		$kml_ready = function_exists('kml_send_transactional_sms');
		$woo_ready = function_exists('PWSMS');
		if ($woo_ready) {
			$gateway_notice = __('پنل پیامک ووکامرس درگاه اصلی ارسال است و نتیجه هر پیام در آرشیو آن ثبت می‌شود.', 'luna-appointments');
		} elseif ($kml_ready) {
			$gateway_notice = __('درگاه ملی‌پیامک افزونه ورود به‌عنوان مسیر جایگزین در دسترس است.', 'luna-appointments');
		} else {
			$gateway_notice = __('هیچ درگاه پیامکی سازگاری در دسترس نیست.', 'luna-appointments');
		}
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e('پیامک تأیید ثبت رزرو', 'luna-appointments'); ?></h1>
			<p><?php esc_html_e('پس از ثبت قطعی هر رزرو، جزئیات آن یک‌بار برای شماره مشتری ارسال می‌شود.', 'luna-appointments'); ?></p>
			<div class="notice <?php echo esc_attr(($kml_ready || $woo_ready) ? 'notice-success' : 'notice-error'); ?> inline"><p>
				<?php echo esc_html($gateway_notice); ?>
			</p></div>
			<form method="post" action="options.php">
				<?php settings_fields('luna_booking_sms_settings'); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e('ارسال پس از ثبت رزرو', 'luna-appointments'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($settings['enabled'], 'yes'); ?>> <?php esc_html_e('فعال باشد', 'luna-appointments'); ?></label></td></tr>
				<tr><th scope="row"><label for="luna-booking-sms-template"><?php esc_html_e('متن پیامک', 'luna-appointments'); ?></label></th><td><textarea id="luna-booking-sms-template" name="<?php echo esc_attr(self::OPTION); ?>[template]" rows="9" class="large-text" dir="rtl"><?php echo esc_textarea($settings['template']); ?></textarea><p class="description"><code>{name}</code> <code>{booking_code}</code> <code>{service}</code> <code>{specialist}</code> <code>{date}</code> <code>{time}</code> <code>{payment_status}</code></p><p class="description"><?php esc_html_e('به‌دلیل محدودیت سرشماره پیامکی، هر نوع لینک پیش از ارسال به‌صورت خودکار حذف می‌شود.', 'luna-appointments'); ?></p></td></tr>
				</table>
				<?php submit_button(__('ذخیره تنظیمات پیامک', 'luna-appointments')); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Queue SMS outside the booking request to keep checkout responsive.
	 */
	public static function queue($booking_id, $booking = array()) {
		unset($booking);
		$booking_id = absint($booking_id);
		if ($booking_id <= 0 || 'yes' !== self::settings()['enabled'] || self::was_sent($booking_id)) {
			return;
		}

		$args = array($booking_id, 1);
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action(self::ACTION, $args, self::ACTION_GROUP, true);
			return;
		}
		if (! wp_next_scheduled(self::ACTION, $args)) {
			wp_schedule_single_event(time() + 2, self::ACTION, $args);
		}
	}

	/**
	 * Send a booking SMS once, with bounded retries on provider failures.
	 */
	public static function send($booking_id, $attempt = 1) {
		$booking_id = absint($booking_id);
		$attempt     = max(1, absint($attempt));
		if ($booking_id <= 0 || 'yes' !== self::settings()['enabled'] || self::was_sent($booking_id)) {
			return;
		}

		$lock_key = 'luna_booking_sms_lock_' . $booking_id;
		$locked   = add_option($lock_key, time(), '', false);
		if (! $locked) {
			$locked_at = (int) get_option($lock_key, 0);
			if ($locked_at > 0 && $locked_at < time() - 300) {
				delete_option($lock_key);
				$locked = add_option($lock_key, time(), '', false);
			}
		}
		if (! $locked) {
			return;
		}

		try {
			if (self::booking_post_id($booking_id) <= 0) {
				if ($attempt < 3) {
					self::schedule_retry($booking_id, $attempt + 1);
				}
				return;
			}
			$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
			if (! is_array($booking)) {
				self::record_failure($booking_id, __('اطلاعات رزرو پیدا نشد.', 'luna-appointments'));
				return;
			}

			$mobile  = self::normalize_mobile($booking['customer_phone'] ?? '');
			$message = self::build_message($booking);
			if (! preg_match('/^09\d{9}$/', $mobile)) {
				self::record_failure($booking_id, __('شماره موبایل مشتری معتبر نیست.', 'luna-appointments'));
				return;
			}

			$result = self::dispatch($mobile, $message, $booking_id);
			if (! is_wp_error($result)) {
				self::record_success($booking_id, $result);
				return;
			}

			self::record_failure($booking_id, $result->get_error_message());
			if ($attempt < 3) {
				self::schedule_retry($booking_id, $attempt + 1);
			}
		} finally {
			delete_option($lock_key);
		}
	}

	private static function build_message($booking) {
		$settings = self::settings();
		$date     = class_exists('Luna_Appointments_Date')
			? Luna_Appointments_Date::format_jalali((string) ($booking['booking_date'] ?? ''), '', true)
			: (string) ($booking['booking_date'] ?? '');
		$time = substr(Luna_Appointments_Date::latin_digits((string) ($booking['booking_time'] ?? '')), 0, 5);
		$payment_labels = array(
			'paid'              => __('پرداخت‌شده', 'luna-appointments'),
			'pending'           => __('در انتظار پرداخت', 'luna-appointments'),
			'pending_payment'   => __('در انتظار پرداخت', 'luna-appointments'),
			'pay_on_arrival'    => __('پرداخت در محل', 'luna-appointments'),
			'consultation'      => __('نیازمند مشاوره', 'luna-appointments'),
			'partially_paid'    => __('بیعانه پرداخت‌شده', 'luna-appointments'),
			'refunded'          => __('بازپرداخت‌شده', 'luna-appointments'),
		);
		$payment = sanitize_key((string) ($booking['payment_status'] ?? 'pending'));
		$replace = array(
			'{name}'           => trim((string) ($booking['customer_name'] ?? '')) ?: __('مشتری گرامی', 'luna-appointments'),
			'{booking_code}'   => (string) ($booking['booking_code'] ?? ''),
			'{service}'        => (string) ($booking['service_name'] ?? ''),
			'{specialist}'     => (string) ($booking['specialist_name'] ?? ''),
			'{date}'           => $date,
			'{time}'           => Luna_Appointments_Date::persian_digits($time),
			'{payment_status}' => $payment_labels[$payment] ?? $payment,
		);
		return self::remove_links(trim(strtr((string) $settings['template'], $replace)));
	}

	/**
	 * Remove URLs because the configured sender line rejects messages containing links.
	 */
	private static function remove_links($message) {
		$message = wp_strip_all_tags((string) $message);
		$patterns = array(
			'~https?://[^\s]+~i',
			'~www\.[^\s]+~i',
			'~(?<![\w@])(?:[a-z0-9-]+\.)+(?:com|net|org|ir|co|me|info|biz)(?:/[^\s]*)?~i',
		);
		$message = preg_replace($patterns, '', $message);
		$message = preg_replace('/[ \t]{2,}/', ' ', (string) $message);
		$message = preg_replace('/\n[ \t]+/', "\n", (string) $message);
		$message = preg_replace('/\n{3,}/', "\n\n", (string) $message);
		return trim((string) $message);
	}

	private static function dispatch($mobile, $message, $booking_id) {
		/*
		 * Prefer the WooCommerce SMS gateway. It is the site's canonical SMS
		 * connection and, unlike the login plugin, records the provider response
		 * in its SMS archive. This also prevents an apparently successful response
		 * from a second set of credentials from being mistaken for delivery.
		 */
		if (function_exists('PWSMS')) {
			$result = PWSMS()->send_sms(array(
				'mobile'  => $mobile,
				'message' => $message,
				'post_id' => self::booking_post_id($booking_id),
				'type'    => 0,
			));
			if (true === $result) {
				return array('provider' => 'persian_woocommerce_sms', 'response' => true);
			}
			$woo_error = new WP_Error('booking_sms_woo_failed', is_string($result) ? $result : __('ارسال از پنل پیامک ووکامرس ناموفق بود.', 'luna-appointments'));
		} else {
			$woo_error = null;
		}

		if (function_exists('kml_send_transactional_sms')) {
			$result = kml_send_transactional_sms($mobile, $message);
			if (! is_wp_error($result)) {
				return array('provider' => 'kml_melipayamak', 'response' => $result);
			}
			return $woo_error ?: $result;
		}

		return $woo_error ?: new WP_Error('booking_sms_provider_missing', __('درگاه پیامکی در دسترس نیست.', 'luna-appointments'));
	}

	public static function register_status_meta_box() {
		add_meta_box(
			'luna-booking-confirmation-sms',
			__('پیامک ثبت رزرو', 'luna-appointments'),
			array(__CLASS__, 'render_status_meta_box'),
			'luna_booking',
			'side',
			'default'
		);
	}

	public static function render_status_meta_box($post) {
		$sent_at  = (string) get_post_meta($post->ID, '_luna_booking_confirmation_sms_sent_at', true);
		$provider = (string) get_post_meta($post->ID, '_luna_booking_confirmation_sms_provider', true);
		$attempt  = (string) get_post_meta($post->ID, '_luna_booking_confirmation_sms_last_attempt', true);
		$error    = (string) get_post_meta($post->ID, '_luna_booking_confirmation_sms_error', true);
		?>
		<p><strong><?php esc_html_e('وضعیت:', 'luna-appointments'); ?></strong> <?php echo $sent_at ? esc_html__('تحویل به وب‌سرویس', 'luna-appointments') : esc_html__('ارسال‌نشده', 'luna-appointments'); ?></p>
		<p><strong><?php esc_html_e('زمان ارسال:', 'luna-appointments'); ?></strong> <?php echo esc_html($sent_at ?: '—'); ?></p>
		<p><strong><?php esc_html_e('درگاه:', 'luna-appointments'); ?></strong> <?php echo esc_html($provider ?: '—'); ?></p>
		<?php if ($attempt) : ?><p><strong><?php esc_html_e('آخرین تلاش:', 'luna-appointments'); ?></strong> <?php echo esc_html($attempt); ?></p><?php endif; ?>
		<?php if ($error) : ?><p style="color:#b32d2e"><strong><?php esc_html_e('خطا:', 'luna-appointments'); ?></strong> <?php echo esc_html($error); ?></p><?php endif; ?>
		<p class="description"><?php esc_html_e('جزئیات پاسخ در آرشیو پیامک ووکامرس ثبت می‌شود.', 'luna-appointments'); ?></p>
		<?php
	}

	private static function normalize_mobile($mobile) {
		$mobile = Luna_Appointments_Date::latin_digits((string) $mobile);
		$mobile = preg_replace('/[^0-9+]/', '', $mobile);
		if (strpos($mobile, '+98') === 0) {
			$mobile = '0' . substr($mobile, 3);
		} elseif (strpos($mobile, '98') === 0 && strlen($mobile) === 12) {
			$mobile = '0' . substr($mobile, 2);
		}
		return $mobile;
	}

	private static function booking_post_id($booking_id) {
		$ids = get_posts(array(
			'post_type'      => 'luna_booking',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_luna_booking_id',
			'meta_value'     => (int) $booking_id,
		));
		return $ids ? (int) $ids[0] : 0;
	}

	private static function was_sent($booking_id) {
		$post_id = self::booking_post_id($booking_id);
		return $post_id > 0 && '' !== (string) get_post_meta($post_id, '_luna_booking_confirmation_sms_sent_at', true);
	}

	private static function record_success($booking_id, $result) {
		$post_id = self::booking_post_id($booking_id);
		if ($post_id <= 0) {
			return;
		}
		update_post_meta($post_id, '_luna_booking_confirmation_sms_sent_at', Luna_Appointments_Date::db_now());
		update_post_meta($post_id, '_luna_booking_confirmation_sms_provider', sanitize_key((string) ($result['provider'] ?? 'unknown')));
		delete_post_meta($post_id, '_luna_booking_confirmation_sms_error');
	}

	private static function record_failure($booking_id, $message) {
		$post_id = self::booking_post_id($booking_id);
		if ($post_id <= 0) {
			return;
		}
		update_post_meta($post_id, '_luna_booking_confirmation_sms_last_attempt', Luna_Appointments_Date::db_now());
		update_post_meta($post_id, '_luna_booking_confirmation_sms_error', sanitize_text_field((string) $message));
	}

	private static function schedule_retry($booking_id, $attempt) {
		$args = array((int) $booking_id, (int) $attempt);
		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + 300, self::ACTION, $args, self::ACTION_GROUP, true);
			return;
		}
		if (! wp_next_scheduled(self::ACTION, $args)) {
			wp_schedule_single_event(time() + 300, self::ACTION, $args);
		}
	}
}
