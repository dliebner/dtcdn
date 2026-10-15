<?php

define('IN_SCRIPT', 1);

$root_path = '/home/dtcdn/';

require($root_path . 'common.php');

$redis = new Redis();
$redis->pconnect('127.0.0.1');

$start = time();

// Run every second for 60 seconds
set_time_limit(60 * 2);
while( time() - 60 < $start ) {

	start_timer('update30sBw');

	$bytes30s = (int)$redis->get('dtcdn:bw_30sec_exp_' . (time() + 1));

	ServerStatus::setMulti($updates = [
		'avg_bytes_per_sec_30s' => round($bytes30s / 30),
		'port_saturation_pct' => round($bytes30s * 8 / 30 / CDNTools::getPortSpeedBits(), 4),
	]);

	$timeElapsed = stop_timer('update30sBw');
	$waitSeconds = max(0, 1 - $timeElapsed);
	usleep($waitSeconds * 1000000);

}
