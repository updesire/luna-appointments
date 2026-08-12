<?php
/** Lightweight calculation tests; run with: php tests/test-consultation-finance.php */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-luna-appointments-consultation-finance.php';

$cases = array(
	'fee deducted' => array(10000000, 1000000, true, array('total_amount' => 10000000.0, 'upfront_fee' => 1000000.0, 'balance_amount' => 9000000.0)),
	'fee not deducted' => array(10000000, 1000000, false, array('total_amount' => 10000000.0, 'upfront_fee' => 1000000.0, 'balance_amount' => 10000000.0)),
	'fee capped to total' => array(500000, 1000000, true, array('total_amount' => 500000.0, 'upfront_fee' => 500000.0, 'balance_amount' => 0.0)),
	'unknown final total' => array(0, 750000, true, array('total_amount' => 0.0, 'upfront_fee' => 750000.0, 'balance_amount' => 0.0)),
	'negative values protected' => array(-1, -20, true, array('total_amount' => 0.0, 'upfront_fee' => 0.0, 'balance_amount' => 0.0)),
);

$failed = 0;
foreach ($cases as $name => $case) {
	$actual = Luna_Appointments_Consultation_Finance::calculate_amounts($case[0], $case[1], $case[2]);
	if ($actual != $case[3]) {
		$failed++;
		fwrite(STDERR, "FAIL {$name}: " . json_encode($actual) . PHP_EOL);
	} else {
		echo "PASS {$name}" . PHP_EOL;
	}
}
exit($failed ? 1 : 0);
