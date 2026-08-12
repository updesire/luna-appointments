<?php
/**
 * Installable mobile workspace for Luna specialists.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) exit;

class Luna_Appointments_Specialist_PWA {
	const APP_SLUG = 'specialist-app';
	const STATUS_NONCE = 'luna_specialist_app_status';
	const CARE_NONCE = 'luna_specialist_app_care';

	public static function boot() {
		add_action('init', array(__CLASS__, 'register_routes'));
		add_filter('query_vars', array(__CLASS__, 'query_vars'));
		add_action('template_redirect', array(__CLASS__, 'route_request'), 0);
		add_action('wp_ajax_luna_specialist_app_booking_status', array(__CLASS__, 'ajax_booking_status'));
		add_action('wp_ajax_luna_specialist_app_save_care', array(__CLASS__, 'ajax_save_care'));
		add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 30, 3);
	}

	public static function register_routes() {
		add_rewrite_rule('^' . self::APP_SLUG . '/?$', 'index.php?luna_specialist_app=1', 'top');
		add_rewrite_rule('^' . self::APP_SLUG . '/manifest\.webmanifest$', 'index.php?luna_specialist_manifest=1', 'top');
		add_rewrite_rule('^' . self::APP_SLUG . '/service-worker\.js$', 'index.php?luna_specialist_sw=1', 'top');
		if ('1' !== get_option('luna_specialist_pwa_routes_v2')) {
			flush_rewrite_rules(false);
			update_option('luna_specialist_pwa_routes_v2', '1', false);
		}
	}

	public static function query_vars($vars) {
		$vars[] = 'luna_specialist_app';
		$vars[] = 'luna_specialist_manifest';
		$vars[] = 'luna_specialist_sw';
		return $vars;
	}

	public static function route_request() {
		$route = self::requested_app_route();
		if (get_query_var('luna_specialist_manifest') || 'manifest.webmanifest' === $route) self::render_manifest();
		if (get_query_var('luna_specialist_sw') || 'service-worker.js' === $route) self::render_service_worker();
		if (! get_query_var('luna_specialist_app') && '' !== $route) return;
		if (! get_query_var('luna_specialist_app') && null === $route) return;
		self::handle_login();
		self::render_app();
		exit;
	}

	/**
	 * Resolve the PWA route without relying solely on the saved permalink rules.
	 * This keeps the app reachable after a domain/subdirectory migration.
	 *
	 * @return string|null Empty string for app root, asset filename, or null.
	 */
	protected static function requested_app_route() {
		$request_path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);
		$home_path    = wp_parse_url(home_url('/'), PHP_URL_PATH);
		$request_path = '/' . ltrim((string) $request_path, '/');
		$home_path    = '/' . trim((string) $home_path, '/');
		if ('/' !== $home_path && 0 === strpos($request_path, trailingslashit($home_path))) {
			$request_path = '/' . ltrim(substr($request_path, strlen($home_path)), '/');
		}
		$prefix = '/' . self::APP_SLUG;
		if ($request_path === $prefix || $request_path === $prefix . '/') return '';
		if ($request_path === $prefix . '/manifest.webmanifest') return 'manifest.webmanifest';
		if ($request_path === $prefix . '/service-worker.js') return 'service-worker.js';
		return null;
	}

	protected static function handle_login() {
		if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) || empty($_POST['luna_specialist_login'])) return;
		if (! isset($_POST['luna_specialist_login_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_specialist_login_nonce'])), 'luna_specialist_app_login')) {
			$GLOBALS['luna_specialist_login_error'] = 'درخواست ورود معتبر نیست؛ لطفاً دوباره تلاش کنید.';
			return;
		}
		$credentials = array(
			'user_login'    => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
			'user_password' => (string) wp_unslash($_POST['pwd'] ?? ''),
			'remember'      => ! empty($_POST['rememberme']),
		);
		$user = wp_signon($credentials, is_ssl());
		if (is_wp_error($user)) {
			$GLOBALS['luna_specialist_login_error'] = 'نام کاربری یا رمز عبور صحیح نیست.';
			return;
		}
		if (! in_array(Luna_Appointments_Specialists::ROLE, (array) $user->roles, true) || Luna_Appointments_Specialists::get_linked_specialist_id($user->ID) <= 0) {
			wp_logout();
			$GLOBALS['luna_specialist_login_error'] = 'این پنل فقط برای حساب متخصصان فعال است.';
			return;
		}
		wp_safe_redirect(self::app_url());
		exit;
	}

	protected static function render_app() {
		nocache_headers();
		if (! Luna_Appointments_Specialists::current_user_is_specialist()) {
			self::document_start('ورود متخصصان', 'login');
			self::render_login();
			self::document_end();
			return;
		}

		$user          = wp_get_current_user();
		$specialist_id = Luna_Appointments_Specialists::get_current_user_specialist_id();
		$specialist    = get_post($specialist_id);
		$bookings      = self::bookings($specialist_id);
		$today         = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_today() : current_datetime()->format('Y-m-d');
		$today_items   = array_values(array_filter($bookings, function($item) use ($today) { return (string) ($item['booking_date'] ?? '') === $today; }));
		$upcoming      = array_values(array_filter($bookings, function($item) use ($today) { return (string) ($item['booking_date'] ?? '') >= $today && ! in_array((string) ($item['status'] ?? ''), array('cancelled', 'completed', 'done'), true); }));
		$customers     = self::customers($bookings);
		$orders        = self::orders($bookings);
		$care_plans    = self::care_plans($specialist_id, $bookings);
		$avatar        = get_the_post_thumbnail_url($specialist_id, 'thumbnail');
		$name          = $specialist instanceof WP_Post ? $specialist->post_title : $user->display_name;
		$profile_meta  = get_post_meta($specialist_id);
		$working_days  = array_map('intval', (array) get_post_meta($specialist_id, '_luna_specialist_working_days', true));

		self::document_start('پنل متخصص ' . $name, 'app');
		?>
		<div class="lspwa-shell" dir="rtl" data-specialist-app>
			<header class="lspwa-topbar">
				<div class="lspwa-brand"><span class="lspwa-brandmark">L</span><div><b>Luna Pro</b><small>فضای کاری متخصص</small></div></div>
				<div class="lspwa-top-actions"><button type="button" class="lspwa-icon-button" data-install-app hidden aria-label="نصب اپلیکیشن">↧</button><a class="lspwa-avatar" href="#profile"><?php echo $avatar ? '<img src="' . esc_url($avatar) . '" alt="">' : esc_html(mb_substr($name, 0, 1)); ?></a></div>
			</header>

			<main class="lspwa-main">
				<section class="lspwa-view is-active" data-view="home">
					<div class="lspwa-greeting"><div><span><?php echo esc_html(self::day_greeting()); ?></span><h1><?php echo esc_html($name); ?></h1><p><?php echo esc_html(class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali(Luna_Appointments_Date::db_today(), '', true) : current_datetime()->format('Y-m-d')); ?></p></div><div class="lspwa-live"><i aria-hidden="true"></i> آنلاین</div></div>
					<div class="lspwa-stats"><article><span>امروز</span><strong><?php echo esc_html(number_format_i18n(count($today_items))); ?></strong><small>مراجعه</small></article><article><span>پیش رو</span><strong><?php echo esc_html(number_format_i18n(count($upcoming))); ?></strong><small>رزرو</small></article><article><span>مراجعان</span><strong><?php echo esc_html(number_format_i18n(count($customers))); ?></strong><small>نفر</small></article><article><span>پلن فعال</span><strong><?php echo esc_html(number_format_i18n(count($care_plans))); ?></strong><small>مراقبتی</small></article></div>
					<article class="lspwa-push-center" data-push-center><div><i aria-hidden="true"></i><span>مرکز اعلان هوشمند</span><h3>رزرو جدید را همان لحظه ببینید</h3><p data-push-status>در حال بررسی وضعیت اعلان‌های این دستگاه…</p></div><button type="button" data-enable-push>فعال‌سازی اعلان‌ها</button></article>
					<div class="lspwa-section-head"><div><span>برنامه امروز</span><h2>قرارهای نزدیک</h2></div><button data-open-view="bookings">مشاهده همه</button></div>
					<div class="lspwa-timeline"><?php echo self::booking_cards($today_items ?: array_slice($upcoming, 0, 4), true); ?></div>
				</section>

				<section class="lspwa-view" data-view="bookings">
					<div class="lspwa-page-title"><div><span>مدیریت برنامه</span><h1>رزروهای من</h1></div><input type="search" aria-label="جست‌وجوی مشتری یا خدمت" placeholder="جست‌وجوی مشتری یا خدمت" data-list-search="bookings"></div>
					<div class="lspwa-filter"><button class="is-active" data-filter-status="all">همه</button><button data-filter-status="confirmed">تأییدشده</button><button data-filter-status="completed">انجام‌شده</button><button data-filter-status="cancelled">لغوشده</button></div>
					<div class="lspwa-booking-list" data-search-list="bookings"><?php echo self::booking_cards($bookings, false); ?></div>
				</section>

				<section class="lspwa-view" data-view="customers">
					<div class="lspwa-page-title"><div><span>پرونده مراجعان</span><h1>مشتریان من</h1></div><input type="search" aria-label="جست‌وجوی نام یا تلفن" placeholder="جست‌وجوی نام یا تلفن" data-list-search="customers"></div>
					<div class="lspwa-customer-list" data-search-list="customers"><?php echo self::customer_cards($customers); ?></div>
				</section>

				<section class="lspwa-view" data-view="orders">
					<div class="lspwa-page-title"><div><span>پرداخت‌ها و خدمات</span><h1>سفارش‌های مرتبط</h1></div></div>
					<div class="lspwa-order-list"><?php echo self::order_cards($orders); ?></div>
				</section>

				<section class="lspwa-view" data-view="care">
					<div class="lspwa-page-title"><div><span>مراقبت پس از خدمت</span><h1>پلن‌های مراقبتی</h1></div></div>
					<div class="lspwa-care-layout">
						<form class="lspwa-form lspwa-care-form" data-care-form>
							<div class="lspwa-form-head"><div><span>پلن جدید یا ویرایش پلن موجود</span><h2>تنظیم مراقبت و یادآوری</h2></div><i aria-hidden="true">فقط مراجعان خودتان</i></div>
							<label><span>مراجعه مرتبط</span><select name="booking_id" required><option value="">انتخاب مراجعه</option><?php foreach ($bookings as $booking) : if (empty($booking['customer_user_id']) || 'cancelled' === ($booking['status'] ?? '')) continue; ?><option value="<?php echo esc_attr($booking['id']); ?>"><?php echo esc_html(($booking['customer_name'] ?? 'مشتری') . ' — ' . ($booking['service_name'] ?? 'خدمت') . ' — ' . self::short_date($booking['booking_date'] ?? '')); ?></option><?php endforeach; ?></select><small>اگر برای این مراجعه قبلاً پلن ساخته شده باشد، همان پلن به‌روزرسانی می‌شود.</small></label>
							<label><span>خلاصه شخصی‌سازی‌شده</span><textarea name="summary" rows="3" required placeholder="وضعیت مراجعه و هدف برنامه مراقبتی"></textarea></label>
							<label><span>دستورهای مراقبتی</span><textarea name="instructions" rows="6" required placeholder="هر دستور را در یک خط بنویسید"></textarea></label>
							<div class="lspwa-form-grid"><label><span>تاریخ مراجعه بعدی</span><input type="date" name="next_date" required></label><label><span>ارسال یادآوری</span><select name="reminder_days"><option value="1">یک روز قبل</option><option value="2" selected>دو روز قبل</option><option value="3">سه روز قبل</option><option value="7">یک هفته قبل</option></select></label></div>
							<button class="lspwa-primary" type="submit">ذخیره و فعال‌سازی یادآوری <span>←</span></button><p class="lspwa-form-message" aria-live="polite"></p>
						</form>
						<div class="lspwa-care-list" data-care-list><?php echo self::care_plan_cards($care_plans); ?></div>
					</div>
				</section>

				<section class="lspwa-view" data-view="profile" id="profile">
					<div class="lspwa-profile-hero"><?php echo $avatar ? '<img src="' . esc_url($avatar) . '" alt="">' : '<span>' . esc_html(mb_substr($name, 0, 1)) . '</span>'; ?><h1><?php echo esc_html($name); ?></h1><p><?php echo esc_html($user->user_email); ?></p></div>
					<form class="lspwa-form lspwa-profile-form" data-profile-form enctype="multipart/form-data">
						<div class="lspwa-form-head"><div><span>اطلاعات قابل ویرایش</span><h2>پروفایل حرفه‌ای من</h2></div><i aria-hidden="true">امتیاز و سطح دسترسی فقط توسط مدیر</i></div>
						<div class="lspwa-form-grid"><label><span>نام نمایشی</span><input name="display_name" value="<?php echo esc_attr($name); ?>" required></label><label><span>ایمیل ورود</span><input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" required></label><label><span>عنوان شغلی</span><input name="specialist_role" value="<?php echo esc_attr(self::meta_value($profile_meta, '_luna_specialist_role')); ?>"></label><label><span>تصویر پروفایل</span><input type="file" name="profile_image" accept="image/*"></label></div>
						<label><span>بیوگرافی</span><textarea name="specialist_bio" rows="4"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_bio')); ?></textarea></label>
						<div class="lspwa-form-grid"><label><span>سوابق و تجربه</span><textarea name="specialist_history" rows="5"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_history')); ?></textarea></label><label><span>مدارک و گواهی‌ها</span><textarea name="specialist_certifications" rows="5"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_certifications')); ?></textarea></label></div>
						<label><span>برچسب‌های تخصص</span><input name="specialist_tags" value="<?php echo esc_attr(self::meta_value($profile_meta, '_luna_specialist_tags')); ?>" placeholder="مثلاً پوست، جوان‌سازی، فیشیال"></label>
						<div class="lspwa-weekdays"><span>روزهای کاری</span><?php foreach (array('شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه') as $day => $day_label) : ?><label><input type="checkbox" name="working_days[]" value="<?php echo esc_attr($day); ?>" <?php checked(in_array($day, $working_days, true)); ?>><i><?php echo esc_html($day_label); ?></i></label><?php endforeach; ?></div>
						<div class="lspwa-form-grid"><label><span>شروع ساعت کاری</span><input type="time" name="working_start" value="<?php echo esc_attr(self::meta_value($profile_meta, '_luna_specialist_working_start', '10:00')); ?>"></label><label><span>پایان ساعت کاری</span><input type="time" name="working_end" value="<?php echo esc_attr(self::meta_value($profile_meta, '_luna_specialist_working_end', '20:00')); ?>"></label></div>
						<label><span>تاریخ‌های تعطیل</span><textarea name="off_dates" rows="3" placeholder="هر تاریخ در یک خط"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_off_dates')); ?></textarea></label><label><span>بازه‌های مرخصی</span><textarea name="leave_ranges" rows="3"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_leave_ranges')); ?></textarea></label><label><span>ساعت‌های مسدود</span><textarea name="blocked_slots" rows="3"><?php echo esc_textarea(self::meta_value($profile_meta, '_luna_specialist_blocked_slots')); ?></textarea></label>
						<button class="lspwa-primary" type="submit">ذخیره تغییرات پروفایل <span>←</span></button><p class="lspwa-form-message" aria-live="polite"></p>
					</form>
					<div class="lspwa-settings"><button type="button" data-install-app><span>نصب روی موبایل</span><i aria-hidden="true">نسخه سریع و تمام‌صفحه</i></button><a href="<?php echo esc_url(get_permalink($specialist_id)); ?>"><span>پروفایل عمومی من</span><i aria-hidden="true">مشاهده در سایت</i></a><a class="is-danger" href="<?php echo esc_url(wp_logout_url(self::app_url())); ?>"><span>خروج امن</span><i aria-hidden="true">خروج از حساب متخصص</i></a></div>
				</section>
			</main>

			<nav class="lspwa-tabbar" aria-label="منوی اپلیکیشن"><button type="button" class="is-active" data-nav-view="home"><i aria-hidden="true">⌂</i><span>خانه</span></button><button type="button" data-nav-view="bookings"><i aria-hidden="true">◷</i><span>رزروها</span></button><button type="button" data-nav-view="customers"><i aria-hidden="true">♙</i><span>مشتریان</span></button><button type="button" data-nav-view="orders"><i aria-hidden="true">◇</i><span>سفارش‌ها</span></button><button type="button" data-nav-view="care"><i aria-hidden="true">♡</i><span>مراقبت</span></button><button type="button" data-nav-view="profile"><i aria-hidden="true">○</i><span>پروفایل</span></button></nav>
			<div class="lspwa-toast" role="status" aria-live="polite"></div>
		</div>
		<script>window.LunaSpecialistApp=<?php echo wp_json_encode(array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(self::STATUS_NONCE), 'careNonce' => wp_create_nonce(self::CARE_NONCE), 'financeNonce' => class_exists('Luna_Appointments_Consultation_Finance') ? wp_create_nonce(Luna_Appointments_Consultation_Finance::specialist_nonce_action()) : '', 'profileNonce' => wp_create_nonce(Luna_Appointments_Specialists::PROFILE_NONCE_ACTION), 'pushNonce' => wp_create_nonce(class_exists('Luna_Notifications_API') ? Luna_Notifications_API::nonce_action() : 'luna_specialist_push_subscription'), 'vapidPublicKey' => class_exists('Luna_Notifications_API') ? Luna_Notifications_API::public_key() : '', 'swUrl' => self::app_url('service-worker.js'), 'language' => 'fa')); ?>;</script>
		<?php
		self::document_end();
	}

	protected static function render_login() {
		$error = (string) ($GLOBALS['luna_specialist_login_error'] ?? '');
		?>
		<main class="lspwa-login" dir="rtl"><section class="lspwa-login-art"><div class="lspwa-orb"></div><div class="lspwa-login-brand"><span>L</span><b>Luna Professional</b></div><div><small>فضای کاری اختصاصی</small><h1>روز کاری شما،<br>شفاف و آرام.</h1><p>رزروها، مراجعان و سفارش‌های مرتبط را از یک فضای امن مدیریت کنید.</p></div><div class="lspwa-login-chips"><span>امن</span><span>سریع</span><span>قابل نصب</span></div></section><section class="lspwa-login-form"><form method="post" action="<?php echo esc_url(self::app_url()); ?>"><span class="lspwa-mobile-logo">Luna Pro</span><small>ورود متخصصان</small><h2>خوش آمدید</h2><p>برای ورود به فضای کاری، اطلاعات حساب متخصص خود را وارد کنید.</p><?php if ($error) : ?><div class="lspwa-login-error"><?php echo esc_html($error); ?></div><?php endif; ?><?php wp_nonce_field('luna_specialist_app_login', 'luna_specialist_login_nonce'); ?><input type="hidden" name="luna_specialist_login" value="1"><label><span>نام کاربری یا ایمیل</span><input name="log" type="text" autocomplete="username" required></label><label><span>رمز عبور</span><div class="lspwa-password"><input name="pwd" type="password" autocomplete="current-password" required><button type="button" data-toggle-password>نمایش</button></div></label><div class="lspwa-login-row"><label class="lspwa-check"><input type="checkbox" name="rememberme" value="1"><span>مرا به خاطر بسپار</span></label><a href="<?php echo esc_url(wp_lostpassword_url(self::app_url())); ?>">فراموشی رمز</a></div><button class="lspwa-login-submit" type="submit">ورود به فضای کاری <span>←</span></button><em>دسترسی فقط برای متخصصان تأییدشده لونا</em></form></section></main>
		<?php
	}

	protected static function document_start($title, $mode) {
		$css = LUNA_APPOINTMENTS_URL . 'assets/specialist-app/specialist-app.css';
		?><!doctype html><html <?php language_attributes(); ?> dir="rtl"><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f4ede8"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="Luna Pro"><meta name="robots" content="noindex,nofollow"><link rel="manifest" href="<?php echo esc_url(self::app_url('manifest.webmanifest')); ?>"><link rel="icon" href="<?php echo esc_url(LUNA_APPOINTMENTS_URL . 'assets/specialist-app/icon.svg'); ?>"><link rel="apple-touch-icon" href="<?php echo esc_url(get_site_icon_url(180, LUNA_APPOINTMENTS_URL . 'assets/specialist-app/icon.svg')); ?>"><link rel="stylesheet" href="<?php echo esc_url($css . '?v=' . rawurlencode((string) @filemtime(LUNA_APPOINTMENTS_PATH . 'assets/specialist-app/specialist-app.css'))); ?>"><link rel="stylesheet" href="<?php echo esc_url(LUNA_APPOINTMENTS_URL . 'assets/consultation-finance.css?v=' . rawurlencode(LUNA_APPOINTMENTS_VERSION)); ?>"><title><?php echo esc_html($title); ?></title></head><body class="lspwa-body lspwa-body--<?php echo esc_attr($mode); ?>"><?php
	}

	protected static function document_end() {
		$js = LUNA_APPOINTMENTS_URL . 'assets/specialist-app/specialist-app.js';
		?><script src="<?php echo esc_url($js . '?v=' . rawurlencode((string) @filemtime(LUNA_APPOINTMENTS_PATH . 'assets/specialist-app/specialist-app.js'))); ?>"></script></body></html><?php
	}

	protected static function bookings($specialist_id) {
		if (! class_exists('Luna_Appointments_Bookings_Table')) return array();
		$result = Luna_Appointments_Bookings_Table::query_bookings_for_specialist($specialist_id, array('per_page' => 250));
		return isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
	}

	protected static function booking_cards($items, $compact) {
		if (! $items) return '<div class="lspwa-empty"><b>برنامه‌ای ثبت نشده است</b><span>قرارهای جدید در این بخش نمایش داده می‌شوند.</span></div>';
		$html = '';
		foreach ($items as $item) {
			$status = sanitize_key((string) ($item['status'] ?? 'pending'));
			$search = implode(' ', array($item['customer_name'] ?? '', $item['customer_phone'] ?? '', $item['service_name'] ?? ''));
			$finance_summary = class_exists('Luna_Appointments_Consultation_Finance') ? Luna_Appointments_Consultation_Finance::frontend_summary_markup((int) ($item['id'] ?? 0), true, false) : '';
			$finance_form    = ! $compact && class_exists('Luna_Appointments_Consultation_Finance') ? Luna_Appointments_Consultation_Finance::specialist_proposal_markup((int) ($item['id'] ?? 0)) : '';
			$html .= '<article class="lspwa-booking-card" data-status="' . esc_attr($status) . '" data-search="' . esc_attr($search) . '"><time><b>' . esc_html(substr((string) ($item['booking_time'] ?? ''), 0, 5)) . '</b><span>' . esc_html(self::short_date((string) ($item['booking_date'] ?? ''))) . '</span></time><div class="lspwa-booking-copy"><span>' . esc_html($item['service_name'] ?? 'خدمت') . '</span><h3>' . esc_html($item['customer_name'] ?? 'مشتری') . '</h3><p>' . esc_html($item['customer_phone'] ?? '') . (! empty($item['wc_order_number']) ? ' · سفارش #' . esc_html($item['wc_order_number']) : '') . '</p>' . $finance_summary . '</div><i class="lspwa-status lspwa-status--' . esc_attr($status) . '">' . esc_html(self::status_label($status)) . '</i>';
			if ($finance_form) $html .= '<div class="lspwa-booking-finance">' . $finance_form . '</div>';
			if (! $compact && ! in_array($status, array('completed', 'done', 'cancelled'), true)) $html .= '<div class="lspwa-booking-actions"><button data-booking-status="completed" data-booking-id="' . esc_attr($item['id']) . '">انجام شد</button><a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', (string) ($item['customer_phone'] ?? ''))) . '">تماس</a></div>';
			$html .= '</article>';
		}
		return $html;
	}

	protected static function customers($bookings) {
		$customers = array();
		foreach ($bookings as $item) {
			$key = ! empty($item['customer_user_id']) ? 'u' . (int) $item['customer_user_id'] : strtolower((string) ($item['customer_phone'] ?? $item['customer_email'] ?? ''));
			if (! $key) continue;
			if (! isset($customers[$key])) $customers[$key] = array('name' => $item['customer_name'] ?? 'مشتری', 'phone' => $item['customer_phone'] ?? '', 'email' => $item['customer_email'] ?? '', 'visits' => 0, 'last' => '');
			$customers[$key]['visits']++;
			if ((string) ($item['booking_date'] ?? '') > $customers[$key]['last']) $customers[$key]['last'] = (string) $item['booking_date'];
		}
		return array_values($customers);
	}

	protected static function customer_cards($customers) {
		if (! $customers) return '<div class="lspwa-empty"><b>هنوز مراجعه‌کننده‌ای ندارید</b><span>مشتریان رزروهای شما اینجا قرار می‌گیرند.</span></div>';
		$html = '';
		foreach ($customers as $customer) {
			$search = implode(' ', $customer);
			$html .= '<article class="lspwa-customer" data-search="' . esc_attr($search) . '"><span>' . esc_html(mb_substr((string) $customer['name'], 0, 1)) . '</span><div><h3>' . esc_html($customer['name']) . '</h3><p>' . esc_html($customer['phone'] ?: $customer['email']) . '</p></div><small>' . esc_html(number_format_i18n($customer['visits'])) . ' مراجعه<br>' . esc_html(self::short_date($customer['last'])) . '</small></article>';
		}
		return $html;
	}

	protected static function orders($bookings) {
		$orders = array();
		foreach ($bookings as $booking) {
			$order_id = (int) ($booking['wc_order_id'] ?? 0);
			if ($order_id > 0 && ! isset($orders[$order_id])) $orders[$order_id] = $booking;
		}
		return array_values($orders);
	}

	protected static function order_cards($orders) {
		if (! $orders) return '<div class="lspwa-empty"><b>سفارش مرتبطی وجود ندارد</b><span>سفارش‌های متصل به خدمات شما اینجا نمایش داده می‌شوند.</span></div>';
		$html = '';
		foreach ($orders as $order) $html .= '<article class="lspwa-order"><span>#' . esc_html($order['wc_order_number'] ?? $order['wc_order_id']) . '</span><div><h3>' . esc_html($order['customer_name'] ?? 'مشتری') . '</h3><p>' . esc_html($order['service_name'] ?? 'خدمت') . ' · ' . esc_html($order['wc_order_created'] ?? '') . '</p></div><strong>' . wp_kses_post($order['wc_order_total'] ?? '') . '<small>' . esc_html($order['wc_order_status_label'] ?? '') . '</small></strong></article>';
		return $html;
	}

	protected static function care_plan_cards($plans) {
		if (! $plans) return '<div class="lspwa-empty"><b>هنوز پلنی تنظیم نکرده‌اید</b><span>از فرم مقابل برای یکی از مراجعات خود برنامه مراقبتی بسازید.</span></div>';
		$html = '';
		foreach ($plans as $plan) {
			$data = Luna_Appointments_Care_Plans::data($plan->ID);
			$user = get_userdata($data['user_id']);
			$html .= '<article class="lspwa-care-card"><div><span>' . esc_html($data['service_id'] ? get_the_title($data['service_id']) : 'مراقبت اختصاصی') . '</span><h3>' . esc_html($user ? $user->display_name : $plan->post_title) . '</h3></div><p>' . esc_html($data['summary']) . '</p><footer><span>مراجعه بعدی</span><b>' . esc_html(self::short_date($data['next_date'])) . '</b><i aria-hidden="true">' . esc_html(number_format_i18n(count($data['instructions']))) . ' دستور</i></footer></article>';
		}
		return $html;
	}

	protected static function care_plans($specialist_id, $bookings) {
		$booking_ids = array_values(array_filter(array_map(function($booking) { return (int) ($booking['id'] ?? 0); }, $bookings)));
		$meta_query = array('relation' => 'OR', array('key' => '_luna_care_specialist_id', 'value' => $specialist_id));
		if ($booking_ids) $meta_query[] = array('key' => '_luna_care_booking_id', 'value' => $booking_ids, 'compare' => 'IN', 'type' => 'NUMERIC');
		$plans = get_posts(array('post_type' => Luna_Appointments_Care_Plans::TYPE, 'post_status' => 'publish', 'posts_per_page' => 50, 'meta_query' => $meta_query));
		foreach ($plans as $plan) {
			if (! (int) get_post_meta($plan->ID, '_luna_care_specialist_id', true)) update_post_meta($plan->ID, '_luna_care_specialist_id', $specialist_id);
		}
		return $plans;
	}

	public static function ajax_save_care() {
		check_ajax_referer(self::CARE_NONCE, 'nonce');
		if (! Luna_Appointments_Specialists::current_user_is_specialist()) wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
		$specialist_id = Luna_Appointments_Specialists::get_current_user_specialist_id();
		$booking_id = absint($_POST['booking_id'] ?? 0);
		$booking = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		if (! is_array($booking) || (int) ($booking['specialist_id'] ?? 0) !== $specialist_id) wp_send_json_error(array('message' => 'این مراجعه متعلق به شما نیست.'), 403);
		$user_id = (int) ($booking['customer_user_id'] ?? 0);
		if ($user_id <= 0) wp_send_json_error(array('message' => 'مراجع حساب کاربری فعال ندارد و امکان ارسال یادآوری وجود ندارد.'), 422);

		$summary = sanitize_textarea_field(wp_unslash($_POST['summary'] ?? ''));
		$instructions = array_values(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', wp_unslash($_POST['instructions'] ?? '')))));
		$next_date = sanitize_text_field(wp_unslash($_POST['next_date'] ?? ''));
		$reminder_days = absint($_POST['reminder_days'] ?? 2);
		if (! $summary || ! $instructions || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_date)) wp_send_json_error(array('message' => 'خلاصه، دستورها و تاریخ مراجعه بعدی را کامل کنید.'), 422);

		$existing = get_posts(array('post_type' => Luna_Appointments_Care_Plans::TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_luna_care_booking_id', 'meta_value' => $booking_id));
		$service_id = (int) ($booking['service_id'] ?? 0);
		$customer_name = (string) ($booking['customer_name'] ?? 'مشتری');
		$plan_id = ! empty($existing) ? (int) $existing[0] : wp_insert_post(array('post_type' => Luna_Appointments_Care_Plans::TYPE, 'post_status' => 'publish', 'post_title' => 'پلن مراقبتی ' . ($service_id ? get_the_title($service_id) : 'مراجعه') . ' – ' . $customer_name));
		if (! $plan_id || is_wp_error($plan_id)) wp_send_json_error(array('message' => 'ساخت پلن انجام نشد.'), 500);
		update_post_meta($plan_id, '_luna_care_user_id', $user_id);
		update_post_meta($plan_id, '_luna_care_booking_id', $booking_id);
		update_post_meta($plan_id, '_luna_care_service_id', $service_id);
		update_post_meta($plan_id, '_luna_care_specialist_id', $specialist_id);
		update_post_meta($plan_id, '_luna_care_order_id', (int) ($booking['wc_order_id'] ?? 0));
		update_post_meta($plan_id, '_luna_care_status', 'active');
		update_post_meta($plan_id, '_luna_care_summary', $summary);
		update_post_meta($plan_id, '_luna_care_instructions', $instructions);
		update_post_meta($plan_id, '_luna_care_next_date', $next_date);
		update_post_meta($plan_id, '_luna_care_reminder_days', min(30, $reminder_days));
		if (! get_post_meta($plan_id, '_luna_care_products', true) && ! empty($booking['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $booking['wc_order_id']);
			$product_ids = array();
			if ($order instanceof WC_Order) foreach ($order->get_items() as $item) $product_ids[] = (int) $item->get_product_id();
			update_post_meta($plan_id, '_luna_care_products', array_values(array_unique(array_filter($product_ids))));
		}
		if (method_exists('Luna_Appointments_Care_Plans', 'reschedule')) Luna_Appointments_Care_Plans::reschedule($plan_id);
		wp_send_json_success(array('message' => 'پلن مراقبتی و یادآوری خودکار فعال شد.', 'html' => self::care_plan_cards(array(get_post($plan_id)))));
	}

	public static function ajax_booking_status() {
		check_ajax_referer(self::STATUS_NONCE, 'nonce');
		if (! Luna_Appointments_Specialists::current_user_is_specialist()) wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
		$booking_id = absint($_POST['booking_id'] ?? 0);
		$status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
		if (! in_array($status, array('completed', 'done'), true)) wp_send_json_error(array('message' => 'وضعیت معتبر نیست.'), 400);
		$booking = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		$specialist_id = Luna_Appointments_Specialists::get_current_user_specialist_id();
		if (! is_array($booking) || (int) ($booking['specialist_id'] ?? 0) !== $specialist_id) wp_send_json_error(array('message' => 'این رزرو متعلق به شما نیست.'), 403);
		$previous = $booking;
		if (! Luna_Appointments_Bookings_Table::update_booking($booking_id, array('status' => $status))) wp_send_json_error(array('message' => 'ذخیره وضعیت انجام نشد.'), 500);
		$current = Luna_Appointments_Bookings_Table::get_booking($booking_id);
		do_action('luna_appointments_booking_status_transition', $booking_id, (string) ($previous['status'] ?? ''), $status, (string) ($previous['payment_status'] ?? ''), (string) ($current['payment_status'] ?? ''), $current, $previous, 'specialist_app');
		wp_send_json_success(array('label' => self::status_label($status), 'message' => 'مراجعه با موفقیت تکمیل شد.'));
	}

	public static function login_redirect($redirect_to, $requested, $user) {
		unset($requested);
		return $user instanceof WP_User && in_array(Luna_Appointments_Specialists::ROLE, (array) $user->roles, true) ? self::app_url() : $redirect_to;
	}

	protected static function render_manifest() {
		header('Content-Type: application/manifest+json; charset=UTF-8');
		$icon192 = get_site_icon_url(192);
		$icon512 = get_site_icon_url(512);
		$fallback = LUNA_APPOINTMENTS_URL . 'assets/specialist-app/icon.svg';
		echo wp_json_encode(array('name' => 'Luna Professional', 'short_name' => 'Luna Pro', 'description' => 'فضای کاری متخصصان لونا', 'lang' => 'fa', 'dir' => 'rtl', 'start_url' => self::app_url(), 'scope' => self::app_url(), 'display' => 'standalone', 'orientation' => 'portrait-primary', 'background_color' => '#f4ede8', 'theme_color' => '#f4ede8', 'icons' => array(array('src' => $icon192 ?: $fallback, 'sizes' => $icon192 ? '192x192' : 'any', 'purpose' => 'any'), array('src' => $icon512 ?: $fallback, 'sizes' => $icon512 ? '512x512' : 'any', 'purpose' => 'maskable'))));
		exit;
	}

	protected static function render_service_worker() {
		header('Content-Type: application/javascript; charset=UTF-8');
		header('Service-Worker-Allowed: ' . wp_parse_url(self::app_url(), PHP_URL_PATH));
		$assets = array(LUNA_APPOINTMENTS_URL . 'assets/specialist-app/specialist-app.css', LUNA_APPOINTMENTS_URL . 'assets/specialist-app/specialist-app.js');
		?>const CACHE='luna-pro-v4';const ASSETS=<?php echo wp_json_encode($assets); ?>;self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).then(()=>self.skipWaiting())));self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));self.addEventListener('fetch',event=>{if(event.request.method!=='GET'||event.request.mode==='navigate')return;event.respondWith(caches.match(event.request).then(cached=>cached||fetch(event.request).then(response=>{if(response&&response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(event.request,copy));}return response;})))});self.addEventListener('push',event=>{let data={title:'Luna Pro',body:'اعلان جدید',url:'./',tag:'luna-pro'};try{data=Object.assign(data,event.data.json())}catch(error){}event.waitUntil(self.registration.showNotification(data.title,{body:data.body,icon:data.icon,badge:data.badge,tag:data.tag,data:{url:data.url},dir:data.dir||'rtl',lang:data.lang||'fa',vibrate:[180,80,180],renotify:true}))});self.addEventListener('notificationclick',event=>{event.notification.close();const url=event.notification.data&&event.notification.data.url?event.notification.data.url:'./';event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(windows=>{for(const client of windows){if('focus'in client){client.navigate(url);return client.focus()}}return clients.openWindow?clients.openWindow(url):null}))});<?php
		exit;
	}

	public static function app_url($path = '') { return home_url('/' . self::APP_SLUG . '/' . ltrim($path, '/')); }
	protected static function meta_value($meta, $key, $default = '') { return isset($meta[$key][0]) && '' !== (string) $meta[$key][0] ? (string) maybe_unserialize($meta[$key][0]) : $default; }
	protected static function day_greeting() { $hour = (int) current_datetime()->format('G'); return $hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'ظهر بخیر' : 'عصر بخیر'); }
	protected static function short_date($date) { return class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::format_jalali($date, '', false) : (string) $date; }
	protected static function status_label($status) { $labels = array('pending' => 'در انتظار', 'confirmed' => 'تأییدشده', 'processing' => 'در حال انجام', 'completed' => 'انجام‌شده', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'); return $labels[$status] ?? $status; }
}
