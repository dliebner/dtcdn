<?php

// TODO: Rename to CDNClientTools.php

if( !defined('IN_SCRIPT') ) die( "Hacking attempt" );

class CDNClient {

	/**
	 * Directories:
	 * 	- Uploads in progress (PHP temp dir)
	 * 	- Transcoding in progress
	 * 		Source file
	 * 		Output file or path (ie HLS)
	 */
	const DIR_TRANSCODE_IN_PROGRESS = 'transcoding/';
	const DIR_TRANSCODE_OUTPUT = 'out/';
	const DIR_WWW = 'www/';
	const DIR_VIDEO = 'v/';

	const HUB_ACTION_VALIDATE_SECRET_KEY = 'validateSecretKey';
	const HUB_ACTION_SYNC_CLIENT_DATA = 'syncClientData';
	const HUB_ACTION_VALIDATE_CDN_TOKEN = 'validateCdnToken';
	const HUB_ACTION_CREATE_SOURCE_VIDEO = 'createSourceVideo';
	const HUB_ACTION_CREATE_VIDEO_VERSION = 'createVideoVersion';
	const HUB_ACTION_FILE_ORACLE_MISSING_PATHS = 'queryMissingPaths';

	const CLIENT_ACTION_INIT_SERVER = 'initServer';
	const CLIENT_ACTION_VALIDATE_SECRET_KEY = 'validateSecretKey';
	const CLIENT_ACTION_GIT_PULL = 'gitPull';
	const CLIENT_ACTION_SYNC_CLIENT_DATA = 'syncClientData';
	const CLIENT_ACTION_CREATE_VIDEO_VERSION = 'createVideoVersion';
	const CLIENT_ACTION_DOWNLOAD_VIDEO_VERSIONS = 'downloadVideoVersions';
	const CLIENT_ACTION_DELETE_VIDEO_VERSIONS = 'deleteVideoVersions';
	const CLIENT_ACTION_DELETE_VIDEO_POSTERS = 'deleteVideoPosters';
	const CLIENT_ACTION_GET_VIDEO_INFO_FROM_URL = 'getVideoInfoFromUrl';

	public static function gitPull(&$execOutput = null) {

		$cmd = 'sudo /home/dtcdn/scripts/git-pull.sh'; // a constant: nothing to escape

		$execOutput = null;
		exec($cmd, $execOutput, $execResult);

		return $execResult === 0;

	}

	public static function postToHub( $action, $params = array(), $options = array() ) {

		global $root_path;

		$serverId = $options['serverId'] ?: Config::get('server_id');
		$secretKey = $options['secretKey'] ?: Config::get('secret_key');
		$hubApiUrl = $options['hubApiUrl'] ?: Config::get('hub_api_url');

		if( !$serverId ) throw new Exception('Server ID is not set.');
		if( !$secretKey ) throw new Exception('Secret key is not set.');
		if( !$hubApiUrl ) throw new Exception('Hub API URL is not set.');

		require_once($root_path. 'includes/JSONEncrypt.php');
		
		// Pack the parcel
		$parcel = array('action' => $action);
		if( is_array($params) && count($params) > 0 ) $parcel['params'] = $params;
		
		$curlParams = array(
			'id' => $serverId,
			'parcel' => JSONEncrypt::encode($parcel, $secretKey)
		);
		
		if( !$response = self::curlPost($hubApiUrl, $curlParams) ) {
			
			throw new Exception('Error when posting to the hub server: no response');
			
		}
			
		if( (!$parsedResponse = json_decode($response)) || !$parsedResponse->status ) {
			
			throw new Exception('Error when posting to the hub server: ' . $response);
			
		}
		
		switch( $parsedResponse->status ) {
			
			case 'success':
				
				if( is_callable($options['success']) ) {
					
					call_user_func($options['success'], $parsedResponse);
					
				}
				
				break;
				
			case 'critical':
				
				throw new Exception('Critical error returned from hub server: ' . $parsedResponse->message);
				
				break;
			
		}
		
	}

	public static function corsOriginAllowed($origin) {

		$corsOrigins = explode(",", Config::get('cors_origins'));

		return in_array($origin, $corsOrigins);

	}

	public static function validateCdnToken($cdnToken, $action, $extraData = [], &$hubResponseDataArray = null, $ip = null, $userId = null) {

		$success = false;

		$extraData = $extraData ?: [];

		self::postToHub(self::HUB_ACTION_VALIDATE_CDN_TOKEN, [
			'tokenKey' => $cdnToken,
			'action' => $action,
			'ip' => $ip,
			'userId' => $userId,
			'extraData' => $extraData,
		],[
			'success' => function($response) use (&$success, &$hubResponseDataArray) {

				if( $response->data && $response->data->result ) $success = true;

				$hubResponseDataArray = CDNTools::objectToArrayRecursive($response->data);

			}
		]);

		return $success;

	}

	public static function createSourceVideo($meta, $sourceExtension, $sourceWidth, $sourceHeight, $sourceHasAudio, $sourceSizeBytes, $duration, $ffprobeResultJson, $sha1, &$hubResponseDataArray = null) {

		$success = false;

		if( !is_string($ffprobeResultJson) ) $ffprobeResultJson = json_encode($ffprobeResultJson);

		self::postToHub(self::HUB_ACTION_CREATE_SOURCE_VIDEO, [
			'meta' => $meta,
			'sourceExtension' => $sourceExtension,
			'sourceWidth' => $sourceWidth,
			'sourceHeight' => $sourceHeight,
			'sourceHasAudio' => $sourceHasAudio,
			'sourceSizeBytes' => $sourceSizeBytes,
			'duration' => $duration,
			'ffprobeResultJson' => $ffprobeResultJson,
			'sha1' => $sha1
		],[
			'success' => function($response) use (&$success, &$hubResponseDataArray) {

				$success = true;

				$hubResponseDataArray = CDNTools::objectToArrayRecursive($response->data);

			}
		]);

		return $success;

	}

	public static function createVideoVersion($sourceFilename, $versionWidth, $versionHeight, $versionHasAudio, $outputType, $sizeBytes, $versionFilename, &$hubResponseDataArray = null) {

		$success = false;

		if( !in_array($outputType, ['mp4','hls']) ) throw new Exception("Invalid output type");

		self::postToHub(self::HUB_ACTION_CREATE_VIDEO_VERSION, [
			'sourceFilename' => $sourceFilename,
			'versionWidth' => $versionWidth,
			'versionHeight' => $versionHeight,
			'versionHasAudio' => $versionHasAudio,
			'outputType' => $outputType,
			'sizeBytes' => $sizeBytes,
			'versionFilename' => $versionFilename,
		],[
			'success' => function($response) use (&$success, &$hubResponseDataArray) {

				$success = true;

				$hubResponseDataArray = CDNTools::objectToArrayRecursive($response->data);

			}
		]);

		return $success;

	}

	public static function syncClientServerStatus() {

		self::postToHub(self::HUB_ACTION_SYNC_CLIENT_DATA, [
			'clientServerStatus' => ServerStatus::getAll(),
		]);

	}

	public static function syncClientServerConfig() {

		self::postToHub(self::HUB_ACTION_SYNC_CLIENT_DATA, [
			'clientServerConfig' => Config::getAll(),
		]);

	}

	/** A hub call that fails before it reaches the hub (DNS, TCP, TLS) is tried once more after this many seconds */
	const HUB_RETRY_DELAY_SECONDS = 2;

	protected static function curlPost($url, $params = array(), $isRetry = false) {
		
		//open connection
		$ch = curl_init();
		
		//set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Timeouts: the connect phase includes the DNS lookup; the total bounds a hub that accepts the connection and
		// then hangs, which used to hold the caller (the die handler, a cron) indefinitely
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		
		if( count($params) > 0 ) {
			
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			
		}
		
		//execute post
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$sentAnything = curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0; // 0 until the request has been handed to the hub
		
		//close connection
		curl_close($ch);
		
		if( $errno ) {

			// Nothing reached the hub, so a second try is safe: a dropped or slow DNS answer, a connection refused or
			// timing out (CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, and
			// CURLE_OPERATION_TIMEDOUT before anything was sent, which is what "Resolving timed out" reports as)
			if( !$isRetry && (in_array($errno, [6, 7, 35], true) || ($errno === 28 && !$sentAnything)) ) {

				sleep(self::HUB_RETRY_DELAY_SECONDS);

				return self::curlPost($url, $params, true);

			}

			throw new Exception('cURL error: ' . $err);

		}
		
		return $response;
		
	}

	protected static $b2Client;
	public static function getB2Client() {

		if( !self::$b2Client ) {

			$b2Client = self::$b2Client = new \dliebner\B2\Client(Config::get('b2_master_key_id'), [
				'keyId' => Config::get('b2_application_key_id'), // optional if you want to use master key (account Id)
				'applicationKey' => Config::get('b2_application_key'),
			]);
			$b2Client->version = 2; // By default will use version 1

		}

		return self::$b2Client;

	}

	/** @param MissingFileDownloader $missingFileDownloader */
	public static function prepareDownloadVideoVersions(array $downloadVersions, &$missingFileDownloader = null) {

		$guzzleClient = new \GuzzleHttp\Client();
		$b2Client = self::getB2Client();
		if( !$missingFileDownloader ) $missingFileDownloader = new MissingFileDownloader($guzzleClient, $b2Client, 10);

		$clientServerSourceUrls = [];

		foreach( $downloadVersions as $version ) {

			$versionFilename = $version['versionFilename'];

			if( $hostnames = $version['clientServerSourceHostnames'] ) {

				foreach($hostnames as $cdnHostname) {
			
					if( $cdnHostname !== Config::get('hostname') ) {

						$urlBase = 'http://' . $cdnHostname . '/';

						switch( $version['type'] ) {

							case 'mp4':

								$clientServerSourceUrls[] = $urlBase . VideoPath::mp4UriPath($versionFilename);

								break;

							case 'hls':

								$clientServerSourceUrls[] = $urlBase . VideoPath::hlsZipUriPath($versionFilename);

								break;

						}

					}

				}

			}

			$missingFileDownloader->addFileToDownload(
				new MissingFile(
					($isHls = $version['type'] === 'hls') ? VideoPath::hlsZipLocalPath($versionFilename) : VideoPath::mp4LocalPath($versionFilename),
					$isHls,
					VideoPath::getVersionCloudPath($versionFilename, $version['type']),
					$clientServerSourceUrls
				)
			);

		}

	}

	/** @param MissingFileDownloader $missingFileDownloader */
	public static function prepareDownloadPosters(array $downloadPosters, &$missingFileDownloader = null) {

		$guzzleClient = new \GuzzleHttp\Client();
		$b2Client = self::getB2Client();
		if( !$missingFileDownloader ) $missingFileDownloader = new MissingFileDownloader($guzzleClient, $b2Client, 10);

		$clientServerSourceUrls = [];

		foreach( $downloadPosters as $posterDlInfo ) {

			$srcFilename = $posterDlInfo['srcFilename'];
			$posterFrameIndex = $posterDlInfo['posterFrameIndex'];

			if( $hostnames = $posterDlInfo['clientServerSourceHostnames'] ) {

				foreach($hostnames as $cdnHostname) {
			
					if( $cdnHostname !== Config::get('hostname') ) {

						$urlBase = 'http://' . $cdnHostname . '/';

						$clientServerSourceUrls[] = $urlBase . VideoPath::posterUriPath($srcFilename, $posterFrameIndex);

					}

				}

			}

			$missingFileDownloader->addFileToDownload(
				new MissingFile(
					VideoPath::posterLocalPath($srcFilename, $posterFrameIndex),
					false,
					VideoPath::getPosterCloudPath($srcFilename, $posterFrameIndex),
					$clientServerSourceUrls
				)
			);

		}

	}

	public static function deleteVideoPoster($srcFilename, $posterFrameIndex) {

		if( !$srcFilename ) throw new Exception("Missing srcFilename");
		$posterFrameIndex = (int)$posterFrameIndex;

		$path = VideoPath::posterLocalPath($srcFilename, $posterFrameIndex);
		if( file_exists($path) ) unlink($path);

	}

	public static function deleteVideoVersion($versionFilename, $versionType) {

		if( !$versionFilename ) throw new Exception("Missing versionFilename");
		if( !$versionType ) throw new Exception("Missing versionType");

		$path = VideoPath::versionLocalPath($versionFilename, $versionType);
		CDNTools::rrmdir($path);

	}

}

class CDNTools {

	public static function getPortSpeedBits() {

		$portSpeedTxt = strtolower(Config::get('port_speed', true));

		//				 1		 2		  3
		$pattern = '/^\s*(\d+)\s*([KMGT])?(bit|bps)?\s*$/i';

		if( !preg_match($pattern, $portSpeedTxt, $matches) ) throw new Exception("Error reading port speed from config");

		$unitConversion = [
			'K' => 1000,
			'M' => 1000000,
			'G' => 1000000000,
			'T' => 1000000000000
		];

		$bits = (int)$matches[1];

		if( !$matches[2] && !$matches[3] ) throw new Exception("Error reading port speed from config");

		if( $matches[2] ) {

			$bits *= $unitConversion[strtoupper($matches[2])];

		}

		return $bits;

	}

