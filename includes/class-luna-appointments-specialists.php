<?php
/**
 * Specialists custom post type and admin fields.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Specialists {
        const ROLE = 'specialist';
        const USER_LINK_META = '_luna_specialist_user_id';
        const USER_SPECIALIST_META = '_luna_specialist_post_id';
        const PROFILE_NONCE_ACTION = 'luna_specialist_profile';
        const REVIEW_NONCE_ACTION = 'luna_specialist_review';
        const REVIEW_ADMIN_NONCE_ACTION = 'luna_specialist_reviews_admin';

	/**
	 * Specialist meta fields.
	 *
	 * @var array<string, string>
	 */
	protected static $meta_fields = array(
		'_luna_specialist_role'          => 'text',
		'_luna_specialist_rating'        => 'number',
		'_luna_specialist_review_count'  => 'number',
		'_luna_specialist_bio'           => 'textarea',
		'_luna_specialist_tone_start'    => 'text',
		'_luna_specialist_tone_end'      => 'text',
		'_luna_specialist_history'       => 'textarea',
		'_luna_specialist_tags'          => 'text',
		'_luna_specialist_certifications'=> 'textarea',
		'_luna_specialist_services'      => 'textarea',
		'_luna_specialist_service_ids'   => 'ids',
		'_luna_specialist_working_days'  => 'ids',
		'_luna_specialist_working_start' => 'text',
		'_luna_specialist_working_end'   => 'text',
		'_luna_specialist_off_dates'     => 'textarea',
				'_luna_specialist_leave_ranges'  => 'textarea',
				'_luna_specialist_blocked_slots' => 'textarea',
		'_luna_specialist_reviews'       => 'textarea',
		'_luna_specialist_booking_url'   => 'text',
	);

	/**
	 * Boot specialist hooks.
	 *
	 * @return void
	 */
	public static function boot() {
                add_action('init', array(__CLASS__, 'register_specialist_role'), 5);
		add_action('init', array(__CLASS__, 'register_post_type'));
		add_action('init', array(__CLASS__, 'register_meta'));
		add_action('init', array(__CLASS__, 'ensure_seeded_posts'), 20);
                add_action('init', array(__CLASS__, 'sync_specialist_users'), 30);
		add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
		add_action('save_post_specialist', array(__CLASS__, 'save_meta_box'));
                add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
                add_action('admin_init', array(__CLASS__, 'restrict_specialist_admin_access'));
                add_action('template_redirect', array(__CLASS__, 'redirect_specialist_account_access'));
                add_filter('show_admin_bar', array(__CLASS__, 'filter_admin_bar_visibility'));
                add_filter('login_redirect', array(__CLASS__, 'filter_specialist_login_redirect'), 10, 3);
                add_action('wp_ajax_luna_update_specialist_profile', array(__CLASS__, 'handle_frontend_profile_update'));
                add_action('wp_ajax_luna_submit_specialist_review', array(__CLASS__, 'handle_frontend_review_submit'));
                add_action('wp_ajax_nopriv_luna_submit_specialist_review', array(__CLASS__, 'handle_frontend_review_submit'));
                add_action('wp_ajax_luna_admin_manage_specialist_review', array(__CLASS__, 'handle_admin_review_manage'));
	}

        /**
         * Register the specialist role.
         *
         * @return void
         */
        public static function register_specialist_role() {
                $role = get_role(self::ROLE);

                if (! $role) {
                        $role = add_role(
                                self::ROLE,
                                __('متخصص', 'luna-appointments'),
                                array(
                                        'read'         => true,
                                        'upload_files' => true,
                                )
                        );
                }

                if (! $role instanceof WP_Role) {
                        $role = get_role(self::ROLE);
                }

                if (! $role instanceof WP_Role) {
                        return;
                }

                $role->add_cap('read');
                $role->add_cap('upload_files');
                $role->remove_cap('edit_posts');
                $role->remove_cap('publish_posts');
                $role->remove_cap('delete_posts');
                $role->remove_cap('edit_pages');
                $role->remove_cap('manage_options');
        }

        /**
         * Enqueue specialist admin helpers.
         *
         * @return void
         */
        public static function enqueue_admin_assets() {
                if (! is_admin()) {
                        return;
                }

                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if (! $screen || 'specialist' !== (string) $screen->post_type || 'post' !== (string) $screen->base) {
                        return;
                }

                $datepicker_base_path = WP_PLUGIN_DIR . '/persian-woocommerce/assets/';
                $datepicker_base_url  = WP_PLUGIN_URL . '/persian-woocommerce/assets/';

                if (file_exists($datepicker_base_path . 'css/persian-datepicker.css')) {
                        wp_enqueue_style(
                                'luna-specialists-persian-datepicker',
                                $datepicker_base_url . 'css/persian-datepicker.css',
                                array(),
				defined('LUNA_APPOINTMENTS_VERSION') ? LUNA_APPOINTMENTS_VERSION : null
                        );
                }

                if (file_exists($datepicker_base_path . 'js/persian-datepicker.min.js')) {
                        wp_enqueue_script(
                                'luna-specialists-persian-datepicker',
                                $datepicker_base_url . 'js/persian-datepicker.min.js',
                                array('jquery'),
				defined('LUNA_APPOINTMENTS_VERSION') ? LUNA_APPOINTMENTS_VERSION : null,
                                true
                        );
                }

                wp_enqueue_script(
                        'luna-specialist-reviews-admin',
			LUNA_APPOINTMENTS_URL . 'admin/specialist-reviews.js',
                        array('jquery'),
			defined('LUNA_APPOINTMENTS_VERSION') ? LUNA_APPOINTMENTS_VERSION : null,
                        true
                );

                wp_localize_script(
                        'luna-specialist-reviews-admin',
                        'lunaSpecialistReviewsAdmin',
                        array(
                                'ajaxUrl' => admin_url('admin-ajax.php'),
                                'nonce'   => wp_create_nonce(self::REVIEW_ADMIN_NONCE_ACTION),
                        )
                );
        }

	/**
	 * Register the specialist post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			'specialist',
			array(
				'labels' => array(
					'name'               => __('متخصص‌ها', 'luna-appointments'),
					'singular_name'      => __('متخصص', 'luna-appointments'),
					'add_new'            => __('افزودن متخصص', 'luna-appointments'),
					'add_new_item'       => __('افزودن متخصص جدید', 'luna-appointments'),
					'edit_item'          => __('ویرایش متخصص', 'luna-appointments'),
					'new_item'           => __('متخصص جدید', 'luna-appointments'),
					'view_item'          => __('مشاهده متخصص', 'luna-appointments'),
					'search_items'       => __('جستجوی متخصص‌ها', 'luna-appointments'),
					'not_found'          => __('هیچ متخصصی پیدا نشد.', 'luna-appointments'),
					'not_found_in_trash' => __('هیچ متخصصی در زباله‌دان پیدا نشد.', 'luna-appointments'),
					'menu_name'          => __('متخصص‌ها', 'luna-appointments'),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_position'      => 26,
				'menu_icon'          => 'dashicons-groups',
				'supports'           => array('title', 'thumbnail', 'page-attributes'),
			)
		);
	}

	/**
	 * Register specialist meta fields.
	 *
	 * @return void
	 */
	public static function register_meta() {
		foreach (self::$meta_fields as $meta_key => $field_type) {
			register_post_meta(
				'specialist',
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
	 * Add specialist details box.
	 *
	 * @return void
	 */
	public static function register_meta_box() {
		add_meta_box(
			'luna_specialist_details',
			__('اطلاعات متخصص', 'luna-appointments'),
			array(__CLASS__, 'render_meta_box'),
			'specialist',
			'normal',
			'high'
		);
	}

	/**
	 * Render specialist detail fields.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_meta_box($post) {
		wp_nonce_field('luna_specialist_meta_box', 'luna_specialist_meta_nonce');

		$values = self::get_specialist_meta_values($post->ID);

		echo '<div class="luna-specialist-fields" style="display:grid;gap:16px;">';
		self::render_text_field('عنوان شغلی', 'luna_specialist_role', $values['_luna_specialist_role']);
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('امتیاز', 'luna_specialist_rating', $values['_luna_specialist_rating'], 'number', '0', '0.1');
		self::render_text_field('تعداد نظر', 'luna_specialist_review_count', $values['_luna_specialist_review_count'], 'number', '0', '1');
		echo '</div>';
		self::render_textarea_field('بیوگرافی', 'luna_specialist_bio', $values['_luna_specialist_bio'], 4);
		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;">';
		self::render_text_field('رنگ شروع', 'luna_specialist_tone_start', $values['_luna_specialist_tone_start']);
		self::render_text_field('رنگ پایان', 'luna_specialist_tone_end', $values['_luna_specialist_tone_end']);
		echo '</div>';
		self::render_textarea_field('سوابق / تجربه', 'luna_specialist_history', $values['_luna_specialist_history'], 5, 'هر مورد در یک خط');
		self::render_text_field('برچسب‌ها', 'luna_specialist_tags', $values['_luna_specialist_tags'], 'text', '', '', 'با کاما جدا کنید');
		self::render_textarea_field('مدارک و گواهی‌ها', 'luna_specialist_certifications', $values['_luna_specialist_certifications'], 4, 'هر مورد در یک خط');
		self::render_textarea_field('خدمات', 'luna_specialist_services', $values['_luna_specialist_services'], 5, 'هر خدمت در یک خط با فرمت `عنوان|قیمت`');
		self::render_service_relations_field(isset($values['_luna_specialist_service_ids']) && is_array($values['_luna_specialist_service_ids']) ? $values['_luna_specialist_service_ids'] : array());
		self::render_booking_availability_fields(
			isset($values['_luna_specialist_working_days']) && is_array($values['_luna_specialist_working_days']) ? $values['_luna_specialist_working_days'] : array(),
			isset($values['_luna_specialist_working_start']) ? (string) $values['_luna_specialist_working_start'] : '',
			isset($values['_luna_specialist_working_end']) ? (string) $values['_luna_specialist_working_end'] : '',
						isset($values['_luna_specialist_off_dates']) ? (string) $values['_luna_specialist_off_dates'] : '',
						isset($values['_luna_specialist_leave_ranges']) ? (string) $values['_luna_specialist_leave_ranges'] : '',
						isset($values['_luna_specialist_blocked_slots']) ? (string) $values['_luna_specialist_blocked_slots'] : ''
		);
                self::render_reviews_admin_field((int) $post->ID, $values['_luna_specialist_reviews']);
		self::render_text_field('لینک رزرو', 'luna_specialist_booking_url', $values['_luna_specialist_booking_url']);
		echo '</div>';
	}

	/**
	 * Save specialist meta values.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function save_meta_box($post_id) {
		if (! isset($_POST['luna_specialist_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luna_specialist_meta_nonce'])), 'luna_specialist_meta_box')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$previous_service_ids = self::get_assigned_service_ids($post_id);
		$new_service_ids      = $previous_service_ids;

		foreach (self::$meta_fields as $meta_key => $field_type) {
			$field_name = ltrim($meta_key, '_');
			$value      = isset($_POST[ $field_name ]) ? wp_unslash($_POST[ $field_name ]) : ('ids' === $field_type ? array() : '');
			$sanitized  = self::sanitize_meta_value($value, $meta_key);

			update_post_meta($post_id, $meta_key, $sanitized);

			if ('_luna_specialist_service_ids' === $meta_key) {
				$new_service_ids = is_array($sanitized) ? array_map('intval', $sanitized) : array();
			}
		}

		if (class_exists('Luna_Appointments_Services')) {
			Luna_Appointments_Services::sync_specialist_relationships($post_id, $new_service_ids, $previous_service_ids);
		}

                self::ensure_specialist_user_for_post($post_id);
	}

	/**
	 * Return normalized specialist meta values.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, string>
	 */
	public static function get_specialist_meta_values($post_id) {
		$values = array();

		foreach (self::$meta_fields as $meta_key => $field_type) {
			$value = get_post_meta($post_id, $meta_key, true);

			if ('ids' === $field_type) {
				$values[ $meta_key ] = is_array($value) ? array_map('intval', $value) : array();
				continue;
			}

			$values[ $meta_key ] = (string) $value;
		}

		return $values;
	}

	public static function get_assigned_service_ids($post_id) {
		$values = self::get_specialist_meta_values($post_id);

		return isset($values['_luna_specialist_service_ids']) && is_array($values['_luna_specialist_service_ids'])
			? array_values(array_unique(array_map('intval', $values['_luna_specialist_service_ids'])))
			: array();
	}

        /**
         * Return weekday labels keyed by working-day index.
         *
         * @return array<int, string>
         */
        public static function get_weekday_labels() {
                return array(
                        0 => __('شنبه', 'luna-appointments'),
                        1 => __('یکشنبه', 'luna-appointments'),
                        2 => __('دوشنبه', 'luna-appointments'),
                        3 => __('سه‌شنبه', 'luna-appointments'),
                        4 => __('چهارشنبه', 'luna-appointments'),
                        5 => __('پنج‌شنبه', 'luna-appointments'),
                        6 => __('جمعه', 'luna-appointments'),
                );
        }

        /**
         * Sync one frontend user for every specialist post.
         *
         * @return void
         */
        public static function sync_specialist_users() {
                $specialists = get_posts(
                        array(
                                'post_type'      => 'specialist',
                                'post_status'    => array('publish', 'draft', 'private'),
                                'posts_per_page' => -1,
                                'fields'         => 'ids',
                                'orderby'        => 'menu_order',
                                'order'          => 'ASC',
                        )
                );

                foreach ((array) $specialists as $specialist_id) {
                        self::ensure_specialist_user_for_post((int) $specialist_id);
                }
        }

        /**
         * Ensure the specialist has one linked frontend user.
         *
         * @param int $specialist_id Specialist post id.
         * @return int
         */
        public static function ensure_specialist_user_for_post($specialist_id) {
                $specialist_id = (int) $specialist_id;

                if ($specialist_id <= 0 || 'specialist' !== get_post_type($specialist_id)) {
                        return 0;
                }

                $linked_user_id = (int) get_post_meta($specialist_id, self::USER_LINK_META, true);
                $linked_user    = $linked_user_id > 0 ? get_userdata($linked_user_id) : false;

                if (! $linked_user instanceof WP_User) {
                        $candidate_ids = get_users(
                                array(
                                        'role__in' => array(self::ROLE),
                                        'meta_key' => self::USER_SPECIALIST_META,
                                        'meta_value' => (string) $specialist_id,
                                        'fields'   => 'ID',
                                        'number'   => 1,
                                )
                        );

                        if (! empty($candidate_ids)) {
                                $linked_user_id = (int) $candidate_ids[0];
                                $linked_user    = get_userdata($linked_user_id);
                        }
                }

                if (! $linked_user instanceof WP_User) {
                        $linked_user_id = self::create_specialist_user($specialist_id);
                        $linked_user    = $linked_user_id > 0 ? get_userdata($linked_user_id) : false;
                }

                if (! $linked_user instanceof WP_User) {
                        return 0;
                }

                if (! in_array(self::ROLE, (array) $linked_user->roles, true)) {
                        $linked_user->set_role(self::ROLE);
                }

                update_post_meta($specialist_id, self::USER_LINK_META, (int) $linked_user->ID);
                update_user_meta((int) $linked_user->ID, self::USER_SPECIALIST_META, $specialist_id);

                return (int) $linked_user->ID;
        }

        /**
         * Create one user for the specialist.
         *
         * @param int $specialist_id Specialist post id.
         * @return int
         */
        protected static function create_specialist_user($specialist_id) {
                $specialist = get_post($specialist_id);

                if (! $specialist instanceof WP_Post) {
                        return 0;
                }

                $slug          = $specialist->post_name ? $specialist->post_name : sanitize_title($specialist->post_title);
                $username_base = sanitize_user('specialist-' . $slug, true);
                $email_base    = sanitize_email(($slug ? $slug : 'specialist-' . $specialist_id) . '@specialist.lunacasa.local');
                $username      = self::get_unique_username($username_base ? $username_base : 'specialist-' . $specialist_id);
                $email         = self::get_unique_email($email_base ? $email_base : 'specialist-' . $specialist_id . '@specialist.lunacasa.local');
                $user_id       = wp_insert_user(
                        array(
                                'user_login'   => $username,
                                'user_pass'    => wp_generate_password(20, true, true),
                                'user_email'   => $email,
                                'display_name' => $specialist->post_title,
                                'nickname'     => $specialist->post_title,
                                'role'         => self::ROLE,
                        )
                );

                return is_wp_error($user_id) ? 0 : (int) $user_id;
        }

        /**
         * Return one unique username.
         *
         * @param string $base Base username.
         * @return string
         */
        protected static function get_unique_username($base) {
                $base    = sanitize_user((string) $base, true);
                $base    = $base ? $base : 'specialist';
                $current = $base;
                $suffix  = 2;

                while (username_exists($current)) {
                        $current = $base . '-' . $suffix;
                        $suffix++;
                }

                return $current;
        }

        /**
         * Return one unique placeholder email.
         *
         * @param string $base Base email.
         * @return string
         */
        protected static function get_unique_email($base) {
                $base        = sanitize_email((string) $base);
                $local_part  = 'specialist';
                $domain_part = 'specialist.lunacasa.local';
                $suffix      = 2;

                if ($base && false !== strpos($base, '@')) {
                        list($local_part, $domain_part) = explode('@', $base, 2);
                        $local_part  = sanitize_user($local_part, true) ? sanitize_user($local_part, true) : 'specialist';
                        $domain_part = $domain_part ? $domain_part : 'specialist.lunacasa.local';
                }

                $current = $local_part . '@' . $domain_part;

                while (email_exists($current)) {
                        $current = $local_part . '-' . $suffix . '@' . $domain_part;
                        $suffix++;
                }

                return $current;
        }

        /**
         * Return the linked specialist id for one user.
         *
         * @param int $user_id User id.
         * @return int
         */
        public static function get_linked_specialist_id($user_id) {
                $user_id       = (int) $user_id;
                $specialist_id = (int) get_user_meta($user_id, self::USER_SPECIALIST_META, true);

                if ($specialist_id > 0 && 'specialist' === get_post_type($specialist_id)) {
                        return $specialist_id;
                }

                $posts = get_posts(
                        array(
                                'post_type'      => 'specialist',
                                'post_status'    => array('publish', 'draft', 'private'),
                                'posts_per_page' => 1,
                                'fields'         => 'ids',
                                'meta_key'       => self::USER_LINK_META,
                                'meta_value'     => (string) $user_id,
                        )
                );

                return ! empty($posts) ? (int) $posts[0] : 0;
        }

        /**
         * Return the current specialist id for the logged-in user.
         *
         * @return int
         */
        public static function get_current_user_specialist_id() {
                if (! is_user_logged_in()) {
                        return 0;
                }

                return self::get_linked_specialist_id((int) get_current_user_id());
        }

        /**
         * Whether the current user is one specialist user.
         *
         * @return bool
         */
        public static function current_user_is_specialist() {
                if (! is_user_logged_in()) {
                        return false;
                }

                $user = wp_get_current_user();

                return in_array(self::ROLE, (array) $user->roles, true) && self::get_current_user_specialist_id() > 0;
        }

        /**
         * Return the frontend destination for specialist users.
         *
         * @return string
         */
        public static function get_specialist_frontend_url() {
		if (class_exists('Luna_Appointments_Specialist_PWA')) {
			return Luna_Appointments_Specialist_PWA::app_url();
		}

		return home_url('/specialist-app/');
        }

        /**
         * Block specialist users from wp-admin.
         *
         * @return void
         */
        public static function restrict_specialist_admin_access() {
                if (! is_user_logged_in() || ! self::current_user_is_specialist()) {
                        return;
                }

                if ((defined('DOING_AJAX') && DOING_AJAX) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
                        return;
                }

                if (defined('WP_CLI') && WP_CLI) {
                        return;
                }

                wp_safe_redirect(self::get_specialist_frontend_url());
                exit;
        }

        /**
         * Redirect specialist users away from the account dashboard to their profile page.
         *
         * @return void
         */
        public static function redirect_specialist_account_access() {
                if (! self::current_user_is_specialist() || is_admin()) {
                        return;
                }

                if ((defined('DOING_AJAX') && DOING_AJAX) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
                        return;
                }

                $is_account_screen = false;

                if (function_exists('is_account_page') && is_account_page()) {
                        $is_account_screen = true;
                } elseif (function_exists('is_page') && is_page(array('my-account', 'account'))) {
                        $is_account_screen = true;
                }

                if (! $is_account_screen) {
                        return;
                }

                wp_safe_redirect(self::get_specialist_frontend_url());
                exit;
        }

        /**
         * Redirect specialist users to their frontend profile after login.
         *
         * @param string           $redirect_to Requested redirect destination.
         * @param string           $requested_redirect_to Original requested redirect destination.
         * @param WP_User|WP_Error $user Logged-in user object.
         * @return string
         */
        public static function filter_specialist_login_redirect($redirect_to, $requested_redirect_to, $user) {
                if (! $user instanceof WP_User) {
                        return $redirect_to;
                }

                return in_array(self::ROLE, (array) $user->roles, true)
                        ? self::get_specialist_frontend_url()
                        : $redirect_to;
        }

        /**
         * Hide the admin bar for specialist users.
         *
         * @param bool $show Whether to show the admin bar.
         * @return bool
         */
        public static function filter_admin_bar_visibility($show) {
                return self::current_user_is_specialist() ? false : $show;
        }

        /**
         * Render the frontend profile editor for the logged-in specialist.
         *
         * @return string
         */
        public static function render_frontend_profile_editor() {
                $specialist_id = self::get_current_user_specialist_id();
                $specialist    = $specialist_id > 0 ? get_post($specialist_id) : null;
                $user          = is_user_logged_in() ? wp_get_current_user() : null;

                if (! $specialist instanceof WP_Post || ! $user instanceof WP_User) {
                        return '';
                }

                $meta         = self::get_specialist_meta_values($specialist_id);
                $weekdays     = self::get_weekday_labels();
                $working_days = isset($meta['_luna_specialist_working_days']) && is_array($meta['_luna_specialist_working_days']) ? array_map('intval', $meta['_luna_specialist_working_days']) : array(0, 1, 2, 3, 4, 5);
                $working_start = ! empty($meta['_luna_specialist_working_start']) ? (string) $meta['_luna_specialist_working_start'] : '10:00';
                $working_end   = ! empty($meta['_luna_specialist_working_end']) ? (string) $meta['_luna_specialist_working_end'] : '20:00';
                $thumb_id     = get_post_thumbnail_id($specialist_id);
                $image_url    = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium_large') : '';

                ob_start();
                ?>
                <section class="specialist-self-editor glass reveal" id="specialistProfileEditor" data-specialist-editor data-specialist-id="<?php echo esc_attr((string) $specialist_id); ?>">
                        <div class="specialist-self-editor__head">
                                <div>
                                        <span class="eyebrow"><?php esc_html_e('پنل متخصص', 'luna-appointments'); ?></span>
                                        <h2><?php esc_html_e('پروفایل من', 'luna-appointments'); ?></h2>
                                        <p><?php esc_html_e('از همین صفحه اطلاعات نمایشی و برنامه کاری خودتان را به‌روزرسانی کنید. این فرم فقط برای پروفایل خود شما فعال است.', 'luna-appointments'); ?></p>
                                </div>
                                <div class="specialist-self-editor__meta">
                                        <span><?php esc_html_e('نقش کاربری:', 'luna-appointments'); ?> <?php esc_html_e('متخصص', 'luna-appointments'); ?></span>
                                        <span><?php echo esc_html($user->user_login); ?></span>
                                </div>
                        </div>
                        <form class="specialist-profile-form" id="specialistProfileForm" enctype="multipart/form-data">
                                <div class="specialist-profile-grid">
                                        <label>
                                                <span><?php esc_html_e('نام نمایشی', 'luna-appointments'); ?></span>
                                                <input type="text" name="display_name" value="<?php echo esc_attr(get_the_title($specialist)); ?>" required>
                                        </label>
                                        <label>
                                                <span><?php esc_html_e('ایمیل ورود', 'luna-appointments'); ?></span>
                                                <input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" required>
                                        </label>
                                        <label>
                                                <span><?php esc_html_e('عنوان شغلی', 'luna-appointments'); ?></span>
                                                <input type="text" name="specialist_role" value="<?php echo esc_attr(isset($meta['_luna_specialist_role']) ? $meta['_luna_specialist_role'] : ''); ?>">
                                        </label>
                                        <label>
                                                <span><?php esc_html_e('تصویر پروفایل', 'luna-appointments'); ?></span>
                                                <input type="file" name="profile_image" accept="image/*">
                                        </label>
                                </div>

                                <div class="specialist-profile-preview">
                                        <div class="specialist-profile-preview__media"<?php echo $image_url ? ' style="background-image:url(' . esc_url($image_url) . ')"' : ''; ?> data-specialist-image-preview>
                                                <?php if (! $image_url) : ?>
                                                        <span><?php esc_html_e('بدون تصویر', 'luna-appointments'); ?></span>
                                                <?php endif; ?>
                                        </div>
                                        <p><?php esc_html_e('در صورت انتخاب تصویر جدید، همان تصویر شاخص متخصص شما به‌روزرسانی می‌شود.', 'luna-appointments'); ?></p>
                                </div>

                                <label>
                                        <span><?php esc_html_e('بیوگرافی', 'luna-appointments'); ?></span>
                                        <textarea name="specialist_bio" rows="4"><?php echo esc_textarea(isset($meta['_luna_specialist_bio']) ? $meta['_luna_specialist_bio'] : ''); ?></textarea>
                                </label>

                                <div class="specialist-profile-grid">
                                        <label>
                                                <span><?php esc_html_e('سوابق / تجربه', 'luna-appointments'); ?></span>
                                                <textarea name="specialist_history" rows="5"><?php echo esc_textarea(isset($meta['_luna_specialist_history']) ? $meta['_luna_specialist_history'] : ''); ?></textarea>
                                        </label>
                                        <label>
                                                <span><?php esc_html_e('مدارک و گواهی‌ها', 'luna-appointments'); ?></span>
                                                <textarea name="specialist_certifications" rows="5"><?php echo esc_textarea(isset($meta['_luna_specialist_certifications']) ? $meta['_luna_specialist_certifications'] : ''); ?></textarea>
                                        </label>
                                </div>

                                <label>
                                        <span><?php esc_html_e('برچسب‌های تخصص', 'luna-appointments'); ?></span>
                                        <input type="text" name="specialist_tags" value="<?php echo esc_attr(isset($meta['_luna_specialist_tags']) ? $meta['_luna_specialist_tags'] : ''); ?>" placeholder="<?php esc_attr_e('مثلاً رنگ مو، بالیاژ، تراپی مو', 'luna-appointments'); ?>">
                                </label>

                                <div class="specialist-profile-availability">
                                        <div class="specialist-profile-availability__head">
                                                <strong><?php esc_html_e('برنامه کاری و عدم حضور', 'luna-appointments'); ?></strong>
                                                <p><?php esc_html_e('روزها، ساعت کاری، تعطیلی‌ها و بازه‌های مسدود خودتان را از همین بخش تنظیم کنید. تاریخ شمسی و میلادی هر دو پذیرفته می‌شوند.', 'luna-appointments'); ?></p>
                                        </div>
                                        <div class="specialist-profile-weekdays">
                                                <?php foreach ($weekdays as $day_index => $label) : ?>
                                                        <label>
                                                                <input type="checkbox" name="working_days[]" value="<?php echo esc_attr((string) $day_index); ?>"<?php checked(in_array((int) $day_index, $working_days, true)); ?>>
                                                                <span><?php echo esc_html($label); ?></span>
                                                        </label>
                                                <?php endforeach; ?>
                                        </div>
                                        <div class="specialist-profile-grid">
                                                <label>
                                                        <span><?php esc_html_e('شروع ساعت کاری', 'luna-appointments'); ?></span>
                                                        <input type="time" name="working_start" value="<?php echo esc_attr($working_start); ?>">
                                                </label>
                                                <label>
                                                        <span><?php esc_html_e('پایان ساعت کاری', 'luna-appointments'); ?></span>
                                                        <input type="time" name="working_end" value="<?php echo esc_attr($working_end); ?>">
                                                </label>
                                        </div>
                                        <div class="specialist-profile-helper-grid">
                                                <label>
                                                        <span><?php esc_html_e('تعطیلی تک‌روزه', 'luna-appointments'); ?></span>
                                                        <input type="text" class="luna-specialist-jalali-date" data-target="specialist_off_dates" placeholder="<?php esc_attr_e('مثلاً ۱۴۰۵/۰۴/۱۵', 'luna-appointments'); ?>">
                                                        <button type="button" class="btn btn-outline" data-luna-add-off-date="specialist_off_dates"><?php esc_html_e('افزودن تعطیلی', 'luna-appointments'); ?></button>
                                                </label>
                                                <label>
                                                        <span><?php esc_html_e('شروع / پایان مرخصی', 'luna-appointments'); ?></span>
                                                        <div class="specialist-profile-range">
                                                                <input type="text" class="luna-specialist-jalali-date" data-range-start="specialist_leave_ranges" placeholder="<?php esc_attr_e('۱۴۰۵/۰۴/۱۵', 'luna-appointments'); ?>">
                                                                <input type="text" class="luna-specialist-jalali-date" data-range-end="specialist_leave_ranges" placeholder="<?php esc_attr_e('۱۴۰۵/۰۴/۲۰', 'luna-appointments'); ?>">
                                                        </div>
                                                        <button type="button" class="btn btn-outline" data-luna-add-range="specialist_leave_ranges"><?php esc_html_e('افزودن بازه مرخصی', 'luna-appointments'); ?></button>
                                                </label>
                                                <label>
                                                        <span><?php esc_html_e('تاریخ و ساعت مسدودی', 'luna-appointments'); ?></span>
                                                        <input type="text" class="luna-specialist-jalali-date" data-slot-date="specialist_blocked_slots" placeholder="<?php esc_attr_e('۱۴۰۵/۰۴/۱۵', 'luna-appointments'); ?>">
                                                        <div class="specialist-profile-range">
                                                                <input type="time" data-slot-start="specialist_blocked_slots" value="10:00">
                                                                <input type="time" data-slot-end="specialist_blocked_slots" value="12:00">
                                                        </div>
                                                        <button type="button" class="btn btn-outline" data-luna-add-slot="specialist_blocked_slots"><?php esc_html_e('افزودن بازه مسدود', 'luna-appointments'); ?></button>
                                                </label>
                                        </div>
                                        <div class="specialist-profile-grid">
                                                <label>
                                                        <span><?php esc_html_e('تاریخ‌های تعطیل', 'luna-appointments'); ?></span>
                                                        <textarea name="off_dates" rows="4"><?php echo esc_textarea(isset($meta['_luna_specialist_off_dates']) ? $meta['_luna_specialist_off_dates'] : ''); ?></textarea>
                                                </label>
                                                <label>
                                                        <span><?php esc_html_e('مرخصی / بازه‌های تعطیلی', 'luna-appointments'); ?></span>
                                                        <textarea name="leave_ranges" rows="4"><?php echo esc_textarea(isset($meta['_luna_specialist_leave_ranges']) ? $meta['_luna_specialist_leave_ranges'] : ''); ?></textarea>
                                                </label>
                                        </div>
                                        <label>
                                                <span><?php esc_html_e('بازه‌های مسدود ساعتی', 'luna-appointments'); ?></span>
                                                <textarea name="blocked_slots" rows="4"><?php echo esc_textarea(isset($meta['_luna_specialist_blocked_slots']) ? $meta['_luna_specialist_blocked_slots'] : ''); ?></textarea>
                                        </label>
                                </div>

                                <div class="specialist-profile-actions">
                                        <button type="submit" class="btn btn-gold"><?php esc_html_e('ذخیره تغییرات پروفایل', 'luna-appointments'); ?></button>
                                        <p class="specialist-profile-message" data-specialist-form-message aria-live="polite"></p>
                                </div>
                        </form>
                </section>
                <?php

                return (string) ob_get_clean();
        }

        /**
         * Handle frontend profile update.
         *
         * @return void
         */
        public static function handle_frontend_profile_update() {
                check_ajax_referer(self::PROFILE_NONCE_ACTION, 'nonce');

                if (! self::current_user_is_specialist()) {
                        wp_send_json_error(array('message' => __('شما اجازه ویرایش این پروفایل را ندارید.', 'luna-appointments')), 403);
                }

                $specialist_id = self::get_current_user_specialist_id();
                $user_id       = (int) get_current_user_id();
                $specialist    = get_post($specialist_id);
                $user          = get_userdata($user_id);

                if (! $specialist instanceof WP_Post || ! $user instanceof WP_User) {
                        wp_send_json_error(array('message' => __('پروفایل متخصص شما پیدا نشد.', 'luna-appointments')), 404);
                }

                $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
                $user_email   = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';

                if ('' === $display_name) {
                        wp_send_json_error(array('message' => __('نام نمایشی نمی‌تواند خالی باشد.', 'luna-appointments')), 422);
                }

                if (! is_email($user_email)) {
                        wp_send_json_error(array('message' => __('ایمیل واردشده معتبر نیست.', 'luna-appointments')), 422);
                }

                $email_owner = email_exists($user_email);
                if ($email_owner && (int) $email_owner !== $user_id) {
                        wp_send_json_error(array('message' => __('این ایمیل قبلاً توسط کاربر دیگری استفاده شده است.', 'luna-appointments')), 422);
                }

                $user_result = wp_update_user(
                        array(
                                'ID'           => $user_id,
                                'display_name' => $display_name,
                                'nickname'     => $display_name,
                                'user_email'   => $user_email,
                        )
                );

                if (is_wp_error($user_result)) {
                        wp_send_json_error(array('message' => $user_result->get_error_message()), 500);
                }

                wp_update_post(
                        array(
                                'ID'         => $specialist_id,
                                'post_title' => $display_name,
                        )
                );

                $updates = array(
                        '_luna_specialist_role'           => isset($_POST['specialist_role']) ? wp_unslash($_POST['specialist_role']) : '',
                        '_luna_specialist_bio'            => isset($_POST['specialist_bio']) ? wp_unslash($_POST['specialist_bio']) : '',
                        '_luna_specialist_history'        => isset($_POST['specialist_history']) ? wp_unslash($_POST['specialist_history']) : '',
                        '_luna_specialist_tags'           => isset($_POST['specialist_tags']) ? wp_unslash($_POST['specialist_tags']) : '',
                        '_luna_specialist_certifications' => isset($_POST['specialist_certifications']) ? wp_unslash($_POST['specialist_certifications']) : '',
                        '_luna_specialist_working_days'   => isset($_POST['working_days']) ? (array) wp_unslash($_POST['working_days']) : array(),
                        '_luna_specialist_working_start'  => isset($_POST['working_start']) ? wp_unslash($_POST['working_start']) : '',
                        '_luna_specialist_working_end'    => isset($_POST['working_end']) ? wp_unslash($_POST['working_end']) : '',
                        '_luna_specialist_off_dates'      => isset($_POST['off_dates']) ? wp_unslash($_POST['off_dates']) : '',
                        '_luna_specialist_leave_ranges'   => isset($_POST['leave_ranges']) ? wp_unslash($_POST['leave_ranges']) : '',
                        '_luna_specialist_blocked_slots'  => isset($_POST['blocked_slots']) ? wp_unslash($_POST['blocked_slots']) : '',
                );

                foreach ($updates as $meta_key => $value) {
                        update_post_meta($specialist_id, $meta_key, self::sanitize_meta_value($value, $meta_key));
                }

                if (! empty($_FILES['profile_image']) && ! empty($_FILES['profile_image']['name'])) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/media.php';
                        require_once ABSPATH . 'wp-admin/includes/image.php';

                        $attachment_id = media_handle_upload('profile_image', 0);

                        if (is_wp_error($attachment_id)) {
                                wp_send_json_error(array('message' => $attachment_id->get_error_message()), 422);
                        }

                        set_post_thumbnail($specialist_id, (int) $attachment_id);
                }

                self::ensure_specialist_user_for_post($specialist_id);

		$payload = self::get_public_payload($specialist_id);

                wp_send_json_success(
                        array(
                                'message'    => __('پروفایل متخصص با موفقیت به‌روزرسانی شد.', 'luna-appointments'),
                                'specialist' => $payload,
                        )
                );
        }

        public static function handle_frontend_review_submit() {
                check_ajax_referer(self::REVIEW_NONCE_ACTION, 'nonce');

                $specialist_post_id = isset($_POST['specialistPostId']) ? (int) wp_unslash($_POST['specialistPostId']) : 0;
                $specialist_slug    = isset($_POST['specialistId']) ? sanitize_title(wp_unslash($_POST['specialistId'])) : '';
                $review_name        = isset($_POST['reviewName']) ? sanitize_text_field(wp_unslash($_POST['reviewName'])) : '';
                $review_text        = isset($_POST['reviewText']) ? sanitize_textarea_field(wp_unslash($_POST['reviewText'])) : '';
                $review_rating      = isset($_POST['reviewRating']) ? (int) wp_unslash($_POST['reviewRating']) : 0;

                if ($specialist_post_id <= 0 && '' !== $specialist_slug) {
                        $specialist = get_page_by_path($specialist_slug, OBJECT, 'specialist');
                        $specialist_post_id = $specialist instanceof WP_Post ? (int) $specialist->ID : 0;
                }

                if ($specialist_post_id <= 0 || 'specialist' !== get_post_type($specialist_post_id)) {
                        wp_send_json_error(array('message' => __('پروفایل متخصص پیدا نشد.', 'luna-appointments')), 404);
                }

                if ('' === $review_name || '' === $review_text || $review_rating < 1 || $review_rating > 5) {
                        wp_send_json_error(array('message' => __('نام، امتیاز و متن نظر را کامل کنید.', 'luna-appointments')), 422);
                }

                $existing_reviews = (string) get_post_meta($specialist_post_id, '_luna_specialist_reviews', true);
                $review_lines     = self::parse_reviews_meta($existing_reviews);
                $review_id        = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('review_', true);
                array_unshift(
                        $review_lines,
                        array(
                        'id'     => $review_id,
                        'name'   => $review_name,
                        'rating' => max(1, min(5, $review_rating)),
                        'text'   => $review_text,
                        'date'   => time(),
                        'status' => 'pending',
                        )
                );

                update_post_meta(
                        $specialist_post_id,
                        '_luna_specialist_reviews',
                        self::sanitize_meta_value(self::encode_reviews_meta($review_lines), '_luna_specialist_reviews')
                );

                self::refresh_specialist_review_summary($specialist_post_id);

		$payload = self::get_public_payload($specialist_post_id);

                wp_send_json_success(
                        array(
                                'message'    => __('نظر شما ثبت شد و پس از تایید نمایش داده می‌شود.', 'luna-appointments'),
                                'specialist' => $payload,
                        )
                );
        }

	/**
	 * Domain-owned specialist representation for AJAX and integrations.
	 * Presentation layers may enrich it through the public filter.
	 *
	 * @param int $specialist_id Specialist post id.
	 * @return array<string,mixed>
	 */
	public static function get_public_payload($specialist_id) {
		$post = get_post((int) $specialist_id);
		if (! $post instanceof WP_Post || 'specialist' !== $post->post_type) {
			return array();
		}

		$payload = array(
			'id'      => (int) $post->ID,
			'slug'    => (string) $post->post_name,
			'name'    => get_the_title($post),
			'url'     => get_permalink($post),
			'image'   => get_the_post_thumbnail_url($post, 'large') ?: '',
			'role'    => (string) get_post_meta($post->ID, '_luna_specialist_role', true),
			'bio'     => (string) get_post_meta($post->ID, '_luna_specialist_bio', true),
			'rating'  => (float) get_post_meta($post->ID, '_luna_specialist_rating', true),
			'reviews' => (int) get_post_meta($post->ID, '_luna_specialist_review_count', true),
		);

		return (array) apply_filters('luna_appointments_specialist_payload', $payload, (int) $post->ID);
	}

	protected static function refresh_specialist_review_summary($specialist_post_id) {
                $specialist_post_id = (int) $specialist_post_id;
                $raw_reviews        = (string) get_post_meta($specialist_post_id, '_luna_specialist_reviews', true);
                $review_lines       = self::parse_reviews_meta($raw_reviews);
                $review_count       = 0;
                $rating_sum         = 0;

                foreach ((array) $review_lines as $review) {
                        $status = isset($review['status']) ? (string) $review['status'] : 'approved';
                        $name   = isset($review['name']) ? trim((string) $review['name']) : '';
                        $text   = isset($review['text']) ? trim((string) $review['text']) : '';
                        $rate   = isset($review['rating']) ? (int) $review['rating'] : 0;

                        if ('approved' !== $status || '' === $name || '' === $text) {
                                continue;
                        }

                        $review_count++;
                        $rating_sum += max(1, min(5, $rate > 0 ? $rate : 5));
                }

                update_post_meta($specialist_post_id, '_luna_specialist_review_count', (string) $review_count);
                update_post_meta(
                        $specialist_post_id,
                        '_luna_specialist_rating',
                        (string) ($review_count > 0 ? round($rating_sum / $review_count, 1) : 5)
                );
        }

        public static function handle_admin_review_manage() {
                check_ajax_referer(self::REVIEW_ADMIN_NONCE_ACTION, 'nonce');

                $specialist_post_id = isset($_POST['specialistPostId']) ? (int) wp_unslash($_POST['specialistPostId']) : 0;
                $review_id          = isset($_POST['reviewId']) ? sanitize_text_field(wp_unslash($_POST['reviewId'])) : '';
                $command            = isset($_POST['command']) ? sanitize_key(wp_unslash($_POST['command'])) : '';

                if ($specialist_post_id <= 0 || 'specialist' !== get_post_type($specialist_post_id)) {
                        wp_send_json_error(array('message' => __('پروفایل متخصص پیدا نشد.', 'luna-appointments')), 404);
                }

                if (! current_user_can('manage_options')) {
                        wp_send_json_error(array('message' => __('شما اجازه مدیریت این نظر را ندارید.', 'luna-appointments')), 403);
                }

                if ('' === $review_id || ! in_array($command, array('approve', 'delete'), true)) {
                        wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'luna-appointments')), 422);
                }

                $raw_reviews = (string) get_post_meta($specialist_post_id, '_luna_specialist_reviews', true);
                $reviews     = self::parse_reviews_meta($raw_reviews);
                $updated     = null;
                $next        = array();
                $found       = false;

                foreach ((array) $reviews as $review) {
                        if (! isset($review['id']) || (string) $review['id'] !== $review_id) {
                                $next[] = $review;
                                continue;
                        }

                        $found = true;
                        if ('delete' === $command) {
                                continue;
                        }

                        $review['status'] = 'approved';
                        $updated          = $review;
                        $next[]           = $review;
                }

                if (! $found) {
                        wp_send_json_error(array('message' => __('نظر مورد نظر پیدا نشد.', 'luna-appointments')), 404);
                }

                update_post_meta(
                        $specialist_post_id,
                        '_luna_specialist_reviews',
                        self::sanitize_meta_value(self::encode_reviews_meta($next), '_luna_specialist_reviews')
                );
                self::refresh_specialist_review_summary($specialist_post_id);

                wp_send_json_success(
                        array(
                                'review'   => $updated,
                                'raw'      => (string) get_post_meta($specialist_post_id, '_luna_specialist_reviews', true),
                                'summary'  => array(
                                        'rating'      => (string) get_post_meta($specialist_post_id, '_luna_specialist_rating', true),
                                        'reviewCount' => (string) get_post_meta($specialist_post_id, '_luna_specialist_review_count', true),
                                ),
                                'message'  => 'delete' === $command ? __('نظر حذف شد.', 'luna-appointments') : __('نظر تایید شد.', 'luna-appointments'),
                        )
                );
        }

        protected static function parse_reviews_meta($raw) {
                $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
                $lines = is_array($lines) ? array_values(array_filter(array_map('trim', $lines))) : array();
                $items = array();

                foreach ($lines as $line) {
                        $parts  = array_map('trim', explode('|', (string) $line));
                        $name   = isset($parts[0]) ? $parts[0] : '';
                        $text   = isset($parts[2]) ? $parts[2] : '';

                        if ('' === $name || '' === $text) {
                                continue;
                        }

                        $rating = isset($parts[1]) ? (int) $parts[1] : 5;
                        $date   = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : 0;
                        $status = isset($parts[4]) ? sanitize_key($parts[4]) : 'approved';
                        $id     = isset($parts[5]) && '' !== $parts[5] ? sanitize_text_field($parts[5]) : md5($line);

                        $items[] = array(
                                'id'     => $id,
                                'name'   => $name,
                                'rating' => max(1, min(5, $rating > 0 ? $rating : 5)),
                                'text'   => $text,
                                'date'   => $date,
                                'status' => in_array($status, array('approved', 'pending'), true) ? $status : 'approved',
                        );
                }

                return $items;
        }

        protected static function encode_reviews_meta($reviews) {
                $lines = array();

                foreach ((array) $reviews as $review) {
                        $id     = isset($review['id']) ? sanitize_text_field((string) $review['id']) : '';
                        $name   = isset($review['name']) ? (string) $review['name'] : '';
                        $text   = isset($review['text']) ? (string) $review['text'] : '';
                        $rating = isset($review['rating']) ? (int) $review['rating'] : 5;
                        $date   = isset($review['date']) ? (int) $review['date'] : 0;
                        $status = isset($review['status']) ? sanitize_key((string) $review['status']) : 'approved';

                        if ('' === trim($name) || '' === trim($text)) {
                                continue;
                        }

                        $lines[] = sprintf(
                                '%s|%d|%s|%d|%s|%s',
                                str_replace(array('|', "\r", "\n"), ' ', trim($name)),
                                max(1, min(5, $rating > 0 ? $rating : 5)),
                                str_replace(array('|', "\r", "\n"), ' ', trim($text)),
                                $date > 0 ? $date : 0,
                                in_array($status, array('approved', 'pending'), true) ? $status : 'approved',
                                '' !== $id ? str_replace(array('|', "\r", "\n"), '', $id) : md5($name . '|' . $text . '|' . $date)
                        );
                }

                return implode("\n", $lines);
        }

        protected static function render_reviews_admin_field($post_id, $raw) {
                $post_id = (int) $post_id;
                $reviews = self::parse_reviews_meta($raw);

                echo '<div style="display:grid;gap:10px;">';
                echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
                echo '<div style="display:grid;gap:4px;">';
                echo '<span style="font-weight:600;">' . esc_html__('نظرات', 'luna-appointments') . '</span>';
                echo '<small style="color:#666;">' . esc_html__('نظرهای جدید ابتدا در انتظار تایید هستند و بعد از تایید نمایش داده می‌شوند.', 'luna-appointments') . '</small>';
                echo '</div>';
                echo '<span class="luna-review-count" style="font-size:12px;color:#666;">' . esc_html(number_format_i18n(count($reviews))) . ' ' . esc_html__('نظر', 'luna-appointments') . '</span>';
                echo '</div>';

                echo '<div class="luna-specialist-reviews-admin" data-specialist-id="' . esc_attr((string) $post_id) . '" style="display:grid;gap:10px;">';
                echo '<div class="luna-review-notice" style="display:none;padding:10px 12px;border-radius:10px;font-size:12px;"></div>';

                if (empty($reviews)) {
                        echo '<div class="luna-review-empty" style="padding:14px;border:1px solid #e5e5e5;border-radius:10px;background:#fff;">' . esc_html__('هنوز نظری ثبت نشده است.', 'luna-appointments') . '</div>';
                } else {
                        foreach ($reviews as $review) {
                                $status = isset($review['status']) ? (string) $review['status'] : 'approved';
                                $date   = isset($review['date']) && (int) $review['date'] > 0 ? wp_date('Y/m/d H:i', (int) $review['date']) : '';

                                echo '<div class="luna-review-row" data-review-id="' . esc_attr((string) $review['id']) . '" style="display:grid;gap:10px;padding:14px;border:1px solid #e5e5e5;border-radius:12px;background:#fff;">';
                                echo '<div style="display:flex;gap:12px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;">';
                                echo '<div style="display:grid;gap:4px;min-width:240px;">';
                                echo '<strong>' . esc_html($review['name']) . '</strong>';
                                echo '<span style="color:#666;font-size:12px;">' . esc_html(str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating'])) . ($date ? ' · ' . esc_html($date) : '') . '</span>';
                                echo '</div>';
                                echo '<div style="display:flex;gap:8px;align-items:center;">';
                                echo '<span class="luna-review-status" style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;' . ('pending' === $status ? 'background:#fff7e6;color:#8a5a00;border:1px solid #ffe1a6;' : 'background:#e8fff2;color:#0a6b3a;border:1px solid #b8f0cf;') . '">' . esc_html('pending' === $status ? __('در انتظار تایید', 'luna-appointments') : __('تایید شده', 'luna-appointments')) . '</span>';
                                if ('pending' === $status) {
                                        echo '<button type="button" class="button button-primary luna-review-action" data-command="approve">' . esc_html__('تایید', 'luna-appointments') . '</button>';
                                }
                                echo '<button type="button" class="button luna-review-action" data-command="delete">' . esc_html__('حذف', 'luna-appointments') . '</button>';
                                echo '</div>';
                                echo '</div>';
                                echo '<div style="color:#333;font-size:13px;line-height:1.85;">' . esc_html($review['text']) . '</div>';
                                echo '</div>';
                        }
                }

                echo '</div>';
                echo '<textarea name="luna_specialist_reviews" rows="5" class="widefat" style="display:none;">' . esc_textarea((string) $raw) . '</textarea>';
                echo '</div>';
        }

	public static function sync_service_relationships($service_id, $new_specialist_ids, $old_specialist_ids = array()) {
		$service_id          = (int) $service_id;
		$new_specialist_ids  = array_values(array_unique(array_map('intval', (array) $new_specialist_ids)));
		$old_specialist_ids  = array_values(array_unique(array_map('intval', (array) $old_specialist_ids)));
		$specialist_ids      = array_values(array_unique(array_merge($new_specialist_ids, $old_specialist_ids)));

		if ($service_id <= 0) {
			return;
		}

		foreach ($specialist_ids as $specialist_id) {
			if ($specialist_id <= 0) {
				continue;
			}

			$assigned_service_ids = self::get_assigned_service_ids($specialist_id);
			$should_assign        = in_array($specialist_id, $new_specialist_ids, true);

			if ($should_assign && ! in_array($service_id, $assigned_service_ids, true)) {
				$assigned_service_ids[] = $service_id;
			}

			if (! $should_assign && in_array($service_id, $assigned_service_ids, true)) {
				$assigned_service_ids = array_values(array_diff($assigned_service_ids, array($service_id)));
			}

			update_post_meta($specialist_id, '_luna_specialist_service_ids', array_values(array_unique(array_map('intval', $assigned_service_ids))));
		}
	}

	/**
	 * Seed specialists into the CPT.
	 *
	 * @return void
	 */
	public static function ensure_seeded_posts() {
		foreach (self::get_seed_specialists() as $seed) {
			$post = get_page_by_path($seed['slug'], OBJECT, 'specialist');
                        $is_new_post = false;

			if (! $post instanceof WP_Post) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => 'specialist',
						'post_status' => 'publish',
						'post_title'  => $seed['title'],
						'post_name'   => $seed['slug'],
						'menu_order'  => isset($seed['menu_order']) ? (int) $seed['menu_order'] : 0,
					),
					true
				);

				if (is_wp_error($post_id) || ! $post_id) {
					continue;
				}

				$post = get_post((int) $post_id);
                                $is_new_post = true;
			}

			if (! $post instanceof WP_Post) {
				continue;
			}

			foreach ($seed['meta'] as $meta_key => $value) {
                                if ($is_new_post || ! metadata_exists('post', $post->ID, $meta_key)) {
                                        update_post_meta($post->ID, $meta_key, $value);
                                }
			}
		}
	}

	/**
	 * Default specialist seeds used by the archive.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_seed_specialists() {
		return array(
			array(
				'slug'       => 'negar-orangi',
				'title'      => 'Negar Orangi',
				'menu_order' => 1,
				'meta'       => array(
                                        '_luna_specialist_role'           => 'استایلیست ارشد مو',
					'_luna_specialist_rating'         => '4.9',
					'_luna_specialist_review_count'   => '142',
                                        '_luna_specialist_bio'            => 'بیش از ۱۰ سال تجربه در رنگ، استایلینگ و تراپی مو با تمرکز ویژه روی بالیاژ و رنگ‌های طبیعی و تمیز.',
					'_luna_specialist_tone_start'     => '#352f29',
					'_luna_specialist_tone_end'       => '#c9a86a',
                                        '_luna_specialist_history'        => "۲۰۱۹ تا ۲۰۲۲ - رنگ‌کار ارشد در یکی از سالن‌های مطرح تهران\n۲۰۲۲ تا امروز - استایلیست ارشد لونا",
                                        '_luna_specialist_tags'           => 'رنگ مو، بالیاژ، کوتاهی ژورنالی، تراپی مو',
                                        '_luna_specialist_certifications' => "گواهی پیشرفته تکنیک‌های رنگ مو\nمدرک فنی و حرفه‌ای آرایشگری",
                                        '_luna_specialist_services'       => "رنگ مو|از ۸۵۰٬۰۰۰ تومان\nبالیاژ|از ۱٬۲۰۰٬۰۰۰ تومان\nکات و استایل|از ۴۵۰٬۰۰۰ تومان",
					'_luna_specialist_service_ids'    => self::resolve_service_ids(array('hair-color', 'balayage')),
                                        '_luna_specialist_reviews'        => "Maryam K.|5|رنگ مو دقیقاً همان چیزی شد که می‌خواستم.\nElham R.|5|خیلی باحوصله و دقیق، نتیجه هم فوق‌العاده بود.",
					'_luna_specialist_booking_url'    => '',
				),
			),
			array(
				'slug'       => 'dr-aida-karimi',
				'title'      => 'Dr. Aida Karimi',
				'menu_order' => 2,
				'meta'       => array(
                                        '_luna_specialist_role'           => 'متخصص پوست و زیبایی',
					'_luna_specialist_rating'         => '4.8',
					'_luna_specialist_review_count'   => '98',
                                        '_luna_specialist_bio'            => 'پزشک با تمرکز بر خدمات پوست و زیبایی، تزریقات زیبایی و جوان‌سازی با رویکرد محافظه‌کارانه و نتیجه‌محور.',
					'_luna_specialist_tone_start'     => '#2e3a36',
					'_luna_specialist_tone_end'       => '#7d9a8e',
                                        '_luna_specialist_history'        => "۲۰۲۰ - فارغ‌التحصیل پزشکی عمومی\n۲۰۲۱ تا امروز - متخصص کلینیک لونا",
                                        '_luna_specialist_tags'           => 'تزریقات زیبایی، لیزر، جوان‌سازی پوست',
                                        '_luna_specialist_certifications' => "گواهی تخصصی تزریقات زیبایی\nگواهی کار با دستگاه‌های لیزر پوست",
                                        '_luna_specialist_services'       => "بوتاکس|از ۲٬۵۰۰٬۰۰۰ تومان\nفیلر لب|از ۳٬۰۰۰٬۰۰۰ تومان\nلیزر موهای زائد|از ۶۰۰٬۰۰۰ تومان",
					'_luna_specialist_service_ids'    => self::resolve_service_ids(array('botox')),
                                        '_luna_specialist_reviews'        => "Sahar M.|5|بسیار دقیق و حرفه‌ای بود.\nNazanin T.|4|نتیجه طبیعی و زیبا شد.",
					'_luna_specialist_booking_url'    => '',
				),
			),
			array(
				'slug'       => 'taraneh-rezaei',
				'title'      => 'Taraneh Rezaei',
				'menu_order' => 3,
				'meta'       => array(
                                        '_luna_specialist_role'           => 'متخصص ناخن',
					'_luna_specialist_rating'         => '4.9',
					'_luna_specialist_review_count'   => '176',
                                        '_luna_specialist_bio'            => 'بیش از ۷ سال تجربه در ژلیش، طراحی ناخن و اکستنشن با امضای کاری مینیمال و ماندگار.',
					'_luna_specialist_tone_start'     => '#3a2630',
					'_luna_specialist_tone_end'       => '#c98a9a',
                                        '_luna_specialist_history'        => "۲۰۱۷ تا ۲۰۲۱ - نیل‌آرتیست مستقل\n۲۰۲۱ تا امروز - متخصص ناخن لونا",
                                        '_luna_specialist_tags'           => 'ژلیش، اکستنشن، نیل‌آرت، ترمیم ناخن',
                                        '_luna_specialist_certifications' => 'گواهی تخصصی اکستنشن و ترمیم ناخن',
                                        '_luna_specialist_services'       => "ژلیش|از ۴۵۰٬۰۰۰ تومان\nاکستنشن ناخن|از ۹۰۰٬۰۰۰ تومان\nطراحی سفارشی|از ۲۵۰٬۰۰۰ تومان",
					'_luna_specialist_service_ids'    => self::resolve_service_ids(array('gelish-manicure')),
                                        '_luna_specialist_reviews'        => "Parisa A.|5|کار بسیار تمیز و دقیق بود.\nYegane M.|5|ژلیشم واقعاً ماندگاری عالی داشت.",
					'_luna_specialist_booking_url'    => '',
				),
			),
			array(
				'slug'       => 'sara-mohammadi',
				'title'      => 'Sara Mohammadi',
				'menu_order' => 4,
				'meta'       => array(
                                        '_luna_specialist_role'           => 'میکاپ آرتیست و عروس',
					'_luna_specialist_rating'         => '5',
					'_luna_specialist_review_count'   => '210',
                                        '_luna_specialist_bio'            => 'بیش از ۸ سال تجربه در میکاپ عروس و مراسم با تخصص در فینیش طبیعی، ماندگار و مناسب پوست‌های حساس.',
					'_luna_specialist_tone_start'     => '#3a2a1f',
					'_luna_specialist_tone_end'       => '#d9a05a',
                                        '_luna_specialist_history'        => "۲۰۱۶ تا ۲۰۲۰ - میکاپ‌آرتیست فریلنس\n۲۰۲۰ تا امروز - میکاپ‌آرتیست ارشد لونا",
                                        '_luna_specialist_tags'           => 'میکاپ عروس، میکاپ مجلسی، میکاپ نچرال',
                                        '_luna_specialist_certifications' => 'گواهی بین‌المللی میکاپ آرتیست',
                                        '_luna_specialist_services'       => "میکاپ عروس|از ۴٬۵۰۰٬۰۰۰ تومان\nمیکاپ مجلسی|از ۱٬۸۰۰٬۰۰۰ تومان",
					'_luna_specialist_service_ids'    => self::resolve_service_ids(array('bridal-makeup')),
                                        '_luna_specialist_reviews'        => "Negin K.|5|میکاپ عروس من دقیقاً همان چیزی شد که می‌خواستم.\nFateme A.|5|تا آخر مراسم کاملاً سالم و زیبا ماند.",
					'_luna_specialist_booking_url'    => '',
				),
			),
			array(
				'slug'       => 'hanieh-nouri',
				'title'      => 'Hanieh Nouri',
				'menu_order' => 5,
				'meta'       => array(
                                        '_luna_specialist_role'           => 'متخصص مژه و ابرو',
					'_luna_specialist_rating'         => '4.8',
					'_luna_specialist_review_count'   => '133',
                                        '_luna_specialist_bio'            => 'متخصص اکستنشن مژه و میکروبلیدینگ با نگاه دقیق به تناسب فرم چهره و جزئیات ظریف.',
					'_luna_specialist_tone_start'     => '#23252b',
					'_luna_specialist_tone_end'       => '#7d8aa3',
                                        '_luna_specialist_history'        => "۲۰۱۹ تا ۲۰۲۲ - متخصص مژه و ابرو در چند سالن تهران\n۲۰۲۲ تا امروز - متخصص لونا سالن و کلینیک",
                                        '_luna_specialist_tags'           => 'اکستنشن مژه، لیفت مژه، میکروبلیدینگ، شیدینگ ابرو',
                                        '_luna_specialist_certifications' => "گواهی تخصصی میکروبلیدینگ\nگواهی اکستنشن والیوم مژه",
                                        '_luna_specialist_services'       => "اکستنشن والیوم مژه|از ۷۵۰٬۰۰۰ تومان\nمیکروبلیدینگ|از ۲٬۲۰۰٬۰۰۰ تومان",
					'_luna_specialist_service_ids'    => self::resolve_service_ids(array('volume-lash-extensions')),
                                        '_luna_specialist_reviews'        => "Melika S.|5|نتیجه خیلی طبیعی و خوش‌فرم بود.\nReyhane M.|4|بسیار حرفه‌ای و دقیق.",
					'_luna_specialist_booking_url'    => '',
				),
			),
		);
	}

	/**
	 * Sanitize specialist meta values.
	 *
	 * @param mixed       $value Raw value.
	 * @param string|null $meta_key Meta key.
	 * @return string
	 */
	public static function sanitize_meta_value($value, $meta_key = null) {
		$normalized = is_scalar($value) ? (string) $value : '';

		if ('_luna_specialist_rating' === $meta_key) {
			$number = self::normalize_rating_value($normalized);

			return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
		}

		if ('_luna_specialist_review_count' === $meta_key) {
			return (string) self::normalize_review_count($normalized);
		}

		if (in_array($meta_key, array('_luna_specialist_tone_start', '_luna_specialist_tone_end'), true)) {
			$color = sanitize_hex_color($normalized);

			return $color ? $color : '';
		}

		if ('_luna_specialist_booking_url' === $meta_key) {
			return esc_url_raw($normalized);
		}

		if ('_luna_specialist_service_ids' === $meta_key) {
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

		if ('_luna_specialist_working_days' === $meta_key) {
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
						return $item >= 0 && $item <= 6;
					}
				)
			);
		}

		if (in_array($meta_key, array('_luna_specialist_working_start', '_luna_specialist_working_end'), true)) {
			$normalized = trim($normalized);

			return preg_match('/^\d{2}:\d{2}$/', $normalized) ? $normalized : '';
		}

		if ('_luna_specialist_off_dates' === $meta_key) {
			$lines = preg_split('/\r\n|\r|\n/', (string) $value);

			return implode(
				"\n",
				array_values(
					array_filter(
						array_map(
                                                        static function ($line) {
                                                                return self::normalize_schedule_date_input($line);
							},
							is_array($lines) ? $lines : array()
						),
						static function ($line) {
							return '' !== $line;
						}
					)
				)
			);
		}

				if ('_luna_specialist_leave_ranges' === $meta_key) {
						return self::sanitize_date_ranges_meta($value);
				}

				if ('_luna_specialist_blocked_slots' === $meta_key) {
						return self::sanitize_blocked_slots_meta($value);
				}

		return trim(wp_kses_post($normalized));
	}

	/**
	 * Normalize a localized rating without relying on PHP numeric coercion.
	 *
	 * @param string $value Raw rating.
	 * @return float
	 */
	protected static function normalize_rating_value($value) {
		$normalized = self::translate_localized_digits($value);
		$normalized = str_replace(array('٬', '،', ','), array('', '.', '.'), $normalized);

		if (! preg_match('/[-+]?\d+(?:\.\d+)?/', $normalized, $matches)) {
			return 0.0;
		}

		return max(0.0, min(5.0, (float) $matches[0]));
	}

	/**
	 * Normalize localized review counts and discard labels/separators safely.
	 *
	 * @param string $value Raw review count.
	 * @return int
	 */
	protected static function normalize_review_count($value) {
		$normalized = self::translate_localized_digits($value);
		$digits     = preg_replace('/[^0-9]/', '', $normalized);

		return '' === $digits ? 0 : max(0, (int) $digits);
	}

	/**
	 * Convert Persian and Arabic digits/decimal marks to their Latin forms.
	 *
	 * @param string $value Localized value.
	 * @return string
	 */
	protected static function translate_localized_digits($value) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٫' => '.',
			)
		);
	}

	protected static function resolve_service_ids($slugs) {
		$ids = array();

		foreach ((array) $slugs as $slug) {
			$post = get_page_by_path((string) $slug, OBJECT, 'service');

			if ($post instanceof WP_Post) {
				$ids[] = (int) $post->ID;
			}
		}

		return array_values(array_unique($ids));
	}

	protected static function render_service_relations_field($selected_ids) {
		$selected_ids = is_array($selected_ids) ? array_map('intval', $selected_ids) : array();
		$services     = class_exists('Luna_Appointments_Services') ? Luna_Appointments_Services::query_services(false) : array();

		echo '<div style="display:grid;gap:8px;">';
		echo '<span style="font-weight:600;">' . esc_html__('خدمات قابل رزرو این متخصص', 'luna-appointments') . '</span>';

		if (empty($services)) {
			echo '<small style="color:#666;">' . esc_html__('هنوز خدمتی ثبت نشده است. ابتدا خدمات را ایجاد کنید، سپس آن‌ها را به این متخصص اختصاص دهید.', 'luna-appointments') . '</small>';
			echo '</div>';
			return;
		}

		echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;padding:12px 14px;border:1px solid #e1e1e1;border-radius:10px;background:#fff;">';
		foreach ($services as $service) {
			if (! $service instanceof WP_Post) {
				continue;
			}

			echo '<label style="display:flex;align-items:center;gap:8px;">';
			echo '<input type="checkbox" name="luna_specialist_service_ids[]" value="' . esc_attr((string) $service->ID) . '"' . checked(in_array((int) $service->ID, $selected_ids, true), true, false) . '>';
			echo '<span>' . esc_html(get_the_title($service)) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '<small style="color:#666;">' . esc_html__('فقط خدمات انتخاب‌شده برای این متخصص در فرآیند رزرو نمایش داده می‌شوند.', 'luna-appointments') . '</small>';
		echo '</div>';
	}

        protected static function render_booking_availability_fields($working_days, $working_start, $working_end, $off_dates, $leave_ranges = '', $blocked_slots = '') {
		$working_days  = is_array($working_days) ? array_map('intval', $working_days) : array();
		$working_start = preg_match('/^\d{2}:\d{2}$/', (string) $working_start) ? (string) $working_start : '10:00';
		$working_end   = preg_match('/^\d{2}:\d{2}$/', (string) $working_end) ? (string) $working_end : '20:00';

		$weekdays = array(
			0 => __('شنبه', 'luna-appointments'),
			1 => __('یکشنبه', 'luna-appointments'),
			2 => __('دوشنبه', 'luna-appointments'),
			3 => __('سه‌شنبه', 'luna-appointments'),
			4 => __('چهارشنبه', 'luna-appointments'),
			5 => __('پنج‌شنبه', 'luna-appointments'),
			6 => __('جمعه', 'luna-appointments'),
		);

                echo '<div class="luna-specialist-availability">';
                echo '<style>.luna-specialist-availability{display:grid;gap:18px;padding:18px 20px;border:1px solid #e1e1e1;border-radius:16px;background:linear-gradient(180deg,#fff 0%,#fbfbfc 100%)}.luna-specialist-availability__head{display:grid;gap:6px}.luna-specialist-availability__head strong{font-size:16px;font-weight:800;color:#111827}.luna-specialist-availability__head p{margin:0;color:#6b7280;line-height:1.8}.luna-specialist-availability__card{display:grid;gap:12px;padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.04)}.luna-specialist-availability__card-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.luna-specialist-availability__card-title strong{font-size:14px;font-weight:800;color:#111827}.luna-specialist-availability__card-title span{color:#6b7280;font-size:12px}.luna-specialist-weekdays{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 14px}.luna-specialist-weekdays label{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;background:#fbfdff}.luna-specialist-availability__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.luna-specialist-helper-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.luna-specialist-helper-row{display:grid;gap:8px}.luna-specialist-helper-row label{display:grid;gap:6px;font-weight:600;color:#111827}.luna-specialist-helper-row input,.luna-specialist-helper-row select,.luna-specialist-helper-row textarea{width:100%}.luna-specialist-helper-actions{display:flex;justify-content:flex-end}.luna-specialist-helper-actions .button{min-height:40px;border-radius:12px}.luna-specialist-helper-note{margin:0;color:#6b7280;line-height:1.8}.luna-specialist-raw{display:grid;gap:12px}.luna-specialist-raw textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;direction:ltr;text-align:left}.luna-specialist-availability small{color:#6b7280;line-height:1.8}@media (max-width:782px){.luna-specialist-weekdays,.luna-specialist-availability__grid,.luna-specialist-helper-grid{grid-template-columns:minmax(0,1fr)}} </style>';
                echo '<div class="luna-specialist-availability__head">';
                echo '<strong>' . esc_html__('دسترسی‌پذیری رزرو', 'luna-appointments') . '</strong>';
                echo '<p>' . esc_html__('روزهای کاری، بازه‌های مرخصی و زمان‌های مسدود این متخصص را از همین بخش تنظیم کنید. ورودی‌های سریع بر اساس تاریخ شمسی هستند و هنگام ذخیره به فرمت سیستم تبدیل می‌شوند.', 'luna-appointments') . '</p>';
                echo '</div>';

                echo '<div class="luna-specialist-availability__card">';
                echo '<div class="luna-specialist-availability__card-title"><strong>' . esc_html__('روزها و ساعات کاری', 'luna-appointments') . '</strong><span>' . esc_html__('پایه‌ی تقویم رزرو این متخصص', 'luna-appointments') . '</span></div>';
                echo '<div class="luna-specialist-weekdays">';
		foreach ($weekdays as $idx => $label) {
                        echo '<label>';
			echo '<input type="checkbox" name="luna_specialist_working_days[]" value="' . esc_attr((string) $idx) . '"' . checked(in_array((int) $idx, $working_days, true), true, false) . '>';
			echo '<span>' . esc_html($label) . '</span>';
			echo '</label>';
		}
		echo '</div>';
		echo '<small>' . esc_html__('فقط روزهای انتخاب‌شده برای این متخصص قابل رزرو هستند. تعطیلی‌های سراسری لونا نیز می‌توانند روز انتخاب‌شده را در کل سایت غیرفعال کنند.', 'luna-appointments') . '</small>';
                echo '<div class="luna-specialist-availability__grid">';
		self::render_text_field('شروع ساعت کاری', 'luna_specialist_working_start', $working_start, 'time');
		self::render_text_field('پایان ساعت کاری', 'luna_specialist_working_end', $working_end, 'time');
		echo '</div>';
                echo '</div>';

                echo '<div class="luna-specialist-availability__card">';
                echo '<div class="luna-specialist-availability__card-title"><strong>' . esc_html__('تعطیلی و مرخصی', 'luna-appointments') . '</strong><span>' . esc_html__('ورود سریع با تاریخ شمسی', 'luna-appointments') . '</span></div>';
                echo '<div class="luna-specialist-helper-grid">';
                echo '<div class="luna-specialist-helper-row">';
                echo '<label><span>' . esc_html__('تعطیلی تک‌روزه', 'luna-appointments') . '</span><input type="text" class="widefat luna-specialist-jalali-date" data-target="luna_specialist_off_dates" placeholder="' . esc_attr__('مثلاً ۱۴۰۵/۰۴/۱۵', 'luna-appointments') . '"></label>';
                echo '<div class="luna-specialist-helper-actions"><button type="button" class="button button-secondary" data-luna-add-off-date="luna_specialist_off_dates">' . esc_html__('افزودن تعطیلی', 'luna-appointments') . '</button></div>';
                echo '</div>';
                echo '<div class="luna-specialist-helper-row">';
                echo '<label><span>' . esc_html__('شروع مرخصی', 'luna-appointments') . '</span><input type="text" class="widefat luna-specialist-jalali-date" data-range-start="luna_specialist_leave_ranges" placeholder="' . esc_attr__('۱۴۰۵/۰۴/۱۵', 'luna-appointments') . '"></label>';
                echo '<label><span>' . esc_html__('پایان مرخصی', 'luna-appointments') . '</span><input type="text" class="widefat luna-specialist-jalali-date" data-range-end="luna_specialist_leave_ranges" placeholder="' . esc_attr__('۱۴۰۵/۰۴/۲۰', 'luna-appointments') . '"></label>';
                echo '<div class="luna-specialist-helper-actions"><button type="button" class="button button-secondary" data-luna-add-range="luna_specialist_leave_ranges">' . esc_html__('افزودن بازه مرخصی', 'luna-appointments') . '</button></div>';
                echo '</div>';
                echo '<div class="luna-specialist-helper-row">';
                echo '<label><span>' . esc_html__('تاریخ مسدودی', 'luna-appointments') . '</span><input type="text" class="widefat luna-specialist-jalali-date" data-slot-date="luna_specialist_blocked_slots" placeholder="' . esc_attr__('۱۴۰۵/۰۴/۱۵', 'luna-appointments') . '"></label>';
                echo '<div class="luna-specialist-availability__grid">';
                echo '<label><span>' . esc_html__('از ساعت', 'luna-appointments') . '</span><input type="time" class="widefat" data-slot-start="luna_specialist_blocked_slots" value="10:00"></label>';
                echo '<label><span>' . esc_html__('تا ساعت', 'luna-appointments') . '</span><input type="time" class="widefat" data-slot-end="luna_specialist_blocked_slots" value="12:00"></label>';
                echo '</div>';
                echo '<div class="luna-specialist-helper-actions"><button type="button" class="button button-secondary" data-luna-add-slot="luna_specialist_blocked_slots">' . esc_html__('افزودن بازه مسدود', 'luna-appointments') . '</button></div>';
                echo '</div>';
                echo '</div>';
                echo '<p class="luna-specialist-helper-note">' . esc_html__('اگر ترجیح می‌دهید داده‌ها را مستقیم ویرایش کنید، می‌توانید از textareaهای زیر هم استفاده کنید. سیستم تاریخ‌های شمسی و میلادی را هر دو می‌پذیرد.', 'luna-appointments') . '</p>';
                echo '<div class="luna-specialist-raw">';
                self::render_textarea_field('تاریخ‌های تعطیل', 'luna_specialist_off_dates', $off_dates, 4, 'هر تاریخ در یک خط؛ فرمت قابل قبول: 1405/04/15 یا 2026-07-06');
                self::render_textarea_field('مرخصی / بازه‌های تعطیلی', 'luna_specialist_leave_ranges', $leave_ranges, 4, 'هر بازه در یک خط با فرمت: 1405/04/15|1405/04/20 یا 2026-07-06|2026-07-11');
                self::render_textarea_field('بازه‌های مسدود ساعتی', 'luna_specialist_blocked_slots', $blocked_slots, 5, 'هر مورد در یک خط با فرمت: 1405/04/15|10:00|12:00');
                echo '</div>';
		echo '</div>';

                echo "<script>document.addEventListener('DOMContentLoaded',function(){if(window.__lunaSpecialistAvailabilityReady){return;}window.__lunaSpecialistAvailabilityReady=true;var convertJalali=function(value){value=(value||'').toString().trim().replace(/-/g,'/');if(!value){return '';}var parts=value.split('/');if(parts.length!==3){return '';}var jy=parseInt(parts[0],10),jm=parseInt(parts[1],10),jd=parseInt(parts[2],10);if(!jy||!jm||!jd){return '';}jy-=979;var jDayNo=365*jy+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4);for(var i=0;i<jm-1;i++){jDayNo+=[31,31,31,31,31,31,30,30,30,30,30,29][i];}jDayNo+=jd-1;var gDayNo=jDayNo+79;var gy=1600+400*Math.floor(gDayNo/146097);gDayNo%=146097;var leap=true;if(gDayNo>=36525){gDayNo--;gy+=100*Math.floor(gDayNo/36524);gDayNo%=36524;if(gDayNo>=365){gDayNo++;}else{leap=false;}}gy+=4*Math.floor(gDayNo/1461);gDayNo%=1461;if(gDayNo>=366){leap=false;gDayNo--;gy+=Math.floor(gDayNo/365);gDayNo%=365;}var sal_a=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31],gm=0;for(;gm<12&&gDayNo>=sal_a[gm];gm++){gDayNo-=sal_a[gm];}var gd=gDayNo+1;gm+=1;var pad=function(num){return num<10?'0'+num:String(num);};return gy+'-'+pad(gm)+'-'+pad(gd);};var appendLine=function(targetName,line){var field=document.querySelector('[name=\"'+targetName+'\"]');if(!field||!line){return;}var current=(field.value||'').split(/\\r?\\n/).map(function(row){return row.trim();}).filter(Boolean);if(current.indexOf(line)===-1){current.push(line);}field.value=current.join('\\n');field.dispatchEvent(new Event('change',{bubbles:true}));};var initPicker=function(input){if(!input||typeof window.jQuery==='undefined'){return;}var $=window.jQuery;if(typeof $.fn.persianDatepicker!=='function'){return;}var args={formatDate:'YYYY/0M/0D'};$(input).persianDatepicker(args);};document.querySelectorAll('.luna-specialist-jalali-date').forEach(initPicker);document.querySelectorAll('[data-luna-add-off-date]').forEach(function(button){button.addEventListener('click',function(){var target=button.getAttribute('data-luna-add-off-date')||'';var input=document.querySelector('[data-target=\"'+target+'\"]');var date=convertJalali(input&&input.value?input.value:'');if(date){appendLine(target,date);if(input){input.value='';}}});});document.querySelectorAll('[data-luna-add-range]').forEach(function(button){button.addEventListener('click',function(){var target=button.getAttribute('data-luna-add-range')||'';var startInput=document.querySelector('[data-range-start=\"'+target+'\"]');var endInput=document.querySelector('[data-range-end=\"'+target+'\"]');var start=convertJalali(startInput&&startInput.value?startInput.value:'');var end=convertJalali(endInput&&endInput.value?endInput.value:'');if(start&&end){appendLine(target,start+'|'+end);if(startInput){startInput.value='';}if(endInput){endInput.value='';}}});});document.querySelectorAll('[data-luna-add-slot]').forEach(function(button){button.addEventListener('click',function(){var target=button.getAttribute('data-luna-add-slot')||'';var dateInput=document.querySelector('[data-slot-date=\"'+target+'\"]');var startInput=document.querySelector('[data-slot-start=\"'+target+'\"]');var endInput=document.querySelector('[data-slot-end=\"'+target+'\"]');var date=convertJalali(dateInput&&dateInput.value?dateInput.value:'');var start=startInput&&startInput.value?startInput.value:'';var end=endInput&&endInput.value?endInput.value:'';if(date&&start&&end){appendLine(target,date+'|'+start+'|'+end);if(dateInput){dateInput.value='';}}});});});</script>";
	}

        public static function parse_date_ranges($value) {
                $lines = preg_split('/\r\n|\r|\n/', (string) $value);
                $rows  = array();

                foreach (is_array($lines) ? $lines : array() as $line) {
                        $line = trim((string) $line);
                        if ('' === $line) {
                                continue;
                        }

                        $parts = array_map('trim', explode('|', $line));
                        $start = self::normalize_schedule_date_input(isset($parts[0]) ? (string) $parts[0] : '');
                        $end   = self::normalize_schedule_date_input(isset($parts[1]) ? (string) $parts[1] : '');

                        if ('' === $start || '' === $end) {
                                continue;
                        }

                        if ($end < $start) {
                                $tmp   = $start;
                                $start = $end;
                                $end   = $tmp;
                        }

                        $rows[] = array(
                                'start' => $start,
                                'end'   => $end,
                        );
                }

                return $rows;
        }

        public static function parse_blocked_slots($value) {
                $lines = preg_split('/\r\n|\r|\n/', (string) $value);
                $rows  = array();

                foreach (is_array($lines) ? $lines : array() as $line) {
                        $line = trim((string) $line);
                        if ('' === $line) {
                                continue;
                        }

                        $parts = array_map('trim', explode('|', $line));
                        $date  = self::normalize_schedule_date_input(isset($parts[0]) ? (string) $parts[0] : '');
                        $start = isset($parts[1]) ? (string) $parts[1] : '';
                        $end   = isset($parts[2]) ? (string) $parts[2] : '';

                        if ('' === $date || ! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
                                continue;
                        }

                        if ($end <= $start) {
                                continue;
                        }

                        $rows[] = array(
                                'date'  => $date,
                                'start' => $start,
                                'end'   => $end,
                        );
                }

                return $rows;
        }

        public static function normalize_schedule_date_input($value) {
                $value = trim((string) $value);
                if ('' === $value) {
                        return '';
                }

                $value = preg_replace('/\s+/', '', $value);
                $value = str_replace('/', '-', $value);

				if (preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/', $value)) {
						return class_exists('Luna_Appointments_Date') && Luna_Appointments_Date::parse_date($value) ? $value : '';
				}

				if (class_exists('Luna_Appointments_Date')) {
						$gregorian = Luna_Appointments_Date::jalali_to_gregorian_date($value);
						if ('' !== $gregorian) {
								return $gregorian;
						}
				}

                if (class_exists('Luna_Finance_Tables') && method_exists('Luna_Finance_Tables', 'parse_finance_datetime_to_timestamp')) {
                        $timestamp = (int) Luna_Finance_Tables::parse_finance_datetime_to_timestamp($value);
                        if ($timestamp > 0) {
								$date = new DateTimeImmutable('@' . $timestamp);
								return $date->setTimezone(wp_timezone())->format('Y-m-d');
                        }
                }

                return '';
		}

		protected static function sanitize_date_ranges_meta($value) {
				$rows = self::parse_date_ranges($value);

				return implode(
						"\n",
						array_map(
								static function ($row) {
										return $row['start'] . '|' . $row['end'];
								},
								$rows
						)
				);
		}

		protected static function sanitize_blocked_slots_meta($value) {
				$rows = self::parse_blocked_slots($value);

				return implode(
						"\n",
						array_map(
								static function ($row) {
										return $row['date'] . '|' . $row['start'] . '|' . $row['end'];
								},
								$rows
						)
				);
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
}
