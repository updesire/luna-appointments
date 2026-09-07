<?php
/** Lightweight slot-generation tests; run with: php tests/test-specialist-service-timing.php */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-luna-appointments-bookings.php';

$method = new ReflectionMethod('Luna_Appointments_Bookings', 'build_schedule_candidate_times');
$method->setAccessible(true);

$cases = array(
	'45 minute starts with 90 minute service' => array(
		array('start' => '10:00', 'end' => '14:00'),
		45,
		90,
		15,
		array('10:00', '10:45', '11:30', '12:15'),
	),
	'long appointment cannot overrun shift' => array(
		array('start' => '10:00', 'end' => '18:00'),
		60,
		240,
		30,
		array('10:00', '11:00', '12:00', '13:00'),
	),
);

$failed = 0;
foreach ($cases as $name => $case) {
	$actual = $method->invoke(null, $case[0], $case[1], $case[2], $case[3], '');
	if ($actual !== $case[4]) {
		$failed++;
		fwrite(STDERR, "FAIL {$name}: " . json_encode($actual) . PHP_EOL);
	} else {
		echo "PASS {$name}" . PHP_EOL;
	}
}

exit($failed ? 1 : 0);
