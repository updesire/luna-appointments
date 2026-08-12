<?php
/**
 * Consultation upfront-fee, balance and immutable ledger domain.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Luna_Appointments_Consultation_Finance {
	const SCHEMA_VERSION = '1.0.0';
	const SCHEMA_OPTION  = 'luna_consultation_finance_schema';
	const MODE_FREE      = 'no_payment';
	const MODE_FEE       = 'upfront_fee';
	const SPECIALIST_NONCE = 'luna_specialist_consultation_proposal';
	const ACCOUNT_ENDPOINT = 'luna-finance';

	public static function boot() {
		add_action('init', array(__CLASS__, 'maybe_install'), 6);
		add_action('luna_appointments_service_finance_fields', array(__CLASS__, 'render_service_fields'), 10, 2);
		add_action('save_post_service', array(__CLASS__, 'save_service_fields'), 20);
		add_action('luna_appointments_booking_finance_committed', array(__CLASS__, 'record_order_created'), 20, 4);
		add_action('woocommerce_payment_complete', array(__CLASS__, 'sync_order'), 30);
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'sync_order_status'), 30, 4);
		add_action('woocommerce_order_refunded', array(__CLASS__, 'sync_refund'), 30, 2);
		add_action('luna_appointments_booking_status_transition', array(__CLASS__, 'handle_booking_transition'), 30, 8);
		add_action('add_meta_boxes_luna_booking', array(__CLASS__, 'register_booking_meta_box'));
		add_action('admin_post_luna_consultation_finance_action', array(__CLASS__, 'handle_admin_action'));
		add_action('wp_ajax_luna_specialist_consultation_proposal', array(__CLASS__, 'ajax_specialist_proposal'));
		add_action('init', array(__CLASS__, 'register_account_endpoint'), 20);
		add_filter('query_vars', array(__CLASS__, 'account_query_vars'));
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'account_menu_item'), 45);
		add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array(__CLASS__, 'render_customer_finance_page'));
		add_action('admin_init', array(__CLASS__, 'maybe_flush_account_endpoint'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
	}

	public static function specialist_nonce_action() {
		return self::SPECIALIST_NONCE;
	}

	public static function register_account_endpoint() {
		if (function_exists('add_rewrite_endpoint')) add_rewrite_endpoint(self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);
	}

	public static function account_query_vars($vars) {
		$vars[] = self::ACCOUNT_ENDPOINT;
		return $vars;
	}

	public static function maybe_flush_account_endpoint() {
		if (! current_user_can('manage_options') || '1' === (string) get_option('luna_finance_endpoint_flushed_v1', '')) return;
		flush_rewrite_rules(false);
		update_option('luna_finance_endpoint_flushed_v1', '1', false);
	}

	public static function account_menu_item($items) {
		if (! is_array($items)) return $items;
		$result = array();
		$added  = false;
		foreach ($items as $key => $label) {
			if ('customer-logout' === $key) {
				$result[self::ACCOUNT_ENDPOINT] = __('امور مالی رزروها', 'luna-appointments');
				$added = true;
			}
			$result[$key] = $label;
		}
		if (! $added) $result[self::ACCOUNT_ENDPOINT] = __('امور مالی رزروها', 'luna-appointments');
		return $result;
	}

	public static function enqueue_assets() {
		wp_enqueue_style('luna-consultation-finance', LUNA_APPOINTMENTS_URL . 'assets/consultation-finance.css', array(), LUNA_APPOINTMENTS_VERSION);
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'luna_booking_finance_ledger';
	}

	public static function maybe_install() {
		if (self::SCHEMA_VERSION !== (string) get_option(self::SCHEMA_OPTION, '')) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_key varchar(120) NOT NULL,
			entry_type varchar(40) NOT NULL,
			amount decimal(18,2) NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'posted',
			method varchar(40) NOT NULL DEFAULT '',
			note text NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_key (entry_key),
			KEY booking_id (booking_id),
			KEY order_id (order_id),
			KEY entry_type_status (entry_type,status)
		) {$charset};");
		if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
			update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
		}
	}

	public static function render_service_fields($post) {
		$plan = self::service_plan((int) $post->ID);
		?>
		<section class="luna-consultation-finance" style="padding:18px;border:1px solid #dfe5d5;border-radius:16px;background:#f8faf5;display:grid;gap:14px;">
			<div><strong><?php esc_html_e('پرداخت خدمت نیازمند مشاوره', 'luna-appointments'); ?></strong><p style="margin:5px 0 0;color:#66705b;"><?php esc_html_e('برای قیمت قطعی، هزینه اولیه مشاوره را اکنون دریافت و از مانده مراجعه حضوری کم کنید.', 'luna-appointments'); ?></p></div>
			<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;">
				<label><span style="display:block;font-weight:700;margin-bottom:6px;"><?php esc_html_e('مدل مالی مشاوره', 'luna-appointments'); ?></span><select name="luna_consultation_payment_mode" style="width:100%;"><option value="no_payment"<?php selected($plan['mode'], self::MODE_FREE); ?>><?php esc_html_e('بدون پرداخت اولیه', 'luna-appointments'); ?></option><option value="upfront_fee"<?php selected($plan['mode'], self::MODE_FEE); ?>><?php esc_html_e('دریافت هزینه اولیه مشاوره', 'luna-appointments'); ?></option></select></label>
				<label><span style="display:block;font-weight:700;margin-bottom:6px;"><?php esc_html_e('مبلغ هزینه اولیه', 'luna-appointments'); ?></span><input type="number" min="0" step="1000" name="luna_consultation_upfront_fee" value="<?php echo esc_attr((string) $plan['upfront_fee']); ?>" style="width:100%;"><small><?php esc_html_e('مبلغ خام، بدون جداکننده', 'luna-appointments'); ?></small></label>
			</div>
			<label><input type="checkbox" name="luna_consultation_deduct_fee" value="1"<?php checked($plan['deduct_fee']); ?>> <?php esc_html_e('هزینه اولیه از مبلغ نهایی خدمت کسر شود', 'luna-appointments'); ?></label>
			<label><span style="display:block;font-weight:700;margin-bottom:6px;"><?php esc_html_e('سیاست لغو هزینه اولیه', 'luna-appointments'); ?></span><select name="luna_consultation_refund_policy"><option value="manual"<?php selected($plan['refund_policy'], 'manual'); ?>><?php esc_html_e('بررسی دستی مدیر', 'luna-appointments'); ?></option><option value="refundable"<?php selected($plan['refund_policy'], 'refundable'); ?>><?php esc_html_e('قابل بازپرداخت', 'luna-appointments'); ?></option><option value="non_refundable"<?php selected($plan['refund_policy'], 'non_refundable'); ?>><?php esc_html_e('غیرقابل بازپرداخت', 'luna-appointments'); ?></option></select></label>
		</section>
		<?php
	}

	public static function save_service_fields($post_id) {
		if (! isset($_POST['luna_service_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_service_meta_nonce'])), 'luna_service_meta_box') || ! current_user_can('edit_post', $post_id)) {
			return;
		}
		$mode = isset($_POST['luna_consultation_payment_mode']) ? sanitize_key(wp_unslash($_POST['luna_consultation_payment_mode'])) : self::MODE_FREE;
		update_post_meta($post_id, '_luna_consultation_payment_mode', self::MODE_FEE === $mode ? self::MODE_FEE : self::MODE_FREE);
		update_post_meta($post_id, '_luna_consultation_upfront_fee', max(0, (int) ($_POST['luna_consultation_upfront_fee'] ?? 0)));
		update_post_meta($post_id, '_luna_consultation_deduct_fee', isset($_POST['luna_consultation_deduct_fee']) ? '1' : '');
		$policy = isset($_POST['luna_consultation_refund_policy']) ? sanitize_key(wp_unslash($_POST['luna_consultation_refund_policy'])) : 'manual';
		update_post_meta($post_id, '_luna_consultation_refund_policy', in_array($policy, array('manual', 'refundable', 'non_refundable'), true) ? $policy : 'manual');
	}

	public static function service_plan($service_id) {
		$service_id = (int) $service_id;
		$mode       = sanitize_key((string) get_post_meta($service_id, '_luna_consultation_payment_mode', true));
		$fee        = max(0, (float) get_post_meta($service_id, '_luna_consultation_upfront_fee', true));
		$requires   = '' !== (string) get_post_meta($service_id, '_luna_service_requires_consultation', true);
		if (! $requires || self::MODE_FEE !== $mode || $fee <= 0) {
			$mode = self::MODE_FREE;
			$fee  = 0;
		}
		$policy = sanitize_key((string) get_post_meta($service_id, '_luna_consultation_refund_policy', true));
		$deduct = ! metadata_exists('post', $service_id, '_luna_consultation_deduct_fee') || '1' === (string) get_post_meta($service_id, '_luna_consultation_deduct_fee', true);
		return array(
			'requires_consultation' => $requires,
			'mode'                  => $mode,
			'upfront_fee'           => round($fee, 2),
			'deduct_fee'            => $deduct,
			'refund_policy'         => in_array($policy, array('manual', 'refundable', 'non_refundable'), true) ? $policy : 'manual',
		);
	}

	public static function is_upfront_fee($service_id) {
		$plan = self::service_plan($service_id);
		return self::MODE_FEE === $plan['mode'] && $plan['upfront_fee'] > 0;
	}

	public static function initial_quote($service_id, $total_amount) {
		$plan  = self::service_plan($service_id);
		$calc  = self::calculate_amounts($total_amount, self::MODE_FEE === $plan['mode'] ? $plan['upfront_fee'] : 0, $plan['deduct_fee']);
		return array(
			'base_amount'     => $calc['total_amount'],
			'discount_amount' => 0,
			'gift_amount'     => 0,
			'wallet_amount'   => 0,
			'payable_amount'  => $calc['upfront_fee'],
			'price_label'     => __('هزینه اولیه مشاوره', 'luna-appointments'),
			'meta'            => array('consultation_finance' => array('mode' => $plan['mode'], 'total_amount' => $calc['total_amount'], 'upfront_fee' => $calc['upfront_fee'], 'balance_amount' => $calc['balance_amount'], 'deduct_fee' => $plan['deduct_fee'], 'refund_policy' => $plan['refund_policy'])),
		);
	}

	public static function calculate_amounts($total_amount, $upfront_fee, $deduct_fee = true) {
		$total = max(0, round((float) $total_amount, 2));
		$fee   = max(0, round((float) $upfront_fee, 2));
		if ($total > 0) $fee = min($total, $fee);
		return array('total_amount' => $total, 'upfront_fee' => $fee, 'balance_amount' => $deduct_fee ? max(0, $total - $fee) : $total);
	}

	public static function record_order_created($booking_id, $order_id, $quote, $context) {
		$meta = isset($quote['meta']['consultation_finance']) && is_array($quote['meta']['consultation_finance']) ? $quote['meta']['consultation_finance'] : array();
		if (empty($meta)) {
			return;
		}
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		if ($order instanceof WC_Order) {
			$order->update_meta_data('_luna_consultation_finance', 'yes');
			$order->update_meta_data('_luna_consultation_total_amount', (float) $meta['total_amount']);
			$order->update_meta_data('_luna_consultation_upfront_fee', (float) $meta['upfront_fee']);
			$order->update_meta_data('_luna_consultation_balance_amount', (float) $meta['balance_amount']);
			$order->update_meta_data('_luna_consultation_deduct_fee', ! empty($meta['deduct_fee']) ? 'yes' : 'no');
			$order->update_meta_data('_luna_consultation_refund_policy', (string) $meta['refund_policy']);
			$order->save();
		}
		self::add_entry($booking_id, $order_id, 'fee_due', (float) $meta['upfront_fee'], 'pending', (string) ($context['payment_method'] ?? ''), __('هزینه اولیه مشاوره ایجاد شد.', 'luna-appointments'), 'fee-due-' . $order_id);
	}

	public static function sync_order_status($order_id, $from, $to, $order) {
		unset($from, $to);
		self::sync_order($order_id, $order);
	}

	public static function sync_order($order_id, $order = false) {
		$order = $order instanceof WC_Order ? $order : (function_exists('wc_get_order') ? wc_get_order($order_id) : false);
		if (! $order instanceof WC_Order) {
			return;
		}
		$booking_id = (int) $order->get_meta('_luna_booking_id', true);
		if ($booking_id <= 0) {
			return;
		}
		if ('yes' === (string) $order->get_meta('_luna_consultation_balance_order', true) && $order->is_paid()) {
			$inserted = self::add_entry($booking_id, $order->get_id(), 'settlement', (float) $order->get_total(), 'posted', (string) $order->get_payment_method(), __('مانده رزرو آنلاین پرداخت شد.', 'luna-appointments'), 'settlement-' . $order->get_id());
			if ($inserted && self::summary($booking_id)['balance_amount'] <= 0) self::mark_settled($booking_id);
			return;
		}
		if ('yes' !== (string) $order->get_meta('_luna_consultation_finance', true)) return;
		if ($order->is_paid()) {
			$inserted = self::add_entry($booking_id, $order->get_id(), 'payment', (float) $order->get_total(), 'posted', (string) $order->get_payment_method(), __('هزینه اولیه مشاوره پرداخت شد.', 'luna-appointments'), 'payment-' . $order->get_id());
			$existing = Luna_Appointments_Bookings_Table::get_booking($booking_id);
			if (is_array($existing) && ! in_array((string) ($existing['status'] ?? ''), array('cancelled', 'refunded'), true)) {
				Luna_Appointments_Bookings_Table::update_booking($booking_id, array('status' => 'consultation_pending', 'payment_status' => 'deposit_paid'));
			}
			if ($inserted) self::notify($booking_id, 'deposit_paid');
		}
	}

	public static function sync_refund($order_id, $refund_id = 0) {
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		if (! $order instanceof WC_Order || 'yes' !== (string) $order->get_meta('_luna_consultation_finance', true)) {
			return;
		}
		$refund = $refund_id && function_exists('wc_get_order') ? wc_get_order($refund_id) : false;
		$amount = $refund instanceof WC_Order_Refund ? abs((float) $refund->get_total()) : (float) $order->get_total_refunded();
		self::add_entry((int) $order->get_meta('_luna_booking_id', true), $order_id, 'refund', -abs($amount), 'posted', 'woocommerce', __('بازپرداخت هزینه اولیه مشاوره.', 'luna-appointments'), 'refund-' . $refund_id);
	}

	public static function add_entry($booking_id, $order_id, $type, $amount, $status = 'posted', $method = '', $note = '', $entry_key = '') {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0) {
			return false;
		}
		$key = $entry_key ? sanitize_key($entry_key) : sanitize_key($type . '-' . $booking_id . '-' . wp_generate_uuid4());
		$result = $wpdb->query($wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (booking_id,order_id,entry_key,entry_type,amount,status,method,note,actor_user_id,created_at) VALUES (%d,%d,%s,%s,%f,%s,%s,%s,%d,%s)',
			$booking_id, (int) $order_id, $key, sanitize_key($type), round((float) $amount, 2), sanitize_key($status), sanitize_key($method), sanitize_textarea_field($note), get_current_user_id(), current_time('mysql', true)
		));
		return 1 === (int) $result;
	}

	public static function summary($booking_id) {
		global $wpdb;
		$booking = Luna_Appointments_Bookings_Table::get_booking((int) $booking_id);
		if (! is_array($booking)) {
			return array();
		}
		$order = ! empty($booking['wc_order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $booking['wc_order_id']) : false;
		$is_finance = $order instanceof WC_Order && 'yes' === (string) $order->get_meta('_luna_consultation_finance', true);
		if (! $is_finance) {
			return array();
		}
		$stored_total = (float) $order->get_meta('_luna_consultation_total_amount', true);
		$total        = max(0, $stored_total);
		if ($total <= 0 && ! empty($booking['base_price'])) {
			$total = max(0, (float) $booking['base_price']);
		}
		if ($total <= 0 && ! empty($booking['service_id'])) {
			$total = max(0, (float) get_post_meta((int) $booking['service_id'], '_luna_service_base_price', true));
		}
		$upfront_fee = max(0, (float) $order->get_meta('_luna_consultation_upfront_fee', true));
		if ($upfront_fee <= 0) {
			$upfront_fee = max(0, (float) $order->get_total());
		}
		if ($upfront_fee <= 0 && ! empty($booking['service_id'])) {
			$upfront_fee = max(0, (float) get_post_meta((int) $booking['service_id'], '_luna_consultation_upfront_fee', true));
		}
		// Safely repair historical orders created before the finance hook or a
		// payment-complete callback was available. Entry keys keep this idempotent.
		if ($stored_total <= 0 && $total > 0) {
			$order->update_meta_data('_luna_consultation_total_amount', $total);
			$order->save();
		}
		if ($order->is_paid()) {
			self::add_entry((int) $booking_id, (int) $order->get_id(), 'payment', (float) $order->get_total(), 'posted', (string) $order->get_payment_method(), __('بازیابی پرداخت هزینه اولیه از سفارش ووکامرس.', 'luna-appointments'), 'payment-' . $order->get_id());
		}
		$entries = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE booking_id=%d ORDER BY id ASC', (int) $booking_id), ARRAY_A);
		$paid = 0;
		$settlement_paid = 0;
		$balance_payment_url = '';
		foreach ((array) $entries as $entry) {
			if ('balance_due' === (string) $entry['entry_type'] && (int) $entry['order_id'] > 0 && function_exists('wc_get_order')) {
				$balance_order = wc_get_order((int) $entry['order_id']);
				if ($balance_order instanceof WC_Order && $balance_order->needs_payment()) $balance_payment_url = (string) $balance_order->get_checkout_payment_url(false);
			}
			if ('posted' !== (string) $entry['status']) continue;
			if (in_array((string) $entry['entry_type'], array('payment', 'settlement', 'adjustment', 'refund'), true)) $paid += (float) $entry['amount'];
			if (in_array((string) $entry['entry_type'], array('settlement', 'adjustment', 'refund'), true)) $settlement_paid += (float) $entry['amount'];
		}
		$deduct = 'no' !== (string) $order->get_meta('_luna_consultation_deduct_fee', true);
		$balance_paid = $deduct ? $paid : $settlement_paid;
		return array('total_amount' => $total, 'final_total_set' => $total > 0, 'upfront_fee' => $upfront_fee, 'initial_order_paid' => $order->is_paid(), 'initial_order_status' => (string) $order->get_status(), 'paid_amount' => max(0, $paid), 'balance_amount' => max(0, $total - max(0, $balance_paid)), 'deduct_fee' => $deduct, 'entries' => (array) $entries, 'order_id' => (int) $order->get_id(), 'balance_payment_url' => $balance_payment_url, 'refund_policy' => (string) $order->get_meta('_luna_consultation_refund_policy', true));
	}

	public static function frontend_summary_markup($booking_id, $compact = false, $show_payment = true) {
		$summary = self::summary((int) $booking_id);
		if (empty($summary)) return '';
		$class = $summary['balance_amount'] > 0 ? 'is-due' : 'is-settled';
		$html  = '<div class="luna-consultation-balance ' . esc_attr($class) . '">';
		if (! $compact) $html .= '<span>' . esc_html__('مبلغ نهایی', 'luna-appointments') . ': <b>' . wp_kses_post(wc_price($summary['total_amount'])) . '</b></span>';
		$html .= '<span>' . esc_html__('پرداخت‌شده', 'luna-appointments') . ': <b>' . wp_kses_post(wc_price($summary['paid_amount'])) . '</b></span><span>' . esc_html__('مانده', 'luna-appointments') . ': <b>' . wp_kses_post(wc_price($summary['balance_amount'])) . '</b></span></div>';
		if ($show_payment && ! empty($summary['balance_payment_url'])) $html .= '<a class="button luna-consultation-pay-balance" href="' . esc_url($summary['balance_payment_url']) . '">' . esc_html__('پرداخت آنلاین مانده', 'luna-appointments') . '</a>';
		return $html;
	}

	private static function proposal($summary) {
		$order = ! empty($summary['order_id']) && function_exists('wc_get_order') ? wc_get_order((int) $summary['order_id']) : false;
		if (! $order instanceof WC_Order) return array('amount' => 0, 'status' => '', 'note' => '', 'by' => 0, 'at' => '');
		return array(
			'amount' => (float) $order->get_meta('_luna_consultation_proposed_total', true),
			'status' => sanitize_key((string) $order->get_meta('_luna_consultation_proposal_status', true)),
			'note'   => (string) $order->get_meta('_luna_consultation_proposal_note', true),
			'by'     => (int) $order->get_meta('_luna_consultation_proposed_by', true),
			'at'     => (string) $order->get_meta('_luna_consultation_proposed_at', true),
		);
	}

	public static function specialist_proposal_markup($booking_id) {
		$summary = self::summary((int) $booking_id);
		if (empty($summary)) return '';
		$proposal = self::proposal($summary);
		$status_labels = array(
			'pending'  => __('منتظر تأیید مدیر', 'luna-appointments'),
			'approved' => __('تأییدشده', 'luna-appointments'),
			'rejected' => __('نیازمند اصلاح', 'luna-appointments'),
		);
		$html  = '<form class="lspwa-finance-proposal" data-finance-proposal data-booking-id="' . esc_attr((string) $booking_id) . '">';
		$html .= '<div><b>' . esc_html__('مبلغ نهایی پس از مشاوره', 'luna-appointments') . '</b>';
		if ($proposal['status']) $html .= '<i class="is-' . esc_attr($proposal['status']) . '">' . esc_html($status_labels[$proposal['status']] ?? $proposal['status']) . '</i>';
		$html .= '</div><label><span>' . esc_html__('مبلغ پیشنهادی', 'luna-appointments') . '</span><input type="number" name="amount" min="1" step="1000" value="' . esc_attr($proposal['amount'] > 0 ? (string) $proposal['amount'] : '') . '" required></label>';
		$html .= '<label><span>' . esc_html__('یادداشت مشاوره', 'luna-appointments') . '</span><textarea name="note" rows="2" maxlength="800" placeholder="' . esc_attr__('توضیح کوتاه برای مدیر', 'luna-appointments') . '">' . esc_textarea($proposal['note']) . '</textarea></label>';
		$html .= '<button type="submit">' . esc_html($proposal['status'] ? __('به‌روزرسانی پیشنهاد', 'luna-appointments') : __('ارسال برای تأیید مدیر', 'luna-appointments')) . '</button><p aria-live="polite"></p></form>';
		return $html;
	}

	public static function ajax_specialist_proposal() {
		check_ajax_referer(self::SPECIALIST_NONCE, 'nonce');
		if (! class_exists('Luna_Appointments_Specialists') || ! Luna_Appointments_Specialists::current_user_is_specialist()) wp_send_json_error(array('message' => __('دسترسی غیرمجاز است.', 'luna-appointments')), 403);
		$booking_id   = absint($_POST['booking_id'] ?? 0);
		$amount       = round(max(0, (float) ($_POST['amount'] ?? 0)), 2);
		$note         = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
		$specialist_id = Luna_Appointments_Specialists::get_current_user_specialist_id();
		$booking      = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (! is_array($booking) || (int) ($booking['specialist_id'] ?? 0) !== (int) $specialist_id) wp_send_json_error(array('message' => __('این رزرو متعلق به شما نیست.', 'luna-appointments')), 403);
		if ($amount <= 0) wp_send_json_error(array('message' => __('مبلغ پیشنهادی معتبر نیست.', 'luna-appointments')), 422);
		$summary = self::summary($booking_id);
		$order   = ! empty($summary['order_id']) ? wc_get_order((int) $summary['order_id']) : false;
		if (! $order instanceof WC_Order) wp_send_json_error(array('message' => __('پرونده مالی این رزرو پیدا نشد.', 'luna-appointments')), 404);
		$order->update_meta_data('_luna_consultation_proposed_total', $amount);
		$order->update_meta_data('_luna_consultation_proposal_status', 'pending');
		$order->update_meta_data('_luna_consultation_proposal_note', $note);
		$order->update_meta_data('_luna_consultation_proposed_by', get_current_user_id());
		$order->update_meta_data('_luna_consultation_proposed_at', current_time('mysql', true));
		$order->save();
		self::add_entry($booking_id, $order->get_id(), 'proposal', $amount, 'pending', 'specialist', __('مبلغ نهایی توسط متخصص پیشنهاد شد.', 'luna-appointments'));
		$order->add_order_note(sprintf(__('متخصص مبلغ نهایی %s را برای تأیید پیشنهاد کرد.', 'luna-appointments'), wp_strip_all_tags(wc_price($amount))));
		self::notify_manager_of_proposal($booking_id, $amount);
		wp_send_json_success(array('message' => __('پیشنهاد مبلغ برای تأیید مدیر ارسال شد.', 'luna-appointments'), 'html' => self::specialist_proposal_markup($booking_id)));
	}

	public static function register_booking_meta_box() {
		add_meta_box('luna_consultation_finance', __('مالی مشاوره و مانده', 'luna-appointments'), array(__CLASS__, 'render_booking_meta_box'), 'luna_booking', 'side', 'high');
	}

	public static function render_booking_meta_box($post) {
		$booking_id = (int) get_post_meta($post->ID, '_luna_booking_id', true);
		$summary    = self::summary($booking_id);
		if (empty($summary)) { echo '<p>' . esc_html__('این رزرو دارای هزینه اولیه مشاوره نیست.', 'luna-appointments') . '</p>'; return; }
		$return_url = get_edit_post_link($post->ID, 'raw');
		$proposal   = self::proposal($summary);
		echo '<div class="luna-consultation-finance-actions" data-booking-id="' . esc_attr((string) $booking_id) . '" data-nonce="' . esc_attr(wp_create_nonce('luna_consultation_finance_' . $booking_id)) . '" data-return-url="' . esc_url($return_url) . '" style="display:grid;gap:8px">';
		if (! $summary['final_total_set']) {
			echo '<div class="notice notice-info inline" style="margin:0"><p>' . esc_html__('مبلغ نهایی هنوز پس از مشاوره ثبت نشده است؛ به همین دلیل مبلغ نهایی و مانده فعلاً صفر هستند.', 'luna-appointments') . '</p></div>';
		}
		echo '<p><strong>' . esc_html__('هزینه اولیه تعیین‌شده:', 'luna-appointments') . '</strong> ' . wp_kses_post(wc_price($summary['upfront_fee'])) . '</p>';
		echo '<p><strong>' . esc_html__('وضعیت سفارش اولیه:', 'luna-appointments') . '</strong> ' . esc_html($summary['initial_order_paid'] ? __('پرداخت شده', 'luna-appointments') : wc_get_order_status_name($summary['initial_order_status'])) . '</p>';
		echo '<p><strong>' . esc_html__('مبلغ نهایی:', 'luna-appointments') . '</strong> ' . ($summary['final_total_set'] ? wp_kses_post(wc_price($summary['total_amount'])) : '<em>' . esc_html__('هنوز تعیین نشده', 'luna-appointments') . '</em>') . '</p><p><strong>' . esc_html__('پرداخت‌شده:', 'luna-appointments') . '</strong> ' . wp_kses_post(wc_price($summary['paid_amount'])) . '</p><p><strong>' . esc_html__('مانده:', 'luna-appointments') . '</strong> ' . ($summary['final_total_set'] ? wp_kses_post(wc_price($summary['balance_amount'])) : '<em>' . esc_html__('پس از ثبت مبلغ نهایی محاسبه می‌شود', 'luna-appointments') . '</em>') . '</p>';
		if ($proposal['amount'] > 0) {
			echo '<div style="padding:10px;border:1px solid #dfe5d5;border-radius:10px;background:#f8faf5"><strong>' . esc_html__('پیشنهاد متخصص:', 'luna-appointments') . '</strong> ' . wp_kses_post(wc_price($proposal['amount'])) . '<br><small>' . esc_html($proposal['note']) . '</small><p style="margin:7px 0 0"><b>' . esc_html__('وضعیت:', 'luna-appointments') . '</b> ' . esc_html('pending' === $proposal['status'] ? __('منتظر تأیید', 'luna-appointments') : ('approved' === $proposal['status'] ? __('تأییدشده', 'luna-appointments') : __('نیازمند اصلاح', 'luna-appointments'))) . '</p></div>';
			if ('pending' === $proposal['status']) self::admin_action_form($booking_id, 'approve_proposal', __('تأیید پیشنهاد و ایجاد پرداخت مانده', 'luna-appointments'));
			if ('pending' === $proposal['status']) self::admin_action_form($booking_id, 'reject_proposal', __('برگشت برای اصلاح متخصص', 'luna-appointments'));
		}
		if (! empty($summary['balance_payment_url'])) echo '<p><a class="button button-primary" target="_blank" href="' . esc_url($summary['balance_payment_url']) . '">' . esc_html__('مشاهده لینک پرداخت مانده', 'luna-appointments') . '</a></p>';
		self::admin_action_form($booking_id, 'update_total', __('ثبت مبلغ نهایی پس از مشاوره', 'luna-appointments'), '<input type="number" name="total_amount" min="0" step="1000" value="' . esc_attr((string) $summary['total_amount']) . '" required style="width:100%;margin-bottom:6px">');
		if ($summary['final_total_set'] && $summary['balance_amount'] > 0) {
			self::admin_action_form($booking_id, 'settle_cash', __('ثبت تسویه حضوری', 'luna-appointments'), '<input type="number" name="amount" min="1" max="' . esc_attr((string) $summary['balance_amount']) . '" value="' . esc_attr((string) $summary['balance_amount']) . '" required style="width:100%;margin-bottom:6px">');
			self::admin_action_form($booking_id, 'create_balance_order', __('ساخت لینک پرداخت مانده', 'luna-appointments'));
		}
		if ($summary['paid_amount'] > 0) self::admin_action_form($booking_id, 'refund_deposit', __('بازپرداخت هزینه اولیه', 'luna-appointments'));
		if ($summary['paid_amount'] > 0) self::admin_action_form($booking_id, 'transfer_deposit', __('انتقال اعتبار به رزرو دیگر', 'luna-appointments'), '<input type="text" name="target_booking_code" placeholder="LN-XXXXX" required style="width:100%;margin-bottom:6px">');
		echo '</div>';
		?>
		<script>
		(function () {
			var root = document.currentScript.previousElementSibling;
			if (!root || !root.classList.contains('luna-consultation-finance-actions')) return;
			root.addEventListener('click', function (event) {
				var button = event.target.closest('.luna-consultation-finance-submit');
				if (!button || !root.contains(button)) return;
				event.preventDefault();
				var actionBox = button.closest('.luna-consultation-finance-action');
				var fields = actionBox ? actionBox.querySelectorAll('input, select, textarea') : [];
				for (var index = 0; index < fields.length; index++) {
					if (!fields[index].checkValidity()) {
						fields[index].reportValidity();
						return;
					}
				}
				button.disabled = true;
				var form = document.createElement('form');
				form.method = 'post';
				form.action = <?php echo wp_json_encode(admin_url('admin-post.php')); ?>;
				form.style.display = 'none';
				var values = {
					action: 'luna_consultation_finance_action',
					booking_id: root.dataset.bookingId || '',
					operation: button.dataset.operation || '',
					_luna_finance_nonce: root.dataset.nonce || '',
					return_url: root.dataset.returnUrl || ''
				};
				Object.keys(values).forEach(function (name) {
					var input = document.createElement('input');
					input.type = 'hidden';
					input.name = name;
					input.value = values[name];
					form.appendChild(input);
				});
				for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++) {
					if (!fields[fieldIndex].name || fields[fieldIndex].disabled) continue;
					var fieldInput = document.createElement('input');
					fieldInput.type = 'hidden';
					fieldInput.name = fields[fieldIndex].name;
					fieldInput.value = fields[fieldIndex].value;
					form.appendChild(fieldInput);
				}
				document.body.appendChild(form);
				form.submit();
			});
		}());
		</script>
		<?php
	}

	private static function admin_action_form($booking_id, $operation, $label, $fields = '') {
		unset($booking_id);
		echo '<div class="luna-consultation-finance-action" style="margin:6px 0">' . $fields . '<button type="button" class="button luna-consultation-finance-submit" data-operation="' . esc_attr($operation) . '" style="width:100%">' . esc_html($label) . '</button></div>';
	}

	public static function handle_admin_action() {
		$booking_id = absint($_POST['booking_id'] ?? 0);
		if (! current_user_can('manage_woocommerce') || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_luna_finance_nonce'] ?? '')), 'luna_consultation_finance_' . $booking_id)) wp_die(esc_html__('دسترسی نامعتبر است.', 'luna-appointments'));
		$operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
		$summary   = self::summary($booking_id);
		if (empty($summary)) wp_die(esc_html__('اطلاعات مالی رزرو پیدا نشد.', 'luna-appointments'));
		if ('settle_cash' === $operation) {
			$amount = min($summary['balance_amount'], max(0, (float) ($_POST['amount'] ?? 0)));
			if ($amount > 0) self::add_entry($booking_id, 0, 'settlement', $amount, 'posted', 'onsite', __('تسویه حضوری مانده توسط مدیر.', 'luna-appointments'));
			if (self::summary($booking_id)['balance_amount'] <= 0) self::mark_settled($booking_id);
		} elseif ('update_total' === $operation) {
			if (self::update_final_total($booking_id, max(0, (float) ($_POST['total_amount'] ?? 0)))) {
				$summary = self::summary($booking_id);
				if ($summary['balance_amount'] > 0) self::create_balance_order($booking_id, $summary);
				self::mark_proposal_status($summary, 'approved');
				self::notify($booking_id, $summary['balance_amount'] > 0 ? 'balance_ready' : 'balance_paid');
			}
		} elseif ('approve_proposal' === $operation) {
			$proposal = self::proposal($summary);
			if ($proposal['amount'] > 0 && self::update_final_total($booking_id, $proposal['amount'])) {
				$summary = self::summary($booking_id);
				self::mark_proposal_status($summary, 'approved');
				if ($summary['balance_amount'] > 0) self::create_balance_order($booking_id, $summary);
				self::notify($booking_id, $summary['balance_amount'] > 0 ? 'balance_ready' : 'balance_paid');
			}
		} elseif ('reject_proposal' === $operation) {
			self::mark_proposal_status($summary, 'rejected');
			self::notify($booking_id, 'proposal_rejected');
		} elseif ('create_balance_order' === $operation) {
			self::create_balance_order($booking_id, $summary);
		} elseif ('refund_deposit' === $operation && function_exists('wc_create_refund')) {
			$order = wc_get_order($summary['order_id']);
			if ($order instanceof WC_Order && $summary['paid_amount'] > 0) wc_create_refund(array('order_id' => $order->get_id(), 'amount' => min($summary['paid_amount'], (float) $order->get_total()), 'reason' => __('بازپرداخت هزینه اولیه مشاوره', 'luna-appointments'), 'refund_payment' => true));
		} elseif ('transfer_deposit' === $operation) {
			self::transfer_credit($booking_id, sanitize_text_field(wp_unslash($_POST['target_booking_code'] ?? '')));
		}
		$return_url = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return_url'] ?? '')), '');
		wp_safe_redirect($return_url ?: admin_url('edit.php?post_type=luna_booking')); exit;
	}

	private static function create_balance_order($booking_id, $summary) {
		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking) || $summary['balance_amount'] <= 0 || ! function_exists('wc_create_order')) return false;
		if (! empty($summary['balance_payment_url'])) return true;
		$order = wc_create_order(array('customer_id' => (int) ($booking['customer_user_id'] ?? 0)));
		if (! $order instanceof WC_Order) return false;
		$initial_order = ! empty($summary['order_id']) ? wc_get_order((int) $summary['order_id']) : false;
		if ($initial_order instanceof WC_Order) {
			$order->set_currency($initial_order->get_currency());
			foreach (array('billing_first_name','billing_last_name','billing_company','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country','billing_email','billing_phone') as $property) {
				$getter = 'get_' . $property;
				$setter = 'set_' . $property;
				if (is_callable(array($initial_order, $getter)) && is_callable(array($order, $setter))) $order->{$setter}($initial_order->{$getter}());
			}
		}
		$item = new WC_Order_Item_Fee(); $item->set_name(sprintf(__('تسویه مانده رزرو %s', 'luna-appointments'), $booking['booking_code'])); $item->set_amount($summary['balance_amount']); $item->set_total($summary['balance_amount']); $order->add_item($item);
		$order->set_created_via('luna_booking_balance'); $order->update_meta_data('_luna_booking_id', $booking_id); $order->update_meta_data('_luna_consultation_balance_order', 'yes'); $order->update_meta_data('_luna_consultation_initial_order_id', (int) $summary['order_id']); $order->calculate_totals(false); $order->save();
		self::add_entry($booking_id, $order->get_id(), 'balance_due', $summary['balance_amount'], 'pending', 'online', __('سفارش تسویه مانده ایجاد شد.', 'luna-appointments'), 'balance-due-' . $order->get_id());
		$order->add_order_note(__('لینک پرداخت مانده رزرو ایجاد شد.', 'luna-appointments'));
		return $order;
	}

	private static function update_final_total($booking_id, $total) {
		$summary = self::summary($booking_id);
		$order   = ! empty($summary['order_id']) ? wc_get_order((int) $summary['order_id']) : false;
		if (! $order instanceof WC_Order) return false;
		$before = (float) $summary['total_amount'];
		foreach ((array) $summary['entries'] as $entry) {
			if ('balance_due' !== (string) ($entry['entry_type'] ?? '') || empty($entry['order_id'])) continue;
			$pending_order = wc_get_order((int) $entry['order_id']);
			if ($pending_order instanceof WC_Order && $pending_order->needs_payment()) {
				$pending_order->update_status('cancelled', __('مبلغ نهایی رزرو تغییر کرد؛ لینک مانده قبلی باطل شد.', 'luna-appointments'));
			}
		}
		$order->update_meta_data('_luna_consultation_total_amount', round(max(0, (float) $total), 2));
		$order->update_meta_data('_luna_consultation_balance_amount', max(0, (float) $total - ($summary['deduct_fee'] ? (float) $summary['paid_amount'] : 0)));
		$order->save();
		self::add_entry($booking_id, $order->get_id(), 'total_change', (float) $total - $before, 'posted', 'admin', sprintf(__('مبلغ نهایی از %1$s به %2$s تغییر کرد.', 'luna-appointments'), $before, $total));
		if ((float) $total <= (float) $summary['paid_amount']) self::mark_settled($booking_id);
		return true;
	}

	private static function mark_settled($booking_id) {
		$booking = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (is_array($booking)) Luna_Appointments_Bookings_Table::update_booking($booking_id, array('payment_status' => 'paid'));
		self::notify($booking_id, 'balance_paid');
	}

	public static function handle_booking_transition($booking_id, $old_status, $new_status, $old_payment, $new_payment, $current, $previous, $source) {
		unset($old_status, $old_payment, $new_payment, $current, $previous, $source);
		if ('cancelled' !== (string) $new_status) return;
		$summary = self::summary((int) $booking_id);
		if (empty($summary) || $summary['paid_amount'] <= 0) return;
		if ('non_refundable' === $summary['refund_policy']) {
			self::add_entry($booking_id, 0, 'forfeit', 0, 'posted', 'policy', __('هزینه اولیه طبق سیاست خدمت غیرقابل بازپرداخت است.', 'luna-appointments'), 'forfeit-' . $booking_id);
			return;
		}
		if ('refundable' === $summary['refund_policy'] && function_exists('wc_create_refund')) {
			$order = wc_get_order($summary['order_id']);
			if ($order instanceof WC_Order && $order->get_total_refunded() < $order->get_total()) {
				wc_create_refund(array('order_id' => $order->get_id(), 'amount' => min($summary['paid_amount'], (float) $order->get_total() - (float) $order->get_total_refunded()), 'reason' => __('بازپرداخت خودکار لغو رزرو مشاوره', 'luna-appointments'), 'refund_payment' => true));
			}
		}
	}

	private static function transfer_credit($from_booking_id, $target_code) {
		global $wpdb;
		$target_code = strtoupper(trim((string) $target_code));
		$target_id   = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Luna_Appointments_Bookings_Table::get_table_name() . ' WHERE booking_code=%s LIMIT 1', $target_code));
		$from        = Luna_Appointments_Bookings_Table::get_booking((int) $from_booking_id);
		$target      = Luna_Appointments_Bookings_Table::get_booking($target_id);
		$summary     = self::summary((int) $from_booking_id);
		if (! is_array($from) || ! is_array($target) || $target_id === (int) $from_booking_id || $summary['paid_amount'] <= 0) return false;
		$from_user = (int) ($from['customer_user_id'] ?? 0);
		$target_user = (int) ($target['customer_user_id'] ?? 0);
		$same_customer = $from_user > 0 ? $from_user === $target_user : ('' !== trim((string) ($from['customer_phone'] ?? '')) && preg_replace('/\D+/', '', (string) $from['customer_phone']) === preg_replace('/\D+/', '', (string) ($target['customer_phone'] ?? '')));
		if (! $same_customer) return false;
		$amount = (float) $summary['paid_amount'];
		$key    = sanitize_key('transfer-' . $from_booking_id . '-' . $target_id);
		self::add_entry($from_booking_id, 0, 'adjustment', -$amount, 'posted', 'transfer', sprintf(__('انتقال اعتبار به رزرو %s', 'luna-appointments'), $target_code), $key . '-out');
		self::add_entry($target_id, 0, 'adjustment', $amount, 'posted', 'transfer', sprintf(__('انتقال اعتبار از رزرو %s', 'luna-appointments'), $from['booking_code']), $key . '-in');
		if (! empty(self::summary($target_id)) && self::summary($target_id)['balance_amount'] <= 0) self::mark_settled($target_id);
		return true;
	}

	private static function mark_proposal_status($summary, $status) {
		$order = ! empty($summary['order_id']) ? wc_get_order((int) $summary['order_id']) : false;
		if (! $order instanceof WC_Order) return false;
		$order->update_meta_data('_luna_consultation_proposal_status', sanitize_key($status));
		$order->update_meta_data('_luna_consultation_proposal_reviewed_by', get_current_user_id());
		$order->update_meta_data('_luna_consultation_proposal_reviewed_at', current_time('mysql', true));
		$order->save();
		return true;
	}

	private static function notify_manager_of_proposal($booking_id, $amount) {
		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) return;
		$subject = __('پیشنهاد مبلغ نهایی مشاوره', 'luna-appointments');
		$message = sprintf(__('برای رزرو %1$s مبلغ %2$s توسط متخصص پیشنهاد شد و منتظر تأیید مدیر است.', 'luna-appointments'), $booking['booking_code'], wp_strip_all_tags(wc_price($amount)));
		$admin_email = sanitize_email((string) get_option('admin_email'));
		if ($admin_email) wp_mail($admin_email, $subject, $message);
		do_action('luna_appointments_consultation_proposal_created', $booking, $amount);
	}

	public static function render_customer_finance_page() {
		if (! is_user_logged_in()) {
			echo '<p>' . esc_html__('برای مشاهده امور مالی وارد حساب خود شوید.', 'luna-appointments') . '</p>';
			return;
		}
		$result = Luna_Appointments_Bookings_Table::query_bookings_for_user(get_current_user_id(), array('per_page' => 250, 'order_by' => 'created_at', 'order' => 'DESC'));
		$items  = array();
		foreach ((array) ($result['items'] ?? array()) as $booking) {
			$summary = self::summary((int) ($booking['id'] ?? 0));
			if (! empty($summary)) $items[] = array('booking' => $booking, 'summary' => $summary, 'proposal' => self::proposal($summary));
		}
		echo '<section class="luna-account-finance"><header><span>' . esc_html__('پرداخت‌های خدمات مشاوره‌ای', 'luna-appointments') . '</span><h2>' . esc_html__('امور مالی رزروها', 'luna-appointments') . '</h2><p>' . esc_html__('مبلغ نهایی، بیعانه، مانده و لینک پرداخت رزروهای شما در این بخش قرار می‌گیرد.', 'luna-appointments') . '</p></header>';
		if (! $items) {
			echo '<div class="luna-account-finance__empty"><b>' . esc_html__('پرونده مالی فعالی ندارید.', 'luna-appointments') . '</b><span>' . esc_html__('رزروهای نیازمند مشاوره پس از ثبت در این بخش نمایش داده می‌شوند.', 'luna-appointments') . '</span></div></section>';
			return;
		}
		echo '<div class="luna-account-finance__grid">';
		foreach ($items as $item) {
			$booking  = $item['booking'];
			$summary  = $item['summary'];
			$proposal = $item['proposal'];
			$settled  = $summary['final_total_set'] && $summary['balance_amount'] <= 0;
			echo '<article class="luna-account-finance__card ' . ($settled ? 'is-settled' : 'is-due') . '"><div class="luna-account-finance__title"><div><small>' . esc_html($booking['booking_code'] ?? '') . '</small><h3>' . esc_html($booking['service_name'] ?? __('خدمت مشاوره‌ای', 'luna-appointments')) . '</h3></div><i>' . esc_html($settled ? __('تسویه‌شده', 'luna-appointments') : ($summary['final_total_set'] ? __('مانده قابل پرداخت', 'luna-appointments') : __('منتظر تعیین مبلغ', 'luna-appointments'))) . '</i></div>';
			echo '<div class="luna-account-finance__amounts"><span>' . esc_html__('هزینه اولیه', 'luna-appointments') . '<b>' . wp_kses_post(wc_price($summary['upfront_fee'])) . '</b></span><span>' . esc_html__('مبلغ نهایی', 'luna-appointments') . '<b>' . ($summary['final_total_set'] ? wp_kses_post(wc_price($summary['total_amount'])) : '—') . '</b></span><span>' . esc_html__('پرداخت‌شده', 'luna-appointments') . '<b>' . wp_kses_post(wc_price($summary['paid_amount'])) . '</b></span><span class="is-balance">' . esc_html__('مانده', 'luna-appointments') . '<b>' . ($summary['final_total_set'] ? wp_kses_post(wc_price($summary['balance_amount'])) : '—') . '</b></span></div>';
			if ('pending' === $proposal['status']) echo '<p class="luna-account-finance__notice">' . esc_html__('مبلغ پیشنهادی متخصص در انتظار تأیید مدیریت است.', 'luna-appointments') . '</p>';
			if (! empty($summary['balance_payment_url'])) echo '<a class="button luna-account-finance__pay" href="' . esc_url($summary['balance_payment_url']) . '">' . esc_html__('پرداخت آنلاین مانده', 'luna-appointments') . '<span>←</span></a>';
			echo '</article>';
		}
		echo '</div></section>';
	}

	private static function notify($booking_id, $event) {
		$booking = Luna_Appointments_Bookings_Table::get_booking_with_context($booking_id);
		if (! is_array($booking)) return;
		$summary = self::summary($booking_id);
		$subjects = array(
			'deposit_paid'      => __('پرداخت هزینه اولیه مشاوره', 'luna-appointments'),
			'balance_ready'     => __('مانده رزرو آماده پرداخت است', 'luna-appointments'),
			'proposal_rejected' => __('مبلغ پیشنهادی نیازمند اصلاح است', 'luna-appointments'),
			'balance_paid'      => __('تسویه کامل رزرو', 'luna-appointments'),
		);
		$subject = $subjects[$event] ?? __('به‌روزرسانی مالی رزرو', 'luna-appointments');
		$message = sprintf(__('رزرو %1$s: مبلغ پرداخت‌شده %2$s و مانده %3$s است.', 'luna-appointments'), $booking['booking_code'], wp_strip_all_tags(wc_price($summary['paid_amount'])), wp_strip_all_tags(wc_price($summary['balance_amount'])));
		if ('proposal_rejected' !== $event && is_email($booking['customer_email'] ?? '')) wp_mail($booking['customer_email'], $subject, $message);
		if (class_exists('Luna_Notifications_API')) {
			$customer_id = (int) ($booking['customer_user_id'] ?? 0);
			$specialist_id = (int) ($booking['specialist_id'] ?? 0);
			$payload = array('title' => $subject, 'body' => $message, 'url' => function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(self::ACCOUNT_ENDPOINT) : home_url('/my-account/'), 'tag' => 'luna-booking-finance-' . $booking_id, 'dir' => 'rtl', 'lang' => (string) ($booking['language'] ?? 'fa'));
			if ($customer_id > 0 && 'proposal_rejected' !== $event) Luna_Notifications_API::send_to_user($customer_id, $payload);
			if ($specialist_id > 0) {
				$payload['url'] = Luna_Appointments_Specialist_PWA::app_url();
				Luna_Notifications_API::send_to_specialist($specialist_id, $payload);
			}
		}
		do_action('luna_appointments_consultation_finance_notification', $event, $booking, $summary);
	}
}
