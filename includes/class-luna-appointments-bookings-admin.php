<?php
/**
 * Admin page for booking records.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Bookings_Admin {
	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	protected static $page_slug = 'luna-bookings';

	/**
	 * Return the admin page slug.
	 *
	 * @return string
	 */
	public static function get_page_slug() {
		return self::$page_slug;
	}

	/**
	 * Render the bookings admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if (! current_user_can('edit_theme_options')) {
			wp_die(esc_html__('You do not have permission to view bookings.', 'luna-appointments'));
		}

		self::handle_actions();

		$view       = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
		$booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;

		echo '<div class="wrap luna-bookings-admin">';
		echo '<h1>' . esc_html__('Luna Bookings', 'luna-appointments') . '</h1>';
		echo '<p>' . esc_html__('Manage booking records, review linked WooCommerce orders, and update booking states from one place.', 'luna-appointments') . '</p>';
		self::render_notices();

		if ('detail' === $view && $booking_id > 0) {
			$booking = class_exists('Luna_Appointments_Bookings_Table')
				? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id)
				: null;

			if (! is_array($booking)) {
				echo '<div class="notice notice-error"><p>' . esc_html__('The requested booking could not be found.', 'luna-appointments') . '</p></div>';
				self::render_list_screen();
			} else {
				self::render_detail_screen($booking);
			}
		} else {
			self::render_list_screen();
		}

		echo '</div>';
	}

	/**
	 * Handle admin actions like confirm, cancel, and status updates.
	 *
	 * @return void
	 */
	protected static function handle_actions() {
		$action = isset($_REQUEST['booking_action']) ? sanitize_key(wp_unslash($_REQUEST['booking_action'])) : '';

		if ('' === $action) {
			return;
		}
		if ('bulk' === $action) {
			self::handle_bulk_action();
			return;
		}

		$booking_id = isset($_REQUEST['booking_id']) ? (int) $_REQUEST['booking_id'] : 0;
		$nonce      = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';

		if ($booking_id <= 0 || ! wp_verify_nonce($nonce, 'luna_booking_action_' . $booking_id)) {
			wp_safe_redirect(
				self::get_admin_page_url(
					array(
						'notice'  => 'error',
						'message' => rawurlencode(__('Security check failed for the booking action.', 'luna-appointments')),
					)
				)
			);
			exit;
		}

		$booking = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id)
			: null;

		if (! is_array($booking)) {
			wp_safe_redirect(
				self::get_admin_page_url(
					array(
						'notice'  => 'error',
						'message' => rawurlencode(__('Booking not found.', 'luna-appointments')),
					)
				)
			);
			exit;
		}

		$result = self::perform_booking_action($action, $booking);
		$args   = array(
			'view'       => 'detail',
			'booking_id' => $booking_id,
		);

		if (is_wp_error($result)) {
			$args['notice']  = 'error';
			$args['message'] = rawurlencode($result->get_error_message());
		} else {
			$args['notice']  = 'success';
			$args['message'] = rawurlencode(isset($result['message']) ? (string) $result['message'] : __('Booking updated.', 'luna-appointments'));
		}

		wp_safe_redirect(self::get_admin_page_url($args));
		exit;
	}

	/**
	 * Perform a booking admin action.
	 *
	 * @param string              $action Booking action.
	 * @param array<string,mixed> $booking Booking row.
	 * @return array<string,string>|WP_Error
	 */
	protected static function perform_booking_action($action, $booking) {
		$allowed_statuses = self::get_allowed_statuses();
		$target_status    = '';
		$payment_status   = isset($booking['payment_status']) ? (string) $booking['payment_status'] : '';
		$note             = '';

		switch ($action) {
			case 'confirm':
				$target_status = 'confirmed';
				$note          = __('Booking confirmed from Luna admin.', 'luna-appointments');
				break;

			case 'cancel':
				$target_status = 'cancelled';
				if (! in_array($payment_status, array('paid', 'refunded'), true)) {
					$payment_status = 'cancelled';
				}
				$note = __('Booking cancelled from Luna admin.', 'luna-appointments');
				break;
			case 'complete':
				$target_status = 'completed';
				$note = __('رزرو با عملیات مدیریت تکمیل شد.', 'luna-appointments');
				break;
			case 'mark_failed':
				$target_status = 'failed';
				$payment_status = 'failed';
				$note = __('رزرو توسط مدیریت ناموفق علامت‌گذاری شد.', 'luna-appointments');
				break;

			case 'update_status':
				$requested_status = isset($_REQUEST['target_status']) ? sanitize_key(wp_unslash($_REQUEST['target_status'])) : '';

				if (! isset($allowed_statuses[ $requested_status ])) {
					return new WP_Error('invalid_status', __('The requested booking status is not allowed.', 'luna-appointments'));
				}

				$target_status = $requested_status;

				if ('cancelled' === $target_status && ! in_array($payment_status, array('paid', 'refunded'), true)) {
					$payment_status = 'cancelled';
				}

				if ('failed' === $target_status) {
					$payment_status = 'failed';
				}

				$note = sprintf(
					/* translators: %s: booking status */
					__('Booking status changed manually to %s.', 'luna-appointments'),
					self::format_status_label($target_status)
				);
				break;

			case 'save_admin_note':
				$admin_note = isset($_REQUEST['admin_note']) ? sanitize_textarea_field(wp_unslash($_REQUEST['admin_note'])) : '';
				$updated    = class_exists('Luna_Appointments_Bookings_Table')
					? Luna_Appointments_Bookings_Table::update_booking(
						(int) $booking['id'],
						array(
							'admin_note' => $admin_note,
						)
					)
					: false;

				if (! $updated) {
					return new WP_Error('admin_note_failed', __('The admin note could not be updated.', 'luna-appointments'));
				}

				return array(
					'message' => __('Admin note updated.', 'luna-appointments'),
				);

			default:
				return new WP_Error('invalid_action', __('The requested booking action is not supported.', 'luna-appointments'));
		}

		if ('' === $target_status) {
			return new WP_Error('missing_status', __('No target booking status was resolved.', 'luna-appointments'));
		}

		if ('cancelled' === $target_status && ! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $booking['wc_order_id']);

			if ($order instanceof WC_Order && ! in_array($order->get_status(), array('cancelled', 'completed', 'refunded', 'failed'), true)) {
				$order->update_status('cancelled', __('Cancelled from Luna Bookings admin.', 'luna-appointments'), true);
			}
		}

		$updated = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::update_booking(
				(int) $booking['id'],
				array(
					'status'         => $target_status,
					'payment_status' => $payment_status,
					'notes'          => self::append_booking_note(isset($booking['notes']) ? (string) $booking['notes'] : '', $note),
				)
			)
			: false;

		if (! $updated) {
			return new WP_Error('booking_update_failed', __('The booking could not be updated.', 'luna-appointments'));
		}
		if (class_exists('Luna_Appointments_Bookings')) {
			Luna_Appointments_Bookings::maybe_trigger_booking_status_transition((int) $booking['id'], $booking, array('status' => $target_status, 'payment_status' => $payment_status), 'admin_panel');
			Luna_Appointments_Bookings::upsert_booking_post_from_row_id((int) $booking['id']);
		}

		return array(
			'message' => sprintf(
				/* translators: %s: booking status */
				__('Booking updated to %s.', 'luna-appointments'),
				self::format_status_label($target_status)
			),
		);
	}

	protected static function handle_bulk_action() {
		check_admin_referer('luna_booking_bulk_action');
		if (! current_user_can('edit_theme_options')) wp_die(esc_html__('دسترسی غیرمجاز است.', 'luna-appointments'));
		$ids = isset($_POST['booking_ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['booking_ids']))))) : array();
		$bulk = isset($_POST['bulk_action_name']) ? sanitize_key(wp_unslash($_POST['bulk_action_name'])) : '';
		$map = array('confirm' => 'confirm', 'cancel' => 'cancel', 'complete' => 'complete', 'failed' => 'mark_failed');
		if (! $ids || ! isset($map[ $bulk ])) {
			wp_safe_redirect(self::get_admin_page_url(array('notice' => 'error', 'message' => rawurlencode(__('عملیات گروهی یا رزروها معتبر نیستند.', 'luna-appointments')))));
			exit;
		}
		$success = 0;
		$failed = 0;
		foreach ($ids as $id) {
			$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($id);
			if (! is_array($booking) || is_wp_error(self::perform_booking_action($map[ $bulk ], $booking))) $failed++; else $success++;
		}
		$message = sprintf(__('عملیات گروهی انجام شد: %1$d موفق و %2$d ناموفق.', 'luna-appointments'), $success, $failed);
		wp_safe_redirect(self::get_admin_page_url(array('notice' => $failed ? 'error' : 'success', 'message' => rawurlencode($message))));
		exit;
	}

	/**
	 * Render admin notices after redirects.
	 *
	 * @return void
	 */
	protected static function render_notices() {
		$notice  = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';
		$message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

		if ('' === $message) {
			return;
		}

		$class = 'success' === $notice ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
	}

	/**
	 * Render the list screen.
	 *
	 * @return void
	 */
	protected static function render_list_screen() {
		$status         = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
		$payment_status = isset($_GET['payment_status']) ? sanitize_key(wp_unslash($_GET['payment_status'])) : '';
		$has_order      = isset($_GET['has_order']) ? sanitize_key(wp_unslash($_GET['has_order'])) : '';
		$service_id     = isset($_GET['service_id']) ? absint(wp_unslash($_GET['service_id'])) : 0;
		$specialist_id  = isset($_GET['specialist_id']) ? absint(wp_unslash($_GET['specialist_id'])) : 0;
		$payment_method = isset($_GET['payment_method']) ? sanitize_key(wp_unslash($_GET['payment_method'])) : '';
		$from_date      = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '';
		$to_date        = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '';
		$payment_error  = isset($_GET['payment_error']) ? sanitize_key(wp_unslash($_GET['payment_error'])) : '';
		$search         = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$paged          = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
		$per_page       = 20;
		$filters        = array(
			'status'         => $status,
			'payment_status' => $payment_status,
			'has_order'      => $has_order,
			'service_id'     => $service_id,
			'specialist_id'  => $specialist_id,
			'payment_method' => $payment_method,
			'from_date'      => $from_date,
			'to_date'        => $to_date,
			'payment_error'  => $payment_error,
			'search'         => $search,
			'paged'          => $paged,
			'per_page'       => $per_page,
		);
		$results        = class_exists('Luna_Appointments_Bookings_Table')
			? Luna_Appointments_Bookings_Table::query_bookings($filters)
			: array(
				'items' => array(),
				'total' => 0,
			);
		$items          = isset($results['items']) && is_array($results['items']) ? $results['items'] : array();
		$total          = isset($results['total']) ? (int) $results['total'] : 0;
		$pages          = max(1, (int) ceil($total / $per_page));
		$status_counts  = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_status_counts() : array();
		$payment_counts = class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_payment_status_counts() : array();

		self::render_overview_cards($status_counts, $payment_counts, $total, $filters);
		self::render_filters($filters, $status_counts, $payment_counts);
		self::render_table($items, $filters);
		self::render_pagination($paged, $pages, $filters);
	}

	/**
	 * Render the detail screen for one booking.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @return void
	 */
	protected static function render_detail_screen($booking) {
		$status_label         = self::format_status_label(isset($booking['status']) ? (string) $booking['status'] : '');
		$payment_status_label = self::format_status_label(isset($booking['payment_status']) ? (string) $booking['payment_status'] : '');
		$payment_title        = isset($booking['wc_payment_title']) && '' !== (string) $booking['wc_payment_title']
			? (string) $booking['wc_payment_title']
			: self::format_payment_label(isset($booking['payment_method']) ? (string) $booking['payment_method'] : '');
		$order_number         = isset($booking['wc_order_number']) && '' !== (string) $booking['wc_order_number']
			? (string) $booking['wc_order_number']
			: (! empty($booking['wc_order_id']) ? (string) $booking['wc_order_id'] : '');
		$order_status_label   = isset($booking['wc_order_status_label']) && '' !== (string) $booking['wc_order_status_label']
			? (string) $booking['wc_order_status_label']
			: __('Not linked', 'luna-appointments');

		echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:18px 0 24px;">';
		echo '<div>';
		echo '<a href="' . esc_url(self::get_admin_page_url()) . '" class="button" style="margin-bottom:12px;">' . esc_html__('← Back to bookings', 'luna-appointments') . '</a>';
		echo '<h2 style="margin:0 0 8px;">' . esc_html(sprintf(__('Booking %s', 'luna-appointments'), isset($booking['booking_code']) ? (string) $booking['booking_code'] : '#')) . '</h2>';
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
		echo self::render_status_badge($status_label, '#0f172a', '#e2e8f0');
		echo self::render_status_badge($payment_status_label, '#7c2d12', '#ffedd5');
		echo '</div>';
		echo '</div>';
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">';
		echo '<a class="button button-primary" href="' . esc_url(self::get_action_url('confirm', (int) $booking['id'], array('view' => 'detail', 'booking_id' => (int) $booking['id']))) . '">' . esc_html__('Confirm Booking', 'luna-appointments') . '</a>';
		echo '<a class="button" href="' . esc_url(self::get_action_url('cancel', (int) $booking['id'], array('view' => 'detail', 'booking_id' => (int) $booking['id']))) . '">' . esc_html__('Cancel Booking', 'luna-appointments') . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url(self::get_admin_page_url()) . '" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 24px;padding:16px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::$page_slug) . '">';
		echo '<input type="hidden" name="view" value="detail">';
		echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking['id']) . '">';
		echo '<input type="hidden" name="booking_action" value="update_status">';
		wp_nonce_field('luna_booking_action_' . (int) $booking['id']);
		echo '<label for="target_status"><strong>' . esc_html__('Change status', 'luna-appointments') . '</strong></label>';
		echo '<select name="target_status" id="target_status" style="min-width:220px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:10px;">';
		foreach (self::get_allowed_statuses() as $value => $label) {
			echo '<option value="' . esc_attr($value) . '"' . selected(isset($booking['status']) ? (string) $booking['status'] : '', $value, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button button-primary">' . esc_html__('Update Status', 'luna-appointments') . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url(self::get_admin_page_url()) . '" style="margin:0 0 24px;padding:16px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::$page_slug) . '">';
		echo '<input type="hidden" name="view" value="detail">';
		echo '<input type="hidden" name="booking_id" value="' . esc_attr((string) $booking['id']) . '">';
		echo '<input type="hidden" name="booking_action" value="save_admin_note">';
		wp_nonce_field('luna_booking_action_' . (int) $booking['id']);
		echo '<label for="admin_note" style="display:block;margin-bottom:10px;"><strong>' . esc_html__('Admin Note', 'luna-appointments') . '</strong></label>';
		echo '<textarea name="admin_note" id="admin_note" rows="4" style="width:100%;max-width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px;resize:vertical;">' . esc_textarea(isset($booking['admin_note']) ? (string) $booking['admin_note'] : '') . '</textarea>';
		echo '<div style="margin-top:12px;"><button type="submit" class="button button-secondary">' . esc_html__('Save Admin Note', 'luna-appointments') . '</button></div>';
		echo '</form>';

		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">';
		self::render_detail_card(
			__('Booking Summary', 'luna-appointments'),
			array(
				__('Booking Code', 'luna-appointments') => isset($booking['booking_code']) ? (string) $booking['booking_code'] : '-',
				__('Service', 'luna-appointments')      => isset($booking['service_name']) && '' !== (string) $booking['service_name'] ? (string) $booking['service_name'] : '-',
				__('Specialist', 'luna-appointments')   => isset($booking['specialist_name']) && '' !== (string) $booking['specialist_name'] ? (string) $booking['specialist_name'] : '-',
				__('Source', 'luna-appointments')       => isset($booking['source']) ? (string) $booking['source'] : '-',
				__('Created', 'luna-appointments')      => self::format_created_label(isset($booking['created_at']) ? (string) $booking['created_at'] : ''),
			)
		);
		self::render_detail_card(
			__('Customer', 'luna-appointments'),
			array(
				__('Name', 'luna-appointments')  => isset($booking['customer_name']) ? (string) $booking['customer_name'] : '-',
				__('Phone', 'luna-appointments') => isset($booking['customer_phone']) ? (string) $booking['customer_phone'] : '-',
				__('Email', 'luna-appointments') => ! empty($booking['customer_email']) ? (string) $booking['customer_email'] : '-',
			)
		);
		self::render_detail_card(
			__('Appointment', 'luna-appointments'),
			array(
				__('Date & Time', 'luna-appointments') => self::format_date_label(isset($booking['booking_date']) ? (string) $booking['booking_date'] : '', isset($booking['booking_time']) ? (string) $booking['booking_time'] : ''),
				__('Duration', 'luna-appointments')    => ! empty($booking['duration_minutes']) ? sprintf(__('%d minutes', 'luna-appointments'), (int) $booking['duration_minutes']) : '-',
				__('Buffer', 'luna-appointments')      => isset($booking['buffer_minutes']) ? sprintf(__('%d minutes', 'luna-appointments'), (int) $booking['buffer_minutes']) : '-',
				__('Price', 'luna-appointments')       => isset($booking['price_label']) && '' !== (string) $booking['price_label'] ? (string) $booking['price_label'] : __('Consultation required', 'luna-appointments'),
			)
		);

		$order_value = '-';
		if ('' !== $order_number) {
			$order_value = '' !== (string) ($booking['wc_order_edit_url'] ?? '')
				? '<a href="' . esc_url((string) $booking['wc_order_edit_url']) . '">#' . esc_html($order_number) . '</a>'
				: '#' . esc_html($order_number);
		}

		self::render_detail_card(
			__('Payment & Order', 'luna-appointments'),
			array(
				__('Payment Method', 'luna-appointments') => $payment_title,
				__('Payment Status', 'luna-appointments') => $payment_status_label,
				__('Woo Order', 'luna-appointments')      => $order_value,
				__('Woo Status', 'luna-appointments')     => $order_status_label,
				__('Order Total', 'luna-appointments')    => isset($booking['wc_order_total']) && '' !== (string) $booking['wc_order_total'] ? (string) $booking['wc_order_total'] : '-',
				__('Order Created', 'luna-appointments')  => isset($booking['wc_order_created']) && '' !== (string) $booking['wc_order_created'] ? (string) $booking['wc_order_created'] : '-',
				__('خطای پرداخت', 'luna-appointments')    => ! empty($booking['payment_error']) ? (string) $booking['payment_error'] : '-',
			)
		);
		echo '</div>';

		echo '<div style="margin-top:18px;padding:18px 20px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,0.04);">';
		echo '<h3 style="margin-top:0;">' . esc_html__('Activity Log', 'luna-appointments') . '</h3>';
		echo '<p style="margin:0;color:#475569;line-height:1.9;white-space:pre-line;">' . esc_html(! empty($booking['notes']) ? (string) $booking['notes'] : __('No notes have been recorded for this booking yet.', 'luna-appointments')) . '</p>';
		echo '</div>';

		$history = Luna_Appointments_Bookings_Table::get_booking_history((int) $booking['id']);
		echo '<div style="margin-top:18px;padding:18px 20px;border:1px solid #dce7d4;border-radius:16px;background:#fbfdf9"><h3 style="margin-top:0">' . esc_html__('تاریخچه تغییرات', 'luna-appointments') . '</h3>';
		if (! $history) {
			echo '<p>' . esc_html__('برای تغییرات قدیمی هنوز تاریخچه ساخت‌یافته‌ای ثبت نشده است.', 'luna-appointments') . '</p>';
		} else {
			echo '<ol style="margin:0;padding-right:20px">';
			foreach ($history as $event) {
				$changes = json_decode((string) ($event['changes_json'] ?? ''), true);
				$summary = array();
				foreach (is_array($changes) ? $changes : array() as $field => $values) {
					$summary[] = $field . ': ' . (string) ($values['from'] ?? '—') . ' ← ' . (string) ($values['to'] ?? '—');
				}
				echo '<li style="padding:9px 4px;border-bottom:1px solid #e7eee2"><strong>' . esc_html((string) ($event['event_type'] ?? 'updated')) . '</strong> <span style="color:#64748b">— ' . esc_html((string) ($event['actor_name'] ?: __('سیستم', 'luna-appointments'))) . '، ' . esc_html(self::format_created_label((string) ($event['created_at'] ?? ''))) . '</span>';
				if ($summary) echo '<small style="display:block;margin-top:4px;color:#475569">' . esc_html(implode(' | ', $summary)) . '</small>';
				echo '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';
	}

	/**
	 * Render booking overview cards.
	 *
	 * @param array<string,int> $status_counts Status counts.
	 * @param array<string,int> $payment_counts Payment status counts.
	 * @param int               $total Current filtered total.
	 * @return void
	 */
	protected static function render_overview_cards($status_counts, $payment_counts, $total, $filters = array()) {
		$all_count = array_sum(array_map('intval', $status_counts));
		$report = Luna_Appointments_Bookings_Table::get_report_summary($filters);
		$cards     = array(
			array(
				'label' => __('All Bookings', 'luna-appointments'),
				'value' => $all_count,
			),
			array(
				'label' => Luna_Appointments_I18n::booking_status('pending_payment'),
				'value' => isset($status_counts['pending_payment']) ? (int) $status_counts['pending_payment'] : 0,
			),
			array(
				'label' => __('Confirmed', 'luna-appointments'),
				'value' => isset($status_counts['confirmed']) ? (int) $status_counts['confirmed'] : 0,
			),
			array(
				'label' => __('Paid', 'luna-appointments'),
				'value' => isset($payment_counts['paid']) ? (int) $payment_counts['paid'] : 0,
			),
			array(
				'label' => __('Filtered Results', 'luna-appointments'),
				'value' => $total,
			),
			array(
				'label' => __('پرداخت ناموفق', 'luna-appointments'),
				'value' => (int) ($report['failed_payments'] ?? 0),
			),
		);

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin:20px 0 14px;">';
		foreach ($cards as $card) {
			echo '<div style="padding:18px 20px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,0.04);">';
			echo '<strong style="display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:10px;">' . esc_html($card['label']) . '</strong>';
			echo '<span style="display:block;font-size:30px;line-height:1;font-weight:700;color:#0f172a;">' . esc_html(number_format_i18n((int) $card['value'])) . '</span>';
			echo '</div>';
		}
		echo '</div>';
		echo '<div style="margin:0 0 24px;padding:14px 18px;border-radius:14px;background:#eef5e8;color:#33452b"><strong>' . esc_html__('ارزش رزروهای پرداخت‌شده در گزارش فعلی:', 'luna-appointments') . '</strong> ' . esc_html(function_exists('wc_price') ? wp_strip_all_tags(wc_price((float) ($report['paid_value'] ?? 0))) : number_format_i18n((float) ($report['paid_value'] ?? 0))) . '</div>';
	}

	/**
	 * Render filters and search.
	 *
	 * @param array<string,mixed> $filters Current filters.
	 * @param array<string,int>   $status_counts Status counts.
	 * @param array<string,int>   $payment_counts Payment status counts.
	 * @return void
	 */
	protected static function render_filters($filters, $status_counts, $payment_counts) {
		$status         = isset($filters['status']) ? (string) $filters['status'] : '';
		$payment_status = isset($filters['payment_status']) ? (string) $filters['payment_status'] : '';
		$has_order      = isset($filters['has_order']) ? (string) $filters['has_order'] : '';
		$search         = isset($filters['search']) ? (string) $filters['search'] : '';
		$service_id     = (int) ($filters['service_id'] ?? 0);
		$specialist_id  = (int) ($filters['specialist_id'] ?? 0);
		$payment_method = (string) ($filters['payment_method'] ?? '');
		$from_date      = (string) ($filters['from_date'] ?? '');
		$to_date        = (string) ($filters['to_date'] ?? '');
		$payment_error  = (string) ($filters['payment_error'] ?? '');
		$tabs           = array(
			''                => __('All', 'luna-appointments'),
			'pending_payment' => Luna_Appointments_I18n::booking_status('pending_payment'),
			'consultation_pending' => __('در انتظار مشاوره', 'luna-appointments'),
			'confirmed'       => __('Confirmed', 'luna-appointments'),
			'cancelled'       => __('Cancelled', 'luna-appointments'),
		);

		echo '<div style="margin:0 0 16px;">';
		foreach ($tabs as $value => $label) {
			$url   = self::get_admin_page_url(
				array_filter(
					array(
						'status'         => $value,
						'payment_status' => $payment_status,
						'has_order'      => $has_order,
						's'              => $search,
						'service_id'     => $service_id,
						'specialist_id'  => $specialist_id,
						'payment_method' => $payment_method,
						'from_date'      => $from_date,
						'to_date'        => $to_date,
						'payment_error'  => $payment_error,
					),
					static function ($item) {
						return '' !== (string) $item;
					}
				)
			);
			$count = '' === $value ? array_sum(array_map('intval', $status_counts)) : (isset($status_counts[ $value ]) ? (int) $status_counts[ $value ] : 0);
			$style = $status === $value ? 'background:#0f172a;color:#fff;border-color:#0f172a;' : 'background:#fff;color:#334155;border-color:#cbd5e1;';

			echo '<a href="' . esc_url($url) . '" style="display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border:1px solid;border-radius:999px;text-decoration:none;margin:0 8px 10px 0;' . esc_attr($style) . '">';
			echo '<span>' . esc_html($label) . '</span>';
			echo '<strong style="font-size:12px;">' . esc_html(number_format_i18n($count)) . '</strong>';
			echo '</a>';
		}
		echo '</div>';

		echo '<form method="get" action="' . esc_url(self::get_admin_page_url()) . '" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 20px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::$page_slug) . '">';
		if ('' !== $status) {
			echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
		}
		echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search by booking code, order number, customer, phone, or email', 'luna-appointments') . '" style="width:340px;max-width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:12px;">';
		echo '<select name="payment_status" style="min-width:180px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:12px;">';
		echo '<option value="">' . esc_html__('All payment states', 'luna-appointments') . '</option>';
		foreach ($payment_counts as $value => $count) {
			echo '<option value="' . esc_attr($value) . '"' . selected($payment_status, $value, false) . '>' . esc_html(self::format_status_label($value) . ' (' . number_format_i18n((int) $count) . ')') . '</option>';
		}
		echo '</select>';
		$services = get_posts(array('post_type' => 'service', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
		echo '<select name="service_id" style="min-width:170px"><option value="">' . esc_html__('همه خدمات', 'luna-appointments') . '</option>';
		foreach ($services as $service) echo '<option value="' . esc_attr((string) $service->ID) . '"' . selected($service_id, $service->ID, false) . '>' . esc_html($service->post_title) . '</option>';
		echo '</select>';
		$specialists = get_posts(array('post_type' => 'specialist', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
		echo '<select name="specialist_id" style="min-width:170px"><option value="">' . esc_html__('همه متخصصان', 'luna-appointments') . '</option>';
		foreach ($specialists as $specialist) echo '<option value="' . esc_attr((string) $specialist->ID) . '"' . selected($specialist_id, $specialist->ID, false) . '>' . esc_html($specialist->post_title) . '</option>';
		echo '</select>';
		echo '<select name="payment_method"><option value="">' . esc_html__('همه روش‌های پرداخت', 'luna-appointments') . '</option>';
		foreach (array('online' => __('آنلاین', 'luna-appointments'), 'cod' => __('پرداخت در محل', 'luna-appointments'), 'consultation' => __('بدون پرداخت؛ مشاوره', 'luna-appointments')) as $key => $label) echo '<option value="' . esc_attr($key) . '"' . selected($payment_method, $key, false) . '>' . esc_html($label) . '</option>';
		echo '</select>';
		echo '<input type="date" name="from_date" value="' . esc_attr($from_date) . '" title="' . esc_attr__('از تاریخ میلادی', 'luna-appointments') . '">';
		echo '<input type="date" name="to_date" value="' . esc_attr($to_date) . '" title="' . esc_attr__('تا تاریخ میلادی', 'luna-appointments') . '">';
		echo '<label><input type="checkbox" name="payment_error" value="yes"' . checked($payment_error, 'yes', false) . '> ' . esc_html__('فقط خطاهای پرداخت', 'luna-appointments') . '</label>';
		echo '<select name="has_order" style="min-width:180px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:12px;">';
		echo '<option value="">' . esc_html__('All order states', 'luna-appointments') . '</option>';
		echo '<option value="linked"' . selected($has_order, 'linked', false) . '>' . esc_html__('With Woo order', 'luna-appointments') . '</option>';
		echo '<option value="unlinked"' . selected($has_order, 'unlinked', false) . '>' . esc_html__('Without Woo order', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '<button type="submit" class="button button-primary">' . esc_html__('Apply Filters', 'luna-appointments') . '</button>';
		echo '<a class="button" href="' . esc_url(self::get_admin_page_url()) . '">' . esc_html__('Reset', 'luna-appointments') . '</a>';
		echo '</form>';
	}

	/**
	 * Render the bookings table.
	 *
	 * @param array<int,array<string,mixed>> $items Booking rows.
	 * @param array<string,mixed>            $filters Current filters.
	 * @return void
	 */
	protected static function render_table($items, $filters) {
		echo '<form method="post" action="' . esc_url(self::get_admin_page_url()) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::$page_slug) . '"><input type="hidden" name="booking_action" value="bulk">';
		wp_nonce_field('luna_booking_bulk_action');
		echo '<div style="display:flex;gap:10px;align-items:center;margin:0 0 12px"><select name="bulk_action_name" required><option value="">' . esc_html__('عملیات گروهی', 'luna-appointments') . '</option><option value="confirm">' . esc_html__('تأیید', 'luna-appointments') . '</option><option value="complete">' . esc_html__('تکمیل‌شده', 'luna-appointments') . '</option><option value="cancel">' . esc_html__('لغو', 'luna-appointments') . '</option><option value="failed">' . esc_html__('ناموفق', 'luna-appointments') . '</option></select><button class="button button-primary" type="submit">' . esc_html__('اعمال', 'luna-appointments') . '</button></div>';
		echo '<div style="overflow:auto;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 14px 36px rgba(15,23,42,0.04);">';
		echo '<table class="widefat striped" style="border:0;margin:0;">';
		echo '<thead><tr>';
		echo '<th style="width:34px"><input type="checkbox" onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(el=>el.checked=this.checked)"></th>';
		echo '<th style="padding:14px 16px;">' . esc_html__('Booking', 'luna-appointments') . '</th>';
		echo '<th style="padding:14px 16px;">' . esc_html__('Customer', 'luna-appointments') . '</th>';
		echo '<th style="padding:14px 16px;">' . esc_html__('Appointment', 'luna-appointments') . '</th>';
		echo '<th style="padding:14px 16px;">' . esc_html__('Payment & Order', 'luna-appointments') . '</th>';
		echo '<th style="padding:14px 16px;">' . esc_html__('Created', 'luna-appointments') . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if (empty($items)) {
			echo '<tr><td colspan="6" style="padding:28px 16px;text-align:center;color:#64748b;">' . esc_html__('No bookings found for the current filters.', 'luna-appointments') . '</td></tr>';
		}

		foreach ($items as $item) {
			$status_label         = self::format_status_label(isset($item['status']) ? (string) $item['status'] : '');
			$payment_status_label = self::format_status_label(isset($item['payment_status']) ? (string) $item['payment_status'] : '');
			$service_name         = isset($item['service_name']) ? (string) $item['service_name'] : '-';
			$specialist_name      = isset($item['specialist_name']) ? (string) $item['specialist_name'] : '-';
			$price_label          = isset($item['price_label']) && '' !== (string) $item['price_label'] ? (string) $item['price_label'] : __('Consultation required', 'luna-appointments');
			$order_number         = isset($item['wc_order_number']) && '' !== (string) $item['wc_order_number']
				? (string) $item['wc_order_number']
				: (! empty($item['wc_order_id']) ? (string) $item['wc_order_id'] : '');
			$order_edit_url       = isset($item['wc_order_edit_url']) ? (string) $item['wc_order_edit_url'] : '';
			$order_status_label   = isset($item['wc_order_status_label']) && '' !== (string) $item['wc_order_status_label']
				? (string) $item['wc_order_status_label']
				: __('Not linked', 'luna-appointments');
			$payment_title        = isset($item['wc_payment_title']) && '' !== (string) $item['wc_payment_title']
				? (string) $item['wc_payment_title']
				: self::format_payment_label(isset($item['payment_method']) ? (string) $item['payment_method'] : '');
			$detail_url           = self::get_admin_page_url(
				array(
					'view'       => 'detail',
					'booking_id' => (int) $item['id'],
				)
			);

			echo '<tr>';
			echo '<td><input type="checkbox" name="booking_ids[]" value="' . esc_attr((string) $item['id']) . '"></td>';
			echo '<td style="padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:inherit;">' . esc_html(isset($item['booking_code']) ? (string) $item['booking_code'] : '-') . '</a></strong>';
			echo '<span style="display:block;color:#475569;margin-top:4px;">' . esc_html($service_name) . '</span>';
			echo '<small style="display:block;color:#94a3b8;margin-top:4px;">' . esc_html__('Status:', 'luna-appointments') . ' ' . esc_html($status_label) . '</small>';
			echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">';
			echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;">' . esc_html__('View', 'luna-appointments') . '</a>';
			echo '<a href="' . esc_url(self::get_action_url('confirm', (int) $item['id'], $filters)) . '" style="text-decoration:none;">' . esc_html__('Confirm', 'luna-appointments') . '</a>';
			echo '<a href="' . esc_url(self::get_action_url('cancel', (int) $item['id'], $filters)) . '" style="text-decoration:none;color:#b91c1c;">' . esc_html__('Cancel', 'luna-appointments') . '</a>';
			echo '</div>';
			echo '</td>';
			echo '<td style="padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;">' . esc_html(isset($item['customer_name']) ? (string) $item['customer_name'] : '-') . '</strong>';
			echo '<span style="display:block;color:#475569;margin-top:4px;">' . esc_html(isset($item['customer_phone']) ? (string) $item['customer_phone'] : '-') . '</span>';
			if (! empty($item['customer_email'])) {
				echo '<small style="display:block;color:#94a3b8;margin-top:4px;">' . esc_html((string) $item['customer_email']) . '</small>';
			}
			echo '</td>';
			echo '<td style="padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;">' . esc_html($specialist_name) . '</strong>';
			echo '<span style="display:block;color:#475569;margin-top:4px;">' . esc_html(self::format_date_label(isset($item['booking_date']) ? (string) $item['booking_date'] : '', isset($item['booking_time']) ? (string) $item['booking_time'] : '')) . '</span>';
			echo '<small style="display:block;color:#94a3b8;margin-top:4px;">' . esc_html($price_label) . '</small>';
			echo '</td>';
			echo '<td style="padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;">' . esc_html($payment_title) . '</strong>';
			echo '<span style="display:block;color:#475569;margin-top:4px;">' . esc_html__('Payment:', 'luna-appointments') . ' ' . esc_html($payment_status_label) . '</span>';
			if ('' !== $order_number) {
				echo '<span style="display:block;color:#475569;margin-top:4px;">' . esc_html__('Order:', 'luna-appointments') . ' ';
				if ('' !== $order_edit_url) {
					echo '<a href="' . esc_url($order_edit_url) . '" style="text-decoration:none;">#' . esc_html($order_number) . '</a>';
				} else {
					echo '#' . esc_html($order_number);
				}
				echo '</span>';
				echo '<small style="display:block;color:#94a3b8;margin-top:4px;">' . esc_html__('Woo Status:', 'luna-appointments') . ' ' . esc_html($order_status_label) . '</small>';
			} else {
				echo '<small style="display:block;color:#94a3b8;margin-top:4px;">' . esc_html__('Order not created yet.', 'luna-appointments') . '</small>';
			}
			if (! empty($item['payment_error'])) {
				echo '<div style="margin-top:8px;padding:8px 10px;border-radius:9px;background:#fff1f2;color:#9f1239;font-size:11px;max-width:320px"><strong>' . esc_html__('خطای پرداخت:', 'luna-appointments') . '</strong> ' . esc_html((string) $item['payment_error']) . '</div>';
			}
			echo '</td>';
			echo '<td style="padding:16px;">';
			echo '<span style="display:block;color:#475569;">' . esc_html(self::format_created_label(isset($item['created_at']) ? (string) $item['created_at'] : '')) . '</span>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Render pagination for the bookings table.
	 *
	 * @param int                 $paged Current page.
	 * @param int                 $pages Total pages.
	 * @param array<string,mixed> $filters Current filters.
	 * @return void
	 */
	protected static function render_pagination($paged, $pages, $filters) {
		if ($pages <= 1) {
			return;
		}

		echo '<div class="tablenav" style="margin-top:18px;">';
		echo '<div class="tablenav-pages" style="float:none;margin-left:auto;">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => self::get_admin_page_url(
						array(
							'status'         => isset($filters['status']) ? (string) $filters['status'] : '',
							'payment_status' => isset($filters['payment_status']) ? (string) $filters['payment_status'] : '',
							'has_order'      => isset($filters['has_order']) ? (string) $filters['has_order'] : '',
							's'              => isset($filters['search']) ? (string) $filters['search'] : '',
							'service_id'     => (int) ($filters['service_id'] ?? 0),
							'specialist_id'  => (int) ($filters['specialist_id'] ?? 0),
							'payment_method' => (string) ($filters['payment_method'] ?? ''),
							'from_date'      => (string) ($filters['from_date'] ?? ''),
							'to_date'        => (string) ($filters['to_date'] ?? ''),
							'payment_error'  => (string) ($filters['payment_error'] ?? ''),
							'paged'          => '%#%',
						)
					),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a reusable detail card.
	 *
	 * @param string               $title Card title.
	 * @param array<string,string> $rows Detail rows.
	 * @return void
	 */
	protected static function render_detail_card($title, $rows) {
		echo '<div style="padding:18px 20px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,0.04);">';
		echo '<h3 style="margin-top:0;margin-bottom:16px;">' . esc_html($title) . '</h3>';
		foreach ($rows as $label => $value) {
			echo '<div style="display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-top:1px solid #f1f5f9;">';
			echo '<strong style="color:#64748b;">' . esc_html($label) . '</strong>';
			echo '<span style="color:#0f172a;text-align:right;">' . wp_kses($value, array('a' => array('href' => array()))) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render a lightweight status badge.
	 *
	 * @param string $label Badge label.
	 * @param string $color Text color.
	 * @param string $background Background color.
	 * @return string
	 */
	protected static function render_status_badge($label, $color, $background) {
		return '<span style="display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;background:' . esc_attr($background) . ';color:' . esc_attr($color) . ';font-size:12px;font-weight:700;">' . esc_html($label) . '</span>';
	}

	/**
	 * Build a bookings admin URL.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return string
	 */
	protected static function get_admin_page_url($args = array()) {
		$args = array_filter(
			array_merge(
				array(
					'page' => self::$page_slug,
				),
				$args
			),
			static function ($value) {
				return '' !== (string) $value;
			}
		);

		return add_query_arg($args, admin_url('admin.php'));
	}

	/**
	 * Build a nonce-protected admin action URL.
	 *
	 * @param string              $action Booking action.
	 * @param int                 $booking_id Booking id.
	 * @param array<string,mixed> $args Additional query args.
	 * @return string
	 */
	protected static function get_action_url($action, $booking_id, $args = array()) {
		$url = self::get_admin_page_url(
			array_merge(
				$args,
				array(
					'booking_action' => $action,
					'booking_id'     => (int) $booking_id,
				)
			)
		);

		return wp_nonce_url($url, 'luna_booking_action_' . (int) $booking_id);
	}

	/**
	 * Append an admin note to the existing notes string.
	 *
	 * @param string $existing Existing note text.
	 * @param string $note New note.
	 * @return string
	 */
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

	/**
	 * Return allowed booking statuses.
	 *
	 * @return array<string,string>
	 */
	protected static function get_allowed_statuses() {
		return array(
			'pending'         => Luna_Appointments_I18n::booking_status('pending'),
			'pending_payment' => Luna_Appointments_I18n::booking_status('pending_payment'),
			'payment_review'  => Luna_Appointments_I18n::booking_status('payment_review'),
			'consultation_pending' => __('در انتظار مشاوره', 'luna-appointments'),
			'confirmed'       => Luna_Appointments_I18n::booking_status('confirmed'),
			'completed'       => Luna_Appointments_I18n::booking_status('completed'),
			'no_show'         => Luna_Appointments_I18n::booking_status('no_show'),
			'cancelled'       => Luna_Appointments_I18n::booking_status('cancelled'),
			'failed'          => Luna_Appointments_I18n::booking_status('failed'),
			'refunded'        => Luna_Appointments_I18n::booking_status('refunded'),
		);
	}

	/**
	 * Format status keys for display.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	protected static function format_status_label($status) {
		$payment_states = array('unpaid', 'paid', 'authorized', 'not_required', 'partial_refund', 'partially_refunded');
		return in_array(sanitize_key((string) $status), $payment_states, true)
			? Luna_Appointments_I18n::payment_status($status)
			: Luna_Appointments_I18n::booking_status($status);
	}

	/**
	 * Format payment labels for display.
	 *
	 * @param string $payment_method Payment method key.
	 * @return string
	 */
	protected static function format_payment_label($payment_method) {
		return Luna_Appointments_I18n::payment_method($payment_method);
	}

	/**
	 * Format the appointment date and time.
	 *
	 * @param string $booking_date Stored Gregorian date.
	 * @param string $booking_time Stored time.
	 * @return string
	 */
	protected static function format_date_label($booking_date, $booking_time) {
		if ('' === $booking_date) {
			return '-';
		}
		return class_exists('Luna_Appointments_Date')
			? Luna_Appointments_Date::format_jalali($booking_date, $booking_time, true)
			: trim($booking_date . ' ' . $booking_time);
	}

	/**
	 * Format created timestamp.
	 *
	 * @param string $created_at Raw created timestamp.
	 * @return string
	 */
	protected static function format_created_label($created_at) {
		if ('' === $created_at) {
			return '-';
		}

		return class_exists('Luna_Appointments_Date')
			? Luna_Appointments_Date::format_db_datetime_jalali($created_at)
			: $created_at;
	}
}
