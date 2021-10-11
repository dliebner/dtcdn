<?php

define('IN_SCRIPT', 1);

$root_path = '/home/bgcdn/';

// Script runs once per minute, we sleep to avoid lots of on-the-minute processing
sleep(5);

require($root_path . 'common.php');

$db = db();

$diskFreeBytes = disk_free_space($root_path);
$diskFreePct = $diskFreeBytes / disk_total_space($root_path);

ServerStatus::set('disk_free_bytes', $diskFreeBytes );
ServerStatus::set('disk_free_pct', round($diskFreePct, 4) );