	public static function getMonthlyBandwidthUsedBytes() {

		$db = db();

		$sql = "SELECT bytes_out
			FROM bandwidth_logs
			WHERE month = LAST_DAY(NOW() - INTERVAL 1 MONTH) + INTERVAL 1 DAY";

		if( !$result = $db->sql_query($sql) ) {

			throw new QueryException("Error selecting", $sql);

		}

		return (int)$db->sql_fetchrow($result)['bytes_out'];

	}

	public static function getMonthlyBandwidthUsedPct() {

		$monthlyBandwidthAlloc = Config::get('monthly_bandwidth_alloc', true);
		$monthlyBandwidthAllocBytes = (int)str_replace('B', '', ByteUnits\parse($monthlyBandwidthAlloc)->format('B'));

		return self::getMonthlyBandwidthUsedBytes() / $monthlyBandwidthAllocBytes;

	}

	public static function getPctMonthPassed() {

		$firstOfTheMonth = (new DateTime('today'))->modify('first day of this month');
		$firstOfNextMonth = (new DateTime('today'))->modify('first day of next month');
		$fotmTs = $firstOfTheMonth->getTimestamp();
		$pctMonthPassed = (time() - $fotmTs) / ($firstOfNextMonth->getTimestamp() - $fotmTs);

		return $pctMonthPassed;

	}

	public static function getProjectedMonthlyBandwidthUsedPct() {

		return self::getMonthlyBandwidthUsedPct() / self::getPctMonthPassed();

	}

	/**
	 * @param string $myDate
	 * @return boolean|DateTime
	 */
	public static function dateTimeFromMysqlDate( $myDate ) {
		
		if( !$myDate ) return false;
		
		$dateTime = DateTime::createFromFormat('Y-m-d', $myDate);
		$dateTime->modify('today'); // sets hour/min/sec to 0
		
		return $dateTime;

	}

	/**
	 * @param string $myDateTime
	 * @return boolean|DateTime
	 */
	public static function dateTimeFromMysqlDateTime( $myDateTime ) {
		
		if( !$myDateTime ) return false;
		
		return DateTime::createFromFormat('Y-m-d H:i:s', $myDateTime);

	}

	public static function intArray(array $numbers) {

		$intified = [];

		foreach( $numbers as $number ) {

			$intified[] = (int)$number;

		}

		return $intified;

	}

	protected static function filenameSafeB64Encode($input) {
		return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
	}
	
	// Generate a random string
	public static function getRandomBase64($num_bytes = 10) {

		$unpadChars = '[\-_]+';
		
		return preg_replace(
			"/(^$unpadChars)|($unpadChars\$)/",
			'',
			self::filenameSafeB64Encode(openssl_random_pseudo_bytes($num_bytes))
		);
		
	}

	public static function objectToArrayRecursive($obj) {

		if( is_object($obj) || is_array($obj) ) {

			$ret = (array)$obj;

			foreach( $ret as &$item ) {

				$item = self::objectToArrayRecursive($item);

			}

			return $ret;

		} else {

			return $obj;

		}

	}

	public static function getEncodingSettings(
		FFProbeResult $probeResult, $fileSizeBytes, $maxSizeBytes, $targetWidth, $targetHeight, $targetBitRate, $hlsByteSizeThreshold,
		&$constrainWidth, &$constrainHeight, &$passThroughVideo, &$saveAsHls
	) {

		/** @var FFProbeResult_VideoStream */
		if( !$videoStream = $probeResult->videoStreams[0] ) {

			AjaxResponse::returnError("Invalid video file.");

		}

		// Video encoding settings
		$targetSizeBytes = ceil($targetBitRate * $probeResult->duration / 8);
		$passThroughVideo = $fileSizeBytes <= $maxSizeBytes && $videoStream->codecName === 'h264';

		// Determine constraining width/height
		$constrainWidth = $targetWidth ?: null;
		$constrainHeight = $targetHeight ?: null;
		
		if( $passThroughVideo && $fileSizeBytes < $hlsByteSizeThreshold ) {

			// Don't need to save as HLS if we can passthrough and we're under the HLS byte size threshold
			$saveAsHls = false;

		} else {

			$passThroughVideo = false;

			$saveAsHls = $targetSizeBytes >= $hlsByteSizeThreshold;

		}

	}

	/**
	 * Recursively delete a directory and all of it's contents - e.g.the equivalent of `rm -r` on the command-line.
	 * Consistent with `rmdir()` and `unlink()`, an E_WARNING level error will be generated on failure.
	 *
	 * @param string $source absolute path to directory or file to delete.
	 * @param bool 	 $removeOnlyChildren set to true will only remove content inside directory.
	 *
	 * @return bool true on success; false on failure
	 */
	/**
	 * Removes a directory tree. A symlink inside it is removed as a link, never followed: these trees sit in
	 * world-writable directories (transcoding/, www/v), where a planted link could otherwise point a delete at any file
	 * the caller may write.
	 */
	public static function rrmdir($source, $removeOnlyChildren = false) {

		if( empty($source) ) return false;

		$source = rtrim($source, '/'); // "link/" would make is_link() look through the link

		if( is_link($source) ) return unlink($source);

		if( file_exists($source) === false ) return false;

		if( is_file($source) ) return unlink($source);

		/** @var SplFileInfo[] $files */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach( $files as $file ) {

			$path = $file->getPathname(); // not getRealPath(): that is the link's target

			if( $file->isLink() ) {

				if( unlink($path) === false ) return false;

			} else if( $file->isDir() ) {

				if( rmdir($path) === false ) return false; // CHILD_FIRST: already emptied

			} else {

				if( unlink($path) === false ) return false;

			}

		}

		if( $removeOnlyChildren === false ) {

			return rmdir($source);
			
		}

		return true;

	}

	public static function getStreamPath( $fp ) {

		return stream_get_meta_data($fp)['uri'];

	}

	/** Returns temp file handle */
	public static function downloadTemporaryFile($url) {
	
		// Create temp file to write to
		$fp_tmp = tmpfile();

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FILE, $fp_tmp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HEADER, false);

		$result = curl_exec($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if( $result && $httpcode == '200' ) {

			return $fp_tmp;

		}

	}
	
}

class CpuPercentCalculator {

	protected $statData1;
	protected $statData2;

	protected function getServerLoadLinuxData() {

		$cpuVals = null;

		if( $handle = fopen('/proc/stat', 'r') ) {

			while( ($line = fgets($handle)) !== false ) {

				// process the line read.
				if( ($trimLine = preg_replace('/^cpu\s+/i', '', $line, -1, $count)) && $count ) {

					// Total CPU, i.e.
					// cpu  1310702 610184 429957 435005796 24705 0 119391 0 0 0
					$cpuVals = preg_split('/\s+/', $trimLine, 5);
					array_pop($cpuVals);

					break;

				}

			}
			fclose($handle);

		}

		return $cpuVals;

	}

	// Returns server load in percent (just number, without percent sign)
	public function getCpuPercent($sleep = 1, $reuse = false) {

		if( is_readable('/proc/stat') ) {

			// Collect 2 samples - each with 1 second period
			// See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
			$statData1 = $this->statData1 ?: ($this->statData1 = $this->getServerLoadLinuxData());

			sleep($sleep);

			$statData2 = $this->statData2 = $this->getServerLoadLinuxData();

			if( $statData1 && $statData2 ) {

				// Get difference
				$diff0 = $statData2[0] - $statData1[0];
				$diff1 = $statData2[1] - $statData1[1];
				$diff2 = $statData2[2] - $statData1[2];
				$diff3 = $statData2[3] - $statData1[3];

				// Sum up the 4 values for User, Nice, System and Idle and calculate
				// the percentage of idle time (which is part of the 4 values!)
				$cpuTime = $diff0 + $diff1 + $diff2 + $diff3;

				// Invert percentage to get CPU time, not idle time
				$pctCpu = 1 - ($diff3 / $cpuTime);

				if( $reuse ) {
					
					// Move $statData2 => $statData1
					$this->statData1 = $statData2;

				} else {

					unset($this->statData1);

				}

				return $pctCpu;

			}

		}

	}

}

class FFProbe {

	public static function dockerProxyProbe($videoFilePath, &$execResult = null, &$execOutput = null, &$ffprobeResultRaw = null) {

		// Each argument escaped once, nothing applied to the assembled command: escapeshellcmd on top of
		// escapeshellarg backslashes $ and \ inside the quoted values and mangles a path instead of protecting it
		$dir = escapeshellarg(dirname($videoFilePath));
		$filename = escapeshellarg(basename($videoFilePath));
		$cmd = "sudo /home/dtcdn/scripts/docker-ffprobe.sh -d $dir -f $filename";

		exec($cmd, $execOutput, $execResult);

		if( $execResult === 0 ) {

			if( $ffprobeResultRaw = json_decode(implode(PHP_EOL, $execOutput), true) ) {

				return new FFProbeResult($ffprobeResultRaw);

			}

		}

		return false;

	}

}

class FFProbeResult_Stream {

	public $codecType;
	public $codecName;
	public $bitRate;
	public $duration;

	protected function __construct($obj) {

		$this->codecType = $obj['codec_type'];
		$this->codecName = $obj['codec_name'];

		$this->bitRate = (int)$obj['bit_rate'];
		$this->duration = (float)$obj['duration'];
		
	}

	public static function createFromJson($json) {

		if( is_string($json) ) $json = json_decode($json, true);
		if( is_object($json) ) $json = CDNTools::objectToArrayRecursive($json);
		if( !is_array($json) ) throw new Exception("Error creating FFProbeResult_Stream from \$json");

		$codecType = $json['codec_type'];

		switch( $codecType ) {

			// Only return video and audio streams
			//  (not data streams, for example)
			case 'video': return new FFProbeResult_VideoStream($json);
			case 'audio': return new FFProbeResult_AudioStream($json);

		}

	}

}

class FFProbeResult_VideoStream extends FFProbeResult_Stream {

	public $width;
	public $height;
	public $sampleAspectRatioString;
	public $sampleAspectRatioFloat;
	public $displayAspectRatioString;
	public $displayAspectRatioFloat;

	protected function __construct($obj) {

		parent::__construct($obj);

		$this->width = $w = (int)$obj['width'];
		$this->height = $h = (int)$obj['height'];

		$this->sampleAspectRatioString = $sar = $obj['sample_aspect_ratio'] ?: null;
		$this->displayAspectRatioString = $dar = $obj['display_aspect_ratio'] ?: null;

		if( $sar && $dar ) {

			$sarParts = explode(':', $sar);
			$this->sampleAspectRatioFloat = $sarParts[0] / $sarParts[1];

			$darParts = explode(':', $dar);
			$this->displayAspectRatioFloat = $darParts[0] / $darParts[1];

		} else {

			$this->sampleAspectRatioFloat = $this->displayAspectRatioFloat = $w / $h;

		}
		
	}

	public function displayWidth() {

		// Rounds to the nearest even number
		return 2 * round($this->height * $this->displayAspectRatioFloat / 2);

	}

	public function displayHeight() {

		return $this->height;

	}

}

class FFProbeResult_AudioStream extends FFProbeResult_Stream {



}

class FFProbeResult {

	public $probeScore;
	public $duration;
	public $sizeBytes;
	public $bitRate;
	public $formats = [];

	/** @var FFProbeResult_VideoStream[] $videoStreams */
	public $videoStreams = [];
	/** @var FFProbeResult_AudioStream[] $audioStreams */
	public $audioStreams = [];

	public function __construct($json) {

		if( is_string($json) ) $json = json_decode($json, true);
		if( is_object($json) ) $json = CDNTools::objectToArrayRecursive($json);
		if( !is_array($json) ) throw new Exception("Error constructing FFProbeResult from \$json: " . print_r($json, 1));
		if( !$format = $json['format'] ) throw new Exception("Error reading format");
		if( !$streams = $json['streams'] ) throw new Exception("Error reading streams");

		$this->probeScore = (int)$format['probe_score'];
		$this->duration = (float)$format['duration'];
		$this->sizeBytes = (int)$format['size'];
		$this->bitRate = (int)$format['bit_rate'];
		$this->formats = explode(',', $format['format_name']);

		foreach( $streams as $streamObj ) {

			$stream = FFProbeResult_Stream::createFromJson($streamObj);

			if( $stream instanceof FFProbeResult_VideoStream ) {

				$this->videoStreams[] = $stream;

			} else if( $stream instanceof FFProbeResult_AudioStream ) {

				$this->audioStreams[] = $stream;

			}

		}
		
	}

	public function hasAudio() {

		return count($this->audioStreams) > 0;

	}

}

class TranscodingJobSettings implements JsonSerializable {

	public $bitRate;
	public $constrainWidth;
	public $constrainHeight;
	public $passThroughVideo;
	public $saveAsHls;
	public $hlsSegmentTime;
	public $mute;

	public $savePoster;
	public $posterFrameIndex;
	public $posterJpegQuality;
	public $posterConstrainWidth;
	public $posterConstrainHeight;

