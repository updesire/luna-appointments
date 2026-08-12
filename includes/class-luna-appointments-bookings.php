<?php
/**
 * Booking submission and persistence handlers.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Bookings {
	/**
	 * Ajax action used by the booking form.
	 *
	 * @var string
	 */
	protected static $ajax_action = 'luna_submit_booking';

	/**
	 * Ajax action used to fetch live reserved slots.
	 *
	 * @var string
	 */
	protected static $slots_ajax_action = 'luna_get_reserved_slots';

	protected static $my_account_ajax_action = 'luna_my_booking_action';

	protected static $my_account_list_ajax_action = 'luna_my_booking_list';

	/**
	 * Booking post type used for admin management.
	 *
	 * @var string
	 */
	protected static $booking_post_type = 'luna_booking';

	protected static $booking_dashboard_slug = 'luna-bookings-dashboard';

        protected static $booking_exports_slug = 'luna-bookings-export';

	protected static $my_account_bookings_endpoint = 'luna-bookings';

	protected static $my_account_vip_endpoint = 'luna-vip';

	/**
	 * Boot booking handlers.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action('init', array(__CLASS__, 'register_booking_post_type'));
		add_action('init', array(__CLASS__, 'register_my_account_endpoints'), 20);
		add_action('admin_menu', array(__CLASS__, 'register_booking_admin_pages'), 9);
		add_action('wp_ajax_' . self::$ajax_action, array(__CLASS__, 'handle_submit'));
		add_action('wp_ajax_nopriv_' . self::$ajax_action, array(__CLASS__, 'handle_submit'));
		add_action('wp_ajax_' . self::$slots_ajax_action, array(__CLASS__, 'handle_reserved_slots'));
		add_action('wp_ajax_nopriv_' . self::$slots_ajax_action, array(__CLASS__, 'handle_reserved_slots'));
		add_action('wp_ajax_' . self::$my_account_ajax_action, array(__CLASS__, 'handle_my_account_action_ajax'));
		add_action('wp_ajax_' . self::$my_account_list_ajax_action, array(__CLASS__, 'handle_my_account_list_ajax'));
		add_action('wp_ajax_luna_booking_editor_update', array(__CLASS__, 'handle_booking_editor_update_ajax'));
		add_action('luna_booking_send_reminder', array(__CLASS__, 'send_booking_reminder'), 10, 2);
		add_action('admin_post_luna_booking_send_manual_reminder', array(__CLASS__, 'handle_manual_reminder_send'));
		add_action('admin_post_luna_booking_update_from_editor', array(__CLASS__, 'handle_booking_editor_update_request'));
		add_action('admin_post_luna_booking_quick_status', array(__CLASS__, 'handle_booking_quick_status'));
                add_action('admin_post_luna_booking_export_csv', array(__CLASS__, 'handle_booking_export_csv'));
                add_action('admin_post_luna_booking_receipt', array(__CLASS__, 'render_booking_receipt_page'));
		add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_order_paid'));
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_order_status_changed'), 10, 4);
		add_action('woocommerce_order_refunded', array(__CLASS__, 'handle_order_refunded'), 10, 2);
		add_action('woocommerce_order_partially_refunded', array(__CLASS__, 'handle_order_refunded'), 10, 2);
		add_action('woocommerce_order_fully_refunded', array(__CLASS__, 'handle_order_refunded'), 10, 2);
                add_action('luna_appointments_booking_status_transition', array(__CLASS__, 'handle_booking_transition_notifications'), 10, 8);
		add_filter('woocommerce_checkout_fields', array(__CLASS__, 'filter_checkout_fields_for_booking'), 20);
		add_filter('woocommerce_default_address_fields', array(__CLASS__, 'filter_default_address_fields_for_booking'), 20);
		add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'filter_booking_order_pay_gateways'), 20);
		add_filter('query_vars', array(__CLASS__, 'filter_my_account_query_vars'));
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'filter_my_account_menu_items'));
		add_action('woocommerce_account_dashboard', array(__CLASS__, 'render_my_account_dashboard_bookings'), 25);
		add_action('woocommerce_account_' . self::$my_account_bookings_endpoint . '_endpoint', array(__CLASS__, 'render_my_account_bookings'));
		add_action('woocommerce_account_' . self::$my_account_vip_endpoint . '_endpoint', array(__CLASS__, 'render_my_account_vip_club'));
		add_action('add_meta_boxes', array(__CLASS__, 'register_booking_meta_boxes'));
		add_action('save_post_' . self::$booking_post_type, array(__CLASS__, 'handle_booking_post_save'), 10, 3);
		add_action('wp_trash_post', array(__CLASS__, 'handle_booking_post_trashed'));
		add_action('untrash_post', array(__CLASS__, 'handle_booking_post_untrashed'));
		add_action('before_delete_post', array(__CLASS__, 'handle_booking_post_deleted'));
		add_action('admin_init', array(__CLASS__, 'maybe_normalize_legacy_booking_dates'), 6);
		add_action('admin_init', array(__CLASS__, 'maybe_backfill_booking_posts'));
		add_action('admin_init', array(__CLASS__, 'maybe_reconcile_booking_orders'), 7);
		add_action('admin_init', array(__CLASS__, 'maybe_repair_interrupted_bookings'), 12);
		add_action('init', array(__CLASS__, 'ensure_booking_maintenance_schedule'), 30);
		add_filter('cron_schedules', array(__CLASS__, 'add_booking_cron_schedule'));
		add_action('luna_appointments_repair_interrupted_bookings', array(__CLASS__, 'maybe_repair_interrupted_bookings'));
		add_action('admin_init', array(__CLASS__, 'maybe_flush_account_endpoints'));
		add_action('show_user_profile', array(__CLASS__, 'render_user_vip_fields'));
		add_action('edit_user_profile', array(__CLASS__, 'render_user_vip_fields'));
		add_action('personal_options_update', array(__CLASS__, 'save_user_vip_fields'));
		add_action('edit_user_profile_update', array(__CLASS__, 'save_user_vip_fields'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_booking_admin_assets'));
		add_action('admin_footer', array(__CLASS__, 'render_booking_editor_admin_script'));
		add_action('admin_notices', array(__CLASS__, 'render_booking_bulk_notice'));
		add_action('post_submitbox_misc_actions', array(__CLASS__, 'render_booking_submitbox_summary'));
		add_action('restrict_manage_posts', array(__CLASS__, 'render_booking_list_filters'));
		add_filter('views_edit-' . self::$booking_post_type, array(__CLASS__, 'filter_booking_list_views'));
		add_filter('bulk_actions-edit-' . self::$booking_post_type, array(__CLASS__, 'register_booking_bulk_actions'));
		add_filter('handle_bulk_actions-edit-' . self::$booking_post_type, array(__CLASS__, 'handle_booking_bulk_actions'), 10, 3);
		add_filter('parent_file', array(__CLASS__, 'highlight_booking_menu_parent'));
		add_filter('submenu_file', array(__CLASS__, 'highlight_booking_menu_submenu'));
		add_filter('redirect_post_location', array(__CLASS__, 'filter_booking_post_redirect'), 10, 2);
	}

	public static function register_booking_bulk_actions($actions) {
		$actions['luna_confirm'] = __('تأیید رزروها', 'luna-appointments');
		$actions['luna_complete'] = __('تکمیل رزروها', 'luna-appointments');
		$actions['luna_cancel'] = __('لغو رزروها', 'luna-appointments');
		$actions['luna_failed'] = __('علامت‌گذاری ناموفق', 'luna-appointments');
		return $actions;
	}

	public static function render_booking_bulk_notice() {
		if (! isset($_GET['luna_bulk_updated'])) return;
		$success = absint(wp_unslash($_GET['luna_bulk_updated']));
		$failed = isset($_GET['luna_bulk_failed']) ? absint(wp_unslash($_GET['luna_bulk_failed'])) : 0;
		echo '<div class="notice ' . esc_attr($failed ? 'notice-warning' : 'notice-success') . ' is-dismissible"><p>' . esc_html(sprintf(__('عملیات گروهی: %1$d رزرو بروزرسانی شد و %2$d رزرو انجام نشد.', 'luna-appointments'), $success, $failed)) . '</p></div>';
	}

	public static function handle_booking_bulk_actions($redirect_url, $action, $post_ids) {
		$targets = array('luna_confirm' => 'confirmed', 'luna_complete' => 'completed', 'luna_cancel' => 'cancelled', 'luna_failed' => 'failed');
		if (! isset($targets[$action])) return $redirect_url;
		$success = 0; $failed = 0;
		foreach (array_map('absint', (array) $post_ids) as $post_id) {
			if (! current_user_can('edit_post', $post_id)) { $failed++; continue; }
			$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
			$existing = $booking_id ? Luna_Appointments_Bookings_Table::get_booking($booking_id) : null;
			if (! is_array($existing)) { $failed++; continue; }
			$status = $targets[$action];
			if ('confirmed' === $status && self::booking_slot_has_conflict($existing)) { $failed++; continue; }
			$changes = array('status' => $status, 'notes' => self::append_booking_note((string) ($existing['notes'] ?? ''), sprintf(__('عملیات گروهی مدیریت: %s', 'luna-appointments'), $status)));
			if ('failed' === $status) $changes['payment_status'] = 'failed';
			if ('cancelled' === $status && ! in_array((string) ($existing['payment_status'] ?? ''), array('paid','refunded'), true)) $changes['payment_status'] = 'cancelled';
			if (! Luna_Appointments_Bookings_Table::update_booking($booking_id, $changes)) { $failed++; continue; }
			self::maybe_trigger_booking_status_transition($booking_id, $existing, $changes, 'admin_bulk_action');
			self::upsert_booking_post_from_row_id($booking_id);
			if ('cancelled' === $status) { self::clear_scheduled_reminders($booking_id); self::maybe_cancel_unpaid_linked_order($existing, __('رزرو با عملیات گروهی لغو شد.', 'luna-appointments')); }
			elseif (! in_array($status, array('completed','failed'), true)) self::maybe_schedule_booking_reminders($booking_id);
			$success++;
		}
		return add_query_arg(array('luna_bulk_updated' => $success, 'luna_bulk_failed' => $failed), $redirect_url);
	}

	public static function handle_my_account_action_ajax() {
		if (! is_user_logged_in()) {
			wp_send_json_error(
				array(
					'message' => __('برای انجام این عملیات باید وارد حساب کاربری شوید.', 'luna-appointments'),
				),
				401
			);
		}

		$user_id = (int) get_current_user_id();
		if ($user_id <= 0) {
			wp_send_json_error(
				array(
					'message' => __('درخواست نامعتبر است.', 'luna-appointments'),
				),
				400
			);
		}

		$booking_id = isset($_POST['booking_id']) ? (int) wp_unslash($_POST['booking_id']) : 0;
		$nonce      = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		$action     = isset($_POST['bookingAction']) ? sanitize_key(wp_unslash($_POST['bookingAction'])) : '';

		if ($booking_id <= 0 || '' === $nonce || '' === $action || ! wp_verify_nonce($nonce, 'luna_my_booking_' . $booking_id)) {
			wp_send_json_error(
				array(
					'message' => __('درخواست نامعتبر است.', 'luna-appointments'),
				),
				400
			);
		}

		$booking = self::get_booking_for_user($booking_id, $user_id);
		if (! is_array($booking)) {
			wp_send_json_error(
				array(
					'message' => __('این رزرو برای حساب شما قابل دسترسی نیست.', 'luna-appointments'),
				),
				403
			);
		}

		if ('cancel' === $action) {
			$cancel_reason = isset($_POST['cancel_reason']) ? sanitize_textarea_field(wp_unslash($_POST['cancel_reason'])) : '';
			$result = self::cancel_booking_from_user($booking, $user_id, $cancel_reason);
			if (is_wp_error($result)) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
					),
					400
				);
			}

			$updated = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;
			if (! is_array($updated)) {
				$updated = $booking;
			}

			wp_send_json_success(
				array(
					'message' => __('رزرو لغو شد.', 'luna-appointments'),
					'booking' => array(
						'id'            => (int) $booking_id,
						'datetimeLabel'  => self::format_booking_datetime_label($updated),
						'statusLabel'    => self::format_account_status_label((string) ($updated['status'] ?? ''), (string) ($updated['payment_status'] ?? '')),
						'statusKey'      => (string) ($updated['status'] ?? ''),
						'paymentStatus'  => (string) ($updated['payment_status'] ?? ''),
					),
				)
			);
		}

		if ('reschedule' === $action) {
			$new_date = isset($_POST['new_date']) ? sanitize_text_field(wp_unslash($_POST['new_date'])) : '';
			$new_time = isset($_POST['new_time']) ? sanitize_text_field(wp_unslash($_POST['new_time'])) : '';
			$result   = self::reschedule_booking_from_user($booking, $user_id, $new_date, $new_time);
			if (is_wp_error($result)) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
					),
					400
				);
			}

			$updated = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;
			if (! is_array($updated)) {
				$updated = $booking;
			}

			wp_send_json_success(
				array(
					'message' => __('زمان رزرو تغییر کرد.', 'luna-appointments'),
					'booking' => array(
						'id'            => (int) $booking_id,
						'datetimeLabel'  => self::format_booking_datetime_label($updated),
						'statusLabel'    => self::format_account_status_label((string) ($updated['status'] ?? ''), (string) ($updated['payment_status'] ?? '')),
						'statusKey'      => (string) ($updated['status'] ?? ''),
						'paymentStatus'  => (string) ($updated['payment_status'] ?? ''),
					),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __('عملیات نامعتبر است.', 'luna-appointments'),
			),
			400
		);
	}

	public static function handle_my_account_list_ajax() {
		if (! is_user_logged_in()) {
			wp_send_json_error(
				array(
					'message' => __('برای مشاهده رزروها باید وارد حساب کاربری شوید.', 'luna-appointments'),
				),
				401
			);
		}

		check_ajax_referer(self::$my_account_list_ajax_action, 'nonce');

		$filter_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
		$filter_search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
		$filter_sort   = isset($_POST['sort']) ? sanitize_key(wp_unslash($_POST['sort'])) : 'newest';

		$html = self::get_my_account_bookings_markup(
			array(
				'show_heading'       => false,
				'show_view_all_link' => false,
				'show_reschedule'    => false,
				'current_tab'        => 'bookings',
				'filter_status'      => $filter_status,
				'filter_search'      => $filter_search,
				'filter_sort'        => $filter_sort,
			)
		);

		wp_send_json_success(
			array(
				'html' => $html,
			)
		);
	}

	/**
	 * Return public booking form config.
	 *
	 * @return array<string, string>
	 */
	public static function get_frontend_config() {
		$language = function_exists('luna_current_language') ? luna_current_language() : (function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'fa');
		$language = in_array($language, array('fa', 'en', 'ar'), true) ? $language : 'fa';
		$config = array(
			'ajaxUrl'           => admin_url('admin-ajax.php'),
			'action'            => self::$ajax_action,
			'nonce'             => wp_create_nonce(self::$ajax_action),
			'slotsAction'       => self::$slots_ajax_action,
			'slotsNonce'        => wp_create_nonce(self::$slots_ajax_action),
			'reservedStatuses'  => array('pending', 'pending_payment', 'payment_review', 'consultation_pending', 'confirmed'),
			'language'          => $language,
		);

		return apply_filters('luna_appointments_booking_frontend_config', $config);
	}

	/**
	 * Return live reserved slots for the selected specialist and date.
	 *
	 * @return void
	 */
	public static function handle_reserved_slots() {
		check_ajax_referer(self::$slots_ajax_action, 'nonce');

		$service_slug     = isset($_POST['serviceId']) ? sanitize_title(wp_unslash($_POST['serviceId'])) : '';
		$specialist_slug = isset($_POST['specialistId']) ? sanitize_title(wp_unslash($_POST['specialistId'])) : '';
		$booking_date    = isset($_POST['bookingDate']) ? sanitize_text_field(wp_unslash($_POST['bookingDate'])) : '';
		$candidate_times = isset($_POST['candidateTimes']) ? wp_unslash($_POST['candidateTimes']) : array();
		$exclude_booking_id = isset($_POST['excludeBookingId']) ? (int) wp_unslash($_POST['excludeBookingId']) : 0;

		if ('' === $specialist_slug || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
			wp_send_json_error(
				array(
					'message' => __('اطلاعات متخصص یا تاریخ رزرو معتبر نیست.', 'luna-appointments'),
				),
				400
			);
		}

		$specialist_post = self::resolve_post_by_slug($specialist_slug, 'specialist');

		if (! $specialist_post instanceof WP_Post) {
			wp_send_json_error(
				array(
					'message' => __('متخصص انتخاب‌شده پیدا نشد.', 'luna-appointments'),
				),
				404
			);
		}

		$service_meta = array();
		$service_post = null;

		if ('' !== $service_slug) {
			$service_post = self::resolve_post_by_slug($service_slug, 'service');

			if ($service_post instanceof WP_Post && class_exists('Luna_Appointments_Services')) {
				$service_meta = Luna_Appointments_Services::get_service_meta_values($service_post->ID);
			}
		}

		if (! $service_post instanceof WP_Post) {
			wp_send_json_error(
				array(
					'message' => __('برای بررسی زمان‌های آزاد، ابتدا خدمت معتبر را انتخاب کنید.', 'luna-appointments'),
				),
				400
			);
		}

		$assigned_specialists = isset($service_meta['_luna_service_specialist_ids']) && is_array($service_meta['_luna_service_specialist_ids'])
			? self::translate_relationship_ids($service_meta['_luna_service_specialist_ids'])
			: array();

		if (! in_array((int) $specialist_post->ID, $assigned_specialists, true)) {
			$specialist_services = class_exists('Luna_Appointments_Specialists') && method_exists('Luna_Appointments_Specialists', 'get_assigned_service_ids')
				? Luna_Appointments_Specialists::get_assigned_service_ids((int) $specialist_post->ID)
				: array();

			if (! empty($specialist_services) && in_array((int) $service_post->ID, array_map('intval', (array) $specialist_services), true)) {
				$assigned_specialists[] = (int) $specialist_post->ID;
			}
		}

		if (! in_array((int) $specialist_post->ID, $assigned_specialists, true)) {
			wp_send_json_error(
				array(
					'message' => __('این متخصص برای خدمت انتخاب‌شده زمان رزرو معتبری ندارد.', 'luna-appointments'),
				),
				409
			);
		}

		$duration_minutes = isset($service_meta['_luna_service_duration_minutes']) ? (int) $service_meta['_luna_service_duration_minutes'] : 60;
		$buffer_minutes   = isset($service_meta['_luna_service_booking_buffer']) ? (int) $service_meta['_luna_service_booking_buffer'] : 0;
		$candidate_times  = is_array($candidate_times)
			? array_values(
				array_filter(
					array_map(
						static function ($time) {
							return preg_match('/^\d{2}:\d{2}$/', (string) $time) ? (string) $time : '';
						},
						$candidate_times
					)
				)
			)
			: array();

		$schedule = self::get_specialist_schedule((int) $specialist_post->ID);

		if (! self::is_specialist_open_for_date($schedule, $booking_date)) {
			wp_send_json_success(
				array(
					'specialistId' => $specialist_slug,
					'bookingDate'  => $booking_date,
					'times'        => array_values($candidate_times),
					'duration'     => $duration_minutes,
					'buffer'       => $buffer_minutes,
					'closed'       => true,
				)
			);
		}

		$candidate_times = array_values(
			array_filter(
				$candidate_times,
								static function ($time) use ($schedule, $duration_minutes, $buffer_minutes, $booking_date) {
										return self::is_time_allowed_by_schedule((string) $time, $duration_minutes, $buffer_minutes, $schedule, $booking_date);
				}
			)
		);

		if ($exclude_booking_id > 0 && is_user_logged_in()) {
			$owned = self::get_booking_for_user($exclude_booking_id, get_current_user_id());
			$exclude_booking_id = is_array($owned) ? $exclude_booking_id : 0;
		} else {
			$exclude_booking_id = 0;
		}

		$reserved_times = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::get_reserved_times((int) $specialist_post->ID, $booking_date, $candidate_times, $duration_minutes, $buffer_minutes, $exclude_booking_id)
			: array();

		wp_send_json_success(
			array(
				'specialistId' => $specialist_slug,
				'bookingDate'  => $booking_date,
				'candidates'   => array_values($candidate_times),
				'times'        => array_values($reserved_times),
				'duration'     => $duration_minutes,
				'buffer'       => $buffer_minutes,
			)
		);
	}

	/**
	 * Return payment options sourced from active WooCommerce gateways.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_payment_options() {
		$options = array();

		if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
			$gateway_manager = WC()->payment_gateways();
			$gateways        = $gateway_manager && method_exists($gateway_manager, 'payment_gateways')
				? $gateway_manager->payment_gateways()
				: array();

			foreach ((array) $gateways as $gateway) {
				if (! is_object($gateway)) {
					continue;
				}

				$enabled = isset($gateway->enabled) ? (string) $gateway->enabled : 'yes';

				if ('yes' !== $enabled) {
					continue;
				}

				$id          = isset($gateway->id) ? sanitize_key((string) $gateway->id) : '';
				$label       = method_exists($gateway, 'get_title') ? (string) $gateway->get_title() : (isset($gateway->title) ? (string) $gateway->title : '');
				$label       = self::normalize_payment_label($id, $label);
				$description = isset($gateway->description) ? wp_strip_all_tags((string) $gateway->description) : '';

				if ('' === $id || '' === trim($label)) {
					continue;
				}

				$options[] = array(
					'id'          => $id,
					'label'       => trim($label),
					'description' => '' !== trim($description) ? trim($description) : __('درگاه پرداخت فعال ووکامرس', 'luna-appointments'),
				);
			}
		}

		if (! empty($options)) {
			return array_values($options);
		}

		return array(
			array(
				'id'          => 'bacs',
				'label'       => __('انتقال بانکی مستقیم', 'luna-appointments'),
				'description' => __('اگر درگاه‌های ووکامرس در دسترس نباشند، این روش به‌عنوان گزینه جایگزین نمایش داده می‌شود.', 'luna-appointments'),
			),
		);
	}

	/**
	 * Handle booking form submission.
	 *
	 * @return void
	 */
	public static function handle_submit() {
		check_ajax_referer(self::$ajax_action, 'nonce');

		$service_slug    = isset($_POST['serviceId']) ? sanitize_title(wp_unslash($_POST['serviceId'])) : '';
		$specialist_slug = isset($_POST['specialistId']) ? sanitize_title(wp_unslash($_POST['specialistId'])) : '';
		$booking_date    = isset($_POST['bookingDate']) ? sanitize_text_field(wp_unslash($_POST['bookingDate'])) : '';
		$booking_time    = isset($_POST['bookingTime']) ? sanitize_text_field(wp_unslash($_POST['bookingTime'])) : '';
		$customer_name   = isset($_POST['customerName']) ? sanitize_text_field(wp_unslash($_POST['customerName'])) : '';
		$customer_phone  = isset($_POST['customerPhone']) ? self::to_latin_digits(sanitize_text_field(wp_unslash($_POST['customerPhone']))) : '';
		$customer_email  = isset($_POST['customerEmail']) ? sanitize_email(wp_unslash($_POST['customerEmail'])) : '';
		$language        = self::request_language();
		$discount_code   = isset($_POST['discountCode']) ? sanitize_text_field(wp_unslash($_POST['discountCode'])) : '';
		$gift_card_code  = isset($_POST['giftCardCode']) ? sanitize_text_field(wp_unslash($_POST['giftCardCode'])) : '';
		$idempotency_key = isset($_POST['idempotencyKey']) ? sanitize_text_field(wp_unslash($_POST['idempotencyKey'])) : '';
		$use_wallet      = isset($_POST['useWallet']) && 'false' !== (string) wp_unslash($_POST['useWallet']);
		$payment_options = self::get_payment_options();
		$default_method  = ! empty($payment_options[0]['id']) ? (string) $payment_options[0]['id'] : 'bacs';
		$payment_method  = isset($_POST['paymentMethod']) ? sanitize_key(wp_unslash($_POST['paymentMethod'])) : $default_method;
		$customer_user_id = get_current_user_id();
		$is_vip           = $customer_user_id > 0 ? (self::is_user_vip($customer_user_id) ? 1 : 0) : 0;
		if ('' === $idempotency_key) {
			$idempotency_key = 'srv_' . wp_generate_uuid4();
		}
		if (strlen($idempotency_key) > 64 || 1 !== preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotency_key)) {
			wp_send_json_error(array('message' => __('شناسه ایمنی درخواست معتبر نیست. صفحه را تازه‌سازی و دوباره تلاش کنید.', 'luna-appointments')), 400);
		}

		if ('' === $customer_name || '' === $customer_phone) {
			wp_send_json_error(
				array(
					'message' => __('لطفاً نام و شماره تماس خود را کامل وارد کنید.', 'luna-appointments'),
				),
				400
			);
		}

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
			wp_send_json_error(
				array(
					'message' => __('تاریخ رزرو معتبر نیست.', 'luna-appointments'),
				),
				400
			);
		}

		if (! preg_match('/^\d{2}:\d{2}$/', $booking_time)) {
			wp_send_json_error(
				array(
					'message' => __('ساعت رزرو معتبر نیست.', 'luna-appointments'),
				),
				400
			);
		}

		if ('' !== $customer_email && ! is_email($customer_email)) {
			wp_send_json_error(
				array(
					'message' => __('ایمیل واردشده معتبر نیست.', 'luna-appointments'),
				),
				400
			);
		}

		$service_post = self::resolve_post_by_slug($service_slug, 'service');

		if (! $service_post instanceof WP_Post) {
			wp_send_json_error(
				array(
					'message' => __('خدمت انتخاب‌شده پیدا نشد.', 'luna-appointments'),
				),
				404
			);
		}

		$specialist_post = self::resolve_post_by_slug($specialist_slug, 'specialist');

		if (! $specialist_post instanceof WP_Post) {
			wp_send_json_error(
				array(
					'message' => __('متخصص انتخاب‌شده پیدا نشد.', 'luna-appointments'),
				),
				404
			);
		}

		$service_meta = class_exists('Luna_Appointments_Services') ? Luna_Appointments_Services::get_service_meta_values($service_post->ID) : array();

		$existing_idempotent = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_by_idempotency_key($idempotency_key) : null;
		if (is_array($existing_idempotent)) {
			self::send_idempotent_booking_response($existing_idempotent, $customer_user_id, $customer_phone);
		}

		if (empty($service_meta['_luna_service_is_active'])) {
			wp_send_json_error(
				array(
					'message' => __('این خدمت در حال حاضر برای رزرو در دسترس نیست.', 'luna-appointments'),
				),
				400
			);
		}

		$assigned_specialists = isset($service_meta['_luna_service_specialist_ids']) && is_array($service_meta['_luna_service_specialist_ids'])
			? self::translate_relationship_ids($service_meta['_luna_service_specialist_ids'])
			: array();

		if (! in_array((int) $specialist_post->ID, $assigned_specialists, true)) {
			$specialist_services = class_exists('Luna_Appointments_Specialists') && method_exists('Luna_Appointments_Specialists', 'get_assigned_service_ids')
				? Luna_Appointments_Specialists::get_assigned_service_ids((int) $specialist_post->ID)
				: array();

			if (! empty($specialist_services) && in_array((int) $service_post->ID, array_map('intval', (array) $specialist_services), true)) {
				$assigned_specialists[] = (int) $specialist_post->ID;
			}
		}

		if (! in_array((int) $specialist_post->ID, $assigned_specialists, true)) {
			wp_send_json_error(
				array(
					'message' => __('این متخصص برای خدمت انتخاب‌شده در دسترس نیست. لطفاً ارتباط خدمت و متخصص را در پنل خدمات/متخصص‌ها بررسی کنید.', 'luna-appointments'),
				),
				400
			);
		}

		$booking_timestamp = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::timestamp($booking_date, $booking_time) : strtotime($booking_date . ' ' . $booking_time);
		if (! $booking_timestamp || $booking_timestamp < current_datetime()->getTimestamp()) {
			wp_send_json_error(
				array(
					'message' => __('زمان انتخاب‌شده گذشته است. لطفاً زمان دیگری را انتخاب کنید.', 'luna-appointments'),
				),
				400
			);
		}

		$duration_minutes = isset($service_meta['_luna_service_duration_minutes']) ? (int) $service_meta['_luna_service_duration_minutes'] : 0;
		$buffer_minutes   = isset($service_meta['_luna_service_booking_buffer']) ? (int) $service_meta['_luna_service_booking_buffer'] : 0;

		$schedule = self::get_specialist_schedule((int) $specialist_post->ID);

		if (! self::is_specialist_open_for_date($schedule, $booking_date) || ! self::is_time_allowed_by_schedule($booking_time, $duration_minutes, $buffer_minutes, $schedule, $booking_date)) {
			wp_send_json_error(
				array(
					'message' => __('این متخصص در تاریخ یا ساعت انتخاب‌شده امکان ارائه این خدمت را ندارد.', 'luna-appointments'),
				),
				409
			);
		}

		if (class_exists('Luna_Appointments_Bookings_Table') && Luna_Appointments_Bookings_Table::slot_exists((int) $specialist_post->ID, $booking_date, $booking_time, $duration_minutes, $buffer_minutes)) {
			wp_send_json_error(
				array(
					'message' => __('این زمان همین حالا رزرو شده است. لطفاً زمان دیگری را انتخاب کنید.', 'luna-appointments'),
				),
				409
			);
		}

		$base_price       = isset($service_meta['_luna_service_base_price']) ? (int) $service_meta['_luna_service_base_price'] : 0;
		$price_label      = isset($service_meta['_luna_service_price_label']) ? trim((string) $service_meta['_luna_service_price_label']) : '';
		$requires_consultation = ! empty($service_meta['_luna_service_requires_consultation']);
		$consultation_plan = class_exists('Luna_Appointments_Consultation_Finance') ? Luna_Appointments_Consultation_Finance::service_plan((int) $service_post->ID) : array('mode' => 'no_payment', 'upfront_fee' => 0);
		$has_consultation_fee = $requires_consultation && 'upfront_fee' === (string) ($consultation_plan['mode'] ?? '') && (float) ($consultation_plan['upfront_fee'] ?? 0) > 0;
		$booking_code      = self::generate_booking_code();
		$normalized_method = $requires_consultation && ! $has_consultation_fee ? 'consultation' : self::normalize_payment_method($payment_method);
		if ($has_consultation_fee && in_array($normalized_method, array('cod', 'onsite', 'consultation'), true)) {
			wp_send_json_error(array('message' => __('هزینه اولیه مشاوره باید پیش از ثبت نهایی و از طریق روش پرداخت غیرحضوری پرداخت شود.', 'luna-appointments')), 400);
		}
		$finance_context   = array(
			'booking_code'     => $booking_code,
			'service_id'       => (int) $service_post->ID,
			'service_slug'     => $service_slug,
			'specialist_id'    => (int) $specialist_post->ID,
			'specialist_slug'  => $specialist_slug,
			'customer_user_id' => (int) $customer_user_id,
			'customer_name'    => $customer_name,
			'customer_phone'   => $customer_phone,
			'customer_email'   => $customer_email,
			'discount_code'    => $discount_code,
			'gift_card_code'   => $gift_card_code,
			'use_wallet'       => $use_wallet,
			'booking_date'     => $booking_date,
			'booking_time'     => $booking_time,
			'duration_minutes' => max(0, $duration_minutes),
			'buffer_minutes'   => max(0, $buffer_minutes),
			'base_price'       => max(0, $base_price),
			'price_label'      => $price_label,
			'payment_method'   => $normalized_method,
			'language'         => $language,
		);
		$finance_quote     = $requires_consultation && ! $has_consultation_fee
			? array(
				'base_amount'     => 0,
				'discount_amount' => 0,
				'gift_amount'     => 0,
				'wallet_amount'   => 0,
				'payable_amount'  => 0,
				'price_label'     => __('پس از مشاوره تعیین می‌شود', 'luna-appointments'),
				'meta'            => array('consultation' => true),
			)
			: ($has_consultation_fee ? Luna_Appointments_Consultation_Finance::initial_quote((int) $service_post->ID, $base_price) : self::get_booking_finance_quote($finance_context));
		$status         = $requires_consultation && ! $has_consultation_fee ? 'consultation_pending' : 'pending_payment';
		$payment_status = $requires_consultation && ! $has_consultation_fee ? 'not_required' : 'pending';

		if (! $requires_consultation && $base_price <= 0 && 'cod' !== $normalized_method) {
			wp_send_json_error(
				array(
					'message' => __('برای این خدمت هنوز مبلغ قابل پرداخت ثبت نشده است. لطفاً پرداخت در محل را انتخاب کنید یا مبلغ خدمت را در پنل لونا تکمیل کنید.', 'luna-appointments'),
				),
				400
			);
		}

		$release_slot_lock = static function () use ($specialist_post, $booking_date) {
			if (class_exists('Luna_Appointments_Bookings_Table')) {
				Luna_Appointments_Bookings_Table::release_slot_lock((int) $specialist_post->ID, $booking_date);
			}
		};
		register_shutdown_function($release_slot_lock);

		$booking_id = self::insert_booking_with_slot_guard(
			array(
				'booking_code'     => $booking_code,
				'idempotency_key'  => $idempotency_key,
				'service_id'       => (int) $service_post->ID,
				'specialist_id'    => (int) $specialist_post->ID,
				'customer_user_id' => (int) $customer_user_id,
				'is_vip'           => (int) $is_vip,
				'customer_name'    => $customer_name,
				'customer_phone'   => $customer_phone,
				'customer_email'   => $customer_email,
				'language'         => $language,
				'booking_date'     => $booking_date,
				'booking_time'     => $booking_time,
				'duration_minutes' => max(0, $duration_minutes),
				'buffer_minutes'   => max(0, $buffer_minutes),
				'base_price'       => max(0, $base_price),
				'price_label'      => $price_label,
				'status'           => $status,
				'payment_status'   => $payment_status,
				'payment_method'   => $normalized_method,
				'notes'            => self::append_booking_note('', sprintf(__('رزرو اولیه با روش پرداخت %s ایجاد شد.', 'luna-appointments'), self::get_payment_label($normalized_method))),
				'admin_note'       => '',
				'source'           => 'booking_form',
			),
			true
		);
		if (is_wp_error($booking_id) || ! $booking_id) {
			$release_slot_lock();
			$duplicate = Luna_Appointments_Bookings_Table::get_booking_by_idempotency_key($idempotency_key);
			if (is_array($duplicate)) {
				self::send_idempotent_booking_response($duplicate, $customer_user_id, $customer_phone);
			}
			wp_send_json_error(
				array(
					'message' => is_wp_error($booking_id) ? $booking_id->get_error_message() : __('ثبت رزرو انجام نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'),
				),
				500
			);
		}

		self::upsert_booking_post_from_row_id((int) $booking_id);

		if ($requires_consultation && ! $has_consultation_fee) {
			$created_booking = Luna_Appointments_Bookings_Table::get_booking_with_context((int) $booking_id);
			if (is_array($created_booking)) {
				self::maybe_send_booking_lifecycle_notification('created', $created_booking, null, 'consultation_booking');
			}

			$release_slot_lock();
			wp_send_json_success(
				array(
					'bookingId'      => (int) $booking_id,
					'bookingCode'    => $booking_code,
					'orderId'        => 0,
					'orderKey'       => '',
					'paymentUrl'     => '',
					'serviceName'    => get_the_title($service_post),
					'specialistName' => get_the_title($specialist_post),
					'paymentMethod'  => 'consultation',
					'paymentLabel'   => __('بدون پرداخت؛ نیازمند مشاوره', 'luna-appointments'),
					'pricing'        => null,
					'status'         => 'consultation_pending',
					'isConsultation' => true,
					'message'        => __('وقت مشاوره شما ثبت شد. پس از بررسی، مجموعه برای ادامه فرایند با شما تماس می‌گیرد.', 'luna-appointments'),
				)
			);
		}

		$finance_prepare = apply_filters(
			'luna_appointments_prepare_booking_finance_commit',
			true,
			(int) $booking_id,
			$finance_quote,
			$finance_context
		);

		if (is_wp_error($finance_prepare)) {
			if (class_exists('Luna_Appointments_Bookings_Table')) {
				$existing_booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
				$failure_data = array(
					'status'         => 'failed',
					'payment_status' => 'failed',
					'notes'          => self::append_booking_note(is_array($existing_booking) && isset($existing_booking['notes']) ? (string) $existing_booking['notes'] : '', $finance_prepare->get_error_message()),
				);
				Luna_Appointments_Bookings_Table::update_booking(
					(int) $booking_id,
					$failure_data
				);
				if (is_array($existing_booking)) {
					self::maybe_trigger_booking_status_transition((int) $booking_id, $existing_booking, $failure_data, 'finance_prepare_failed');
				}
				self::upsert_booking_post_from_row_id((int) $booking_id);
			}

			$release_slot_lock();
			wp_send_json_error(
				array(
					'message' => $finance_prepare->get_error_message(),
				),
				409
			);
		}

		$order_result = self::create_payment_order(
			(int) $booking_id,
			$service_post,
			$specialist_post,
			array(
				'booking_code'     => $booking_code,
				'customer_user_id' => (int) $customer_user_id,
				'is_vip'           => (int) $is_vip,
				'customer_name'    => $customer_name,
				'customer_phone'   => $customer_phone,
				'customer_email'   => $customer_email,
				'booking_date'     => $booking_date,
				'booking_time'     => $booking_time,
				'duration_minutes' => max(0, $duration_minutes),
				'buffer_minutes'   => max(0, $buffer_minutes),
				'base_price'       => max(0, $base_price),
				'price_label'      => $price_label,
				'payment_method'   => $normalized_method,
				'finance_quote'    => $finance_quote,
				'consultation_plan'=> $consultation_plan,
			)
		);

		if (is_wp_error($order_result)) {
			do_action(
				'luna_appointments_release_booking_finance_commit',
				(int) $booking_id,
				0,
				$finance_quote,
				$finance_context,
				'order_create_failed'
			);

			if (class_exists('Luna_Appointments_Bookings_Table')) {
				$existing_booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
				$failure_data = array(
					'status'         => 'failed',
					'payment_status' => 'failed',
					'notes'          => self::append_booking_note(is_array($existing_booking) && isset($existing_booking['notes']) ? (string) $existing_booking['notes'] : '', $order_result->get_error_message()),
				);
				Luna_Appointments_Bookings_Table::update_booking(
					(int) $booking_id,
					$failure_data
				);
				if (is_array($existing_booking)) {
					self::maybe_trigger_booking_status_transition((int) $booking_id, $existing_booking, $failure_data, 'order_create_failed');
				}
				self::upsert_booking_post_from_row_id((int) $booking_id);
			}

			$release_slot_lock();
			wp_send_json_error(
				array(
					'message' => $order_result->get_error_message(),
				),
				500
			);
		}

                $created_booking = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context((int) $booking_id) : null;
                if (is_array($created_booking)) {
                        self::maybe_send_booking_lifecycle_notification('created', $created_booking, null, 'booking_form');
                }

		$release_slot_lock();
		wp_send_json_success(
			array(
				'bookingId'      => (int) $booking_id,
				'bookingCode'    => $booking_code,
				'orderId'        => isset($order_result['order_id']) ? (int) $order_result['order_id'] : 0,
				'orderKey'       => isset($order_result['order_key']) ? (string) $order_result['order_key'] : '',
				'paymentUrl'     => isset($order_result['payment_url']) ? (string) $order_result['payment_url'] : '',
				'serviceName'    => get_the_title($service_post),
				'specialistName' => get_the_title($specialist_post),
				'paymentMethod'  => $normalized_method,
				'paymentLabel'   => self::get_payment_label($normalized_method),
				'pricing'        => $finance_quote,
				'status'         => $status,
				'isConsultation' => $requires_consultation,
				'isConsultationFee' => $has_consultation_fee,
				'message'        => $has_consultation_fee ? __('رزرو مشاوره ثبت شد و سفارش پرداخت هزینه اولیه آماده است.', 'luna-appointments') : __('رزرو شما با موفقیت ثبت شد و سفارش پرداخت ووکامرس آماده است.', 'luna-appointments'),
			)
		);
	}

	/** Return the canonical result of an already accepted submission. */
	protected static function send_idempotent_booking_response($booking, $request_user_id = 0, $request_phone = '') {
		$owner_id    = (int) ($booking['customer_user_id'] ?? 0);
		$stored_phone = self::to_latin_digits((string) ($booking['customer_phone'] ?? ''));
		$request_phone = self::to_latin_digits((string) $request_phone);
		if (($owner_id > 0 && $owner_id !== (int) $request_user_id) || (0 === $owner_id && '' !== $stored_phone && $stored_phone !== $request_phone)) {
			wp_send_json_error(array('message' => __('این شناسه درخواست متعلق به رزرو دیگری است.', 'luna-appointments')), 409);
		}
		$status = sanitize_key((string) ($booking['status'] ?? ''));
		if (in_array($status, array('failed', 'cancelled', 'refunded'), true)) {
			wp_send_json_error(
				array(
					'message'          => __('تلاش قبلی این درخواست نهایی نشد. لطفاً دوباره اقدام کنید.', 'luna-appointments'),
					'resetIdempotency' => true,
					'bookingCode'      => (string) ($booking['booking_code'] ?? ''),
				),
				409
			);
		}

		$order       = false;
		$payment_url = '';
		if (! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $booking['wc_order_id']);
			if ($order instanceof WC_Order) {
				$payment_url = self::get_booking_payment_url($order);
			}
		}

		$is_consultation = 'consultation_pending' === $status || 'consultation' === (string) ($booking['payment_method'] ?? '');
		if (! $is_consultation && ! $order instanceof WC_Order) {
			wp_send_json_error(
				array(
					'message'       => __('درخواست رزرو قبلی هنوز در حال نهایی‌شدن است؛ چند لحظه دیگر دوباره تلاش کنید.', 'luna-appointments'),
					'retryable'     => true,
					'bookingCode'   => (string) ($booking['booking_code'] ?? ''),
				),
				409
			);
		}

		$finance = $is_consultation ? null : self::get_booking_finance_snapshot($booking);
		wp_send_json_success(
			array(
				'bookingId'      => (int) ($booking['id'] ?? 0),
				'bookingCode'    => (string) ($booking['booking_code'] ?? ''),
				'orderId'        => $order instanceof WC_Order ? (int) $order->get_id() : 0,
				'orderKey'       => $order instanceof WC_Order ? (string) $order->get_order_key() : '',
				'paymentUrl'     => $payment_url,
				'serviceName'    => ! empty($booking['service_id']) ? get_the_title((int) $booking['service_id']) : '',
				'specialistName' => ! empty($booking['specialist_id']) ? get_the_title((int) $booking['specialist_id']) : '',
				'paymentMethod'  => (string) ($booking['payment_method'] ?? ''),
				'paymentLabel'   => self::get_payment_label((string) ($booking['payment_method'] ?? '')),
				'pricing'        => $finance,
				'status'         => $status,
				'isConsultation' => $is_consultation,
				'isReplay'       => true,
				'message'        => __('این درخواست قبلاً ثبت شده بود؛ همان نتیجه امن بازگردانده شد.', 'luna-appointments'),
			)
		);
	}

	public static function register_booking_post_type() {
		$labels = array(
			'name'               => __('رزروها', 'luna-appointments'),
			'singular_name'      => __('رزرو', 'luna-appointments'),
			'add_new'            => __('افزودن', 'luna-appointments'),
			'add_new_item'       => __('افزودن رزرو', 'luna-appointments'),
			'edit_item'          => __('ویرایش رزرو', 'luna-appointments'),
			'new_item'           => __('رزرو جدید', 'luna-appointments'),
			'view_item'          => __('مشاهده رزرو', 'luna-appointments'),
			'search_items'       => __('جستجوی رزروها', 'luna-appointments'),
			'not_found'          => __('رزروی پیدا نشد.', 'luna-appointments'),
			'not_found_in_trash' => __('رزروی در زباله‌دان پیدا نشد.', 'luna-appointments'),
			'all_items'          => __('همه رزروها', 'luna-appointments'),
			'menu_name'          => __('رزروها', 'luna-appointments'),
		);

		register_post_type(
			self::$booking_post_type,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'menu_position'       => 57,
				'menu_icon'           => 'dashicons-calendar-alt',
				'supports'            => array('title'),
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);

		add_filter('manage_' . self::$booking_post_type . '_posts_columns', array(__CLASS__, 'filter_booking_columns'));
		add_action('manage_' . self::$booking_post_type . '_posts_custom_column', array(__CLASS__, 'render_booking_column'), 10, 2);
		add_filter('manage_edit-' . self::$booking_post_type . '_sortable_columns', array(__CLASS__, 'filter_booking_sortable_columns'));
		add_action('pre_get_posts', array(__CLASS__, 'filter_booking_admin_query'));
	}

	public static function register_my_account_endpoints() {
		if (! function_exists('add_rewrite_endpoint')) {
			return;
		}

		add_rewrite_endpoint(self::$my_account_bookings_endpoint, EP_ROOT | EP_PAGES);
		add_rewrite_endpoint(self::$my_account_vip_endpoint, EP_ROOT | EP_PAGES);
	}

	public static function maybe_flush_account_endpoints() {
		if (! is_admin() || ! current_user_can('manage_options')) {
			return;
		}

		if ('2' === (string) get_option('luna_booking_account_endpoints_flushed', '')) {
			return;
		}

		flush_rewrite_rules(false);
		update_option('luna_booking_account_endpoints_flushed', '2', false);
	}

	public static function filter_my_account_query_vars($vars) {
		$vars[] = self::$my_account_bookings_endpoint;
		$vars[] = self::$my_account_vip_endpoint;
		return $vars;
	}

	public static function filter_my_account_menu_items($items) {
		if (! is_array($items)) {
			return $items;
		}

		$label_map = array(
			'dashboard'       => __('پیشخوان', 'luna-appointments'),
			'orders'          => __('سفارش ها', 'luna-appointments'),
			'downloads'       => __('دانلودها', 'luna-appointments'),
			'edit-address'    => __('آدرس ها', 'luna-appointments'),
			'payment-methods' => __('روش های پرداخت', 'luna-appointments'),
			'edit-account'    => __('جزئیات حساب', 'luna-appointments'),
			'customer-logout' => __('خروج', 'luna-appointments'),
		);
		$bookings_label = __('رزروهای من', 'luna-appointments');
		$vip_label      = __('باشگاه VIP', 'luna-appointments');

		$new_items = array();
		foreach ($items as $key => $label) {
			if (isset($label_map[ $key ])) {
				$label = $label_map[ $key ];
			}
			if ('customer-logout' === $key) {
				$new_items[ self::$my_account_bookings_endpoint ] = $bookings_label;
				$new_items[ self::$my_account_vip_endpoint ]      = $vip_label;
			}
			$new_items[ $key ] = $label;
		}

		if (! isset($new_items[ self::$my_account_bookings_endpoint ])) {
			$new_items[ self::$my_account_bookings_endpoint ] = $bookings_label;
		}
		if (! isset($new_items[ self::$my_account_vip_endpoint ])) {
			$new_items[ self::$my_account_vip_endpoint ] = $vip_label;
		}

		return $new_items;
	}

	public static function render_my_account_dashboard_bookings() {
		echo '<section class="luna-account-bookings-dashboard" style="margin-top:28px;">';
		self::render_my_account_bookings();
		echo '</section>';
	}

	public static function get_my_account_bookings_markup($args = array()) {
		ob_start();
		self::render_my_account_bookings($args);
		return (string) ob_get_clean();
	}

	public static function render_my_account_bookings($args = array()) {
		$args = wp_parse_args(
			is_array($args) ? $args : array(),
			array(
				'show_heading'       => true,
				'show_view_all_link' => true,
				'show_reschedule'    => false,
				'current_tab'        => '',
				'filter_status'      => null,
				'filter_search'      => null,
				'filter_sort'        => null,
			)
		);

		if (! is_user_logged_in()) {
			echo '<p>' . esc_html__('برای مشاهده رزروهای خود ابتدا وارد حساب کاربری شوید.', 'luna-appointments') . '</p>';
			return;
		}

		$user_id = get_current_user_id();
		self::handle_my_account_booking_actions($user_id);

		$current_tab = '' !== (string) $args['current_tab'] ? sanitize_key((string) $args['current_tab']) : (isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '');
		$filter_status = null !== $args['filter_status'] ? sanitize_key((string) $args['filter_status']) : (isset($_GET['luna_bk_status']) ? sanitize_key(wp_unslash($_GET['luna_bk_status'])) : '');
		$filter_search = null !== $args['filter_search'] ? sanitize_text_field((string) $args['filter_search']) : (isset($_GET['luna_bk_q']) ? sanitize_text_field(wp_unslash($_GET['luna_bk_q'])) : '');
		$filter_sort   = null !== $args['filter_sort'] ? sanitize_key((string) $args['filter_sort']) : (isset($_GET['luna_bk_sort']) ? sanitize_key(wp_unslash($_GET['luna_bk_sort'])) : 'newest');

		$sort_order = 'oldest' === $filter_sort ? 'ASC' : 'DESC';
		$result = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::query_bookings_for_user(
				$user_id,
				array(
					'paged'    => 1,
					'per_page' => 50,
					'status'   => $filter_status,
					'search'   => $filter_search,
					'order_by' => 'booking_date',
					'order'    => $sort_order,
				)
			)
			: array('items' => array(), 'total' => 0);
		$items  = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();

		if (! empty($args['show_heading'])) {
			$is_bookings_endpoint = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::$my_account_bookings_endpoint);
			echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 14px;">';
			echo '<h2 style="margin:0;">' . esc_html__('رزروهای من', 'luna-appointments') . '</h2>';
			if (! $is_bookings_endpoint && ! empty($args['show_view_all_link'])) {
				echo '<a class="button" href="' . esc_url(wc_get_account_endpoint_url(self::$my_account_bookings_endpoint)) . '">' . esc_html__('مشاهده همه رزروها', 'luna-appointments') . '</a>';
			}
			echo '</div>';
		}

		echo '<form method="get" class="luna-account-booking-filters" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 14px;">';
		if ('' !== $current_tab) {
			echo '<input type="hidden" name="tab" value="' . esc_attr($current_tab) . '">';
		}
		echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<span style="font-size:12px;color:rgba(79,74,86,.78);">' . esc_html__('وضعیت', 'luna-appointments') . '</span>';
		echo '<select name="luna_bk_status" style="min-height:38px;border-radius:12px;border:1px solid rgba(37,48,66,.12);padding:6px 10px;background:rgba(255,255,255,.7);">';
		echo '<option value="">' . esc_html__('همه', 'luna-appointments') . '</option>';
		echo '<option value="pending_payment"' . selected($filter_status, 'pending_payment', false) . '>' . esc_html__('در انتظار', 'luna-appointments') . '</option>';
		echo '<option value="confirmed"' . selected($filter_status, 'confirmed', false) . '>' . esc_html__('تایید', 'luna-appointments') . '</option>';
		echo '<option value="cancelled"' . selected($filter_status, 'cancelled', false) . '>' . esc_html__('لغو شده', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '</label>';
		echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<span style="font-size:12px;color:rgba(79,74,86,.78);">' . esc_html__('مرتب‌سازی', 'luna-appointments') . '</span>';
		echo '<select name="luna_bk_sort" style="min-height:38px;border-radius:12px;border:1px solid rgba(37,48,66,.12);padding:6px 10px;background:rgba(255,255,255,.7);">';
		echo '<option value="newest"' . selected($filter_sort, 'newest', false) . '>' . esc_html__('جدیدترین', 'luna-appointments') . '</option>';
		echo '<option value="oldest"' . selected($filter_sort, 'oldest', false) . '>' . esc_html__('قدیمی‌ترین', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '</label>';
		echo '<label style="display:inline-flex;gap:8px;align-items:center;flex:1;min-width:220px;">';
		echo '<span style="font-size:12px;color:rgba(79,74,86,.78);">' . esc_html__('جستجو', 'luna-appointments') . '</span>';
		echo '<input type="search" name="luna_bk_q" value="' . esc_attr($filter_search) . '" placeholder="' . esc_attr__('خدمت، متخصص، کد رزرو...', 'luna-appointments') . '" style="flex:1;min-height:38px;border-radius:12px;border:1px solid rgba(37,48,66,.12);padding:6px 10px;background:rgba(255,255,255,.7);">';
		echo '</label>';
		echo '<button type="submit" class="button" style="min-height:38px;">' . esc_html__('اعمال', 'luna-appointments') . '</button>';
		echo '</form>';

		if (empty($items)) {
			echo '<p>' . esc_html__('رزروی برای نمایش وجود ندارد.', 'luna-appointments') . '</p>';
			return;
		}

		echo '<table class="shop_table shop_table_responsive my_account_orders">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('خدمت', 'luna-appointments') . '</th>';
		echo '<th>' . esc_html__('متخصص', 'luna-appointments') . '</th>';
		echo '<th>' . esc_html__('زمان', 'luna-appointments') . '</th>';
		echo '<th>' . esc_html__('وضعیت', 'luna-appointments') . '</th>';
		echo '<th>' . esc_html__('عملیات', 'luna-appointments') . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ($items as $booking) {
			$booking_id   = isset($booking['id']) ? (int) $booking['id'] : 0;
			$service_id   = isset($booking['service_id']) ? (int) $booking['service_id'] : 0;
			$specialist_id = isset($booking['specialist_id']) ? (int) $booking['specialist_id'] : 0;
			$service_name = isset($booking['service_name']) ? (string) $booking['service_name'] : '';
			$spec_name    = isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '';
			$datetime     = self::format_booking_datetime_label($booking);
			$status       = isset($booking['status']) ? (string) $booking['status'] : '';
			$pay_status   = isset($booking['payment_status']) ? (string) $booking['payment_status'] : '';
			$can_change   = self::can_user_change_booking($booking);
			$is_bookings_screen = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::$my_account_bookings_endpoint)) || 'bookings' === $current_tab;

			echo '<tr class="order">';
			echo '<td data-title="' . esc_attr__('خدمت', 'luna-appointments') . '">' . esc_html($service_name) . '</td>';
			echo '<td data-title="' . esc_attr__('متخصص', 'luna-appointments') . '">' . esc_html($spec_name) . '</td>';
			echo '<td data-title="' . esc_attr__('زمان', 'luna-appointments') . '">' . esc_html($datetime) . '</td>';
			echo '<td data-title="' . esc_attr__('وضعیت', 'luna-appointments') . '">' . esc_html(self::format_account_status_label($status, $pay_status));
			if (class_exists('Luna_Appointments_Consultation_Finance')) echo Luna_Appointments_Consultation_Finance::frontend_summary_markup($booking_id);
			echo '</td>';
			echo '<td data-title="' . esc_attr__('عملیات', 'luna-appointments') . '">';

                        if ($booking_id > 0) {
                                $receipt_url = wp_nonce_url(
                                        add_query_arg(
                                                array(
                                                        'action'     => 'luna_booking_receipt',
                                                        'booking_id' => $booking_id,
                                                ),
                                                admin_url('admin-post.php')
                                        ),
                                        'luna_booking_receipt_' . $booking_id
                                );
                                echo '<p style="margin:0 0 10px;"><a class="button" target="_blank" href="' . esc_url($receipt_url) . '">' . esc_html__('رسید / PDF', 'luna-appointments') . '</a></p>';
                        }

			if ($booking_id > 0 && $can_change) {
				$nonce = wp_create_nonce('luna_my_booking_' . $booking_id);

				echo '<form method="post" style="margin:0 0 10px;">';
				echo '<input type="hidden" name="luna_booking_action" value="cancel">';
				echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking_id) . '">';
				echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
				echo '<input type="hidden" name="cancel_reason" value="">';
				echo '<button type="submit" class="button luna-booking-cancel-button">' . esc_html__('لغو رزرو', 'luna-appointments') . '</button>';
				echo '</form>';

				if ($is_bookings_screen) {
					$service_slug    = $service_id > 0 ? (string) get_post_field('post_name', $service_id) : '';
					$specialist_slug = $specialist_id > 0 ? (string) get_post_field('post_name', $specialist_id) : '';
					$current_date    = isset($booking['booking_date']) ? (string) $booking['booking_date'] : '';
					$current_time    = isset($booking['booking_time']) ? (string) $booking['booking_time'] : '';
					$duration        = isset($booking['duration_minutes']) ? (int) $booking['duration_minutes'] : 0;
					$buffer          = isset($booking['buffer_minutes']) ? (int) $booking['buffer_minutes'] : 0;

					echo '<button type="button" class="button luna-booking-reschedule-toggle" data-booking-id="' . esc_attr((string) $booking_id) . '"><span class="luna-booking-reschedule-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 7V3M16 7V3M4.5 9.5H19.5M6 5H18C19.1046 5 20 5.89543 20 7V19C20 20.1046 19.1046 21 18 21H6C4.89543 21 4 20.1046 4 19V7C4 5.89543 4.89543 5 6 5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 14L11 16L15 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="luna-booking-reschedule-label">' . esc_html__('تغییر زمان', 'luna-appointments') . '</span></button>';
					echo '<div class="luna-booking-reschedule-panel" hidden>';
					echo '<div class="luna-booking-reschedule-head"><strong class="luna-booking-reschedule-title">' . esc_html__('تغییر زمان رزرو', 'luna-appointments') . '</strong><button type="button" class="luna-booking-reschedule-close" aria-label="' . esc_attr__('بستن', 'luna-appointments') . '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6L18 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>';
					echo '<form method="post" class="luna-booking-reschedule-form" data-booking-id="' . esc_attr((string) $booking_id) . '" data-service="' . esc_attr($service_slug) . '" data-specialist="' . esc_attr($specialist_slug) . '" data-current-date="' . esc_attr($current_date) . '" data-current-time="' . esc_attr($current_time) . '" data-duration="' . esc_attr((string) $duration) . '" data-buffer="' . esc_attr((string) $buffer) . '" style="margin:0;">';
					echo '<input type="hidden" name="luna_booking_action" value="reschedule">';
					echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking_id) . '">';
					echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
					echo '<label class="luna-booking-reschedule-date">';
					echo '<span class="luna-booking-reschedule-date-label">' . esc_html__('تاریخ جدید', 'luna-appointments') . '</span>';
					echo '<input type="hidden" name="new_date" value="' . esc_attr($current_date) . '" class="luna-booking-date-gregorian">';
					echo '<input type="text" inputmode="numeric" autocomplete="off" class="luna-booking-date-jalali" data-gregorian="' . esc_attr($current_date) . '" placeholder="' . esc_attr__('مثلاً ۱۴۰۵/۰۴/۱۱', 'luna-appointments') . '" required>';
					echo '</label>';
					echo '<div class="luna-booking-slot-area" data-slot-area="1" style="margin:0 0 10px;"></div>';
					echo '<input type="hidden" name="new_time" value="">';
					echo '<button type="submit" class="button" disabled>' . esc_html__('ثبت تغییر زمان', 'luna-appointments') . '</button>';
					echo '</form>';
					echo '</div>';
				}
			} else {
				echo '—';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		static $printed_reschedule_config = false;
		if (! $printed_reschedule_config) {
			$printed_reschedule_config = true;
			$config = array(
				'ajaxUrl'     => admin_url('admin-ajax.php'),
				'action'      => self::$slots_ajax_action,
				'accountAction' => self::$my_account_ajax_action,
				'listAction'  => self::$my_account_list_ajax_action,
				'nonce'       => wp_create_nonce(self::$slots_ajax_action),
				'listNonce'   => wp_create_nonce(self::$my_account_list_ajax_action),
				'workingHours'=> self::build_working_hours(self::get_booking_slot_step_minutes()),
				'messages'    => array(
					'cancelConfirm' => __('آیا از لغو این رزرو مطمئن هستید؟', 'luna-appointments'),
					'cancelReason'  => __('اگر مایل هستید دلیل لغو را بنویسید (اختیاری):', 'luna-appointments'),
					'slotsLoading'  => __('در حال دریافت زمان‌های آزاد...', 'luna-appointments'),
					'slotsEmpty'    => __('زمان آزادی برای این تاریخ پیدا نشد.', 'luna-appointments'),
					'slotsClosed'   => __('این متخصص در تاریخ انتخاب‌شده فعال نیست.', 'luna-appointments'),
					'slotsError'    => __('دریافت زمان‌های آزاد انجام نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'),
					'chooseTime'    => __('یک ساعت را انتخاب کنید.', 'luna-appointments'),
					'searchLoading' => __('در حال به‌روزرسانی رزروها...', 'luna-appointments'),
					'searchError'   => __('جستجوی رزروها انجام نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'),
				),
			);
			echo '<script>window.LunaBookingReschedule = window.LunaBookingReschedule || ' . wp_json_encode($config) . ';</script>';
		}
	}

	public static function get_booking_slot_step_minutes() {
		$step_minutes = 30;

		$settings = Luna_Appointments_API::settings();
		if (isset($settings['booking_slot_step_minutes'])) {
			$step_minutes = (int) $settings['booking_slot_step_minutes'];
		}

		$step_minutes = max(1, (int) $step_minutes);
		if (0 !== (60 % $step_minutes)) {
			$step_minutes = 30;
		}

		return $step_minutes;
	}

	public static function apply_booking_weekend_settings($days) {
		$days     = is_array($days) ? array_values(array_unique(array_map('intval', $days))) : array();
		$settings = Luna_Appointments_API::settings();

		$thursday_open = isset($settings['booking_thursday_open']) ? 'no' !== (string) $settings['booking_thursday_open'] : true;
		$friday_open   = isset($settings['booking_friday_open']) ? 'yes' === (string) $settings['booking_friday_open'] : false;

		// The global setting is an upper-level restriction. It must never add a
		// weekday that the specialist explicitly left unchecked.
		if (! $thursday_open) {
			$days = array_values(array_diff($days, array(5)));
		}

		if (! $friday_open) {
			$days = array_values(array_diff($days, array(6)));
		}

		$days = array_values(
			array_filter(
				array_unique(array_map('intval', $days)),
				static function ($day) {
					return $day >= 0 && $day <= 6;
				}
			)
		);
		sort($days);

		return $days;
	}

	protected static function build_working_hours($step_minutes = 30) {
		$step_minutes = max(1, (int) $step_minutes);
		if (0 !== (60 % $step_minutes)) {
			$step_minutes = 30;
		}

		$times = array();
		for ($minutes = 0; $minutes < 24 * 60; $minutes += $step_minutes) {
			$hour   = (int) floor($minutes / 60);
			$minute = $minutes % 60;
			$times[] = sprintf('%02d:%02d', $hour, $minute);
		}

		return $times;
	}

	public static function render_my_account_vip_club() {
		if (! is_user_logged_in()) {
			echo '<p>' . esc_html__('برای مشاهده باشگاه VIP ابتدا وارد حساب کاربری شوید.', 'luna-appointments') . '</p>';
			return;
		}

		$user_id    = get_current_user_id();
		$is_vip     = self::is_user_vip($user_id);
		$points     = (int) get_user_meta($user_id, '_luna_vip_points', true);
		$threshold  = self::get_vip_auto_threshold();
		$next_level = max(0, $threshold - $points);

		echo '<h2>' . esc_html__('باشگاه VIP', 'luna-appointments') . '</h2>';
		echo '<p>' . esc_html($is_vip ? __('وضعیت شما: VIP', 'luna-appointments') : __('وضعیت شما: عادی', 'luna-appointments')) . '</p>';
		echo '<p>' . esc_html(sprintf(__('امتیاز شما: %s', 'luna-appointments'), self::to_persian_digits((string) $points))) . '</p>';
		if (! $is_vip) {
			echo '<p>' . esc_html(sprintf(__('تا VIP شدن: %s رزرو پرداخت‌شده دیگر', 'luna-appointments'), self::to_persian_digits((string) $next_level))) . '</p>';
		}
	}

	public static function render_user_vip_fields($user) {
		if (! $user instanceof WP_User || ! current_user_can('edit_users')) {
			return;
		}

		$is_vip = get_user_meta((int) $user->ID, '_luna_is_vip', true) ? '1' : '';
		$points = (int) get_user_meta((int) $user->ID, '_luna_vip_points', true);

		echo '<h2>' . esc_html__('Luna VIP', 'luna-appointments') . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr>';
		echo '<th><label for="luna_is_vip">' . esc_html__('VIP', 'luna-appointments') . '</label></th>';
		echo '<td><label><input type="checkbox" name="luna_is_vip" id="luna_is_vip" value="1"' . checked($is_vip, '1', false) . '> ' . esc_html__('این کاربر VIP است', 'luna-appointments') . '</label></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th>' . esc_html__('VIP Points', 'luna-appointments') . '</th>';
		echo '<td>' . esc_html((string) $points) . '</td>';
		echo '</tr>';
		echo '</tbody></table>';
	}

	public static function save_user_vip_fields($user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0 || ! current_user_can('edit_users')) {
			return;
		}

		$is_vip = isset($_POST['luna_is_vip']) ? 1 : 0;
		update_user_meta($user_id, '_luna_is_vip', $is_vip ? 1 : 0);
	}

	protected static function is_user_vip($user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return false;
		}
		return (bool) get_user_meta($user_id, '_luna_is_vip', true);
	}

	protected static function get_vip_auto_threshold() {
		$settings = Luna_Appointments_API::settings();
		$raw      = isset($settings['vip_auto_threshold']) ? (string) $settings['vip_auto_threshold'] : '5';
		$value    = (int) preg_replace('/[^\d]/', '', $raw);
		return max(1, $value);
	}

	protected static function maybe_award_vip_points($booking_id, $order) {
		unset($order);
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (! is_array($booking)) {
			return;
		}

		$user_id = isset($booking['customer_user_id']) ? (int) $booking['customer_user_id'] : 0;
		if ($user_id <= 0) {
			return;
		}

		$payment_status = isset($booking['payment_status']) ? sanitize_key((string) $booking['payment_status']) : '';
		if ('paid' !== $payment_status) {
			return;
		}

		$post_id = self::find_booking_post_id($booking_id);
		if ($post_id <= 0) {
			self::upsert_booking_post_from_row_id($booking_id);
			$post_id = self::find_booking_post_id($booking_id);
		}
		if ($post_id <= 0) {
			return;
		}

		if ('1' === (string) get_post_meta($post_id, '_luna_vip_points_awarded', true)) {
			return;
		}

		$current = (int) get_user_meta($user_id, '_luna_vip_points', true);
		update_user_meta($user_id, '_luna_vip_points', $current + 1);
		update_post_meta($post_id, '_luna_vip_points_awarded', '1');

		if (! self::is_user_vip($user_id)) {
			$threshold = self::get_vip_auto_threshold();
			$points    = (int) get_user_meta($user_id, '_luna_vip_points', true);
			if ($points >= $threshold) {
				update_user_meta($user_id, '_luna_is_vip', 1);
			}
		}
	}

	protected static function handle_my_account_booking_actions($user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return;
		}

		if (! isset($_POST['luna_booking_action'], $_POST['booking_id'], $_POST['_wpnonce'])) {
			return;
		}

		$booking_id = (int) wp_unslash($_POST['booking_id']);
		$nonce      = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
		if ($booking_id <= 0 || ! wp_verify_nonce($nonce, 'luna_my_booking_' . $booking_id)) {
			wc_add_notice(__('درخواست نامعتبر است.', 'luna-appointments'), 'error');
			return;
		}

		$action = sanitize_key(wp_unslash($_POST['luna_booking_action']));
		$booking = self::get_booking_for_user($booking_id, $user_id);
		if (! is_array($booking)) {
			wc_add_notice(__('این رزرو برای حساب شما قابل دسترسی نیست.', 'luna-appointments'), 'error');
			return;
		}

		if ('cancel' === $action) {
			$cancel_reason = isset($_POST['cancel_reason']) ? sanitize_textarea_field(wp_unslash($_POST['cancel_reason'])) : '';
			$result = self::cancel_booking_from_user($booking, $user_id, $cancel_reason);
			wc_add_notice(is_wp_error($result) ? $result->get_error_message() : __('رزرو لغو شد.', 'luna-appointments'), is_wp_error($result) ? 'error' : 'success');
			return;
		}

		if ('reschedule' === $action) {
			$new_date = isset($_POST['new_date']) ? sanitize_text_field(wp_unslash($_POST['new_date'])) : '';
			$new_time = isset($_POST['new_time']) ? sanitize_text_field(wp_unslash($_POST['new_time'])) : '';
			$result   = self::reschedule_booking_from_user($booking, $user_id, $new_date, $new_time);
			wc_add_notice(is_wp_error($result) ? $result->get_error_message() : __('زمان رزرو تغییر کرد.', 'luna-appointments'), is_wp_error($result) ? 'error' : 'success');
			return;
		}
	}

	protected static function get_booking_for_user($booking_id, $user_id) {
		$booking_id = (int) $booking_id;
		$user_id    = (int) $user_id;
		if ($booking_id <= 0 || $user_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return null;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) {
			return null;
		}

		$owner_id = isset($booking['customer_user_id']) ? (int) $booking['customer_user_id'] : 0;
		if ($owner_id === $user_id) {
			return $booking;
		}

		if (! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $booking['wc_order_id']);
			if ($order instanceof WC_Order && (int) $order->get_customer_id() === $user_id) {
				if (0 === $owner_id) {
					Luna_Appointments_Bookings_Table::update_booking($booking_id, array('customer_user_id' => $user_id));
					self::upsert_booking_post_from_row_id($booking_id);
					$booking['customer_user_id'] = $user_id;
				}
				return $booking;
			}
		}

		return null;
	}

	protected static function can_user_change_booking($booking) {
		$status = isset($booking['status']) ? sanitize_key((string) $booking['status']) : '';
		if (in_array($status, array('cancelled', 'failed', 'refunded'), true)) {
			return false;
		}

		$booking_date = isset($booking['booking_date']) ? (string) $booking['booking_date'] : '';
		$booking_time = isset($booking['booking_time']) ? (string) $booking['booking_time'] : '';
		$timestamp    = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::timestamp($booking_date, $booking_time) : strtotime($booking_date . ' ' . $booking_time);
		if (! $timestamp) {
			return false;
		}

		return $timestamp > current_datetime()->getTimestamp();
	}

	/**
	 * Broadcast booking status transitions for dependent subsystems such as finance.
	 *
	 * @param int                  $booking_id Booking id.
	 * @param array<string,mixed>  $previous_booking Previous booking snapshot.
	 * @param array<string,mixed>  $changes Updated booking fields.
	 * @param string               $source Transition source.
	 * @return void
	 */
	public static function maybe_trigger_booking_status_transition($booking_id, $previous_booking, $changes, $source = '') {
		if ((int) $booking_id <= 0 || ! is_array($previous_booking) || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$old_status         = isset($previous_booking['status']) ? sanitize_key((string) $previous_booking['status']) : '';
		$new_status         = array_key_exists('status', $changes) ? sanitize_key((string) $changes['status']) : $old_status;
		$old_payment_status = isset($previous_booking['payment_status']) ? sanitize_key((string) $previous_booking['payment_status']) : '';
		$new_payment_status = array_key_exists('payment_status', $changes) ? sanitize_key((string) $changes['payment_status']) : $old_payment_status;

		if ($old_status === $new_status && $old_payment_status === $new_payment_status) {
			return;
		}

		$current_booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
		if (! is_array($current_booking)) {
			$current_booking                 = $previous_booking;
			$current_booking['id']          = (int) $booking_id;
			$current_booking['status']      = $new_status;
			$current_booking['payment_status'] = $new_payment_status;
		}

		do_action(
			'luna_appointments_booking_status_transition',
			(int) $booking_id,
			$old_status,
			$new_status,
			$old_payment_status,
			$new_payment_status,
			$current_booking,
			$previous_booking,
			sanitize_key((string) $source)
		);
	}

	protected static function cancel_booking_from_user($booking, $user_id, $cancel_reason = '') {
		unset($user_id);
		if (! class_exists('Luna_Appointments_Bookings_Table')) {
			return new WP_Error('table_missing', __('امکان لغو رزرو در حال حاضر فراهم نیست.', 'luna-appointments'));
		}

		if (! self::can_user_change_booking($booking)) {
			return new WP_Error('cannot_cancel', __('این رزرو قابل لغو نیست.', 'luna-appointments'));
		}

		$booking_id      = isset($booking['id']) ? (int) $booking['id'] : 0;
		$payment_status  = isset($booking['payment_status']) ? sanitize_key((string) $booking['payment_status']) : '';
		$next_payment    = in_array($payment_status, array('paid', 'refunded'), true) ? $payment_status : 'cancelled';
		$existing_notes  = isset($booking['notes']) ? (string) $booking['notes'] : '';
		$cancel_reason   = trim((string) $cancel_reason);
		$cancel_note     = __('رزرو توسط مشتری لغو شد.', 'luna-appointments');
		if ('' !== $cancel_reason) {
			$cancel_note = $cancel_note . ' ' . sprintf(__('دلیل: %s', 'luna-appointments'), $cancel_reason);
		}
		$updated = Luna_Appointments_Bookings_Table::update_booking(
			$booking_id,
			array(
				'status'         => 'cancelled',
				'payment_status' => $next_payment,
				'notes'          => self::append_booking_note($existing_notes, $cancel_note),
			)
		);

		if (! $updated) {
			return new WP_Error('cancel_failed', __('لغو رزرو انجام نشد.', 'luna-appointments'));
		}

		self::maybe_trigger_booking_status_transition(
			$booking_id,
			$booking,
			array(
				'status'         => 'cancelled',
				'payment_status' => $next_payment,
			),
			'user_cancel'
		);

		self::maybe_cancel_unpaid_linked_order($booking, __('لغو توسط مشتری.', 'luna-appointments'));

		self::upsert_booking_post_from_row_id($booking_id);
		self::clear_scheduled_reminders($booking_id);

		return true;
	}

	/** Cancel the linked WooCommerce order only while no money has been captured. */
	protected static function maybe_cancel_unpaid_linked_order($booking, $note = '') {
		$order_id = is_array($booking) && isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0;
		if ($order_id <= 0 || ! function_exists('wc_get_order')) {
			return false;
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order || $order->is_paid() || in_array($order->get_status(), array('cancelled', 'completed', 'refunded'), true)) {
			return false;
		}

		$order->update_status(
			'cancelled',
			'' !== trim((string) $note) ? (string) $note : __('رزرو مرتبط لغو شد.', 'luna-appointments'),
			true
		);
		return true;
	}

	/** Check a booking's current slot while excluding the booking itself. */
	protected static function booking_slot_has_conflict($booking) {
		if (! is_array($booking) || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return false;
		}

		$specialist_id = (int) ($booking['specialist_id'] ?? 0);
		$date          = (string) ($booking['booking_date'] ?? '');
		$time          = (string) ($booking['booking_time'] ?? '');
		if ($specialist_id <= 0 || '' === $date || '' === $time) {
			return false;
		}

		return Luna_Appointments_Bookings_Table::slot_exists(
			$specialist_id,
			$date,
			$time,
			(int) ($booking['duration_minutes'] ?? 0),
			(int) ($booking['buffer_minutes'] ?? 0),
			(int) ($booking['id'] ?? 0)
		);
	}

	protected static function insert_booking_with_slot_guard($booking_data, $hold_lock = false) {
		if (! class_exists('Luna_Appointments_Bookings_Table')) {
			return new WP_Error('missing_table_class', __('در حال حاضر امکان ذخیره‌سازی رزرو در دسترس نیست.', 'luna-appointments'));
		}

		$specialist_id     = isset($booking_data['specialist_id']) ? (int) $booking_data['specialist_id'] : 0;
		$booking_date      = isset($booking_data['booking_date']) ? (string) $booking_data['booking_date'] : '';
		$booking_time      = isset($booking_data['booking_time']) ? (string) $booking_data['booking_time'] : '';
		$duration_minutes  = isset($booking_data['duration_minutes']) ? (int) $booking_data['duration_minutes'] : 0;
		$buffer_minutes    = isset($booking_data['buffer_minutes']) ? (int) $booking_data['buffer_minutes'] : 0;

		if ($specialist_id <= 0 || '' === $booking_date || '' === $booking_time) {
			return new WP_Error('invalid_slot', __('اطلاعات زمان رزرو کامل نیست.', 'luna-appointments'));
		}

		if (! Luna_Appointments_Bookings_Table::acquire_slot_lock($specialist_id, $booking_date, 5)) {
			return new WP_Error('slot_lock_timeout', __('همزمان درخواست دیگری برای همین زمان در حال ثبت است. لطفاً چند لحظه دیگر دوباره تلاش کنید.', 'luna-appointments'));
		}

		$transaction_started = Luna_Appointments_Bookings_Table::begin_transaction();
		try {
			if (Luna_Appointments_Bookings_Table::slot_exists($specialist_id, $booking_date, $booking_time, $duration_minutes, $buffer_minutes)) {
				if ($transaction_started) {
					Luna_Appointments_Bookings_Table::rollback_transaction();
				}
				return new WP_Error('slot_taken', __('این زمان همین حالا رزرو شده است. لطفاً زمان دیگری را انتخاب کنید.', 'luna-appointments'));
			}

			$result = Luna_Appointments_Bookings_Table::insert_booking($booking_data, false);
			if (is_wp_error($result)) {
				if ($transaction_started) {
					Luna_Appointments_Bookings_Table::rollback_transaction();
				}
				return $result;
			}
			if ($transaction_started && ! Luna_Appointments_Bookings_Table::commit_transaction()) {
				Luna_Appointments_Bookings_Table::rollback_transaction();
				return new WP_Error('booking_commit_failed', __('ثبت نهایی رزرو انجام نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'));
			}
			do_action('luna_appointments_booking_created', (int) $result, $booking_data);
			return $result;
		} catch (Throwable $error) {
			if ($transaction_started) {
				Luna_Appointments_Bookings_Table::rollback_transaction();
			}
			error_log('Luna booking transaction failed: ' . $error->getMessage());
			return new WP_Error('booking_transaction_failed', __('ثبت تراکنشی رزرو انجام نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'));
		} finally {
			if (! $hold_lock) {
				Luna_Appointments_Bookings_Table::release_slot_lock($specialist_id, $booking_date);
			}
		}
	}

	protected static function reschedule_booking_from_user($booking, $user_id, $new_date, $new_time) {
		unset($user_id);
		if (! class_exists('Luna_Appointments_Bookings_Table')) {
			return new WP_Error('table_missing', __('امکان تغییر زمان رزرو در حال حاضر فراهم نیست.', 'luna-appointments'));
		}

		if (! self::can_user_change_booking($booking)) {
			return new WP_Error('cannot_reschedule', __('این رزرو قابل تغییر زمان نیست.', 'luna-appointments'));
		}

		$new_date = trim((string) $new_date);
		$new_time = trim((string) $new_time);
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) || ! preg_match('/^\d{2}:\d{2}$/', $new_time)) {
			return new WP_Error('invalid_datetime', __('تاریخ یا ساعت وارد شده معتبر نیست.', 'luna-appointments'));
		}

		$booking_id    = isset($booking['id']) ? (int) $booking['id'] : 0;
		$specialist_id = isset($booking['specialist_id']) ? (int) $booking['specialist_id'] : 0;
		$schedule      = $specialist_id > 0 ? self::get_specialist_schedule($specialist_id) : array();

		if (! self::is_specialist_open_for_date($schedule, $new_date)) {
			return new WP_Error('closed_date', __('این متخصص در تاریخ انتخاب‌شده فعال نیست.', 'luna-appointments'));
		}

		$duration_minutes = isset($booking['duration_minutes']) ? (int) $booking['duration_minutes'] : 0;
		$buffer_minutes   = isset($booking['buffer_minutes']) ? (int) $booking['buffer_minutes'] : 0;
		if (! self::is_time_allowed_by_schedule($new_time, $duration_minutes, $buffer_minutes, $schedule, $new_date)) {
			return new WP_Error('closed_time', __('این ساعت خارج از بازه کاری متخصص است.', 'luna-appointments'));
		}

		$new_timestamp = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::timestamp($new_date, $new_time) : strtotime($new_date . ' ' . $new_time);
		if (! $new_timestamp || $new_timestamp < current_datetime()->getTimestamp()) {
			return new WP_Error('datetime_past', __('زمان انتخاب‌شده گذشته است.', 'luna-appointments'));
		}

		$existing_notes = isset($booking['notes']) ? (string) $booking['notes'] : '';
		if (! Luna_Appointments_Bookings_Table::acquire_slot_lock($specialist_id, $new_date, 5)) {
			return new WP_Error('slot_lock_timeout', __('همزمان درخواست دیگری برای همین زمان در حال ثبت است. لطفاً چند لحظه دیگر دوباره تلاش کنید.', 'luna-appointments'));
		}

		try {
			if ($specialist_id > 0 && Luna_Appointments_Bookings_Table::slot_exists($specialist_id, $new_date, $new_time, $duration_minutes, $buffer_minutes, $booking_id)) {
				return new WP_Error('slot_taken', __('این زمان همین حالا رزرو شده است. لطفاً زمان دیگری را انتخاب کنید.', 'luna-appointments'));
			}

			$updated = Luna_Appointments_Bookings_Table::update_booking(
				$booking_id,
				array(
					'booking_date' => $new_date,
					'booking_time' => $new_time,
					'notes'        => self::append_booking_note($existing_notes, __('زمان رزرو توسط مشتری تغییر کرد.', 'luna-appointments')),
				)
			);
		} finally {
			Luna_Appointments_Bookings_Table::release_slot_lock($specialist_id, $new_date);
		}

		if (! $updated) {
			return new WP_Error('reschedule_failed', __('تغییر زمان رزرو انجام نشد.', 'luna-appointments'));
		}

		if (! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $booking['wc_order_id']);
			if ($order instanceof WC_Order) {
				$order->update_meta_data('_luna_booking_date', $new_date);
				$order->update_meta_data('_luna_booking_time', $new_time);
				$order->set_customer_note(
					sprintf(
						__('رزرو به زمان جدید منتقل شد: %1$s %2$s.', 'luna-appointments'),
						$new_date,
						$new_time
					)
				);
				$order->save();
			}
		}

		self::upsert_booking_post_from_row_id($booking_id);
		self::clear_scheduled_reminders($booking_id);
		self::maybe_schedule_booking_reminders($booking_id);
                $current_booking = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;
                if (is_array($current_booking)) {
                        self::maybe_send_booking_lifecycle_notification('rescheduled', $current_booking, $booking, 'user_reschedule');
                }

		return true;
	}

	protected static function get_booking_reminder_settings() {
		$settings = Luna_Appointments_API::settings();
		$minutes  = isset($settings['booking_reminder_minutes_before']) ? (int) preg_replace('/[^\d]/', '', (string) $settings['booking_reminder_minutes_before']) : 120;
		$minutes  = max(5, $minutes);

		return array(
			'sms_enabled'      => isset($settings['booking_reminder_sms_enabled']) && 'yes' === (string) $settings['booking_reminder_sms_enabled'],
			'wa_enabled'       => isset($settings['booking_reminder_whatsapp_enabled']) && 'yes' === (string) $settings['booking_reminder_whatsapp_enabled'],
			'minutes_before'   => $minutes,
			'sms_url'          => isset($settings['booking_sms_webhook_url']) ? trim((string) $settings['booking_sms_webhook_url']) : '',
			'wa_url'           => isset($settings['booking_whatsapp_webhook_url']) ? trim((string) $settings['booking_whatsapp_webhook_url']) : '',
			'token'            => isset($settings['booking_webhook_token']) ? trim((string) $settings['booking_webhook_token']) : '',
		);
	}

	protected static function maybe_schedule_booking_reminders($booking_id) {
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) {
			return;
		}

		$status = isset($booking['status']) ? sanitize_key((string) $booking['status']) : '';
		if ('confirmed' !== $status) {
			self::clear_scheduled_reminders($booking_id);
			return;
		}

		$settings = self::get_booking_reminder_settings();
		$timestamp = class_exists('Luna_Appointments_Date')
			? Luna_Appointments_Date::timestamp((string) $booking['booking_date'], (string) $booking['booking_time'])
			: strtotime((string) $booking['booking_date'] . ' ' . (string) $booking['booking_time']);
		if (! $timestamp) {
			return;
		}

		self::clear_scheduled_reminders($booking_id);

		$run_at = $timestamp - ((int) $settings['minutes_before'] * 60);
		$run_at = max(current_datetime()->getTimestamp() + 60, $run_at);

		if (! empty($settings['sms_enabled']) && '' !== (string) $settings['sms_url']) {
			wp_schedule_single_event($run_at, 'luna_booking_send_reminder', array($booking_id, 'sms'));
		}
		if (! empty($settings['wa_enabled']) && '' !== (string) $settings['wa_url']) {
			wp_schedule_single_event($run_at, 'luna_booking_send_reminder', array($booking_id, 'whatsapp'));
		}
	}

	protected static function clear_scheduled_reminders($booking_id) {
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0) {
			return;
		}

		foreach (array('sms', 'whatsapp') as $channel) {
			$next = wp_next_scheduled('luna_booking_send_reminder', array($booking_id, $channel));
			while ($next) {
				wp_unschedule_event($next, 'luna_booking_send_reminder', array($booking_id, $channel));
				$next = wp_next_scheduled('luna_booking_send_reminder', array($booking_id, $channel));
			}
		}
	}

	public static function send_booking_reminder($booking_id, $channel, $force = false) {
		$booking_id = (int) $booking_id;
		$channel    = sanitize_key((string) $channel);
		$force      = (bool) $force;

		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) {
			return;
		}

		$status = isset($booking['status']) ? sanitize_key((string) $booking['status']) : '';
		if ('confirmed' !== $status) {
			return;
		}

		$post_id = self::find_booking_post_id($booking_id);
		$sent_key = 'sms' === $channel ? '_luna_reminder_sms_sent_at' : '_luna_reminder_whatsapp_sent_at';
		if (! $force && $post_id > 0 && '' !== (string) get_post_meta($post_id, $sent_key, true)) {
			return;
		}

		$settings = self::get_booking_reminder_settings();
		$url      = 'sms' === $channel ? (string) $settings['sms_url'] : (string) $settings['wa_url'];
		if ('' === trim($url)) {
			return;
		}

		$payload = array(
			'channel'         => $channel,
			'booking_id'      => $booking_id,
			'booking_code'    => isset($booking['booking_code']) ? (string) $booking['booking_code'] : '',
			'customer_name'   => isset($booking['customer_name']) ? (string) $booking['customer_name'] : '',
			'customer_phone'  => isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '',
			'customer_email'  => isset($booking['customer_email']) ? (string) $booking['customer_email'] : '',
			'service_name'    => isset($booking['service_name']) ? (string) $booking['service_name'] : '',
			'specialist_name' => isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '',
			'booking_date'    => isset($booking['booking_date']) ? (string) $booking['booking_date'] : '',
			'booking_time'    => isset($booking['booking_time']) ? (string) $booking['booking_time'] : '',
			'datetime_label'  => self::format_booking_datetime_label($booking),
			'is_vip'          => ! empty($booking['is_vip']) ? 1 : 0,
		);

		$response = self::dispatch_reminder_webhook($url, (string) $settings['token'], $payload);
		$code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
		$ok       = $code >= 200 && $code < 300;

		$note_label = 'sms' === $channel ? __('یادآوری پیامک', 'luna-appointments') : __('یادآوری واتساپ', 'luna-appointments');
		$existing_notes = isset($booking['notes']) ? (string) $booking['notes'] : '';
		$error_message = is_wp_error($response) ? $response->get_error_message() : '';
		$note = $ok
			? sprintf(__('%1$s ارسال شد (کد %2$s).', 'luna-appointments'), $note_label, $code ? (string) $code : '—')
			: sprintf(__('%1$s ارسال نشد (کد %2$s).', 'luna-appointments'), $note_label, $code ? (string) $code : '—');
		if (! $ok && '' !== $error_message) {
			$note .= ' ' . sprintf(__('خطا: %s', 'luna-appointments'), $error_message);
		}
		Luna_Appointments_Bookings_Table::update_booking($booking_id, array('notes' => self::append_booking_note($existing_notes, $note)));
		self::upsert_booking_post_from_row_id($booking_id);

		if ($post_id > 0) {
			$prefix = 'sms' === $channel ? '_luna_reminder_sms' : '_luna_reminder_whatsapp';
			update_post_meta($post_id, $prefix . '_last_at', class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql'));
			update_post_meta($post_id, $prefix . '_last_code', $code ? (string) $code : '');
			update_post_meta($post_id, $prefix . '_last_ok', $ok ? '1' : '0');
		}

		if ($ok && $post_id > 0) {
			update_post_meta($post_id, $sent_key, class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql'));
		}
	}

	public static function handle_manual_reminder_send() {
		if (! current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You do not have permission to send reminders.', 'luna-appointments'));
		}

		$booking_post_id = isset($_POST['post_id']) ? (int) wp_unslash($_POST['post_id']) : 0;
		$booking_id      = isset($_POST['booking_id']) ? (int) wp_unslash($_POST['booking_id']) : 0;
		$channel         = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '';
		$nonce           = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		if (! $booking_post_id || '' === $nonce || ! wp_verify_nonce($nonce, 'luna_manual_reminder_' . $booking_post_id)) {
			wp_die(esc_html__('Invalid request.', 'luna-appointments'));
		}

		if ($booking_id > 0 && in_array($channel, array('sms', 'whatsapp'), true)) {
			self::send_booking_reminder($booking_id, $channel, true);
		}

		$redirect = $booking_post_id > 0 ? get_edit_post_link($booking_post_id, 'raw') : admin_url();
		wp_safe_redirect(add_query_arg(array('luna_reminder' => '1'), $redirect));
		exit;
	}

	protected static function dispatch_reminder_webhook($url, $token, $payload) {
                return self::dispatch_booking_webhook($url, $token, $payload);
        }

        protected static function get_booking_lifecycle_settings() {
		$settings = Luna_Appointments_API::settings();

                return array(
                        'url'          => isset($settings['booking_lifecycle_webhook_url']) ? trim((string) $settings['booking_lifecycle_webhook_url']) : '',
                        'token'        => isset($settings['booking_lifecycle_webhook_token']) ? trim((string) $settings['booking_lifecycle_webhook_token']) : '',
                        'on_created'   => ! isset($settings['booking_notify_on_created']) || 'no' !== (string) $settings['booking_notify_on_created'],
                        'on_cancelled' => ! isset($settings['booking_notify_on_cancelled']) || 'no' !== (string) $settings['booking_notify_on_cancelled'],
                        'on_rescheduled' => ! isset($settings['booking_notify_on_rescheduled']) || 'no' !== (string) $settings['booking_notify_on_rescheduled'],
                        'on_paid'      => ! isset($settings['booking_notify_on_paid']) || 'no' !== (string) $settings['booking_notify_on_paid'],
                        'on_refunded'  => ! isset($settings['booking_notify_on_refunded']) || 'no' !== (string) $settings['booking_notify_on_refunded'],
                );
        }

        protected static function dispatch_booking_webhook($url, $token, $payload) {
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'    => wp_json_encode($payload),
		);

		$token = trim((string) $token);
		if ('' !== $token) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return wp_remote_post(esc_url_raw($url), $args);
	}

        protected static function maybe_send_booking_lifecycle_notification($event, $booking, $previous_booking = null, $source = '') {
                $event    = sanitize_key((string) $event);
                $booking  = is_array($booking) ? $booking : array();
                $settings = self::get_booking_lifecycle_settings();
                $url      = isset($settings['url']) ? (string) $settings['url'] : '';

                if ('' === $url || empty($booking) || empty($booking['id'])) {
                        return;
                }

                $enabled_map = array(
                        'created'     => ! empty($settings['on_created']),
                        'cancelled'   => ! empty($settings['on_cancelled']),
                        'rescheduled' => ! empty($settings['on_rescheduled']),
                        'paid'        => ! empty($settings['on_paid']),
                        'refunded'    => ! empty($settings['on_refunded']),
                );

                if (empty($enabled_map[ $event ])) {
                        return;
                }

                $payload = self::build_booking_lifecycle_payload($event, $booking, $previous_booking, $source);
                self::dispatch_booking_webhook($url, isset($settings['token']) ? (string) $settings['token'] : '', $payload);
        }

        protected static function build_booking_lifecycle_payload($event, $booking, $previous_booking = null, $source = '') {
                $booking          = is_array($booking) ? $booking : array();
                $previous_booking = is_array($previous_booking) ? $previous_booking : array();
                $snapshot         = self::get_booking_finance_snapshot($booking);

                return array(
                        'event'            => sanitize_key((string) $event),
                        'source'           => sanitize_key((string) $source),
                        'booking_id'       => isset($booking['id']) ? (int) $booking['id'] : 0,
                        'booking_code'     => isset($booking['booking_code']) ? (string) $booking['booking_code'] : '',
                        'status'           => isset($booking['status']) ? (string) $booking['status'] : '',
                        'payment_status'   => isset($booking['payment_status']) ? (string) $booking['payment_status'] : '',
                        'status_label'     => self::format_status_label(isset($booking['status']) ? (string) $booking['status'] : '', isset($booking['payment_status']) ? (string) $booking['payment_status'] : ''),
                        'customer_name'    => isset($booking['customer_name']) ? (string) $booking['customer_name'] : '',
                        'customer_phone'   => isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '',
                        'customer_email'   => isset($booking['customer_email']) ? (string) $booking['customer_email'] : '',
                        'service_name'     => isset($booking['service_name']) ? (string) $booking['service_name'] : '',
                        'specialist_name'  => isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '',
                        'booking_date'     => isset($booking['booking_date']) ? (string) $booking['booking_date'] : '',
                        'booking_time'     => isset($booking['booking_time']) ? (string) $booking['booking_time'] : '',
                        'datetime_label'   => self::format_booking_datetime_label($booking),
                        'wc_order_id'      => isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0,
                        'payment_method'   => isset($booking['payment_method']) ? (string) $booking['payment_method'] : '',
                        'is_vip'           => ! empty($booking['is_vip']) ? 1 : 0,
                        'finance'          => $snapshot,
                        'previous_status'  => isset($previous_booking['status']) ? (string) $previous_booking['status'] : '',
                        'previous_payment_status' => isset($previous_booking['payment_status']) ? (string) $previous_booking['payment_status'] : '',
                        'previous_date'    => isset($previous_booking['booking_date']) ? (string) $previous_booking['booking_date'] : '',
                        'previous_time'    => isset($previous_booking['booking_time']) ? (string) $previous_booking['booking_time'] : '',
                );
        }

        public static function handle_booking_transition_notifications($booking_id, $old_status, $new_status, $old_payment_status, $new_payment_status, $current_booking, $previous_booking, $source) {
                unset($booking_id, $old_status);

                if (is_array($previous_booking) && is_array($current_booking)) {
                        $old_date = isset($previous_booking['booking_date']) ? (string) $previous_booking['booking_date'] : '';
                        $old_time = isset($previous_booking['booking_time']) ? (string) $previous_booking['booking_time'] : '';
                        $new_date = isset($current_booking['booking_date']) ? (string) $current_booking['booking_date'] : '';
                        $new_time = isset($current_booking['booking_time']) ? (string) $current_booking['booking_time'] : '';

                        if (($old_date && $new_date && $old_date !== $new_date) || ($old_time && $new_time && $old_time !== $new_time)) {
                                self::maybe_send_booking_lifecycle_notification('rescheduled', $current_booking, $previous_booking, $source);
                        }
                }

                if ('cancelled' === (string) $new_status) {
                        self::maybe_send_booking_lifecycle_notification('cancelled', $current_booking, $previous_booking, $source);
                }

                if ('paid' === (string) $new_payment_status) {
                        if ('paid' !== (string) $old_payment_status) {
                                self::maybe_send_booking_lifecycle_notification('paid', $current_booking, $previous_booking, $source);
                        }
                }

                if ('refunded' === (string) $new_payment_status) {
                        if ('refunded' !== (string) $old_payment_status) {
                                self::maybe_send_booking_lifecycle_notification('refunded', $current_booking, $previous_booking, $source);
                        }
                }
        }

	public static function register_booking_admin_pages() {
		add_menu_page(
			__('رزروها', 'luna-appointments'),
			__('رزروها', 'luna-appointments'),
			'edit_theme_options',
			self::$booking_dashboard_slug,
			array(__CLASS__, 'render_booking_dashboard'),
			'dashicons-calendar-alt',
			57
		);

		add_submenu_page(
			self::$booking_dashboard_slug,
			__('داشبورد رزروها', 'luna-appointments'),
			__('داشبورد', 'luna-appointments'),
			'edit_theme_options',
			self::$booking_dashboard_slug,
			array(__CLASS__, 'render_booking_dashboard')
		);

		add_submenu_page(
			self::$booking_dashboard_slug,
			__('لیست رزروها', 'luna-appointments'),
			__('لیست رزروها', 'luna-appointments'),
			'edit_theme_options',
			'edit.php?post_type=' . self::$booking_post_type
		);

		add_submenu_page(
			self::$booking_dashboard_slug,
			__('مدیریت و گزارش رزروها', 'luna-appointments'),
			__('مدیریت پیشرفته', 'luna-appointments'),
			'edit_theme_options',
			Luna_Appointments_Bookings_Admin::get_page_slug(),
			array('Luna_Appointments_Bookings_Admin', 'render_page')
		);

		add_submenu_page(
			self::$booking_dashboard_slug,
			__('تقویم رزروها', 'luna-appointments'),
			__('تقویم', 'luna-appointments'),
			'edit_theme_options',
			'luna-bookings-calendar',
			array(__CLASS__, 'render_booking_calendar')
		);

                add_submenu_page(
                        self::$booking_dashboard_slug,
                        __('خروجی CSV رزروها', 'luna-appointments'),
                        __('خروجی CSV', 'luna-appointments'),
                        'edit_theme_options',
                        self::$booking_exports_slug,
                        array(__CLASS__, 'render_booking_exports_page')
                );
	}

	public static function render_booking_dashboard() {
		if (! current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You do not have permission to view bookings.', 'luna-appointments'));
		}

		$status_counts  = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_status_counts() : array();
		$payment_counts = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_payment_status_counts() : array();
		$total          = 0;
		foreach ((array) $status_counts as $count) {
			$total += (int) $count;
		}

		$today_count = 0;
		$vip_count   = 0;
		if (class_exists('Luna_Appointments_Bookings_Table')) {
			global $wpdb;
			$table = Luna_Appointments_Bookings_Table::get_table_name();
			$today = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_today() : current_datetime()->format('Y-m-d');
			$today_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_date = %s", $today));
			$vip_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_vip = 1");
		}

		$pending_payment = isset($status_counts['pending_payment']) ? (int) $status_counts['pending_payment'] : 0;
		$confirmed       = isset($status_counts['confirmed']) ? (int) $status_counts['confirmed'] : 0;
		$cancelled       = isset($status_counts['cancelled']) ? (int) $status_counts['cancelled'] : 0;
		$paid            = isset($payment_counts['paid']) ? (int) $payment_counts['paid'] : 0;

		$list_url = admin_url('edit.php?post_type=' . self::$booking_post_type);
		$link_pending   = add_query_arg(array('luna_status' => 'pending_payment'), $list_url);
		$link_confirmed = add_query_arg(array('luna_status' => 'confirmed'), $list_url);
		$link_cancelled = add_query_arg(array('luna_status' => 'cancelled'), $list_url);
		$today_key      = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_today() : current_datetime()->format('Y-m-d');
		$link_today     = add_query_arg(array('luna_from' => $today_key, 'luna_to' => $today_key), $list_url);
		$link_vip       = add_query_arg(array('luna_vip' => '1'), $list_url);

		$latest = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::query_bookings(array('paged' => 1, 'per_page' => 8))
			: array('items' => array(), 'total' => 0);
		$items = isset($latest['items']) && is_array($latest['items']) ? $latest['items'] : array();

		echo '<div class="wrap luna-bookings-dashboard">';
		echo '<div class="luna-dash-head">';
		echo '<div class="luna-dash-title">';
		echo '<span class="luna-eyebrow">' . esc_html__('پنل رزروها', 'luna-appointments') . '</span>';
		echo '<h1>' . esc_html__('رزروها', 'luna-appointments') . '</h1>';
		echo '<p class="luna-dash-sub">' . esc_html__('نمای کلی وضعیت رزروها و دسترسی سریع به فیلترهای مهم.', 'luna-appointments') . '</p>';
		echo '</div>';
		echo '<div class="luna-dash-actions">';
		echo '<a class="button button-primary" href="' . esc_url($list_url) . '">' . esc_html__('مشاهده لیست رزروها', 'luna-appointments') . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<div class="luna-dash-cards">';
		echo '<a class="luna-card" href="' . esc_url($list_url) . '"><div class="k">' . esc_html__('کل رزروها', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $total)) . '</div></a>';
		echo '<a class="luna-card" href="' . esc_url($link_today) . '"><div class="k">' . esc_html__('رزروهای امروز', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $today_count)) . '</div></a>';
		echo '<a class="luna-card" href="' . esc_url($link_vip) . '"><div class="k">' . esc_html__('رزروهای VIP', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $vip_count)) . '</div></a>';
		echo '<a class="luna-card warn" href="' . esc_url($link_pending) . '"><div class="k">' . esc_html__('در انتظار پرداخت', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $pending_payment)) . '</div></a>';
		echo '<a class="luna-card ok" href="' . esc_url($link_confirmed) . '"><div class="k">' . esc_html__('تایید شده', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $confirmed)) . '</div></a>';
		echo '<a class="luna-card bad" href="' . esc_url($link_cancelled) . '"><div class="k">' . esc_html__('لغو شده', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $cancelled)) . '</div></a>';
		echo '<div class="luna-card neutral"><div class="k">' . esc_html__('پرداخت شده', 'luna-appointments') . '</div><div class="v">' . esc_html(self::to_persian_digits((string) $paid)) . '</div></div>';
		echo '</div>';

		$daily = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_daily_counts(14) : array();
		$top_services = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_top_services(30, 8) : array();
		$top_specialists = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_top_specialists(30, 8) : array();
		$max_daily = 0;
		foreach ((array) $daily as $d) {
			$max_daily = max($max_daily, isset($d['total']) ? (int) $d['total'] : 0);
		}

		echo '<div class="luna-dash-grid">';
		echo '<div class="luna-panel">';
		echo '<div class="luna-panel-head"><div><h2>' . esc_html__('روند ثبت رزرو در ۱۴ روز اخیر', 'luna-appointments') . '</h2><span class="luna-muted">' . esc_html__('بر اساس روز ایجاد رزرو، نه تاریخ مراجعه', 'luna-appointments') . '</span></div><div class="luna-chart-legend"><span class="is-total">' . esc_html__('کل', 'luna-appointments') . '</span><span class="is-confirmed">' . esc_html__('تاییدشده', 'luna-appointments') . '</span><span class="is-cancelled">' . esc_html__('لغوشده', 'luna-appointments') . '</span></div></div>';
		if ($max_daily <= 0) {
			echo '<div class="luna-chart-empty"><strong>' . esc_html__('هنوز رزروی در این بازه ثبت نشده است.', 'luna-appointments') . '</strong><span>' . esc_html__('با ثبت اولین رزرو، آمار روزانه در این بخش نمایش داده می‌شود.', 'luna-appointments') . '</span></div>';
		} else {
			echo '<div class="luna-chart">';
			foreach ((array) $daily as $d) {
			$date = isset($d['date']) ? (string) $d['date'] : '';
			$total_day = isset($d['total']) ? (int) $d['total'] : 0;
			$conf_day  = isset($d['confirmed']) ? (int) $d['confirmed'] : 0;
			$can_day   = isset($d['cancelled']) ? (int) $d['cancelled'] : 0;
			$pct_total = $max_daily > 0 ? (int) round(($total_day / $max_daily) * 100) : 0;
			$pct_conf  = $max_daily > 0 ? (int) round(($conf_day / $max_daily) * 100) : 0;
			$pct_can   = $max_daily > 0 ? (int) round(($can_day / $max_daily) * 100) : 0;
			$label     = $date && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali_day($date) : '';
			$display_date = $date && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($date, '', false) : $date;
			$chart_label = sprintf(__('تاریخ %1$s: کل %2$d، تاییدشده %3$d، لغوشده %4$d', 'luna-appointments'), $display_date, $total_day, $conf_day, $can_day);
			echo '<div class="luna-bar" title="' . esc_attr($chart_label) . '" aria-label="' . esc_attr($chart_label) . '">';
			echo '<div class="luna-bar-stack" style="--t:' . esc_attr((string) $pct_total) . '%;--c:' . esc_attr((string) $pct_conf) . '%;--x:' . esc_attr((string) $pct_can) . '%;"><i></i>' . ($total_day > 0 ? '<b>' . esc_html(self::to_persian_digits((string) $total_day)) . '</b>' : '') . '</div>';
			echo '<div class="luna-bar-label">' . esc_html($label) . '</div>';
			echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="luna-panel">';
		echo '<div class="luna-panel-head"><h2>' . esc_html__('بیشترین رزرو (۳۰ روز اخیر)', 'luna-appointments') . '</h2></div>';
		echo '<div class="luna-top-grid">';
		echo '<div>';
		echo '<h3 class="luna-subhead">' . esc_html__('خدمات', 'luna-appointments') . '</h3>';
		if (empty($top_services)) {
			echo '<p class="luna-empty">' . esc_html__('داده‌ای موجود نیست.', 'luna-appointments') . '</p>';
		} else {
			echo '<ol class="luna-top-list">';
			foreach ((array) $top_services as $row) {
				$name = isset($row['name']) ? (string) $row['name'] : '';
				$count = isset($row['total']) ? (int) $row['total'] : 0;
				echo '<li><span class="n">' . esc_html($name) . '</span><span class="v">' . esc_html(self::to_persian_digits((string) $count)) . '</span></li>';
			}
			echo '</ol>';
		}
		echo '</div>';
		echo '<div>';
		echo '<h3 class="luna-subhead">' . esc_html__('متخصص‌ها', 'luna-appointments') . '</h3>';
		if (empty($top_specialists)) {
			echo '<p class="luna-empty">' . esc_html__('داده‌ای موجود نیست.', 'luna-appointments') . '</p>';
		} else {
			echo '<ol class="luna-top-list">';
			foreach ((array) $top_specialists as $row) {
				$name = isset($row['name']) ? (string) $row['name'] : '';
				$count = isset($row['total']) ? (int) $row['total'] : 0;
				echo '<li><span class="n">' . esc_html($name) . '</span><span class="v">' . esc_html(self::to_persian_digits((string) $count)) . '</span></li>';
			}
			echo '</ol>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="luna-dash-latest">';
		echo '<div class="luna-panel">';
		echo '<div class="luna-panel-head"><h2>' . esc_html__('آخرین رزروها', 'luna-appointments') . '</h2><a class="luna-link" href="' . esc_url($list_url) . '">' . esc_html__('مشاهده همه', 'luna-appointments') . '</a></div>';
		if (empty($items)) {
			echo '<p class="luna-empty">' . esc_html__('رزروی برای نمایش وجود ندارد.', 'luna-appointments') . '</p>';
		} else {
			echo '<div class="luna-latest-table">';
			echo '<div class="row head"><div>' . esc_html__('کد', 'luna-appointments') . '</div><div>' . esc_html__('نام مشتری', 'luna-appointments') . '</div><div>' . esc_html__('خدمت', 'luna-appointments') . '</div><div>' . esc_html__('تاریخ و ساعت', 'luna-appointments') . '</div><div>' . esc_html__('وضعیت', 'luna-appointments') . '</div></div>';
			foreach ($items as $row) {
				$booking_id = isset($row['id']) ? (int) $row['id'] : 0;
				$post_id    = $booking_id > 0 ? self::find_booking_post_id($booking_id) : 0;
				$edit_link  = $post_id > 0 ? get_edit_post_link($post_id, 'raw') : '';
				$code       = isset($row['booking_code']) ? (string) $row['booking_code'] : '';
				$cust       = isset($row['customer_name']) ? (string) $row['customer_name'] : '';
				$svc        = isset($row['service_name']) ? (string) $row['service_name'] : '';
				$date_lbl   = self::format_booking_datetime_label($row);
				$st         = isset($row['status']) ? (string) $row['status'] : '';
				$pay        = isset($row['payment_status']) ? (string) $row['payment_status'] : '';
				$badge_cls  = 'badge badge-' . sanitize_html_class($st ? $st : 'unknown');
				echo '<div class="row">';
				echo '<div>' . ($edit_link ? '<a class="luna-code" href="' . esc_url($edit_link) . '">' . esc_html($code) . '</a>' : esc_html($code)) . '</div>';
				echo '<div>' . esc_html($cust) . '</div>';
				echo '<div>' . esc_html($svc) . '</div>';
				echo '<div>' . esc_html($date_lbl) . '</div>';
				echo '<div><span class="' . esc_attr($badge_cls) . '">' . esc_html(self::format_status_label($st, $pay)) . '</span></div>';
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	public static function render_booking_calendar() {
		if (! current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You do not have permission to view bookings.', 'luna-appointments'));
		}

		$view   = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'week';
		$date   = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : (class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_today() : current_datetime()->format('Y-m-d'));
		$status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			$date = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_today() : current_datetime()->format('Y-m-d');
		}

		$days = 'day' === $view ? 1 : 7;
		$start = $date;
		$start_ts = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::timestamp($start) : strtotime($start);
		if (! $start_ts) {
			$start_ts = current_datetime()->getTimestamp();
			$start    = self::gregorian_date_from_timestamp($start_ts);
		}
		$end    = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::add_days($start, $days - 1) : self::gregorian_date_from_timestamp($start_ts + (($days - 1) * DAY_IN_SECONDS));

		$items = array();
		if (class_exists('Luna_Appointments_Bookings_Table')) {
			$result = Luna_Appointments_Bookings_Table::query_bookings_by_date_range($start, $end, array('status' => $status, 'limit' => 900));
			$items  = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
		}

		$by_date = array();
		foreach ((array) $items as $row) {
			$d = isset($row['booking_date']) ? (string) $row['booking_date'] : '';
			if ('' === $d) {
				continue;
			}
			if (! isset($by_date[ $d ])) {
				$by_date[ $d ] = array();
			}
			$by_date[ $d ][] = $row;
		}

		$base_url = admin_url('admin.php?page=luna-bookings-calendar');
		$prev_date = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::add_days($start, -$days) : self::gregorian_date_from_timestamp($start_ts - ($days * DAY_IN_SECONDS));
		$next_date = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::add_days($start, $days) : self::gregorian_date_from_timestamp($start_ts + ($days * DAY_IN_SECONDS));
		$prev_url = add_query_arg(array('view' => $view, 'date' => $prev_date, 'status' => $status), $base_url);
		$next_url = add_query_arg(array('view' => $view, 'date' => $next_date, 'status' => $status), $base_url);

		echo '<div class="wrap luna-bookings-calendar">';
		echo '<div class="luna-dash-head">';
		echo '<div class="luna-dash-title">';
		echo '<span class="luna-eyebrow">' . esc_html__('تقویم رزروها', 'luna-appointments') . '</span>';
		echo '<h1>' . esc_html__('نمای روز/هفته', 'luna-appointments') . '</h1>';
		echo '<p class="luna-dash-sub">' . esc_html__('برای مدیریت سریع رزروها بر اساس تاریخ.', 'luna-appointments') . '</p>';
		echo '</div>';
		echo '<div class="luna-dash-actions" style="display:flex;gap:10px;align-items:center;">';
		echo '<a class="button" href="' . esc_url($prev_url) . '">' . esc_html__('قبلی', 'luna-appointments') . '</a>';
		echo '<a class="button" href="' . esc_url($next_url) . '">' . esc_html__('بعدی', 'luna-appointments') . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<form method="get" class="luna-cal-filters" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 16px;">';
		echo '<input type="hidden" name="page" value="luna-bookings-calendar">';
		echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<span class="luna-muted">' . esc_html__('نمایش', 'luna-appointments') . '</span>';
		echo '<select name="view" style="height:38px;border-radius:12px;border:1px solid var(--luna-line);padding:8px 10px;background:rgba(255,255,255,.75);">';
		echo '<option value="week"' . selected($view, 'week', false) . '>' . esc_html__('هفته', 'luna-appointments') . '</option>';
		echo '<option value="day"' . selected($view, 'day', false) . '>' . esc_html__('روز', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '</label>';
		echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<span class="luna-muted">' . esc_html__('تاریخ شروع', 'luna-appointments') . '</span>';
		echo '<input type="date" name="date" value="' . esc_attr($start) . '" style="height:38px;border-radius:12px;border:1px solid var(--luna-line);padding:8px 10px;background:rgba(255,255,255,.75);">';
		echo '</label>';
		echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<span class="luna-muted">' . esc_html__('وضعیت', 'luna-appointments') . '</span>';
		echo '<select name="status" style="height:38px;border-radius:12px;border:1px solid var(--luna-line);padding:8px 10px;background:rgba(255,255,255,.75);">';
		echo '<option value="">' . esc_html__('همه', 'luna-appointments') . '</option>';
		echo '<option value="pending_payment"' . selected($status, 'pending_payment', false) . '>' . esc_html__('در انتظار پرداخت', 'luna-appointments') . '</option>';
		echo '<option value="confirmed"' . selected($status, 'confirmed', false) . '>' . esc_html__('تایید شده', 'luna-appointments') . '</option>';
		echo '<option value="cancelled"' . selected($status, 'cancelled', false) . '>' . esc_html__('لغو شده', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '</label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__('نمایش', 'luna-appointments') . '</button>';
		echo '</form>';

		echo '<div class="luna-cal-grid">';
		for ($i = 0; $i < $days; $i++) {
			$day_date = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::add_days($start, $i) : self::gregorian_date_from_timestamp($start_ts + ($i * DAY_IN_SECONDS));
			$label = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($day_date, '', true) : $day_date;

			echo '<section class="luna-panel luna-cal-day">';
			echo '<div class="luna-panel-head"><h2 style="font-size:14px;margin:0;">' . esc_html($label) . '</h2></div>';

			$rows = isset($by_date[ $day_date ]) ? (array) $by_date[ $day_date ] : array();
			if (empty($rows)) {
				echo '<p class="luna-empty">' . esc_html__('رزروی برای این تاریخ ثبت نشده است.', 'luna-appointments') . '</p>';
			} else {
				echo '<div class="luna-cal-table">';
				echo '<div class="row head"><div>' . esc_html__('ساعت', 'luna-appointments') . '</div><div>' . esc_html__('مشتری', 'luna-appointments') . '</div><div>' . esc_html__('خدمت', 'luna-appointments') . '</div><div>' . esc_html__('متخصص', 'luna-appointments') . '</div><div>' . esc_html__('وضعیت', 'luna-appointments') . '</div><div>' . esc_html__('ویرایش', 'luna-appointments') . '</div></div>';
				foreach ($rows as $r) {
					$booking_id = isset($r['id']) ? (int) $r['id'] : 0;
					$post_id    = $booking_id > 0 ? self::find_booking_post_id($booking_id) : 0;
					$edit_link  = $post_id > 0 ? get_edit_post_link($post_id, 'raw') : '';
					$time       = isset($r['booking_time']) ? (string) $r['booking_time'] : '';
					$cust       = isset($r['customer_name']) ? (string) $r['customer_name'] : '';
					$svc        = isset($r['service_name']) ? (string) $r['service_name'] : '';
					$spec       = isset($r['specialist_name']) ? (string) $r['specialist_name'] : '';
					$st         = isset($r['status']) ? (string) $r['status'] : '';
					$pay        = isset($r['payment_status']) ? (string) $r['payment_status'] : '';
					$badge_cls  = 'badge badge-' . sanitize_html_class($st ? $st : 'unknown');

					echo '<div class="row">';
					echo '<div>' . esc_html(self::to_persian_digits($time)) . '</div>';
					echo '<div>' . esc_html($cust) . '</div>';
					echo '<div>' . esc_html($svc) . '</div>';
					echo '<div>' . esc_html($spec) . '</div>';
					echo '<div><span class="' . esc_attr($badge_cls) . '">' . esc_html(self::format_account_status_label($st, $pay)) . '</span></div>';
					echo '<div>' . ($edit_link ? '<a class="luna-link" href="' . esc_url($edit_link) . '">' . esc_html__('باز کردن', 'luna-appointments') . '</a>' : '—') . '</div>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</section>';
		}
		echo '</div>';
		echo '</div>';
	}

        public static function render_booking_exports_page() {
                if (! current_user_can('edit_theme_options')) {
                        wp_die(esc_html__('You do not have permission to export bookings.', 'luna-appointments'));
                }

                $from_date      = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : current_datetime()->format('Y-m-01');
                $to_date        = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : current_datetime()->format('Y-m-d');
                $status         = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
                $payment_status = isset($_GET['payment_status']) ? sanitize_key(wp_unslash($_GET['payment_status'])) : '';
                $search         = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

                echo '<div class="wrap">';
                echo '<h1>' . esc_html__('خروجی CSV رزروها', 'luna-appointments') . '</h1>';
                echo '<p>' . esc_html__('برای دریافت فایل CSV رزروها، بازه تاریخ و فیلترهای موردنظر را انتخاب کنید. این خروجی برای اکسل و گزارش‌گیری مدیریتی مناسب است.', 'luna-appointments') . '</p>';
                echo '<div class="postbox" style="padding:20px;max-width:980px;">';
                echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;align-items:end;">';
                echo '<input type="hidden" name="page" value="' . esc_attr(self::$booking_exports_slug) . '">';
                echo '<label style="display:grid;gap:6px;"><span>' . esc_html__('از تاریخ', 'luna-appointments') . '</span><input type="date" name="from_date" value="' . esc_attr($from_date) . '"></label>';
                echo '<label style="display:grid;gap:6px;"><span>' . esc_html__('تا تاریخ', 'luna-appointments') . '</span><input type="date" name="to_date" value="' . esc_attr($to_date) . '"></label>';
                echo '<label style="display:grid;gap:6px;"><span>' . esc_html__('وضعیت رزرو', 'luna-appointments') . '</span><select name="status"><option value="">' . esc_html__('همه', 'luna-appointments') . '</option><option value="pending_payment"' . selected($status, 'pending_payment', false) . '>' . esc_html__('در انتظار پرداخت', 'luna-appointments') . '</option><option value="payment_review"' . selected($status, 'payment_review', false) . '>' . esc_html__('در انتظار بررسی', 'luna-appointments') . '</option><option value="consultation_pending"' . selected($status, 'consultation_pending', false) . '>' . esc_html__('در انتظار مشاوره', 'luna-appointments') . '</option><option value="confirmed"' . selected($status, 'confirmed', false) . '>' . esc_html__('تایید شده', 'luna-appointments') . '</option><option value="cancelled"' . selected($status, 'cancelled', false) . '>' . esc_html__('لغو شده', 'luna-appointments') . '</option><option value="failed"' . selected($status, 'failed', false) . '>' . esc_html__('ناموفق', 'luna-appointments') . '</option><option value="refunded"' . selected($status, 'refunded', false) . '>' . esc_html__('برگشت', 'luna-appointments') . '</option></select></label>';
                echo '<label style="display:grid;gap:6px;"><span>' . esc_html__('وضعیت پرداخت', 'luna-appointments') . '</span><select name="payment_status"><option value="">' . esc_html__('همه', 'luna-appointments') . '</option><option value="pending"' . selected($payment_status, 'pending', false) . '>' . esc_html__('در انتظار پرداخت', 'luna-appointments') . '</option><option value="deposit_paid"' . selected($payment_status, 'deposit_paid', false) . '>' . esc_html__('هزینه اولیه پرداخت شده', 'luna-appointments') . '</option><option value="not_required"' . selected($payment_status, 'not_required', false) . '>' . esc_html__('بدون نیاز به پرداخت', 'luna-appointments') . '</option><option value="authorized"' . selected($payment_status, 'authorized', false) . '>' . esc_html__('در انتظار تایید پرداخت', 'luna-appointments') . '</option><option value="paid"' . selected($payment_status, 'paid', false) . '>' . esc_html__('پرداخت شده', 'luna-appointments') . '</option><option value="failed"' . selected($payment_status, 'failed', false) . '>' . esc_html__('ناموفق', 'luna-appointments') . '</option><option value="cancelled"' . selected($payment_status, 'cancelled', false) . '>' . esc_html__('لغو شده', 'luna-appointments') . '</option><option value="refunded"' . selected($payment_status, 'refunded', false) . '>' . esc_html__('برگشت', 'luna-appointments') . '</option></select></label>';
                echo '<label style="display:grid;gap:6px;grid-column:1 / span 3;"><span>' . esc_html__('جستجو', 'luna-appointments') . '</span><input type="text" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('کد رزرو، نام مشتری، موبایل، ایمیل، خدمت یا متخصص', 'luna-appointments') . '"></label>';
                echo '<div style="display:flex;gap:10px;justify-content:flex-end;">';
                echo '<button type="submit" class="button">' . esc_html__('اعمال فیلتر', 'luna-appointments') . '</button>';
                echo '</div>';
                echo '</form>';

                echo '<hr style="margin:20px 0;">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
                wp_nonce_field('luna_booking_export_csv');
                echo '<input type="hidden" name="action" value="luna_booking_export_csv">';
                echo '<input type="hidden" name="from_date" value="' . esc_attr($from_date) . '">';
                echo '<input type="hidden" name="to_date" value="' . esc_attr($to_date) . '">';
                echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
                echo '<input type="hidden" name="payment_status" value="' . esc_attr($payment_status) . '">';
                echo '<input type="hidden" name="s" value="' . esc_attr($search) . '">';
                echo '<button type="submit" class="button button-primary">' . esc_html__('دانلود فایل CSV', 'luna-appointments') . '</button>';
                echo '<span style="color:#6b7280;">' . esc_html__('فایل با UTF-8 BOM تولید می‌شود تا در اکسل فارسی به‌درستی باز شود.', 'luna-appointments') . '</span>';
                echo '</form>';
                echo '</div>';
                echo '</div>';
        }

        public static function handle_booking_export_csv() {
                if (! current_user_can('edit_theme_options')) {
                        wp_die(esc_html__('You do not have permission to export bookings.', 'luna-appointments'));
                }

                check_admin_referer('luna_booking_export_csv');

                $args = array(
                        'from_date'      => isset($_POST['from_date']) ? sanitize_text_field(wp_unslash($_POST['from_date'])) : '',
                        'to_date'        => isset($_POST['to_date']) ? sanitize_text_field(wp_unslash($_POST['to_date'])) : '',
                        'status'         => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '',
                        'payment_status' => isset($_POST['payment_status']) ? sanitize_key(wp_unslash($_POST['payment_status'])) : '',
                        'search'         => isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '',
                        'limit'          => 3000,
                );

                $items = class_exists('Luna_Appointments_Bookings_Table')
                        ? Luna_Appointments_Bookings_Table::query_bookings_for_export($args)
                        : array();

                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="luna-bookings-' . gmdate('Ymd-His') . '.csv"');

                $out = fopen('php://output', 'w');
                if (! $out) {
                        exit;
                }

                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array('Booking ID', 'Booking Code', 'Status', 'Payment Status', 'Customer', 'Phone', 'Email', 'Service', 'Specialist', 'Booking Date', 'Booking Time', 'DateTime Label', 'VIP', 'Base Amount', 'Payment Method', 'WC Order ID', 'Created At', 'Updated At'));

                foreach ((array) $items as $booking) {
                        fputcsv(
                                $out,
                                array(
                                        isset($booking['id']) ? (int) $booking['id'] : 0,
                                        isset($booking['booking_code']) ? (string) $booking['booking_code'] : '',
                                        isset($booking['status']) ? (string) $booking['status'] : '',
                                        isset($booking['payment_status']) ? (string) $booking['payment_status'] : '',
                                        isset($booking['customer_name']) ? (string) $booking['customer_name'] : '',
                                        isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '',
                                        isset($booking['customer_email']) ? (string) $booking['customer_email'] : '',
                                        isset($booking['service_name']) ? (string) $booking['service_name'] : '',
                                        isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '',
                                        isset($booking['booking_date']) ? (string) $booking['booking_date'] : '',
                                        isset($booking['booking_time']) ? (string) $booking['booking_time'] : '',
                                        self::format_booking_datetime_label($booking),
                                        ! empty($booking['is_vip']) ? '1' : '0',
                                        isset($booking['base_price']) ? (float) $booking['base_price'] : 0,
                                        isset($booking['payment_method']) ? (string) $booking['payment_method'] : '',
                                        isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0,
                                        isset($booking['created_at']) ? (string) $booking['created_at'] : '',
                                        isset($booking['updated_at']) ? (string) $booking['updated_at'] : '',
                                )
                        );
                }

                fclose($out);
                exit;
        }

        public static function render_booking_receipt_page() {
                $booking_id = isset($_GET['booking_id']) ? (int) wp_unslash($_GET['booking_id']) : 0;
                $nonce      = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

                if ($booking_id <= 0 || '' === $nonce || ! wp_verify_nonce($nonce, 'luna_booking_receipt_' . $booking_id)) {
                        wp_die(esc_html__('درخواست رسید معتبر نیست.', 'luna-appointments'));
                }

                if (! class_exists('Luna_Appointments_Bookings_Table')) {
                        wp_die(esc_html__('داده رزرو در دسترس نیست.', 'luna-appointments'));
                }

                $booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
                if (! is_array($booking)) {
                        wp_die(esc_html__('رزرو پیدا نشد.', 'luna-appointments'));
                }

                if (! self::current_user_can_view_booking_receipt($booking)) {
                        wp_die(esc_html__('اجازه مشاهده این رسید را ندارید.', 'luna-appointments'));
                }

                $finance    = self::get_booking_finance_snapshot($booking);
                $order_id   = isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0;
                $order_link = $order_id > 0 ? get_edit_post_link($order_id, 'raw') : '';

                nocache_headers();
                echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
                echo '<title>' . esc_html(sprintf(__('رسید رزرو %s', 'luna-appointments'), isset($booking['booking_code']) ? (string) $booking['booking_code'] : '#' . $booking_id)) . '</title>';
                echo '<style>body{margin:0;background:#f4f1ee;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Tahoma,sans-serif}a{color:#253042;text-decoration:none}.luna-receipt{max-width:860px;margin:32px auto;padding:0 20px}.luna-receipt__shell{background:#fff;border:1px solid rgba(37,48,66,.08);border-radius:28px;box-shadow:0 20px 50px rgba(15,23,42,.08);overflow:hidden}.luna-receipt__head{padding:28px 32px;background:linear-gradient(135deg,#253042 0%,#39465e 100%);color:#fff}.luna-receipt__head small{display:block;opacity:.72;font-size:12px;margin-bottom:10px}.luna-receipt__head h1{margin:0 0 8px;font-size:28px}.luna-receipt__head p{margin:0;opacity:.84;line-height:1.9}.luna-receipt__toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 32px;border-bottom:1px solid rgba(37,48,66,.08);background:#faf8f7}.luna-receipt__toolbar .button{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:14px;border:1px solid rgba(37,48,66,.12);background:#fff;color:#253042;font-weight:700}.luna-receipt__toolbar .button.primary{background:#253042;color:#fff;border-color:#253042}.luna-receipt__body{padding:30px 32px;display:grid;gap:22px}.luna-receipt__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.luna-receipt__card{padding:18px;border:1px solid rgba(37,48,66,.08);border-radius:20px;background:#fff}.luna-receipt__card h2{margin:0 0 14px;font-size:16px}.luna-receipt__facts{display:grid;gap:10px}.luna-receipt__fact{display:flex;justify-content:space-between;gap:16px;padding-bottom:10px;border-bottom:1px dashed rgba(37,48,66,.12)}.luna-receipt__fact:last-child{padding-bottom:0;border-bottom:0}.luna-receipt__fact strong{color:#111827}.luna-receipt__totals{display:grid;gap:10px}.luna-receipt__totals .row{display:flex;justify-content:space-between;gap:16px}.luna-receipt__totals .row.total{margin-top:6px;padding-top:12px;border-top:1px solid rgba(37,48,66,.1);font-size:18px;font-weight:800}.luna-receipt__footer{padding:0 32px 28px;color:#6b7280;line-height:1.9}.luna-status-chip{display:inline-flex;padding:8px 12px;border-radius:999px;background:rgba(37,48,66,.08);font-weight:800}@media print{body{background:#fff}.luna-receipt{max-width:none;margin:0;padding:0}.luna-receipt__shell{border:0;border-radius:0;box-shadow:none}.luna-receipt__toolbar{display:none}}@media (max-width:760px){.luna-receipt__grid{grid-template-columns:minmax(0,1fr)}.luna-receipt__head,.luna-receipt__toolbar,.luna-receipt__body,.luna-receipt__footer{padding-right:20px;padding-left:20px}}</style>';
                echo '</head><body><div class="luna-receipt"><div class="luna-receipt__shell">';
                echo '<header class="luna-receipt__head"><small>' . esc_html__('Luna Booking Receipt', 'luna-appointments') . '</small><h1>' . esc_html(isset($booking['booking_code']) ? (string) $booking['booking_code'] : sprintf(__('رزرو #%d', 'luna-appointments'), $booking_id)) . '</h1><p>' . esc_html__('این برگه خلاصه وضعیت رزرو، اطلاعات مشتری و جزئیات مالی ثبت‌شده در لونا را نمایش می‌دهد.', 'luna-appointments') . '</p></header>';
                echo '<div class="luna-receipt__toolbar"><div><span class="luna-status-chip">' . esc_html(self::format_status_label(isset($booking['status']) ? (string) $booking['status'] : '', isset($booking['payment_status']) ? (string) $booking['payment_status'] : '')) . '</span></div><div style="display:flex;gap:10px;flex-wrap:wrap;"><button type="button" class="button primary" onclick="window.print()">' . esc_html__('چاپ / ذخیره PDF', 'luna-appointments') . '</button>' . ($order_link ? '<a class="button" href="' . esc_url($order_link) . '">' . esc_html__('مشاهده سفارش', 'luna-appointments') . '</a>' : '') . '</div></div>';
                echo '<div class="luna-receipt__body">';
                echo '<div class="luna-receipt__grid">';
                echo '<section class="luna-receipt__card"><h2>' . esc_html__('مشخصات رزرو', 'luna-appointments') . '</h2><div class="luna-receipt__facts">';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('کد رزرو', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['booking_code']) ? (string) $booking['booking_code'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('تاریخ و ساعت', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_datetime_label($booking)) . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('خدمت', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['service_name']) ? (string) $booking['service_name'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('متخصص', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('روش پرداخت', 'luna-appointments') . '</span><strong>' . esc_html(self::get_payment_label(isset($booking['payment_method']) ? (string) $booking['payment_method'] : '')) . '</strong></div>';
                echo '</div></section>';
                echo '<section class="luna-receipt__card"><h2>' . esc_html__('مشخصات مشتری', 'luna-appointments') . '</h2><div class="luna-receipt__facts">';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('نام', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['customer_name']) ? (string) $booking['customer_name'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('موبایل', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('ایمیل', 'luna-appointments') . '</span><strong>' . esc_html(isset($booking['customer_email']) ? (string) $booking['customer_email'] : '—') . '</strong></div>';
                echo '<div class="luna-receipt__fact"><span>' . esc_html__('وضعیت VIP', 'luna-appointments') . '</span><strong>' . esc_html(! empty($booking['is_vip']) ? __('بله', 'luna-appointments') : __('خیر', 'luna-appointments')) . '</strong></div>';
                echo '</div></section>';
                echo '</div>';
                echo '<section class="luna-receipt__card"><h2>' . esc_html__('جمع‌بندی مالی', 'luna-appointments') . '</h2><div class="luna-receipt__totals">';
                echo '<div class="row"><span>' . esc_html__('مبلغ پایه', 'luna-appointments') . '</span><strong>' . esc_html(self::format_receipt_money(isset($finance['base_amount']) ? $finance['base_amount'] : 0)) . '</strong></div>';
                echo '<div class="row"><span>' . esc_html__('تخفیف', 'luna-appointments') . '</span><strong>' . esc_html(self::format_receipt_money(isset($finance['discount_amount']) ? $finance['discount_amount'] : 0)) . '</strong></div>';
                echo '<div class="row"><span>' . esc_html__('گیفت‌کارت', 'luna-appointments') . '</span><strong>' . esc_html(self::format_receipt_money(isset($finance['gift_amount']) ? $finance['gift_amount'] : 0)) . '</strong></div>';
                echo '<div class="row"><span>' . esc_html__('کیف پول', 'luna-appointments') . '</span><strong>' . esc_html(self::format_receipt_money(isset($finance['wallet_amount']) ? $finance['wallet_amount'] : 0)) . '</strong></div>';
                echo '<div class="row total"><span>' . esc_html__('مبلغ نهایی قابل پرداخت', 'luna-appointments') . '</span><strong>' . esc_html(self::format_receipt_money(isset($finance['payable_amount']) ? $finance['payable_amount'] : 0)) . '</strong></div>';
                echo '</div></section>';
                echo '</div>';
                echo '<div class="luna-receipt__footer"><p>' . esc_html__('این رسید برای بایگانی، چاپ یا ذخیره به‌صورت PDF مناسب است. در صورت اختلاف، داده‌های ثبت‌شده در پنل مدیریت و سفارش ووکامرس ملاک نهایی هستند.', 'luna-appointments') . '</p></div>';
                echo '</div></div></body></html>';
                exit;
        }

        protected static function current_user_can_view_booking_receipt($booking) {
                if (current_user_can('edit_theme_options')) {
                        return true;
                }

                if (! is_user_logged_in() || ! is_array($booking)) {
                        return false;
                }

                $user_id = (int) get_current_user_id();

                return $user_id > 0 && $user_id === (int) ($booking['customer_user_id'] ?? 0);
        }

	public static function filter_booking_columns($columns) {
		unset($columns['date']);

		$columns['title']          = __('رزرو', 'luna-appointments');
		$columns['vip']            = __('VIP', 'luna-appointments');
		$columns['customer_name']  = __('نام مشتری', 'luna-appointments');
		$columns['customer_phone'] = __('موبایل', 'luna-appointments');
		$columns['service_name']   = __('خدمت', 'luna-appointments');
		$columns['specialist_name']= __('متخصص', 'luna-appointments');
		$columns['booking_time']   = __('تاریخ و ساعت', 'luna-appointments');
		$columns['status']         = __('وضعیت', 'luna-appointments');
		$columns['quick_actions']  = __('عملیات', 'luna-appointments');
		$columns['finance']        = __('مالی لونا', 'luna-appointments');
		$columns['payment_error']  = __('خطای پرداخت', 'luna-appointments');
		$columns['wc_order_id']    = __('سفارش', 'luna-appointments');
		$columns['date']           = __('بروزرسانی', 'luna-appointments');

		return $columns;
	}

	public static function render_booking_column($column, $post_id) {
		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		$booking    = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;

		if (! is_array($booking)) {
			echo '—';
			return;
		}

		switch ($column) {
			case 'vip':
				echo ! empty($booking['is_vip']) ? '<span class="luna-status luna-status-confirmed">VIP</span>' : '—';
				break;
			case 'customer_name':
				echo esc_html(isset($booking['customer_name']) ? (string) $booking['customer_name'] : '');
				break;
			case 'customer_phone':
				echo esc_html(isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '');
				break;
			case 'service_name':
				echo esc_html(isset($booking['service_name']) ? (string) $booking['service_name'] : '');
				break;
			case 'specialist_name':
				echo esc_html(isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '');
				break;
			case 'booking_time':
				echo esc_html(self::format_booking_datetime_label($booking));
				break;
			case 'status':
				$status = isset($booking['status']) ? (string) $booking['status'] : '';
				$pay    = isset($booking['payment_status']) ? (string) $booking['payment_status'] : '';
				$cls    = 'luna-status luna-status-' . sanitize_html_class($status ? $status : 'unknown');
				$paycls = $pay ? ' luna-pay luna-pay-' . sanitize_html_class($pay) : '';
				echo '<span class="' . esc_attr($cls . $paycls) . '">' . esc_html(self::format_status_label($status, $pay)) . '</span>';
				break;
			case 'quick_actions':
				self::render_booking_quick_actions($post_id, $booking);
				break;
			case 'finance':
				echo wp_kses_post(self::get_booking_finance_summary_markup($booking, true));
				break;
			case 'payment_error':
				if (! empty($booking['payment_error'])) {
					echo '<span style="display:block;max-width:240px;padding:7px 9px;border-radius:8px;background:#fff1f2;color:#9f1239;font-size:11px">' . esc_html((string) $booking['payment_error']) . '</span>';
				} else {
					echo '—';
				}
				break;
			case 'wc_order_id':
				$order_id = isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0;
				if ($order_id > 0) {
					$link = function_exists('get_edit_post_link') ? get_edit_post_link($order_id, 'raw') : '';
					if ($link) {
						echo '<a class="luna-order-link" href="' . esc_url($link) . '">#' . esc_html((string) $order_id) . '</a>';
					} else {
						echo esc_html('#' . (string) $order_id);
					}
				} else {
					echo '—';
				}
				break;
		}
	}

	/** Render secure status shortcuts in the booking list. */
	protected static function render_booking_quick_actions($post_id, $booking) {
		if (! current_user_can('edit_post', $post_id)) {
			echo '—';
			return;
		}

		$status = isset($booking['status']) ? sanitize_key((string) $booking['status']) : '';
		$url    = admin_url('admin-post.php');
		$nonce  = wp_create_nonce('luna_booking_quick_status_' . $post_id);
		echo '<div class="luna-booking-quick-actions">';
		if ('confirmed' !== $status) {
			echo '<form method="post" action="' . esc_url($url) . '"><input type="hidden" name="action" value="luna_booking_quick_status"><input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '"><input type="hidden" name="booking_status" value="confirmed"><input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '"><button type="submit" class="button luna-quick-confirm">' . esc_html__('تأیید', 'luna-appointments') . '</button></form>';
		} else {
			echo '<span class="luna-quick-done">' . esc_html__('تأیید شده', 'luna-appointments') . '</span>';
		}
		if ('cancelled' !== $status) {
			echo '<form method="post" action="' . esc_url($url) . '" onsubmit="return window.confirm(\'' . esc_js(__('این رزرو لغو شود؟', 'luna-appointments')) . '\');"><input type="hidden" name="action" value="luna_booking_quick_status"><input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '"><input type="hidden" name="booking_status" value="cancelled"><input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '"><button type="submit" class="button luna-quick-cancel">' . esc_html__('لغو', 'luna-appointments') . '</button></form>';
		} else {
			echo '<span class="luna-quick-cancelled">' . esc_html__('لغو شده', 'luna-appointments') . '</span>';
		}
		echo '</div>';
	}

	/** Apply a list-table status action and keep the booking mirror/integrations in sync. */
	public static function handle_booking_quick_status() {
		$post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
		$status  = isset($_POST['booking_status']) ? sanitize_key(wp_unslash($_POST['booking_status'])) : '';

		if ($post_id <= 0 || ! in_array($status, array('confirmed', 'cancelled'), true)) {
			wp_die(esc_html__('درخواست نامعتبر است.', 'luna-appointments'), '', array('response' => 400));
		}
		check_admin_referer('luna_booking_quick_status_' . $post_id);
		if (! current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('اجازه انجام این عملیات را ندارید.', 'luna-appointments'), '', array('response' => 403));
		}

		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		$existing   = $booking_id > 0 && class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking($booking_id) : null;
		if (! is_array($existing)) {
			wp_die(esc_html__('رکورد رزرو پیدا نشد.', 'luna-appointments'), '', array('response' => 404));
		}
		if ('confirmed' === $status && self::booking_slot_has_conflict($existing)) {
			wp_die(esc_html__('این بازه با رزرو دیگری تداخل دارد و امکان تأیید آن وجود ندارد.', 'luna-appointments'), '', array('response' => 409));
		}

		$note = 'confirmed' === $status ? __('رزرو از فهرست مدیریت تأیید شد.', 'luna-appointments') : __('رزرو از فهرست مدیریت لغو شد.', 'luna-appointments');
		$changes = array(
			'status' => $status,
			'notes'  => self::append_booking_note(isset($existing['notes']) ? (string) $existing['notes'] : '', $note),
		);
		if ('cancelled' === $status && ! in_array((string) ($existing['payment_status'] ?? ''), array('paid', 'refunded'), true)) {
			$changes['payment_status'] = 'cancelled';
		}

		Luna_Appointments_Bookings_Table::update_booking($booking_id, $changes);
		self::maybe_trigger_booking_status_transition($booking_id, $existing, $changes, 'admin_quick_action');
		self::upsert_booking_post_from_row_id($booking_id);

		if ('cancelled' === $status) {
			self::clear_scheduled_reminders($booking_id);
			self::maybe_cancel_unpaid_linked_order($existing, __('رزرو توسط مدیریت لغو شد.', 'luna-appointments'));
		} else {
			self::maybe_schedule_booking_reminders($booking_id);
		}

		$redirect = wp_get_referer();
		if (! $redirect || false === strpos($redirect, 'post_type=' . self::$booking_post_type)) {
			$redirect = admin_url('edit.php?post_type=' . self::$booking_post_type);
		}
		wp_safe_redirect(add_query_arg('luna_booking_quick_updated', $status, $redirect));
		exit;
	}

	protected static function get_booking_finance_snapshot($booking) {
		$snapshot = array(
			'base_amount'    => 0.0,
			'discount_amount'=> 0.0,
			'gift_amount'    => 0.0,
			'wallet_amount'  => 0.0,
			'payable_amount' => 0.0,
			'discount_code'  => '',
			'gift_code'      => '',
			'use_wallet'     => false,
			'status'         => '',
			'order_id'       => isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0,
		);

		$order = false;
		if (! empty($snapshot['order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order($snapshot['order_id']);
		}

		$quote = array();
		$context = array();

		if ($order instanceof WC_Order) {
			$snapshot['base_amount']     = (float) $order->get_meta('_luna_booking_base_amount', true);
			$snapshot['discount_amount'] = (float) $order->get_meta('_luna_booking_discount_amount', true);
			$snapshot['gift_amount']     = (float) $order->get_meta('_luna_booking_gift_amount', true);
			$snapshot['wallet_amount']   = (float) $order->get_meta('_luna_booking_wallet_amount', true);
			$snapshot['payable_amount']  = (float) $order->get_meta('_luna_booking_payable_amount', true);
			$snapshot['status']          = (string) $order->get_status();
			$quote                       = self::decode_booking_finance_json($order->get_meta('_luna_booking_finance_quote', true));
		}

		if (class_exists('Luna_Finance_Tables') && ! empty($booking['id'])) {
			$allocation = Luna_Finance_Tables::get_allocation_by_booking_id((int) $booking['id']);
			if (is_array($allocation)) {
				if ($snapshot['base_amount'] <= 0) {
					$snapshot['base_amount'] = isset($allocation['base_amount']) ? (float) $allocation['base_amount'] : 0.0;
				}
				if ($snapshot['discount_amount'] <= 0) {
					$snapshot['discount_amount'] = isset($allocation['discount_amount']) ? (float) $allocation['discount_amount'] : 0.0;
				}
				if ($snapshot['gift_amount'] <= 0) {
					$snapshot['gift_amount'] = isset($allocation['gift_amount']) ? (float) $allocation['gift_amount'] : 0.0;
				}
				if ($snapshot['wallet_amount'] <= 0) {
					$snapshot['wallet_amount'] = isset($allocation['wallet_amount']) ? (float) $allocation['wallet_amount'] : 0.0;
				}
				if ($snapshot['payable_amount'] <= 0) {
					$snapshot['payable_amount'] = isset($allocation['payable_amount']) ? (float) $allocation['payable_amount'] : 0.0;
				}
				if ('' === $snapshot['status']) {
					$snapshot['status'] = isset($allocation['status']) ? (string) $allocation['status'] : '';
				}

				$allocation_meta = self::decode_booking_finance_json(isset($allocation['meta_json']) ? $allocation['meta_json'] : '');
				if (empty($quote) && isset($allocation_meta['quote']) && is_array($allocation_meta['quote'])) {
					$quote = $allocation_meta['quote'];
				}
				if (isset($allocation_meta['context']) && is_array($allocation_meta['context'])) {
					$context = $allocation_meta['context'];
				}
			}
		}

		if (isset($quote['meta']['discount']['code'])) {
			$snapshot['discount_code'] = strtoupper(trim((string) $quote['meta']['discount']['code']));
		} elseif (isset($context['discount_code'])) {
			$snapshot['discount_code'] = strtoupper(trim((string) $context['discount_code']));
		}

		if (isset($quote['meta']['gift_card']['code'])) {
			$snapshot['gift_code'] = strtoupper(trim((string) $quote['meta']['gift_card']['code']));
		} elseif (isset($context['gift_card_code'])) {
			$snapshot['gift_code'] = strtoupper(trim((string) $context['gift_card_code']));
		}

		if (isset($quote['meta']['wallet']['requested'])) {
			$snapshot['use_wallet'] = ! empty($quote['meta']['wallet']['requested']);
		} elseif (isset($context['use_wallet'])) {
			$snapshot['use_wallet'] = ! empty($context['use_wallet']);
		}

		return $snapshot;
	}

	protected static function get_booking_finance_summary_markup($booking, $compact = false) {
		$finance = self::get_booking_finance_snapshot($booking);
		$has_finance = $finance['base_amount'] > 0 || $finance['discount_amount'] > 0 || $finance['gift_amount'] > 0 || $finance['wallet_amount'] > 0 || '' !== $finance['discount_code'] || '' !== $finance['gift_code'] || $finance['payable_amount'] > 0;

		if (! $has_finance) {
			return '—';
		}

		$lines = array();

		if ('' !== $finance['discount_code'] || $finance['discount_amount'] > 0) {
			$label   = __('تخفیف', 'luna-appointments');
			$code    = '' !== $finance['discount_code'] ? ' (' . $finance['discount_code'] . ')' : '';
			$amount  = $finance['discount_amount'] > 0 ? ' -' . self::format_booking_finance_amount($finance['discount_amount']) : '';
			$lines[] = '<div>' . esc_html($label . $code . $amount) . '</div>';
		}

		if ('' !== $finance['gift_code'] || $finance['gift_amount'] > 0) {
			$label   = __('گیفت‌کارت', 'luna-appointments');
			$code    = '' !== $finance['gift_code'] ? ' (' . $finance['gift_code'] . ')' : '';
			$amount  = $finance['gift_amount'] > 0 ? ' -' . self::format_booking_finance_amount($finance['gift_amount']) : '';
			$lines[] = '<div>' . esc_html($label . $code . $amount) . '</div>';
		}

		if ($finance['wallet_amount'] > 0 || $finance['use_wallet']) {
			$label   = __('کیف پول', 'luna-appointments');
			$amount  = $finance['wallet_amount'] > 0 ? ' -' . self::format_booking_finance_amount($finance['wallet_amount']) : '';
			$lines[] = '<div>' . esc_html($label . $amount) . '</div>';
		}

		if ($finance['payable_amount'] > 0 || $compact) {
			$lines[] = '<div><strong>' . esc_html__('قابل پرداخت', 'luna-appointments') . ':</strong> ' . esc_html(self::format_booking_finance_amount($finance['payable_amount'])) . '</div>';
		}

		if ($compact) {
			return '<div class="luna-booking-finance-compact">' . implode('', $lines) . '</div>';
		}

		return implode('', $lines);
	}

	protected static function get_booking_finance_meta_box_markup($booking) {
		$finance = self::get_booking_finance_snapshot($booking);
		$has_finance = $finance['base_amount'] > 0 || $finance['discount_amount'] > 0 || $finance['gift_amount'] > 0 || $finance['wallet_amount'] > 0 || $finance['payable_amount'] > 0 || '' !== $finance['discount_code'] || '' !== $finance['gift_code'];

		if (! $has_finance) {
			return '<p class="luna-booking-empty">' . esc_html__('هنوز داده مالی برای این رزرو ثبت نشده است.', 'luna-appointments') . '</p>';
		}

		$rows = array();
		$rows[] = '<li><span>' . esc_html__('مبلغ پایه', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_amount($finance['base_amount'])) . '</strong></li>';

		if ('' !== $finance['discount_code'] || $finance['discount_amount'] > 0) {
			$rows[] = '<li><span>' . esc_html__('کد تخفیف', 'luna-appointments') . '</span><strong>' . esc_html('' !== $finance['discount_code'] ? $finance['discount_code'] : '—') . '</strong></li>';
			$rows[] = '<li><span>' . esc_html__('مبلغ تخفیف', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_amount($finance['discount_amount'])) . '</strong></li>';
		}

		if ('' !== $finance['gift_code'] || $finance['gift_amount'] > 0) {
			$rows[] = '<li><span>' . esc_html__('گیفت‌کارت', 'luna-appointments') . '</span><strong>' . esc_html('' !== $finance['gift_code'] ? $finance['gift_code'] : '—') . '</strong></li>';
			$rows[] = '<li><span>' . esc_html__('اعتبار هدیه', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_amount($finance['gift_amount'])) . '</strong></li>';
		}

		if ($finance['wallet_amount'] > 0 || $finance['use_wallet']) {
			$rows[] = '<li><span>' . esc_html__('کیف پول', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_amount($finance['wallet_amount'])) . '</strong></li>';
		}

		$rows[] = '<li><span>' . esc_html__('مبلغ قابل پرداخت', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_amount($finance['payable_amount'])) . '</strong></li>';

		if ('' !== $finance['status']) {
			$rows[] = '<li><span>' . esc_html__('وضعیت مالی', 'luna-appointments') . '</span><strong>' . esc_html(self::format_booking_finance_state_label($finance['status'])) . '</strong></li>';
		}

		return '<ul class="luna-booking-finance-list">' . implode('', $rows) . '</ul>';
	}

	protected static function decode_booking_finance_json($value) {
		if (is_array($value)) {
			return $value;
		}

		if (! is_string($value) || '' === trim($value)) {
			return array();
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? $decoded : array();
	}

	protected static function format_booking_finance_amount($amount) {
		$amount = (float) $amount;

		if (function_exists('wc_price')) {
			return wp_strip_all_tags(wc_price($amount));
		}

		return number_format_i18n($amount);
	}

        protected static function format_receipt_money($amount) {
                return self::format_booking_finance_amount($amount);
        }

	protected static function format_booking_finance_state_label($status) {
		$map = array(
			'pending'   => __('در انتظار پرداخت', 'luna-appointments'),
			'on-hold'   => __('در انتظار بررسی پرداخت', 'luna-appointments'),
			'failed'    => __('پرداخت ناموفق', 'luna-appointments'),
			'cancelled' => __('لغو شده', 'luna-appointments'),
			'completed' => __('پرداخت و تکمیل شده', 'luna-appointments'),
			'refunded'  => __('بازپرداخت شده', 'luna-appointments'),
			'quoted'    => __('پیش‌نمایش شده', 'luna-appointments'),
			'reserved'  => __('رزرو شده', 'luna-appointments'),
			'committed' => __('نهایی شده', 'luna-appointments'),
			'released'  => __('آزاد شده', 'luna-appointments'),
			'processing'=> __('در حال پردازش', 'luna-appointments'),
		);

		$status = sanitize_key((string) $status);

		return isset($map[ $status ]) ? $map[ $status ] : ($status ? $status : __('نامشخص', 'luna-appointments'));
	}

	public static function filter_booking_sortable_columns($columns) {
		$columns['wc_order_id']  = 'wc_order_id';
		return $columns;
	}

	public static function filter_booking_admin_query($query) {
		if (! is_admin() || ! $query instanceof WP_Query) {
			return;
		}

		if (! $query->is_main_query()) {
			return;
		}

		if (self::$booking_post_type !== $query->get('post_type')) {
			return;
		}

		$meta_query = array();

		$status = isset($_GET['luna_status']) ? sanitize_key(wp_unslash($_GET['luna_status'])) : '';
		if ('' !== $status) {
			$meta_query[] = array(
				'key'     => '_luna_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$payment_status = isset($_GET['luna_payment_status']) ? sanitize_key(wp_unslash($_GET['luna_payment_status'])) : '';
		if ('' !== $payment_status) {
			$meta_query[] = array(
				'key'     => '_luna_payment_status',
				'value'   => $payment_status,
				'compare' => '=',
			);
		}

		$service_id = isset($_GET['luna_service']) ? (int) wp_unslash($_GET['luna_service']) : 0;
		if ($service_id > 0) {
			$meta_query[] = array(
				'key'     => '_luna_service_id',
				'value'   => $service_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		$specialist_id = isset($_GET['luna_specialist']) ? (int) wp_unslash($_GET['luna_specialist']) : 0;
		if ($specialist_id > 0) {
			$meta_query[] = array(
				'key'     => '_luna_specialist_id',
				'value'   => $specialist_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		$payment_method = isset($_GET['luna_payment_method']) ? sanitize_key(wp_unslash($_GET['luna_payment_method'])) : '';
		if ('' !== $payment_method) {
			$meta_query[] = array(
				'key'     => '_luna_payment_method',
				'value'   => $payment_method,
				'compare' => '=',
			);
		}

		$has_order = isset($_GET['luna_has_order']) ? sanitize_key(wp_unslash($_GET['luna_has_order'])) : '';
		if ('linked' === $has_order) {
			$meta_query[] = array(
				'key'     => '_luna_wc_order_id',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		} elseif ('unlinked' === $has_order) {
			$meta_query[] = array(
				'key'     => '_luna_wc_order_id',
				'value'   => 0,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		$date_from = isset($_GET['luna_from']) ? sanitize_text_field(wp_unslash($_GET['luna_from'])) : '';
		if ('' !== $date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
			$meta_query[] = array(
				'key'     => '_luna_booking_date',
				'value'   => $date_from,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		$date_to = isset($_GET['luna_to']) ? sanitize_text_field(wp_unslash($_GET['luna_to'])) : '';
		if ('' !== $date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
			$meta_query[] = array(
				'key'     => '_luna_booking_date',
				'value'   => $date_to,
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		$vip = isset($_GET['luna_vip']) ? sanitize_key(wp_unslash($_GET['luna_vip'])) : '';
		if ('1' === $vip) {
			$meta_query[] = array(
				'key'     => '_luna_is_vip',
				'value'   => 1,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		} elseif ('0' === $vip) {
			$meta_query[] = array(
				'key'     => '_luna_is_vip',
				'value'   => 0,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		if (! empty($meta_query)) {
			$query->set('meta_query', array_merge(array('relation' => 'AND'), $meta_query));
		}

		$order_by = (string) $query->get('orderby');
		if ('booking_code' === $order_by) {
			$query->set('meta_key', '_luna_booking_code');
			$query->set('orderby', 'meta_value');
		} elseif ('wc_order_id' === $order_by) {
			$query->set('meta_key', '_luna_wc_order_id');
			$query->set('orderby', 'meta_value_num');
		}
	}

	public static function render_booking_list_filters() {
		if (! is_admin()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen || self::$booking_post_type !== (string) $screen->post_type) {
			return;
		}

		$status = isset($_GET['luna_status']) ? sanitize_key(wp_unslash($_GET['luna_status'])) : '';
		$payment_status = isset($_GET['luna_payment_status']) ? sanitize_key(wp_unslash($_GET['luna_payment_status'])) : '';
		$service_id = isset($_GET['luna_service']) ? (int) wp_unslash($_GET['luna_service']) : 0;
		$specialist_id = isset($_GET['luna_specialist']) ? (int) wp_unslash($_GET['luna_specialist']) : 0;
		$payment_method = isset($_GET['luna_payment_method']) ? sanitize_key(wp_unslash($_GET['luna_payment_method'])) : '';
		$has_order = isset($_GET['luna_has_order']) ? sanitize_key(wp_unslash($_GET['luna_has_order'])) : '';
		$vip = isset($_GET['luna_vip']) ? sanitize_key(wp_unslash($_GET['luna_vip'])) : '';
		$date_from = isset($_GET['luna_from']) ? sanitize_text_field(wp_unslash($_GET['luna_from'])) : '';
		$date_to   = isset($_GET['luna_to']) ? sanitize_text_field(wp_unslash($_GET['luna_to'])) : '';

		echo '<select name="luna_status" class="postform">';
		echo '<option value="">' . esc_html__('همه وضعیت‌ها', 'luna-appointments') . '</option>';
		foreach (array('pending_payment', 'payment_review', 'consultation_pending', 'conflict', 'confirmed', 'cancelled', 'failed', 'refunded') as $opt) {
			echo '<option value="' . esc_attr($opt) . '"' . selected($status, $opt, false) . '>' . esc_html(self::format_status_label($opt, '')) . '</option>';
		}
		echo '</select>';

		echo '<select name="luna_payment_status" class="postform">';
		echo '<option value="">' . esc_html__('همه پرداخت‌ها', 'luna-appointments') . '</option>';
		foreach (array('paid', 'deposit_paid', 'pending', 'authorized', 'failed', 'cancelled', 'refunded', 'partial_refund') as $opt) {
			$label = self::format_status_label('', $opt);
			$label = strpos($label, '/') !== false ? trim(explode('/', $label)[1]) : $opt;
			echo '<option value="' . esc_attr($opt) . '"' . selected($payment_status, $opt, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';

		echo '<select name="luna_has_order" class="postform">';
		echo '<option value="">' . esc_html__('همه سفارش‌ها', 'luna-appointments') . '</option>';
		echo '<option value="linked"' . selected($has_order, 'linked', false) . '>' . esc_html__('دارای سفارش', 'luna-appointments') . '</option>';
		echo '<option value="unlinked"' . selected($has_order, 'unlinked', false) . '>' . esc_html__('بدون سفارش', 'luna-appointments') . '</option>';
		echo '</select>';

		echo '<select name="luna_vip" class="postform">';
		echo '<option value="">' . esc_html__('همه مشتری‌ها', 'luna-appointments') . '</option>';
		echo '<option value="1"' . selected($vip, '1', false) . '>' . esc_html__('فقط VIP', 'luna-appointments') . '</option>';
		echo '<option value="0"' . selected($vip, '0', false) . '>' . esc_html__('غیر VIP', 'luna-appointments') . '</option>';
		echo '</select>';

		$services = get_posts(array('post_type' => 'service', 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'posts_per_page' => 200, 'fields' => 'ids'));
		echo '<select name="luna_service" class="postform">';
		echo '<option value="0">' . esc_html__('همه خدمات', 'luna-appointments') . '</option>';
		foreach ((array) $services as $id) {
			$id = (int) $id;
			echo '<option value="' . esc_attr((string) $id) . '"' . selected($service_id, $id, false) . '>' . esc_html(get_the_title($id)) . '</option>';
		}
		echo '</select>';

		$specialists = get_posts(array('post_type' => 'specialist', 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'posts_per_page' => 200, 'fields' => 'ids'));
		echo '<select name="luna_specialist" class="postform">';
		echo '<option value="0">' . esc_html__('همه متخصص‌ها', 'luna-appointments') . '</option>';
		foreach ((array) $specialists as $id) {
			$id = (int) $id;
			echo '<option value="' . esc_attr((string) $id) . '"' . selected($specialist_id, $id, false) . '>' . esc_html(get_the_title($id)) . '</option>';
		}
		echo '</select>';

		$payment_options = self::get_payment_options();
		echo '<select name="luna_payment_method" class="postform">';
		echo '<option value="">' . esc_html__('همه روش‌های پرداخت', 'luna-appointments') . '</option>';
		foreach ((array) $payment_options as $opt) {
			$id = isset($opt['id']) ? sanitize_key((string) $opt['id']) : '';
			$label = isset($opt['label']) ? (string) $opt['label'] : $id;
			if ('' === $id) {
				continue;
			}
			echo '<option value="' . esc_attr($id) . '"' . selected($payment_method, $id, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';

		echo '<span class="luna-date-filter">';
		echo '<input type="date" name="luna_from" value="' . esc_attr($date_from) . '" />';
		echo '<input type="date" name="luna_to" value="' . esc_attr($date_to) . '" />';
		echo '</span>';
	}

	public static function filter_booking_list_views($views) {
		if (! class_exists('Luna_Appointments_Bookings_Table')) {
			return $views;
		}

		$counts = Luna_Appointments_Bookings_Table::get_status_counts();
		$base   = admin_url('edit.php?post_type=' . self::$booking_post_type);

		$make = static function ($label, $key, $count) use ($base) {
			$url = '' === $key ? $base : add_query_arg(array('luna_status' => $key), $base);
			return '<a href="' . esc_url($url) . '">' . esc_html($label) . ' <span class="count">(' . esc_html((string) (int) $count) . ')</span></a>';
		};

		$total = 0;
		foreach ((array) $counts as $c) {
			$total += (int) $c;
		}

		$views = array();
		$views['all']             = $make(__('همه', 'luna-appointments'), '', $total);
		$views['pending_payment'] = $make(__('در انتظار پرداخت', 'luna-appointments'), 'pending_payment', isset($counts['pending_payment']) ? (int) $counts['pending_payment'] : 0);
		$views['confirmed']       = $make(__('تایید شده', 'luna-appointments'), 'confirmed', isset($counts['confirmed']) ? (int) $counts['confirmed'] : 0);
		$views['cancelled']       = $make(__('لغو شده', 'luna-appointments'), 'cancelled', isset($counts['cancelled']) ? (int) $counts['cancelled'] : 0);

		return $views;
	}

	public static function register_booking_meta_boxes() {
		add_meta_box(
			'luna_booking_details',
			__('جزئیات رزرو', 'luna-appointments'),
			array(__CLASS__, 'render_booking_meta_box'),
			self::$booking_post_type,
			'normal',
			'high'
		);
		add_meta_box('luna_booking_history', __('تاریخچه و خطاهای پرداخت', 'luna-appointments'), array(__CLASS__, 'render_booking_history_meta_box'), self::$booking_post_type, 'normal', 'default');
	}

	public static function render_booking_history_meta_box($post) {
		$booking_id = (int) get_post_meta($post->ID, '_luna_booking_id', true);
		$booking = $booking_id ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;
		if (! is_array($booking)) { echo '<p>' . esc_html__('رکورد رزرو پیدا نشد.', 'luna-appointments') . '</p>'; return; }
		if (! empty($booking['payment_error'])) echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('آخرین خطای پرداخت:', 'luna-appointments') . '</strong> ' . esc_html((string) $booking['payment_error']) . '</p></div>';
		$history = Luna_Appointments_Bookings_Table::get_booking_history($booking_id, 100);
		if (! $history) { echo '<p>' . esc_html__('برای تغییرات قدیمی تاریخچه ساخت‌یافته وجود ندارد؛ تغییرات بعدی ثبت خواهند شد.', 'luna-appointments') . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('زمان', 'luna-appointments') . '</th><th>' . esc_html__('عامل', 'luna-appointments') . '</th><th>' . esc_html__('رویداد', 'luna-appointments') . '</th><th>' . esc_html__('تغییرات', 'luna-appointments') . '</th></tr></thead><tbody>';
		foreach ($history as $event) {
			$changes = json_decode((string) ($event['changes_json'] ?? ''), true); $parts = array();
			foreach (is_array($changes) ? $changes : array() as $field => $values) $parts[] = $field . ': ' . (string) ($values['from'] ?? '—') . ' ← ' . (string) ($values['to'] ?? '—');
			echo '<tr><td>' . esc_html(class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_db_datetime_jalali((string) $event['created_at']) : (string) $event['created_at']) . '</td><td>' . esc_html((string) ($event['actor_name'] ?: __('سیستم', 'luna-appointments'))) . '</td><td>' . esc_html((string) $event['event_type']) . '<br><small>' . esc_html((string) $event['source']) . '</small></td><td>' . esc_html($parts ? implode(' | ', $parts) : '—') . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_booking_meta_box($post) {
		$booking_id = (int) get_post_meta($post->ID, '_luna_booking_id', true);
		$booking    = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id) : null;

		wp_nonce_field('luna_booking_post_save_' . $post->ID, '_luna_booking_nonce');

		if (! is_array($booking)) {
			echo '<p>' . esc_html__('این رزرو به دیتای جدول رزروها متصل نیست.', 'luna-appointments') . '</p>';
			return;
		}

		$admin_note = isset($booking['admin_note']) ? (string) $booking['admin_note'] : '';
		$status     = isset($booking['status']) ? (string) $booking['status'] : '';
		$pay_status = isset($booking['payment_status']) ? (string) $booking['payment_status'] : '';
		$is_vip     = ! empty($booking['is_vip']);
		$code       = isset($booking['booking_code']) ? (string) $booking['booking_code'] : '';
		$date_value = isset($booking['booking_date']) ? (string) $booking['booking_date'] : '';
		$time_value = isset($booking['booking_time']) ? (string) $booking['booking_time'] : '';
		$status_cls = 'luna-status luna-status-' . sanitize_html_class($status ? $status : 'unknown');

		$service_id = isset($booking['service_id']) ? (int) $booking['service_id'] : 0;
		$spec_id    = isset($booking['specialist_id']) ? (int) $booking['specialist_id'] : 0;
		$service_link = $service_id > 0 ? get_edit_post_link($service_id, 'raw') : '';
		$spec_link    = $spec_id > 0 ? get_edit_post_link($spec_id, 'raw') : '';

		$customer_name  = isset($booking['customer_name']) ? (string) $booking['customer_name'] : '';
		$customer_phone = isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '';
		$customer_email = isset($booking['customer_email']) ? (string) $booking['customer_email'] : '';
		$phone_href     = 'tel:' . preg_replace('/\s+/', '', $customer_phone);
		$email_href     = 'mailto:' . $customer_email;
		$order_id = isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0;

		$sms_sent_at = (string) get_post_meta($post->ID, '_luna_reminder_sms_sent_at', true);
		$wa_sent_at  = (string) get_post_meta($post->ID, '_luna_reminder_whatsapp_sent_at', true);
		$sms_last_at = (string) get_post_meta($post->ID, '_luna_reminder_sms_last_at', true);
		$wa_last_at  = (string) get_post_meta($post->ID, '_luna_reminder_whatsapp_last_at', true);
		$sms_last_ok = (string) get_post_meta($post->ID, '_luna_reminder_sms_last_ok', true);
		$wa_last_ok  = (string) get_post_meta($post->ID, '_luna_reminder_whatsapp_last_ok', true);
		$sms_last_code = (string) get_post_meta($post->ID, '_luna_reminder_sms_last_code', true);
		$wa_last_code  = (string) get_post_meta($post->ID, '_luna_reminder_whatsapp_last_code', true);
		$manual_action = admin_url('admin-post.php');
		$manual_nonce  = wp_create_nonce('luna_manual_reminder_' . $post->ID);
		$payment_label = self::get_payment_label(isset($booking['payment_method']) ? (string) $booking['payment_method'] : '');
		$updated_label = isset($booking['updated_at']) && '' !== trim((string) $booking['updated_at']) && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_db_datetime_jalali((string) $booking['updated_at']) : '—';
		$notes         = isset($booking['notes']) ? (string) $booking['notes'] : '';
		$error_notice   = isset($_GET['luna_booking_error']) ? sanitize_text_field(wp_unslash($_GET['luna_booking_error'])) : '';
		$saved_notice   = isset($_GET['luna_booking_saved']) ? (int) $_GET['luna_booking_saved'] : 0;
		$notice_markup  = '';
		if ('' !== $error_notice) {
			$notice_markup = '<div class="notice notice-error inline"><p>' . esc_html($error_notice) . '</p></div>';
		} elseif ($saved_notice) {
			$notice_markup = '<div class="notice notice-success inline"><p>' . esc_html__('تغییرات رزرو با موفقیت ذخیره شد.', 'luna-appointments') . '</p></div>';
		}

		echo '<div class="luna-booking-editor">';
		echo '<div class="luna-booking-editor__hero">';
		echo '<div class="luna-booking-editor__eyebrow">' . esc_html__('Booking Workspace', 'luna-appointments') . '</div>';
		echo '<div class="luna-booking-editor__hero-row">';
		echo '<div>';
		echo '<h2 class="luna-booking-editor__title">' . esc_html($code ? $code : __('رزرو بدون کد', 'luna-appointments')) . '</h2>';
		echo '<p class="luna-booking-editor__subtitle">' . esc_html(self::format_booking_datetime_label($booking)) . '</p>';
		echo '</div>';
		echo '<div class="luna-booking-editor__chips">';
		echo '<span class="' . esc_attr($status_cls) . '" id="luna-booking-status-chip" data-status="' . esc_attr($status) . '" data-payment-status="' . esc_attr($pay_status) . '">' . esc_html(self::format_status_label($status, $pay_status)) . '</span>';
		if ($is_vip) {
			echo '<span class="luna-status luna-status-confirmed" id="luna-booking-vip-chip">VIP</span>';
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="luna-booking-editor__meta-strip">';
		echo '<span><strong>' . esc_html__('آخرین بروزرسانی', 'luna-appointments') . ':</strong> <span id="luna-booking-updated-at">' . esc_html($updated_label) . '</span></span>';
		echo '<span><strong>' . esc_html__('روش پرداخت', 'luna-appointments') . ':</strong> ' . esc_html($payment_label) . '</span>';
		if ($order_id > 0) {
			$link = get_edit_post_link($order_id, 'raw');
			echo '<span><strong>' . esc_html__('سفارش ووکامرس', 'luna-appointments') . ':</strong> ' . ($link ? '<a href="' . esc_url($link) . '">#' . esc_html((string) $order_id) . '</a>' : esc_html('#' . (string) $order_id)) . '</span>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="luna-booking-editor__grid">';

		echo '<section class="luna-booking-card">';
		echo '<div class="luna-booking-card__head"><h3>' . esc_html__('اطلاعات رزرو', 'luna-appointments') . '</h3><span>' . esc_html__('نمای کلی رزرو', 'luna-appointments') . '</span></div>';
		echo '<div class="luna-booking-facts">';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('خدمت', 'luna-appointments') . '</label><div>' . ($service_link ? '<a href="' . esc_url($service_link) . '">' . esc_html(isset($booking['service_name']) ? (string) $booking['service_name'] : '') . '</a>' : esc_html(isset($booking['service_name']) ? (string) $booking['service_name'] : '—')) . '</div></div>';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('متخصص', 'luna-appointments') . '</label><div>' . ($spec_link ? '<a href="' . esc_url($spec_link) . '">' . esc_html(isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '') . '</a>' : esc_html(isset($booking['specialist_name']) ? (string) $booking['specialist_name'] : '—')) . '</div></div>';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('مشتری', 'luna-appointments') . '</label><div>' . esc_html($customer_name ? $customer_name : '—') . '</div></div>';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('موبایل', 'luna-appointments') . '</label><div>' . ($customer_phone ? '<a href="' . esc_url($phone_href) . '">' . esc_html($customer_phone) . '</a>' : '—') . '</div></div>';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('ایمیل', 'luna-appointments') . '</label><div>' . ($customer_email ? '<a href="' . esc_url($email_href) . '">' . esc_html($customer_email) . '</a>' : '—') . '</div></div>';
		echo '<div class="luna-booking-fact"><label>' . esc_html__('زمان', 'luna-appointments') . '</label><div>' . esc_html(self::format_booking_datetime_label($booking)) . '</div></div>';
		echo '</div>';
		echo '</section>';

		echo '<section class="luna-booking-card">';
		echo '<div class="luna-booking-card__head"><h3>' . esc_html__('مالی لونا', 'luna-appointments') . '</h3><span>' . esc_html__('تخفیف، گیفت‌کارت و کیف پول', 'luna-appointments') . '</span></div>';
		echo wp_kses_post(self::get_booking_finance_meta_box_markup($booking));
		echo '</section>';

		echo '<section class="luna-booking-card">';
		echo '<div class="luna-booking-card__head"><h3>' . esc_html__('یادآوری‌ها', 'luna-appointments') . '</h3><span>' . esc_html__('ارسال دستی پیام‌ها', 'luna-appointments') . '</span></div>';
		echo '<div class="luna-booking-reminders">';
		echo '<div class="luna-booking-reminder">';
		echo '<h4>' . esc_html__('پیامک', 'luna-appointments') . '</h4>';
		echo '<p><strong>' . esc_html__('آخرین تلاش', 'luna-appointments') . ':</strong> ' . esc_html($sms_last_at ? $sms_last_at : '—') . '</p>';
		echo '<p><strong>' . esc_html__('نتیجه', 'luna-appointments') . ':</strong> ' . esc_html(('1' === $sms_last_ok) ? __('موفق', 'luna-appointments') : ($sms_last_at ? __('ناموفق', 'luna-appointments') : '—')) . ($sms_last_code ? ' (' . esc_html($sms_last_code) . ')' : '') . '</p>';
		echo '<p><strong>' . esc_html__('ارسال شده', 'luna-appointments') . ':</strong> ' . esc_html($sms_sent_at ? $sms_sent_at : '—') . '</p>';
		echo '<form method="post" action="' . esc_url($manual_action) . '">';
		echo '<input type="hidden" name="action" value="luna_booking_send_manual_reminder">';
		echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
		echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking_id) . '">';
		echo '<input type="hidden" name="channel" value="sms">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($manual_nonce) . '">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('ارسال تست پیامک', 'luna-appointments') . '</button>';
		echo '</form>';
		echo '</div>';
		echo '<div class="luna-booking-reminder">';
		echo '<h4>' . esc_html__('واتساپ', 'luna-appointments') . '</h4>';
		echo '<p><strong>' . esc_html__('آخرین تلاش', 'luna-appointments') . ':</strong> ' . esc_html($wa_last_at ? $wa_last_at : '—') . '</p>';
		echo '<p><strong>' . esc_html__('نتیجه', 'luna-appointments') . ':</strong> ' . esc_html(('1' === $wa_last_ok) ? __('موفق', 'luna-appointments') : ($wa_last_at ? __('ناموفق', 'luna-appointments') : '—')) . ($wa_last_code ? ' (' . esc_html($wa_last_code) . ')' : '') . '</p>';
		echo '<p><strong>' . esc_html__('ارسال شده', 'luna-appointments') . ':</strong> ' . esc_html($wa_sent_at ? $wa_sent_at : '—') . '</p>';
		echo '<form method="post" action="' . esc_url($manual_action) . '">';
		echo '<input type="hidden" name="action" value="luna_booking_send_manual_reminder">';
		echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
		echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking_id) . '">';
		echo '<input type="hidden" name="channel" value="whatsapp">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($manual_nonce) . '">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('ارسال تست واتساپ', 'luna-appointments') . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</section>';

		echo '<section class="luna-booking-card luna-booking-card--editor">';
		echo '<div class="luna-booking-card__head"><h3>' . esc_html__('ویرایش رزرو', 'luna-appointments') . '</h3><span>' . esc_html__('همه تغییرات از همین بخش ذخیره می‌شوند', 'luna-appointments') . '</span></div>';
		echo '<div class="luna-booking-form-grid">';
		echo '<div class="luna-admin-field"><label for="luna_booking_status">' . esc_html__('وضعیت رزرو', 'luna-appointments') . '</label><select name="luna_booking_status" id="luna_booking_status">';
		foreach (array('pending_payment', 'payment_review', 'consultation_pending', 'conflict', 'confirmed', 'cancelled', 'failed', 'refunded') as $opt) {
			echo '<option value="' . esc_attr($opt) . '"' . selected($status, $opt, false) . '>' . esc_html(self::format_status_label($opt, '')) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="luna-admin-field"><label>' . esc_html__('وضعیت VIP', 'luna-appointments') . '</label><label class="luna-admin-check"><input type="checkbox" name="luna_booking_is_vip" value="1"' . checked($is_vip, true, false) . '> <span>' . esc_html__('این رزرو VIP است', 'luna-appointments') . '</span></label></div>';
		echo '<div class="luna-admin-field"><label for="luna_booking_date">' . esc_html__('تاریخ جدید رزرو', 'luna-appointments') . '</label><input type="date" id="luna_booking_date" name="luna_booking_date" value="' . esc_attr($date_value) . '"></div>';
		echo '<div class="luna-admin-field"><label for="luna_booking_time">' . esc_html__('ساعت جدید رزرو', 'luna-appointments') . '</label><input type="time" id="luna_booking_time" name="luna_booking_time" value="' . esc_attr($time_value) . '"></div>';
		echo '<div class="luna-admin-field luna-admin-field--full"><label for="luna_booking_admin_note">' . esc_html__('یادداشت مدیریت', 'luna-appointments') . '</label><textarea name="luna_booking_admin_note" id="luna_booking_admin_note" rows="5">' . esc_textarea($admin_note) . '</textarea></div>';
		echo '</div>';
		echo '<div class="luna-booking-editor__actions">';
		echo '<button type="button" class="button button-primary button-hero" id="luna-booking-editor-save">' . esc_html__('ذخیره تغییرات رزرو', 'luna-appointments') . '</button>';
		echo '<p>' . esc_html__('این دکمه از مسیر اختصاصی رزرو استفاده می‌کند و وضعیت، زمان و یادداشت را مستقیم در جدول رزروها ذخیره می‌کند.', 'luna-appointments') . '</p>';
                echo '<p><a class="button button-secondary" target="_blank" href="' . esc_url(wp_nonce_url(add_query_arg(array('action' => 'luna_booking_receipt', 'booking_id' => $booking_id), admin_url('admin-post.php')), 'luna_booking_receipt_' . $booking_id)) . '">' . esc_html__('نسخه چاپ / PDF رزرو', 'luna-appointments') . '</a></p>';
		echo '<div id="luna-booking-editor-flash">' . wp_kses_post($notice_markup) . '</div>';
		echo '</div>';
		echo '</section>';

		if ('' !== trim($notes)) {
			echo '<section class="luna-booking-card luna-booking-card--history">';
			echo '<div class="luna-booking-card__head"><h3>' . esc_html__('تاریخچه رزرو', 'luna-appointments') . '</h3><span>' . esc_html__('لاگ تغییرات و اتفاقات', 'luna-appointments') . '</span></div>';
			echo '<pre class="luna-booking-history">' . esc_html($notes) . '</pre>';
			echo '</section>';
		}

		echo '</div>';
		echo '</div>';
	}

	protected static function process_booking_editor_update($post_id, $request = null) {
		$post_id = (int) $post_id;
		$request = is_array($request) ? $request : $_POST;
		$post    = get_post($post_id);

		if (! $post instanceof WP_Post || self::$booking_post_type !== $post->post_type) {
			return new WP_Error('invalid_booking_post', __('رزرو انتخاب‌شده معتبر نیست.', 'luna-appointments'));
		}

		if (! current_user_can('edit_post', $post_id)) {
			return new WP_Error('booking_permission_denied', __('اجازه ویرایش این رزرو را ندارید.', 'luna-appointments'));
		}

		$nonce = isset($request['_luna_booking_nonce']) ? sanitize_text_field(wp_unslash($request['_luna_booking_nonce'])) : '';
		if ('' === $nonce || ! wp_verify_nonce($nonce, 'luna_booking_post_save_' . $post_id)) {
			return new WP_Error('booking_invalid_nonce', __('درخواست ویرایش رزرو معتبر نیست. صفحه را دوباره بارگذاری کنید.', 'luna-appointments'));
		}

		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return new WP_Error('booking_mapping_missing', __('رزرو به رکورد معتبر جدول رزروها متصل نیست.', 'luna-appointments'));
		}

		$existing = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (! is_array($existing)) {
			return new WP_Error('booking_row_missing', __('رکورد اصلی رزرو پیدا نشد.', 'luna-appointments'));
		}

		self::clear_scheduled_reminders($booking_id);

		$target_status = isset($request['luna_booking_status']) ? sanitize_key(wp_unslash($request['luna_booking_status'])) : '';
		$admin_note    = isset($request['luna_booking_admin_note']) ? sanitize_textarea_field(wp_unslash($request['luna_booking_admin_note'])) : '';
		$new_date      = isset($request['luna_booking_date']) ? trim(sanitize_text_field(wp_unslash($request['luna_booking_date']))) : '';
		$new_time      = isset($request['luna_booking_time']) ? trim(sanitize_text_field(wp_unslash($request['luna_booking_time']))) : '';
		$new_is_vip    = isset($request['luna_booking_is_vip']) ? 1 : 0;

		$update_data    = array();
		$existing_notes = isset($existing['notes']) ? (string) $existing['notes'] : '';

		if ('' !== $target_status && $target_status !== (string) ($existing['status'] ?? '')) {
			$update_data['status'] = $target_status;
			$update_data['notes']  = self::append_booking_note($existing_notes, sprintf(__('وضعیت رزرو از داخل مدیریت به %s تغییر کرد.', 'luna-appointments'), self::format_status_label($target_status, '')));
			$existing_notes        = (string) $update_data['notes'];
		}

		$update_data['admin_note'] = $admin_note;

		$existing_is_vip = ! empty($existing['is_vip']) ? 1 : 0;
		if ($new_is_vip !== $existing_is_vip) {
			$update_data['is_vip'] = $new_is_vip;
			$update_data['notes']  = self::append_booking_note($existing_notes, $new_is_vip ? __('رزرو به VIP تغییر کرد.', 'luna-appointments') : __('رزرو از حالت VIP خارج شد.', 'luna-appointments'));
			$existing_notes        = (string) $update_data['notes'];
		}

		$existing_date = isset($existing['booking_date']) ? (string) $existing['booking_date'] : '';
		$existing_time = isset($existing['booking_time']) ? (string) $existing['booking_time'] : '';
		if ('' !== $new_date && '' !== $new_time && ($new_date !== $existing_date || $new_time !== $existing_time)) {
			if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) || ! preg_match('/^\d{2}:\d{2}$/', $new_time)) {
				return new WP_Error('booking_invalid_datetime', __('تاریخ یا ساعت جدید معتبر نیست.', 'luna-appointments'));
			}

			$specialist_id     = isset($existing['specialist_id']) ? (int) $existing['specialist_id'] : 0;
			$duration_minutes  = isset($existing['duration_minutes']) ? (int) $existing['duration_minutes'] : 0;
			$buffer_minutes    = isset($existing['buffer_minutes']) ? (int) $existing['buffer_minutes'] : 0;
			$schedule          = $specialist_id > 0 ? self::get_specialist_schedule($specialist_id) : array();

			if ($specialist_id > 0 && ! self::is_specialist_open_for_date($schedule, $new_date)) {
				return new WP_Error('booking_closed_date', __('این متخصص در تاریخ انتخاب‌شده فعال نیست.', 'luna-appointments'));
			}
					if ($specialist_id > 0 && ! self::is_time_allowed_by_schedule($new_time, $duration_minutes, $buffer_minutes, $schedule, $new_date)) {
				return new WP_Error('booking_closed_time', __('این ساعت خارج از بازه کاری متخصص است.', 'luna-appointments'));
			}
			if ($specialist_id > 0 && Luna_Appointments_Bookings_Table::slot_exists($specialist_id, $new_date, $new_time, $duration_minutes, $buffer_minutes, $booking_id)) {
				return new WP_Error('booking_slot_taken', __('این زمان قبلاً رزرو شده است.', 'luna-appointments'));
			}

			$update_data['booking_date'] = $new_date;
			$update_data['booking_time'] = $new_time;
			$update_data['notes']        = self::append_booking_note($existing_notes, __('زمان رزرو توسط مدیریت تغییر کرد.', 'luna-appointments'));
		}

		if ('confirmed' === $target_status && 'confirmed' !== (string) ($existing['status'] ?? '')) {
			$prospective_booking = array_merge($existing, $update_data);
			if (self::booking_slot_has_conflict($prospective_booking)) {
				return new WP_Error('booking_slot_conflict', __('این بازه با رزرو دیگری تداخل دارد و تا رفع تداخل قابل تأیید نیست.', 'luna-appointments'));
			}
		}

		if (! empty($existing['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $existing['wc_order_id']);
			if ($order instanceof WC_Order) {
				if (array_key_exists('is_vip', $update_data)) {
					$order->update_meta_data('_luna_is_vip', $new_is_vip);
				}
				if (isset($update_data['booking_date'])) {
					$order->update_meta_data('_luna_booking_date', $update_data['booking_date']);
				}
				if (isset($update_data['booking_time'])) {
					$order->update_meta_data('_luna_booking_time', $update_data['booking_time']);
				}
				$order->save();
			}
		}

		if (empty($update_data)) {
			$current_booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
			return array(
				'post_id'         => $post_id,
				'booking_id'      => $booking_id,
				'updated'         => false,
				'current_booking' => is_array($current_booking) ? $current_booking : $existing,
			);
		}

		Luna_Appointments_Bookings_Table::update_booking($booking_id, $update_data);
		self::maybe_trigger_booking_status_transition($booking_id, $existing, $update_data, 'admin_edit');
		if (isset($update_data['status']) && 'cancelled' === $update_data['status']) {
			self::maybe_cancel_unpaid_linked_order($existing, __('رزرو توسط مدیریت لغو شد.', 'luna-appointments'));
		}
		self::upsert_booking_post_from_row_id($booking_id);
		self::maybe_award_vip_points($booking_id, false);
		self::maybe_schedule_booking_reminders($booking_id);
		$current_booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);

                if (is_array($current_booking) && (isset($update_data['booking_date']) || isset($update_data['booking_time']))) {
                        self::maybe_send_booking_lifecycle_notification('rescheduled', $current_booking, $existing, 'admin_edit');
                }

		return array(
			'post_id'         => $post_id,
			'booking_id'      => $booking_id,
			'updated'         => true,
			'current_booking' => is_array($current_booking) ? $current_booking : $existing,
		);
	}

	public static function handle_booking_editor_update_request() {
		$post_id = isset($_POST['post_ID']) ? (int) wp_unslash($_POST['post_ID']) : 0;
		$result  = self::process_booking_editor_update($post_id, $_POST);
		$args    = array(
			'post'   => $post_id,
			'action' => 'edit',
		);

		if (is_wp_error($result)) {
			$args['luna_booking_error'] = $result->get_error_message();
		} else {
			$args['message']           = 1;
			$args['luna_booking_saved'] = ! empty($result['updated']) ? 1 : 0;
		}

		wp_safe_redirect(add_query_arg($args, admin_url('post.php')));
		exit;
	}

	public static function handle_booking_editor_update_ajax() {
		$post_id = isset($_POST['post_ID']) ? (int) wp_unslash($_POST['post_ID']) : 0;
		$result  = self::process_booking_editor_update($post_id, $_POST);

		if (is_wp_error($result)) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		$current_booking = isset($result['current_booking']) && is_array($result['current_booking']) ? $result['current_booking'] : array();
		$status          = isset($current_booking['status']) ? (string) $current_booking['status'] : '';
		$payment_status  = isset($current_booking['payment_status']) ? (string) $current_booking['payment_status'] : '';
		$updated_label   = isset($current_booking['updated_at']) && '' !== trim((string) $current_booking['updated_at']) && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_db_datetime_jalali((string) $current_booking['updated_at']) : '—';

		wp_send_json_success(
			array(
				'message'        => ! empty($result['updated']) ? __('تغییرات رزرو با موفقیت ذخیره شد.', 'luna-appointments') : __('تغییری برای ذخیره وجود نداشت.', 'luna-appointments'),
				'updated'        => ! empty($result['updated']),
				'status'         => $status,
				'payment_status' => $payment_status,
				'status_label'   => self::format_status_label($status, $payment_status),
				'status_class'   => 'luna-status luna-status-' . sanitize_html_class($status ? $status : 'unknown'),
				'is_vip'         => ! empty($current_booking['is_vip']),
				'updated_label'  => $updated_label,
			)
		);
	}

	public static function handle_booking_post_save($post_id, $post, $update) {
		if (! $update || ! $post instanceof WP_Post) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (isset($_POST['action'])) {
			$request_action = sanitize_key(wp_unslash($_POST['action']));
			if (in_array($request_action, array('luna_booking_update_from_editor', 'luna_booking_editor_update'), true)) {
				return;
			}
		}

		self::process_booking_editor_update($post_id, $_POST);
	}

	public static function handle_booking_post_trashed($post_id) {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || self::$booking_post_type !== $post->post_type) {
			return;
		}

		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$existing = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		Luna_Appointments_Bookings_Table::update_booking(
			$booking_id,
			array(
				'status' => 'cancelled',
				'notes'  => self::append_booking_note(is_array($existing) && isset($existing['notes']) ? (string) $existing['notes'] : '', __('رزرو به زباله‌دان منتقل شد.', 'luna-appointments')),
			)
		);
		if (is_array($existing)) {
			self::maybe_trigger_booking_status_transition(
				$booking_id,
				$existing,
				array('status' => 'cancelled'),
				'trash'
			);
		}
		if (is_array($existing)) {
			self::maybe_cancel_unpaid_linked_order($existing, __('رزرو با انتقال به زباله‌دان لغو شد.', 'luna-appointments'));
		}
		self::clear_scheduled_reminders($booking_id);
	}

	public static function handle_booking_post_untrashed($post_id) {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || self::$booking_post_type !== $post->post_type) {
			return;
		}

		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$existing = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		Luna_Appointments_Bookings_Table::update_booking(
			$booking_id,
			array(
				'notes' => self::append_booking_note(is_array($existing) && isset($existing['notes']) ? (string) $existing['notes'] : '', __('رزرو از زباله‌دان بازگردانی شد.', 'luna-appointments')),
			)
		);
		self::upsert_booking_post_from_row_id($booking_id);
		self::maybe_schedule_booking_reminders($booking_id);
	}

	public static function handle_booking_post_deleted($post_id) {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || self::$booking_post_type !== $post->post_type) {
			return;
		}

		$booking_id = (int) get_post_meta($post_id, '_luna_booking_id', true);
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (! is_array($booking)) {
			return;
		}

		self::maybe_cancel_unpaid_linked_order($booking, __('رزرو برای همیشه از مدیریت حذف شد.', 'luna-appointments'));

		if (method_exists('Luna_Appointments_Bookings_Table', 'delete_booking')) {
			Luna_Appointments_Bookings_Table::delete_booking($booking_id);
		}
	}

	public static function maybe_backfill_booking_posts() {
		if (! is_admin() || ! current_user_can('edit_theme_options')) {
			return;
		}

		if (! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$backfill_version = '2';
		if ($backfill_version !== (string) get_option('luna_booking_post_backfill_version', '')) {
			delete_option('luna_booking_post_backfill_done');
			update_option('luna_booking_post_backfill_cursor', 0, false);
		}

		if ('1' === (string) get_option('luna_booking_post_backfill_done', '')) {
			return;
		}

		global $wpdb;
		$table  = Luna_Appointments_Bookings_Table::get_table_name();
		$cursor = (int) get_option('luna_booking_post_backfill_cursor', 0);
		$limit  = 25;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				$limit
			)
		);

		if (empty($ids)) {
			update_option('luna_booking_post_backfill_done', '1', false);
			update_option('luna_booking_post_backfill_version', $backfill_version, false);
			return;
		}

		$last = $cursor;
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			$last = max($last, $id);
			self::upsert_booking_post_from_row_id($id);
		}

		update_option('luna_booking_post_backfill_cursor', $last, false);
	}

	/** Convert accidentally persisted Jalali booking keys back to Gregorian DATE values. */
	public static function maybe_normalize_legacy_booking_dates() {
		if (! is_admin() || ! current_user_can('edit_theme_options') || ! class_exists('Luna_Appointments_Bookings_Table') || ! class_exists('Luna_Appointments_Date')) {
			return;
		}
		$version = '1.0.0';
		if ($version === (string) get_option('luna_booking_gregorian_date_migration', '')) {
			return;
		}

		global $wpdb;
		$table = Luna_Appointments_Bookings_Table::get_table_name();
		$cursor = (int) get_option('luna_booking_gregorian_date_cursor', 0);
		$rows  = $wpdb->get_results($wpdb->prepare("SELECT id, booking_date, wc_order_id, notes FROM {$table} WHERE id > %d AND booking_date < '1700-01-01' ORDER BY id ASC LIMIT 50", $cursor), ARRAY_A);
		if (empty($rows)) {
			update_option('luna_booking_gregorian_date_migration', $version, false);
			delete_option('luna_booking_gregorian_date_cursor');
			return;
		}

		$last = $cursor;
		foreach ($rows as $row) {
			$booking_id = (int) ($row['id'] ?? 0);
			$last       = max($last, $booking_id);
			$old_date   = (string) ($row['booking_date'] ?? '');
			$new_date   = Luna_Appointments_Date::jalali_to_gregorian_date($old_date);
			if ($booking_id <= 0 || '' === $new_date) {
				continue;
			}
			Luna_Appointments_Bookings_Table::update_booking(
				$booking_id,
				array(
					'booking_date' => $new_date,
					'notes'        => self::append_booking_note((string) ($row['notes'] ?? ''), sprintf(__('تاریخ ذخیره‌شده قدیمی از شمسی %1$s به کلید میلادی %2$s استاندارد شد.', 'luna-appointments'), $old_date, $new_date)),
				)
			);
			if (! empty($row['wc_order_id']) && function_exists('wc_get_order')) {
				$order = wc_get_order((int) $row['wc_order_id']);
				if ($order instanceof WC_Order) {
					$order->update_meta_data('_luna_booking_date', $new_date);
					$order->save_meta_data();
				}
			}
			self::upsert_booking_post_from_row_id($booking_id);
			self::clear_scheduled_reminders($booking_id);
			self::maybe_schedule_booking_reminders($booking_id);
		}
		update_option('luna_booking_gregorian_date_cursor', $last, false);
	}

	/**
	 * Repair historic links and replay WooCommerce as the financial source of
	 * truth. Work is intentionally batched so a large site cannot time out.
	 */
	public static function maybe_reconcile_booking_orders() {
		if (! is_admin() || ! current_user_can('edit_theme_options') || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$version = '1.0.0';
		if ($version === (string) get_option('luna_booking_order_reconcile_version', '')) {
			return;
		}

		global $wpdb;
		$table  = Luna_Appointments_Bookings_Table::get_table_name();
		$cursor = (int) get_option('luna_booking_order_reconcile_cursor', 0);
		$limit  = 40;
		$ids    = $wpdb->get_col(
			$wpdb->prepare("SELECT id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $cursor, $limit)
		);

		if (empty($ids)) {
			update_option('luna_booking_order_reconcile_version', $version, false);
			delete_option('luna_booking_order_reconcile_cursor');
			return;
		}

		$last = $cursor;
		foreach ((array) $ids as $raw_id) {
			$booking_id = (int) $raw_id;
			$last       = max($last, $booking_id);
			$booking    = Luna_Appointments_Bookings_Table::get_booking($booking_id);
			if (! is_array($booking)) {
				continue;
			}

			$order = false;
			if (! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
				$order = wc_get_order((int) $booking['wc_order_id']);
			}
			if (! $order instanceof WC_Order && function_exists('wc_get_orders')) {
				$orders = wc_get_orders(
					array(
						'limit'      => 1,
						'return'     => 'objects',
						'meta_key'   => '_luna_booking_id',
						'meta_value' => $booking_id,
						'orderby'    => 'ID',
						'order'      => 'DESC',
					)
				);
				$order = ! empty($orders) ? reset($orders) : false;
			}

			if ($order instanceof WC_Order) {
				self::sync_booking_from_order((int) $order->get_id(), $order);
			} else {
				self::upsert_booking_post_from_row_id($booking_id);
			}
		}

		update_option('luna_booking_order_reconcile_cursor', $last, false);
		if (count((array) $ids) < $limit) {
			update_option('luna_booking_order_reconcile_version', $version, false);
			delete_option('luna_booking_order_reconcile_cursor');
		}
	}

	public static function maybe_repair_interrupted_bookings() {
		$is_cron = defined('DOING_CRON') && DOING_CRON;
		if ((! $is_cron && (! is_admin() || ! current_user_can('edit_theme_options'))) || ! class_exists('Luna_Appointments_Bookings_Table')) return;
		if (! $is_cron && get_transient('luna_booking_recovery_running')) return;
		set_transient('luna_booking_recovery_running', 1, MINUTE_IN_SECONDS);

		global $wpdb;
		$table = Luna_Appointments_Bookings_Table::get_table_name();
		$cutoff = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::now()->modify('-10 minutes')->format('Y-m-d H:i:s') : current_time('mysql');
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE source = %s
				   AND status = %s
				   AND payment_status = %s
				   AND wc_order_id = 0
				   AND created_at <= %s
				 ORDER BY id ASC LIMIT 250",
				'booking_form',
				'pending_payment',
				'pending',
				$cutoff
			)
		);

		foreach ((array) $ids as $id) {
			$id = (int) $id;
			if ($id <= 0) continue;
			$booking = Luna_Appointments_Bookings_Table::get_booking($id);
			if (! is_array($booking)) continue;
			if (function_exists('wc_get_orders')) {
				$orders = wc_get_orders(array('limit' => 1, 'return' => 'objects', 'meta_key' => '_luna_booking_id', 'meta_value' => $id, 'orderby' => 'ID', 'order' => 'DESC'));
				$order  = ! empty($orders) ? reset($orders) : false;
				if ($order instanceof WC_Order) {
					self::sync_booking_from_order((int) $order->get_id(), $order);
					continue;
				}
			}
			$notes = is_array($booking) && isset($booking['notes']) ? (string) $booking['notes'] : '';
			do_action('luna_appointments_release_booking_finance_commit', $id, 0, array(), $booking, 'interrupted_booking_recovery');
			Luna_Appointments_Bookings_Table::update_booking(
				$id,
				array(
					'status' => 'failed',
					'payment_status' => 'failed',
					'notes' => self::append_booking_note($notes, __('این رزرو پس از ثبت موفق، به‌دلیل خطای شناسه نسخه قبلی نیمه‌کاره مانده بود و برای امکان تلاش مجدد آزاد شد.', 'luna-appointments')),
				)
			);
			self::maybe_trigger_booking_status_transition($id, $booking, array('status' => 'failed', 'payment_status' => 'failed'), 'interrupted_recovery');
			self::upsert_booking_post_from_row_id($id);
			self::clear_scheduled_reminders($id);
		}
	}

	public static function ensure_booking_maintenance_schedule() {
		if (! wp_next_scheduled('luna_appointments_repair_interrupted_bookings')) {
			wp_schedule_event(time() + 300, 'luna_five_minutes', 'luna_appointments_repair_interrupted_bookings');
		}
	}

	public static function add_booking_cron_schedule($schedules) {
		$schedules['luna_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __('هر پنج دقیقه (نگهداری رزرو لونا)', 'luna-appointments'),
		);
		return $schedules;
	}

	public static function upsert_booking_post_from_row_id($booking_id) {
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return;
		}

		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) {
			return;
		}

		$post_id = self::find_booking_post_id($booking_id);

		$booking_code = isset($booking['booking_code']) ? (string) $booking['booking_code'] : ('#' . $booking_id);
		$title        = $booking_code;
		if ('' === $title) {
			$title = __('رزرو', 'luna-appointments');
		}

		$post_data = array(
			'post_type'   => self::$booking_post_type,
			'post_title'  => $title,
			'post_status' => 'publish',
		);

		if ($post_id > 0) {
			$post_data['ID'] = $post_id;
			wp_update_post($post_data);
		} else {
			$post_id = (int) wp_insert_post($post_data);
			if ($post_id <= 0) {
				return;
			}
		}

		update_post_meta($post_id, '_luna_booking_id', $booking_id);
		update_post_meta($post_id, '_luna_booking_code', $booking_code);
		update_post_meta($post_id, '_luna_wc_order_id', isset($booking['wc_order_id']) ? (int) $booking['wc_order_id'] : 0);

		foreach (
			array(
				'service_id',
				'specialist_id',
				'customer_user_id',
				'is_vip',
				'customer_name',
				'customer_phone',
				'customer_email',
				'language',
				'booking_date',
				'booking_time',
				'duration_minutes',
				'buffer_minutes',
				'base_price',
				'price_label',
				'status',
				'payment_status',
				'payment_method',
				'wc_order_key',
				'source',
				'notes',
				'admin_note',
				'created_at',
				'updated_at',
			) as $key
		) {
			if (array_key_exists($key, $booking)) {
				update_post_meta($post_id, '_luna_' . $key, $booking[ $key ]);
			}
		}
	}

	protected static function find_booking_post_id($booking_id) {
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::$booking_post_type,
				'post_status'    => array('publish', 'draft', 'pending', 'private', 'trash'),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_luna_booking_id',
				'meta_value'     => (string) $booking_id,
			)
		);

		return ! empty($posts[0]) ? (int) $posts[0] : 0;
	}

	protected static function format_booking_datetime_label($booking) {
		$booking_date = isset($booking['booking_date']) ? (string) $booking['booking_date'] : '';
		$booking_time = isset($booking['booking_time']) ? (string) $booking['booking_time'] : '';

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
			return trim($booking_date . ' ' . $booking_time);
		}

		if (class_exists('Luna_Appointments_Date')) {
			return Luna_Appointments_Date::format_jalali($booking_date, $booking_time, true);
		}
		return self::to_persian_digits(trim($booking_date . ' ' . $booking_time));
	}

	protected static function to_persian_digits($value) {
		$value = (string) $value;
		return strtr(
			$value,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}

	protected static function to_latin_digits($value) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/** Machine-safe Gregorian date; unlike wp_date(), plugins cannot Jalali-convert it. */
	protected static function gregorian_date_from_timestamp($timestamp) {
		$date = new DateTimeImmutable('@' . (int) $timestamp);
		return $date->setTimezone(wp_timezone())->format('Y-m-d');
	}

	protected static function format_status_label($status, $payment_status) {
		return Luna_Appointments_I18n::combined_status($status, $payment_status);
	}

	protected static function format_account_status_label($status, $payment_status) {
		$status = sanitize_key((string) $status);
		$pay    = sanitize_key((string) $payment_status);

		if (in_array($status, array('cancelled', 'failed', 'refunded'), true) || in_array($pay, array('cancelled', 'failed', 'refunded'), true)) {
			$map = array(
				'cancelled' => __('لغو شده', 'luna-appointments'),
				'failed'    => __('ناموفق', 'luna-appointments'),
				'refunded'  => __('برگشت', 'luna-appointments'),
			);

			if (isset($map[ $status ])) {
				return $map[ $status ];
			}

			if (isset($map[ $pay ])) {
				return $map[ $pay ];
			}
		}

		if ('paid' === $pay) {
			return __('پرداخت شده', 'luna-appointments');
		}

		if ('deposit_paid' === $pay) {
			return __('هزینه اولیه پرداخت شده؛ در انتظار مشاوره', 'luna-appointments');
		}

		if (in_array($status, array('completed', 'done'), true)) {
			return __('انجام شده', 'luna-appointments');
		}

		if ('confirmed' === $status) {
			return __('تایید', 'luna-appointments');
		}

		if ('consultation_pending' === $status || 'not_required' === $pay) {
			return __('در انتظار مشاوره', 'luna-appointments');
		}

		if (in_array($status, array('pending_payment', 'payment_review', 'pending'), true) || in_array($pay, array('pending', 'authorized'), true)) {
			return __('در انتظار پرداخت', 'luna-appointments');
		}

		return self::format_status_label($status, $payment_status);
	}

	public static function highlight_booking_menu_parent($parent_file) {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && self::$booking_post_type === (string) $screen->post_type) {
			return self::$booking_dashboard_slug;
		}
		return $parent_file;
	}

	public static function highlight_booking_menu_submenu($submenu_file) {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && self::$booking_post_type === (string) $screen->post_type) {
			return 'edit.php?post_type=' . self::$booking_post_type;
		}
		return $submenu_file;
	}

	public static function filter_booking_post_redirect($location, $post_id) {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || self::$booking_post_type !== $post->post_type) {
			return $location;
		}

		$location = remove_query_arg(array('trashed', 'untrashed', 'deleted'), (string) $location);
		$message  = 1;
		$query    = wp_parse_url($location, PHP_URL_QUERY);
		if (is_string($query)) {
			parse_str($query, $query_args);
			if (isset($query_args['message'])) {
				$message = (int) $query_args['message'];
			}
		}
		$message  = $message > 0 ? $message : 1;

		return add_query_arg(
			array(
				'post'    => (int) $post_id,
				'action'  => 'edit',
				'message' => $message,
			),
			admin_url('post.php')
		);
	}

	protected static function is_booking_editor_screen() {
		if (! is_admin()) {
			return false;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		return $screen && self::$booking_post_type === (string) $screen->post_type && 'post' === (string) $screen->base;
	}

	public static function render_booking_submitbox_summary() {
		if (! self::is_booking_editor_screen()) {
			return;
		}

		echo '<div class="misc-pub-section misc-pub-luna-summary">';
		echo '<div class="misc-pub-luna-summary__icon" aria-hidden="true">L</div>';
		echo '<div class="misc-pub-luna-summary__content">';
		echo '<strong>' . esc_html__('کنترل نهایی رزرو', 'luna-appointments') . '</strong>';
		echo '<p>' . esc_html__('تغییرات وضعیت، زمان و یادداشت از دکمه پایین ثبت می‌شود و بعد از ذخیره روی همین رزرو می‌مانید.', 'luna-appointments') . '</p>';
		echo '</div>';
		echo '</div>';
	}

	public static function render_booking_editor_admin_script() {
		if (! self::is_booking_editor_screen()) {
			return;
		}

		$config = array(
			'ajaxUrl'           => admin_url('admin-ajax.php'),
			'action'            => 'luna_booking_editor_update',
			'texts'             => array(
				'heading'   => __('بروزرسانی رزرو', 'luna-appointments'),
				'save'      => __('ذخیره تغییرات رزرو', 'luna-appointments'),
				'saving'    => __('در حال ذخیره...', 'luna-appointments'),
				'trash'     => __('لغو و انتقال به زباله‌دان', 'luna-appointments'),
				'fallback'  => __('پاسخ معتبری از سرور دریافت نشد.', 'luna-appointments'),
					'timeout'   => __('ذخیره رزرو طول کشید. احتمالاً تغییرات ثبت شده‌اند؛ یک بار صفحه را تازه‌سازی کنید.', 'luna-appointments'),
			),
		);

		echo '<script>window.lunaBookingEditorConfig=' . wp_json_encode($config) . ';document.addEventListener("DOMContentLoaded",function(){var cfg=window.lunaBookingEditorConfig||{},submitBox=document.getElementById("submitdiv"),publishButton=document.getElementById("publish"),editorSaveButton=document.getElementById("luna-booking-editor-save"),statusChip=document.getElementById("luna-booking-status-chip"),vipField=document.querySelector(\'input[name="luna_booking_is_vip"]\'),vipChip=document.getElementById("luna-booking-vip-chip"),updatedAt=document.getElementById("luna-booking-updated-at"),flash=document.getElementById("luna-booking-editor-flash");if(!submitBox||!publishButton||!publishButton.form){return;}var bookingForm=publishButton.form,heading=submitBox.querySelector(".postbox-header h2"),deleteLink=submitBox.querySelector("#delete-action a"),requestController=null,requestTimer=null,requestTimedOut=false,defaultPublishLabel=(cfg.texts&&cfg.texts.save)?cfg.texts.save:(publishButton.value||publishButton.textContent||""),defaultEditorLabel=editorSaveButton?(editorSaveButton.textContent||defaultPublishLabel):"";if(heading&&cfg.texts&&cfg.texts.heading){heading.textContent=cfg.texts.heading;}publishButton.value=defaultPublishLabel;publishButton.textContent=defaultPublishLabel;if(editorSaveButton){editorSaveButton.textContent=defaultEditorLabel||defaultPublishLabel;}if(deleteLink&&cfg.texts&&cfg.texts.trash){deleteLink.textContent=cfg.texts.trash;}var renderNotice=function(type,message){if(!flash){return;}flash.innerHTML="";if(!message){return;}var notice=document.createElement("div"),text=document.createElement("p");notice.className="notice notice-"+type+" inline";text.textContent=message;notice.appendChild(text);flash.appendChild(notice);};var syncVisualState=function(data){if(statusChip&&data.status_class&&data.status_label){statusChip.className=data.status_class;statusChip.textContent=data.status_label;statusChip.setAttribute("data-status",data.status||"");statusChip.setAttribute("data-payment-status",data.payment_status||"");}if(updatedAt&&data.updated_label){updatedAt.textContent=data.updated_label;}if(vipField){var wantsVip=!!vipField.checked;if(data&&typeof data.is_vip!=="undefined"){wantsVip=!!data.is_vip;vipField.checked=wantsVip;}if(wantsVip&&!vipChip&&statusChip&&statusChip.parentNode){vipChip=document.createElement("span");vipChip.id="luna-booking-vip-chip";vipChip.className="luna-status luna-status-confirmed";vipChip.textContent="VIP";statusChip.parentNode.appendChild(vipChip);}else if(!wantsVip&&vipChip){vipChip.remove();vipChip=null;}}};var setBusy=function(isBusy){var publishLabel=isBusy&&cfg.texts&&cfg.texts.saving?cfg.texts.saving:defaultPublishLabel,editorLabel=isBusy&&cfg.texts&&cfg.texts.saving?cfg.texts.saving:(defaultEditorLabel||defaultPublishLabel);publishButton.disabled=isBusy;if(editorSaveButton){editorSaveButton.disabled=isBusy;}bookingForm.classList.toggle("is-saving",!!isBusy);publishButton.value=publishLabel;publishButton.textContent=publishLabel;if(editorSaveButton){editorSaveButton.textContent=editorLabel;}};var clearPendingState=function(){if(requestTimer){window.clearTimeout(requestTimer);requestTimer=null;}requestController=null;requestTimedOut=false;setBusy(false);};var submitAjax=function(){if(bookingForm.classList.contains("is-saving")){return;}setBusy(true);renderNotice("info","");var formData=new FormData(bookingForm);formData.set("action",cfg.action||"luna_booking_editor_update");requestController=window.AbortController?new AbortController():null;requestTimedOut=false;requestTimer=window.setTimeout(function(){requestTimedOut=true;if(requestController){requestController.abort();}if(requestTimer){window.clearTimeout(requestTimer);requestTimer=null;}requestController=null;setBusy(false);renderNotice("warning",cfg.texts&&cfg.texts.timeout?cfg.texts.timeout:(cfg.texts&&cfg.texts.fallback?cfg.texts.fallback:"خطا"));},8000);fetch(cfg.ajaxUrl,{method:"POST",body:formData,credentials:"same-origin",signal:requestController?requestController.signal:void 0}).then(function(response){if(!response.ok){throw new Error(cfg.texts&&cfg.texts.fallback?cfg.texts.fallback:"خطا");}return response.json();}).then(function(payload){if(requestTimedOut){return;}clearPendingState();if(!payload||!payload.success){throw new Error(payload&&payload.data&&payload.data.message?payload.data.message:(cfg.texts&&cfg.texts.fallback?cfg.texts.fallback:"خطا"));}syncVisualState(payload.data||{});renderNotice("success",payload.data&&payload.data.message?payload.data.message:(cfg.texts&&cfg.texts.save?cfg.texts.save:""));}).catch(function(error){if(requestTimedOut||error&&error.name==="AbortError"){return;}clearPendingState();renderNotice("error",error&&error.message?error.message:(cfg.texts&&cfg.texts.fallback?cfg.texts.fallback:"خطا"));});};window.addEventListener("pageshow",function(){clearPendingState();});bookingForm.addEventListener("submit",function(event){var submitter=event.submitter||document.activeElement;if(submitter&&(submitter===publishButton||submitter===editorSaveButton||submitter.id==="luna-booking-editor-save")){event.preventDefault();submitAjax();}});publishButton.addEventListener("click",function(event){event.preventDefault();submitAjax();});if(editorSaveButton){editorSaveButton.addEventListener("click",function(event){event.preventDefault();submitAjax();});}});</script>';
	}

	public static function enqueue_booking_admin_assets() {
		if (! is_admin()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_booking_post_type = $screen && self::$booking_post_type === (string) $screen->post_type;
		$is_dashboard_screen  = $screen && ('toplevel_page_' . self::$booking_dashboard_slug) === (string) $screen->id;
		$is_calendar_screen   = $screen && false !== strpos((string) $screen->id, 'luna-bookings-calendar');

		if (! $screen || (! $is_booking_post_type && ! $is_dashboard_screen && ! $is_calendar_screen)) {
			return;
		}

		$theme_fonts_path = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() . '/assets/css/fonts.css' : '';
		if ($theme_fonts_path && file_exists($theme_fonts_path) && function_exists('get_stylesheet_directory_uri')) {
			$ver = (string) filemtime($theme_fonts_path);
			wp_enqueue_style('luna-theme-fonts-admin', get_stylesheet_directory_uri() . '/assets/css/fonts.css', array(), $ver);
		}

		wp_register_style('luna-bookings-admin', false, array(), '1.0.0');
		wp_enqueue_style('luna-bookings-admin');

		wp_add_inline_style(
			'luna-bookings-admin',
			'body.post-type-luna_booking,body.toplevel_page_luna-bookings-dashboard{--luna-bg:#f6efeb;--luna-surface:#f8f1ed;--luna-ink:#253042;--luna-muted:#7f7380;--luna-accent:#caa45f;--luna-line:rgba(37,48,66,.12);background:var(--luna-bg);}
			body.post-type-luna_booking #wpcontent{padding-left:20px}
			body.toplevel_page_luna-bookings-dashboard #wpcontent{padding-left:20px}
			body.post-type-luna_booking .wrap{max-width:none;width:auto;margin-left:20px}
			body.toplevel_page_luna-bookings-dashboard .wrap{max-width:1280px}
			body.post-type-luna_booking .wrap h1.wp-heading-inline{font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;letter-spacing:-.2px;color:var(--luna-ink)}
			body.toplevel_page_luna-bookings-dashboard .wrap h1{font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;letter-spacing:-.2px;color:var(--luna-ink)}
			body.post-type-luna_booking .wp-header-end{margin:18px 0}
			body.post-type-luna_booking .tablenav{padding:12px 12px 0}
			body.post-type-luna_booking .tablenav .actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
			body.post-type-luna_booking .tablenav .actions select,body.post-type-luna_booking .tablenav .actions input[type="date"]{border:1px solid var(--luna-line);border-radius:12px;padding:8px 10px;background:rgba(255,255,255,.75);height:38px}
			body.post-type-luna_booking .luna-date-filter{display:inline-flex;gap:8px;align-items:center}
			body.post-type-luna_booking .search-box input[type="search"]{border:1px solid var(--luna-line);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.75)}
			body.post-type-luna_booking .search-box input[type="submit"],body.post-type-luna_booking .tablenav .button{border-radius:12px;border:1px solid var(--luna-line);background:#fff;box-shadow:none}
			body.post-type-luna_booking .tablenav .button-primary{background:var(--luna-accent);border-color:rgba(0,0,0,.06);color:#253042}
			body.post-type-luna_booking .wp-list-table{border:1px solid var(--luna-line);border-radius:18px;overflow:hidden;background:rgba(255,255,255,.72);box-shadow:0 16px 40px rgba(37,48,66,.08)}
			body.post-type-luna_booking .wp-list-table thead th,body.post-type-luna_booking .wp-list-table tfoot th{background:rgba(248,241,237,.9);border-bottom:1px solid var(--luna-line);color:var(--luna-ink)}
			body.post-type-luna_booking .wp-list-table td,body.post-type-luna_booking .wp-list-table th{vertical-align:middle}
			body.post-type-luna_booking .wp-list-table tbody tr{background:transparent}
			body.post-type-luna_booking .wp-list-table tbody tr:hover{background:rgba(202,164,95,.10)}
			body.post-type-luna_booking .wp-list-table .column-title strong a{color:var(--luna-ink)}
			body.post-type-luna_booking .luna-order-link{font-weight:600;color:var(--luna-ink);text-decoration:none}
			body.post-type-luna_booking .luna-order-link:hover{text-decoration:underline}
			body.post-type-luna_booking .luna-status{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;border:1px solid var(--luna-line);background:rgba(255,255,255,.75);color:var(--luna-ink);white-space:nowrap;font-weight:600}
			body.post-type-luna_booking .luna-status.luna-status-confirmed{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.10)}
			body.post-type-luna_booking .luna-status.luna-status-cancelled{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.10)}
			body.post-type-luna_booking .luna-status.luna-status-failed{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.10)}
			body.post-type-luna_booking .luna-status.luna-status-pending_payment{border-color:rgba(202,164,95,.30);background:rgba(202,164,95,.12)}
			body.post-type-luna_booking .luna-status.luna-status-payment_review{border-color:rgba(59,130,246,.25);background:rgba(59,130,246,.10)}
			body.post-type-luna_booking .wp-list-table .column-booking_time{min-width:150px}
			body.post-type-luna_booking .wp-list-table .column-finance{min-width:220px}
			body.post-type-luna_booking .wp-list-table .column-quick_actions{width:150px;min-width:150px}
			body.post-type-luna_booking .luna-booking-quick-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
			body.post-type-luna_booking .luna-booking-quick-actions form{margin:0}
			body.post-type-luna_booking .luna-booking-quick-actions .button{min-height:30px;padding:2px 11px;border-radius:10px;font-family:inherit;font-weight:700;line-height:1.6}
			body.post-type-luna_booking .luna-quick-confirm{border-color:rgba(34,135,70,.3);background:rgba(63,177,96,.12);color:#176534}
			body.post-type-luna_booking .luna-quick-cancel{border-color:rgba(196,61,61,.28);background:rgba(225,77,77,.09);color:#a12828}
			body.post-type-luna_booking .luna-quick-done,body.post-type-luna_booking .luna-quick-cancelled{font-size:11px;font-weight:700;color:var(--luna-muted)}
			body.post-type-luna_booking .wp-list-table .column-customer_phone{white-space:nowrap}
			body.post-type-luna_booking .wp-list-table .column-wc_order_id{white-space:nowrap}
						body.post-type-luna_booking.post-php #poststuff #post-body.columns-2{margin-left:320px;margin-right:0}
			body.post-type-luna_booking.post-php #post-body-content{margin:0 0 0 0;float:none;width:auto;min-width:0}
			body.post-type-luna_booking.post-php #postbox-container-1{width:300px}
						body.post-type-luna_booking.post-php #postbox-container-2{float:none;width:auto;min-width:0;margin:0 !important}
			body.post-type-luna_booking.post-php #postbox-container-2 .meta-box-sortables{margin:0}
			body.post-type-luna_booking.post-php #postbox-container-1 .postbox,body.post-type-luna_booking.post-php #post-body-content .postbox{border:1px solid var(--luna-line);border-radius:24px;overflow:hidden;background:rgba(255,255,255,.78);box-shadow:0 18px 44px rgba(37,48,66,.08)}
			body.post-type-luna_booking.post-php #titlediv,body.post-type-luna_booking.post-php #minor-publishing,body.post-type-luna_booking.post-php .handle-order-higher,body.post-type-luna_booking.post-php .handle-order-lower{display:none}
			body.post-type-luna_booking.post-php #submitdiv .postbox-header,body.post-type-luna_booking.post-php #luna_booking_details .postbox-header{background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(248,241,237,.9));border-bottom:1px solid var(--luna-line);padding:16px 20px}
			body.post-type-luna_booking.post-php #submitdiv .postbox-header h2,body.post-type-luna_booking.post-php #luna_booking_details .postbox-header h2{font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;font-weight:800;font-size:17px;color:var(--luna-ink)}
			body.post-type-luna_booking.post-php #submitdiv .inside{padding:18px}
			body.post-type-luna_booking.post-php #submitdiv #submitpost{display:grid;gap:14px}
			body.post-type-luna_booking.post-php #submitdiv .misc-pub-luna-summary{display:grid;grid-template-columns:44px 1fr;gap:12px;align-items:flex-start;margin:0;padding:14px 15px;border:1px solid rgba(202,164,95,.22);border-radius:18px;background:linear-gradient(145deg,rgba(255,255,255,.92),rgba(248,241,237,.82))}
			body.post-type-luna_booking.post-php #submitdiv .misc-pub-luna-summary__icon{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#d9b16d,#c79f5a);color:#253042;font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;font-weight:900;box-shadow:0 10px 20px rgba(202,164,95,.22)}
			body.post-type-luna_booking.post-php #submitdiv .misc-pub-luna-summary__content strong{display:block;margin:0 0 6px;color:var(--luna-ink);font-weight:900;font-size:14px}
			body.post-type-luna_booking.post-php #submitdiv .misc-pub-luna-summary__content p{margin:0;color:var(--luna-muted);line-height:1.8}
			body.post-type-luna_booking.post-php #submitdiv #delete-action a{color:#9f3f3f;font-weight:700}
			body.post-type-luna_booking.post-php #submitdiv #major-publishing-actions{background:rgba(248,241,237,.75);border-top:1px solid var(--luna-line);padding:14px 18px}
			body.post-type-luna_booking.post-php #submitdiv .button-primary{background:linear-gradient(135deg,#d8b270,#caa45f);border-color:rgba(0,0,0,.06);color:#253042;border-radius:14px;padding:0 18px;font-weight:800;min-height:42px}
			body.post-type-luna_booking.post-php #submitdiv .button-secondary{border-radius:12px}
			body.post-type-luna_booking.post-php .luna-booking-editor{display:grid;gap:18px}
						body.post-type-luna_booking.post-php #luna-booking-editor-flash{display:grid;gap:10px}
						body.post-type-luna_booking.post-php #luna-booking-editor-flash .notice{margin:0}
			body.post-type-luna_booking.post-php .luna-booking-editor__hero{position:relative;padding:22px 24px;border:1px solid rgba(202,164,95,.22);border-radius:26px;background:radial-gradient(circle at top right,rgba(202,164,95,.24),transparent 38%),linear-gradient(145deg,rgba(255,255,255,.96),rgba(248,241,237,.86));box-shadow:0 20px 45px rgba(37,48,66,.10)}
			body.post-type-luna_booking.post-php .luna-booking-editor__eyebrow{display:inline-flex;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--luna-muted);font-weight:700}
			body.post-type-luna_booking.post-php .luna-booking-editor__hero-row{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-top:10px}
			body.post-type-luna_booking.post-php .luna-booking-editor__title{margin:0;font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;font-size:31px;line-height:1.12;font-weight:900;color:var(--luna-ink)}
			body.post-type-luna_booking.post-php .luna-booking-editor__subtitle{margin:8px 0 0;color:var(--luna-muted);font-size:14px}
			body.post-type-luna_booking.post-php .luna-booking-editor__chips{display:flex;gap:8px;flex-wrap:wrap}
			body.post-type-luna_booking.post-php .luna-booking-editor__meta-strip{display:flex;gap:14px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid rgba(37,48,66,.10);color:var(--luna-muted)}
			body.post-type-luna_booking.post-php .luna-booking-editor__meta-strip span,body.post-type-luna_booking.post-php .luna-booking-editor__meta-strip a{font-size:13px;color:var(--luna-muted);text-decoration:none}
			body.post-type-luna_booking.post-php .luna-booking-editor__meta-strip strong{color:var(--luna-ink);font-weight:800}
			body.post-type-luna_booking.post-php .luna-booking-editor__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
			body.post-type-luna_booking.post-php .luna-booking-card{padding:18px 18px 20px;border:1px solid var(--luna-line);border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.88),rgba(248,241,237,.72));box-shadow:0 14px 34px rgba(37,48,66,.08)}
			body.post-type-luna_booking.post-php .luna-booking-card--editor,body.post-type-luna_booking.post-php .luna-booking-card--history{grid-column:1/-1}
			body.post-type-luna_booking.post-php .luna-booking-card__head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid rgba(37,48,66,.10)}
			body.post-type-luna_booking.post-php .luna-booking-card__head h3{margin:0;font-family:"YekanBakhFaNum",Tahoma,Arial,sans-serif;font-size:20px;font-weight:900;color:var(--luna-ink)}
			body.post-type-luna_booking.post-php .luna-booking-card__head span{font-size:12px;color:var(--luna-muted)}
			body.post-type-luna_booking.post-php .luna-booking-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
			body.post-type-luna_booking.post-php .luna-booking-fact{padding:14px 15px;border:1px solid rgba(37,48,66,.08);border-radius:18px;background:rgba(255,255,255,.72)}
			body.post-type-luna_booking.post-php .luna-booking-fact label{display:block;margin-bottom:7px;color:var(--luna-muted);font-size:12px;font-weight:800}
			body.post-type-luna_booking.post-php .luna-booking-fact div,body.post-type-luna_booking.post-php .luna-booking-fact a{color:var(--luna-ink);font-weight:700;text-decoration:none;word-break:break-word}
			body.post-type-luna_booking.post-php .luna-booking-fact a:hover{color:#a37b37}
			body.post-type-luna_booking.post-php .luna-booking-finance-list{margin:0;padding:0;list-style:none;display:grid;gap:10px}
			body.post-type-luna_booking.post-php .luna-booking-finance-list li{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:13px 15px;border:1px solid rgba(37,48,66,.08);border-radius:18px;background:rgba(255,255,255,.72)}
			body.post-type-luna_booking.post-php .luna-booking-finance-list li span{color:var(--luna-muted);font-weight:700}
			body.post-type-luna_booking.post-php .luna-booking-finance-list li strong{color:var(--luna-ink);font-weight:900}
			body.post-type-luna_booking.post-php .luna-booking-empty{margin:0;padding:14px 15px;border:1px dashed rgba(37,48,66,.14);border-radius:18px;background:rgba(255,255,255,.55);color:var(--luna-muted)}
			body.post-type-luna_booking.post-php .luna-booking-reminders{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
			body.post-type-luna_booking.post-php .luna-booking-reminder{padding:16px;border:1px solid rgba(37,48,66,.08);border-radius:18px;background:rgba(255,255,255,.72)}
			body.post-type-luna_booking.post-php .luna-booking-reminder h4{margin:0 0 12px;font-size:16px;font-weight:900;color:var(--luna-ink)}
			body.post-type-luna_booking.post-php .luna-booking-reminder p{margin:0 0 8px;color:var(--luna-muted)}
			body.post-type-luna_booking.post-php .luna-booking-reminder strong{color:var(--luna-ink);font-weight:800}
			body.post-type-luna_booking.post-php .luna-booking-reminder .button{margin-top:8px;border-radius:12px}
			body.post-type-luna_booking.post-php .luna-booking-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
			body.post-type-luna_booking.post-php .luna-admin-field{display:grid;gap:8px}
			body.post-type-luna_booking.post-php .luna-admin-field--full{grid-column:1/-1}
			body.post-type-luna_booking.post-php .luna-admin-field label{color:var(--luna-ink);font-size:13px;font-weight:900}
			body.post-type-luna_booking.post-php .luna-admin-field input[type="date"],body.post-type-luna_booking.post-php .luna-admin-field input[type="time"],body.post-type-luna_booking.post-php .luna-admin-field select,body.post-type-luna_booking.post-php .luna-admin-field textarea{width:100%;border:1px solid rgba(37,48,66,.12);border-radius:16px;padding:12px 14px;background:rgba(255,255,255,.82);box-shadow:inset 0 1px 0 rgba(255,255,255,.65)}
			body.post-type-luna_booking.post-php .luna-admin-field textarea{min-height:130px;resize:vertical}
			body.post-type-luna_booking.post-php .luna-admin-check{display:flex;align-items:center;gap:10px;padding:13px 14px;border:1px solid rgba(37,48,66,.12);border-radius:16px;background:rgba(255,255,255,.82)}
			body.post-type-luna_booking.post-php .luna-admin-check input{margin:0}
						body.post-type-luna_booking.post-php .luna-booking-editor__actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:18px;padding-top:16px;border-top:1px solid rgba(37,48,66,.08)}
						body.post-type-luna_booking.post-php .luna-booking-editor__actions p{margin:0;color:var(--luna-muted);line-height:1.9;flex:1 1 280px}
						body.post-type-luna_booking.post-php .luna-booking-editor__actions .button-hero{min-height:46px;padding:0 20px;border-radius:14px;background:linear-gradient(135deg,#d8b270,#caa45f);border-color:rgba(0,0,0,.06);color:#253042;font-weight:900;box-shadow:0 14px 28px rgba(202,164,95,.20)}
						body.post-type-luna_booking.post-php .luna-booking-editor__actions #luna-booking-editor-flash{flex:1 1 100%}
						body.post-type-luna_booking.post-php form.is-saving #luna-booking-editor-save,body.post-type-luna_booking.post-php form.is-saving #publish{opacity:.7;pointer-events:none}
			body.post-type-luna_booking.post-php .luna-booking-history{margin:0;padding:16px 18px;border:1px solid rgba(37,48,66,.08);border-radius:18px;background:rgba(255,255,255,.72);white-space:pre-wrap;color:var(--luna-ink);font-family:Menlo,Monaco,monospace;line-height:1.9}
			body.toplevel_page_luna-bookings-dashboard .luna-bookings-dashboard{padding-top:8px}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;margin:14px 0 18px}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-title .luna-eyebrow{display:inline-block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-title h1{margin:6px 0 6px;font-size:32px;line-height:1.15}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-sub{margin:0;color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-actions .button-primary{background:var(--luna-accent);border-color:rgba(0,0,0,.06);color:#253042;border-radius:14px;padding:10px 14px}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:0 0 18px}
			body.toplevel_page_luna-bookings-dashboard .luna-card{display:block;padding:14px 14px;border-radius:18px;border:1px solid var(--luna-line);background:rgba(255,255,255,.72);box-shadow:0 10px 28px rgba(37,48,66,.08);text-decoration:none}
			body.toplevel_page_luna-bookings-dashboard .luna-card:hover{transform:translateY(-1px);box-shadow:0 16px 40px rgba(37,48,66,.10)}
			body.toplevel_page_luna-bookings-dashboard .luna-card .k{font-size:12px;color:var(--luna-muted);letter-spacing:.08em;text-transform:uppercase}
			body.toplevel_page_luna-bookings-dashboard .luna-card .v{margin-top:10px;font-size:26px;font-weight:700;color:var(--luna-ink)}
			body.toplevel_page_luna-bookings-dashboard .luna-card.warn{background:rgba(202,164,95,.12)}
			body.toplevel_page_luna-bookings-dashboard .luna-card.ok{background:rgba(34,197,94,.10)}
			body.toplevel_page_luna-bookings-dashboard .luna-card.bad{background:rgba(239,68,68,.10)}
			body.toplevel_page_luna-bookings-dashboard .luna-card.neutral{background:rgba(248,241,237,.9)}
			body.toplevel_page_luna-bookings-dashboard .luna-panel{border:1px solid var(--luna-line);border-radius:20px;background:rgba(255,255,255,.72);box-shadow:0 16px 40px rgba(37,48,66,.08);padding:16px}
			body.toplevel_page_luna-bookings-dashboard .luna-panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}
			body.toplevel_page_luna-bookings-dashboard .luna-panel-head h2{margin:0;font-size:16px;color:var(--luna-ink)}
			body.toplevel_page_luna-bookings-dashboard .luna-link{color:var(--luna-ink);text-decoration:none;font-weight:600}
			body.toplevel_page_luna-bookings-dashboard .luna-link:hover{text-decoration:underline}
			body.toplevel_page_luna-bookings-dashboard .luna-empty{margin:0;color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-latest-table{display:grid;gap:2px;border-radius:16px;overflow:hidden;border:1px solid var(--luna-line)}
			body.toplevel_page_luna-bookings-dashboard .luna-latest-table .row{display:grid;grid-template-columns:140px 1.2fr 1.2fr 220px 260px;gap:10px;align-items:center;padding:10px 12px;background:rgba(255,255,255,.72)}
			body.toplevel_page_luna-bookings-dashboard .luna-latest-table .row.head{background:rgba(248,241,237,.92);font-weight:700;color:var(--luna-ink)}
			body.toplevel_page_luna-bookings-dashboard .luna-latest-table .row:not(.head):hover{background:rgba(202,164,95,.10)}
			body.toplevel_page_luna-bookings-dashboard .luna-code{font-weight:800;color:var(--luna-ink);text-decoration:none}
			body.toplevel_page_luna-bookings-dashboard .luna-code:hover{text-decoration:underline}
			body.toplevel_page_luna-bookings-dashboard .badge{display:inline-flex;align-items:center;justify-content:center;padding:7px 10px;border-radius:999px;border:1px solid var(--luna-line);background:rgba(255,255,255,.75);font-weight:700;white-space:nowrap}
			body.toplevel_page_luna-bookings-dashboard .badge.badge-confirmed{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.10)}
			body.toplevel_page_luna-bookings-dashboard .badge.badge-cancelled{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.10)}
			body.toplevel_page_luna-bookings-dashboard .badge.badge-pending_payment{border-color:rgba(202,164,95,.30);background:rgba(202,164,95,.12)}
			body.toplevel_page_luna-bookings-dashboard .badge.badge-payment_review{border-color:rgba(59,130,246,.25);background:rgba(59,130,246,.10)}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:12px;margin:0 0 18px}
			body.toplevel_page_luna-bookings-dashboard .luna-muted{color:var(--luna-muted);font-size:12px}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-legend{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-legend span{display:inline-flex;align-items:center;gap:5px}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-legend span:before{content:"";width:8px;height:8px;border-radius:50%;background:#caa45f}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-legend .is-confirmed:before{background:#57a96b}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-legend .is-cancelled:before{background:#df6b6b}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-empty{min-height:150px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:7px;border:1px dashed var(--luna-line);border-radius:16px;background:rgba(255,255,255,.42);color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-chart-empty strong{color:var(--luna-ink);font-size:14px}
			body.toplevel_page_luna-bookings-dashboard .luna-chart{display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:6px;align-items:end}
			body.toplevel_page_luna-bookings-dashboard .luna-bar{display:flex;flex-direction:column;align-items:center;gap:6px}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-stack{width:100%;height:84px;border-radius:14px;border:1px solid var(--luna-line);background:rgba(255,255,255,.6);position:relative;overflow:hidden}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-stack:before{content:"";position:absolute;left:0;right:0;bottom:0;height:var(--t);background:linear-gradient(180deg,rgba(202,164,95,.38),rgba(202,164,95,.72))}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-stack:after{content:"";position:absolute;left:4px;right:4px;bottom:0;height:var(--c);background:rgba(72,151,91,.72);border-radius:8px 8px 0 0}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-stack i{position:absolute;left:9px;right:9px;bottom:0;height:var(--x);background:rgba(218,79,79,.82);border-radius:6px 6px 0 0}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-stack b{position:absolute;z-index:3;top:6px;left:0;right:0;text-align:center;color:var(--luna-ink);font-size:11px}
			body.toplevel_page_luna-bookings-dashboard .luna-bar-label{font-size:10px;color:var(--luna-muted)}
			body.toplevel_page_luna-bookings-dashboard .luna-top-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
			body.toplevel_page_luna-bookings-dashboard .luna-subhead{margin:0 0 10px;font-size:13px;color:var(--luna-ink)}
			body.toplevel_page_luna-bookings-dashboard .luna-top-list{margin:0;padding:0 18px 0 0;display:grid;gap:8px}
			body.toplevel_page_luna-bookings-dashboard .luna-top-list li{display:flex;justify-content:space-between;gap:10px}
			body.toplevel_page_luna-bookings-dashboard .luna-top-list .n{color:var(--luna-ink);font-weight:650}
			body.toplevel_page_luna-bookings-dashboard .luna-top-list .v{color:var(--luna-muted);font-weight:700;white-space:nowrap}
			body.toplevel_page_luna-bookings-dashboard .luna-dash-grid .luna-bar-stack i{display:block}
			body[class*="luna-bookings-calendar"] #wpcontent{padding-left:20px}
			body[class*="luna-bookings-calendar"] .wrap{max-width:1280px}
			body[class*="luna-bookings-calendar"] .luna-cal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0 0 18px}
			body[class*="luna-bookings-calendar"] .luna-cal-day .luna-empty{margin:0;color:var(--luna-muted)}
			body[class*="luna-bookings-calendar"] .luna-cal-table{display:grid;gap:2px;border-radius:16px;overflow:hidden;border:1px solid var(--luna-line)}
			body[class*="luna-bookings-calendar"] .luna-cal-table .row{display:grid;grid-template-columns:90px 1fr 1fr 1fr 180px 90px;gap:10px;align-items:center;padding:10px 12px;background:rgba(255,255,255,.72)}
			body[class*="luna-bookings-calendar"] .luna-cal-table .row.head{background:rgba(248,241,237,.92);font-weight:700;color:var(--luna-ink)}
			body[class*="luna-bookings-calendar"] .luna-cal-table .row:not(.head):hover{background:rgba(202,164,95,.10)}
						@media (max-width:1100px){body.post-type-luna_booking.post-php #poststuff #post-body.columns-2{margin-left:0;margin-right:0}body.post-type-luna_booking.post-php #postbox-container-1{width:100%}body.post-type-luna_booking.post-php .luna-booking-editor__grid,body.post-type-luna_booking.post-php .luna-booking-reminders,body.post-type-luna_booking.post-php .luna-booking-facts,body.post-type-luna_booking.post-php .luna-booking-form-grid,body.toplevel_page_luna-bookings-dashboard .luna-dash-grid{grid-template-columns:1fr}body.toplevel_page_luna-bookings-dashboard .luna-chart{grid-template-columns:repeat(7,minmax(0,1fr))}body[class*="luna-bookings-calendar"] .luna-cal-grid{grid-template-columns:1fr}}'
		);
	}

	public static function filter_checkout_fields_for_booking($fields) {
		if (! self::is_booking_checkout_context()) {
			return $fields;
		}

		if (isset($fields['billing'])) {
			foreach (array('billing_country', 'billing_state', 'billing_city', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_company') as $key) {
				unset($fields['billing'][ $key ]);
			}
		}

		unset($fields['shipping']);
		unset($fields['order']['order_comments']);

		return $fields;
	}

	public static function filter_default_address_fields_for_booking($fields) {
		if (! self::is_booking_checkout_context()) {
			return $fields;
		}

		foreach (array('country', 'state', 'city', 'address_1', 'address_2', 'postcode', 'company') as $key) {
			unset($fields[ $key ]);
		}

		return $fields;
	}

	public static function filter_booking_order_pay_gateways($available_gateways) {
		$order = self::get_booking_checkout_order_from_request();

		if (! $order instanceof WC_Order) {
			return $available_gateways;
		}

		$payment_method = sanitize_key((string) $order->get_payment_method());

		if ('' === $payment_method) {
			return $available_gateways;
		}

		$selected_gateway = isset($available_gateways[ $payment_method ]) && is_object($available_gateways[ $payment_method ])
			? $available_gateways[ $payment_method ]
			: self::get_payment_gateway($payment_method);

		if (! $selected_gateway instanceof WC_Payment_Gateway) {
			return $available_gateways;
		}

		$available_gateways[ $payment_method ] = $selected_gateway;

		foreach ($available_gateways as $gateway_id => $gateway) {
			if ($gateway_id !== $payment_method) {
				unset($available_gateways[ $gateway_id ]);
				continue;
			}

			$available_gateways[ $gateway_id ]->chosen = true;
		}

		if (function_exists('WC') && WC()->session) {
			WC()->session->set('chosen_payment_method', $payment_method);
		}

		return $available_gateways;
	}

	protected static function is_booking_checkout_context() {
		return self::get_booking_checkout_order_from_request() instanceof WC_Order;
	}

	public static function is_booking_order_pay_context($order = false) {
		if (! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url('order-pay')) {
			return false;
		}

		$order = $order instanceof WC_Order ? $order : self::get_booking_checkout_order_from_request();

		return $order instanceof WC_Order;
	}

	public static function get_booking_order_pay_gateway($order = false) {
		$order = $order instanceof WC_Order ? $order : self::get_booking_checkout_order_from_request();

		if (! $order instanceof WC_Order) {
			return null;
		}

		return self::get_payment_gateway((string) $order->get_payment_method());
	}

	public static function should_auto_submit_booking_order_pay($order = false) {
		if (! self::is_booking_order_pay_context($order)) {
			return false;
		}

		return isset($_GET['luna_booking_autopay']) && '1' === sanitize_text_field(wp_unslash($_GET['luna_booking_autopay']));
	}

	protected static function get_booking_checkout_order_from_request() {
		if (! function_exists('is_checkout') || ! is_checkout()) {
			return false;
		}

		if (! function_exists('wc_get_order')) {
			return false;
		}

		$order_id = 0;
		if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
			$order_id = (int) get_query_var('order-pay');
		}

		if ($order_id <= 0 && isset($_GET['key']) && function_exists('wc_get_order_id_by_order_key')) {
			$key      = sanitize_text_field(wp_unslash($_GET['key']));
			$order_id = (int) wc_get_order_id_by_order_key($key);
		}

		if ($order_id <= 0) {
			return false;
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return false;
		}

		$booking_id = (int) $order->get_meta('_luna_booking_id', true);
		if ($booking_id > 0 || 'luna_booking' === (string) $order->get_created_via()) {
			return $order;
		}

		return false;
	}

	/**
	 * Build the payment URL used after booking submission.
	 *
	 * For online gateways we land on WooCommerce pay-for-order with an auto-pay
	 * flag so the page can submit the payment form immediately without asking for
	 * the gateway a second time.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	protected static function get_booking_payment_url($order) {
		if (! $order instanceof WC_Order) {
			return '';
		}

		if (! $order->needs_payment() || ! in_array((string) $order->get_status(), array('pending', 'failed'), true)) {
			return '';
		}

		$payment_url    = (string) $order->get_checkout_payment_url(false);
		$payment_method = sanitize_key((string) $order->get_payment_method());

		if ('' === $payment_url) {
			return '';
		}

		if ('cod' === $payment_method) {
			return $payment_url;
		}

		return (string) add_query_arg('luna_booking_autopay', '1', $payment_url);
	}

	/**
	 * Sync a booking after an order payment completes.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public static function handle_order_paid($order_id) {
		self::sync_booking_from_order((int) $order_id);
	}

	/**
	 * Sync booking status whenever the WooCommerce order changes state.
	 *
	 * @param int            $order_id WooCommerce order id.
	 * @param string         $from_status Previous order status.
	 * @param string         $to_status New order status.
	 * @param WC_Order|false $order Order object.
	 * @return void
	 */
	public static function handle_order_status_changed($order_id, $from_status, $to_status, $order) {
		unset($from_status, $to_status);
		self::sync_booking_from_order((int) $order_id, $order);
	}

	/** Sync full and partial refunds, which do not always change order status. */
	public static function handle_order_refunded($order_id, $refund_id = 0) {
		unset($refund_id);
		self::sync_booking_from_order((int) $order_id);
	}

	/**
	 * Convert relationship IDs to their counterpart in the active language.
	 * Technical booking records remain language-neutral; only the selected
	 * service/specialist content IDs are localized here.
	 *
	 * @param int[] $ids Post IDs.
	 * @return int[]
	 */
	protected static function translate_relationship_ids($ids) {
		$translated = array();
		foreach ((array) $ids as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			if (function_exists('luna_translate_object_id')) {
				$post_id = (int) luna_translate_object_id($post_id);
			} elseif (function_exists('pll_get_post')) {
				$localized_id = (int) pll_get_post($post_id);
				$post_id      = $localized_id > 0 ? $localized_id : $post_id;
			}
			$translated[] = $post_id;
		}

		return array_values(array_unique($translated));
	}

	/** @return string */
	protected static function request_language() {
		$language = isset($_POST['language']) ? sanitize_key(wp_unslash($_POST['language'])) : '';
		if (! in_array($language, array('fa', 'en', 'ar'), true)) {
			$language = function_exists('luna_current_language') ? luna_current_language() : (function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'fa');
		}
		return in_array($language, array('fa', 'en', 'ar'), true) ? $language : 'fa';
	}

	/**
	 * Resolve a post by slug for a specific post type.
	 *
	 * @param string $slug Post slug.
	 * @param string $post_type Post type.
	 * @return WP_Post|null
	 */
	protected static function resolve_post_by_slug($slug, $post_type) {
		if ('' === $slug) {
			return null;
		}

		$post = get_page_by_path($slug, OBJECT, $post_type);

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Generate a unique booking code.
	 *
	 * @return string
	 */
	protected static function generate_booking_code() {
		$attempts = 0;

		do {
			$attempts++;
			$code = 'LN-' . strtoupper(wp_generate_password(5, false, false));
		} while (class_exists('Luna_Appointments_Bookings_Table') && Luna_Appointments_Bookings_Table::booking_code_exists($code) && $attempts < 8);

		return $code;
	}

	/**
	 * Create a WooCommerce payment order for a booking.
	 *
	 * @param int     $booking_id Booking id.
	 * @param WP_Post $service_post Service post object.
	 * @param WP_Post $specialist_post Specialist post object.
	 * @param array   $context Booking context.
	 * @return array<string,mixed>|WP_Error
	 */
	protected static function create_payment_order($booking_id, $service_post, $specialist_post, $context) {
		if (! function_exists('wc_create_order') || ! function_exists('WC') || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return new WP_Error('woocommerce_unavailable', __('در حال حاضر امکان ساخت سفارش ووکامرس برای این رزرو وجود ندارد.', 'luna-appointments'));
		}

		$billing_name_parts = self::split_customer_name(isset($context['customer_name']) ? (string) $context['customer_name'] : '');
		$customer_id        = isset($context['customer_user_id']) ? (int) $context['customer_user_id'] : get_current_user_id();
		$order_args         = $customer_id > 0 ? array('customer_id' => $customer_id) : array();
		$order              = wc_create_order($order_args);

		if (is_wp_error($order) || ! $order instanceof WC_Order) {
			return new WP_Error('woocommerce_order_failed', __('سفارش پرداخت این رزرو ساخته نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'));
		}

		$payment_method = isset($context['payment_method']) ? sanitize_key((string) $context['payment_method']) : '';
		$gateway        = self::get_payment_gateway($payment_method);
		$finance_quote  = self::normalize_booking_finance_quote(
			isset($context['finance_quote']) ? $context['finance_quote'] : array(),
			array(
				'base_amount'    => isset($context['base_price']) ? (float) $context['base_price'] : 0,
				'price_label'    => isset($context['price_label']) ? (string) $context['price_label'] : '',
				'payable_amount' => isset($context['base_price']) ? (float) $context['base_price'] : 0,
			)
		);
		$amount         = isset($finance_quote['payable_amount']) ? (float) $finance_quote['payable_amount'] : (isset($context['base_price']) ? (float) $context['base_price'] : 0.0);
		$display_name   = get_the_title($service_post);
		$booking_date   = isset($context['booking_date']) ? (string) $context['booking_date'] : '';
		$booking_time   = isset($context['booking_time']) ? (string) $context['booking_time'] : '';
		$price_label    = isset($finance_quote['price_label']) && '' !== trim((string) $finance_quote['price_label'])
			? (string) $finance_quote['price_label']
			: wc_price($amount);
		$fee_item       = new WC_Order_Item_Fee();

		$is_consultation_fee = ! empty($finance_quote['meta']['consultation_finance']);
		$fee_item->set_name($is_consultation_fee ? sprintf(__('هزینه اولیه مشاوره: %s', 'luna-appointments'), $display_name) : sprintf(__('رزرو: %s', 'luna-appointments'), $display_name));
		$fee_item->set_amount($amount);
		$fee_item->set_total($amount);
		$order->add_item($fee_item);

		$order->set_created_via('luna_booking');
		$order->set_currency(get_woocommerce_currency());
		$order->set_address(
			array(
				'first_name' => $billing_name_parts['first_name'],
				'last_name'  => $billing_name_parts['last_name'],
				'email'      => isset($context['customer_email']) ? (string) $context['customer_email'] : '',
				'phone'      => isset($context['customer_phone']) ? (string) $context['customer_phone'] : '',
			),
			'billing'
		);
		$order->set_customer_note(
			sprintf(
				/* translators: 1: booking code, 2: specialist name, 3: booking date, 4: booking time */
				__('رزرو لونا %1$s با %2$s در تاریخ %3$s ساعت %4$s.', 'luna-appointments'),
				isset($context['booking_code']) ? (string) $context['booking_code'] : '',
				get_the_title($specialist_post),
				$booking_date,
				$booking_time
			)
		);

		if ($gateway) {
			$order->set_payment_method($gateway);
			$order->set_payment_method_title(self::get_payment_label($payment_method));
		} else {
			$order->set_payment_method($payment_method);
			$order->set_payment_method_title(self::get_payment_label($payment_method));
		}

		if ('cod' === $payment_method) {
			$order->set_status('on-hold', __('رزرو ثبت شد و پرداخت در محل انجام می‌شود.', 'luna-appointments'));
		} elseif ($amount <= 0) {
			$order->set_status('processing', __('رزرو ثبت شد و نیازی به پرداخت آنلاین نیست.', 'luna-appointments'));
		}

		$order->update_meta_data('_luna_booking_id', (int) $booking_id);
		$order->update_meta_data('_luna_booking_code', isset($context['booking_code']) ? (string) $context['booking_code'] : '');
		$order->update_meta_data('_luna_language', isset($context['language']) && in_array((string) $context['language'], array('fa', 'en', 'ar'), true) ? (string) $context['language'] : 'fa');
		$order->update_meta_data('_luna_is_vip', isset($context['is_vip']) ? (int) $context['is_vip'] : 0);
		$order->update_meta_data('_luna_booking_service_id', (int) $service_post->ID);
		$order->update_meta_data('_luna_booking_specialist_id', (int) $specialist_post->ID);
		$order->update_meta_data('_luna_booking_specialist_name', get_the_title($specialist_post));
		$order->update_meta_data('_luna_booking_date', $booking_date);
		$order->update_meta_data('_luna_booking_time', $booking_time);
		$order->update_meta_data('_luna_booking_buffer_minutes', isset($context['buffer_minutes']) ? (int) $context['buffer_minutes'] : 0);
		$order->update_meta_data('_luna_booking_price_label', $price_label);
		$order->update_meta_data('_luna_booking_base_amount', isset($finance_quote['base_amount']) ? (float) $finance_quote['base_amount'] : 0);
		$order->update_meta_data('_luna_booking_discount_amount', isset($finance_quote['discount_amount']) ? (float) $finance_quote['discount_amount'] : 0);
		$order->update_meta_data('_luna_booking_gift_amount', isset($finance_quote['gift_amount']) ? (float) $finance_quote['gift_amount'] : 0);
		$order->update_meta_data('_luna_booking_wallet_amount', isset($finance_quote['wallet_amount']) ? (float) $finance_quote['wallet_amount'] : 0);
		$order->update_meta_data('_luna_booking_payable_amount', $amount);
		$order->update_meta_data('_luna_booking_finance_quote', wp_json_encode($finance_quote));
		$order->calculate_totals(false);
		$order->save();

		$existing_booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
		if (! is_array($existing_booking)) {
			$order->update_meta_data('_luna_booking_link_failed', 1);
			$order->add_order_note(__('رکورد اصلی رزرو پس از ساخت سفارش پیدا نشد؛ سفارش برای جلوگیری از پرداخت اشتباه لغو شد.', 'luna-appointments'));
			if (! $order->is_paid()) {
				$order->set_status('cancelled');
			}
			$order->save();
			return new WP_Error('booking_row_missing_after_order', __('سفارش ساخته شد اما رکورد اصلی رزرو در دسترس نبود؛ سفارش ایمن‌سازی و لغو شد.', 'luna-appointments'));
		}

		$resolved_state = self::resolve_booking_state_from_order($order, $existing_booking);
		$linked         = Luna_Appointments_Bookings_Table::update_booking(
				(int) $booking_id,
				array(
					'wc_order_id'    => (int) $order->get_id(),
					'wc_order_key'   => (string) $order->get_order_key(),
					'status'         => $resolved_state['status'],
					'payment_status' => $resolved_state['payment_status'],
					'payment_method' => $order->get_payment_method(),
					'notes'          => self::append_booking_note(
						is_array($existing_booking) && isset($existing_booking['notes']) ? (string) $existing_booking['notes'] : '',
						sprintf(
						/* translators: 1: order number, 2: order status */
						__('سفارش ووکامرس #%1$s با وضعیت %2$s برای این رزرو ساخته شد.', 'luna-appointments'),
						$order->get_order_number(),
						wc_get_order_status_name($order->get_status())
						)
					),
				)
		);
		$linked_booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
		$linked         = $linked && is_array($linked_booking) && (int) ($linked_booking['wc_order_id'] ?? 0) === (int) $order->get_id();

		if (! $linked) {
				$order->update_meta_data('_luna_booking_link_failed', 1);
				$order->add_order_note(__('اتصال سفارش به رکورد رزرو ناموفق بود؛ سفارش برای جلوگیری از پرداخت اشتباه لغو شد.', 'luna-appointments'));
				if (! $order->is_paid()) {
					$order->set_status('cancelled');
				}
				$order->save();
				return new WP_Error('booking_order_link_failed', __('سفارش ساخته شد اما اتصال امن آن به رزرو انجام نشد. رزرو برای بررسی ثبت و سفارش لغو شد.', 'luna-appointments'));
		}

		self::upsert_booking_post_from_row_id((int) $booking_id);
		do_action(
			'luna_appointments_booking_finance_committed',
			(int) $booking_id,
			(int) $order->get_id(),
			$finance_quote,
			$context
		);

		return array(
			'order_id'     => (int) $order->get_id(),
			'order_key'    => (string) $order->get_order_key(),
			'payment_url'  => self::get_booking_payment_url($order),
			'order_status' => (string) $order->get_status(),
		);
	}

	/**
	 * Sync the linked booking row from a WooCommerce order.
	 *
	 * @param int            $order_id WooCommerce order id.
	 * @param WC_Order|false $order Order object.
	 * @return void
	 */
	protected static function sync_booking_from_order($order_id, $order = false) {
		if (! class_exists('Luna_Appointments_Bookings_Table') || ! function_exists('wc_get_order')) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order((int) $order_id);

		if (! $order instanceof WC_Order) {
			return;
		}

		$booking_id = (int) $order->get_meta('_luna_booking_id', true);
		$booking    = $booking_id > 0
			? Luna_Appointments_Bookings_Table::get_booking($booking_id)
			: Luna_Appointments_Bookings_Table::get_booking_by_order_id((int) $order->get_id());

		if (! is_array($booking) || empty($booking['id'])) {
			return;
		}
		if ($booking_id <= 0) {
			$order->update_meta_data('_luna_booking_id', (int) $booking['id']);
			$order->save_meta_data();
		}

		$customer_user_id = (int) $order->get_customer_id();
		$is_vip           = $customer_user_id > 0 ? (self::is_user_vip($customer_user_id) ? 1 : 0) : (int) $order->get_meta('_luna_is_vip', true);
		$resolved_state   = self::resolve_booking_state_from_order($order, $booking);

		// A late payment must never silently create an overlapping confirmed slot.
		if ('confirmed' === $resolved_state['status'] && ! in_array((string) ($booking['status'] ?? ''), array('confirmed', 'completed', 'done'), true)) {
			$has_conflict = Luna_Appointments_Bookings_Table::slot_exists(
				(int) ($booking['specialist_id'] ?? 0),
				(string) ($booking['booking_date'] ?? ''),
				(string) ($booking['booking_time'] ?? ''),
				(int) ($booking['duration_minutes'] ?? 0),
				(int) ($booking['buffer_minutes'] ?? 0),
				(int) $booking['id']
			);
			if ($has_conflict) {
				$resolved_state['status'] = 'conflict';
			}
		}
		$update_data      = array(
			'wc_order_id'    => (int) $order->get_id(),
			'wc_order_key'   => (string) $order->get_order_key(),
			'status'         => $resolved_state['status'],
			'payment_status' => $resolved_state['payment_status'],
			'payment_method' => $order->get_payment_method() ? $order->get_payment_method() : (isset($booking['payment_method']) ? (string) $booking['payment_method'] : ''),
			'notes'          => self::build_order_sync_note(isset($booking['notes']) ? (string) $booking['notes'] : '', $order, $booking, $resolved_state),
		);
		if ('conflict' === $resolved_state['status']) {
			$update_data['admin_note'] = self::append_booking_note(
				isset($booking['admin_note']) ? (string) $booking['admin_note'] : '',
				__('پرداخت سفارش انجام شده، اما این بازه زمانی اکنون با رزرو دیگری تداخل دارد و باید دستی بررسی شود.', 'luna-appointments')
			);
		}
		if ($customer_user_id > 0 && empty($booking['customer_user_id'])) {
			$update_data['customer_user_id'] = $customer_user_id;
		}
		if (0 !== $is_vip || empty($booking['is_vip'])) {
			$update_data['is_vip'] = $is_vip ? 1 : 0;
		}

		$updated = Luna_Appointments_Bookings_Table::update_booking(
			(int) $booking['id'],
			$update_data
		);
		if (! $updated) {
			do_action('luna_appointments_booking_order_sync_failed', (int) $booking['id'], (int) $order->get_id(), $update_data);
			return;
		}
		self::maybe_trigger_booking_status_transition((int) $booking['id'], $booking, $update_data, 'order_sync');

		self::upsert_booking_post_from_row_id((int) $booking['id']);
		self::maybe_award_vip_points((int) $booking['id'], $order);
		self::maybe_schedule_booking_reminders((int) $booking['id']);
	}

	/**
	 * Resolve operational and financial states without overwriting deliberate
	 * terminal booking decisions such as completed or cancelled appointments.
	 */
	protected static function resolve_booking_state_from_order($order, $booking = array()) {
		$order_status   = sanitize_key((string) $order->get_status());
		$current_status = sanitize_key((string) ($booking['status'] ?? ''));
		$payment_status = self::map_booking_payment_status_from_order($order);
		$method         = sanitize_key((string) $order->get_payment_method());
		$is_consultation_deposit = 'yes' === (string) $order->get_meta('_luna_consultation_finance', true);

		if (in_array($current_status, array('completed', 'done'), true)) {
			return array('status' => $current_status, 'payment_status' => $payment_status);
		}
		if ('cancelled' === $current_status && ! in_array($order_status, array('refunded'), true)) {
			return array('status' => 'cancelled', 'payment_status' => $payment_status);
		}
		if ('refunded' === $order_status || 'refunded' === $payment_status) {
			return array('status' => 'cancelled' === $current_status ? 'cancelled' : 'refunded', 'payment_status' => $payment_status);
		}
		if ('cancelled' === $order_status) {
			return array('status' => 'cancelled', 'payment_status' => $payment_status);
		}
		if ($order->is_paid()) {
			if ($is_consultation_deposit) {
				return array('status' => 'consultation_pending', 'payment_status' => 'deposit_paid');
			}
			return array('status' => 'confirmed', 'payment_status' => $payment_status);
		}
		if ('on-hold' === $order_status) {
			$status = 'cod' === $method || 'confirmed' === $current_status ? 'confirmed' : 'payment_review';
			return array('status' => $status, 'payment_status' => $payment_status);
		}
		if ('failed' === $order_status) {
			$status = 'confirmed' === $current_status ? 'confirmed' : 'failed';
			return array('status' => $status, 'payment_status' => $payment_status);
		}

		return array(
			'status'         => 'confirmed' === $current_status ? 'confirmed' : 'pending_payment',
			'payment_status' => $payment_status,
		);
	}

	/**
	 * Resolve the WooCommerce payment gateway object.
	 *
	 * @param string $payment_method Gateway id.
	 * @return WC_Payment_Gateway|null
	 */
	protected static function get_payment_gateway($payment_method) {
		if (! function_exists('WC') || ! WC() || ! method_exists(WC(), 'payment_gateways')) {
			return null;
		}

		$gateway_manager = WC()->payment_gateways();
		$gateways        = $gateway_manager && method_exists($gateway_manager, 'payment_gateways')
			? $gateway_manager->payment_gateways()
			: array();

		return isset($gateways[ $payment_method ]) && is_object($gateways[ $payment_method ])
			? $gateways[ $payment_method ]
			: null;
	}

	/**
	 * Split a full name into billing first and last name.
	 *
	 * @param string $full_name Full name.
	 * @return array<string,string>
	 */
	protected static function split_customer_name($full_name) {
		$full_name = trim((string) $full_name);

		if ('' === $full_name) {
			return array(
				'first_name' => '',
				'last_name'  => '',
			);
		}

		$parts = preg_split('/\s+/', $full_name);

		if (! is_array($parts) || empty($parts)) {
			return array(
				'first_name' => $full_name,
				'last_name'  => '',
			);
		}

		$first_name = array_shift($parts);

		return array(
			'first_name' => (string) $first_name,
			'last_name'  => implode(' ', $parts),
		);
	}

	/**
	 * Map WooCommerce order status to booking status.
	 *
	 * @param string $order_status WooCommerce order status.
	 * @return string
	 */
	protected static function map_booking_status_from_order($order_status, $order = null, $booking = array()) {
		if ($order instanceof WC_Order) {
			$state = self::resolve_booking_state_from_order($order, $booking);
			return $state['status'];
		}
		$order_status = sanitize_key((string) $order_status);

		if (in_array($order_status, array('processing', 'completed'), true)) {
			return 'confirmed';
		}

		if ('on-hold' === $order_status) {
			return 'payment_review';
		}

		if ('refunded' === $order_status) {
			return 'refunded';
		}

		if ('cancelled' === $order_status) {
			return 'cancelled';
		}

		if ('failed' === $order_status) {
			return 'failed';
		}

		return 'pending_payment';
	}

	/**
	 * Map WooCommerce payment state to booking payment status.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return string
	 */
	protected static function map_booking_payment_status_from_order($order) {
		$order_status = sanitize_key((string) $order->get_status());
		$total        = (float) $order->get_total();
		$refunded     = (float) $order->get_total_refunded();

		if ($refunded > 0 && $total > 0 && $refunded < $total) {
			return 'partial_refund';
		}

		if ('refunded' === $order_status || ($total > 0 && $refunded >= $total)) {
			return 'refunded';
		}

		if ($order->is_paid()) {
			return 'paid';
		}

		if ('failed' === $order_status) {
			return 'failed';
		}

		if ('on-hold' === $order_status) {
			return 'authorized';
		}

		if ('cancelled' === $order_status) {
			return 'cancelled';
		}

		return 'pending';
	}

	protected static function append_booking_note($existing, $note) {
		$existing = trim((string) $existing);
		$note     = trim((string) $note);
		$stamp    = wp_date(get_option('date_format') . ' ' . get_option('time_format'));

		if ('' === $note) {
			return $existing;
		}

		if ('' === $existing) {
			return '[' . $stamp . '] ' . $note;
		}

		return $existing . "\n" . '[' . $stamp . '] ' . $note;
	}

	protected static function build_order_sync_note($existing, $order, $booking, $resolved_state = null) {
		$resolved_state     = is_array($resolved_state) ? $resolved_state : self::resolve_booking_state_from_order($order, $booking);
		$new_status         = (string) $resolved_state['status'];
		$new_payment_status = (string) $resolved_state['payment_status'];
		$current_status     = isset($booking['status']) ? (string) $booking['status'] : '';
		$current_payment    = isset($booking['payment_status']) ? (string) $booking['payment_status'] : '';

		if ($new_status === $current_status && $new_payment_status === $current_payment) {
			return $existing;
		}

		return self::append_booking_note(
			$existing,
			sprintf(
				__('سفارش ووکامرس #%1$s به وضعیت %2$s رسید و وضعیت پرداخت رزرو به %3$s به‌روزرسانی شد.', 'luna-appointments'),
				$order->get_order_number(),
				wc_get_order_status_name($order->get_status()),
				$new_payment_status
			)
		);
	}

	protected static function get_specialist_schedule($specialist_post_id) {
		$specialist_post_id = (int) $specialist_post_id;
		$meta               = class_exists('Luna_Appointments_Specialists') ? Luna_Appointments_Specialists::get_specialist_meta_values($specialist_post_id) : array();

		$has_saved_days = metadata_exists('post', $specialist_post_id, '_luna_specialist_working_days');
		$days = $has_saved_days && isset($meta['_luna_specialist_working_days']) && is_array($meta['_luna_specialist_working_days'])
			? array_values(
				array_filter(
					array_map(
						static function ($day) {
							return (int) $day;
						},
						$meta['_luna_specialist_working_days']
					),
					static function ($day) {
						return $day >= 0 && $day <= 6;
					}
				)
			)
			: array(0, 1, 2, 3, 4, 5);
		if (! $has_saved_days) {
			$days = array(0, 1, 2, 3, 4, 5);
		}
		$days = self::apply_booking_weekend_settings($days);

		$start = isset($meta['_luna_specialist_working_start']) && preg_match('/^\d{2}:\d{2}$/', (string) $meta['_luna_specialist_working_start'])
			? (string) $meta['_luna_specialist_working_start']
			: '10:00';
		$end   = isset($meta['_luna_specialist_working_end']) && preg_match('/^\d{2}:\d{2}$/', (string) $meta['_luna_specialist_working_end'])
			? (string) $meta['_luna_specialist_working_end']
			: '20:00';

		$off_dates = isset($meta['_luna_specialist_off_dates']) ? preg_split('/\r\n|\r|\n/', (string) $meta['_luna_specialist_off_dates']) : array();
		$off_dates = array_values(
			array_filter(
				array_map(
					static function ($line) {
                                                return class_exists('Luna_Appointments_Specialists') && method_exists('Luna_Appointments_Specialists', 'normalize_schedule_date_input')
                                                        ? Luna_Appointments_Specialists::normalize_schedule_date_input($line)
                                                        : '';
					},
					is_array($off_dates) ? $off_dates : array()
				),
				static function ($line) {
					return '' !== $line;
				}
			)
		);
				$leave_ranges = class_exists('Luna_Appointments_Specialists') && isset($meta['_luna_specialist_leave_ranges'])
						? Luna_Appointments_Specialists::parse_date_ranges($meta['_luna_specialist_leave_ranges'])
						: array();
				$blocked_slots = class_exists('Luna_Appointments_Specialists') && isset($meta['_luna_specialist_blocked_slots'])
						? Luna_Appointments_Specialists::parse_blocked_slots($meta['_luna_specialist_blocked_slots'])
						: array();

		return array(
						'days'         => $days,
						'start'        => $start,
						'end'          => $end,
						'offDates'     => $off_dates,
						'leaveRanges'  => $leave_ranges,
						'blockedSlots' => $blocked_slots,
		);
	}

	protected static function is_specialist_open_for_date($schedule, $booking_date) {
		$booking_date = (string) $booking_date;

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
			return false;
		}

				$days        = isset($schedule['days']) && is_array($schedule['days']) ? array_map('intval', $schedule['days']) : array();
				$offDates    = isset($schedule['offDates']) && is_array($schedule['offDates']) ? $schedule['offDates'] : array();
				$leaveRanges = isset($schedule['leaveRanges']) && is_array($schedule['leaveRanges']) ? $schedule['leaveRanges'] : array();

		if (in_array($booking_date, $offDates, true)) {
			return false;
		}

				if (self::is_date_in_schedule_ranges($booking_date, $leaveRanges)) {
						return false;
				}

		$date_object = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::parse_date($booking_date) : null;
		if (! $date_object) {
			return false;
		}

		$greg_w = (int) $date_object->format('w');
		$idx    = ($greg_w + 1) % 7;

		return in_array($idx, $days, true);
	}

		protected static function is_time_allowed_by_schedule($booking_time, $duration_minutes, $buffer_minutes, $schedule, $booking_date = '') {
		$booking_time = (string) $booking_time;
		$start        = isset($schedule['start']) ? (string) $schedule['start'] : '10:00';
		$end          = isset($schedule['end']) ? (string) $schedule['end'] : '20:00';

		$time_m  = self::time_to_minutes($booking_time);
		$start_m = self::time_to_minutes($start);
		$end_m   = self::time_to_minutes($end);

		if (null === $time_m || null === $start_m || null === $end_m || $end_m <= $start_m) {
			return true;
		}

		if ($time_m < $start_m || $time_m >= $end_m) {
			return false;
		}

		$duration = max(0, (int) $duration_minutes);
		$buffer   = max(0, (int) $buffer_minutes);

		if ($duration > 0 && ($time_m + $duration + $buffer) > $end_m) {
			return false;
		}

				if ('' !== $booking_date && self::is_time_blocked_for_date($booking_date, $booking_time, $duration, $buffer, $schedule)) {
						return false;
				}

		return true;
	}

		protected static function is_date_in_schedule_ranges($booking_date, $ranges) {
				$booking_date = (string) $booking_date;
				$ranges       = is_array($ranges) ? $ranges : array();

				foreach ($ranges as $range) {
						$start = isset($range['start']) ? (string) $range['start'] : '';
						$end   = isset($range['end']) ? (string) $range['end'] : '';

						if ('' === $start || '' === $end) {
								continue;
						}

						if ($booking_date >= $start && $booking_date <= $end) {
								return true;
						}
				}

				return false;
		}

		protected static function is_time_blocked_for_date($booking_date, $booking_time, $duration_minutes, $buffer_minutes, $schedule) {
				$booking_date  = (string) $booking_date;
				$booking_start = self::time_to_minutes($booking_time);
				$booking_end   = null === $booking_start ? null : $booking_start + max(0, (int) $duration_minutes) + max(0, (int) $buffer_minutes);
				$blocked_slots = isset($schedule['blockedSlots']) && is_array($schedule['blockedSlots']) ? $schedule['blockedSlots'] : array();

				if (null === $booking_start || null === $booking_end) {
						return false;
				}

				foreach ($blocked_slots as $slot) {
						$date  = isset($slot['date']) ? (string) $slot['date'] : '';
						$start = isset($slot['start']) ? self::time_to_minutes($slot['start']) : null;
						$end   = isset($slot['end']) ? self::time_to_minutes($slot['end']) : null;

						if ($booking_date !== $date || null === $start || null === $end || $end <= $start) {
								continue;
						}

						if ($booking_start < $end && $booking_end > $start) {
								return true;
						}
				}

				return false;
		}

	protected static function time_to_minutes($booking_time) {
		$booking_time = trim((string) $booking_time);

		if (! preg_match('/^(\d{2}):(\d{2})$/', $booking_time, $matches)) {
			return null;
		}

		return ((int) $matches[1] * 60) + (int) $matches[2];
	}

	/**
	 * Build the booking finance quote through external finance integrations.
	 *
	 * @param array<string, mixed> $context Booking context.
	 * @return array<string, mixed>
	 */
	protected static function get_booking_finance_quote($context) {
		$default_quote = array(
			'base_amount'     => isset($context['base_price']) ? max(0, (float) $context['base_price']) : 0,
			'discount_amount' => 0,
			'gift_amount'     => 0,
			'wallet_amount'   => 0,
			'payable_amount'  => isset($context['base_price']) ? max(0, (float) $context['base_price']) : 0,
			'currency'        => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
			'price_label'     => isset($context['price_label']) ? (string) $context['price_label'] : '',
			'meta'            => array(),
		);

		$quote = apply_filters('luna_appointments_booking_finance_quote', $default_quote, is_array($context) ? $context : array());

		return self::normalize_booking_finance_quote($quote, $default_quote);
	}

	/**
	 * Normalize finance quotes to a safe structure.
	 *
	 * @param mixed                $quote Quote from external integrations.
	 * @param array<string, mixed> $defaults Fallback values.
	 * @return array<string, mixed>
	 */
	protected static function normalize_booking_finance_quote($quote, $defaults = array()) {
		$normalized = wp_parse_args(
			is_array($quote) ? $quote : array(),
			wp_parse_args(
				is_array($defaults) ? $defaults : array(),
				array(
					'base_amount'     => 0,
					'discount_amount' => 0,
					'gift_amount'     => 0,
					'wallet_amount'   => 0,
					'payable_amount'  => 0,
					'currency'        => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
					'price_label'     => '',
					'meta'            => array(),
				)
			)
		);

		$normalized['base_amount']     = round(max(0, (float) $normalized['base_amount']), 2);
		$normalized['discount_amount'] = round(max(0, (float) $normalized['discount_amount']), 2);
		$normalized['gift_amount']     = round(max(0, (float) $normalized['gift_amount']), 2);
		$normalized['wallet_amount']   = round(max(0, (float) $normalized['wallet_amount']), 2);
		$normalized['payable_amount']  = round(max(0, (float) $normalized['payable_amount']), 2);
		$normalized['currency']        = sanitize_text_field((string) $normalized['currency']);
		$normalized['price_label']     = trim((string) $normalized['price_label']);
		$normalized['meta']            = is_array($normalized['meta']) ? $normalized['meta'] : array();

		return $normalized;
	}

	/**
	 * Normalize allowed payment methods.
	 *
	 * @param string $payment_method Raw payment method.
	 * @return string
	 */
	protected static function normalize_payment_method($payment_method) {
		$payment_method = sanitize_key((string) $payment_method);
		$options        = self::get_payment_options();

		foreach ($options as $option) {
			if (isset($option['id']) && $payment_method === $option['id']) {
				return $payment_method;
			}
		}

		return ! empty($options[0]['id']) ? (string) $options[0]['id'] : 'bacs';
	}

	/**
	 * Return the public payment label.
	 *
	 * @param string $payment_method Payment method.
	 * @return string
	 */
	protected static function get_payment_label($payment_method) {
		if ('consultation' === sanitize_key((string) $payment_method)) {
			return __('بدون پرداخت؛ نیازمند مشاوره', 'luna-appointments');
		}
		$options = self::get_payment_options();

		foreach ($options as $option) {
			if (isset($option['id']) && $payment_method === $option['id']) {
				return isset($option['label']) ? (string) $option['label'] : $payment_method;
			}
		}

		return self::normalize_payment_label($payment_method, $payment_method);
	}

	/** Return a stable Persian label even when WooCommerce stores an English gateway title. */
	protected static function normalize_payment_label($payment_method, $label = '') {
		return Luna_Appointments_I18n::payment_method($payment_method, $label);
	}
}
