<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

function default_exception_handler($e) {

	Logger::logEvent("api exception", [
		'email' => true,
		'exception' => $e
	]);
	
	$eClass = get_class($e);
	
	if( in_array($eClass, array('QueryException','GeneralException','SilentAjaxException','GeneralExceptionWithData')) ) {
	
		handleAjaxException($e);
		
	} else {

		echo '<div>';
		echo '<b>Fatal error</b>:  Uncaught exception \'' . get_class($e) . '\' with message ';
		echo $e->getMessage() . '<br>';
		echo 'Stack trace:<pre>' . $e->getTraceAsString() . '</pre>';
		echo 'thrown in <b>' . $e->getFile() . '</b> on line <b>' . $e->getLine() . '</b><br>';
		echo '</div>';
		
	}

}

function handleAjaxException(Exception $e, $options = array()) {
		
	switch( get_class($e) ) {

		case 'GeneralException':

			AjaxResponse::returnError($e->getMessage(), null, $options);

		case 'GeneralExceptionWithData':

			AjaxResponse::returnError($e->getMessage(), debugEnabled() ? $e->data : null, $options);
			
		case 'SilentAjaxException':

			die( AjaxResponse::status('silentError', $e->getMessage(), null, $options) );
		
		case 'QueryException':

			global $db;

			if( debugEnabled() ) {
			
				AjaxResponse::criticalDie(
					$e->getMessage() . "\n"
						. 'on line ' . $e->getLine() . "\n"
						. ' in ' . $e->getFile() . "\n\n"
						. $e->sql,
					array('sql' => $e->sql, 'err' => $db->sql_error()),
					$options
				);

			} else {
			
				AjaxResponse::criticalDie(
					$e->getMessage() . "\n"
						. 'on line ' . $e->getLine() . "\n"
						. ' in ' . $e->getFile(),
					[],
					$options
				);

			}
			
			break;
			
		default:

			AjaxResponse::criticalDie($e->getMessage(), null, $options);
			
			break;
		
	}
	
}

set_exception_handler('default_exception_handler');

/**
 * Client receiving from Hub
 */

 $secretKey = Config::get('secret_key');

// Attempt to decode the parcel
if( !$payload = JSONEncrypt::decode(postdata_to_original($_POST['parcel']), $secretKey ?: 'init') ) AjaxResponse::criticalDie('Invalid parcel.');

if( !$payload->action ) AjaxResponse::criticalDie('Missing action.');
$params = $payload->params;

if( !$secretKey ) {
		
	// Allow init only
	if( $payload->action == CDNClient::CLIENT_ACTION_INIT_SERVER ) {

		// Require HTTPS
		if( $_SERVER['HTTPS'] !== 'on' ) {
	
			AjaxResponse::criticalDie("Attempted to init insecurely. Choose a new secretKey and try again over HTTPS.");
	
		}

		$newConfig = (array)$params->updateConfig;

		if( !$newSecretKey = $newConfig['secret_key'] ) {

			AjaxResponse::criticalDie("No secret key passed.");

		}

		if( !$newServerId = $newConfig['server_id'] ) {

			AjaxResponse::criticalDie("No server ID passed.");

		}

		if( !$newHubApiUrl = $newConfig['hub_api_url'] ) {

			AjaxResponse::criticalDie("No hub API URL passed.");

		}

		$success = false;

		CDNClient::postToHub(CDNClient::HUB_ACTION_VALIDATE_SECRET_KEY, [], [
			'serverId' => $newServerId,
			'secretKey' => $newSecretKey,
			'hubApiUrl' => $newHubApiUrl,
			'success' => function() use (&$success) {

				$success = true;

			}
		]);

		if( $success ) {

			Config::setMulti($newConfig);

			AjaxResponse::returnSuccess();

		} else {

			AjaxResponse::criticalDie("Error validating secret key.");

		}

	} else {

		AjaxResponse::criticalDie('Invalid parcel.');

	}

}