	public function __construct(
		$bitRate = null,
		$constrainWidth = null,
		$constrainHeight = null,
		$passThroughVideo = null,
		$saveAsHls = null,
		$hlsSegmentTime = null,
		$mute = false,
		$savePoster = null,
		$posterFrameIndex = 0,
		$posterJpegQuality = 2,
		$posterConstrainWidth = null,
		$posterConstrainHeight = null
	) {

		$this->bitRate = (int)$bitRate;
		$this->constrainWidth = (int)$constrainWidth ?: null;
		$this->constrainHeight = (int)$constrainHeight ?: null;
		$this->passThroughVideo = $passThroughVideo;
		$this->saveAsHls = $saveAsHls;
		$this->hlsSegmentTime = $hlsSegmentTime;
		$this->mute = $mute;

		$this->savePoster = $savePoster;
		$this->posterFrameIndex = $posterFrameIndex;
		$this->posterJpegQuality = $posterJpegQuality;
		$this->posterConstrainWidth = $posterConstrainWidth;
		$this->posterConstrainHeight = $posterConstrainHeight;
		
	}

	public static function fromJson($json) {

		if( !$json ) return null;
		if( !is_string($json) ) throw new Exception("Invalid json");
		if( !$obj = json_decode($json, true) ) throw new Exception("Error parsing json");

		return self::fromObject($obj);

	}

	public static function fromObject($obj) {

		if( !is_array($obj) && !is_object($obj) ) {

			throw new Exception("Invalid object");

		}

		$jobSettings = new self;

		foreach( $obj as $key => $val ) {

			if( property_exists($jobSettings, $key) ) $jobSettings->$key = $val; 

		}

		if( !$jobSettings->bitRate ) throw new Exception("Bit rate required");

		return $jobSettings;

	}

	public function jsonSerialize() {
		
		return array_filter(unscopedObjVars($this), function($val) {

			return !is_null($val);

		});

	}

}

class VideoPath {

	public static function localWwwPath() {

		global $root_path;

		return $root_path . CDNClient::DIR_WWW;

	}

	public static function getDirPrefix($filename) {

		$clean = array_values(array_filter(str_split($filename), function($char) {

			return $char != '-';

		}));

		return $clean[0] . '/' . $clean[1] . '/' . $clean[2] . '/' . $clean[3] . '/';

	}

	public static function videoUriBaseFolder($filename) {

		return CDNClient::DIR_VIDEO . self::getDirPrefix($filename);

	}

	protected static function hlsRelativeDir($versionFilename) {

		return $versionFilename . '/';

	}

	public static function hlsVideoFilesUriFolder($versionFilename) {

		return self::videoUriBaseFolder($versionFilename) . self::hlsRelativeDir($versionFilename);

	}

	public static function hlsVideoFilesLocalFolder($versionFilename) {

		return self::localWwwPath() . self::hlsVideoFilesUriFolder($versionFilename);

	}

	public static function hlsIndexUriPath($versionFilename) {

		return self::hlsVideoFilesUriFolder($versionFilename) . 'index.m3u8';

	}

	public static function hlsIndexLocalPath($versionFilename) {

		return self::localWwwPath() . self::hlsIndexUriPath($versionFilename);

	}

	public static function hlsZipUriPath($versionFilename) {

		return self::hlsVideoFilesUriFolder($versionFilename) . $versionFilename . '.zip';

	}

	public static function hlsZipLocalPath($versionFilename) {

		return self::localWwwPath() . self::hlsZipUriPath($versionFilename);

	}

	public static function mp4UriPath($versionFilename) {

		return self::videoUriBaseFolder($versionFilename) . $versionFilename . '.mp4';

	}

	public static function mp4LocalPath($versionFilename) {

		return self::localWwwPath() . self::mp4UriPath($versionFilename);

	}

	public static function posterUriPath($srcFilename, $posterFrameIndex) {

		return self::videoUriBaseFolder($srcFilename) . $srcFilename . '_poster_' . $posterFrameIndex . '.jpg';

	}

	public static function posterLocalPath($srcFilename, $posterFrameIndex) {

		return self::localWwwPath() . self::posterUriPath($srcFilename, $posterFrameIndex);

	}

	public static function versionLocalPath($versionFilename, $versionType) {

		switch( $versionType ) {

			case 'mp4': return self::mp4LocalPath($versionFilename);
			case 'hls': return self::hlsVideoFilesLocalFolder($versionFilename);

			default:

				throw new Exception("Unknown versionType: " . $versionType);

		}

	}

	public static function getSrcCloudPath($srcFilename, $srcExtension) {

		return 'video_src/' . self::getDirPrefix($srcFilename) . $srcFilename . ($srcExtension ? '.' . $srcExtension : '');

	}

	public static function getVersionCloudPath($versionFilename, $versionType) {

		return 'video_versions/' . self::getDirPrefix($versionFilename) . $versionFilename . ($versionType === 'hls' ? '.zip' : '.mp4');

	}

	public static function getPosterCloudPath($srcFilename, $posterFrameIndex) {

		return 'video_src/' . self::getDirPrefix($srcFilename) . $srcFilename . '_poster_' . $posterFrameIndex . '.jpg';

	}

}

class TranscodingJob {

	/**
	 * An active job older than this is given up on by the minute sweep: a container still running is killed, one with
	 * no container or an unreadable state is marked failed. Far above any real transcode, and the hub has forgotten a
	 * source with no version well before this anyway. (A finish that keeps failing is given up on earlier, at
	 * TRANSCODE_FINISH_RETRY_MAX_MINUTES.)
	 */
	const TRANSCODE_MAX_ACTIVE_MINUTES = 120;
	/** upload-progress reports an error for a job this old that hasn't produced its version, rather than a bar that never ends */
	const TRANSCODE_STALE_MINUTES = 15;
	/**
	 * A finish that keeps failing (hub unreachable, probe error) is retried by the sweep until the job is this old, counted
	 * from its creation. Inside the hub's one-hour rule for a source with no version: past that the hub has deleted the
	 * source and refuses the version anyway. Counting from creation means a job that waited long for a slot gets fewer
	 * retries, and a job for a source that already has a post (no orphan rule) is given up on just the same; accepted,
	 * since the published files are kept and the hub may still hold the version.
	 */
	const TRANSCODE_FINISH_RETRY_MAX_MINUTES = 50;
	/** A claim (transcode_started) with no container recorded yet is startTranscode() mid-run for this long; older ones are released */
	const TRANSCODE_CLAIM_GRACE_MINUTES = 5;
	/** A cloud upload still failing or stalling this long after its job was created is abandoned rather than retried again */
	const CLOUD_UPLOAD_MAX_AGE_HOURS = 24;
	/** A cloud upload marked started this long ago and never finished is re-queued by the minute sweep */
	const CLOUD_UPLOAD_STALE_MINUTES = 120;
	/** A cloud upload that failed is tried again after this long, not on the queue's next one-second loop */
	const CLOUD_UPLOAD_RETRY_MINUTES = 10;

	/** Row columns plus the ages the sweep and upload-progress decide on, computed by MySQL so PHP's time zone doesn't matter */
	const SELECT_WITH_AGES = "*,
			TIMESTAMPDIFF(MINUTE, job_started, NOW()) AS age_minutes,
			TIMESTAMPDIFF(MINUTE, transcode_started, NOW()) AS active_minutes";

	public $id;
	public $srcFilename;
	public $srcIsNew;
	public $srcExtension;
	public $srcSizeBytes;
	public $srcDuration;
	public $versionFilename;
	public $versionWidth;
	public $versionHeight;
	public $jobSettings;
	public $jobStarted;
	public $jobFinished;
	public $progressToken;
	public $dockerContainerId;
	public $cloudUploadStarted;
	public $transcodeStarted;
	public $transcodeFinished;

	public $data;

	public function __construct($row) {

		$this->id = (int)$row['id'];
		$this->srcFilename = $row['src_filename'];
		$this->srcIsNew = (bool)$row['src_is_new'];
		$this->srcExtension = $row['src_extension'] ?: null;
		$this->srcSizeBytes = (int)$row['src_size_bytes'];
		$this->srcDuration = (float)$row['src_duration'];
		$this->versionFilename = $row['version_filename'];
		$this->versionWidth = ($row['version_width'] ? (int)$row['version_width'] : null);
		$this->versionHeight = ($row['version_height'] ? (int)$row['version_height'] : null);
		$this->jobSettings = TranscodingJobSettings::fromJson($row['job_settings']);
		$this->jobStarted = $row['job_started'] ? CDNTools::dateTimeFromMysqlDateTime($row['job_started']) : null;
		$this->jobFinished = $row['job_finished'] ? CDNTools::dateTimeFromMysqlDateTime($row['job_finished']) : null;
		$this->progressToken = $row['progress_token'];
		$this->dockerContainerId = $row['docker_container_id'] ?: null;
		$this->cloudUploadStarted = $row['cloud_upload_started'] ? CDNTools::dateTimeFromMysqlDateTime($row['cloud_upload_started']) : null;
		$this->transcodeStarted = $row['transcode_started'] ? CDNTools::dateTimeFromMysqlDateTime($row['transcode_started']) : null;
		$this->transcodeFinished = $row['transcode_finished'] ? CDNTools::dateTimeFromMysqlDateTime($row['transcode_finished']) : null;

		$this->data = $row;
		
	}

	public function inProgressDir() {

		global $root_path;

		return $root_path . CDNClient::DIR_TRANSCODE_IN_PROGRESS . $this->versionFilename . '/';

	}

	public function deleteInProgressFolder() {

		return CDNTools::rrmdir( $this->inProgressDir() );

	}

	public function inProgressPath() {

		return $this->inProgressDir() . $this->srcFilename;

	}

	public function sourceVideoExistsOnDisk() {

		return file_exists($this->inProgressPath());

	}

	public static function getById($id) {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE id = " . (int)$id;

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		if( $row = $db->sql_fetchrow($result) ) {

			return new self($row);

		} else {

			return false;

		}

	}

	/** @return TranscodingJob[] */
	public static function getAllBySrcFilename($srcFilename) {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE src_filename = '" . original_to_query($srcFilename) . "'";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	/** @return TranscodingJob[] */
	public static function getAllByProgressTokens(array $progressTokens) {

		if( !$progressTokens ) return [];

		$db = db();

		$in = [];
		foreach( $progressTokens as $pt ) {

			$in[] = "'" . original_to_query($pt) . "'";

		}

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE progress_token IN (" . implode(",", $in) . ")";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$job = new self($row);

			$jobs[$job->progressToken] = $job;

		}

		return $jobs;

	}

