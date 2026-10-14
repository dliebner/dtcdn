<?php

define('IN_SCRIPT', 1);

$root_path = './../../';

include_once( $root_path . 'common.php' );
require_once( $root_path . 'includes/JSONEncrypt.php');

function default_exception_handler($e) {

	Logger::logEvent("client-api exception", [
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
 * 	Client API
 * 		Actions require tokens granted by Hub server
 * 
 * 		cdn_tokens
 * 		- id
 * 		- action
 * 		- user_id (NULL)
 * 		- created
 * 		- expires
 * 		
 */

// CORS
$origin = $_SERVER['HTTP_ORIGIN'];

if( CDNClient::corsOriginAllowed($origin) ) {

	header('Access-Control-Allow-Origin: ' . $origin);

} else {

	exit;

}

// End here for OPTIONS requests
if( strtolower($_SERVER['REQUEST_METHOD']) === 'options' ) exit;

$action = postdata_to_original($_POST['action']);

switch( $action ) {

	case 'upload-progress':

		$progressTokens = postdata_to_original($_POST['progressTokens']);

		if( !$progressTokens = array_filter(
			array_map(
				function($pt) { 
			
					return trim($pt);

				},
				explode(",", $progressTokens)
			), function($pt) {

				// Not-empty
				return strlen($pt);

			})
		) {

			AjaxResponse::returnError("Invalid progress tokens.");

		}

		if( !$jobsByPt = TranscodingJob::getAllByProgressTokens($progressTokens) ) {

			AjaxResponse::returnError("Invalid progress tokens.");

		}

		$returnProgressTokens = [];
		foreach( $jobsByPt as $pt => $job ) {

			$ret = [
				'progressToken' => $pt
			];

			if( $job->hasFailed() ) {

				AjaxResponse::returnError("There was an error transcoding the video.", debugEnabled() ? [
					'failCode' => json_decode($job->data['transcode_fail_code']),
					'failOutput' => json_decode($job->data['transcode_fail_output']),
				] : null);

			} else if( $job->transcodeFinished ) {

				$ret += [
					"isFinished" => true,
					"pctComplete" => 1
				];

				if( $job->data['hub_return_meta'] ) {

					$ret['meta'] = json_decode($job->data['hub_return_meta']);

				}

			} else if( $job->isStale() ) {

				// Waiting for a slot or for its finish for too long: an error now rather than a bar the user watches forever.
				// A job still waiting is dropped (nobody is waiting for it any more; a retry reuses a running job, not a
				// waiting one); one whose finish is being retried keeps its output and the sweep decides.
				if( !$job->transcodeStarted ) $job->markFailed('abandoned', null, 'reported stale to the uploader while waiting ' . $job->ageMinutes() . ' min', true);

				AjaxResponse::returnError("The video is taking too long to process. Please try again later.");

			} else if( !$job->transcodeStarted ) {

				$ret += [
					"isFinished" => false,
					"pctComplete" => 0,
				];

			} else {

				$pctComplete = $job->getPercentComplete($isFinished, $execResult, $dockerOutput);

				if( $pctComplete === false ) {

					AjaxResponse::returnError("There was an error transcoding the video.", debugEnabled() ? [
						'execResult' => $execResult,
						'dockerOutput' => $dockerOutput,
					] : null);

				}

				// $isFinished means ffmpeg is done but the job isn't: the die handler (or the sweep, within a minute) still
				// has to move the output and register the version, so the client keeps polling and sees 100% meanwhile
				$ret += [
					"isFinished" => false,
					"pctComplete" => $pctComplete,
				];

			}

			$returnProgressTokens[] = $ret;

		}

		AjaxResponse::returnSuccess([
			'progressTokens' => $returnProgressTokens
		]);

		break;

	case 'upload-video':

		/**
			 Upload flow:
				- Upload video to the lowest-cpu server
				- Validate video file w/ ffprobe
				- Move file to in_progress folder
				- Add transcoding_jobs entry
					transcoding_jobs
					- id
					- src
					- started (NULL)
					- finished (NULL)
					- docker_container_id (NULL)
				- Transcode video
					Simultaneously upload original to cloud storage
				- Upload transcoded video to cloud storage
		*/

		// Get uploaded file data
		$originalFilename = $_FILES['file']['name'];
		$originalExtension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: null;
		$tmpFile = $_FILES['file']['tmp_name'];

		if( !is_uploaded_file($tmpFile) ) {

			AjaxResponse::returnError("Error reading the uploaded file.");

		}

		$fileSizeBytes = filesize($tmpFile);

		if( $probeResult = FFProbe::dockerProxyProbe($tmpFile, $execResult, $execOutput, $ffprobeResultRaw) ) {

			// Check probe result

			/** @var FFProbeResult_VideoStream */
			if( !$videoStream = $probeResult->videoStreams[0] ) {

				AjaxResponse::returnError("Invalid video file.");

			}

			// Source video info
			$duration = $probeResult->duration;
			$sourceHasAudio = $probeResult->hasAudio();
			$sourceWidth = $videoStream->displayWidth();
			$sourceHeight = $videoStream->displayHeight();
		
			// Validate CDN token, send source info, get video versions (to encode)
			$cdnToken = postdata_to_original($_POST['cdnToken']);
			$userId = (int)$_POST['userId'];
	
			$returnMeta = [];
	
			if(
				!CDNClient::validateCdnToken($cdnToken, 'upload-video', [
					'sourceWidth' => $sourceWidth,
					'sourceHeight' => $sourceHeight,
					'sourceDuration' => $duration,
					'sourceSizeBytes' => $fileSizeBytes,
				], $responseData, $_SERVER['REMOTE_ADDR'], $userId)
			) {
	
				AjaxResponse::returnError("Invalid upload token.");
	
			}
		
			// Validate video file
			$fileUploadLimit = $responseData['fileUploadLimit'];
	
			if( $fileSizeBytes > $fileUploadLimit ) {
	
				AjaxResponse::returnError("Max file size: " . humanFilesize($fileUploadLimit));
	
			}
	
			// Grab metadata
			$meta = $responseData['meta'];
			if( is_array($responseData['returnMeta']) ) $returnMeta = $responseData['returnMeta'] + $returnMeta;
	
			if( !$versions = $responseData['versions'] ) throw new GeneralExceptionWithData("Missing versions in validateCdnToken response.", $responseData);

			// Optional poster params
			$savePoster = $meta['savePoster'];
			$posterFrameIndex = $meta['posterFrameIndex'];
			$posterJpegQuality = $meta['posterJpegQuality'];
			$posterConstrainWidth = $meta['posterConstrainWidth'];
			$posterConstrainHeight = $meta['posterConstrainHeight'];
	
			// Get lowest maxDuration
			$minMaxDuration = null;
			foreach( $versions as $version ) {
	
				if( !$minMaxDuration || $version['maxDuration'] < $minMaxDuration ) $minMaxDuration = $version['maxDuration'];
	
			}

			foreach( $versions as $version ) {

				// Video encoding settings
				$maxSizeBytes = $version['maxSizeBytes'];
				$maxDuration = $version['maxDuration'];
				$targetBitRate = $version['bitRate'];
				$targetSizeBytes = ceil($targetBitRate * $probeResult->duration / 8);
				$targetWidth = $version['width'];
				$targetHeight = $version['height'];
				$mute = (bool)$version['mute'];
				$hlsByteSizeThreshold = $version['hlsByteSizeThreshold'];

				CDNTools::getEncodingSettings(
					$probeResult, $fileSizeBytes, $maxSizeBytes, $targetWidth, $targetHeight, $targetBitRate, $hlsByteSizeThreshold,
					$constrainWidth, $constrainHeight, $passThroughVideo, $saveAsHls
				);

				if( !($passThroughVideo || $probeResult->duration <= $maxDuration) ) {

					AjaxResponse::returnError("Video must be under " . floor($minMaxDuration) . " seconds long.");

				}

			}

			$sha1 = sha1_file($tmpFile);

			/**
			 * Submit to hub server to add video:
			 * 	- creative_video_src (if not exists based on file hash)
			 *  - creative_video_versions
			 * 
			 * Get:
			 * 	- src filename
			 * 	- version_filename?
			 */

			if( !CDNClient::createSourceVideo($meta, $originalExtension, $sourceWidth, $sourceHeight, $sourceHasAudio, $fileSizeBytes, $duration, $ffprobeResultRaw, $sha1, $hubResponseDataArray) ) {

				AjaxResponse::returnError("Error saving source video.");

			}
			
			if( is_array($hubResponseDataArray['returnMeta']) ) $returnMeta = $hubResponseDataArray['returnMeta'] + $returnMeta;

			// Existing versions
			$existingVersions = $hubResponseDataArray['existingVersions'];

			// Missing versions
			$missingVersions = $hubResponseDataArray['missingVersions'];

			if( $existingVersions && !$missingVersions ) {

				// All versions exist
				AjaxResponse::returnSuccess([
					'existingVersions' => $existingVersions,
					'meta' => $returnMeta
				]);

			}

			$movedUploadedFile = false;
			$progressTokens = [];
			foreach( $missingVersions as $version ) {

				$sourceFilename = $hubResponseDataArray['sourceFilename'];
				$sourceIsNew = $hubResponseDataArray['sourceIsNew'];
				$versionFilename = $version['versionFilename'];

				$encodingSettings = $version['encodingSettings'];
				$maxSizeBytes = $encodingSettings['maxSizeBytes'];
				$maxDuration = $encodingSettings['maxDuration'];
				$targetBitRate = $encodingSettings['bitRate'];
				$targetSizeBytes = ceil($targetBitRate * $probeResult->duration / 8);
				$targetWidth = $encodingSettings['width'];
				$targetHeight = $encodingSettings['height'];
				$mute = (bool)$encodingSettings['mute'];
				$hlsByteSizeThreshold = $encodingSettings['hlsByteSizeThreshold'];

				CDNTools::getEncodingSettings(
					$probeResult, $fileSizeBytes, $maxSizeBytes, $targetWidth, $targetHeight, $targetBitRate, $hlsByteSizeThreshold,
					$constrainWidth, $constrainHeight, $passThroughVideo, $saveAsHls
				);

				// A job for this version may already be running or done (the uploader gave up and the user tried again):
				// report it rather than start a second one into the same work directory
				if( $existingJob = TranscodingJob::findReusableJob($versionFilename) ) {

					$progressTokens[] = $existingJob->progressToken;
					continue;

				}

				// Start new job
				$tcJob = TranscodingJob::create($sourceFilename, $sourceIsNew, $originalExtension, $fileSizeBytes, $duration, $versionFilename, $targetWidth, $targetHeight, new TranscodingJobSettings(
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

				// Can't move uploaded file twice; save job the moved it first, then copy
				if( !$movedUploadedFile ) {

					// Move file to in-progress folder (/home/dtcdn/transcoding)
					if( !$tcJob->moveUploadedFile($tmpFile) ) {

						AjaxResponse::returnError("Error preparing transcoding job.");

					}

					$movedUploadedFile = $tcJob;

				} else {

					// Copy source video to new job's inProgressPath
					$tcJob->createInProgressDir();
					copy($movedUploadedFile->inProgressPath(), $tcJob->inProgressPath());

				}

				$tcJob->setTranscodeReady();
				$tcJob->startTranscode();

				$progressTokens[] = $tcJob->progressToken;

			}

			// In-progress
			AjaxResponse::returnSuccess([
				'progressTokens' => $progressTokens,
				'meta' => $returnMeta,
			]);

		}

		AjaxResponse::returnError("Error encoding video.", debugEnabled() ? [
			'cmd' => $cmd,
			'execResult' => $execResult,
			'execOutput' => $execOutput,
			'ffprobeResultRaw' => $ffprobeResultRaw
		] : null);

		break;

	default:

		/*
		print_r([
			'upload_max_filesize' => ini_get('upload_max_filesize'),
			'post_max_size' => ini_get('post_max_size'),
			'$_POST' => $_POST,
			'$_FILES' => $_FILES
		]);
		*/

		AjaxResponse::criticalDie("Invalid action.");

}
