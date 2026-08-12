<?php
/**
 * Service package catalogue, commerce bridge and purchased package records.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Service_Packages {
	const POST_TYPE        = 'luna_service_pack';
	const PASS_POST_TYPE   = 'luna_package_pass';
	const TAXONOMY         = 'luna_package_category';
	const PAGE_SLUG        = 'service-packages';
	const NONCE_ACTION     = 'luna_buy_service_package';
	const SEED_OPTION      = 'luna_service_packages_seeded_v1';
	const TAXONOMY_MIGRATION_OPTION = 'luna_package_categories_migrated_v1';
	const ORDER_ISSUED_KEY = '_luna_service_packages_issued';

	public static function boot() {
		add_action('init', array(__CLASS__, 'register_post_types'));
		add_action('init', array(__CLASS__, 'ensure_package_categories'), 20);
		add_action('init', array(__CLASS__, 'register_shortcode'));
		add_action('init', array(__CLASS__, 'ensure_defaults'), 25);
		add_action('wp_loaded', array(__CLASS__, 'handle_buy_request'), 35);
		add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_boxes'));
		add_action('add_meta_boxes_' . self::PASS_POST_TYPE, array(__CLASS__, 'add_pass_meta_box'));
		add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save_package'), 10, 3);
		add_action('save_post_' . self::PASS_POST_TYPE, array(__CLASS__, 'save_pass'), 10, 3);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
		add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'package_columns'));
		add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'package_column_value'), 10, 2);
		add_action('restrict_manage_posts', array(__CLASS__, 'package_category_filter'));
		add_filter('manage_' . self::PASS_POST_TYPE . '_posts_columns', array(__CLASS__, 'pass_columns'));
		add_action('manage_' . self::PASS_POST_TYPE . '_posts_custom_column', array(__CLASS__, 'pass_column_value'), 10, 2);
		add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_order_package_column'), 35);
		add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'render_legacy_order_package_column'), 35, 2);
		add_filter('manage_woocommerce_page_wc-orders_columns', array(__CLASS__, 'add_order_package_column'), 35);
		add_action('manage_woocommerce_page_wc-orders_custom_column', array(__CLASS__, 'render_hpos_order_package_column'), 35, 2);
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_filter('template_include', array(__CLASS__, 'single_template'));
		add_filter('wp_nav_menu_items', array(__CLASS__, 'add_primary_menu_link'), 24, 2);
		add_action('init', array(__CLASS__, 'register_account_endpoint'), 12);
		add_action('init', array(__CLASS__, 'ensure_account_rewrite'), 30);
		add_filter('query_vars', array(__CLASS__, 'account_query_vars'));
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'account_menu_item'), 35);
		add_action('woocommerce_account_my-service-packages_endpoint', array(__CLASS__, 'render_account_packages'));

		add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_item_data'), 10, 3);
		add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_data'), 10, 2);
		add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'copy_order_item_data'), 10, 4);
		add_filter('woocommerce_is_sold_individually', array(__CLASS__, 'sold_individually'), 10, 2);
		add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'cart_item_thumbnail'), 20, 3);
		add_filter('woocommerce_checkout_fields', array(__CLASS__, 'filter_checkout_fields'), 40);
		add_filter('woocommerce_default_address_fields', array(__CLASS__, 'filter_default_address_fields'), 40);
		add_filter('woocommerce_cart_needs_shipping', array(__CLASS__, 'cart_needs_shipping'), 30);
		add_action('woocommerce_payment_complete', array(__CLASS__, 'issue_order_packages'), 25);
		add_action('woocommerce_order_status_processing', array(__CLASS__, 'issue_order_packages'), 25);
		add_action('woocommerce_order_status_completed', array(__CLASS__, 'issue_order_packages'), 25);
		add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'cancel_order_packages'), 25);
		add_action('woocommerce_order_status_refunded', array(__CLASS__, 'cancel_order_packages'), 25);
		add_action('woocommerce_order_status_failed', array(__CLASS__, 'cancel_order_packages'), 25);
		add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'render_order_packages'), 25);
		add_action('woocommerce_thankyou', array(__CLASS__, 'render_order_packages'), 25);
		add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'render_admin_order_packages'), 25);
	}

	public static function register_post_types() {
		register_post_type(self::POST_TYPE, array(
			'labels' => array(
				'name' => 'پکیج‌های خدماتی', 'singular_name' => 'پکیج خدماتی', 'menu_name' => 'پکیج‌های خدماتی',
				'add_new' => 'افزودن پکیج', 'add_new_item' => 'افزودن پکیج خدماتی', 'edit_item' => 'ویرایش پکیج',
				'all_items' => 'همه پکیج‌ها', 'view_item' => 'مشاهده پکیج', 'search_items' => 'جستجوی پکیج‌ها',
			),
			'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'show_in_rest' => true,
			'menu_icon' => 'dashicons-screenoptions', 'menu_position' => 27,
			'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'page-attributes'),
			'has_archive' => false, 'rewrite' => array('slug' => 'service-package', 'with_front' => false),
			'capability_type' => 'post', 'map_meta_cap' => true,
		));

		register_post_type(self::PASS_POST_TYPE, array(
			'labels' => array(
				'name' => 'پکیج‌های خریداری‌شده', 'singular_name' => 'پکیج خریداری‌شده', 'menu_name' => 'پکیج‌های خریداری‌شده',
				'all_items' => 'پکیج‌های مشتریان', 'edit_item' => 'مشاهده پکیج مشتری', 'search_items' => 'جستجوی پکیج مشتری',
			),
			'public' => false, 'show_ui' => true, 'show_in_menu' => 'edit.php?post_type=' . self::POST_TYPE,
			'show_in_rest' => false, 'supports' => array('title'), 'capability_type' => 'shop_order', 'map_meta_cap' => true,
			'capabilities' => array('create_posts' => 'do_not_allow'),
		));

		register_taxonomy(self::TAXONOMY, array(self::POST_TYPE), array(
			'labels' => array(
				'name'              => 'دسته‌بندی پکیج‌ها',
				'singular_name'     => 'دسته‌بندی پکیج',
				'menu_name'         => 'دسته‌بندی‌ها',
				'all_items'         => 'همه دسته‌بندی‌ها',
				'edit_item'         => 'ویرایش دسته‌بندی',
				'view_item'         => 'مشاهده دسته‌بندی',
				'update_item'       => 'به‌روزرسانی دسته‌بندی',
				'add_new_item'      => 'افزودن دسته‌بندی جدید',
				'new_item_name'     => 'نام دسته‌بندی جدید',
				'parent_item'       => 'دسته مادر',
				'parent_item_colon' => 'دسته مادر:',
				'search_items'      => 'جستجوی دسته‌بندی‌ها',
				'not_found'         => 'دسته‌بندی پیدا نشد.',
			),
			'public'            => true,
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array('slug' => 'service-package-category', 'with_front' => false),
		));
	}

	/** Create base terms and migrate the former category meta without data loss. */
	public static function ensure_package_categories() {
		if (! taxonomy_exists(self::TAXONOMY)) {
			return;
		}

		$defaults = array('massage' => 'ماساژ', 'laser' => 'لیزر');
		foreach ($defaults as $slug => $name) {
			if (! term_exists($slug, self::TAXONOMY)) {
				wp_insert_term($name, self::TAXONOMY, array('slug' => $slug));
			}
			$base_term = get_term_by('slug', $slug, self::TAXONOMY);
			if ($base_term instanceof WP_Term && function_exists('pll_set_term_language') && function_exists('pll_get_term_language') && ! pll_get_term_language($base_term->term_id, 'slug')) {
				pll_set_term_language($base_term->term_id, 'fa');
			}
		}

		if (get_option(self::TAXONOMY_MIGRATION_OPTION)) {
			return;
		}

		$package_ids = get_posts(array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		));
		foreach ($package_ids as $package_id) {
			$current_terms = wp_get_object_terms((int) $package_id, self::TAXONOMY, array('fields' => 'ids'));
			if (! is_wp_error($current_terms) && ! empty($current_terms)) {
				continue;
			}
			$legacy_slug = sanitize_title((string) get_post_meta((int) $package_id, '_luna_package_category', true));
			if (! $legacy_slug) {
				$legacy_slug = 'massage';
			}
			if (! term_exists($legacy_slug, self::TAXONOMY)) {
				wp_insert_term(ucwords(str_replace(array('-', '_'), ' ', $legacy_slug)), self::TAXONOMY, array('slug' => $legacy_slug));
			}
			$legacy_term = get_term_by('slug', $legacy_slug, self::TAXONOMY);
			if ($legacy_term instanceof WP_Term && function_exists('pll_set_term_language') && function_exists('pll_get_term_language') && ! pll_get_term_language($legacy_term->term_id, 'slug')) {
				pll_set_term_language($legacy_term->term_id, 'fa');
			}
			wp_set_object_terms((int) $package_id, $legacy_slug, self::TAXONOMY, false);
		}
		update_option(self::TAXONOMY_MIGRATION_OPTION, 1, false);
		flush_rewrite_rules(false);
	}

	public static function register_shortcode() {
		add_shortcode('luna_service_packages', array(__CLASS__, 'render_landing'));
	}

	public static function ensure_defaults() {
		if (get_option(self::SEED_OPTION) || ! post_type_exists(self::POST_TYPE)) {
			return;
		}

		$rows = array(
			array('massage', 'ماساژ ریلکسی', 4, 12000000, 'آرامش عمیق و کاهش تنش‌های روزانه'),
			array('massage', 'ماساژ سوئدی', 4, 13000000, 'حرکات کلاسیک برای گردش خون و رفع خستگی'),
			array('massage', 'ماساژ سنگ داغ', 4, 14000000, 'گرمای کنترل‌شده برای رهایی عضلات'),
			array('massage', 'ماساژ دیپ درمانی', 4, 15500000, 'تمرکز درمانی روی گرفتگی‌های عمیق'),
			array('massage', 'ماساژ ریلکسی', 6, 18500000, 'برنامه منظم آرام‌سازی بدن و ذهن'),
			array('massage', 'ماساژ سوئدی', 6, 20000000, 'دوره پیوسته بازسازی و رفع خستگی'),
			array('massage', 'ماساژ سنگ داغ', 6, 21000000, 'شش تجربه گرم و عمیق برای ریکاوری'),
			array('massage', 'ماساژ دیپ درمانی', 6, 24000000, 'پروتکل درمانی برای تنش‌های ماندگار'),
			array('massage', 'ماساژ ریلکسی', 8, 25000000, 'مسیر کامل آرامش و نگهداری نتیجه'),
			array('massage', 'ماساژ سوئدی', 8, 27000000, 'برنامه بلندمدت نشاط و گردش خون'),
			array('massage', 'ماساژ سنگ داغ', 8, 29000000, 'دوره کامل آرام‌سازی با سنگ‌های گرم'),
			array('massage', 'ماساژ دیپ درمانی', 8, 31000000, 'دوره تخصصی برای مراقبت عمیق عضلات'),
			array('laser', 'لیزر فول بادی', 1, 2700000, 'تک جلسه کامل فول بادی'),
			array('laser', 'لیزر فول بادی', 4, 10000000, 'پکیج چهار جلسه‌ای فول بادی'),
			array('laser', 'لیزر فول بادی', 6, 15500000, 'پکیج شش جلسه‌ای فول بادی'),
			array('laser', 'لیزر فول بادی', 8, 20800000, 'پکیج هشت جلسه‌ای فول بادی'),
			array('laser', 'لیزر توتال بادی', 1, 3000000, 'تک جلسه کامل توتال بادی'),
			array('laser', 'لیزر توتال بادی', 4, 11000000, 'پکیج چهار جلسه‌ای توتال بادی'),
			array('laser', 'لیزر توتال بادی', 6, 17000000, 'پکیج شش جلسه‌ای توتال بادی'),
			array('laser', 'لیزر توتال بادی', 8, 22800000, 'پکیج هشت جلسه‌ای توتال بادی'),
		);

		foreach ($rows as $index => $row) {
			$post_id = wp_insert_post(array(
				'post_type' => self::POST_TYPE, 'post_status' => 'publish',
				'post_title' => $row[1] . ' – ' . self::sessions_label($row[2]),
				'post_excerpt' => $row[4], 'post_content' => self::default_content($row[1], $row[2]), 'menu_order' => $index + 1,
			));
			if ($post_id && ! is_wp_error($post_id)) {
				update_post_meta($post_id, '_luna_package_category', $row[0]);
				wp_set_object_terms($post_id, $row[0], self::TAXONOMY, false);
				update_post_meta($post_id, '_luna_package_service_name', $row[1]);
				update_post_meta($post_id, '_luna_package_sessions', $row[2]);
				update_post_meta($post_id, '_luna_package_price', $row[3]);
				update_post_meta($post_id, '_luna_package_validity_days', 365);
				update_post_meta($post_id, '_luna_package_accent', 'massage' === $row[0] ? '#6f7f4b' : '#476f72');
				update_post_meta($post_id, '_luna_package_featured', in_array($row[2], array(6, 8), true) ? 'yes' : 'no');
				self::sync_product($post_id);
			}
		}

		$page = get_page_by_path(self::PAGE_SLUG);
		if (! $page) {
			wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'پکیج‌های خدماتی لونا', 'post_name' => self::PAGE_SLUG, 'post_content' => '[luna_service_packages]'));
		}
		update_option(self::SEED_OPTION, 1, false);
		flush_rewrite_rules(false);
	}

	private static function default_content($service, $sessions) {
		return '<p>این پکیج برای تجربه منظم و پیوسته «' . esc_html($service) . '» طراحی شده است. پس از خرید، ' . esc_html(self::sessions_label($sessions)) . ' برای شما فعال می‌شود و هماهنگی زمان مراجعه از طریق مرکز انجام خواهد شد.</p><h2>شرایط استفاده</h2><ul><li>مراجعه حضوری به مرکز لونا</li><li>هماهنگی زمان هر جلسه پیش از مراجعه</li><li>قابل استفاده برای خریدار پکیج</li></ul>';
	}

	public static function add_meta_boxes() {
		add_meta_box('luna-service-package-settings', 'تنظیمات پکیج خدماتی', array(__CLASS__, 'render_settings_box'), self::POST_TYPE, 'normal', 'high');
	}

	public static function add_pass_meta_box() {
		add_meta_box('luna-package-pass-status', 'مدیریت جلسات پکیج', array(__CLASS__, 'render_pass_box'), self::PASS_POST_TYPE, 'normal', 'high');
	}

	public static function render_pass_box($post) {
		wp_nonce_field('luna_save_package_pass', 'luna_package_pass_nonce');
		$total = max(1, absint(get_post_meta($post->ID, '_luna_package_total_sessions', true)));
		$remaining = min($total, absint(get_post_meta($post->ID, '_luna_package_remaining_sessions', true)));
		$status = (string) get_post_meta($post->ID, '_luna_package_status', true) ?: 'active';
		$expires = (string) get_post_meta($post->ID, '_luna_package_expires_at', true);
		echo '<div dir="rtl" style="display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:16px">';
		echo '<p><label style="display:block;font-weight:700;margin-bottom:7px">جلسات باقی‌مانده</label><input style="width:100%" type="number" min="0" max="' . esc_attr($total) . '" name="luna_pass_remaining" value="' . esc_attr($remaining) . '"><small>تعداد کل: ' . esc_html($total) . '</small></p>';
		echo '<p><label style="display:block;font-weight:700;margin-bottom:7px">وضعیت</label><select style="width:100%" name="luna_pass_status">';
		foreach (array('active' => 'فعال', 'used' => 'تکمیل‌شده', 'expired' => 'منقضی', 'cancelled' => 'لغوشده') as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
		echo '</select></p><p><label style="display:block;font-weight:700;margin-bottom:7px">تاریخ انقضا</label><input style="width:100%" type="text" name="luna_pass_expires" value="' . esc_attr($expires) . '" placeholder="2027-07-30 12:00:00"><small>خالی یعنی بدون انقضا.</small></p></div>';

		$schedule = self::pass_schedule($post->ID, $total);
		echo '<style>.luna-pass-schedule{direction:rtl;margin-top:22px}.luna-pass-schedule h3{margin-bottom:5px}.luna-pass-schedule>p{color:#646970}.luna-pass-session{display:grid;grid-template-columns:80px minmax(170px,1fr) minmax(130px,.65fr) minmax(160px,.8fr);gap:12px;align-items:end;margin:10px 0;padding:14px;border:1px solid #dcdcde;border-radius:14px;background:#fff}.luna-pass-session label{display:grid;gap:6px;font-weight:700}.luna-pass-session input,.luna-pass-session select{width:100%}.luna-pass-date-wrap{display:flex;gap:6px}.luna-pass-date-wrap input{min-width:0;cursor:pointer;background:#fff}.luna-pass-date-trigger{flex:0 0 40px;padding:0!important;font-size:17px!important}.datepicker-container,.datepicker-plot-area{z-index:100000!important}@media(max-width:782px){.luna-pass-session{grid-template-columns:1fr 1fr}.luna-pass-session__number{grid-column:1/-1}}</style>';
		echo '<section class="luna-pass-schedule"><h3>برنامه جلسات مراجعه</h3><p>تاریخ را به‌صورت جلالی وارد کنید؛ برای نمونه ۱۴۰۵/۰۵/۲۰. تاریخ استاندارد میلادی به‌صورت داخلی ذخیره می‌شود.</p>';
		foreach ($schedule as $index => $session) {
			$jalali = $session['date'] && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($session['date'], '', false) : '';
			echo '<div class="luna-pass-session"><strong class="luna-pass-session__number">جلسه ' . esc_html($index + 1) . '</strong>';
			echo '<label>تاریخ مراجعه<span class="luna-pass-date-wrap"><input type="text" inputmode="numeric" class="luna-pass-jalali-datepicker" name="luna_pass_session_date[]" value="' . esc_attr($jalali) . '" placeholder="انتخاب تاریخ" autocomplete="off" readonly><button type="button" class="button luna-pass-date-trigger" aria-label="باز کردن تقویم">📅</button></span></label>';
			echo '<label>ساعت<input type="time" name="luna_pass_session_time[]" value="' . esc_attr($session['time']) . '"></label>';
			echo '<label>وضعیت<select name="luna_pass_session_status[]">';
			foreach (array('pending' => 'زمان تعیین نشده', 'scheduled' => 'برنامه‌ریزی‌شده', 'completed' => 'انجام‌شده', 'cancelled' => 'لغوشده') as $key => $label) {
				echo '<option value="' . esc_attr($key) . '" ' . selected($session['status'], $key, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select></label></div>';
		}
		echo '</section>';
	}

	public static function enqueue_admin_assets() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen || self::PASS_POST_TYPE !== (string) $screen->post_type || 'post' !== (string) $screen->base) return;
		$base_path = WP_PLUGIN_DIR . '/persian-woocommerce/assets/';
		$base_url  = WP_PLUGIN_URL . '/persian-woocommerce/assets/';
		if (file_exists($base_path . 'css/persian-datepicker.css')) {
			wp_enqueue_style('luna-package-persian-datepicker', $base_url . 'css/persian-datepicker.css', array(), LUNA_APPOINTMENTS_VERSION);
		}
		if (file_exists($base_path . 'js/persian-datepicker.min.js')) {
			wp_enqueue_script('luna-package-persian-datepicker', $base_url . 'js/persian-datepicker.min.js', array('jquery'), LUNA_APPOINTMENTS_VERSION, true);
		}
		wp_enqueue_script('luna-service-package-admin', LUNA_APPOINTMENTS_URL . 'assets/service-package-admin.js', array('jquery'), LUNA_APPOINTMENTS_VERSION, true);
	}

	public static function save_pass($post_id, $post, $update) {
		unset($update);
		if (! $post instanceof WP_Post || wp_is_post_revision($post_id) || ! isset($_POST['luna_package_pass_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_package_pass_nonce'])), 'luna_save_package_pass') || ! current_user_can('edit_post', $post_id)) return;
		$total = max(1, absint(get_post_meta($post_id, '_luna_package_total_sessions', true)));
		$remaining = min($total, absint($_POST['luna_pass_remaining'] ?? 0));
		$status = sanitize_key(wp_unslash($_POST['luna_pass_status'] ?? 'active'));
		if (! in_array($status, array('active', 'used', 'expired', 'cancelled'), true)) $status = 'active';
		if (0 === $remaining && 'active' === $status) $status = 'used';
		update_post_meta($post_id, '_luna_package_remaining_sessions', $remaining);
		update_post_meta($post_id, '_luna_package_status', $status);
		update_post_meta($post_id, '_luna_package_expires_at', sanitize_text_field(wp_unslash($_POST['luna_pass_expires'] ?? '')));

		if (isset($_POST['luna_pass_session_date'], $_POST['luna_pass_session_time'], $_POST['luna_pass_session_status'])) {
			$dates    = array_map('sanitize_text_field', (array) wp_unslash($_POST['luna_pass_session_date']));
			$times    = array_map('sanitize_text_field', (array) wp_unslash($_POST['luna_pass_session_time']));
			$statuses = array_map('sanitize_key', (array) wp_unslash($_POST['luna_pass_session_status']));
			$schedule = array();
			for ($index = 0; $index < $total; $index++) {
				$date = isset($dates[$index]) && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::jalali_to_gregorian_date($dates[$index]) : '';
				$time = isset($times[$index]) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $times[$index]) ? $times[$index] : '';
				$session_status = $statuses[$index] ?? 'pending';
				if (! in_array($session_status, array('pending', 'scheduled', 'completed', 'cancelled'), true)) $session_status = 'pending';
				if (! $date || ! $time) {
					$session_status = 'completed' === $session_status ? 'completed' : 'pending';
				} elseif ('pending' === $session_status) {
					$session_status = 'scheduled';
				}
				$schedule[] = array('date' => $date, 'time' => $time, 'status' => $session_status);
			}
			update_post_meta($post_id, '_luna_package_schedule', $schedule);
			$completed = count(array_filter($schedule, static function($session) { return 'completed' === $session['status']; }));
			$remaining = max(0, $total - $completed);
			update_post_meta($post_id, '_luna_package_remaining_sessions', $remaining);
			if (0 === $remaining && ! in_array($status, array('cancelled', 'expired'), true)) {
				update_post_meta($post_id, '_luna_package_status', 'used');
			}
		}
	}

	public static function render_settings_box($post) {
		wp_nonce_field('luna_save_service_package', 'luna_package_nonce');
		$data = self::package_data($post->ID);
		?>
		<style>.luna-package-admin{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:18px;direction:rtl}.luna-package-admin label{display:block;margin-bottom:7px;font-weight:700}.luna-package-admin input,.luna-package-admin select{width:100%}@media(max-width:782px){.luna-package-admin{grid-template-columns:1fr}}</style>
		<div class="luna-package-admin">
			<p><label for="luna_package_service_name">نام خدمت</label><input id="luna_package_service_name" name="luna_package_service_name" value="<?php echo esc_attr($data['service_name']); ?>"></p>
			<p><label for="luna_package_sessions">تعداد جلسات</label><input id="luna_package_sessions" name="luna_package_sessions" type="number" min="1" max="100" value="<?php echo esc_attr($data['sessions']); ?>"></p>
			<p><label for="luna_package_price">قیمت پکیج</label><input id="luna_package_price" name="luna_package_price" type="number" min="1" step="1" value="<?php echo esc_attr($data['price']); ?>"><small>مبلغ بر اساس واحد پول ووکامرس ذخیره می‌شود.</small></p>
			<p><label for="luna_package_validity_days">اعتبار پس از خرید (روز)</label><input id="luna_package_validity_days" name="luna_package_validity_days" type="number" min="0" value="<?php echo esc_attr($data['validity_days']); ?>"><small>صفر یعنی بدون انقضا.</small></p>
			<p><label for="luna_package_accent">رنگ پکیج</label><input id="luna_package_accent" name="luna_package_accent" type="color" value="<?php echo esc_attr($data['accent']); ?>"></p>
			<p><label for="luna_package_featured">نمایش به‌عنوان پیشنهاد ویژه</label><select id="luna_package_featured" name="luna_package_featured"><option value="no" <?php selected($data['featured'], 'no'); ?>>خیر</option><option value="yes" <?php selected($data['featured'], 'yes'); ?>>بله</option></select></p>
		</div>
		<?php
	}

	public static function save_package($post_id, $post, $update) {
		unset($update);
		if (! $post instanceof WP_Post || wp_is_post_revision($post_id) || ! isset($_POST['luna_package_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_package_nonce'])), 'luna_save_service_package') || ! current_user_can('edit_post', $post_id)) {
			return;
		}
		$assigned_terms = wp_get_object_terms($post_id, self::TAXONOMY, array('fields' => 'slugs'));
		if (! is_wp_error($assigned_terms) && ! empty($assigned_terms[0])) {
			update_post_meta($post_id, '_luna_package_category', sanitize_title($assigned_terms[0]));
		}
		update_post_meta($post_id, '_luna_package_service_name', sanitize_text_field(wp_unslash($_POST['luna_package_service_name'] ?? '')));
		update_post_meta($post_id, '_luna_package_sessions', max(1, absint($_POST['luna_package_sessions'] ?? 1)));
		update_post_meta($post_id, '_luna_package_price', max(1, (float) wp_unslash($_POST['luna_package_price'] ?? 0)));
		update_post_meta($post_id, '_luna_package_validity_days', absint($_POST['luna_package_validity_days'] ?? 0));
		$accent = sanitize_hex_color(wp_unslash($_POST['luna_package_accent'] ?? ''));
		update_post_meta($post_id, '_luna_package_accent', $accent ?: '#6f7f4b');
		update_post_meta($post_id, '_luna_package_featured', 'yes' === sanitize_key(wp_unslash($_POST['luna_package_featured'] ?? 'no')) ? 'yes' : 'no');
		self::sync_product($post_id);
	}

	private static function sync_product($package_id) {
		if (! class_exists('WC_Product_Simple')) {
			return 0;
		}
		$data = self::package_data($package_id);
		$product_id = absint(get_post_meta($package_id, '_luna_package_product_id', true));
		$product = $product_id ? wc_get_product($product_id) : false;
		if (! $product instanceof WC_Product_Simple) {
			$product = new WC_Product_Simple();
		}
		$product->set_name('پکیج ' . get_the_title($package_id));
		$product->set_status(get_post_status($package_id) === 'publish' ? 'publish' : 'draft');
		$product->set_catalog_visibility('hidden');
		$product->set_virtual(true);
		$product->set_sold_individually(true);
		$product->set_regular_price((string) max(1, $data['price']));
		$product->set_price((string) max(1, $data['price']));
		$product->set_tax_status('none');
		$product->set_manage_stock(false);
		$product_id = $product->save();
		update_post_meta($package_id, '_luna_package_product_id', $product_id);
		update_post_meta($product_id, '_luna_is_service_package', 'yes');
		update_post_meta($product_id, '_luna_service_package_id', $package_id);
		return $product_id;
	}

	public static function handle_buy_request() {
		if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['luna_buy_service_package'])) {
			return;
		}
		$package_id = absint($_POST['package_id'] ?? 0);
		$nonce = sanitize_text_field(wp_unslash($_POST['luna_package_buy_nonce'] ?? ''));
		if (! $package_id || ! wp_verify_nonce($nonce, self::NONCE_ACTION . '_' . $package_id) || self::POST_TYPE !== get_post_type($package_id) || 'publish' !== get_post_status($package_id)) {
			wc_add_notice('پکیج انتخاب‌شده معتبر نیست.', 'error');
			return;
		}
		if (! function_exists('WC') || ! WC()->cart) {
			return;
		}
		$product_id = self::sync_product($package_id);
		if (! $product_id) {
			wc_add_notice('امکان افزودن این پکیج به سبد خرید وجود ندارد.', 'error');
			return;
		}
		WC()->cart->empty_cart();
		$added = WC()->cart->add_to_cart($product_id, 1, 0, array(), array('luna_service_package_id' => $package_id));
		if (! $added) {
			wc_add_notice('افزودن پکیج به سبد خرید انجام نشد.', 'error');
			return;
		}
		wp_safe_redirect(wc_get_checkout_url());
		exit;
	}

	public static function cart_item_data($data, $product_id, $variation_id) {
		unset($variation_id);
		if ('yes' === get_post_meta($product_id, '_luna_is_service_package', true) && empty($data['luna_service_package_id'])) {
			$data['luna_service_package_id'] = absint(get_post_meta($product_id, '_luna_service_package_id', true));
		}
		return $data;
	}

	public static function display_cart_item_data($display, $item) {
		$package_id = absint($item['luna_service_package_id'] ?? 0);
		if (! $package_id) {
			return $display;
		}
		$data = self::package_data($package_id);
		$display[] = array('key' => 'نوع خدمت', 'value' => self::category_label($data['category']));
		$display[] = array('key' => 'تعداد جلسات', 'value' => self::sessions_label($data['sessions']));
		$display[] = array('key' => 'نحوه دریافت', 'value' => 'مراجعه حضوری به مرکز');
		return $display;
	}

	public static function copy_order_item_data($item, $cart_key, $values, $order) {
		unset($cart_key, $order);
		$package_id = absint($values['luna_service_package_id'] ?? 0);
		if (! $package_id) {
			return;
		}
		$data = self::package_data($package_id);
		$item->add_meta_data('_luna_service_package_id', $package_id, true);
		$item->add_meta_data('نوع خدمت', self::category_label($data['category']), true);
		$item->add_meta_data('تعداد جلسات', self::sessions_label($data['sessions']), true);
	}

	public static function sold_individually($sold, $product) {
		return $product instanceof WC_Product && 'yes' === get_post_meta($product->get_id(), '_luna_is_service_package', true) ? true : $sold;
	}

	public static function cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key) {
		unset($cart_item_key);
		$package_id = absint($cart_item['luna_service_package_id'] ?? 0);
		if (! $package_id) {
			return $thumbnail;
		}
		$data = self::package_data($package_id);
		return '<span class="luna-package-cart-icon is-' . esc_attr($data['category']) . '" aria-hidden="true">' . self::icon($data['category']) . '</span>';
	}

	private static function cart_is_package_only() {
		if (! function_exists('WC') || ! WC()->cart || WC()->cart->is_empty()) {
			return false;
		}
		foreach (WC()->cart->get_cart() as $item) {
			$product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
			if ('yes' !== get_post_meta($product_id, '_luna_is_service_package', true)) {
				return false;
			}
		}
		return true;
	}

	public static function filter_checkout_fields($fields) {
		if (! self::cart_is_package_only()) {
			return $fields;
		}
		if (isset($fields['billing'])) {
			foreach (array('billing_country', 'billing_state', 'billing_city', 'billing_address_1', 'billing_address_2', 'billing_postcode', 'billing_company', 'billing_email') as $key) {
				unset($fields['billing'][ $key ]);
			}
		}
		unset($fields['shipping'], $fields['order']['order_comments']);
		return $fields;
	}

	public static function filter_default_address_fields($fields) {
		if (! self::cart_is_package_only()) {
			return $fields;
		}
		foreach (array('country', 'state', 'city', 'address_1', 'address_2', 'postcode', 'company') as $key) {
			unset($fields[ $key ]);
		}
		return $fields;
	}

	public static function cart_needs_shipping($needs_shipping) {
		return self::cart_is_package_only() ? false : $needs_shipping;
	}

	public static function issue_order_packages($order_id) {
		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order || 'yes' === $order->get_meta(self::ORDER_ISSUED_KEY, true)) {
			return;
		}
		$issued = array();
		foreach ($order->get_items() as $item_id => $item) {
			$package_id = absint($item->get_meta('_luna_service_package_id', true));
			if (! $package_id) {
				continue;
			}
			$data = self::package_data($package_id);
			$pass_id = wp_insert_post(array(
				'post_type' => self::PASS_POST_TYPE, 'post_status' => 'publish',
				'post_title' => 'PKG-' . $order->get_id() . '-' . $item_id . ' | ' . get_the_title($package_id),
			));
			if (! $pass_id || is_wp_error($pass_id)) {
				continue;
			}
			$expires = $data['validity_days'] > 0 ? wp_date('Y-m-d H:i:s', current_time('timestamp', true) + DAY_IN_SECONDS * $data['validity_days'], new DateTimeZone('UTC')) : '';
			update_post_meta($pass_id, '_luna_package_id', $package_id);
			update_post_meta($pass_id, '_luna_package_order_id', $order->get_id());
			update_post_meta($pass_id, '_luna_package_order_item_id', $item_id);
			update_post_meta($pass_id, '_luna_package_user_id', $order->get_user_id());
			update_post_meta($pass_id, '_luna_package_customer_name', trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
			update_post_meta($pass_id, '_luna_package_customer_phone', $order->get_billing_phone());
			update_post_meta($pass_id, '_luna_package_total_sessions', $data['sessions']);
			update_post_meta($pass_id, '_luna_package_remaining_sessions', $data['sessions']);
			update_post_meta($pass_id, '_luna_package_status', 'active');
			update_post_meta($pass_id, '_luna_package_expires_at', $expires);
			update_post_meta($pass_id, '_luna_package_schedule', array_fill(0, $data['sessions'], array('date' => '', 'time' => '', 'status' => 'pending')));
			$issued[] = $pass_id;
		}
		if ($issued) {
			$order->update_meta_data(self::ORDER_ISSUED_KEY, 'yes');
			$order->update_meta_data('_luna_service_package_pass_ids', $issued);
			$order->save();
		}
	}

	public static function render_order_packages($order_id) {
		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}
		$ids = array_filter(array_map('absint', (array) $order->get_meta('_luna_service_package_pass_ids', true)));
		if (! $ids) {
			return;
		}
		echo '<section class="luna-order-packages"><h2>پکیج‌های خدماتی شما</h2><div class="luna-order-packages__grid">';
		foreach ($ids as $pass_id) {
			$package_id = absint(get_post_meta($pass_id, '_luna_package_id', true));
			$total = max(1, absint(get_post_meta($pass_id, '_luna_package_total_sessions', true)));
			$next = self::next_session(self::pass_schedule($pass_id, $total));
			echo '<article><span>' . self::icon(self::package_data($package_id)['category']) . '</span><div><strong>' . esc_html(get_the_title($package_id)) . '</strong><small>جلسات باقی‌مانده: ' . esc_html(get_post_meta($pass_id, '_luna_package_remaining_sessions', true)) . '</small>';
			if ($next) echo '<small class="luna-order-package-next">جلسه بعدی: ' . esc_html(self::session_label($next)) . '</small>';
			echo '</div></article>';
		}
		echo '</div></section>';
	}

	/** Disable every issued pass when its source order is no longer payable/valid. */
	public static function cancel_order_packages($order_id) {
		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return;
		}
		$ids = array_filter(array_map('absint', (array) $order->get_meta('_luna_service_package_pass_ids', true)));
		foreach ($ids as $pass_id) {
			if (self::PASS_POST_TYPE === get_post_type($pass_id)) {
				update_post_meta($pass_id, '_luna_package_status', 'cancelled');
			}
		}
	}

	public static function render_admin_order_packages($order) {
		if (! $order instanceof WC_Order) {
			return;
		}
		$ids = array_filter(array_map('absint', (array) $order->get_meta('_luna_service_package_pass_ids', true)));
		if ($ids) {
			echo '<div class="luna-admin-order-packages"><p><strong>پکیج خدماتی:</strong> ';
			foreach ($ids as $id) {
				$total = max(1, absint(get_post_meta($id, '_luna_package_total_sessions', true)));
				$next = self::next_session(self::pass_schedule($id, $total));
				echo '<a href="' . esc_url(get_edit_post_link($id)) . '">#' . esc_html($id) . '</a> ';
				if ($next) echo '<span style="display:block;margin-top:6px">جلسه بعدی: <strong>' . esc_html(self::session_label($next)) . '</strong></span>';
			}
			echo '</p></div>';
		}
	}

	public static function package_columns($columns) {
		return array('cb' => $columns['cb'], 'title' => 'عنوان پکیج', 'package_category' => 'گروه', 'package_sessions' => 'جلسات', 'package_price' => 'قیمت', 'package_product' => 'محصول ووکامرس', 'date' => $columns['date']);
	}

	/** Add a real taxonomy filter above the package list table. */
	public static function package_category_filter($post_type) {
		if (self::POST_TYPE !== $post_type || ! taxonomy_exists(self::TAXONOMY)) {
			return;
		}
		$selected = isset($_GET[self::TAXONOMY]) ? sanitize_title(wp_unslash($_GET[self::TAXONOMY])) : '';
		wp_dropdown_categories(array(
			'show_option_all' => 'همه دسته‌بندی‌های پکیج',
			'taxonomy'        => self::TAXONOMY,
			'name'            => self::TAXONOMY,
			'orderby'         => 'name',
			'selected'        => $selected,
			'hierarchical'    => true,
			'hide_empty'      => false,
			'value_field'     => 'slug',
		));
	}

	public static function package_column_value($column, $post_id) {
		$data = self::package_data($post_id);
		if ('package_category' === $column) echo esc_html(self::category_label($data['category']));
		if ('package_sessions' === $column) echo esc_html(self::sessions_label($data['sessions']));
		if ('package_price' === $column) echo wp_kses_post(self::price($data['price']));
		if ('package_product' === $column) { $product_id = absint(get_post_meta($post_id, '_luna_package_product_id', true)); echo $product_id ? '<a href="' . esc_url(get_edit_post_link($product_id)) . '">#' . esc_html($product_id) . '</a>' : '—'; }
	}

	public static function pass_columns($columns) {
		return array('cb' => $columns['cb'], 'title' => 'شناسه پکیج', 'pass_customer' => 'مشتری', 'pass_sessions' => 'جلسات', 'pass_next' => 'مراجعه بعدی', 'pass_order' => 'سفارش', 'pass_status' => 'وضعیت', 'pass_expiry' => 'اعتبار', 'date' => 'تاریخ خرید');
	}

	public static function pass_column_value($column, $post_id) {
		if ('pass_customer' === $column) echo esc_html(get_post_meta($post_id, '_luna_package_customer_name', true) . ' — ' . get_post_meta($post_id, '_luna_package_customer_phone', true));
		if ('pass_sessions' === $column) echo esc_html(get_post_meta($post_id, '_luna_package_remaining_sessions', true) . ' از ' . get_post_meta($post_id, '_luna_package_total_sessions', true));
		if ('pass_next' === $column) { $total = max(1, absint(get_post_meta($post_id, '_luna_package_total_sessions', true))); $next = self::next_session(self::pass_schedule($post_id, $total)); echo $next ? esc_html(self::session_label($next)) : '—'; }
		if ('pass_order' === $column) { $order_id = absint(get_post_meta($post_id, '_luna_package_order_id', true)); echo $order_id ? '<a href="' . esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')) . '">#' . esc_html($order_id) . '</a>' : '—'; }
		if ('pass_status' === $column) echo '<span style="display:inline-block;padding:5px 10px;border-radius:999px;background:#e7efdc;color:#435422;font-weight:700">' . esc_html(self::status_label(get_post_meta($post_id, '_luna_package_status', true))) . '</span>';
		if ('pass_expiry' === $column) echo esc_html(get_post_meta($post_id, '_luna_package_expires_at', true) ?: 'بدون انقضا');
	}

	public static function add_order_package_column($columns) {
		$result = array();
		foreach ($columns as $key => $label) {
			$result[$key] = $label;
			if (in_array($key, array('order_status', 'status'), true)) $result['luna_package_order'] = 'پکیج خدماتی';
		}
		if (! isset($result['luna_package_order'])) $result['luna_package_order'] = 'پکیج خدماتی';
		return $result;
	}

	private static function order_has_package($order) {
		if (! $order instanceof WC_Order) return false;
		foreach ($order->get_items() as $item) if (absint($item->get_meta('_luna_service_package_id', true))) return true;
		return false;
	}

	public static function render_legacy_order_package_column($column, $post_id) {
		if ('luna_package_order' !== $column) return;
		echo self::order_has_package(wc_get_order($post_id)) ? '<span style="display:inline-block;padding:5px 9px;border-radius:999px;background:#e7efdc;color:#435422;font-weight:700">پکیج خدماتی</span>' : '<span style="color:#aaa">—</span>';
	}

	public static function render_hpos_order_package_column($column, $order) {
		if ('luna_package_order' !== $column) return;
		if (! $order instanceof WC_Order) $order = wc_get_order($order);
		echo self::order_has_package($order) ? '<span style="display:inline-block;padding:5px 9px;border-radius:999px;background:#e7efdc;color:#435422;font-weight:700">پکیج خدماتی</span>' : '<span style="color:#aaa">—</span>';
	}

	public static function register_account_endpoint() { add_rewrite_endpoint('my-service-packages', EP_ROOT | EP_PAGES); }
	public static function ensure_account_rewrite() {
		if ('1.3.0' !== get_option('luna_service_packages_rewrite_version')) {
			flush_rewrite_rules(false);
			update_option('luna_service_packages_rewrite_version', '1.3.0', false);
		}
	}
	public static function account_query_vars($vars) { $vars[] = 'my-service-packages'; return $vars; }
	public static function account_menu_item($items) {
		$result = array();
		foreach ($items as $key => $label) {
			$result[$key] = $label;
			if ('orders' === $key) $result['my-service-packages'] = 'پکیج‌های خدماتی';
		}
		if (! isset($result['my-service-packages'])) $result['my-service-packages'] = 'پکیج‌های خدماتی';
		return $result;
	}

	public static function render_account_packages() {
		$user_id = get_current_user_id();
		$passes = get_posts(array('post_type' => self::PASS_POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_luna_package_user_id', 'meta_value' => $user_id, 'orderby' => 'date', 'order' => 'DESC'));
		echo '<section class="luna-account-packages"><header><span>برنامه‌های فعال من</span><h2>پکیج‌های خدماتی</h2><p>جلسات خریداری‌شده و مانده هر پکیج را در این بخش مشاهده کنید.</p></header>';
		if (! $passes) { echo '<div class="luna-account-packages__empty">هنوز پکیج خدماتی خریداری نکرده‌اید. <a href="' . esc_url(self::page_url()) . '">مشاهده پکیج‌ها</a></div></section>'; return; }
		echo '<div class="luna-account-packages__grid">';
		foreach ($passes as $pass) {
			$package_id = absint(get_post_meta($pass->ID, '_luna_package_id', true)); $data = self::package_data($package_id); $remaining = absint(get_post_meta($pass->ID, '_luna_package_remaining_sessions', true)); $total = max(1, absint(get_post_meta($pass->ID, '_luna_package_total_sessions', true))); $progress = max(0, min(100, (($total - $remaining) / $total) * 100));
			$order_id = absint(get_post_meta($pass->ID, '_luna_package_order_id', true)); $expires = (string) get_post_meta($pass->ID, '_luna_package_expires_at', true); $expiry_label = $expires && class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_db_datetime_jalali($expires) : ($expires ?: 'بدون انقضا');
			$schedule = self::pass_schedule($pass->ID, $total); $next = self::next_session($schedule);
			echo '<article style="--package-accent:' . esc_attr($data['accent']) . '"><span class="icon">' . self::icon($data['category']) . '</span><div class="copy"><small>' . esc_html(self::category_label($data['category'])) . '</small><h3>' . esc_html(get_the_title($package_id)) . '</h3><div class="luna-account-packages__meta"><span>شناسه پکیج <strong>#' . esc_html($pass->ID) . '</strong></span><span>شماره سفارش <strong>#' . esc_html($order_id ?: '—') . '</strong></span><span>اعتبار <strong>' . esc_html($expiry_label) . '</strong></span></div><p><strong>' . esc_html($remaining) . '</strong> جلسه از ' . esc_html($total) . ' جلسه باقی مانده</p><div class="bar"><i style="width:' . esc_attr($progress) . '%"></i></div>';
			if ($next) echo '<div class="luna-account-packages__next"><small>جلسه بعدی</small><strong>' . esc_html(self::session_label($next)) . '</strong></div>';
			echo '<ol class="luna-account-packages__sessions">';
			foreach ($schedule as $index => $session) {
				echo '<li class="is-' . esc_attr($session['status']) . '"><span>جلسه ' . esc_html($index + 1) . '</span><strong>' . esc_html($session['date'] && $session['time'] ? self::session_label($session) : 'زمان مراجعه تعیین نشده') . '</strong><small>' . esc_html(self::session_status_label($session['status'])) . '</small></li>';
			}
			echo '</ol></div><span class="status">' . esc_html(self::status_label(get_post_meta($pass->ID, '_luna_package_status', true))) . '</span></article>';
		}
		echo '</div></section>';
	}

	public static function render_landing() {
		$packages = get_posts(array('post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => array('menu_order' => 'ASC', 'date' => 'ASC')));
		$categories = array();
		foreach ($packages as $package) {
			$package_data = self::package_data($package->ID);
			$categories[ $package_data['category'] ] = $package_data['category_name'];
		}
		ob_start();
		?>
		<main class="luna-package-store" dir="<?php echo esc_attr(self::direction()); ?>">
			<div class="luna-package-store__container">
			<header class="luna-package-hero">
				<span class="luna-package-kicker">برنامه‌ریزی برای حال خوب شما</span>
				<h1>پکیج‌های خدماتی <em>لونا</em></h1>
				<p>خدمت موردنظر را به‌صورت یک دوره منظم انتخاب کنید؛ تعداد جلسات شما پس از پرداخت فعال می‌شود و برای هر مراجعه با مرکز هماهنگ خواهید شد.</p>
				<div class="luna-package-hero__trust"><span>قیمت شفاف</span><span>فعال‌سازی پس از پرداخت</span><span>پیگیری جلسات باقی‌مانده</span></div>
			</header>
			<nav class="luna-package-filter" aria-label="فیلتر پکیج‌ها">
				<button type="button" class="is-active" data-package-filter="all">همه پکیج‌ها</button>
				<?php foreach ($categories as $category_slug => $category_name) : ?>
					<button type="button" data-package-filter="<?php echo esc_attr($category_slug); ?>"><?php echo esc_html($category_name); ?></button>
				<?php endforeach; ?>
			</nav>
			<div class="luna-package-sections">
			<?php foreach ($categories as $category => $heading) : ?>
				<section class="luna-package-group" data-package-group="<?php echo esc_attr($category); ?>">
					<div class="luna-package-group__heading"><span><?php echo self::icon($category); ?></span><div><small><?php echo 'massage' === $category ? 'آرامش و ریکاوری' : ('laser' === $category ? 'مراقبت منظم و دقیق' : 'برنامه تخصصی لونا'); ?></small><h2><?php echo esc_html($heading); ?></h2></div></div>
					<div class="luna-package-grid">
					<?php foreach ($packages as $package) : $data = self::package_data($package->ID); if ($category !== $data['category']) continue; echo self::card($package, $data); endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
			</div>
			</div>
		</main>
		<?php
		return ob_get_clean();
	}

	public static function render_single($package_id) {
		$data = self::package_data($package_id);
		ob_start();
		?>
		<main class="luna-package-single" dir="<?php echo esc_attr(self::direction()); ?>" style="--package-accent:<?php echo esc_attr($data['accent']); ?>">
			<div class="luna-package-single__container">
			<a class="luna-package-back" href="<?php echo esc_url(self::page_url()); ?>">← بازگشت به همه پکیج‌ها</a>
			<section class="luna-package-single__hero">
				<div class="luna-package-single__art"><span><?php echo self::icon($data['category']); ?></span><b><?php echo esc_html(self::sessions_label($data['sessions'])); ?></b></div>
				<div class="luna-package-single__copy"><span class="luna-package-kicker"><?php echo esc_html($data['category_name']); ?></span><h1><?php echo esc_html(get_the_title($package_id)); ?></h1><p><?php echo esc_html(get_the_excerpt($package_id)); ?></p><div class="luna-package-single__facts"><span><small>تعداد جلسات</small><strong><?php echo esc_html(self::sessions_label($data['sessions'])); ?></strong></span><span><small>نوع دریافت</small><strong>مراجعه حضوری</strong></span><span><small>اعتبار</small><strong><?php echo $data['validity_days'] ? esc_html($data['validity_days'] . ' روز') : 'بدون انقضا'; ?></strong></span></div><div class="luna-package-single__price"><?php echo wp_kses_post(self::price($data['price'])); ?></div><?php echo self::buy_form($package_id, 'خرید این پکیج'); ?></div>
			</section>
			<section class="luna-package-single__content"><div><?php echo wp_kses_post(apply_filters('the_content', get_post_field('post_content', $package_id))); ?></div><aside><strong>بعد از خرید چه می‌شود؟</strong><ol><li>سفارش و پرداخت ثبت می‌شود.</li><li>پکیج با تعداد جلسات کامل برای شما فعال می‌شود.</li><li>برای هماهنگی زمان هر جلسه با مرکز در ارتباط خواهید بود.</li></ol></aside></section>
			</div>
		</main>
		<?php
		return ob_get_clean();
	}

	private static function card($post, $data) {
		return '<article class="luna-package-card' . ('yes' === $data['featured'] ? ' is-featured' : '') . '" style="--package-accent:' . esc_attr($data['accent']) . '"><div class="luna-package-card__top"><span class="luna-package-card__icon">' . self::icon($data['category']) . '</span><span class="luna-package-card__sessions">' . esc_html(self::sessions_label($data['sessions'])) . '</span></div><small>' . esc_html($data['category_name']) . '</small><h3><a href="' . esc_url(get_permalink($post)) . '">' . esc_html($post->post_title) . '</a></h3><p>' . esc_html($post->post_excerpt) . '</p><div class="luna-package-card__bottom"><strong>' . wp_kses_post(self::price($data['price'])) . '</strong><a href="' . esc_url(get_permalink($post)) . '">جزئیات و خرید ←</a></div></article>';
	}

	private static function buy_form($package_id, $label) {
		return '<form class="luna-package-buy" method="post"><input type="hidden" name="luna_buy_service_package" value="1"><input type="hidden" name="package_id" value="' . esc_attr($package_id) . '"><input type="hidden" name="luna_package_buy_nonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION . '_' . $package_id)) . '"><button type="submit">' . esc_html($label) . '<span aria-hidden="true">←</span></button></form>';
	}

	public static function enqueue_assets() {
		if (is_singular(self::POST_TYPE) || is_page(self::PAGE_SLUG) || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'luna_service_packages') || (function_exists('is_cart') && (is_cart() || is_checkout())) || (function_exists('is_account_page') && is_account_page())) {
			wp_enqueue_style('luna-service-packages', LUNA_APPOINTMENTS_URL . 'assets/service-packages.css', array(), LUNA_APPOINTMENTS_VERSION);
			wp_enqueue_script('luna-service-packages', LUNA_APPOINTMENTS_URL . 'assets/service-packages.js', array(), LUNA_APPOINTMENTS_VERSION, true);
		}
	}

	public static function single_template($template) {
		if (is_page(self::PAGE_SLUG)) {
			$candidate = LUNA_APPOINTMENTS_PATH . 'templates/page-service-packages.php';
			if (file_exists($candidate)) return $candidate;
		}
		if (is_singular(self::POST_TYPE)) {
			$candidate = LUNA_APPOINTMENTS_PATH . 'templates/single-service-package.php';
			if (file_exists($candidate)) return $candidate;
		}
		return $template;
	}

	public static function add_primary_menu_link($items, $args) {
		if (empty($args->theme_location) || 'primary' !== $args->theme_location || false !== strpos($items, '/' . self::PAGE_SLUG)) return $items;
		return $items . '<li class="menu-item menu-item-service-packages"><a href="' . esc_url(self::page_url()) . '">پکیج‌ها</a></li>';
	}

	private static function pass_schedule($pass_id, $total) {
		$stored = get_post_meta($pass_id, '_luna_package_schedule', true);
		$stored = is_array($stored) ? array_values($stored) : array();
		$result = array();
		for ($index = 0; $index < max(1, absint($total)); $index++) {
			$row = isset($stored[$index]) && is_array($stored[$index]) ? $stored[$index] : array();
			$date = isset($row['date']) && class_exists('Luna_Appointments_Date') && Luna_Appointments_Date::parse_date($row['date']) ? $row['date'] : '';
			$time = isset($row['time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $row['time']) ? (string) $row['time'] : '';
			$status = sanitize_key($row['status'] ?? 'pending');
			if (! in_array($status, array('pending', 'scheduled', 'completed', 'cancelled'), true)) $status = 'pending';
			$result[] = array('date' => $date, 'time' => $time, 'status' => $status);
		}
		return $result;
	}

	private static function next_session($schedule) {
		$now = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::now()->getTimestamp() : current_time('timestamp');
		$next = null;
		$next_stamp = PHP_INT_MAX;
		foreach ((array) $schedule as $session) {
			if ('scheduled' !== ($session['status'] ?? '') || empty($session['date']) || empty($session['time'])) continue;
			$stamp = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::timestamp($session['date'], $session['time']) : strtotime($session['date'] . ' ' . $session['time']);
			if ($stamp >= $now && $stamp < $next_stamp) { $next = $session; $next_stamp = $stamp; }
		}
		return $next;
	}

	private static function session_label($session) {
		if (empty($session['date'])) return 'زمان مراجعه تعیین نشده';
		return class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($session['date'], $session['time'] ?? '', true) : trim($session['date'] . ' ' . ($session['time'] ?? ''));
	}

	private static function session_status_label($status) {
		return array('pending' => 'در انتظار تعیین زمان', 'scheduled' => 'زمان‌بندی‌شده', 'completed' => 'انجام‌شده', 'cancelled' => 'لغوشده')[$status] ?? 'در انتظار تعیین زمان';
	}

	private static function package_data($post_id) {
		$terms = wp_get_object_terms((int) $post_id, self::TAXONOMY, array('orderby' => 'term_order', 'order' => 'ASC'));
		$term = ! is_wp_error($terms) && ! empty($terms[0]) && $terms[0] instanceof WP_Term ? $terms[0] : null;
		$category = $term ? $term->slug : sanitize_title((string) get_post_meta($post_id, '_luna_package_category', true));
		if (! $category) {
			$category = 'massage';
		}
		return array(
			'category' => $category,
			'category_name' => $term ? $term->name : self::category_label($category),
			'service_name' => (string) get_post_meta($post_id, '_luna_package_service_name', true),
			'sessions' => max(1, absint(get_post_meta($post_id, '_luna_package_sessions', true))),
			'price' => max(0, (float) get_post_meta($post_id, '_luna_package_price', true)),
			'validity_days' => absint(get_post_meta($post_id, '_luna_package_validity_days', true)),
			'accent' => sanitize_hex_color(get_post_meta($post_id, '_luna_package_accent', true)) ?: '#6f7f4b',
			'featured' => 'yes' === get_post_meta($post_id, '_luna_package_featured', true) ? 'yes' : 'no',
		);
	}

	private static function language() { return function_exists('luna_current_language') ? luna_current_language() : (0 === strpos((string) get_locale(), 'ar') ? 'ar' : (0 === strpos((string) get_locale(), 'en') ? 'en' : 'fa')); }
	private static function direction() { return in_array(self::language(), array('fa', 'ar'), true) ? 'rtl' : 'ltr'; }
	private static function page_url() { return function_exists('luna_language_url') ? luna_language_url(self::PAGE_SLUG . '/') : home_url('/' . self::PAGE_SLUG . '/'); }
	private static function sessions_label($sessions) { $count=absint($sessions); if('en'===self::language())return 1===$count?'Single session':$count.' sessions';if('ar'===self::language())return 1===$count?'جلسة واحدة':$count.' جلسات';return 1===$count?'تک جلسه':$count.' جلسه'; }
	private static function category_label($category) { $term=get_term_by('slug',sanitize_title($category),self::TAXONOMY);if($term instanceof WP_Term)return $term->name;$labels=array('fa'=>array('laser'=>'لیزر','massage'=>'ماساژ'),'en'=>array('laser'=>'Laser','massage'=>'Massage'),'ar'=>array('laser'=>'ليزر','massage'=>'تدليك'));$set=$labels[self::language()]??$labels['fa'];return $set[$category]??ucwords(str_replace(array('-','_'),' ',(string)$category)); }
	private static function status_label($status) { $labels=array('fa'=>array('active'=>'فعال','used'=>'تکمیل‌شده','expired'=>'منقضی','cancelled'=>'لغوشده'),'en'=>array('active'=>'Active','used'=>'Completed','expired'=>'Expired','cancelled'=>'Cancelled'),'ar'=>array('active'=>'نشطة','used'=>'مكتملة','expired'=>'منتهية','cancelled'=>'ملغاة'));$set=$labels[self::language()]??$labels['fa'];return $set[$status]??$set['active']; }
	private static function price($amount) { return function_exists('wc_price') ? wc_price($amount) : number_format_i18n($amount) . ' تومان'; }
	private static function icon($category) {
		return 'laser' === $category
			? '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 5v10M32 49v10M5 32h10M49 32h10M13 13l7 7M44 44l7 7M51 13l-7 7M20 44l-7 7"/><circle cx="32" cy="32" r="11"/><circle cx="32" cy="32" r="3"/></svg>'
			: '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M13 36c5-9 12-14 19-14 8 0 12 5 19 14M10 43c8 3 14 4 22 4s15-1 22-4M20 20c3-7 7-11 12-11s9 4 12 11"/><path d="M19 36c3-5 7-8 13-8s10 3 13 8"/></svg>';
	}
}
