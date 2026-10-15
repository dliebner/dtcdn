<?php

define('IN_SCRIPT', 1);

$root_path = '/home/dtcdn/';

require($root_path . 'common.php');

if( $containerId = $argv[1] ) {

	if( $tJob = TranscodingJob::getByContainerId($containerId) ) {

		$tJob->finishTranscode();

	}

}
