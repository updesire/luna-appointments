<?php
/**
 * Services custom post type and admin fields.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Services {
	/**
	 * Service meta fields.
	 *
	 * @var array<string, string>
	 */
	protected static $meta_fields = array(
		'_luna_service_area'                => 'area',
		'_luna_service_duration_minutes'    => 'number',
		'_luna_service_duration_label'      => 'text',
		'_luna_service_base_price'          => 'number',
		'_luna_service_price_min'           => 'number',
		'_luna_service_price_max'           => 'number',
		'_luna_service_price_label'         => 'text',
		'_luna_service_category'            => 'text',
		'_luna_service_source_category'     => 'text',
		'_luna_service_brand'               => 'text',
		'_luna_service_import_key'          => 'text',
		'_luna_service_short_description'   => 'textarea',
		'_luna_service_booking_buffer'      => 'number',
		'_luna_service_requires_consultation' => 'checkbox',
		'_luna_service_is_active'           => 'checkbox',
		'_luna_service_specialist_ids'      => 'ids',
	);

	/**
	 * Boot service hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action('init', array(__CLASS__, 'register_post_type'));
		add_action('init', array(__CLASS__, 'register_taxonomy'));
		add_action('init', array(__CLASS__, 'register_meta'));
		add_action('init', array(__CLASS__, 'ensure_seeded_posts'), 20);
		add_action('init', array(__CLASS__, 'ensure_clinic_services_imported'), 30);
		add_action('init', array(__CLASS__, 'ensure_service_areas_migrated'), 31);
		add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
		add_action('save_post_service', array(__CLASS__, 'save_meta_box'));
		add_filter('manage_service_posts_columns', array(__CLASS__, 'admin_columns'));
		add_action('manage_service_posts_custom_column', array(__CLASS__, 'render_admin_column'), 10, 2);
	}

	/**
	 * Register the service post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			'service',
			array(
				'labels' => array(
					'name'               => __('خدمات', 'luna-appointments'),
					'singular_name'      => __('خدمت', 'luna-appointments'),
					'add_new'            => __('افزودن خدمت', 'luna-appointments'),
					'add_new_item'       => __('افزودن خدمت جدید', 'luna-appointments'),
					'edit_item'          => __('ویرایش خدمت', 'luna-appointments'),
					'new_item'           => __('خدمت جدید', 'luna-appointments'),
					'view_item'          => __('مشاهده خدمت', 'luna-appointments'),
					'search_items'       => __('جستجوی خدمات', 'luna-appointments'),
					'not_found'          => __('هیچ خدمتی پیدا نشد.', 'luna-appointments'),
					'not_found_in_trash' => __('هیچ خدمتی در زباله‌دان پیدا نشد.', 'luna-appointments'),
					'menu_name'          => __('خدمات', 'luna-appointments'),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_position'      => 27,
				'menu_icon'          => 'dashicons-clipboard',
				'taxonomies'         => array(self::get_category_taxonomy()),
				'supports'           => array('title', 'editor', 'thumbnail', 'page-attributes'),
			)
		);
	}

	/**
	 * Return the service category taxonomy name.
	 *
	 * @return string
	 */
	protected static function get_category_taxonomy() {
		return 'luna_service_category';
	}

	/**
	 * Register the service category taxonomy.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::get_category_taxonomy(),
			array('service'),
			array(
				'labels'            => array(
					'name'              => __('دسته‌بندی خدمات', 'luna-appointments'),
					'singular_name'     => __('دسته خدمت', 'luna-appointments'),
					'search_items'      => __('جستجوی دسته‌های خدمات', 'luna-appointments'),
					'all_items'         => __('همه دسته‌های خدمات', 'luna-appointments'),
					'parent_item'       => __('دسته مادر', 'luna-appointments'),
					'parent_item_colon' => __('دسته مادر:', 'luna-appointments'),
					'edit_item'         => __('ویرایش دسته خدمت', 'luna-appointments'),
					'update_item'       => __('بروزرسانی دسته خدمت', 'luna-appointments'),
					'add_new_item'      => __('افزودن دسته خدمت جدید', 'luna-appointments'),
					'new_item_name'     => __('نام دسته خدمت جدید', 'luna-appointments'),
					'menu_name'         => __('دسته‌بندی خدمات', 'luna-appointments'),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				/* Show the native term-management screen below the Services CPT. */
				'show_in_menu'      => true,
				'show_in_rest'      => false,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	/**
	 * Register service meta fields.
	 *
	 * @return void
	 */
	public static function register_meta() {
		foreach (self::$meta_fields as $meta_key => $field_type) {
			register_post_meta(
				'service',
				$meta_key,
				array(
					'single'            => true,
					'type'              => self::get_registered_meta_type($field_type),
					'show_in_rest'      => false,
					'sanitize_callback' => array(__CLASS__, 'sanitize_meta_value'),
					'auth_callback'     => static function () {
						return current_user_can('edit_posts');
					},
				)
			);
		}
	}

	/**
	 * Resolve the register_post_meta type.
	 *
	 * @param string $field_type Internal field type.
	 * @return string
	 */
	protected static function get_registered_meta_type($field_type) {
		if ('number' === $field_type) {
			return 'number';
		}

		if ('ids' === $field_type) {
			return 'array';
		}

		return 'string';
	}

	/**
	 * Add service details box.
	 *
	 * @return void
	 */
	public static function register_meta_box() {
		add_meta_box(
			'luna_service_details',
			__('اطلاعات خدمت', 'luna-appointments'),
			array(__CLASS__, 'render_meta_box'),
			'service',
			'normal',
			'high'
		);
	}

	/**
	 * Render service detail fields.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_meta_box($post) {
		wp_nonce_field('luna_service_meta_box', 'luna_service_meta_nonce');

		$values = self::get_service_meta_values($post->ID);

		echo '<div class="luna-service-fields" style="display:grid;gap:16px;">';
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_area_field($values['_luna_service_area']);
		self::render_text_field('برند', 'luna_service_brand', $values['_luna_service_brand'], 'text', '', '', 'برای خدمات کلینیک اختیاری است.');
		echo '</div>';
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('مدت زمان (دقیقه)', 'luna_service_duration_minutes', $values['_luna_service_duration_minutes'], 'number', '0', '1');
		self::render_text_field('عنوان مدت/واحد', 'luna_service_duration_label', $values['_luna_service_duration_label'], 'text', '', '', 'مثال: ۶۰ دقیقه یا جلسه‌ای');
		echo '</div>';
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('قیمت پایه', 'luna_service_base_price', $values['_luna_service_base_price'], 'number', '0', '1000', 'فقط مبلغ خام را بدون جداکننده وارد کنید.');
		self::render_text_field('برچسب قیمت', 'luna_service_price_label', $values['_luna_service_price_label'], 'text', '', '', 'مثال: از ۸۵۰٬۰۰۰ تومان');
		echo '</div>';
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('حداقل قیمت', 'luna_service_price_min', $values['_luna_service_price_min'], 'number', '0', '1000');
		self::render_text_field('حداکثر قیمت', 'luna_service_price_max', $values['_luna_service_price_max'], 'number', '0', '1000');
		echo '</div>';
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('دسته‌بندی', 'luna_service_category', $values['_luna_service_category'], 'text', '', '', 'با taxonomy دسته‌بندی خدمات همگام می‌شود. مثال: مو، کلینیک، ناخن');
		self::render_text_field('دسته مبدأ', 'luna_service_source_category', $values['_luna_service_source_category'], 'text', '', '', 'برای نگهداری دسته دقیق فایل قیمت؛ دسته نمایشی بالا مستقل است.');
		echo '</div>';
		self::render_textarea_field('توضیح کوتاه', 'luna_service_short_description', $values['_luna_service_short_description'], 4, 'خلاصه کوتاه خدمت که در رابط رزرو و لیست‌ها استفاده می‌شود.');
		self::render_text_field('بافر رزرو (دقیقه)', 'luna_service_booking_buffer', $values['_luna_service_booking_buffer'], 'number', '0', '1', 'فاصله اضافه بین نوبت‌ها برای این خدمت.');
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_checkbox_field('نیازمند مشاوره', 'luna_service_requires_consultation', ! empty($values['_luna_service_requires_consultation']), 'اگر این خدمت نیاز به تایید اولیه یا مشاوره دارد، فعالش کنید.');
		self::render_checkbox_field('فعال برای رزرو', 'luna_service_is_active', ! empty($values['_luna_service_is_active']), 'برای مخفی شدن این خدمت از رزروهای بعدی، این گزینه را غیرفعال کنید.');
		echo '</div>';
		do_action('luna_appointments_service_finance_fields', $post, $values);
		self::render_specialists_field($values['_luna_service_specialist_ids']);
		echo '</div>';
	}

	/**
	 * Save service meta values.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function save_meta_box($post_id) {
		if (! isset($_POST['luna_service_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_service_meta_nonce'])), 'luna_service_meta_box')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$previous_specialist_ids = self::get_assigned_specialist_ids($post_id);
		$new_specialist_ids      = $previous_specialist_ids;

		foreach (self::$meta_fields as $meta_key => $field_type) {
			$field_name = ltrim($meta_key, '_');

			if ('checkbox' === $field_type) {
				$value = isset($_POST[ $field_name ]) ? '1' : '';
			} elseif ('ids' === $field_type) {
				$value = isset($_POST[ $field_name ]) ? wp_unslash($_POST[ $field_name ]) : array();
			} else {
				$value = isset($_POST[ $field_name ]) ? wp_unslash($_POST[ $field_name ]) : '';
			}

			$sanitized = self::sanitize_meta_value($value, $meta_key);
			update_post_meta($post_id, $meta_key, $sanitized);

			if ('_luna_service_specialist_ids' === $meta_key) {
				$new_specialist_ids = is_array($sanitized) ? array_map('intval', $sanitized) : array();
			}
		}

		$category_name = self::resolve_submitted_category_name(
			isset($_POST['tax_input']) ? wp_unslash($_POST['tax_input']) : array(),
			(string) get_post_meta($post_id, '_luna_service_category', true)
		);
		update_post_meta($post_id, '_luna_service_category', $category_name);
		if (self::is_clinic_category($category_name)) {
			update_post_meta($post_id, '_luna_service_area', 'clinic');
		}
		self::sync_category_term($post_id, $category_name);

		if (class_exists('Luna_Appointments_Specialists')) {
			Luna_Appointments_Specialists::sync_service_relationships($post_id, $new_specialist_ids, $previous_specialist_ids);
		}
	}

	/**
	 * Return normalized service meta values.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public static function get_service_meta_values($post_id) {
		$values = array();

		foreach (self::$meta_fields as $meta_key => $field_type) {
			$value = get_post_meta($post_id, $meta_key, true);

			if ('ids' === $field_type) {
				$values[ $meta_key ] = is_array($value) ? array_map('intval', $value) : array();
				continue;
			}

			$values[ $meta_key ] = (string) $value;
		}

		if (empty($values['_luna_service_area'])) {
			$values['_luna_service_area'] = 'salon';
		}

		return $values;
	}

	public static function get_assigned_specialist_ids($post_id) {
		$values = self::get_service_meta_values($post_id);

		return isset($values['_luna_service_specialist_ids']) && is_array($values['_luna_service_specialist_ids'])
			? array_values(array_unique(array_map('intval', $values['_luna_service_specialist_ids'])))
			: array();
	}

	public static function sync_specialist_relationships($specialist_id, $new_service_ids, $old_service_ids = array()) {
		$specialist_id   = (int) $specialist_id;
		$new_service_ids = array_values(array_unique(array_map('intval', (array) $new_service_ids)));
		$old_service_ids = array_values(array_unique(array_map('intval', (array) $old_service_ids)));

		if ($specialist_id <= 0) {
			return;
		}

		$service_ids = array_values(array_unique(array_merge($new_service_ids, $old_service_ids)));

		foreach ($service_ids as $service_id) {
			if ($service_id <= 0) {
				continue;
			}

			$assigned_specialists = self::get_assigned_specialist_ids($service_id);
			$should_assign        = in_array($service_id, $new_service_ids, true);

			if ($should_assign && ! in_array($specialist_id, $assigned_specialists, true)) {
				$assigned_specialists[] = $specialist_id;
			}

			if (! $should_assign && in_array($specialist_id, $assigned_specialists, true)) {
				$assigned_specialists = array_values(array_diff($assigned_specialists, array($specialist_id)));
			}

			update_post_meta($service_id, '_luna_service_specialist_ids', array_values(array_unique(array_map('intval', $assigned_specialists))));
		}
	}

	/**
	 * Query published service posts ordered for booking and admin use.
	 *
	 * @param bool $active_only Whether to limit results to active services.
	 * @return WP_Post[]
	 */
	public static function query_services($active_only = true) {
		$posts = get_posts(
			array(
				'post_type'      => 'service',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'order'          => 'ASC',
			)
		);

		if (! is_array($posts)) {
			return array();
		}

		if (! $active_only) {
			return $posts;
		}

		return array_values(
			array_filter(
				$posts,
				static function ($post) {
					if (! $post instanceof WP_Post) {
						return false;
					}

					$meta = self::get_service_meta_values($post->ID);

					return ! empty($meta['_luna_service_is_active']);
				}
			)
		);
	}

	/**
	 * Seed services into the CPT.
	 *
	 * @return void
	 */
	public static function ensure_seeded_posts() {
		foreach (self::get_seed_services() as $seed) {
			$post = get_page_by_path($seed['slug'], OBJECT, 'service');

			if (! $post instanceof WP_Post) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'service',
						'post_status'  => 'publish',
						'post_title'   => $seed['title'],
						'post_name'    => $seed['slug'],
						'post_content' => isset($seed['content']) ? $seed['content'] : '',
						'menu_order'   => isset($seed['menu_order']) ? (int) $seed['menu_order'] : 0,
					),
					true
				);

				if (is_wp_error($post_id) || ! $post_id) {
					continue;
				}

				$post = get_post((int) $post_id);
			}

			if (! $post instanceof WP_Post) {
				continue;
			}

			foreach ($seed['meta'] as $meta_key => $value) {
				update_post_meta($post->ID, $meta_key, self::sanitize_meta_value($value, $meta_key));
			}

			$category_name = isset($seed['meta']['_luna_service_category']) ? (string) $seed['meta']['_luna_service_category'] : '';
			self::sync_category_term($post->ID, $category_name);
		}
	}

	/**
	 * Import the approved clinic price list into the shared service post type.
	 *
	 * The version gate keeps normal requests inexpensive, while the stable import
	 * key makes retries safe if an individual WordPress insert fails.
	 *
	 * @return void
	 */
	public static function ensure_clinic_services_imported() {
		$version = 'clinic-price-1405-04-v2';

		if ($version === get_option('luna_clinic_services_import_version', '')) {
			return;
		}

		$data_file = dirname(__DIR__) . '/data/clinic-services.php';
		if (! is_readable($data_file)) {
			return;
		}

		$rows = require $data_file;
		if (! is_array($rows) || 125 !== count($rows)) {
			return;
		}

		$default_specialists = self::resolve_clinic_specialist_ids();
		$imported            = 0;
		$failed              = 0;

		wp_defer_term_counting(true);

		foreach ($rows as $row) {
			$result = self::upsert_clinic_service($row, $default_specialists);
			if (is_wp_error($result) || ! $result) {
				++$failed;
				continue;
			}

			++$imported;
		}

		wp_defer_term_counting(false);

		update_option(
			'luna_clinic_services_import_report',
			array(
				'version'     => $version,
				'imported'    => $imported,
				'failed'      => $failed,
				'imported_at' => current_time('mysql'),
			),
			false
		);

		if (0 === $failed && 125 === $imported && ! empty($default_specialists)) {
			update_option('luna_clinic_services_import_version', $version, false);
		}
	}

	/**
	 * Repair the area of existing services created before area separation.
	 *
	 * @return void
	 */
	public static function ensure_service_areas_migrated() {
		$version = 'service-area-v1';
		if ($version === get_option('luna_service_area_migration_version', '')) {
			return;
		}

		$service_ids = get_posts(
			array(
				'post_type'      => 'service',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ((array) $service_ids as $service_id) {
			$category = (string) get_post_meta((int) $service_id, '_luna_service_category', true);
			$area     = (string) get_post_meta((int) $service_id, '_luna_service_area', true);

			if (self::is_clinic_category($category)) {
				update_post_meta((int) $service_id, '_luna_service_area', 'clinic');
			} elseif (! in_array($area, array('salon', 'clinic'), true)) {
				update_post_meta((int) $service_id, '_luna_service_area', 'salon');
			}
		}

		update_option('luna_service_area_migration_version', $version, false);
	}

	/**
	 * Insert or update one clinic service from the normalized workbook row.
	 *
	 * @param array<string, mixed> $row                 Workbook row.
	 * @param int[]                $default_specialists Initial clinic specialists.
	 * @return int|WP_Error
	 */
	protected static function upsert_clinic_service($row, $default_specialists) {
		$row_number = isset($row['row']) ? (int) $row['row'] : 0;
		$title      = isset($row['title']) ? trim((string) $row['title']) : '';
		$raw_group  = isset($row['category']) ? trim((string) $row['category']) : '';

		if ($row_number <= 0 || '' === $title || '' === $raw_group) {
			return new WP_Error('luna_invalid_clinic_service', __('ردیف خدمت کلینیک معتبر نیست.', 'luna-appointments'));
		}

		$import_key = 'clinic-price-1405-04-row-' . $row_number;
		$existing   = get_posts(
			array(
				'post_type'      => 'service',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_luna_service_import_key',
				'meta_value'     => $import_key,
				'no_found_rows'  => true,
			)
		);

		$duration_label = isset($row['duration']) ? trim((string) $row['duration']) : '';
		$brand          = isset($row['brand']) ? trim((string) $row['brand']) : '';
		$price_min      = isset($row['min']) ? max(0, (int) $row['min']) : 0;
		$price_max      = isset($row['max']) ? max(0, (int) $row['max']) : 0;
		$category       = self::map_clinic_category($raw_group);
		$consultation   = self::clinic_service_requires_consultation($raw_group);
		$base_price     = $price_min > 0 ? $price_min : $price_max;
		$postarr        = array(
			'post_type'    => 'service',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => sprintf('clinic-%03d-%s', $row_number, sanitize_title($title)),
			'post_content' => self::build_clinic_service_description($title, $category, $duration_label, $brand),
			'menu_order'   => 1000 + $row_number,
		);

		if (! empty($existing)) {
			$postarr['ID'] = (int) $existing[0];
			$post_id       = wp_update_post($postarr, true);
		} else {
			$post_id = wp_insert_post($postarr, true);
		}

		if (is_wp_error($post_id) || ! $post_id) {
			return $post_id;
		}

		$meta = array(
			'_luna_service_area'                 => 'clinic',
			'_luna_service_duration_minutes'     => self::resolve_clinic_duration($duration_label, $raw_group),
			'_luna_service_duration_label'       => '' !== $duration_label ? $duration_label : self::default_clinic_duration_label($raw_group),
			'_luna_service_base_price'           => $base_price,
			'_luna_service_price_min'            => $price_min,
			'_luna_service_price_max'            => $price_max,
			'_luna_service_price_label'          => self::format_clinic_price_label($price_min, $price_max),
			'_luna_service_category'             => $category,
			'_luna_service_source_category'      => $raw_group,
			'_luna_service_brand'                => $brand,
			'_luna_service_import_key'           => $import_key,
			'_luna_service_short_description'    => self::build_clinic_service_description($title, $category, $duration_label, $brand),
			'_luna_service_booking_buffer'       => 10,
			'_luna_service_requires_consultation' => $consultation ? '1' : '',
		);

		foreach ($meta as $meta_key => $value) {
			update_post_meta($post_id, $meta_key, self::sanitize_meta_value($value, $meta_key));
		}

		if (! metadata_exists('post', $post_id, '_luna_service_is_active')) {
			update_post_meta($post_id, '_luna_service_is_active', '1');
		}

		$assigned_specialists = self::get_assigned_specialist_ids($post_id);
		if (empty($assigned_specialists) && ! empty($default_specialists)) {
			$assigned_specialists = $default_specialists;
			update_post_meta($post_id, '_luna_service_specialist_ids', $assigned_specialists);
		}

		if (! empty($assigned_specialists) && class_exists('Luna_Appointments_Specialists')) {
			Luna_Appointments_Specialists::sync_service_relationships($post_id, $assigned_specialists, array());
		}

		self::sync_category_term($post_id, $category);

		return (int) $post_id;
	}

	/** @return int[] */
	protected static function resolve_clinic_specialist_ids() {
		$ids = self::resolve_specialist_ids(array('dr-aida-karimi'));

		if (! empty($ids)) {
			return $ids;
		}

		foreach (self::query_specialists() as $specialist) {
			$haystack = get_the_title($specialist) . ' ' . (string) get_post_meta($specialist->ID, '_luna_specialist_role', true);
			if (false !== strpos($haystack, 'پزشک') || false !== strpos($haystack, 'کلینیک')) {
				$ids[] = (int) $specialist->ID;
			}
		}

		return array_values(array_unique($ids));
	}

	/** @return string */
	protected static function map_clinic_category($raw_group) {
		$map = array(
			'بوتاکس' => 'بوتاکس', 'ژل' => 'ژل', 'نخ' => 'نخ', 'جوانسازی' => 'جوان‌سازی',
			'مزوتراپی' => 'مزوتراپی', 'فیلر' => 'هیر فیلر', 'لیزر موهای زائد' => 'لیزر موهای زائد',
			'ماساژ' => 'ماساژ', 'فیشال' => 'فیشال و خدمات مکمل', 'فیشال VIP' => 'فیشال و خدمات مکمل',
			'ماساژ صورت' => 'فیشال و خدمات مکمل', 'ریمو تتو' => 'فیشال و خدمات مکمل', 'سایر' => 'فیشال و خدمات مکمل',
			'EMS' => 'پکیج‌های لاغری', 'EMS طلایی' => 'پکیج‌های لاغری', 'بازو' => 'پکیج‌های لاغری',
			'شکم / پهلو' => 'پکیج‌های لاغری', 'ران / باسن' => 'پکیج‌های لاغری',
		);

		return isset($map[ $raw_group ]) ? $map[ $raw_group ] : $raw_group;
	}

	/**
	 * Determine whether a category belongs unambiguously to the clinic area.
	 *
	 * @param string $category Category label.
	 * @return bool
	 */
	protected static function is_clinic_category($category) {
		$category = trim((string) $category);

		return in_array(
			$category,
			array(
				'بوتاکس', 'ژل', 'نخ', 'جوانسازی', 'جوان‌سازی', 'مزوتراپی', 'فیلر', 'هیر فیلر',
				'لیزر موهای زائد', 'پکیج‌های لاغری', 'فیشال و خدمات مکمل',
			),
			true
		);
	}

	/** @return bool */
	protected static function clinic_service_requires_consultation($raw_group) {
		return in_array($raw_group, array('بوتاکس', 'ژل', 'نخ', 'جوانسازی', 'مزوتراپی', 'فیلر', 'ریمو تتو', 'سایر'), true);
	}

	/** @return int */
	protected static function resolve_clinic_duration($label, $raw_group) {
		$normalized = strtr((string) $label, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'));
		if (preg_match('/(\d{2,3})/', $normalized, $matches)) {
			return max(15, min(240, (int) $matches[1]));
		}

		if ('ماساژ' === $raw_group || in_array($raw_group, array('فیشال', 'فیشال VIP', 'ماساژ صورت'), true)) {
			return 60;
		}

		if (in_array($raw_group, array('EMS', 'EMS طلایی', 'بازو', 'شکم / پهلو', 'ران / باسن'), true)) {
			return 45;
		}

		return 30;
	}

	/** @return string */
	protected static function default_clinic_duration_label($raw_group) {
		return self::resolve_clinic_duration('', $raw_group) . ' دقیقه';
	}

	/** @return string */
	protected static function format_clinic_price_label($min, $max) {
		$format = static function ($amount) {
			$latin = number_format((int) $amount, 0, '.', '٬');
			return strtr($latin, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
		};

		if ($min > 0 && $max > 0 && $min !== $max) {
			return sprintf('از %s تا %s تومان', $format($min), $format($max));
		}
		if ($min > 0) {
			return sprintf('از %s تومان', $format($min));
		}
		if ($max > 0) {
			return sprintf('%s تومان', $format($max));
		}

		return 'نیازمند استعلام';
	}

	/** @return string */
	protected static function build_clinic_service_description($title, $category, $duration, $brand) {
		$parts = array(sprintf('خدمت %s در بخش %s کلینیک لونا', $title, $category));
		if ('' !== $brand) {
			$parts[] = 'با برند ' . $brand;
		}
		if ('' !== $duration) {
			$parts[] = 'با زمان ' . $duration;
		}

		return implode('، ', $parts) . '.';
	}

	/**
	 * Default service seeds used by booking.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_seed_services() {
		return array(
			array(
				'slug'       => 'hair-color',
                                'title'      => 'رنگ مو',
				'menu_order' => 1,
				'content'    => 'خدمت رنگ موی لونا برای ایجاد بُعد نرم، انتخاب تون شخصی‌سازی‌شده و فینیش حرفه‌ای سالن طراحی شده است.',
				'meta'       => array(
					'_luna_service_area'                 => 'salon',
					'_luna_service_duration_minutes'      => '90',
					'_luna_service_base_price'            => '850000',
                                        '_luna_service_price_label'           => 'از ۸۵۰٬۰۰۰ تومان',
                                        '_luna_service_category'              => 'مو',
                                        '_luna_service_short_description'     => 'رنگ مو با مشاوره رنگ، انتخاب تون مناسب و فینیش براق و تمیز.',
					'_luna_service_booking_buffer'        => '15',
					'_luna_service_requires_consultation' => '',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('negar-orangi')),
				),
			),
			array(
				'slug'       => 'balayage',
                                'title'      => 'بالیاژ',
				'menu_order' => 2,
				'content'    => 'بالیاژ با تمرکز روی بُعد رنگ، روشن‌سازی کنترل‌شده و فینیش لوکس برای نتیجه‌ای طبیعی.',
				'meta'       => array(
					'_luna_service_area'                 => 'salon',
					'_luna_service_duration_minutes'      => '120',
					'_luna_service_base_price'            => '1200000',
                                        '_luna_service_price_label'           => 'از ۱٬۲۰۰٬۰۰۰ تومان',
                                        '_luna_service_category'              => 'مو',
                                        '_luna_service_short_description'     => 'بالیاژ با روشن‌سازی نرم و انتقال رنگ طبیعی و حرفه‌ای.',
					'_luna_service_booking_buffer'        => '20',
					'_luna_service_requires_consultation' => '1',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('negar-orangi')),
				),
			),
			array(
				'slug'       => 'gelish-manicure',
                                'title'      => 'ژلیش مانیکور',
				'menu_order' => 3,
				'content'    => 'مانیکور ژلیش با آماده‌سازی تمیز ناخن، فرم‌دهی دقیق و ماندگاری بالا.',
				'meta'       => array(
					'_luna_service_area'                 => 'salon',
					'_luna_service_duration_minutes'      => '60',
					'_luna_service_base_price'            => '450000',
                                        '_luna_service_price_label'           => 'از ۴۵۰٬۰۰۰ تومان',
                                        '_luna_service_category'              => 'ناخن',
                                        '_luna_service_short_description'     => 'مانیکور ژلیش با دوام بالا برای استفاده روزمره یا مناسبت.',
					'_luna_service_booking_buffer'        => '10',
					'_luna_service_requires_consultation' => '',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('taraneh-rezaei')),
				),
			),
			array(
				'slug'       => 'bridal-makeup',
                                'title'      => 'میکاپ عروس',
				'menu_order' => 4,
				'content'    => 'سرویس کامل میکاپ عروس با تمرکز روی ماندگاری، هماهنگی با نور و فینیش مناسب عکاسی و مراسم.',
				'meta'       => array(
					'_luna_service_area'                 => 'salon',
					'_luna_service_duration_minutes'      => '120',
					'_luna_service_base_price'            => '4500000',
                                        '_luna_service_price_label'           => 'از ۴٬۵۰۰٬۰۰۰ تومان',
                                        '_luna_service_category'              => 'میکاپ',
                                        '_luna_service_short_description'     => 'میکاپ عروس با طراحی چهره، ماندگاری بالا و فینیش مناسب مراسم.',
					'_luna_service_booking_buffer'        => '20',
					'_luna_service_requires_consultation' => '1',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('sara-mohammadi')),
				),
			),
			array(
				'slug'       => 'volume-lash-extensions',
                                'title'      => 'اکستنشن والیوم مژه',
				'menu_order' => 5,
				'content'    => 'اکستنشن والیوم مژه با طراحی متناسب با فرم چشم، تراکم دلخواه و راهنمای نگهداری.',
				'meta'       => array(
					'_luna_service_area'                 => 'salon',
					'_luna_service_duration_minutes'      => '90',
					'_luna_service_base_price'            => '750000',
                                        '_luna_service_price_label'           => 'از ۷۵۰٬۰۰۰ تومان',
                                        '_luna_service_category'              => 'مژه و ابرو',
                                        '_luna_service_short_description'     => 'اکستنشن والیوم مژه با طراحی متناسب با فرم چشم و نگهداری اصولی.',
					'_luna_service_booking_buffer'        => '15',
					'_luna_service_requires_consultation' => '',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('hanieh-nouri')),
				),
			),
			array(
				'slug'       => 'botox',
                                'title'      => 'بوتاکس',
				'menu_order' => 6,
				'content'    => 'نوبت بوتاکس با ارزیابی اولیه، برنامه‌ریزی محافظه‌کارانه و توضیح مراقبت‌های بعد از انجام.',
				'meta'       => array(
					'_luna_service_area'                 => 'clinic',
					'_luna_service_duration_minutes'      => '30',
					'_luna_service_base_price'            => '2500000',
                                        '_luna_service_price_label'           => 'از ۲٬۵۰۰٬۰۰۰ تومان',
					'_luna_service_category'              => 'بوتاکس',
                                        '_luna_service_short_description'     => 'بوتاکس با مشاوره، برنامه‌ریزی درمان و توضیح مراقبت‌های پس از انجام.',
					'_luna_service_booking_buffer'        => '20',
					'_luna_service_requires_consultation' => '1',
					'_luna_service_is_active'             => '1',
					'_luna_service_specialist_ids'        => self::resolve_specialist_ids(array('dr-aida-karimi')),
				),
			),
		);
	}

	/**
	 * Resolve seeded specialist slugs into post ids.
	 *
	 * @param string[] $slugs Specialist slugs.
	 * @return int[]
	 */
	protected static function resolve_specialist_ids($slugs) {
		$ids = array();

		foreach ((array) $slugs as $slug) {
			$post = get_page_by_path((string) $slug, OBJECT, 'specialist');

			if ($post instanceof WP_Post) {
				$ids[] = (int) $post->ID;
			}
		}

		return $ids;
	}

	/**
	 * Return specialists for checkbox mapping.
	 *
	 * @return WP_Post[]
	 */
	protected static function query_specialists() {
		$posts = get_posts(
			array(
				'post_type'      => 'specialist',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'order'          => 'ASC',
			)
		);

		return is_array($posts) ? $posts : array();
	}

	/**
	 * Sanitize service meta values.
	 *
	 * @param mixed       $value Raw value.
	 * @param string|null $meta_key Meta key.
	 * @return mixed
	 */
	public static function sanitize_meta_value($value, $meta_key = null) {
		if (in_array($meta_key, array('_luna_service_duration_minutes', '_luna_service_base_price', '_luna_service_price_min', '_luna_service_price_max', '_luna_service_booking_buffer'), true)) {
			return (string) max(0, (int) $value);
		}

		if ('_luna_service_area' === $meta_key) {
			return 'clinic' === sanitize_key((string) $value) ? 'clinic' : 'salon';
		}

		if (in_array($meta_key, array('_luna_service_requires_consultation', '_luna_service_is_active'), true)) {
			return ! empty($value) ? '1' : '';
		}

		if ('_luna_service_specialist_ids' === $meta_key) {
			$ids = is_array($value) ? $value : array($value);

			return array_values(
				array_filter(
					array_map(
						static function ($item) {
							return (int) $item;
						},
						$ids
					),
					static function ($item) {
						return $item > 0;
					}
				)
			);
		}

		$normalized = is_scalar($value) ? (string) $value : '';

		return trim(wp_kses_post($normalized));
	}

	/**
	 * Resolve the submitted category name from taxonomy UI or meta field.
	 *
	 * @param mixed  $tax_input Submitted taxonomy payload.
	 * @param string $fallback  Fallback category text value.
	 * @return string
	 */
	protected static function resolve_submitted_category_name($tax_input, $fallback = '') {
		$taxonomy = self::get_category_taxonomy();
		$fallback = trim((string) $fallback);

		if (! is_array($tax_input) || ! array_key_exists($taxonomy, $tax_input)) {
			return $fallback;
		}

		$submitted = $tax_input[ $taxonomy ];

		if (is_array($submitted)) {
			$submitted = array_values(array_filter(array_map('intval', $submitted)));

			if (empty($submitted)) {
				return $fallback;
			}

			$term = get_term((int) $submitted[0], $taxonomy);

			return $term instanceof WP_Term ? trim((string) $term->name) : $fallback;
		}

		$submitted = trim((string) $submitted);

		if ('' === $submitted) {
			return $fallback;
		}

		if (ctype_digit($submitted)) {
			$term = get_term((int) $submitted, $taxonomy);

			return $term instanceof WP_Term ? trim((string) $term->name) : $fallback;
		}

		return $submitted;
	}

	/**
	 * Sync the category term assignment for a service.
	 *
	 * @param int    $post_id       Service post id.
	 * @param string $category_name Category label.
	 * @return void
	 */
	public static function sync_category_term($post_id, $category_name) {
		$post_id       = (int) $post_id;
		$category_name = trim((string) $category_name);
		$taxonomy      = self::get_category_taxonomy();

		if ($post_id <= 0 || ! taxonomy_exists($taxonomy)) {
			return;
		}

		if ('' === $category_name) {
			wp_set_object_terms($post_id, array(), $taxonomy, false);
			return;
		}

		$term = term_exists($category_name, $taxonomy);

		if (! $term) {
			$term = wp_insert_term($category_name, $taxonomy);
		}

		if (is_wp_error($term) || empty($term)) {
			return;
		}

		$term_id = is_array($term) && isset($term['term_id']) ? (int) $term['term_id'] : (int) $term;

		if ($term_id > 0) {
			wp_set_object_terms($post_id, array($term_id), $taxonomy, false);
		}
	}

	/** @param array<string, string> $columns Columns. @return array<string, string> */
	public static function admin_columns($columns) {
		$result = array();
		foreach ($columns as $key => $label) {
			$result[ $key ] = $label;
			if ('title' === $key) {
				$result['luna_service_area']  = __('بخش خدمت', 'luna-appointments');
				$result['luna_service_price'] = __('قیمت', 'luna-appointments');
				$result['luna_service_brand'] = __('برند', 'luna-appointments');
			}
		}

		return $result;
	}

	/** @param string $column Column key. @param int $post_id Post id. @return void */
	public static function render_admin_column($column, $post_id) {
		if ('luna_service_area' === $column) {
			$area = (string) get_post_meta($post_id, '_luna_service_area', true);
			echo esc_html('clinic' === $area ? __('کلینیک', 'luna-appointments') : __('سالن زیبایی', 'luna-appointments'));
		}
		if ('luna_service_price' === $column) {
			echo esc_html((string) get_post_meta($post_id, '_luna_service_price_label', true));
		}
		if ('luna_service_brand' === $column) {
			$brand = (string) get_post_meta($post_id, '_luna_service_brand', true);
			echo '' !== $brand ? esc_html($brand) : '&mdash;';
		}
	}

	/**
	 * Render a text input row.
	 *
	 * @param string $label Label.
	 * @param string $name Field name.
	 * @param string $value Field value.
	 * @param string $type Input type.
	 * @param string $min Minimum value.
	 * @param string $step Step value.
	 * @param string $help Help text.
	 * @return void
	 */
	protected static function render_text_field($label, $name, $value, $type = 'text', $min = '', $step = '', $help = '') {
		echo '<label style="display:grid;gap:6px;">';
		echo '<span style="font-weight:600;">' . esc_html($label) . '</span>';
		echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="widefat"' . ($min !== '' ? ' min="' . esc_attr($min) . '"' : '') . ($step !== '' ? ' step="' . esc_attr($step) . '"' : '') . '>';
		if ($help) {
			echo '<small style="color:#666;">' . esc_html($help) . '</small>';
		}
		echo '</label>';
	}

	/** @param string $value Current area. @return void */
	protected static function render_area_field($value) {
		$value = 'clinic' === $value ? 'clinic' : 'salon';
		echo '<label style="display:grid;gap:6px;">';
		echo '<span style="font-weight:600;">' . esc_html__('بخش خدمت', 'luna-appointments') . '</span>';
		echo '<select name="luna_service_area" class="widefat">';
		echo '<option value="salon"' . selected($value, 'salon', false) . '>' . esc_html__('سالن زیبایی', 'luna-appointments') . '</option>';
		echo '<option value="clinic"' . selected($value, 'clinic', false) . '>' . esc_html__('کلینیک زیبایی', 'luna-appointments') . '</option>';
		echo '</select>';
		echo '<small style="color:#666;">' . esc_html__('این گزینه تب نمایش خدمت در فرم رزرو را تعیین می‌کند.', 'luna-appointments') . '</small>';
		echo '</label>';
	}

	/**
	 * Render a textarea row.
	 *
	 * @param string $label Label.
	 * @param string $name Field name.
	 * @param string $value Field value.
	 * @param int    $rows Rows.
	 * @param string $help Help text.
	 * @return void
	 */
	protected static function render_textarea_field($label, $name, $value, $rows = 4, $help = '') {
		echo '<label style="display:grid;gap:6px;">';
		echo '<span style="font-weight:600;">' . esc_html($label) . '</span>';
		echo '<textarea name="' . esc_attr($name) . '" rows="' . (int) $rows . '" class="widefat">' . esc_textarea($value) . '</textarea>';
		if ($help) {
			echo '<small style="color:#666;">' . esc_html($help) . '</small>';
		}
		echo '</label>';
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $label Label.
	 * @param string $name Field name.
	 * @param bool   $checked Checked state.
	 * @param string $help Help text.
	 * @return void
	 */
	protected static function render_checkbox_field($label, $name, $checked = false, $help = '') {
		echo '<label style="display:grid;gap:6px;">';
		echo '<span style="font-weight:600;">' . esc_html($label) . '</span>';
		echo '<span><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '> ' . esc_html__('فعال', 'luna-appointments') . '</label></span>';
		if ($help) {
			echo '<small style="color:#666;">' . esc_html($help) . '</small>';
		}
		echo '</label>';
	}

	/**
	 * Render specialist relationship checkboxes.
	 *
	 * @param int[] $selected_ids Selected specialist ids.
	 * @return void
	 */
	protected static function render_specialists_field($selected_ids) {
		$selected_ids = is_array($selected_ids) ? array_map('intval', $selected_ids) : array();
		$specialists  = self::query_specialists();

		echo '<div style="display:grid;gap:8px;">';
		echo '<span style="font-weight:600;">' . esc_html__('متخصص‌های این خدمت', 'luna-appointments') . '</span>';

		if (empty($specialists)) {
			echo '<small style="color:#666;">' . esc_html__('هنوز متخصصی ثبت نشده است. ابتدا متخصص‌ها را ایجاد کنید، سپس آن‌ها را به این خدمت اختصاص دهید.', 'luna-appointments') . '</small>';
			echo '</div>';
			return;
		}

		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;padding:12px 14px;border:1px solid #e1e1e1;border-radius:10px;background:#fff;">';
		foreach ($specialists as $specialist) {
			echo '<label style="display:flex;align-items:center;gap:8px;">';
			echo '<input type="checkbox" name="luna_service_specialist_ids[]" value="' . esc_attr((string) $specialist->ID) . '"' . checked(in_array((int) $specialist->ID, $selected_ids, true), true, false) . '>';
			echo '<span>' . esc_html(get_the_title($specialist)) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '<small style="color:#666;">' . esc_html__('این متخصص‌ها برای این خدمت در فرآیند رزرو در دسترس در نظر گرفته می‌شوند.', 'luna-appointments') . '</small>';
		echo '</div>';
	}
}
