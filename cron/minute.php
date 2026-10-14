<?php

define('IN_SCRIPT', 1);

$root_path = '/home/dtcdn/';

// Script runs once per minute, we sleep to avoid lots of on-the-minute processing
sleep(5);

require($root_path . 'common.php');

$db = db();


// Update free disk space
$diskFreeBytes = disk_free_space($root_path);
$diskFreePct = $diskFreeBytes / disk_total_space($root_path);

ServerStatus::set('disk_free_bytes', $diskFreeBytes );
ServerStatus::set('disk_free_pct', round($diskFreePct, 4) );


// Delete expired transcoding jobs
TranscodingJob::deleteExpiredJobs();

// Finish, fail or start the transcoding jobs nothing else will (a die event the daemon missed, a finish that failed
// part-way, a job waiting for a slot), and re-queue cloud uploads that never finished
$sweep = TranscodingJob::sweepActiveJobs();
$sweep['cloudUploadsRequeued'] = TranscodingJob::resetStaleCloudUploads();

if( array_filter($sweep) ) echo date('c') . ' sweep: ' . json_encode($sweep) . "\n";

ServerStatus::setMulti([
	'transcode_jobs_active' => TranscodingJob::numActiveTranscodingJobs(),
	'transcode_jobs_waiting' => count(TranscodingJob::getUnstartedTranscodeJobs()),
]);

