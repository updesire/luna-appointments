<?php
/**
 * Personalized aftercare plans and reminders.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Care_Plans {
	const TYPE = 'luna_care_plan';
	const CRON = 'luna_care_plan_reminder';

	public static function boot() {
		add_action('init', array(__CLASS__, 'register_post_type'));
		add_action('add_meta_boxes_' . self::TYPE, array(__CLASS__, 'add_meta_box'));
		add_action('save_post_' . self::TYPE, array(__CLASS__, 'save'), 10, 3);
		add_action('wp_ajax_luna_care_customer_history', array(__CLASS__, 'ajax_customer_history'));
		add_action('luna_appointments_booking_status_transition', array(__CLASS__, 'booking_transition'), 20, 8);
		add_action(self::CRON, array(__CLASS__, 'send_reminder'));
		add_filter('manage_' . self::TYPE . '_posts_columns', array(__CLASS__, 'columns'));
		add_action('manage_' . self::TYPE . '_posts_custom_column', array(__CLASS__, 'column'), 10, 2);
	}

	public static function register_post_type() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => 'پلن‌های مراقبتی',
					'singular_name' => 'پلن مراقبتی',
					'add_new'       => 'افزودن پلن',
					'add_new_item'  => 'ساخت پلن مراقبتی',
					'edit_item'     => 'ویرایش پلن مراقبتی',
					'menu_name'     => 'پلن مراقبتی',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-heart',
				'supports'     => array('title', 'editor'),
				'show_in_rest' => false,
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box('luna-care-plan', 'تنظیمات برنامه مراقبتی', array(__CLASS__, 'box'), self::TYPE, 'normal', 'high');
	}

	public static function box($post) {
		wp_nonce_field('luna_care_plan_save', 'luna_care_nonce');
		$value    = self::data($post->ID);
		$users    = get_users(array('number' => 300, 'orderby' => 'display_name'));
		$history  = $value['user_id'] ? self::customer_history($value['user_id']) : array();
		$products = function_exists('wc_get_products') ? wc_get_products(array('limit' => 150, 'status' => 'publish')) : array();
		$config   = array(
			'url'        => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('luna_care_customer_history'),
			'bookingId'  => $value['booking_id'],
			'emptyText'  => 'برای این مشتری سابقه مراجعه‌ای پیدا نشد.',
			'loadingText'=> 'در حال دریافت سوابق مشتری…',
		);

		echo '<style>
		.luna-care-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:1100px}.luna-care-grid label{display:grid;gap:7px;font-weight:700}.luna-care-grid input,.luna-care-grid select,.luna-care-grid textarea{width:100%;box-sizing:border-box}.luna-care-wide{grid-column:1/-1}.luna-care-visit{padding:18px;border:1px solid #e4ddd7;border-radius:14px;background:#fff}.luna-care-visit small{font-weight:400;color:#716b68}.luna-care-context{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}.luna-care-context span{padding:11px;border-radius:10px;background:#f7f4f1;font-weight:400}.luna-care-context b{display:block;margin-bottom:4px;font-size:11px;color:#8b6252}.luna-care-help{margin:0;color:#766e69;font-weight:400}.luna-care-loading{opacity:.6;pointer-events:none}@media(max-width:782px){.luna-care-grid,.luna-care-context{grid-template-columns:1fr}.luna-care-wide{grid-column:1}}
		</style><div class="luna-care-grid" dir="rtl">';

		echo '<label>مشتری<select id="luna-care-user" name="care_user_id"><option value="0">انتخاب مشتری</option>';
		foreach ($users as $user) {
			echo '<option value="' . esc_attr($user->ID) . '" ' . selected($value['user_id'], $user->ID, false) . '>' . esc_html($user->display_name . ' – ' . $user->user_email) . '</option>';
		}
		echo '</select><small class="luna-care-help">پس از انتخاب مشتری، سوابق خدمات او خودکار دریافت می‌شود.</small></label>';

		echo '<label class="luna-care-visit">سابقه مراجعه<select id="luna-care-booking" name="care_booking_id"><option value="0">انتخاب سابقه مراجعه</option>';
		foreach ($history as $item) {
			echo '<option value="' . esc_attr($item['id']) . '" ' . selected($value['booking_id'], $item['id'], false) . '>' . esc_html($item['label']) . '</option>';
		}
		echo '</select><small class="luna-care-help" id="luna-care-history-note">آخرین مراجعه تکمیل‌شده به‌صورت پیش‌فرض انتخاب می‌شود.</small><div class="luna-care-context" id="luna-care-context"><span><b>خدمت</b><i data-care-detail="service">—</i></span><span><b>متخصص</b><i data-care-detail="specialist">—</i></span><span><b>تاریخ مراجعه</b><i data-care-detail="date">—</i></span><span><b>سفارش مرتبط</b><i data-care-detail="order">—</i></span></div></label>';

		echo '<label>تاریخ مراجعه بعدی<input type="date" name="care_next_date" value="' . esc_attr($value['next_date']) . '"></label><label>یادآوری چند روز قبل<input type="number" min="0" name="care_reminder_days" value="' . esc_attr($value['reminder_days']) . '"></label><label>وضعیت<select name="care_status"><option value="active" ' . selected($value['status'], 'active', false) . '>فعال</option><option value="completed" ' . selected($value['status'], 'completed', false) . '>تکمیل‌شده</option><option value="cancelled" ' . selected($value['status'], 'cancelled', false) . '>لغوشده</option></select></label><label class="luna-care-wide">خلاصه شخصی‌سازی‌شده<textarea rows="3" name="care_summary">' . esc_textarea($value['summary']) . '</textarea></label><label class="luna-care-wide">دستورهای مراقبتی؛ هر دستور در یک خط<textarea rows="8" name="care_instructions">' . esc_textarea(implode("\n", $value['instructions'])) . '</textarea></label><label class="luna-care-wide">محصولات پیشنهادی<select id="luna-care-products" multiple size="7" name="care_products[]">';
		foreach ($products as $product) {
			echo '<option value="' . esc_attr($product->get_id()) . '" ' . selected(in_array($product->get_id(), $value['products'], true), true, false) . '>' . esc_html($product->get_name()) . '</option>';
		}
		echo '</select><small class="luna-care-help">محصولات موجود در سفارش مرتبط هنگام انتخاب مراجعه به‌صورت خودکار پیشنهاد می‌شوند.</small></label></div>';

		echo '<script>window.LunaCarePlanAdmin=' . wp_json_encode($config) . ';window.LunaCarePlanInitial=' . wp_json_encode(array_values($history)) . ';</script>';
		self::print_admin_script();
	}

	protected static function print_admin_script() {
		?>
		<script>
		(function(){
			'use strict';
			var user=document.getElementById('luna-care-user'),booking=document.getElementById('luna-care-booking'),context=document.getElementById('luna-care-context'),products=document.getElementById('luna-care-products'),config=window.LunaCarePlanAdmin||{},items=window.LunaCarePlanInitial||[];
			if(!user||!booking)return;
			function text(key,value){var node=context.querySelector('[data-care-detail="'+key+'"]');if(node)node.textContent=value||'—';}
			function selectedItem(){return items.find(function(item){return String(item.id)===String(booking.value);});}
			function show(){var item=selectedItem();text('service',item&&item.service);text('specialist',item&&item.specialist);text('date',item&&item.date);text('order',item&&item.order);}
			function suggestProducts(item){if(!products||!item||!item.product_ids||!item.product_ids.length)return;Array.prototype.forEach.call(products.options,function(option){option.selected=item.product_ids.indexOf(parseInt(option.value,10))!==-1;});}
			function fill(nextItems,preferred){items=nextItems||[];booking.innerHTML='';var first=new Option(items.length?'انتخاب سابقه مراجعه':config.emptyText,'0');booking.add(first);items.forEach(function(item){booking.add(new Option(item.label,item.id));});var target=preferred&&items.some(function(item){return String(item.id)===String(preferred);})?preferred:(items[0]?items[0].id:0);booking.value=String(target);show();if(!preferred)suggestProducts(selectedItem());}
			user.addEventListener('change',function(){var userId=parseInt(user.value,10)||0;if(!userId){fill([],0);return;}booking.closest('.luna-care-visit').classList.add('luna-care-loading');booking.innerHTML='<option>'+config.loadingText+'</option>';var body=new URLSearchParams({action:'luna_care_customer_history',nonce:config.nonce,user_id:String(userId)});fetch(config.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(response){return response.json();}).then(function(response){fill(response.success&&response.data?response.data.items:[],0);}).catch(function(){fill([],0);}).finally(function(){booking.closest('.luna-care-visit').classList.remove('luna-care-loading');});});
			booking.addEventListener('change',function(){show();suggestProducts(selectedItem());});
			fill(items,config.bookingId||0);
		})();
		</script>
		<?php
	}

	public static function ajax_customer_history() {
		check_ajax_referer('luna_care_customer_history', 'nonce');
		if (! current_user_can('edit_posts')) {
			wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
		}
		$user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
		wp_send_json_success(array('items' => self::customer_history($user_id)));
	}

	protected static function customer_history($user_id) {
		if ($user_id <= 0 || ! class_exists('Luna_Appointments_Bookings_Table')) {
			return array();
		}
		$result = Luna_Appointments_Bookings_Table::query_bookings_for_user(
			$user_id,
			array('order_by' => 'booking_date', 'order' => 'DESC', 'per_page' => 100)
		);
		$rows = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
		usort($rows, function($a, $b) {
			$a_done = in_array((string) ($a['status'] ?? ''), array('completed', 'done'), true) ? 1 : 0;
			$b_done = in_array((string) ($b['status'] ?? ''), array('completed', 'done'), true) ? 1 : 0;
			if ($a_done !== $b_done) return $b_done <=> $a_done;
			return strcmp((string) ($b['booking_date'] ?? ''), (string) ($a['booking_date'] ?? ''));
		});
		return array_values(array_map(array(__CLASS__, 'history_item'), $rows));
	}

	protected static function history_item($booking) {
		$order_label = ! empty($booking['wc_order_number']) ? 'سفارش #' . $booking['wc_order_number'] : 'بدون سفارش ووکامرس';
		$date        = ! empty($booking['booking_date']) ? self::display_date($booking['booking_date']) : '';
		$status      = self::booking_status_label((string) ($booking['status'] ?? ''));
		$products    = self::order_product_ids((int) ($booking['wc_order_id'] ?? 0));
		$service = (string) ($booking['service_name'] ?? 'خدمت بدون عنوان');
		return array(
			'id'            => (int) ($booking['id'] ?? 0),
			'label'         => trim($service . ' — ' . $date . ' — ' . $status . ' — ' . $order_label),
			'service'       => $service,
			'service_id'    => (int) ($booking['service_id'] ?? 0),
			'specialist'    => (string) ($booking['specialist_name'] ?? ''),
			'specialist_id' => (int) ($booking['specialist_id'] ?? 0),
			'date'          => $date,
			'order'         => $order_label,
			'order_id'      => (int) ($booking['wc_order_id'] ?? 0),
			'product_ids'   => array_values(array_unique($products)),
		);
	}

	public static function save($id, $post, $update) {
		unset($update);
		if (! $post instanceof WP_Post || wp_is_post_revision($id) || ! isset($_POST['luna_care_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_care_nonce'])), 'luna_care_plan_save') || ! current_user_can('edit_post', $id)) return;

		$user_id    = isset($_POST['care_user_id']) ? absint($_POST['care_user_id']) : 0;
		$booking_id = isset($_POST['care_booking_id']) ? absint($_POST['care_booking_id']) : 0;
		$booking    = $booking_id && class_exists('Luna_Appointments_Bookings_Table') ? Luna_Appointments_Bookings_Table::get_booking($booking_id) : null;
		if (! is_array($booking) || (int) ($booking['customer_user_id'] ?? 0) !== $user_id) {
			$booking_id = 0;
			$booking    = null;
		}
		update_post_meta($id, '_luna_care_user_id', $user_id);
		update_post_meta($id, '_luna_care_booking_id', $booking_id);
		update_post_meta($id, '_luna_care_service_id', (int) ($booking['service_id'] ?? 0));
		update_post_meta($id, '_luna_care_specialist_id', (int) ($booking['specialist_id'] ?? 0));
		update_post_meta($id, '_luna_care_order_id', (int) ($booking['wc_order_id'] ?? 0));

		$next_date = isset($_POST['care_next_date']) ? sanitize_text_field(wp_unslash($_POST['care_next_date'])) : '';
		update_post_meta($id, '_luna_care_next_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_date) ? $next_date : '');
		update_post_meta($id, '_luna_care_reminder_days', isset($_POST['care_reminder_days']) ? absint($_POST['care_reminder_days']) : 2);
		$status = isset($_POST['care_status']) ? sanitize_key(wp_unslash($_POST['care_status'])) : 'active';
		update_post_meta($id, '_luna_care_status', in_array($status, array('active', 'completed', 'cancelled'), true) ? $status : 'active');
		update_post_meta($id, '_luna_care_summary', sanitize_textarea_field(wp_unslash($_POST['care_summary'] ?? '')));
		update_post_meta($id, '_luna_care_instructions', array_values(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', wp_unslash($_POST['care_instructions'] ?? ''))))));
		update_post_meta($id, '_luna_care_products', array_values(array_filter(array_map('absint', (array) ($_POST['care_products'] ?? array())))));
		self::schedule($id);
	}

	public static function booking_transition($booking_id, $old, $new, $oldpay, $newpay, $booking, $previous, $source) {
		unset($old, $oldpay, $newpay, $previous, $source);
		if (! in_array(sanitize_key($new), array('completed', 'done'), true)) return;
		$existing = get_posts(array('post_type' => self::TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_luna_care_booking_id', 'meta_value' => (int) $booking_id));
		if ($existing) return;
		$user = (int) ($booking['customer_user_id'] ?? 0);
		if ($user <= 0) return;
		$service = (int) ($booking['service_id'] ?? 0);
		$title   = 'پلن مراقبتی ' . ($service ? get_the_title($service) : 'مراجعه') . ' – ' . ($booking['customer_name'] ?? 'مشتری');
		$id      = wp_insert_post(array('post_type' => self::TYPE, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'این برنامه را متناسب با نتیجه خدمت و نیاز مشتری تکمیل کنید.'));
		if (! $id) return;
		update_post_meta($id, '_luna_care_user_id', $user);
		update_post_meta($id, '_luna_care_booking_id', (int) $booking_id);
		update_post_meta($id, '_luna_care_service_id', $service);
		update_post_meta($id, '_luna_care_specialist_id', (int) ($booking['specialist_id'] ?? 0));
		update_post_meta($id, '_luna_care_order_id', (int) ($booking['wc_order_id'] ?? 0));
		update_post_meta($id, '_luna_care_status', 'active');
		update_post_meta($id, '_luna_care_reminder_days', 2);
		update_post_meta($id, '_luna_care_summary', 'برنامه مراقبتی شما پس از ' . ($service ? get_the_title($service) : 'خدمت لونا') . ' آماده شده است.');
		update_post_meta($id, '_luna_care_instructions', array('دستورهای متخصص را در این بخش تکمیل کنید.'));
		update_post_meta($id, '_luna_care_products', self::order_product_ids((int) ($booking['wc_order_id'] ?? 0)));
	}

	protected static function order_product_ids($order_id) {
		$products = array();
		if ($order_id <= 0 || ! function_exists('wc_get_order')) return $products;
		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) return $products;
		foreach ($order->get_items() as $order_item) {
			$product_id = (int) $order_item->get_product_id();
			if ($product_id > 0) $products[] = $product_id;
		}
		return array_values(array_unique($products));
	}

	protected static function schedule($id) {
		$old = wp_next_scheduled(self::CRON, array($id));
		if ($old) wp_unschedule_event($old, self::CRON, array($id));
		$data = self::data($id);
		if ('active' !== $data['status'] || ! $data['next_date'] || get_post_meta($id, '_luna_care_reminder_sent_for', true) === $data['next_date']) return;
		try {
			$visit = new DateTimeImmutable($data['next_date'] . ' 10:00:00', wp_timezone());
			$run   = $visit->getTimestamp() - DAY_IN_SECONDS * $data['reminder_days'];
		} catch (Exception $exception) {
			return;
		}
		if ($run <= time()) $run = time() + 60;
		wp_schedule_single_event($run, self::CRON, array($id));
		update_post_meta($id, '_luna_care_reminder_at', $run);
	}

	public static function reschedule($id) {
		self::schedule((int) $id);
	}

	public static function send_reminder($id) {
		$data = self::data($id);
		if ('active' !== $data['status'] || $data['user_id'] <= 0 || ! $data['next_date'] || get_post_meta($id, '_luna_care_reminder_sent_for', true) === $data['next_date']) return;
		$user = get_userdata($data['user_id']);
		if (! $user || ! is_email($user->user_email)) return;
		$date    = self::display_date($data['next_date']);
		$subject = 'یادآوری مراجعه بعدی لونا';
		$body    = "سلام {$user->display_name}،\n\nزمان پیشنهادی مراجعه بعدی شما {$date} است. برنامه مراقبتی و محصولات پیشنهادی در پنل کاربری لونا در دسترس است.\n";
		if (wp_mail($user->user_email, $subject, $body, array('Content-Type: text/plain; charset=UTF-8'))) {
			update_post_meta($id, '_luna_care_reminder_sent_at', current_time('mysql'));
			update_post_meta($id, '_luna_care_reminder_sent_for', $data['next_date']);
			do_action('luna_care_plan_reminder_sent', $id, $data, $user);
		}
	}

	public static function render_account($user_id) {
		$posts = get_posts(array('post_type' => self::TYPE, 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_luna_care_user_id', 'meta_value' => (int) $user_id));
		if (! $posts) return '<div class="care-empty"><strong>هنوز پلن مراقبتی ثبت نشده است</strong><p>پس از انجام خدمت، برنامه اختصاصی شما در این بخش نمایش داده می‌شود.</p></div>';
		$html = '<div class="care-plan-list">';
		foreach ($posts as $post) {
			$data = self::data($post->ID);
			$html .= '<article class="care-plan-card"><header><div><span>' . esc_html($data['service_id'] ? get_the_title($data['service_id']) : 'پلن اختصاصی') . '</span><h3>' . esc_html($post->post_title) . '</h3></div><b>' . esc_html(self::status_label($data['status'])) . '</b></header>';
			if ($data['summary']) $html .= '<p>' . esc_html($data['summary']) . '</p>';
			if ($data['instructions']) {
				$html .= '<ol>';
				foreach ($data['instructions'] as $instruction) $html .= '<li>' . esc_html($instruction) . '</li>';
				$html .= '</ol>';
			}
			if ($data['products']) {
				$html .= '<div class="care-products"><h4>محصولات پیشنهادی</h4>';
				foreach ($data['products'] as $product_id) {
					$product = function_exists('wc_get_product') ? wc_get_product($product_id) : false;
					if (! $product) continue;
					$html .= '<a href="' . esc_url($product->get_permalink()) . '"><span>' . wp_kses_post($product->get_image('woocommerce_thumbnail')) . '</span><strong>' . esc_html($product->get_name()) . '</strong><small>' . wp_kses_post($product->get_price_html()) . '</small></a>';
				}
				$html .= '</div>';
			}
			if ($data['next_date']) $html .= '<footer><span>مراجعه پیشنهادی بعدی</span><strong>' . esc_html(self::display_date($data['next_date'])) . '</strong></footer>';
			$html .= '</article>';
		}
		return $html . '</div>';
	}

	public static function data($id) {
		return array(
			'user_id'       => (int) get_post_meta($id, '_luna_care_user_id', true),
			'booking_id'    => (int) get_post_meta($id, '_luna_care_booking_id', true),
			'service_id'    => (int) get_post_meta($id, '_luna_care_service_id', true),
			'specialist_id' => (int) get_post_meta($id, '_luna_care_specialist_id', true),
			'order_id'      => (int) get_post_meta($id, '_luna_care_order_id', true),
			'next_date'     => (string) get_post_meta($id, '_luna_care_next_date', true),
			'reminder_days' => (int) (get_post_meta($id, '_luna_care_reminder_days', true) ?: 2),
			'status'        => (string) (get_post_meta($id, '_luna_care_status', true) ?: 'active'),
			'summary'       => (string) get_post_meta($id, '_luna_care_summary', true),
			'instructions'  => array_values(array_filter((array) get_post_meta($id, '_luna_care_instructions', true))),
			'products'      => array_values(array_filter(array_map('intval', (array) get_post_meta($id, '_luna_care_products', true)))),
		);
	}

	protected static function display_date($date) {
		return class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($date, '', false) : $date;
	}

	public static function columns($columns) {
		return array('cb' => $columns['cb'], 'title' => 'عنوان پلن', 'care_customer' => 'مشتری', 'care_service' => 'خدمت', 'care_next' => 'مراجعه بعدی', 'care_status' => 'وضعیت', 'date' => $columns['date']);
	}

	public static function column($column, $id) {
		$data = self::data($id);
		if ('care_customer' === $column) { $user = get_userdata($data['user_id']); echo $user ? esc_html($user->display_name) : '—'; }
		if ('care_service' === $column) echo $data['service_id'] ? esc_html(get_the_title($data['service_id'])) : '—';
		if ('care_next' === $column) echo esc_html($data['next_date'] ? self::display_date($data['next_date']) : '—');
		if ('care_status' === $column) echo esc_html(self::status_label($data['status']));
	}

	protected static function status_label($status) {
		$labels = array('active' => 'فعال', 'completed' => 'تکمیل‌شده', 'cancelled' => 'لغوشده');
		return $labels[$status] ?? $status;
	}

	protected static function booking_status_label($status) {
		$labels = array('completed' => 'تکمیل‌شده', 'done' => 'انجام‌شده', 'confirmed' => 'تأییدشده', 'pending' => 'در انتظار', 'cancelled' => 'لغوشده');
		return $labels[$status] ?? $status;
	}
}
