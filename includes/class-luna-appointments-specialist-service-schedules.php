<?php
/** Per-specialist, per-service appointment timing. @package LunaAppointments */
if (! defined('ABSPATH')) { exit; }

final class Luna_Appointments_Specialist_Service_Schedules {
	const SCHEMA_VERSION = '1.0.1';
	const SCHEMA_OPTION = 'luna_specialist_service_schedule_schema';

	public static function boot() {
		add_action('init', array(__CLASS__, 'maybe_install'), 6);
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'luna_specialist_service_schedule';
	}

	public static function maybe_install() {
		if ((string) get_option(self::SCHEMA_OPTION, '') !== self::SCHEMA_VERSION) { self::install(); }
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		dbDelta("CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			specialist_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 65535,
			buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 65535,
			slot_step_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 65535,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY specialist_service (specialist_id, service_id),
			KEY service_id (service_id)
		) {$charset};");
		update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
	}

	public static function get($specialist_id, $service_id) {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE specialist_id=%d AND service_id=%d', absint($specialist_id), absint($service_id)), ARRAY_A);
		return is_array($row) ? $row : array();
	}

	public static function resolve($specialist_id, $service_id) {
		$row = self::get($specialist_id, $service_id);
		$meta = class_exists('Luna_Appointments_Services') ? Luna_Appointments_Services::get_service_meta_values(absint($service_id)) : array();
		$global_step = class_exists('Luna_Appointments_Bookings') ? Luna_Appointments_Bookings::get_booking_slot_step_minutes() : 30;
		return array(
			'duration_minutes' => self::bounded($row['duration_minutes'] ?? 0, 1, 720, self::bounded($meta['_luna_service_duration_minutes'] ?? 0, 1, 720, 60)),
			'buffer_minutes' => self::bounded($row['buffer_minutes'] ?? -1, 0, 240, self::bounded($meta['_luna_service_booking_buffer'] ?? 0, 0, 240, 0)),
			'slot_step_minutes' => self::bounded($row['slot_step_minutes'] ?? 0, 1, 240, self::bounded($global_step, 1, 240, 30)),
			'source' => empty($row) ? 'fallback' : 'specialist_service',
		);
	}

	private static function bounded($value, $min, $max, $fallback) {
		$value = is_numeric($value) ? (int) $value : 0;
		return ($value >= $min && $value <= $max) ? $value : (int) $fallback;
	}

	public static function save_many($specialist_id, $input, $allowed_service_ids) {
		global $wpdb;
		$specialist_id = absint($specialist_id);
		$allowed = array_values(array_unique(array_filter(array_map('absint', (array) $allowed_service_ids))));
		$input = is_array($input) ? $input : array();
		$now = class_exists('Luna_Appointments_Date') ? Luna_Appointments_Date::db_now() : current_time('mysql', true);
		foreach ($allowed as $service_id) {
			$row = isset($input[$service_id]) && is_array($input[$service_id]) ? $input[$service_id] : array();
			$duration = isset($row['duration_minutes']) && '' !== (string)$row['duration_minutes'] ? self::bounded($row['duration_minutes'], 1, 720, 65535) : 65535;
			$buffer = isset($row['buffer_minutes']) && '' !== (string)$row['buffer_minutes'] ? self::bounded($row['buffer_minutes'], 0, 240, 65535) : 65535;
			$step = isset($row['slot_step_minutes']) && '' !== (string)$row['slot_step_minutes'] ? self::bounded($row['slot_step_minutes'], 1, 240, 65535) : 65535;
			if (65535 === $duration && 65535 === $buffer && 65535 === $step) {
				$wpdb->delete(self::table_name(), array('specialist_id'=>$specialist_id,'service_id'=>$service_id), array('%d','%d'));
				continue;
			}
			$existing = self::get($specialist_id, $service_id);
			$data = array('specialist_id'=>$specialist_id,'service_id'=>$service_id,'duration_minutes'=>$duration,'buffer_minutes'=>$buffer,'slot_step_minutes'=>$step,'updated_at'=>$now);
			if ($existing) {
				$wpdb->update(self::table_name(), $data, array('id'=>(int)$existing['id']), array('%d','%d','%d','%d','%d','%s'), array('%d'));
			} else {
				$data['created_at'] = $now;
				$wpdb->insert(self::table_name(), $data, array('%d','%d','%d','%d','%d','%s','%s'));
			}
		}
		if ($allowed) {
			$placeholders = implode(',', array_fill(0, count($allowed), '%d'));
			$params = array_merge(array($specialist_id), $allowed);
			$wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_name() . " WHERE specialist_id=%d AND service_id NOT IN ({$placeholders})", ...$params));
		} elseif ($specialist_id > 0) {
			$wpdb->delete(self::table_name(), array('specialist_id' => $specialist_id), array('%d'));
		}
	}

	public static function render_fields($specialist_id, $service_ids, $prefix = 'luna_service_timing') {
		$service_ids = array_values(array_unique(array_filter(array_map('absint', (array) $service_ids))));
		echo '<style>.luna-service-timing-row{display:grid;grid-template-columns:minmax(160px,1.5fr) repeat(3,minmax(100px,1fr));gap:10px;align-items:end;padding:12px;background:#fff;border-radius:12px}.luna-service-timing-row input{width:100%}@media(max-width:782px){.luna-service-timing-row{grid-template-columns:1fr 1fr}.luna-service-timing-row>strong{grid-column:1/-1}}@media(max-width:440px){.luna-service-timing-row{grid-template-columns:1fr}}</style>';
		echo '<section class="luna-service-timings" style="display:grid;gap:12px;padding:16px;border:1px solid #dfe5d5;border-radius:14px;background:#fbfcf8">';
		echo '<div><strong>' . esc_html__('زمان‌بندی اختصاصی خدمات', 'luna-appointments') . '</strong><p style="margin:6px 0 0;color:#64705b">' . esc_html__('مقادیر خالی از تنظیم خود خدمت و سپس تنظیم سراسری استفاده می‌کنند.', 'luna-appointments') . '</p></div>';
		if (! $service_ids) { echo '<p>' . esc_html__('ابتدا خدمات این متخصص را انتخاب و ذخیره کنید.', 'luna-appointments') . '</p></section>'; return; }
		foreach ($service_ids as $service_id) {
			$row = self::get($specialist_id, $service_id); $resolved = self::resolve($specialist_id, $service_id);
			echo '<div class="luna-service-timing-row">';
			echo '<strong>' . esc_html(get_the_title($service_id)) . '</strong>';
			$fields = array('duration_minutes'=>__('مدت رزرو (دقیقه)', 'luna-appointments'),'buffer_minutes'=>__('فاصله بعد رزرو', 'luna-appointments'),'slot_step_minutes'=>__('فاصله شروع اسلات‌ها', 'luna-appointments'));
			foreach ($fields as $key=>$label) { $max = 'duration_minutes'===$key ? 720 : 240; $value = isset($row[$key]) && (int)$row[$key] <= $max ? (int)$row[$key] : ''; echo '<label><span style="display:block;font-size:12px;margin-bottom:5px">'.esc_html($label).'</span><input style="width:100%" type="number" min="'.('buffer_minutes'===$key?'0':'1').'" max="'.$max.'" name="'.esc_attr($prefix).'['.esc_attr((string)$service_id).']['.esc_attr($key).']" value="'.esc_attr((string)$value).'" placeholder="'.esc_attr((string)$resolved[$key]).'"></label>'; }
			echo '</div>';
		}
		echo '</section>';
	}
}
