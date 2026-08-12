<?php
/**
 * Date boundary for the appointments domain.
 *
 * Database values are always ASCII Gregorian dates in the WordPress timezone.
 * Jalali conversion is performed only when producing a human-facing label.
 *
 * @package LunaAppointments
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Luna_Appointments_Date {
	public static function timezone() {
		return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
	}

	public static function now() {
		return new DateTimeImmutable('now', self::timezone());
	}

	public static function db_now() {
		return self::now()->format('Y-m-d H:i:s');
	}

	public static function db_today() {
		return self::now()->format('Y-m-d');
	}

	public static function parse_date($date) {
		$date = self::latin_digits(trim((string) $date));
		if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return null;
		}
		$year = (int) substr($date, 0, 4);
		if ($year < 1900 || $year > 2200) {
			return null;
		}
		$value  = DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::timezone());
		$errors = DateTimeImmutable::getLastErrors();
		if (! $value || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $value->format('Y-m-d') !== $date) {
			return null;
		}
		return $value;
	}

	public static function parse_datetime($date, $time = '00:00:00') {
		$date = self::latin_digits(trim((string) $date));
		$time = self::latin_digits(trim((string) $time));
		if (1 === preg_match('/^\d{2}:\d{2}$/', $time)) {
			$time .= ':00';
		}
		if (1 !== preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
			return null;
		}
		$value  = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, self::timezone());
		$errors = DateTimeImmutable::getLastErrors();
		if (! $value || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $value->format('Y-m-d H:i:s') !== $date . ' ' . $time) {
			return null;
		}
		return $value;
	}

	public static function timestamp($date, $time = '00:00:00') {
		$value = self::parse_datetime($date, $time);
		return $value ? $value->getTimestamp() : 0;
	}

	public static function add_days($date, $days) {
		$value = self::parse_date($date);
		return $value ? $value->modify(sprintf('%+d days', (int) $days))->format('Y-m-d') : '';
	}

	public static function format_jalali($date, $time = '', $include_weekday = true) {
		$value = self::parse_date($date);
		if (! $value) {
			return trim((string) $date . ' ' . (string) $time);
		}
		list($jy, $jm, $jd) = self::gregorian_to_jalali((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));
		$weekdays = array('یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه');
		$label = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
		if ($include_weekday) {
			$label = $weekdays[(int) $value->format('w')] . ' ' . $label;
		}
		if ('' !== trim((string) $time)) {
			$label .= ' - ' . substr(self::latin_digits((string) $time), 0, 5);
		}
		return self::persian_digits($label);
	}

	public static function format_jalali_day($date) {
		$value = self::parse_date($date);
		if (! $value) {
			return '';
		}
		$parts = self::gregorian_to_jalali((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));
		return self::persian_digits((string) $parts[2]);
	}

	public static function format_db_datetime_jalali($datetime) {
		$datetime = self::latin_digits(trim((string) $datetime));
		if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})(?::\d{2})?$/', $datetime, $matches)) {
			return $datetime;
		}
		return self::format_jalali($matches[1], $matches[2], false);
	}

	public static function persian_digits($value) {
		return strtr((string) $value, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
	}

	public static function latin_digits($value) {
		return strtr((string) $value, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
	}

	public static function jalali_to_gregorian_date($date) {
		$date = str_replace('/', '-', self::latin_digits(trim((string) $date)));
		if (1 !== preg_match('/^(1[34]\d{2})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
			return '';
		}
		list($gy, $gm, $gd) = self::jalali_to_gregorian((int) $matches[1], (int) $matches[2], (int) $matches[3]);
		$result = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
		return self::parse_date($result) ? $result : '';
	}

	/** Algorithm based on the public-domain jalaali conversion formula. */
	private static function gregorian_to_jalali($gy, $gm, $gd) {
		$gdm = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
		$gy2 = $gm > 2 ? $gy + 1 : $gy;
		$days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
		$jy = -1595 + (33 * intdiv($days, 12053));
		$days %= 12053;
		$jy += 4 * intdiv($days, 1461);
		$days %= 1461;
		if ($days > 365) {
			$jy += intdiv($days - 1, 365);
			$days = ($days - 1) % 365;
		}
		if ($days < 186) {
			$jm = 1 + intdiv($days, 31);
			$jd = 1 + ($days % 31);
		} else {
			$jm = 7 + intdiv($days - 186, 30);
			$jd = 1 + (($days - 186) % 30);
		}
		return array($jy, $jm, $jd);
	}

	private static function jalali_to_gregorian($jy, $jm, $jd) {
		$jy += 1595;
		$days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd;
		$days += $jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186;
		$gy = 400 * intdiv($days, 146097);
		$days %= 146097;
		if ($days > 36524) {
			$gy += 100 * intdiv(--$days, 36524);
			$days %= 36524;
			if ($days >= 365) {
				$days++;
			}
		}
		$gy += 4 * intdiv($days, 1461);
		$days %= 1461;
		if ($days > 365) {
			$gy += intdiv($days - 1, 365);
			$days = ($days - 1) % 365;
		}
		$gd = $days + 1;
		$leap = (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0);
		$months = array(0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
		for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
			$gd -= $months[$gm];
		}
		return array($gy, $gm, $gd);
	}
}