switch( $payload->action ) {

	case CDNClient::CLIENT_ACTION_VALIDATE_SECRET_KEY:

		AjaxResponse::returnSuccess();

		break;

	case CDNClient::CLIENT_ACTION_GIT_PULL:

		$result = CDNClient::gitPull();

		AjaxResponse::returnSuccess([
			'pullSuccess' => $result
		]);

		break;

	case CDNClient::CLIENT_ACTION_SYNC_CLIENT_DATA:

		AjaxResponse::returnSuccess([
			'clientServerStatus' => ServerStatus::getAll(),
		]);

		break;

	case CDNClient::CLIENT_ACTION_GET_VIDEO_INFO_FROM_URL:

		if( !$videoUrl = $params->url ) AjaxResponse::criticalDie("Missing url");

		$options = $params->options ?: (object)[
			'dctHash' => false,
			'md5' => false,
		];

		$videoInfo = [];

		// Download video as temporary file
		if( !$fp_tmp = CDNTools::downloadTemporaryFile( $videoUrl ) ) {

			AjaxResponse::returnError("Error downloading: $videoUrl");

		}

		$tmpFile = CDNTools::getStreamPath($fp_tmp);

		// Probe the file
		if( !$probeResult = FFProbe::dockerProxyProbe($tmpFile, $execResult, $execOutput, $ffprobeResultRaw) ) {

			AjaxResponse::returnError("Error probing video file", debugEnabled() ? [
				'cmd' => $cmd,
				'execResult' => $execResult,
				'execOutput' => $execOutput,
				'ffprobeResultRaw' => $ffprobeResultRaw
			] : null);
			
		}

		if( !$videoStream = $probeResult->videoStreams[0] ) {

			AjaxResponse::returnError("Invalid video file.");

		}

		if( $options->md5 ) {

			$videoInfo['md5'] = md5_file($tmpFile);

		}

		if( $options->dctHash && ($getVideoDCTHashPath = './' . $root_path . 'utils/getVideoDCTHash') && file_exists($getVideoDCTHashPath) ) {

			// Each argument escaped once; escapeshellcmd over an escapeshellarg'd string backslashes $ and \ inside the quotes
			$cmd = escapeshellarg($getVideoDCTHashPath) . ' ' . escapeshellarg($tmpFile);

			$result = exec( $cmd );
	
			if( $result && is_numeric($result) ) {
				
				$videoInfo['dctHash'] = gmp_convert($result, 10, 36);
				
			} else {
		
				$videoInfo['dctHashFail'] = $cmd . "\n" . $result;
		
			}

		}

		// Close the temporary file stream
		fclose($fp_tmp);

		AjaxResponse::returnSuccess([
			'videoInfo' => array_merge($videoInfo, [
				'duration' => $probeResult->duration,
				'hasAudio' => $probeResult->hasAudio(),
				'width' => $videoStream->displayWidth(),
				'height' => $videoStream->displayHeight(),
			])
		]);

		break;

	case CDNClient::CLIENT_ACTION_CREATE_VIDEO_VERSION:

		if( !$multi = $params->multi ) {

			$multi = [$params];

		}

		$progressTokens = [];

		foreach( $multi as $multiParams ) {

			if( !$sourceFilename = $multiParams->sourceFilename ) AjaxResponse::criticalDie("Missing sourceFilename");
			if( !isset($multiParams->sourceIsNew) ) AjaxResponse::criticalDie("Missing sourceIsNew");
			$sourceIsNew = $multiParams->sourceIsNew;
			if( !$originalExtension = $multiParams->originalExtension ) AjaxResponse::criticalDie("Missing originalExtension");
			if( !$fileSizeBytes = $multiParams->fileSizeBytes ) AjaxResponse::criticalDie("Missing fileSizeBytes");
			if( !$maxSizeBytes = $multiParams->maxSizeBytes ) AjaxResponse::criticalDie("Missing maxSizeBytes");
			if( !$duration = $multiParams->duration ) AjaxResponse::criticalDie("Missing duration");
			if( !$versionFilename = $multiParams->versionFilename ) AjaxResponse::criticalDie("Missing versionFilename");
			$versionWidth = $multiParams->versionWidth ?? null;
			$versionHeight = $multiParams->versionHeight ?? null;
			if( !$versionWidth && !$versionHeight ) AjaxResponse::criticalDie("Missing versionWidth and versionHeight");
			if( !$targetBitRate = $multiParams->bitRate ) AjaxResponse::criticalDie("Missing bitRate");
			if( !$hlsByteSizeThreshold = $multiParams->hlsByteSizeThreshold ) AjaxResponse::criticalDie("Missing hlsByteSizeThreshold");
			if( !$sourceFfprobeJson = $multiParams->sourceFfprobeJson ) AjaxResponse::criticalDie("Missing sourceFfprobeJson");
			if( !isset($multiParams->mute) ) AjaxResponse::criticalDie("Missing mute");
			$mute = $multiParams->mute;

			// Optional poster params
			$savePoster = $multiParams->savePoster;
			$posterFrameIndex = $multiParams->posterFrameIndex;
			$posterJpegQuality = $multiParams->posterJpegQuality;
			$posterConstrainWidth = $multiParams->posterConstrainWidth;
			$posterConstrainHeight = $multiParams->posterConstrainHeight;

			if( !$probeResult = new FFProbeResult($sourceFfprobeJson) ) AjaxResponse::criticalDie("Error reading source ffprobe json");

			CDNTools::getEncodingSettings(
				$probeResult, $fileSizeBytes, $maxSizeBytes, $versionWidth, $versionHeight, $targetBitRate, $hlsByteSizeThreshold,
				$constrainWidth, $constrainHeight, $passThroughVideo, $saveAsHls
			);

			// A job for this version may already be running or done (a retried upload): report it rather than start a
			// second one into the same work directory
			if( $existingJob = TranscodingJob::findReusableJob($versionFilename) ) {

				// A row never marked ready whose writer died (a complete source here or in a sibling's directory, old
				// enough that no live request is about to mark it) is started; otherwise its token is reported as is
				$existingJob->startIfAbandonedBeforeReady();

				$progressTokens[] = $existingJob->progressToken;
				continue;

			}

			// Start new job
			$tcJob = TranscodingJob::create($sourceFilename, $sourceIsNew, $originalExtension, $fileSizeBytes, $duration, $versionFilename, $versionWidth, $versionHeight, new TranscodingJobSettings(
				$targetBitRate,
				$constrainWidth,
				$constrainHeight,
				$passThroughVideo,
				$saveAsHls,
				null,
				$mute,
				$savePoster,
				$posterFrameIndex,
				$posterJpegQuality,
				$posterConstrainWidth,
				$posterConstrainHeight
			));

			// The source from this directory or a sibling job's, only a complete copy (a partial one is another request's
			// write in progress or a dead request's leftover; until 2026-10 any file passed, and a faststart mp4 cut short
			// would have been encoded and published truncated); otherwise from the cloud
			if( $tcJob->copySourceFromSiblings() ) {

				// Start transcoding now
				$tcJob->setTranscodeReady();
				$tcJob->startTranscode();

			} else {
				
				// TODO: if source is not in cloud, set a flag to tell client to re-upload the video file
				$tcJob->queueSourceDownload();

			}

			$progressTokens[] = $tcJob->progressToken;

		}

		AjaxResponse::returnSuccess([
			'progressTokens' => $progressTokens
		]);

		break;

	case CDNClient::CLIENT_ACTION_DOWNLOAD_VIDEO_VERSIONS:

		if( !$downloadVersions = $params->downloadVersions ) AjaxResponse::criticalDie("Missing downloadVersions");

		$downloadVersions = CDNTools::objectToArrayRecursive($downloadVersions);
		CDNClient::prepareDownloadVideoVersions($downloadVersions, $missingFileDownloader);

		if( $downloadPosters = $params->downloadPosters ) {

			$downloadPosters = CDNTools::objectToArrayRecursive($downloadPosters);
			CDNClient::prepareDownloadPosters($downloadPosters, $missingFileDownloader);

		}

		$missingFileDownloader->doDownload();

		AjaxResponse::returnSuccess();

		break;

	case CDNClient::CLIENT_ACTION_DELETE_VIDEO_VERSIONS:

		if( !$deleteVersions = $params->deleteVersions ) AjaxResponse::criticalDie("Missing deleteVersions");

		$deleteVersions = CDNTools::objectToArrayRecursive($deleteVersions);

		foreach( $deleteVersions as $version ) {

			CDNClient::deleteVideoVersion($version['versionFilename'], $version['type']);

		}

		AjaxResponse::returnSuccess();

		break;

	case CDNClient::CLIENT_ACTION_DELETE_VIDEO_POSTERS:

		if( !$deletePosters = $params->deletePosters ) AjaxResponse::criticalDie("Missing deletePosters");

		$deletePosters = CDNTools::objectToArrayRecursive($deletePosters);

		foreach( $deletePosters as $posterInfo ) {

			CDNClient::deleteVideoPoster($posterInfo['srcFilename'], $posterInfo['posterFrameIndex']);

		}

		AjaxResponse::returnSuccess();

		break;

	default:

		AjaxResponse::criticalDie('Invalid action: ' . $payload->action);

		break;

}