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
	const CLIENT_ACTION_GET_VIDEO_INFO_FROM_URL = 'getVideoInfoFromUrl';

	public static function gitPull(&$execOutput = null) {

		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/git-pull.sh"
		);

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

	protected static function curlPost($url, $params = array()) {
		
		//open connection
		$ch = curl_init();
		
		//set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// set the connect timeout
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		
		if( count($params) > 0 ) {
			
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			
		}
		
		//execute post
		$response = curl_exec($ch);
		
		if( $err = curl_error($ch) ) throw new Exception('cURL error: ' . $err);
		
		//close connection
		curl_close($ch);
		
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
	public static function downloadVideoVersions(array $downloadVersions, &$missingFileDownloader = null) {

		$guzzleClient = new \GuzzleHttp\Client();
		$b2Client = self::getB2Client();
		$missingFileDownloader = new MissingFileDownloader($guzzleClient, $b2Client, 10);

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

		$missingFileDownloader->doDownload();

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
		$uploadedAspectRatio = $videoStream->displayAspectRatioFloat;
		$targetAspectRatio = $targetWidth / $targetHeight;

		if( $uploadedAspectRatio > $targetAspectRatio ) {

			// Uploaded video is "wider" (proportionally) than the target dimensions
			$constrainWidth = $targetWidth * 2; // 2x resolution
			$constrainHeight = -2;

		} else {

			// Uploaded video is "taller" (proportionally) than the target dimensions
			$constrainWidth = -2;
			$constrainHeight = $targetHeight * 2; // 2x resolution

		}
		
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
	public static function rrmdir($source, $removeOnlyChildren = false) {

		if( empty($source) || file_exists($source) === false ) return false;

		if( is_file($source) || is_link($source) ) return unlink($source);

		/** @var SplFileInfo[] $files */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach( $files as $file ) {

			if( $file->isDir() ) {

				if( self::rrmdir($file->getRealPath()) === false ) return false;

			} else {

				if( unlink($file->getRealPath()) === false ) return false;

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

		$dir = escapeshellarg(dirname($videoFilePath));
		$filename = escapeshellarg(basename($videoFilePath));
		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/docker-ffprobe.sh -d $dir -f $filename"
		);

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

			case 'video': return new FFProbeResult_VideoStream($json);
			case 'audio': return new FFProbeResult_AudioStream($json);

			default: throw new Exception("Unknown codec type");

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

	public function __construct($bitRate = null, $constrainWidth = null, $constrainHeight = null, $passThroughVideo = null, $saveAsHls = null, $hlsSegmentTime = null, $mute = false) {

		$this->bitRate = (int)$bitRate;
		$this->constrainWidth = (int)$constrainWidth ?: null;
		$this->constrainHeight = (int)$constrainHeight ?: null;
		$this->passThroughVideo = $passThroughVideo;
		$this->saveAsHls = $saveAsHls;
		$this->hlsSegmentTime = $hlsSegmentTime;
		$this->mute = $mute;
		
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

		return $clean[0] . '/' . $clean[1] . '/' . $clean[2] . '/';

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

}

class TranscodingJob {

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
		$this->versionWidth = (int)$row['version_width'];
		$this->versionHeight = (int)$row['version_height'];
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

		$sql = "SELECT *
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

		$sql = "SELECT COUNT(*) as count
			FROM transcoding_jobs
			WHERE transcode_is_active = 1";

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
			AND cloud_download_src_in_progress = 0";

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

	/** @return TranscodingJob[] */
	public static function getUnstartedTranscodeJobs() {

		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE transcode_ready = 1
			AND transcode_is_active = 0";

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

		// Required
		if( !$dir = realpath($this->inProgressDir()) ) throw new Exception("Error getting absolute path");
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
		if( ($constrainWidth = $this->jobSettings->constrainWidth) && ($constrainHeight = $this->jobSettings->constrainHeight) ) {

			$escapedArgs[] = '-w ' . escapeshellarg($constrainWidth);
			$escapedArgs[] = '-h ' . escapeshellarg($constrainHeight);

		}

		if( $this->jobSettings->saveAsHls ) $escapedArgs[] = "-s";
		if( $this->jobSettings->passThroughVideo ) $escapedArgs[] = "-p";
		if( $this->jobSettings->mute ) $escapedArgs[] = "-m";

		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/docker-ffmpeg.sh " . implode(" ", $escapedArgs)
		);

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

	public function finishTranscode() {

		$db = db();

		$pctComplete = $this->getPercentComplete($transcodeIsFinished, $execResult, $dockerOutput);

		if( !$transcodeIsFinished ) {

			$sql = "UPDATE transcoding_jobs
				SET transcode_fail_code = '" . original_to_query(json_encode($execResult)) . "',
					transcode_fail_output = '" . original_to_query(json_encode($dockerOutput)) . "'
				WHERE id=" . (int)$this->id;

			if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

			return false;

		}

		$transcodeOutDir = $this->inProgressDir() . CDNClient::DIR_TRANSCODE_OUTPUT;
		$wwwDir = VideoPath::localWwwPath() . VideoPath::videoUriBaseFolder($this->srcFilename);

		// Move contents of transcode out dir to www dir
		$basePath = realpath($transcodeOutDir);

		/** @var SplFileInfo[] $files */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($basePath),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach( $files as $file ) {

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

			// Initialize archive object
			$zip = new ZipArchive();
			$zip->open(VideoPath::hlsZipLocalPath($this->versionFilename), ZipArchive::CREATE | ZipArchive::OVERWRITE);

			// Create recursive directory iterator
			/** @var SplFileInfo[] $files */
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($basePath),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			$i = 0;
			foreach( $files as $file ) {

				// Skip directories (they would be added automatically)
				if( !$file->isDir() ) {

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

		$sql = "UPDATE transcoding_jobs
			SET transcode_finished = NOW(),
				transcode_fail_code = NULL,
				transcode_fail_output = NULL,
				hub_return_meta = " . ($hubResponseDataArray['returnMeta'] ? "'" . json_encode($hubResponseDataArray['returnMeta']) . "'" : "NULL") . "
			WHERE id=" . (int)$this->id;

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		// Start any pending transcoding jobs if possible
		foreach( self::getUnstartedTranscodeJobs() as $job ) {

			if( $job->startTranscode() === false ) break;

		}

		return true;

	}

	public function setTranscodeStarted() {

		$db = db();

		$sql = "UPDATE transcoding_jobs
			SET transcode_started = CASE WHEN transcode_started IS NULL THEN NOW() ELSE transcode_started END
			WHERE id = " . (int)$this->id;

		if( !$db->sql_query($sql) ) throw new QueryException("Error updating", $sql);

		$this->transcodeStarted = $this->transcodeStarted ?: new DateTime();

		return $db->sql_affectedrows() > 0;

	}

	public function unsetTranscodeStarted() {

		$sql = "UPDATE transcoding_jobs
			SET transcode_started = NULL
			WHERE id = " . (int)$this->id;

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
			" . (int)$versionWidth . ",
			" . (int)$versionHeight . ",
			'" . original_to_query(json_encode($jobSettings)) . "',
			'" . original_to_query($progressToken) . "'
		)";

		if( !$db->sql_query($sql) ) throw new QueryException("Could not insert into transcoding_jobs", $sql);

		$insertId = $db->sql_nextid();

		return self::getById($insertId);

	}

	public static function deleteExpiredJobs() {
		
		$db = db();

		$sql = "SELECT *
			FROM transcoding_jobs
			WHERE job_finished < NOW() - INTERVAL 1 HOUR";

		if( !$result = $db->sql_query($sql) ) {

			throw new QueryException("Error selecting", $sql);

		}

		$deleteIds = [];

		while( $row = $db->sql_fetchrow($result) ) {

			$job = new self($row);
			$job->deleteInProgressFolder();

			$deleteIds[] = (int)$job->id;

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
				AND src_cloud_upload_started IS NULL
			) OR (
				transcode_is_finished = 1
				AND cloud_upload_started IS NULL
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

		$cmd = escapeshellcmd(
			"sudo /home/bgcdn/scripts/docker-logs.sh -c $containerId -n 70"
		);

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
				$tcJob->startTranscode();

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