<?php
/**
 * Booking database table management.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

class Luna_Appointments_Bookings_Table {
	/**
	 * Schema version option key.
	 *
	 * @var string
	 */
	protected static $schema_option = 'luna_builder_bookings_schema_version';

	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	protected static $schema_version = '1.6.0';

	/**
	 * Boot runtime hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action('init', array(__CLASS__, 'maybe_install'), 5);
	}

	/**
	 * Return the bookings table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'luna_bookings';
	}

	public static function get_events_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'luna_booking_events';
	}

	/**
	 * Ensure the latest schema is installed.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		$installed_version = (string) get_option(self::$schema_option, '');

		if (self::$schema_version !== $installed_version) {
			self::install();
		}
	}

	/**
	 * Install or upgrade the bookings table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(self::get_schema_sql());
		$table  = self::get_table_name();
		foreach (array($table, self::get_events_table_name()) as $transactional_table) {
			$status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($transactional_table)), ARRAY_A);
			if (is_array($status) && isset($status['Engine']) && 'InnoDB' !== (string) $status['Engine']) {
				$wpdb->query("ALTER TABLE {$transactional_table} ENGINE=InnoDB");
			}
		}
		$column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'idempotency_key'");
		$language_column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'language'");
		$status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
		$events_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::get_events_table_name()));
		if ('idempotency_key' === (string) $column && 'language' === (string) $language_column && is_array($status) && 'InnoDB' === (string) ($status['Engine'] ?? '') && self::get_events_table_name() === (string) $events_exists) {
			update_option(self::$schema_option, self::$schema_version);
		}
	}

	/**
	 * Check whether a booking code already exists.
	 *
	 * @param string $booking_code Booking code.
	 * @return bool
	 */
	public static function booking_code_exists($booking_code) {
		global $wpdb;

		$table_name = self::get_table_name();
		$found      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE booking_code = %s LIMIT 1",
				$booking_code
			)
		);

		return ! empty($found);
	}

	public static function get_booking_by_idempotency_key($key) {
		global $wpdb;
		$key = trim((string) $key);
		if ('' === $key) {
			return null;
		}
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::get_table_name() . ' WHERE idempotency_key = %s LIMIT 1', $key), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public static function begin_transaction() {
		global $wpdb;
		return false !== $wpdb->query('START TRANSACTION');
	}

	public static function commit_transaction() {
		global $wpdb;
		return false !== $wpdb->query('COMMIT');
	}

	public static function rollback_transaction() {
		global $wpdb;
		return false !== $wpdb->query('ROLLBACK');
	}

	/**
	 * Check whether a specialist time slot is already reserved.
	 *
	 * @param int    $specialist_id Specialist post id.
	 * @param string $booking_date Gregorian booking date.
	 * @param string $booking_time Booking time.
	 * @return bool
	 */
	public static function slot_exists($specialist_id, $booking_date, $booking_time, $duration_minutes = 0, $buffer_minutes = 0, $exclude_booking_id = 0) {
		$appointments = self::get_blocking_bookings_for_date($specialist_id, $booking_date, $exclude_booking_id);

		return self::time_slot_overlaps_appointments($booking_time, $duration_minutes, $buffer_minutes, $appointments);
	}

	/**
	 * Acquire a short-lived lock for booking operations on a specialist/date pair.
	 *
	 * @param int    $specialist_id Specialist post id.
	 * @param string $booking_date Gregorian booking date.
	 * @param int    $timeout Seconds to wait for lock.
	 * @return bool
	 */
	public static function acquire_slot_lock($specialist_id, $booking_date, $timeout = 5) {
		global $wpdb;

		$key    = self::build_slot_lock_key($specialist_id, $booking_date);
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$key,
				max(0, (int) $timeout)
			)
		);

		return '1' === (string) $result || 1 === (int) $result;
	}

	/**
	 * Release a previously acquired slot lock.
	 *
	 * @param int    $specialist_id Specialist post id.
	 * @param string $booking_date Gregorian booking date.
	 * @return void
	 */
	public static function release_slot_lock($specialist_id, $booking_date) {
		global $wpdb;

		$key = self::build_slot_lock_key($specialist_id, $booking_date);
		$wpdb->get_var(
			$wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				$key
			)
		);
	}

	/**
	 * Return all reserved times for a specialist on a given date.
	 *
	 * @param int    $specialist_id Specialist post id.
	 * @param string $booking_date Gregorian booking date.
	 * @return array<int, string>
	 */
	public static function get_reserved_times($specialist_id, $booking_date, $candidate_times = array(), $duration_minutes = 0, $buffer_minutes = 0, $exclude_booking_id = 0) {
		$appointments = self::get_blocking_bookings_for_date($specialist_id, $booking_date, (int) $exclude_booking_id);
		$times        = array();

		if (empty($candidate_times)) {
			foreach ($appointments as $appointment) {
				$time = isset($appointment['booking_time']) ? trim((string) $appointment['booking_time']) : '';

				if ('' !== $time) {
					$times[] = $time;
				}
			}

			return array_values(array_unique($times));
		}

		foreach ((array) $candidate_times as $candidate_time) {
			$time = is_string($candidate_time) ? trim($candidate_time) : '';

			if ('' === $time) {
				continue;
			}

			if (self::time_slot_overlaps_appointments($time, $duration_minutes, $buffer_minutes, $appointments)) {
				$times[] = $time;
			}
		}

		return array_values(array_unique($times));
	}

	/**
	 * Insert a booking row.
	 *
	 * @param array<string, mixed> $booking_data Booking payload.
	 * @return int|WP_Error
	 */
	public static function insert_booking($booking_data, $fire_hooks = true) {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql');
		$defaults   = array(
			'booking_code'     => '',
			'service_id'       => 0,
			'specialist_id'    => 0,
			'customer_user_id' => 0,
			'is_vip'           => 0,
			'customer_name'    => '',
			'customer_phone'   => '',
			'customer_email'   => '',
			'language'         => 'fa',
			'booking_date'     => '',
			'booking_time'     => '',
			'duration_minutes' => 0,
			'buffer_minutes'   => 0,
			'base_price'       => 0,
			'price_label'      => '',
			'status'           => 'pending',
			'payment_status'   => 'unpaid',
			'payment_method'   => '',
			'wc_order_id'      => 0,
			'wc_order_key'     => '',
			'notes'            => '',
			'admin_note'       => '',
			'source'           => 'booking_form',
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		$data       = wp_parse_args($booking_data, $defaults);
		$data['language'] = in_array((string) $data['language'], array('fa', 'en', 'ar'), true) ? (string) $data['language'] : 'fa';
		if (class_exists('Luna_Appointments_Date')) {
			$data['booking_date'] = Luna_Appointments_Date::latin_digits((string) $data['booking_date']);
			if (! Luna_Appointments_Date::parse_date($data['booking_date'])) {
				return new WP_Error('invalid_gregorian_booking_date', __('تاریخ رزرو باید یک تاریخ میلادی معتبر برای ذخیره‌سازی باشد.', 'luna-appointments'));
			}
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
		}
		$fields     = self::get_field_formats();
		$insert_row = array();
		$formats    = array();

		foreach ($fields as $field => $format) {
			if (! array_key_exists($field, $data)) {
				continue;
			}

			$insert_row[ $field ] = $data[ $field ];
			$formats[]            = $format;
		}

		$inserted = $wpdb->insert($table_name, $insert_row, $formats);

		if (false === $inserted) {
			return new WP_Error('booking_insert_failed', __('رکورد رزرو ذخیره نشد. لطفاً دوباره تلاش کنید.', 'luna-appointments'));
		}

		// Preserve the id before firing integrations. Hook callbacks may execute
		// other INSERT queries and overwrite the mutable $wpdb->insert_id value.
		$booking_id = (int) $wpdb->insert_id;
		if ($fire_hooks) {
			self::log_event($booking_id, 'created', array(), $insert_row, 'booking_created');
			do_action('luna_appointments_booking_created', $booking_id, $insert_row);
		}

		return $booking_id;
	}

	/**
	 * Update an existing booking row.
	 *
	 * @param int                 $booking_id Booking id.
	 * @param array<string,mixed> $booking_data Updated payload.
	 * @return bool
	 */
	public static function update_booking($booking_id, $booking_data) {
		global $wpdb;

		$booking_id = (int) $booking_id;

		if ($booking_id <= 0 || empty($booking_data) || ! is_array($booking_data)) {
			return false;
		}

		$table_name = self::get_table_name();
		$previous   = self::get_booking($booking_id);
		$allowed    = self::get_field_formats();
		$data       = array();
		$formats    = array();

		unset($allowed['created_at']);

		foreach ($allowed as $field => $format) {
			if (! array_key_exists($field, $booking_data)) {
				continue;
			}

			$data[ $field ] = $booking_data[ $field ];
			$formats[]      = $format;
		}

		if (empty($data)) {
			return false;
		}
		if (isset($data['booking_date']) && class_exists('Luna_Appointments_Date')) {
			$data['booking_date'] = Luna_Appointments_Date::latin_digits((string) $data['booking_date']);
			if (! Luna_Appointments_Date::parse_date($data['booking_date'])) {
				return false;
			}
		}

		if (! isset($data['updated_at'])) {
			$data['updated_at'] = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql');
			$formats[]          = '%s';
		}

		$result = $wpdb->update(
			$table_name,
			$data,
			array('id' => $booking_id),
			$formats,
			array('%d')
		);

		if (false !== $result) {
			$current = self::get_booking($booking_id);
			$changes = array();
			foreach (array_keys($data) as $field) {
				if ('updated_at' !== $field && (string) ($previous[ $field ] ?? '') !== (string) ($current[ $field ] ?? '')) {
					$changes[ $field ] = array('from' => $previous[ $field ] ?? null, 'to' => $current[ $field ] ?? null);
				}
			}
			if ($changes) {
				self::log_event($booking_id, 'updated', $previous, $current, current_filter(), $changes);
			}
			do_action('luna_appointments_booking_updated', $booking_id, $current, $previous, $data);
			return true;
		}

		return false;
	}

	public static function log_event($booking_id, $event_type, $before = array(), $after = array(), $source = '', $changes = array()) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ($booking_id <= 0) return false;
		return false !== $wpdb->insert(self::get_events_table_name(), array(
			'booking_id' => $booking_id,
			'event_type' => sanitize_key((string) $event_type),
			'source' => sanitize_key((string) $source),
			'actor_user_id' => get_current_user_id(),
			'changes_json' => wp_json_encode($changes, JSON_UNESCAPED_UNICODE),
			'before_json' => wp_json_encode($before, JSON_UNESCAPED_UNICODE),
			'after_json' => wp_json_encode($after, JSON_UNESCAPED_UNICODE),
			'created_at' => class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql'),
		), array('%d','%s','%s','%d','%s','%s','%s','%s'));
	}

	public static function get_booking_history($booking_id, $limit = 100) {
		global $wpdb;
		$table = self::get_events_table_name();
		return (array) $wpdb->get_results($wpdb->prepare(
			"SELECT e.*, u.display_name AS actor_name FROM {$table} e LEFT JOIN {$wpdb->users} u ON u.ID=e.actor_user_id WHERE e.booking_id=%d ORDER BY e.id DESC LIMIT %d",
			(int) $booking_id, max(1, min(250, (int) $limit))
		), ARRAY_A);
	}

	/**
	 * Fetch a booking by its primary key.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>|null
	 */
	public static function get_booking($booking_id) {
		global $wpdb;

		$table_name = self::get_table_name();
		$row        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
				(int) $booking_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * Fetch a single booking row enriched with related names and order data.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>|null
	 */
	public static function get_booking_with_context($booking_id) {
		$row = self::get_booking($booking_id);

		if (! is_array($row)) {
			return null;
		}

		return self::enrich_booking_row($row);
	}

	/**
	 * Fetch a booking row by WooCommerce order id.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array<string,mixed>|null
	 */
	public static function get_booking_by_order_id($order_id) {
		global $wpdb;

		$table_name = self::get_table_name();
		$row        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE wc_order_id = %d LIMIT 1",
				(int) $order_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * Query booking rows for the admin page.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public static function query_bookings($args = array()) {
		global $wpdb;

		$defaults   = array(
			'status'         => '',
			'payment_status' => '',
			'has_order'      => '',
			'service_id'     => 0,
			'specialist_id'  => 0,
			'payment_method' => '',
			'from_date'      => '',
			'to_date'        => '',
			'payment_error'  => '',
			'search'         => '',
			'paged'          => 1,
			'per_page'       => 20,
		);
		$args       = wp_parse_args($args, $defaults);
		$table_name = self::get_table_name();
		$where      = array('1=1');
		$params     = array();
		$paged      = max(1, (int) $args['paged']);
		$per_page   = max(1, (int) $args['per_page']);
		$offset     = ($paged - 1) * $per_page;

		if ('' !== (string) $args['status']) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key((string) $args['status']);
		}

		if ('' !== (string) $args['payment_status']) {
			$where[]  = 'payment_status = %s';
			$params[] = sanitize_key((string) $args['payment_status']);
		}

		if ('linked' === (string) $args['has_order']) {
			$where[] = 'wc_order_id > 0';
		} elseif ('unlinked' === (string) $args['has_order']) {
			$where[] = 'wc_order_id = 0';
		}

		if ((int) $args['service_id'] > 0) {
			$where[] = 'service_id = %d';
			$params[] = (int) $args['service_id'];
		}
		if ((int) $args['specialist_id'] > 0) {
			$where[] = 'specialist_id = %d';
			$params[] = (int) $args['specialist_id'];
		}
		if ('' !== (string) $args['payment_method']) {
			$where[] = 'payment_method = %s';
			$params[] = sanitize_key((string) $args['payment_method']);
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from_date'])) {
			$where[] = 'booking_date >= %s';
			$params[] = (string) $args['from_date'];
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to_date'])) {
			$where[] = 'booking_date <= %s';
			$params[] = (string) $args['to_date'];
		}
		if ('yes' === (string) $args['payment_error']) {
			$where[] = "payment_status IN ('failed','payment_review')";
		}

		if ('' !== (string) $args['search']) {
			$like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
			$where[]  = '(booking_code LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR customer_email LIKE %s OR CAST(wc_order_id AS CHAR) LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode(' AND ', $where);
		$total_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$total     = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare($total_sql, ...$params)
				: $total_sql
		);
		$row_params = array_merge($params, array($per_page, $offset));
		$rows       = $wpdb->get_results($wpdb->prepare($rows_sql, ...$row_params), ARRAY_A);
		$items      = array();

		foreach (is_array($rows) ? $rows : array() as $row) {
			$items[] = self::enrich_booking_row($row);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	public static function query_bookings_by_date_range($from_date, $to_date, $args = array()) {
		global $wpdb;

		$from_date = trim((string) $from_date);
		$to_date   = trim((string) $to_date);
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$defaults   = array(
			'status'   => '',
			'limit'    => 500,
		);
		$args       = wp_parse_args($args, $defaults);
		$table_name = self::get_table_name();
		$where      = array('booking_date >= %s', 'booking_date <= %s');
		$params     = array($from_date, $to_date);
		$limit      = max(1, (int) $args['limit']);

		if ('' !== (string) $args['status']) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key((string) $args['status']);
		}

		$where_sql = implode(' AND ', $where);
		$total_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY booking_date ASC, booking_time ASC, id ASC LIMIT %d";
		$total     = (int) $wpdb->get_var($wpdb->prepare($total_sql, ...$params));
		$row_params = array_merge($params, array($limit));
		$rows       = $wpdb->get_results($wpdb->prepare($rows_sql, ...$row_params), ARRAY_A);

		$items = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$items[] = self::enrich_booking_row($row);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

        /**
         * Query bookings for export/report screens with optional filters.
         *
         * @param array<string,mixed> $args Query arguments.
         * @return array<int,array<string,mixed>>
         */
        public static function query_bookings_for_export($args = array()) {
                global $wpdb;

                $args       = wp_parse_args(
                        is_array($args) ? $args : array(),
                        array(
                                'status'         => '',
                                'payment_status' => '',
                                'search'         => '',
                                'from_date'      => '',
                                'to_date'        => '',
                                'limit'          => 2000,
                        )
                );
                $table_name = self::get_table_name();
                $where      = array('1=1');
                $params     = array();
                $limit      = max(1, min(5000, (int) $args['limit']));

                if ('' !== (string) $args['status']) {
                        $where[]  = 'status = %s';
                        $params[] = sanitize_key((string) $args['status']);
                }

                if ('' !== (string) $args['payment_status']) {
                        $where[]  = 'payment_status = %s';
                        $params[] = sanitize_key((string) $args['payment_status']);
                }

                if ('' !== (string) $args['from_date'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from_date'])) {
                        $where[]  = 'booking_date >= %s';
                        $params[] = (string) $args['from_date'];
                }

                if ('' !== (string) $args['to_date'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to_date'])) {
                        $where[]  = 'booking_date <= %s';
                        $params[] = (string) $args['to_date'];
                }

                if ('' !== (string) $args['search']) {
                        $like        = '%' . $wpdb->esc_like((string) $args['search']) . '%';
                        $posts_table = $wpdb->posts;
                        $where[]     = "(booking_code LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR customer_email LIKE %s OR EXISTS (SELECT 1 FROM {$posts_table} p WHERE p.ID = service_id AND p.post_title LIKE %s) OR EXISTS (SELECT 1 FROM {$posts_table} p2 WHERE p2.ID = specialist_id AND p2.post_title LIKE %s))";
                        $params[]    = $like;
                        $params[]    = $like;
                        $params[]    = $like;
                        $params[]    = $like;
                        $params[]    = $like;
                        $params[]    = $like;
                }

                $where_sql = implode(' AND ', $where);
                $rows_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY booking_date DESC, booking_time DESC, id DESC LIMIT %d";
                $rows      = $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, array($limit))), ARRAY_A);
                $items     = array();

                foreach (is_array($rows) ? $rows : array() as $row) {
                        $items[] = self::enrich_booking_row($row);
                }

                return $items;
        }

	public static function query_bookings_for_user($user_id, $args = array()) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$defaults   = array(
			'status'         => '',
			'payment_status' => '',
			'search'         => '',
			'order_by'       => 'booking_date',
			'order'          => 'DESC',
			'paged'          => 1,
			'per_page'       => 20,
		);
		$args       = wp_parse_args($args, $defaults);
		$table_name = self::get_table_name();
		$paged      = max(1, (int) $args['paged']);
		$per_page   = max(1, (int) $args['per_page']);
		$offset     = ($paged - 1) * $per_page;

		$where  = array('customer_user_id = %d');
		$params = array($user_id);

		if ('' !== (string) $args['status']) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key((string) $args['status']);
		}

		if ('' !== (string) $args['payment_status']) {
			$where[]  = 'payment_status = %s';
			$params[] = sanitize_key((string) $args['payment_status']);
		}

		if ('' !== (string) $args['search']) {
			$like        = '%' . $wpdb->esc_like((string) $args['search']) . '%';
			$posts_table = $wpdb->posts;
			$where[]     = "(booking_code LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR customer_email LIKE %s OR EXISTS (SELECT 1 FROM {$posts_table} p WHERE p.ID = service_id AND p.post_title LIKE %s) OR EXISTS (SELECT 1 FROM {$posts_table} p2 WHERE p2.ID = specialist_id AND p2.post_title LIKE %s))";
			$params[]    = $like;
			$params[]    = $like;
			$params[]    = $like;
			$params[]    = $like;
			$params[]    = $like;
			$params[]    = $like;
		}

		$order_by = in_array((string) $args['order_by'], array('booking_date', 'created_at', 'id'), true) ? (string) $args['order_by'] : 'booking_date';
		$order    = 'ASC' === strtoupper((string) $args['order']) ? 'ASC' : 'DESC';

		if ('created_at' === $order_by) {
			$orderby_sql = "created_at {$order}, id {$order}";
		} elseif ('id' === $order_by) {
			$orderby_sql = "id {$order}";
		} else {
			$orderby_sql = "booking_date {$order}, booking_time {$order}, id {$order}";
		}

		$where_sql = implode(' AND ', $where);
		$total_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby_sql} LIMIT %d OFFSET %d";
		$total     = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare($total_sql, ...$params)
				: $total_sql
		);
		$row_params = array_merge($params, array($per_page, $offset));
		$rows       = $wpdb->get_results($wpdb->prepare($rows_sql, ...$row_params), ARRAY_A);

		$items = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$items[] = self::enrich_booking_row($row);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Query bookings assigned to one specialist for the specialist application.
	 *
	 * @param int                  $specialist_id Specialist post id.
	 * @param array<string,mixed>  $args Query arguments.
	 * @return array<string,mixed>
	 */
	public static function query_bookings_for_specialist($specialist_id, $args = array()) {
		global $wpdb;

		$specialist_id = (int) $specialist_id;
		if ($specialist_id <= 0) return array('items' => array(), 'total' => 0);

		$args = wp_parse_args($args, array('status' => '', 'from_date' => '', 'to_date' => '', 'paged' => 1, 'per_page' => 100));
		$where = array('specialist_id = %d');
		$params = array($specialist_id);
		if ('' !== (string) $args['status']) {
			$where[] = 'status = %s';
			$params[] = sanitize_key((string) $args['status']);
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from_date'])) {
			$where[] = 'booking_date >= %s';
			$params[] = (string) $args['from_date'];
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to_date'])) {
			$where[] = 'booking_date <= %s';
			$params[] = (string) $args['to_date'];
		}
		$paged = max(1, (int) $args['paged']);
		$per_page = min(250, max(1, (int) $args['per_page']));
		$offset = ($paged - 1) * $per_page;
		$table_name = self::get_table_name();
		$where_sql = implode(' AND ', $where);
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}", ...$params));
		$row_params = array_merge($params, array($per_page, $offset));
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY booking_date DESC, booking_time DESC, id DESC LIMIT %d OFFSET %d", ...$row_params), ARRAY_A);
		$items = array();
		foreach ((array) $rows as $row) $items[] = self::enrich_booking_row($row);
		return array('items' => $items, 'total' => $total);
	}

	/**
	 * Return booking counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public static function get_status_counts() {
		return self::get_grouped_counts('status');
	}

	/**
	 * Return booking counts grouped by payment status.
	 *
	 * @return array<string, int>
	 */
	public static function get_payment_status_counts() {
		return self::get_grouped_counts('payment_status');
	}

	public static function get_report_summary($args = array()) {
		global $wpdb;
		$args = wp_parse_args(is_array($args) ? $args : array(), array('from_date' => '', 'to_date' => '', 'service_id' => 0, 'specialist_id' => 0));
		$where = array('1=1'); $params = array();
		foreach (array('from_date' => '>=', 'to_date' => '<=') as $key => $operator) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args[$key])) { $where[] = "booking_date {$operator} %s"; $params[] = (string) $args[$key]; }
		}
		foreach (array('service_id','specialist_id') as $key) {
			if ((int) $args[$key] > 0) { $where[] = "{$key} = %d"; $params[] = (int) $args[$key]; }
		}
		$sql = 'SELECT COUNT(*) total, SUM(status="confirmed") confirmed, SUM(status IN ("completed","done")) completed, SUM(status="cancelled") cancelled, SUM(payment_status="failed") failed_payments, COALESCE(SUM(CASE WHEN payment_status="paid" THEN base_price ELSE 0 END),0) paid_value FROM ' . self::get_table_name() . ' WHERE ' . implode(' AND ', $where);
		$row = $wpdb->get_row($params ? $wpdb->prepare($sql, ...$params) : $sql, ARRAY_A);
		return is_array($row) ? $row : array();
	}

	public static function get_daily_counts($days = 14) {
		global $wpdb;

		$days       = max(1, (int) $days);
		$today      = current_datetime()->setTime(0, 0, 0);
		$start_date = $today->modify('-' . ($days - 1) . ' days');
		// Keep database keys Gregorian; wp_date() can be converted by Jalali plugins.
		$from       = $start_date->format('Y-m-d');
		$table    = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS activity_date,
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
					SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY DATE(created_at) ASC",
				$from
			),
			ARRAY_A
		);

		$map = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$date = isset($row['activity_date']) ? (string) $row['activity_date'] : '';
			if ('' === $date) {
				continue;
			}
			$map[ $date ] = array(
				'date'      => $date,
				'total'     => isset($row['total']) ? (int) $row['total'] : 0,
				'confirmed' => isset($row['confirmed']) ? (int) $row['confirmed'] : 0,
				'cancelled' => isset($row['cancelled']) ? (int) $row['cancelled'] : 0,
			);
		}

		/*
		 * Some hosts return an empty result for DATE() grouping when their SQL
		 * timezone/mode differs from WordPress. The booking list still reads the
		 * same rows correctly, so use a bounded PHP aggregation as a reliable
		 * fallback instead of presenting a false "no data" state.
		 */
		if (empty($map)) {
			$fallback_rows = $wpdb->get_results(
				"SELECT created_at, status FROM {$table} ORDER BY id DESC LIMIT 5000",
				ARRAY_A
			);

			foreach (is_array($fallback_rows) ? $fallback_rows : array() as $row) {
				$created_at = isset($row['created_at']) ? trim((string) $row['created_at']) : '';
				$date       = preg_match('/^\d{4}-\d{2}-\d{2}/', $created_at) ? substr($created_at, 0, 10) : '';

				if ('' === $date || $date < $from) {
					continue;
				}

				if (! isset($map[ $date ])) {
					$map[ $date ] = array('date' => $date, 'total' => 0, 'confirmed' => 0, 'cancelled' => 0);
				}

				$status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
				$map[ $date ]['total']++;
				if ('confirmed' === $status) {
					$map[ $date ]['confirmed']++;
				} elseif ('cancelled' === $status) {
					$map[ $date ]['cancelled']++;
				}
			}
		}

		$out = array();
		for ($i = 0; $i < $days; $i++) {
			$d = $start_date->modify('+' . $i . ' days')->format('Y-m-d');
			$out[] = isset($map[ $d ])
				? $map[ $d ]
				: array(
					'date'      => $d,
					'total'     => 0,
					'confirmed' => 0,
					'cancelled' => 0,
				);
		}

		return $out;
	}

	public static function get_top_services($days = 30, $limit = 8) {
		return self::get_top_entities('service_id', $days, $limit);
	}

	public static function get_top_specialists($days = 30, $limit = 8) {
		return self::get_top_entities('specialist_id', $days, $limit);
	}

	protected static function get_top_entities($field, $days, $limit) {
		global $wpdb;

		$field = 'specialist_id' === (string) $field ? 'specialist_id' : 'service_id';
		$days  = max(1, (int) $days);
		$limit = max(1, (int) $limit);
		$from  = current_datetime()->setTime(0, 0, 0)->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
		$table = self::get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$field} AS entity_id, COUNT(*) AS total
				FROM {$table}
				WHERE booking_date >= %s AND {$field} > 0
				GROUP BY {$field}
				ORDER BY total DESC
				LIMIT %d",
				$from,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$id = isset($row['entity_id']) ? (int) $row['entity_id'] : 0;
			if ($id <= 0) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'name'  => get_the_title($id),
				'total' => isset($row['total']) ? (int) $row['total'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Return booking counts grouped by a supported column.
	 *
	 * @param string $column Supported status column.
	 * @return array<string,int>
	 */
	protected static function get_grouped_counts($column) {
		global $wpdb;

		$column = in_array($column, array('status', 'payment_status'), true) ? $column : 'status';
		$table_name = self::get_table_name();
		$rows       = $wpdb->get_results("SELECT {$column} AS group_key, COUNT(*) AS total FROM {$table_name} GROUP BY {$column}", ARRAY_A);
		$counts     = array();

		foreach (is_array($rows) ? $rows : array() as $row) {
			$key             = isset($row['group_key']) ? (string) $row['group_key'] : '';
			$counts[ $key ]  = isset($row['total']) ? (int) $row['total'] : 0;
		}

		return $counts;
	}

	/**
	 * Enrich a booking row with readable names and WooCommerce order context.
	 *
	 * @param array<string,mixed> $row Raw booking row.
	 * @return array<string,mixed>
	 */
	protected static function enrich_booking_row($row) {
		$row['service_name']    = ! empty($row['service_id']) ? get_the_title((int) $row['service_id']) : '';
		$row['specialist_name'] = ! empty($row['specialist_id']) ? get_the_title((int) $row['specialist_id']) : '';

		if (! empty($row['wc_order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $row['wc_order_id']);

			if ($order instanceof WC_Order) {
				$row['wc_order_number']       = $order->get_order_number();
				$row['wc_order_status']       = $order->get_status();
				$row['wc_order_status_label'] = function_exists('wc_get_order_status_name')
					? wc_get_order_status_name($order->get_status())
					: ucfirst((string) $order->get_status());
				$row['wc_order_edit_url']     = get_edit_post_link((int) $order->get_id(), '');
				$row['wc_payment_title']      = $order->get_payment_method_title();
				$row['wc_order_total']        = wp_strip_all_tags($order->get_formatted_order_total());
				$row['wc_order_created']      = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '';
				$row['payment_error']         = self::get_order_payment_error($order);
			}
		}

		return $row;
	}

	protected static function get_order_payment_error($order) {
		if (! $order instanceof WC_Order) return '';
		foreach (array('_luna_payment_error', '_payment_error', '_payment_failure_message') as $key) {
			$value = trim(wp_strip_all_tags((string) $order->get_meta($key, true)));
			if ('' !== $value) return $value;
		}
		if (! in_array((string) $order->get_status(), array('failed', 'cancelled', 'on-hold'), true) || ! function_exists('wc_get_order_notes')) return '';
		$notes = wc_get_order_notes(array('order_id' => (int) $order->get_id(), 'limit' => 10, 'orderby' => 'date_created_gmt', 'order' => 'DESC', 'type' => 'internal'));
		foreach ((array) $notes as $note) {
			$content = trim(wp_strip_all_tags((string) ($note->content ?? '')));
			if (preg_match('/خطا|ناموفق|failed|gateway|درگاه|پرداخت/ui', $content)) return $content;
		}
		return '';
	}

	protected static function get_field_formats() {
		return array(
			'booking_code'     => '%s',
			'idempotency_key'  => '%s',
			'service_id'       => '%d',
			'specialist_id'    => '%d',
			'customer_user_id' => '%d',
			'is_vip'           => '%d',
			'customer_name'    => '%s',
			'customer_phone'   => '%s',
			'customer_email'   => '%s',
			'language'         => '%s',
			'booking_date'     => '%s',
			'booking_time'     => '%s',
			'duration_minutes' => '%d',
			'buffer_minutes'   => '%d',
			'base_price'       => '%d',
			'price_label'      => '%s',
			'status'           => '%s',
			'payment_status'   => '%s',
			'payment_method'   => '%s',
			'wc_order_id'      => '%d',
			'wc_order_key'     => '%s',
			'notes'            => '%s',
			'admin_note'       => '%s',
			'source'           => '%s',
			'created_at'       => '%s',
			'updated_at'       => '%s',
		);
	}

	protected static function get_blocking_bookings_for_date($specialist_id, $booking_date, $exclude_booking_id = 0) {
		global $wpdb;

		$table_name = self::get_table_name();
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, booking_time, duration_minutes, buffer_minutes, status
				FROM {$table_name}
				WHERE specialist_id = %d
					AND booking_date = %s
					AND status IN ('pending', 'pending_payment', 'payment_review', 'consultation_pending', 'confirmed')
					AND (%d = 0 OR id != %d)
				ORDER BY booking_time ASC",
				(int) $specialist_id,
				$booking_date,
				(int) $exclude_booking_id,
				(int) $exclude_booking_id
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	protected static function build_slot_lock_key($specialist_id, $booking_date) {
		$specialist_id = max(0, (int) $specialist_id);
		$booking_date  = preg_replace('/[^0-9\-]/', '', (string) $booking_date);

		return 'luna_booking_slot_' . $specialist_id . '_' . $booking_date;
	}

	protected static function time_slot_overlaps_appointments($booking_time, $duration_minutes, $buffer_minutes, $appointments) {
		$candidate = self::build_time_window($booking_time, $duration_minutes, $buffer_minutes);

		if (! $candidate) {
			return false;
		}

		foreach ($appointments as $appointment) {
			$existing = self::build_time_window(
				isset($appointment['booking_time']) ? (string) $appointment['booking_time'] : '',
				isset($appointment['duration_minutes']) ? (int) $appointment['duration_minutes'] : 0,
				isset($appointment['buffer_minutes']) ? (int) $appointment['buffer_minutes'] : 0
			);

			if (! $existing) {
				continue;
			}

			if ($candidate['start'] < $existing['end'] && $existing['start'] < $candidate['end']) {
				return true;
			}
		}

		return false;
	}

	protected static function build_time_window($booking_time, $duration_minutes, $buffer_minutes) {
		$start = self::time_to_minutes($booking_time);

		if (null === $start) {
			return null;
		}

		$duration = max(1, (int) $duration_minutes);
		$buffer   = max(0, (int) $buffer_minutes);

		return array(
			'start' => $start,
			'end'   => $start + $duration + $buffer,
		);
	}

	protected static function time_to_minutes($booking_time) {
		$booking_time = trim((string) $booking_time);

		if (! preg_match('/^(\d{2}):(\d{2})$/', $booking_time, $matches)) {
			return null;
		}

		return ((int) $matches[1] * 60) + (int) $matches[2];
	}

	/**
	 * Delete a booking row permanently.
	 *
	 * @param int $booking_id Booking id.
	 * @return bool
	 */
	public static function delete_booking($booking_id) {
		global $wpdb;

		$booking_id = (int) $booking_id;

		if ($booking_id <= 0) {
			return false;
		}

		$table_name = self::get_table_name();
		$result     = $wpdb->delete($table_name, array('id' => $booking_id), array('%d'));

		return false !== $result;
	}

	/**
	 * Return the dbDelta SQL for the bookings table.
	 *
	 * @return string
	 */
	protected static function get_schema_sql() {
		global $wpdb;

		$table_name        = self::get_table_name();
		$charset_collate   = $wpdb->get_charset_collate();

		$events_table = self::get_events_table_name();
		return "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_code VARCHAR(32) NOT NULL DEFAULT '',
			idempotency_key VARCHAR(64) NULL DEFAULT NULL,
			service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			specialist_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_vip TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			customer_phone VARCHAR(50) NOT NULL DEFAULT '',
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			language VARCHAR(5) NOT NULL DEFAULT 'fa',
			booking_date DATE NOT NULL,
			booking_time VARCHAR(10) NOT NULL DEFAULT '',
			duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
			buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0,
			base_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price_label VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
			payment_method VARCHAR(30) NOT NULL DEFAULT '',
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wc_order_key VARCHAR(64) NOT NULL DEFAULT '',
			notes LONGTEXT NULL,
			admin_note LONGTEXT NULL,
			source VARCHAR(30) NOT NULL DEFAULT 'booking_form',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY booking_code (booking_code),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY service_id (service_id),
			KEY specialist_id (specialist_id),
			KEY customer_user_id (customer_user_id),
			KEY language (language),
			KEY is_vip (is_vip),
			KEY booking_date (booking_date),
			KEY booking_time (booking_time),
			KEY status (status),
			KEY payment_status (payment_status),
			KEY wc_order_id (wc_order_id)
		) ENGINE=InnoDB {$charset_collate};

		CREATE TABLE {$events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(40) NOT NULL DEFAULT '',
			source VARCHAR(80) NOT NULL DEFAULT '',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			changes_json LONGTEXT NULL,
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY event_type (event_type),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset_collate};";
	}
}