	public static function getByProgressToken($progressToken) {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE progress_token = '" . original_to_query($progressToken) . "'";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		if( $row = $db->sql_fetchrow($result) ) {

			return new self($row);

		} else {

			return false;

		}

	}

	public static function getByContainerId($dockerContainerId) {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE docker_container_id = '" . original_to_query($dockerContainerId) . "'";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		if( $row = $db->sql_fetchrow($result) ) {

			return new self($row);

		} else {

			return false;

		}

	}

	public static function numActiveTranscodingJobs() {

		$db = db();

		// A fail code on an active row is a finish waiting to be retried (its container has exited), not an encode
		$sql = "SELECT COUNT(*) as count
			FROM transcoding_jobs
			WHERE transcode_is_active = 1
			AND transcode_fail_code IS NULL";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$row = $db->sql_fetchrow($result);

		return (int)$row['count'];

	}

	public static function canStartTranscodingJob() {

		return self::numActiveTranscodingJobs() < Config::get('transcode_job_limit');

	}

	public function createInProgressDir() {

		$dir = $this->inProgressDir();
		if( !is_dir($dir) ) {
	
			if( !mkdir_recursive($dir, 0777)) {
				
				throw new Exception("Could not create progress dir.");
				
			}
			
		}

	}

	public function moveUploadedFile($tmpFile) {

		if( $this->sourceVideoExistsOnDisk() ) return true;

		$this->createInProgressDir();

		return move_uploaded_file($tmpFile, $this->inProgressPath());

	}

	public function setTranscodeReady() {

		$sql = "UPDATE transcoding_jobs
			SET transcode_ready = 1,
				flag_cloud_download_src = 0
			WHERE id = " . (int)$this->id;

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

	}

	public function queueSourceDownload() {

		$sql = "UPDATE transcoding_jobs
			SET flag_cloud_download_src = 1
			WHERE id = " . (int)$this->id;

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

	}

	/** @return TranscodingJob[] */
	public static function getSourceDownloadJobs() {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE flag_cloud_download_src = 1
			AND cloud_download_src_in_progress = 0
			AND transcode_finished IS NULL";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	public static function setCloudDownloadSrcInProgress(array $jobs, $inProgress = true) {

		if( !$jobs ) return;

		$sql = "UPDATE transcoding_jobs
			SET cloud_download_src_in_progress = " . ($inProgress ? "1" : "0") . "
			WHERE id IN (" . implode(
				",",
				array_map(function($job) {

					return (int)$job->id;

				}, $jobs)
			) . ")";

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating transcoding_jobs", $sql);

	}

	/** @return TranscodingJob[] jobs waiting for a transcode slot, oldest first */
	public static function getUnstartedTranscodeJobs() {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE transcode_ready = 1
			AND transcode_started IS NULL
			AND transcode_finished IS NULL
			ORDER BY id";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	/**
	 * The job already producing this version, if there is one: unfinished, or finished with its version and not yet
	 * expired. A retried upload (the uploader gave up, the user tried again) asks for the same version filename, and a
	 * second job would share the first one's work directory and output files. A job that has waited for a slot past
	 * TRANSCODE_STALE_MINUTES is abandoned here instead of handed out, since the next progress poll would abandon it anyway
	 * and fail the retry; the caller then creates a fresh row.
	 *
	 * @return TranscodingJob|null
	 */
	public static function findReusableJob($versionFilename) {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE version_filename = '" . original_to_query($versionFilename) . "'
			AND (transcode_finished IS NULL OR transcode_fail_code IS NULL)
			ORDER BY id DESC
			LIMIT 1";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		if( !$row = $db->sql_fetchrow($result) ) return null;

		$job = new self($row);

		if( !$job->transcodeStarted && !$job->transcodeFinished && $job->ageMinutes() >= self::TRANSCODE_STALE_MINUTES ) {

			if( $job->markFailed('abandoned', null, 'stale waiting job replaced by a retried upload after ' . $job->ageMinutes() . ' min', true) ) return null;

			// A starter claimed it between the select and the abandon: it is encoding now, so reuse it (unless it was
			// finished or failed in that same instant, e.g. by the original uploader's poll)
			$fresh = self::getById($job->id);

			return ($fresh && !$fresh->hasFailed()) ? $fresh : null;

		}

		return $job;

	}

	/** @return TranscodingJob[] jobs created over $minutes ago that never became ready to transcode */
	public static function getNeverReadyJobs($minutes) {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE transcode_ready = 0
			AND transcode_finished IS NULL
			AND job_started < NOW() - INTERVAL " . (int)$minutes . " MINUTE
			ORDER BY id";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	/** @return TranscodingJob[] jobs that hold a transcode slot (started, not finished) */
	public static function getActiveTranscodeJobs() {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE transcode_is_active = 1
			ORDER BY id";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	/** @return TranscodingJob[] jobs that ended without a version (finished with a fail code) */
	public static function getFailedJobs() {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . "
			FROM transcoding_jobs
			WHERE transcode_finished IS NOT NULL
			AND transcode_fail_code IS NOT NULL
			ORDER BY id";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	/** @return TranscodingJob[] jobs with a cloud upload marked started and not finished */
	public static function getPendingCloudUploadJobs() {

		$db = db();

		$sql = "SELECT " . self::SELECT_WITH_AGES . ",
				TIMESTAMPDIFF(MINUTE, src_cloud_upload_started, NOW()) AS src_cloud_upload_minutes,
				TIMESTAMPDIFF(MINUTE, cloud_upload_started, NOW()) AS cloud_upload_minutes
			FROM transcoding_jobs
			WHERE (src_cloud_upload_started IS NOT NULL AND src_cloud_upload_finished IS NULL)
			OR (cloud_upload_started IS NOT NULL AND cloud_upload_finished IS NULL)
			ORDER BY id";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting from transcoding_jobs", $sql);

		$jobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$jobs[] = new self($row);

		}

		return $jobs;

	}

	public function startTranscode() {

		// Can we start a transcoding job now?
		if( !self::canStartTranscodingJob() ) return false;

		// Claim job
		if( !$this->setTranscodeStarted() ) return;

		// Required: the job's own directory, not a symlink planted in world-writable transcoding/ (the root script
		// chowns and mounts whatever it is handed; it checks this too)
		if( is_link(rtrim($this->inProgressDir(), '/')) || !$dir = realpath($this->inProgressDir()) ) {

			$this->unsetTranscodeStarted();
			throw new Exception("Error getting absolute path");

		}
		$dir .= '/';
		$inFile = $this->srcFilename;
		$outFile = CDNClient::DIR_TRANSCODE_OUTPUT . (
			$this->jobSettings->saveAsHls ? $this->versionFilename . '/index.m3u8' : $this->versionFilename . '.mp4'
		);
		$bitRate = $this->jobSettings->bitRate;

		// Create output dir if it doesn't exist
		$outDir = $dir . CDNClient::DIR_TRANSCODE_OUTPUT . ($this->jobSettings->saveAsHls ? $this->versionFilename . '/': '');
		if( !is_dir($outDir) ) {
	
			if( !mkdir_recursive($outDir, 0777)) {
				
				$this->unsetTranscodeStarted();
				throw new Exception("Could not create output dir.");
				
			}
			
		}

		// Escaped args
		$escapedArgs = [
			'-d ' . escapeshellarg($dir),
			'-i ' . escapeshellarg($inFile),
			'-o ' . escapeshellarg($outFile),
			'-b ' . escapeshellarg($bitRate),
		];

		// Optional args
		if( $constrainWidth = $this->jobSettings->constrainWidth ) $escapedArgs[] = '-w ' . escapeshellarg($constrainWidth);
		if( $constrainHeight = $this->jobSettings->constrainHeight ) $escapedArgs[] = '-h ' . escapeshellarg($constrainHeight);

		if( $this->jobSettings->saveAsHls ) $escapedArgs[] = "-s";
		if( $this->jobSettings->passThroughVideo ) $escapedArgs[] = "-p";
		if( $this->jobSettings->mute ) $escapedArgs[] = "-m";

		if( $this->jobSettings->savePoster ) {

			// Frame capture args
			$captureFrameIndex = $this->jobSettings->posterFrameIndex ?: 0;
			$frameOutFile = CDNClient::DIR_TRANSCODE_OUTPUT . $this->srcFilename . "_poster_$captureFrameIndex.jpg";
			$frameJpegQuality = $this->jobSettings->posterJpegQuality ?: 2;

			if( !file_exists(VideoPath::posterLocalPath($this->srcFilename, $captureFrameIndex)) ) { // Don't re-capture frame if we already have it
			
				$escapedArgs[] = '-F ' . escapeshellarg($frameOutFile);
				$escapedArgs[] = '-I ' . escapeshellarg($captureFrameIndex);
				$escapedArgs[] = '-Q ' . escapeshellarg($frameJpegQuality);

				if( $frameConstrainWidth = $this->jobSettings->posterConstrainWidth ) $escapedArgs[] = '-W ' . escapeshellarg($frameConstrainWidth);
				if( $frameConstrainHeight = $this->jobSettings->posterConstrainHeight ) $escapedArgs[] = '-H ' . escapeshellarg($frameConstrainHeight);

			}

		}

		// The values above are each escapeshellarg'd; no escapeshellcmd over the whole command (it would backslash
		// characters inside the quoted values)
		$cmd = "sudo /home/dtcdn/scripts/docker-ffmpeg.sh " . implode(" ", $escapedArgs);

		exec($cmd, $execOutput, $execResult);

		if( $execResult === 0 ) {

			if( count($execOutput) > 1 ) {

				// More than one line of output... something went wrong

			} else {

				$dockerContainerId = trim($execOutput[0]);

				$this->setDockerContainerId($dockerContainerId);

				return true;

			}

		}

		$this->unsetTranscodeStarted();

		throw new GeneralExceptionWithData("Error starting job: $cmd", [
			'cmd' => $cmd,
			'execResult' => $execResult,
			'execOutput' => $execOutput
		]);

	}

	public function isHls() {

		return (bool)$this->jobSettings->saveAsHls;

	}

	/**
	 * Finishes a job whose container has exited: by the die handler (scripts/docker-container-die.php) as soon as it
	 * happens, and by the minute sweep (sweepActiveJobs()) for what the handler missed and for a finish that failed
	 * part-way (hub unreachable, probe error), which is retried here each minute. Running it again is safe: the move finds
	 * nothing left to move, the HLS zip is rebuilt, and the hub returns the version it already has rather than creating a
	 * second one. A MySQL lock stops the two callers finishing the same job at once.
	 *
	 * @return bool|null true: the version is registered with the hub and the job is finished. false: the job failed and is
	 *   marked so (its slot is free), or a step after ffmpeg failed and is recorded in transcode_fail_* for the sweep to
	 *   retry (the job stays active until TRANSCODE_FINISH_RETRY_MAX_MINUTES past its creation). null: already finished,
	 *   or another process has it.
	 */
	public function finishTranscode() {

		if( $this->transcodeFinished ) return null;

		if( !$this->lock() ) return null;

		try {

			// The row this object was built from may predate another process's finish (the sweep reads its rows once, then
			// works through them while the die handler runs); re-read it now that the lock is held
			if( !$fresh = self::getById($this->id) ) return null;

			if( $fresh->transcodeFinished ) return null;

			$this->dockerContainerId = $fresh->dockerContainerId;
			$this->data = $fresh->data + $this->data;

			return $this->finishTranscodeLocked();

		} finally {

			$this->unlock();

		}

	}

	protected function finishTranscodeLocked() {

		$db = db();

		$pctComplete = $this->getPercentComplete($transcodeIsFinished, $execResult, $dockerOutput);

		// The container's exit status, when it can be read (null without the sudo rule for docker-container.sh)
		$state = $this->containerState();

		if( $state && self::containerIsLive($state) ) return false; // still encoding; the sweep asked too early

		$exitedCleanly = $state && $state['status'] === 'exited' && $state['exitCode'] === 0;
		$exitedBadly = $state && $state['status'] === 'exited' && $state['exitCode'] !== 0;

		if( !$transcodeIsFinished || $exitedBadly ) {

			// No progress=end in the container's log, or a non-zero exit. Until 2026-10 this only recorded the output, so
			// the job stayed active, held one of the transcode_job_limit slots for good and the uploader polled it at 0%.
			// The exit status decides: a clean exit without the line in the last 70 log lines is still a finished encode
			// (the probe in publishOutput() checks the output), a log that couldn't be read is retried, anything else
			// failed. ffmpeg writes progress=end and still exits non-zero (69) when most of the input failed to decode
			// (-max_error_rate), and that output isn't worth publishing.
			if( !$exitedCleanly ) {

				// Only when docker logs itself failed ($execResult) on a container that still exists is this a read error
				// to retry; a readable log with no progress line is ffmpeg failing before its first progress block
				if( $execResult !== 0 && (!$state || $state['status'] !== 'missing') ) {

					$this->recordFinishError(new GeneralException('Could not read the container log: ' . $dockerOutput));

					return false;

				}

				// getPercentComplete() reads only the container's stdout (the progress lines); ffmpeg's own error is on stderr
				exec("sudo /home/dtcdn/scripts/docker-logs.sh -c " . escapeshellarg($this->dockerContainerId) . " -n 30 2>&1", $logTail);

				$this->markFailed('ffmpeg_failed', [ 'exitCode' => $state['exitCode'] ?? null, 'dockerLogsResult' => $execResult, 'dockerOutput' => $dockerOutput, 'logTail' => $logTail, 'containerState' => $state ], 'ffmpeg exited without finishing');

				self::startWaitingJobs();

				return false;

			}

		}

		try {

			$hubResponseDataArray = $this->publishOutput();

		} catch( Throwable $e ) {

			if( self::isPermanentHubRefusal($e) ) {

				// The hub has deleted the source (its orphan rule), so this version can never be registered
				$this->failPublished('source_gone', $e->getMessage(), 'hub no longer has the source: ' . $e->getMessage());

				self::startWaitingJobs();

				return false;

			}

			// Part of the output may already be in www/; the job stays active and the sweep retries from here each minute
			$this->recordFinishError($e);

			return false;

		}

		$sql = "UPDATE transcoding_jobs
			SET transcode_finished = NOW(),
				transcode_fail_code = NULL,
				transcode_fail_output = NULL,
				hub_return_meta = " . ($hubResponseDataArray['returnMeta'] ? "'" . original_to_query(json_encode($hubResponseDataArray['returnMeta'])) . "'" : "NULL") . "
			WHERE id=" . (int)$this->id;

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		$this->transcodeFinished = new DateTime();
		$this->data['transcode_fail_code'] = null;
		$this->data['transcode_fail_output'] = null;

		// Start any pending transcoding jobs if possible
		self::startWaitingJobs();

		return true;

	}

	/**
	 * Moves the container's output into www/, zips an HLS directory for the cloud, probes the result and registers the
	 * version with the hub.
	 *
	 * @return array the hub's response data
	 */
	protected function publishOutput() {

		$transcodeOutDir = $this->inProgressDir() . CDNClient::DIR_TRANSCODE_OUTPUT;
		$wwwDir = VideoPath::localWwwPath() . VideoPath::videoUriBaseFolder($this->srcFilename);

		// Move contents of transcode out dir to www dir. The work directory is world-writable, so only a real out/
		// directory inside the real work directory is read, and a symlinked entry is skipped rather than moved into
		// the served tree (a link's target would be renamed there).
		$basePath = realpath($transcodeOutDir);
		$workPath = realpath($this->inProgressDir());

		if( !$basePath || !$workPath || is_link(rtrim($transcodeOutDir, '/')) || $basePath !== $workPath . '/' . rtrim(CDNClient::DIR_TRANSCODE_OUTPUT, '/') ) {

			throw new GeneralExceptionWithData("Output directory is not the job's own", [ 'out' => $transcodeOutDir, 'resolved' => $basePath ]);

		}

		/** @var SplFileInfo[] $files */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($basePath),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach( $files as $file ) {

			// No links, and no dotfiles: a planted .htaccess would land beside the media and could re-enable PHP there
			if( $file->isLink() || $file->getFilename()[0] === '.' ) continue;

			$filePath = $file->getRealPath();
			$relativePath = substr($filePath, strlen($basePath) + 1);

			if( $file->isDir() ) {

				$dirName = $wwwDir . $relativePath;

			} else {

				$dirName = $wwwDir . dirname($relativePath);

			}

			// Create missing path directories
			if( !file_exists($dirName) && !mkdir_recursive($dirName, 0777)) {
				
				throw new Exception("Could not create dir: $dirName");
				
			}

			if( !$file->isDir() ) {

				// Move files
				rename($filePath, $wwwDir . $relativePath);

				//echo "rename $filePath ${wwwDir}${relativePath}\n";

			}

		}

		$totalSizeBytes = 0;

		$probeVideoFile = null;

		if( $this->isHls() ) {

			// Prepare zipped files for cloud upload

			// Get real path for our folder
			$basePath = realpath(VideoPath::hlsVideoFilesLocalFolder($this->versionFilename));

			// The zip is written inside the folder it archives. A previous attempt's zip (a finish being retried, or a
			// job reusing a finish_timeout row's directory) must not go into this one, nor count towards the size
			// registered with the hub; it's removed first and skipped below in case something else wrote one meanwhile
			$zipPath = VideoPath::hlsZipLocalPath($this->versionFilename);
			@unlink($zipPath);

			// Initialize archive object
			$zip = new ZipArchive();
			$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

			// Create recursive directory iterator
			/** @var SplFileInfo[] $files */
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($basePath),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			$i = 0;
			foreach( $files as $file ) {

				// Skip directories (they would be added automatically) and the zip itself
				if( !$file->isDir() && $file->getFilename() !== basename($zipPath) ) {

					// Count size in bytes
					$totalSizeBytes += $file->getSize();

					// Get real and relative path for current file
					$filePath = $file->getRealPath();
					$relativePath = substr($filePath, strlen($basePath) + 1);

					// Set video file to probe
					if( !$probeVideoFile && $file->getFilename() === 'index0.ts' ) $probeVideoFile = $filePath;

					// Add current file to archive
					$zip->addFile($filePath, $relativePath);
					$zip->setCompressionIndex($i++, ZipArchive::CM_STORE);

				}

			}

			// Zip archive will be created only after closing object
			$zip->close();

		} else {

			// mp4: Just get size
			$filePath = $probeVideoFile = VideoPath::mp4LocalPath($this->versionFilename);

			if( !file_exists($filePath) ) {

				throw new GeneralException("Could not find video file $filePath");

			}

			$totalSizeBytes = filesize($filePath);

		}

		if( !$probeVideoFile ) {

			throw new GeneralExceptionWithData("Did not find a video file to probe", [
				'basePath' => $basePath
			]);

		}

		if( !$probeResult = FFProbe::dockerProxyProbe($probeVideoFile, $execResult, $execOutput, $ffprobeResultRaw) ) {

			throw new GeneralExceptionWithData("Error getting probe result", [
				'execResult' => $execResult,
				'execOutput' => $execOutput,
				'ffprobeResultRaw' => $ffprobeResultRaw
			]);

		}

		$hasAudio = $probeResult->hasAudio();

		if( !CDNClient::createVideoVersion($this->srcFilename, $this->versionWidth, $this->versionHeight, $hasAudio, $this->isHls() ? 'hls' : 'mp4', $totalSizeBytes, $this->versionFilename, $hubResponseDataArray) ) {

			throw new Exception("Error updating hub server.");

		}

		return $hubResponseDataArray;

	}

	/**
	 * Ends the job as failed. transcode_finished is set so the job stops holding a transcode slot (transcode_is_active is
	 * started-and-not-finished), and transcode_fail_code marks it as having produced no version: upload-progress reports
	 * an error, the cloud upload queue skips it, and deleteExpiredJobs() removes it an hour later.
	 */
	public function markFailed($failCode, $failOutput, $message, $onlyIfUnstarted = false) {

		$db = db();

		// Only an unfinished row: another process may have finished it since this one was read. A pending source
		// download is dropped with it, so it can't keep writing into a directory a replacement job uses.
		$sql = "UPDATE transcoding_jobs
			SET transcode_finished = NOW(),
				flag_cloud_download_src = 0,
				transcode_fail_code = '" . original_to_query(self::jsonColumn($failCode)) . "',
				transcode_fail_output = '" . original_to_query(self::jsonColumn($failOutput)) . "'
			WHERE id = " . (int)$this->id . "
			AND transcode_finished IS NULL" . ($onlyIfUnstarted ? "
			AND transcode_started IS NULL" : "");

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		if( !$db->sql_affectedrows() ) return false;

		$this->transcodeFinished = new DateTime();
		$this->data['transcode_fail_code'] = self::jsonColumn($failCode);
		$this->data['transcode_fail_output'] = self::jsonColumn($failOutput);

		Logger::logEvent('transcode_failed', [
			'message' => $message,
			'data' => [
				'jobId' => $this->id,
				'srcFilename' => $this->srcFilename,
				'versionFilename' => $this->versionFilename,
				'failCode' => $failCode,
				'failOutput' => $failOutput,
			]
		]);

		return true;

	}

	/** Records why a finish attempt failed after ffmpeg had succeeded, without ending the job: the sweep retries it */
	protected function recordFinishError(Throwable $e) {

		$db = db();

		$sql = "UPDATE transcoding_jobs
			SET transcode_fail_code = '" . original_to_query(self::jsonColumn('finish_failed')) . "',
				transcode_fail_output = '" . original_to_query(self::jsonColumn($e->getMessage())) . "'
			WHERE id = " . (int)$this->id . "
			AND transcode_finished IS NULL";

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		if( !$db->sql_affectedrows() ) return;

		Logger::logEvent('transcode_finish_failed', [
			'message' => 'Will retry from the minute sweep: ' . $e->getMessage(),
			'exception' => $e,
			'data' => [
				'jobId' => $this->id,
				'versionFilename' => $this->versionFilename,
				'activeMinutes' => $this->data['active_minutes'] ?? null,
			]
		]);

	}

	/** The hub's answer when the source of this version no longer exists there; retrying can't change it */
	protected static function isPermanentHubRefusal(Throwable $e) {

		return strpos($e->getMessage(), 'Could not find source video') !== false;

	}

	/**
	 * Fails a job whose source the hub no longer has, and removes what publishOutput() put into www/ for it: the version
	 * (and an HLS zip beside it) and the source's poster, which nothing will ever point at. The work directory goes with
	 * deleteExpiredJobs() as for any failed job.
	 */
	public function failPublished($failCode, $failOutput, $message) {

		if( !$this->markFailed($failCode, $failOutput, $message) ) {

			// Already finished. Only one way leaves the files to this attempt: the sweep failed the row as finish_blocked
			// (this attempt held the lock past the hard limit) while publishOutput() was moving them; the hub has no source
			// for them, so they go too. Anything else (a success, finish_timeout with its files kept on purpose) is final.
			$fresh = self::getById($this->id);

			if( !$fresh || !$fresh->hasFailed() || $fresh->failCode() !== 'finish_blocked' ) return false;

		}

		try {

			CDNClient::deleteVideoVersion($this->versionFilename, $this->isHls() ? 'hls' : 'mp4');

			if( $this->isHls() ) @unlink(VideoPath::hlsZipLocalPath($this->versionFilename));

			if( $this->jobSettings->savePoster ) CDNClient::deleteVideoPoster($this->srcFilename, $this->jobSettings->posterFrameIndex ?: 0);

		} catch( Throwable $e ) {

			Logger::logEvent('transcode_published_delete_failed', [ 'message' => $e->getMessage(), 'exception' => $e, 'data' => [ 'jobId' => $this->id ] ]);

		}

		return true;

	}

	/** The transcode_fail_code as written (it is a JSON column, so the raw value is quoted): e.g. 'ffmpeg_failed', or null */
	public function failCode() {

		return $this->data['transcode_fail_code'] === null ? null : json_decode($this->data['transcode_fail_code']);

	}

	/** True when the job ended without producing a version */
	public function hasFailed() {

		return $this->transcodeFinished && $this->data['transcode_fail_code'] !== null;

	}

	/** Minutes since the job was created, when the row was selected with SELECT_WITH_AGES; null otherwise */
	public function ageMinutes() {

		return isset($this->data['age_minutes']) ? (int)$this->data['age_minutes'] : null;

	}

	/**
	 * A job old enough that upload-progress should report an error instead of a bar: still waiting for a slot, or
	 * encoded but with a finish attempt that failed (transcode_fail_code set while active). One that is encoding isn't
	 * stale however long it waited first.
	 */
	public function isStale() {

		if( $this->transcodeFinished || $this->ageMinutes() === null || $this->ageMinutes() < self::TRANSCODE_STALE_MINUTES ) return false;

		return !$this->transcodeStarted || $this->data['transcode_fail_code'] !== null;

	}

	/** A container state in which ffmpeg may still be running */
	protected static function containerIsLive(array $state) {

		return in_array($state['status'], [ 'running', 'created', 'restarting', 'paused' ], true);

	}

	/** JSON for a json column: never false, whatever bytes a tool wrote to its output */
	protected static function jsonColumn($value) {

		return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

	}

	/** A SQL expression for the lock's name: named locks are server-wide, so the database name keeps instances apart */
	protected function lockNameSql() {

		return "CONCAT(DATABASE(), '_transcoding_job_', " . (int)$this->id . ")";

	}

	/** A MySQL named lock on this job, held by the connection; false when another process has it */
	protected function lock() {

		$db = db();

		$sql = "SELECT GET_LOCK(" . $this->lockNameSql() . ", 0) AS locked";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error getting lock", $sql);

		return (int)$db->sql_fetchrow($result)['locked'] === 1;

	}

	protected function unlock() {

		db()->sql_query("SELECT RELEASE_LOCK(" . $this->lockNameSql() . ")");

	}

	/**
	 * Asks docker about the job's container.
	 *
	 * @return array|null ['status' => 'running'|'exited'|'created'|'dead'|..., 'exitCode' => int, 'finishedAt' => string];
	 *   ['status' => 'missing'] when docker no longer knows the id (pruned); null when the state couldn't be read, e.g.
	 *   the sudo rule for scripts/docker-container.sh isn't installed.
	 */
	public function containerState(&$execOutput = null) {

		if( !$this->dockerContainerId ) return null;

		// The id is escaped; nothing applied to the assembled command. -n: never prompt for a password
		$cmd = "sudo -n /home/dtcdn/scripts/docker-container.sh -a inspect -c " . escapeshellarg($this->dockerContainerId) . " 2>&1";

		// exec() appends to an array that already has lines. The sweep passes one variable for every job it inspects,
		// so without this every job after the first read the first job's state line (a job whose container had exited
		// was taken for running while another job was encoding, and kept its slot)
		$execOutput = [];
		exec($cmd, $execOutput, $execResult);

		$text = trim(implode(PHP_EOL, $execOutput ?: []));

		// Line by line: stderr is merged in, and sudo or docker may print a line of their own around the one we want
		if( $execResult === 0 && preg_match('/^(\w+) (-?\d+) (\S+)$/m', $text, $matches) ) {

			return [ 'status' => $matches[1], 'exitCode' => (int)$matches[2], 'finishedAt' => $matches[3] ];

		}

		if( stripos($text, 'No such') !== false ) return [ 'status' => 'missing' ];

		return null;

	}

	/** Kills the job's container (a transcode that has run far too long) */
	public function killContainer(&$execOutput = null) {

		if( !$this->dockerContainerId ) return false;

		$cmd = "sudo -n /home/dtcdn/scripts/docker-container.sh -a kill -c " . escapeshellarg($this->dockerContainerId) . " 2>&1";

		$execOutput = []; // exec() appends; see containerState()
		exec($cmd, $execOutput, $execResult);

		return $execResult === 0;

	}

	/**
	 * Starts jobs that are waiting for a transcode slot, oldest first, while transcode_job_limit allows. A start that
	 * throws is logged and skipped (startTranscode() has released its claim), so one bad job can't block the rest; the
	 * sweep tries it again next minute and gives up at TRANSCODE_MAX_ACTIVE_MINUTES.
	 *
	 * @return int jobs started
	 */
	public static function startWaitingJobs() {

		$started = 0;

		foreach( self::getUnstartedTranscodeJobs() as $job ) {

			try {

				$result = $job->startTranscode();

				if( $result === false ) break; // at the limit

				if( $result ) $started++;

			} catch( Exception $e ) {

				Logger::logEvent('transcode_start_failed', [
					'message' => $e->getMessage(),
					'exception' => $e,
					'data' => [ 'jobId' => $job->id, 'versionFilename' => $job->versionFilename ]
				]);

			}

		}

		return $started;

	}

	/**
	 * The minute cron's safety net for jobs nothing else will finish. Until 2026-10 a job was only ever finished by the
	 * die handler, and only finishTranscode() started waiting jobs; a handler that failed part-way (the hub unreachable
	 * for a moment) left its job active, holding a slot, and every later upload waited unstarted for good.
	 *
	 * For each active job the container is inspected: exited, finish it (the handler missed it, or an earlier finish
	 * failed and is retried); missing, mark it failed; running or claimed with no container, leave it unless it is past
	 * TRANSCODE_MAX_ACTIVE_MINUTES, then kill and fail it. Jobs waiting past the same age are failed too. Then the
	 * waiting jobs are started.
	 *
	 * @return array counts of what was done, for the cron log and scripts/jobs-status.php
	 */
	public static function sweepActiveJobs() {

		$report = [ 'finished' => 0, 'retried' => 0, 'failed' => 0, 'running' => 0, 'unknown' => 0, 'started' => 0 ];

		foreach( self::getActiveTranscodeJobs() as $job ) {

			$activeMinutes = (int)$job->data['active_minutes'];
			$expired = $activeMinutes >= self::TRANSCODE_MAX_ACTIVE_MINUTES;

			try {

				if( $job->data['transcode_fail_code'] !== null ) {

					// A recorded finish failure. Its container has exited (the code is only written after that check), so
					// retry without asking docker: the retry then doesn't depend on docker-container.sh's sudo rule, which a
					// server that pulled this code may not have installed yet. finishTranscodeLocked() re-reads the log.
					if( $job->ageMinutes() >= self::TRANSCODE_FINISH_RETRY_MAX_MINUTES ) {

						// Too old for the hub to still want the version. The published files stay: the hub may have
						// registered the version on an attempt whose response was lost, and its orphan rule removes an
						// unused one.
						// Under the job's lock: a sweep that ran long (the hub hanging for its 60 s curl timeout) may still
						// be publishing this job, and failing it under that attempt could leave its files unaccounted for
						// (a "Could not find source video" answer then finds the row failed already and deletes nothing).
						// A busy lock means that attempt decides; this is retried next minute.
						if( $job->lock() ) {

							try {

								if( $job->markFailed('finish_timeout', json_decode($job->data['transcode_fail_output'], true), 'finish still failing ' . $job->ageMinutes() . ' min after creation') ) $report['failed']++;

							} finally {

								$job->unlock();

							}

						} else if( $expired && $job->markFailed('finish_blocked', json_decode($job->data['transcode_fail_output'], true), "lock never acquired for the finish_timeout mark, active $activeMinutes min") ) {

							// A lock nothing will release (a process stuck inside finishTranscode()): the same way out as
							// the exited-container branch below, or the row would never expire
							$report['failed']++;

						}

					} else {

						$result = $job->finishTranscode();

						if( $result === true ) $report['finished']++;
						else if( $result === false ) $report['retried']++;

					}

					continue;

				}

				if( !$job->dockerContainerId ) {

					// Claimed but no container recorded. For a few seconds that is startTranscode() between its claim and
					// its docker run (another process started the job), so leave a young claim alone; an old one is a
					// crash between the two writes (startTranscode() releases the claim itself on a failed run), released
					// here so startWaitingJobs() retries it.
					if( $expired ) {

						if( $job->markFailed('no_container', null, "claimed with no container for $activeMinutes min") ) $report['failed']++;

					} else if( $activeMinutes >= self::TRANSCODE_CLAIM_GRACE_MINUTES ) {

						$job->unsetTranscodeStarted($job->data['transcode_started']);
						$report['retried']++;

					}

					continue;

				}

				$state = $job->containerState($inspectOutput);

				if( $state === null ) {

					// Docker couldn't be asked (the sudo rule not installed, docker down). Such a job must still end
					// eventually, or this is the incident again under another name.
					if( $expired ) {

						// The kill goes through the same sudo rule as the inspect, so it fails for the same reason; the job
						// is failed regardless, or it would hold its slot for good (the sudo rule missing is far likelier
						// than an encode still running after two hours). The record says whether the kill was confirmed.
						$killed = $job->killContainer($killOutput);

						if( $job->markFailed('state_unknown_timeout', [ 'inspectOutput' => $inspectOutput, 'killed' => $killed, 'killOutput' => $killOutput ], "container state unreadable, active $activeMinutes min" . ($killed ? '; container killed' : '; kill not confirmed')) ) $report['failed']++;

					} else {

						$report['unknown']++;
						$unknownStateOutput = $inspectOutput;

					}

				} else if( self::containerIsLive($state) ) {

					if( $expired ) {

						// Failed only once the container is known to be stopped (a retry would share its out/ under -y),
						// which a failed kill of a running container doesn't give; docker kill also refuses a container
						// that isn't running (created, paused), and such a one holds nothing, so that job is failed too
						$killed = $job->killContainer($killOutput);
						$stillRunning = !$killed && in_array($state['status'], [ 'running', 'restarting' ], true);

						if( $stillRunning ) {

							Logger::logEvent('transcode_kill_failed', [ 'message' => 'Could not kill the container of an expired job; left for the next sweep', 'data' => [ 'jobId' => $job->id, 'output' => $killOutput ] ]);

						} else if( $job->markFailed('timeout', [ 'containerState' => $state, 'killed' => $killed, 'killOutput' => $killOutput ], "still {$state['status']} after $activeMinutes min" . ($killed ? '; container killed' : '')) ) {

							$report['failed']++;

						}

					} else {

						$report['running']++;

					}

				} else if( $state['status'] === 'missing' ) {

					if( $job->markFailed('container_missing', null, 'container no longer exists') ) $report['failed']++;

				} else {

					// Exited with no finish attempt yet (the die handler missed it): finished however old it is; the hub's
					// refusal of a deleted source then ends it. null means another process holds the job's lock; past the
					// age limit that is a lock nothing will release (a hung handler, another MySQL session), so the job
					// is failed rather than left holding a slot.
					$result = $job->finishTranscode();

					if( $result === true ) $report['finished']++;
					else if( $result === false ) $report['retried']++;
					else if( $expired && $job->markFailed('finish_blocked', null, "lock never acquired, active $activeMinutes min") ) $report['failed']++;

				}

			} catch( Throwable $e ) {

				Logger::logEvent('transcode_sweep_error', [
					'message' => $e->getMessage(),
					'exception' => $e,
					'data' => [ 'jobId' => $job->id ]
				]);

			}

		}

		// Jobs that never got going: waiting for a slot, or never made ready (a source download that keeps failing, an
		// upload that couldn't be moved). The hub forgets a source with no version after an hour anyway.
		try {

			foreach( self::getUnstartedTranscodeJobs() as $job ) {

				if( $job->ageMinutes() >= self::TRANSCODE_MAX_ACTIVE_MINUTES ) {

					if( $job->markFailed('never_started', null, 'waited ' . $job->ageMinutes() . ' min for a transcode slot', true) ) $report['failed']++;

				}

			}

			foreach( self::getNeverReadyJobs(self::TRANSCODE_MAX_ACTIVE_MINUTES) as $job ) {

				if( $job->markFailed('never_ready', null, 'not ready to transcode ' . $job->ageMinutes() . ' min after creation', true) ) $report['failed']++;

			}

		} catch( Throwable $e ) {

			Logger::logEvent('transcode_sweep_error', [ 'message' => $e->getMessage(), 'exception' => $e ]);

		}

		$report['started'] = self::startWaitingJobs();

		if( $report['unknown'] ) {

			Logger::logEvent('transcode_sweep_unknown_state', [
				'message' => "Could not read the container state of {$report['unknown']} active job(s). Is scripts/docker-container.sh in /etc/sudoers.d/dtcdn-sudoers?",
				'data' => [ 'lastOutput' => $unknownStateOutput ?? null ]
			]);

		}

		return $report;

	}

	/**
	 * Pushes a failed upload's start mark back so resetStaleCloudUploads() re-queues it in CLOUD_UPLOAD_RETRY_MINUTES.
	 * Clearing the mark at once would retry a file that always fails on the queue's next one-second loop, about sixty
	 * times a minute, each with a log row, and a large one would fill every batch. A job over CLOUD_UPLOAD_MAX_AGE_HOURS
	 * old is abandoned instead.
	 *
	 * @param string $type 'src' or 'version'
	 * @return int jobs abandoned
	 */
	public static function deferCloudUploadRetry($type, array $jobIds) {

		if( !$jobIds ) return 0;

		$prefix = $type === 'src' ? 'src_' : '';
		$minutesAgo = self::CLOUD_UPLOAD_STALE_MINUTES - self::CLOUD_UPLOAD_RETRY_MINUTES;
		$ids = implode(",", CDNTools::intArray($jobIds));

		// An upload that has failed for a day is given up on, or its row and work directory would never expire
		$abandoned = self::abandonOldCloudUploads($type, $ids, 'failing');

		// _finished cleared too: a version whose own file went up but whose poster failed was just marked finished, and
		// resetStaleCloudUploads() only re-queues an unfinished row
		$sql = "UPDATE transcoding_jobs
			SET {$prefix}cloud_upload_started = NOW() - INTERVAL " . (int)$minutesAgo . " MINUTE,
				{$prefix}cloud_upload_finished = NULL
			WHERE id IN ($ids)
			AND job_started >= NOW() - INTERVAL " . (int)self::CLOUD_UPLOAD_MAX_AGE_HOURS . " HOUR";

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		return $abandoned;

	}

	/**
	 * Marks the cloud upload of the given jobs that are over CLOUD_UPLOAD_MAX_AGE_HOURS old finished without uploading,
	 * and logs them (cloud_upload_abandoned). The retries (deferCloudUploadRetry(), resetStaleCloudUploads()) have no
	 * count, so this is what ends one that never succeeds; the version file stays served from www/v, without its B2 copy.
	 *
	 * @param string $type 'src' or 'version'
	 * @param string $idsSql comma-separated job ids, already ints
	 * @param string $why for the log
	 * @return int rows abandoned
	 */
	protected static function abandonOldCloudUploads($type, $idsSql, $why) {

		$db = db();
		$prefix = $type === 'src' ? 'src_' : '';

		$sql = "SELECT id FROM transcoding_jobs
			WHERE id IN ($idsSql)
			AND {$prefix}cloud_upload_finished IS NULL
			AND job_started < NOW() - INTERVAL " . (int)self::CLOUD_UPLOAD_MAX_AGE_HOURS . " HOUR";

		if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting", $sql);

		$oldIds = array_map(fn($row) => (int)$row['id'], $db->sql_fetchrowset($result));
		$db->sql_freeresult($result);

		if( !$oldIds ) return 0;

		self::abandonCloudUpload($type, $oldIds);

		Logger::logEvent('cloud_upload_abandoned', [
			'message' => "Cloud upload still $why " . self::CLOUD_UPLOAD_MAX_AGE_HOURS . " hours after the job was created; marked finished so the job can expire",
			'data' => [ 'type' => $type, 'jobIds' => $oldIds ]
		]);

		return count($oldIds);

	}

	/**
	 * Marks an upload finished without uploading, for a file that no longer exists locally (the hub deleted the source
	 * as an orphan before its upload ran). Nothing can be done for it, and the row can now expire.
	 *
	 * @param string $type 'src' or 'version'
	 */
	public static function abandonCloudUpload($type, array $jobIds) {

		if( !$jobIds ) return;

		$prefix = $type === 'src' ? 'src_' : '';

		// Both marks: getCloudUploadJobs() selects on a null _started, so the job must leave that set too
		$sql = "UPDATE transcoding_jobs
			SET {$prefix}cloud_upload_started = NOW(),
				{$prefix}cloud_upload_finished = NOW()
			WHERE id IN (" . implode(",", CDNTools::intArray($jobIds)) . ")";

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

	}

	/**
	 * Re-queues cloud uploads marked started over CLOUD_UPLOAD_STALE_MINUTES ago and never finished: the upload cron died
	 * mid-run (reboot, OOM) or its bookkeeping lost them. Until 2026-10 such rows sat forever with their work directories.
	 *
	 * @return int rows re-queued
	 */
	public static function resetStaleCloudUploads() {

		$db = db();

		$reset = 0;

		foreach( [ 'src' => 'src_cloud_upload_started', 'version' => 'cloud_upload_started' ] as $type => $started ) {

			$finished = str_replace('_started', '_finished', $started);
			$staleSql = "$started IS NOT NULL
				AND $finished IS NULL
				AND $started < NOW() - INTERVAL " . (int)self::CLOUD_UPLOAD_STALE_MINUTES . " MINUTE";

			// One stalling for a day is given up on instead
			$sql = "SELECT id FROM transcoding_jobs WHERE $staleSql";

			if( !$result = $db->sql_query($sql) ) throw new QueryException("Error selecting", $sql);

			$staleIds = array_map(fn($row) => (int)$row['id'], $db->sql_fetchrowset($result));
			$db->sql_freeresult($result);

			if( !$staleIds ) continue;

			self::abandonOldCloudUploads($type, implode(",", $staleIds), 'unfinished');

			$sql = "UPDATE transcoding_jobs
				SET $started = NULL
				WHERE $staleSql
				AND job_started >= NOW() - INTERVAL " . (int)self::CLOUD_UPLOAD_MAX_AGE_HOURS . " HOUR";

			if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

			$reset += $db->sql_affectedrows();

		}

		if( $reset ) Logger::logEvent('cloud_upload_requeued', [ 'message' => "$reset stale cloud upload(s) re-queued" ]);

		return $reset;

	}

	/** Claims the job for this process. False when another process claimed it first, or it has since been finished or failed */
	public function setTranscodeStarted() {

		$db = db();

		$sql = "UPDATE transcoding_jobs
			SET transcode_started = NOW()
			WHERE id = " . (int)$this->id . "
			AND transcode_started IS NULL
			AND transcode_finished IS NULL";

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		$this->transcodeStarted = $this->transcodeStarted ?: new DateTime();

		return $db->sql_affectedrows() > 0;

	}

	public function unsetTranscodeStarted($claimedAt = null) {

		// Only a claim with no container: startTranscode() may have recorded its container (a docker run that took
		// longer than the sweep's grace period) since the caller read the row, and releasing that claim would let
		// startWaitingJobs() start a second container into the same out/. With $claimedAt (the transcode_started the
		// caller read), only that claim: two overlapping sweeps could otherwise release a claim the first one's
		// startWaitingJobs() has just made, before its docker run records the container.
		$sql = "UPDATE transcoding_jobs
			SET transcode_started = NULL
			WHERE id = " . (int)$this->id . "
			AND docker_container_id IS NULL
			AND transcode_finished IS NULL" . ($claimedAt !== null ? "
			AND transcode_started = '" . original_to_query($claimedAt) . "'" : "");

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

	}

	public function setDockerContainerId($dockerContainerId) {

		$this->dockerContainerId = $dockerContainerId;

		$sql = "UPDATE transcoding_jobs
			SET docker_container_id = '" . original_to_query($dockerContainerId) . "',
				transcode_started = CASE WHEN transcode_started IS NULL THEN NOW() ELSE transcode_started END
			WHERE id = " . (int)$this->id;

		if( !db()->sql_query($sql) ) throw new QueryException("Error updating", $sql);

	}

	protected static function generateRandomProgressToken() {

		global $db;

		do {

			$randToken = CDNTools::getRandomBase64(10);

			$sql = "SELECT id
				FROM transcoding_jobs
				WHERE progress_token='" . original_to_query($randToken) . "'";

			if( !$result = $db->sql_query($sql) ) throw new QueryException("Could not select", $sql);

		} while( $db->sql_numrows($result) > 0 );

		return $randToken;

	}

	public static function create($srcFilename, $srcIsNew, $srcExtension, $srcSizeBytes, $srcDuration, $versionFilename, $versionWidth, $versionHeight, TranscodingJobSettings $jobSettings) {

		$db = db();

		$progressToken = self::generateRandomProgressToken();

		$sql = "INSERT INTO transcoding_jobs (
			src_filename,
			src_is_new,
			src_extension,
			src_size_bytes,
			src_duration,
			version_filename,
			version_width,
			version_height,
			job_settings,
			progress_token
		) VALUES (
			'" . original_to_query($srcFilename) . "',
			" . (int)$srcIsNew . ",
			" . ($srcExtension ? "'" . original_to_query($srcExtension) . "'" : "NULL") . ",
			" . (int)$srcSizeBytes . ",
			'" . original_to_query($srcDuration) . "',
			'" . original_to_query($versionFilename) . "',
			" . ($versionWidth ? (int)$versionWidth : "NULL") . ",
			" . ($versionHeight ? (int)$versionHeight : "NULL") . ",
			'" . original_to_query(json_encode($jobSettings)) . "',
			'" . original_to_query($progressToken) . "'
		)";

		if( !$db->sql_query($sql) ) throw new QueryException("Could not insert into transcoding_jobs", $sql);

		$insertId = $db->sql_nextid();

		return self::getById($insertId);

	}

	public static function deleteExpiredJobs() {
		
		$db = db();

		// job_finished (transcode and uploads done) is never set for a failed job, so those go by transcode_finished
		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE job_finished < NOW() - INTERVAL 1 HOUR
			OR (transcode_fail_code IS NOT NULL AND transcode_finished < NOW() - INTERVAL 1 HOUR)";

		if( !$result = $db->sql_query($sql) ) {

			throw new QueryException("Error selecting", $sql);

		}

		$expiredJobs = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$expiredJobs[] = new self($row);

		}

		$deleteIds = self::getJobIds($expiredJobs);

		foreach( $expiredJobs as $job ) {

			// A retry after an abandoned or failed job is a new row for the same version, in the same work directory
			// (the source is still there). Leave the directory to that job; only the row goes.
			$sql = "SELECT 1
				FROM transcoding_jobs
				WHERE version_filename = '" . original_to_query($job->versionFilename) . "'
				AND id NOT IN (" . implode(",", $deleteIds) . ")
				LIMIT 1";

			if( !$liveResult = $db->sql_query($sql) ) throw new QueryException("Error selecting", $sql);

			if( $db->sql_fetchrow($liveResult) ) continue;

			try {

				$deleted = $job->deleteInProgressFolder();

			} catch( Throwable $e ) {

				$deleted = false; // e.g. a directory this user can't open

			}

			if( !$deleted && is_dir($job->inProgressDir()) ) {

				// The row still goes, so this is logged once rather than retried every minute
				Logger::logEvent('transcode_job_dir_delete_failed', [
					'message' => 'Could not delete the work directory; remove it by hand' . (isset($e) ? ': ' . $e->getMessage() : ''),
					'data' => [ 'jobId' => $job->id, 'dir' => $job->inProgressDir() ]
				]);

			}

			unset($e);

		}

		if( $deleteIds ) {

			$sql = "DELETE FROM transcoding_jobs
				WHERE id IN (" . implode(",", $deleteIds) . ")";

			if( !$db->sql_query($sql) ) {

				throw new QueryException("Error deleting", $sql);

			}

			return true;

		}

	}

	const CLOUD_UPLOAD_MAX_BATCH_SIZE = 100*1000*1000; // 100mb
	const CLOUD_UPLOAD_MAX_BATCH_UPLOADS = 100;
	const CLOUD_UPLOAD_MAX_CONCURRENT = 10;

	public static function getCloudUploadJobs() {

		/**
		 * Cloud upload architecture:
		 * 	Get up to 100mb worth of jobs
		 *  Up to 100 uploads
		 *  Max concurrency of 10
		 */
		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE (
				src_is_new = 1
				AND transcode_ready = 1
				AND src_cloud_upload_started IS NULL
				AND src_cloud_upload_finished IS NULL
			) OR (
				transcode_is_finished = 1
				AND transcode_fail_code IS NULL
				AND cloud_upload_started IS NULL
				AND cloud_upload_finished IS NULL
			)
			LIMIT " . self::CLOUD_UPLOAD_MAX_BATCH_UPLOADS;

		if( !$result = $db->sql_query($sql) ) {

			throw new QueryException("Error selecting", $sql);

		}

		$totalBytes = 0;

		$tJobs = [];
		while( $row = $db->sql_fetchrow($result) ) {

			$tJobs[] = $job = new self($row);

			$totalBytes += $job->srcSizeBytes;

			if( $totalBytes > self::CLOUD_UPLOAD_MAX_BATCH_SIZE ) break;
			
		}
		$db->sql_freeresult($result);
		
		return $tJobs;

	}

	/** @param TranscodingJob[] $tJobs */
	public static function setSrcCloudUploadStarted( array $tJobs ) {

		if( !$tJobs ) return;

		$db = db();

		$jobIds = self::getJobIds($tJobs);

		$sql = "UPDATE transcoding_jobs
			SET src_cloud_upload_started = NOW()
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	/** @param TranscodingJob[] $tJobs */
	public static function unsetSrcCloudUploadStarted( array $jobIds ) {

		if( !$jobIds ) return;

		$db = db();

		$jobIds = CDNTools::intArray($jobIds);

		$sql = "UPDATE transcoding_jobs
			SET src_cloud_upload_started = NULL
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	/** @param TranscodingJob[] $tJobs */
	public static function setSrcCloudUploadFinished( array $jobIds ) {

		if( !$jobIds ) return;

		$db = db();

		$jobIds = CDNTools::intArray($jobIds);

		$sql = "UPDATE transcoding_jobs
			SET src_cloud_upload_finished = NOW()
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	/** @param TranscodingJob[] $tJobs */
	public static function setCloudUploadStarted( array $tJobs ) {

		if( !$tJobs ) return;

		$db = db();

		$jobIds = self::getJobIds($tJobs);

		$sql = "UPDATE transcoding_jobs
			SET cloud_upload_started = NOW()
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	/** @param TranscodingJob[] $tJobs */
	public static function unsetCloudUploadStarted( array $jobIds ) {

		if( !$jobIds ) return;

		$db = db();

		$jobIds = CDNTools::intArray($jobIds);

		$sql = "UPDATE transcoding_jobs
			SET cloud_upload_started = NULL
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	/** @param TranscodingJob[] $tJobs */
	public static function setCloudUploadFinished( array $jobIds ) {

		if( !$jobIds ) return;

		$db = db();

		$jobIds = CDNTools::intArray($jobIds);

		$sql = "UPDATE transcoding_jobs
			SET cloud_upload_finished = NOW()
			WHERE id IN (" . implode(",", $jobIds) . ")";

		if( !$db->sql_query($sql) ) {

			throw new QueryException("Error updating", $sql);

		}

	}

	public function getSrcCloudPath() {

		return VideoPath::getSrcCloudPath($this->srcFilename, $this->srcExtension);

	}

	public function getVersionCloudPath() {

		return VideoPath::getVersionCloudPath($this->versionFilename, $this->isHls() ? 'hls' : 'mp4');

	}

	/** @param TranscodingJob[] $tJobs */
	public static function getJobIds( array $tJobs ) {

		$jobIds = [];
		foreach( $tJobs as $job ) {

			$jobIds[] = (int)$job->id;

		}

		return $jobIds;

	}

	public function getPercentComplete(&$isFinished = false, &$execResult = null, &$dockerOutput = null) {

		if( !$this->dockerContainerId ) return 0;

		$containerId = escapeshellarg($this->dockerContainerId);

		// The id is escaped above; no escapeshellcmd on top. Only the container's stdout carries the progress lines;
		// its stderr (ffmpeg's banner and encode summary) is dropped, or it lands in the caller's output, i.e. the
		// cron log on every finish from the sweep
		$cmd = "sudo /home/dtcdn/scripts/docker-logs.sh -c $containerId -n 70 2>/dev/null";

		exec($cmd, $execOutput, $execResult);

		$execOutput = $dockerOutput = $execOutput ? implode(PHP_EOL, $execOutput) : $execOutput;

		if( $execResult === 0 ) {

			// Finished?
			if( preg_match('/^progress=end/im', $execOutput ?: "") ) {

				$isFinished = true;

				return 1;

			}

			if( preg_match_all('/^out_time_us=(\d+)/im', $execOutput ?: "", $matches) ) {

				if( $lastOutTimeUs = array_pop($matches[1]) ) {

					$curOutTimeS = $lastOutTimeUs / 1000000;
					$progress = $curOutTimeS / $this->srcDuration;

					return $progress;

				} else {

					// out_time_us=0
					return 0;

				}

			}

			if( !$execOutput ) return 0;

		}

		return false;

	}

}

class Cron {

	public static function downloadSourcesFromCloud() {

		if( $jobs = TranscodingJob::getSourceDownloadJobs() ) {

			$client = CDNClient::getB2Client();
	
			$downloader = new \dliebner\B2\ParallelDownloader($client, 10);

			TranscodingJob::setCloudDownloadSrcInProgress($jobs);

			foreach( $jobs as $tcJob ) {

				$downloader->addFileToDownload([
					'tcJob' => $tcJob,
					'BucketName' => Config::get('b2_bucket_name'),
					'FileName' => $tcJob->getSrcCloudPath(),
					'SaveAs' => $tcJob->inProgressPath()
				]);

			}

			$downloader->doDownload();

			print_r([
				'downloaded' => count($downloader->getAllDownloadedFiles()),
				'failed' => count($downloader->getAllFailedFiles())
			]);

			foreach( $downloader->getAllFailedFiles() as $failedFileOptions ) {

				/** @var TranscodingJob $tcJob */
				$tcJob = $failedFileOptions['tcJob'];

			}

			$downloadedFiles = $downloader->getAllDownloadedFiles();

			foreach( $downloadedFiles as $downloadFileResult ) {

				$fileOptions = $downloadFileResult->originalFileOptions;
				/** @var TranscodingJob $tcJob */
				$tcJob = $fileOptions['tcJob'];

				$tcJob->setTranscodeReady();

				// A start that throws (docker, the path check) is logged and left for the sweep's startWaitingJobs(); it
				// mustn't stop the other jobs' starts or the in-progress reset below
				try {

					$tcJob->startTranscode();

				} catch( Throwable $e ) {

					Logger::logEvent('transcode_start_failed', [ 'message' => $e->getMessage(), 'exception' => $e, 'data' => [ 'jobId' => $tcJob->id, 'from' => 'downloadSourcesFromCloud' ] ]);

				}

			}

			TranscodingJob::setCloudDownloadSrcInProgress($jobs, false);

			if( $downloadedFiles ) return true;

		}

	}

}

class MissingFile {

	public $localSavePath;
	public $isZipped;
	public $clientServerSourceUrls;
	public $b2CloudPath;

	public function __construct($localSavePath, $isZipped, $b2CloudPath, $clientServerSourceUrls = []) {
		
		$this->localSavePath = $localSavePath;
		$this->isZipped = $isZipped;
		$this->clientServerSourceUrls = $clientServerSourceUrls;
		$this->b2CloudPath = $b2CloudPath;

	}

	public function getTmpSavePath() {

		return $this->localSavePath . '.part';

	}

	public function moveTmpToFinal() {

		if( file_exists($this->getTmpSavePath()) ) {

			return rename($this->getTmpSavePath(), $this->localSavePath);

		}

	}

	public function deleteTmp() {

		if( file_exists($this->getTmpSavePath()) ) return unlink($this->getTmpSavePath());

	}

}

class MissingFileDownloadResult {

    public $originalMissingFile;
    public $result;

    public function __construct(MissingFile $originalMissingFile, $result) {

        $this->originalMissingFile = $originalMissingFile;
        $this->result = $result;
        
    }

}

/**
 * Download flow:
 * 	- N download lanes
 * 	- K missing files
 * 		Attempt to download file from transcoding server (promise) -> then cloud (promise)
 * 	- Download lanes process one missing file at a time
 */
class MissingFileDownloader {

	public $guzzleClient;
	public $b2Client;

	public $numDownloadLanes = 7;

	/** @var MissingFile[] */
    public $filesToDownload = [];

    /** @var MissingFileDownloadLane[] */
    protected $mfDownloadLanes = [];

    public function __construct(\GuzzleHttp\Client $guzzleClient, \dliebner\B2\Client $b2Client, $numDownloadLanes = null) {

		$this->guzzleClient = $guzzleClient;
        $this->b2Client = $b2Client;

        if( $numDownloadLanes ) $this->numDownloadLanes = $numDownloadLanes;
        
    }

    public function addFileToDownload(MissingFile $missingFile) {

        $this->filesToDownload[] = $missingFile;

    }

    public function getNextFile() {

        return array_shift($this->filesToDownload);

    }

    /** @return MissingFileDownloadResult[] */
    public function getAllDownloadedFiles() {

        $allDownloadedFiles = [];

        foreach( $this->mfDownloadLanes as $lane ) {

            $allDownloadedFiles = array_merge($allDownloadedFiles, $lane->downloadedFiles);

        }

        return $allDownloadedFiles;

    }

    public function getAllFailedFiles() {

        $allFailedFiles = [];

        foreach( $this->mfDownloadLanes as $lane ) {

            $allFailedFiles = array_merge($allFailedFiles, $lane->failedFiles);

        }

        return $allFailedFiles;

    }

    public function numFilesToDownload() {

        return count($this->filesToDownload);

    }

    public function doDownload() {

        // Create download lanes
        $numDownloadLanes = min($this->numFilesToDownload(), $this->numDownloadLanes);

        $this->mfDownloadLanes = [];
        $promises = [];

        for( $i = 0; $i < $numDownloadLanes; $i++ ) {

            $this->mfDownloadLanes[] = $downloadLane = new MissingFileDownloadLane($this);
            $promises[] = $downloadLane->begin();

        }

        \GuzzleHttp\Promise\Each::of($promises)->then()->wait();

        return $this->getAllFailedFiles() ? false : $this->getAllDownloadedFiles();

    }

}

class MissingFileDownloadLane {

    public $parallelDownloader;

	/** @var MissingFileDownloadResult[] */
    public $downloadedFiles = [];
    public $failedFiles = [];

    public function __construct(MissingFileDownloader $mfDownloader) {

        $this->parallelDownloader = $mfDownloader;
        
    }

    public function begin() {

        return $this->downloadNextFile();

    }

    protected function downloadNextFile() {

        if( $nextFile = $this->parallelDownloader->getNextFile() ) {

			$onFileFailed = function() use ($nextFile) {

				$nextFile->deleteTmp();
				$this->failedFiles[] = $nextFile;

			};

			$onFileDownloaded = function() use ($nextFile, $onFileFailed) {

				if( $nextFile->moveTmpToFinal() ) {

					if( $nextFile->isZipped ) {
	
						// Unzip the downloaded file
						$zipFile = $nextFile->localSavePath;
	
						$zip = new ZipArchive;
	
						if( $zip->open($zipFile) ) {
	
							if( !$zip->extractTo( dirname($zipFile) ) ) {
	
								Logger::logEvent("unzip failed", [
									'email' => true,
									'data' => [
										'$root_path' => $GLOBALS['root_path'],
										'__DIR__' => __DIR__,
										'dirname($zipFile)' => dirname($zipFile),
										'file' => $nextFile,
									]
								]);
	
							}
	
							$zip->close();
	
							//echo $zipFile . " unzipped\n";
	
						} else {
	
							Logger::logEvent("zip open failed", [
								'email' => true,
								'data' => [
									'$root_path' => $GLOBALS['root_path'],
									'__DIR__' => __DIR__,
									'file' => $nextFile,
								]
							]);
	
						}
	
					}

					$this->downloadedFiles[] = new MissingFileDownloadResult($nextFile, true);

				} else {

					$onFileFailed();

				}

			};

			$b2Download = function() use ($nextFile, $onFileDownloaded, $onFileFailed) {

				// If direct transcoding server download fails, attempt to download from cloud
				$b2Client = $this->parallelDownloader->b2Client;
				
				$requestUrl = $b2Client->getB2FileRequestUrl(Config::get('b2_bucket_name'), $nextFile->b2CloudPath);
				$requestOptions = [
					'sink' => $nextFile->getTmpSavePath()
				];

				//echo "attempting to download b2 file from $requestUrl to " . $nextFile->getTmpSavePath() . "\n";

				$asyncRequest = new \dliebner\B2\AsyncRequestWithRetries($b2Client, 'GET', $requestUrl, $requestOptions);

				return $asyncRequest->begin()->then(function(\Psr\Http\Message\ResponseInterface $response) use ($onFileDownloaded) {
					
					$onFileDownloaded();

					return $this->downloadNextFile();
					
				}, function(\Exception $reason) use ($onFileFailed, $requestUrl) {

					$onFileFailed();

					//echo "$requestUrl failed: " . $reason->getMessage() . "\n";

					return $this->downloadNextFile();

				});

			};

			// Create directory if necessary
			$dirname = dirname($nextFile->getTmpSavePath());
			if( !file_exists($dirname)  && !mkdir_recursive($dirname, 0777) ) throw new Exception("Unable to create dir: $dirname");

			if( $nextFile->clientServerSourceUrls ) {

				// Attempt to download file directly from transcoding server
				$guzzleClient = $this->parallelDownloader->guzzleClient;

				$downloadNextSourceUrl = function($i = 0) use (&$downloadNextSourceUrl, $nextFile, $guzzleClient, $onFileDownloaded, $b2Download) {

					if( $sourceUrl = $nextFile->clientServerSourceUrls[$i] ) {

						//echo "attempting to download " . $sourceUrl . " to " . $nextFile->getTmpSavePath() . "\n";
		
						return $guzzleClient->requestAsync('GET', $sourceUrl, [
							'connect_timeout' => 1,
							'sink' => $nextFile->getTmpSavePath()
						])->then(function() use ($onFileDownloaded) {
		
							$onFileDownloaded();
		
							return $this->downloadNextFile();
		
						}, function(Exception $e) use ($sourceUrl, $i, $downloadNextSourceUrl) {
		
							//echo "direct download " . $sourceUrl . " failed: " . $e->getMessage() . "\n";
		
							// If direct transcoding server download fails, attempt next download source
							return $downloadNextSourceUrl($i + 1);
		
						});

					} else {

						return $b2Download();

					}

				};

				return $downloadNextSourceUrl();

			} else {

				// No transcoding server URL, just download from cloud
				return $b2Download();

			}

        }

    }

}

class Logger {

	public static function insertEventType( $eventType ) {

		global $db;

		$sql = "INSERT INTO log_event_types (event_type)
			SELECT '" . original_to_query($eventType) . "'
			FROM (SELECT @existing_id := NULL) as aux
			LEFT OUTER JOIN (
				SELECT @existing_id:=id as id
				FROM log_event_types
				WHERE event_type = '" . original_to_query($eventType) . "'
			) as existing_row ON 1
			WHERE existing_row.id IS NULL
			LIMIT 1";

		if( !$db->sql_query($sql) ) {

			throw new QueryException('Could not insert/update log_event_types', $sql);

		}

		if( $db->sql_affectedrows() ) {

			return $db->sql_nextid();
				
		} else {
				
			$sql = "SELECT @existing_id as existing_id";

			if( !$result = $db->sql_query($sql) ) {
					
				throw new QueryException('Could not select @existing_id', $sql);
					
			}
				
			return $db->sql_fetchrow($result)['existing_id'];
				
		}

	}
	
	public static function convertEventTypeToFilename($eventType, $backup = 'event') {
		
		$removed_apostrophes = preg_replace('/(\w)\'(\w)/', '$1$2', $eventType);
		$cleaned = preg_replace(array('/[^\w\-]+/', '/[-_]+/'), '-', $removed_apostrophes);
		$trimmed = trim($cleaned, '-');
		$final = strtolower($trimmed);
	
		if( empty($final) ) $final = $backup;
	
		return $final . '.html';
		
	}
	
	/**
	 * @param array $options bool 'email', string 'message', mixed 'data', Exception 'exception', bool 'to_file'
	 */
	public static function logEvent($eventType, $options = array()) {
		
		global $root_path;

		$db = db();

		if( ($e = $options['exception']) && get_class($e) == 'GeneralExceptionWithData' ) {

			$options['data'] = $options['data'] ?: [];
			$options['data']['_exception_data'] = $e->data;

		}
		
		if( $options['email'] ) {
			
			require_once($root_path . 'includes/Email.php');
			
			$contents = '<div>A log event has occurred:</div>
				<table><tr><td valign="top"><b>Event: </b></td><td>' . h($eventType) . '</tr>' .
				($options['message'] ? '<tr><td valign="top"><b>Message: </b></td><td>' . $options['message'] . '</td></tr>' : '');
			
			if( $options['exception'] ) {
				
				/**
				 * @var Exception $e
				 */
				$e = $options['exception'];

				$eMessage = '<div>';
				$eMessage .= '<b>Fatal error</b>:  Uncaught exception \'' . get_class($e) . '\' with message ';
				$eMessage .= $e->getMessage() . '<br>';
				$eMessage .= 'Stack trace:<pre>' . $e->getTraceAsString() . '</pre>';
				$eMessage .= 'thrown in <b>' . $e->getFile() . '</b> on line <b>' . $e->getLine() . '</b><br>';
				$eMessage .= '</div>';
				
				$sqlError = $db->sql_error();
				
				if( $sqlError['message'] ) {
					
					$eMessage .= '<br><br>sql_error: ' . print_r($db->sql_error(),1);
					
					if( get_class($e) == 'QueryException' ) {
						
						$eMessage .= '<br>sql: ' . myspecialchars($e->sql);
						
					}
					
				}
				
				$contents .= '<tr><td valign="top"><b>Exception: </b></td><td>' . $eMessage . '</td></tr>';
				
			}
				
			$contents .= ($options['data'] ? '<tr><td valign="top"><b>Data: </b></td><td><pre>' . myspecialchars(print_r($options['data'], 1)) . '</pre></td></tr>' : '') .
			'</table>';
			
			$email = new Email('dliebner@gmail.com', 'Event log: ' . $eventType, $contents);
			$email->send();
			
		}
		
		try {
				
			if( $options['exception'] ) $sqlError = $sqlError ?: $db->sql_error();
		
			// Log to database
			$eventTypeId = self::insertEventType($eventType);
			
			$sql = "INSERT INTO log_events (event_type_id, message, data, exception_data) VALUES 
				(" . (int)$eventTypeId . ", "
					. ($options['message'] ? "'" . original_to_query($options['message']) . "'" : "NULL") . ", "
					. ($options['data'] ? "'" . original_to_query(json_encode($options['data'])) . "'" : "NULL") . ", ";
			
			if( $options['exception'] ) {
	
				$e = $options['exception'];
				
				$jsonE = array(
					'msg' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'file' => $e->getFile(),
					'line' => $e->getLine()
				);
				
				if( $sqlError['message']) $jsonE['sql_error'] = $sqlError;
				
				// putting this here for reference in case we ever get any utf issues
				// $encodedArray = array_map(utf8_encode, $rawArray);
				
				$sql .= "'" . original_to_query(json_encode($jsonE)) . "'";
				
			} else {
				
				$sql .= "NULL";
				
			}
			
			$sql .= ")";
	
			if( !$db->sql_query($sql) ) {
	
				throw new QueryException('Could not insert into log_events', $sql);
	
			}
			
		} catch( Exception $e ) {

			$backupFileLog = true;
			
		}
		
		if( $backupFileLog || $options['to_file'] ) {

			// Backup file log
			$contents = '<div style="margin: 20px 0">
				<table><tr><td valign="top"><b>Event: </b></td><td>' . h($eventType) . '</tr>' .
				($options['message'] ? '<tr><td valign="top"><b>Message: </b></td><td>' . h($options['message']) . '</td></tr>' : '');
			
			if( $options['exception'] ) {
				
				/**
				 * @var Exception $e
				 */
				$e = $options['exception'];

				$eMessage = '<div>';
				$eMessage .= '<b>Fatal error</b>:  Uncaught exception \'' . get_class($e) . '\' with message ';
				$eMessage .= $e->getMessage() . '<br>';
				$eMessage .= 'Stack trace:<pre>' . $e->getTraceAsString() . '</pre>';
				$eMessage .= 'thrown in <b>' . $e->getFile() . '</b> on line <b>' . $e->getLine() . '</b><br>';
				$eMessage .= '</div>';
				
				$sqlError = $db->sql_error();
				
				if( $sqlError['message'] ) $eMessage .= "\n\nsql_error: " . print_r($db->sql_error(),1);
				
				$contents .= '<tr><td valign="top"><b>Exception: </b></td><td>' . $eMessage . '</td></tr>';
				
			}
				
			$contents .= ($options['data'] ? '<tr><td valign="top"><b>Data: </b></td><td>' . nl2br(h(print_r($options['data'], 1))) . '</td></tr>' : '') .
			'</table>';
			
			$fileName = self::convertEventTypeToFilename($eventType);
			$path = $root_path . 'logs/' . $fileName;
			
			$handle = fopen($path, 'a');
			fwrite($handle, $contents);
			fclose($handle);
			
		}
		
	}

}