<?php

/**
 * Shows the transcoding jobs that are in flight or stuck, and with --fix runs the minute cron's sweep once.
 *
 *   sudo -u dtcdn /usr/bin/php7.4 /home/dtcdn/scripts/jobs-status.php [--fix]
 *
 * Lists: active jobs (holding a transcode slot) with their container's state, jobs waiting for a slot, failed jobs
 * awaiting cleanup, and cloud uploads marked started but not finished. --fix calls TranscodingJob::sweepActiveJobs()
 * and resetStaleCloudUploads(), the same as cron/minute.php does every minute, and prints what they did.
 */

define('IN_SCRIPT', 1);

$root_path = '/home/dtcdn/';

require($root_path . 'common.php');

$fix = in_array('--fix', array_slice($argv, 1), true);

// install/dtcdn-cron redirects every dtcdn cron's output here. Without the directory the shell's redirect fails and
// cron never runs the command, so the sweep, the cloud queue and the bandwidth update all stop without a trace.
if( !is_dir('/var/log/dtcdn') ) echo "WARNING: /var/log/dtcdn is missing; the dtcdn crons in /etc/cron.d/dtcdn-cron can't start (see install/manual-steps.txt)\n\n";

function printJobs($title, array $jobs, callable $describe) {

	echo $title . ': ' . count($jobs) . "\n";

	foreach( $jobs as $job ) {

		echo '  #' . $job->id . ' ' . $job->versionFilename . ' (src ' . $job->srcFilename . ') ' . $describe($job) . "\n";

	}

	echo "\n";

}

printJobs('Active (holding a transcode slot)', TranscodingJob::getActiveTranscodeJobs(), function(TranscodingJob $job) {

	$state = $job->containerState($output);
	$stateText = $state ? $state['status'] . ($state['status'] === 'exited' ? ' code ' . $state['exitCode'] : '') : 'state unknown (' . trim(implode(' ', $output ?: [])) . ')';

	return 'active ' . (int)$job->data['active_minutes'] . ' min, container ' . ($job->dockerContainerId ? substr($job->dockerContainerId, 0, 12) . ' ' . $stateText : 'none')
		. ($job->data['transcode_fail_code'] !== null ? ', last error ' . $job->data['transcode_fail_output'] : '');

});

printJobs('Waiting for a slot', TranscodingJob::getUnstartedTranscodeJobs(), function(TranscodingJob $job) {

	return 'waiting ' . $job->ageMinutes() . ' min';

});

printJobs('Failed (deleted an hour after failing)', TranscodingJob::getFailedJobs(), function(TranscodingJob $job) {

	return 'code ' . $job->failCode() . ', finished ' . $job->data['transcode_finished'];

});

printJobs('Cloud uploads started, not finished', TranscodingJob::getPendingCloudUploadJobs(), function(TranscodingJob $job) {

	$parts = [];
	if( $job->data['src_cloud_upload_started'] && !$job->data['src_cloud_upload_finished'] ) $parts[] = 'source ' . (int)$job->data['src_cloud_upload_minutes'] . ' min';
	if( $job->data['cloud_upload_started'] && !$job->data['cloud_upload_finished'] ) $parts[] = 'version ' . (int)$job->data['cloud_upload_minutes'] . ' min';

	return implode(', ', $parts) . ' (re-queued after ' . TranscodingJob::CLOUD_UPLOAD_STALE_MINUTES . ' min)';

});

echo 'Transcode slots: ' . TranscodingJob::numActiveTranscodingJobs() . ' of ' . Config::get('transcode_job_limit') . " in use\n\n";

if( $fix ) {

	$report = TranscodingJob::sweepActiveJobs();
	$report['cloudUploadsRequeued'] = TranscodingJob::resetStaleCloudUploads();

	echo 'Sweep: ' . json_encode($report) . "\n";

} else {

	echo "Run with --fix to sweep now (what cron/minute.php does every minute).\n";

}
