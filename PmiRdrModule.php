<?php
namespace PmiModule\PmiRdrModule;

use ExternalModules\ExternalModules;
use Google\Cloud\Datastore\DatastoreClient;

class PmiRdrModule extends \ExternalModules\AbstractExternalModule {
	public $client;
	public $credentials;

	const RECORD_CREATED_BY_MODULE = "rdr_module_created_this_";
	const RDR_CACHE_STATUS = "cache_status";
	const RDR_CACHE_SNAPSHOTS = "current_snapshots";

	public function __construct() {
		parent::__construct();

		if(is_file(__DIR__."/vendor/autoload.php")) {
			require_once(__DIR__."/vendor/autoload.php");
		}
		else if(is_file(dirname(dirname(__DIR__))."/vendor/autoload.php")) {
			require_once(dirname(dirname(__DIR__))."/vendor/autoload.php");
		}

		$credentialsPath = $this->getSystemSetting("credentials-path");
		$credentialsKind = $this->getSystemSetting("credentials-kind");
		$credentialsKey = $this->getSystemSetting("credentials-key");

		$this->credentials = false;

		try {
			if($_SERVER['APPLICATION_ID'] || $_SERVER['GAE_APPLICATION']) {
				$datastore = new DatastoreClient();

				$query = $datastore->query()->kind($credentialsKind);
				$result = $datastore->runQuery($query);

				/** @var \Google\Cloud\Datastore\Entity $entity */
				foreach($result as $entity) {
					$credentialsJson = $entity[$credentialsKey];
					if($credentialsJson) {
						$this->credentials = json_decode($credentialsJson,true);
						if($_GET['debug']) {
							echo "We have credentials. Count: ".count($this->credentials)." StrLen: ".strlen($credentialsJson)."<br />";
						}
					}
				}
			}
		}
		catch(\Exception $e) {
			$this->credentials = false;
		}

		if(empty($this->credentials)) {
			if (!empty($credentialsPath)) {
				## Add function to find web_root and then append credentials path
				putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
			}
		}
	}
	
	public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1 ) {
		## Prevent hook from being called by the RDR cron
		if(defined(self::RECORD_CREATED_BY_MODULE.$project_id."~".$record) &&
				constant(self::RECORD_CREATED_BY_MODULE.$project_id."~".$record) == 1) {
			return;
		}

		define(self::RECORD_CREATED_BY_MODULE.$project_id."~".$record,1);

		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $this->getGoogleClient();

		/** @var \GuzzleHttp\Client $httpClient */
		$httpClient = $client->authorize();

		$data = $this->getData($project_id,$record,$event_id);

		$rdrUrl = $this->getProjectSetting("rdr-urls",$project_id);

		$metadata = $this->getMetadata($project_id);

		$dataMappingJson = $this->getProjectSetting("rdr-data-mapping-json",$project_id);
		$dataMappingFields = $this->getProjectSetting("rdr-redcap-field-name",$project_id);
		$dataMappingApiFields = $this->getProjectSetting("rdr-redcap-field-name",$project_id);
		$apiRecordFields = $this->getProjectSetting("rdr-endpoint-record",$project_id);
		$redcapRecordFields = $this->getProjectSetting("rdr-record-field",$project_id);
		$dataFormats = $this->getProjectSetting("rdr-data-format",$project_id);
		$dataConnectionTypes = $this->getProjectSetting("rdr-connection-type",$project_id);
		$conditions = $this->getProjectSetting("rdr-conditions",$project_id);
		$testingOnly = $this->getProjectSetting("rdr-test-only",$project_id);

		foreach($rdrUrl as $urlKey => $thisUrl) {
			if(!in_array($dataConnectionTypes[$urlKey], ["push","pipe"])) {
				continue;
			}
			
			if(empty($dataMappingJson[$urlKey])) {
				$dataMapping = [];
				
				foreach($dataMappingFields[$urlKey] as $mappingKey => $fieldName) {
					$dataMapping[$fieldName] = $dataMappingApiFields[$urlKey][$mappingKey];
				}
			}
			else {
				$dataMapping = json_decode($dataMappingJson[$urlKey],true);
			}

			## Check for conditions before trying to send
			if(!empty($conditions[$urlKey])) {
				$readyToAct = \REDCap::evaluateLogic($conditions[$urlKey],$project_id,$record,$event_id,$repeat_instance);
				if(!$readyToAct) {
					if($testingOnly[$urlKey] == 1) {
						error_log("RDR Test: No action taken on $record ~ ".var_export($readyToAct,true)." ~ ".$conditions[$urlKey]);
					}
					continue;
				}
			}

			if($dataConnectionTypes[$urlKey] == "push") {
				$exportData = [];
				foreach($dataMapping as $redcapField => $apiField) {
					$apiNestedFields = explode("/",$apiField);

					if(count($apiNestedFields) > 0 && array_key_exists($redcapField,$data)) {
						if(empty($data[$redcapField])) {
							continue;
						}

						$importPlace = &$exportData;
						if($dataFormats[$urlKey] == "assoc") {
							$importPlace = &$importPlace[$record];
						}

						foreach($apiNestedFields as $tempField) {
							$importPlace = &$importPlace[$tempField];
						}

						$value = $data[$redcapField];
						if($metadata[$redcapField]["field_type"] == "checkbox") {
							$value = [];
							foreach($data[$redcapField] as $checkboxRaw => $checkboxChecked) {
								if($checkboxChecked == 1) {
									$value[] = $checkboxRaw;
								}
							}
						}
						else if($metadata[$redcapField]["field_type"] == "yesno") {
							$value = boolval($value);
						}
						else if(is_numeric($value)) {
							$value = (int)$value;
						}
						$importPlace = $value;
					}
				}

				if(!empty($exportData)) {
					$exportData = [$exportData];
	//				$results = $httpClient->post($thisUrl,["form_params" => $exportData]);

	//				$exportData = json_encode($exportData);
					## TODO Temp test string to see if works
	//				$exportData = '[{"userId": 5000,"creationTime": "2020-03-15T21:21:13.056Z","modifiedTime": "2020-03-15T21:21:13.056Z","givenName": "REDCap test","familyName": "REDCap test","email": "redcap_test@xxx.com","streetAddress1": "REDCap test","streetAddress2": "REDCap test","city": "REDCap test","state": "REDCap test","zipCode": "00000","country": "usa","ethnicity": "HISPANIC","sexAtBirth": ["FEMALE", "INTERSEX"],"identifiesAsLgbtq": false,"lgbtqIdentity": "REDCap test","gender": ["MAN", "WOMAN"],"race": ["AIAN", "WHITE"],"education": "COLLEGE_GRADUATE","degree": ["PHD", "MBA"],"disability": "YES","affiliations": [{"institution": "REDCap test","role": "REDCap test","nonAcademicAffiliation": "INDUSTRY"}],"verifiedInstitutionalAffiliation": {"institutionShortName": "REDCap test","institutionalRole": "REDCap test"}}]';
	//				$exportData = json_decode($exportData,true);

					if($testingOnly[$urlKey] != 1) {
						$results = $httpClient->post($thisUrl,["json" => $exportData]);
						if($results->getStatusCode() != 200) {
							$message = $results->getBody()->getContents();
							error_log("RDR Test: ".var_export($results->getHeaders(),true));
							error_log("RDR Test: ".var_export($message,true));

							\REDCap::logEvent("Pushed decision to RDR","Failed: \n".$message,"",$record,$event_id,$project_id);
						}
						else {
							\REDCap::logEvent("Pushed decision to RDR","Success","",$record,$event_id,$project_id);
						}
					}
					else {
						error_log("RDR Test: Ready to Send $record ~ ".var_export($exportData,true));
					}
				}
			}
			else if($dataConnectionTypes[$urlKey] == "pipe") {
				$thisRecord = $record;
				if(!empty($redcapRecordFields[$urlKey])) {
					$thisRecord = $data[$redcapRecordFields[$urlKey]];
				}

				## Skip if we don't have a record ID to match for piping
				if(empty($thisRecord)) {
					continue;
				}

				## Pull the data from the API and then decode it (assuming its JSON for now)
				$results = $httpClient->get($thisUrl."?snapshot_id=".($thisRecord));

				$decodedResults = json_decode($results->getBody()->getContents(),true);

				## Export full API results if trying to debug
				if($_GET['debug'] == 1) {
					echo "Debug Test<Br />";
					echo "<pre>".htmlspecialchars(var_export($decodedResults,true))."</pre><br />";
					continue;
				}

				## This value is set if an error is returned from the RDR
				if($decodedResults["message"] != "") {
					echo "Error getting results: received message \"".$decodedResults["message"]."\"<br />";
					continue;
				}

				## Start looping through the data returned from the API (this is the "record" level)
				foreach($decodedResults as $dataKey => $dataDetails) {
					## This could be because an error message was received or the API data isn't formatted properly
					if(!is_array($dataDetails)) {
						continue;
					}

					## "flat" means that the top level array keys don't contain the record IDs, so need to look it up from the data
					$dataRecordId = $dataKey;
					if($dataFormats[$urlKey] == "flat") {
						$dataRecordId = $dataDetails[$apiRecordFields[$urlKey]];
					}

					if($dataRecordId == $thisRecord) {
						## This is the API record to be pulled in

						## Start with an empty data set for the record and start trying to pull data from the API array
						$rowData = [];
						foreach($dataMapping as $redcapField => $apiField) {
							$checkboxMatches = [];

							## Check REDCap metadata so that bool and raw data can be mapped properly
							## "___[raw_value]" is used to map checkboxes one value at a time
							if(preg_match("/\\_\\_\\_([0-9a-zA-Z]+$)/",$redcapField,$checkboxMatches)) {
								$checkboxValue = $checkboxMatches[1];
								$checkboxFieldName = substr($redcapField,0,strlen($checkboxMatches) - strlen($checkboxMatches[0]));

								if(!array_key_exists($checkboxFieldName,$rowData)) {
									$rowData[$checkboxFieldName] = [];
								}

								$rowData[$checkboxFieldName][$checkboxValue] = ($this->getApiValue($dataDetails,$apiField) ? "1" : "0");
							}
							else {
								$rowData[$redcapField] = $this->getApiValue($dataDetails,$apiField,$metadata[$redcapField]);
							}
						}

						if(count($rowData) > 0) {
							## Filter out data is already exists in REDCap
							$dataToSave = [];
							foreach($rowData as $fieldName => $newValue) {
								if(!empty($data[$fieldName])) {
									$dataToSave[$fieldName] = $newValue;
								}
							}

							if(count($dataToSave) == 0) {
								break;
							}

							if($testingOnly[$urlKey] == 1) {
								echo "<pre>";error_log("PMI Test - ".var_export($rowData,true));echo "</pre>";echo "<br />";
								break;
							}

							## Time to save the data
							$results = $this->saveData($project_id,$record,$event_id,$rowData);

							if(count($results["errors"]) > 0) {
								error_log("PMI RDR: Couldn't import data: ".var_export($results["errors"],true));
							}
						}
						break;
					}
				}
			}
		}
	}
	
	public function resume_cache_or_restart($rdrUrl) {
		## Have to reset $_GET['pid'] so this function uses system level logging
		$oldProjectId = $_GET['pid'];
		$_GET['pid'] = NULL;
		
		$q = $this->queryLogs(
			"SELECT ".self::RDR_CACHE_STATUS.", ".self::RDR_CACHE_SNAPSHOTS.", timeout
			WHERE message = '".self::RDR_CACHE_STATUS."'
				AND url = ?
			ORDER BY timestamp DESC LIMIT 1", [$rdrUrl]);
		
		$cacheLog = db_fetch_assoc($q);
		$returnValue = false;
		
		if($cacheLog[self::RDR_CACHE_STATUS] == "done") {
			if($cacheLog['timeout'] < time()) {
				$snapshotSetting = $this->getCacheSettingByUrl($rdrUrl);
				
				$this->encodeSnapshotsForStorage([], $snapshotSetting."_new");
				
				## Start pulling new cache
				$this->fetchNextSnapshots($rdrUrl, []);
			}
			else {
				$returnValue =  true;
			}
		}
		else {
			$currentSnapshots = $cacheLog['currentSnapshots'] ?: [];
			
			## Start doing the next batch of snapshots
			$this->fetchNextSnapshots($rdrUrl, $currentSnapshots);
		}
		
		## Restore $_GET PID
		$_GET['pid'] = $oldProjectId;
		return $returnValue;
	}
	
	public function getCacheSettingByUrl($rdrUrl) {
		$cachedSnapshots = $this->getSystemSetting(self::RDR_CACHE_SNAPSHOTS);
		$cachedSnapshots = json_decode($cachedSnapshots, true);
		
		$snapshotSetting = false;
		$saveNewSnapshotUrl = false;
		
		## Find snapshot location for this URL
		foreach($cachedSnapshots as $thisSnapshot) {
			$thisUrl = $thisSnapshot['url'];
			if($thisUrl == $rdrUrl) {
				$snapshotSetting = $thisSnapshot['setting_name'];
			}
		}
		
		## Create a new snapshot setting id and verify uniqueness
		while($snapshotSetting === false) {
			$saveNewSnapshotUrl = true;
			$snapshotSetting = "cache_id_".uniqid();
			foreach($cachedSnapshots as $thisSnapshot) {
				if($thisSnapshot['setting_name'] == $snapshotSetting) {
					$snapshotSetting = false;
					break;
				}
			}
		}
		
		if($saveNewSnapshotUrl) {
			$cachedSnapshots[] = [
				"url" => $rdrUrl,
				"setting_name" => $snapshotSetting
			];
			$cacheToSave = json_encode($cachedSnapshots);
			
			if($cacheToSave) {
				$this->setSystemSetting(self::RDR_CACHE_SNAPSHOTS, $cacheToSave);
			}
		}
		
		return $snapshotSetting;
	}
	
	public function getCachedDataByURL($rdrUrl) {
		$cacheSetting = $this->getCacheSettingByUrl($rdrUrl);
		
		return $this->decodeSnapshotsFromStorage($cacheSetting);
	}
	
	
	public function fetchNextSnapshots($rdrUrl, $currentSnapshots) {
		## Have to reset $_GET['pid'] so this function uses system level logging
		$oldProjectId = $_GET['pid'];
		$_GET['pid'] = NULL;
		
		$isLastSnapshot = false;
		
		$snapshotSetting = $this->getCacheSettingByUrl($rdrUrl);
		
		$storedSnapshots = $this->getSystemSetting($snapshotSetting."_new");
		$storedSnapshots = json_decode($storedSnapshots, true) ?: [];
		
		if(count($currentSnapshots) == 0) {
			$latestSnapshot = 0;
		}
		else {
			$latestSnapshot = max($currentSnapshots);
		}
		
		## Pull the data from the API and then decode it (assuming its JSON for now)
		$urlToPull = $rdrUrl."?last_snapshot_id=".$latestSnapshot;
		$newSnapshots = $this->rdrPullSnapshotsFromAPI($urlToPull, false);
		
		foreach($newSnapshots as $snapshotKey => $thisSnapshot) {
			$storedSnapshots[$snapshotKey] = $thisSnapshot;
			$currentSnapshots[] = $snapshotKey;
		}
		
		$this->encodeSnapshotsForStorage($storedSnapshots, $snapshotSetting."_new");
		
		## TODO Not currently sure how to check if last snapshot, so just always assuming last
		## It's possible the API changed and there's no longer away to limit count of responses
		$isLastSnapshot = true;
		
		if($isLastSnapshot) {
			## Copy the new setting to the base cache location once done
			$this->encodeSnapshotsForStorage($storedSnapshots, $snapshotSetting);
			
			$timeout = ((int)$this->getSystemSetting('timeout_duration')) ?: 48*60*60;
			$this->log(self::RDR_CACHE_STATUS,[
				self::RDR_CACHE_SNAPSHOTS => json_encode($currentSnapshots),
				self::RDR_CACHE_STATUS => "done",
				"url" => $rdrUrl,
				"timeout" => time() + $timeout
			]);
			
			## Restore $_GET PID
			$_GET['pid'] = $oldProjectId;
			return true;
		}
		
		$this->log(self::RDR_CACHE_STATUS,[
			self::RDR_CACHE_SNAPSHOTS => json_encode($currentSnapshots),
			self::RDR_CACHE_STATUS => "not done",
			"url" => $rdrUrl
		]);
		
		## Restore $_GET PID
		$_GET['pid'] = $oldProjectId;
		return false;
	}
	
	public function rdrPullSnapshotsFromAPI($rdrUrl, $debugApi = false) {
		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $this->getGoogleClient();
		
		/** @var GuzzleHttp\ClientInterface $httpClient */
		$httpClient = $client->authorize();
		
		$results = $httpClient->get($rdrUrl);
		
		$decodedResults = json_decode($results->getBody()->getContents(),true);
		
		## Export full API results if trying to debug
		if($debugApi) {
			echo "Debug Test<Br />";
			echo "Results Details: ".$results->getStatusCode()."<br />";
			echo "Total Records Pulled: ".count($decodedResults)."<br />";
			if(count($decodedResults) > 1000) {
				$outputResults = array_slice($decodedResults, 0, 1000);
			}
			else {
				$outputResults = $decodedResults;
			}
			
			echo "<pre>".htmlspecialchars(var_export($outputResults,true))."</pre><br />";
		}
		
		return $decodedResults;
	}
	
	function encodeSnapshotsForStorage($storedSnapshots, $snapshotSetting) {
		$storedSnapshots = json_encode($storedSnapshots);
		
		## Max length on log parameter is 16M characters
		## Cutting at 3/4 limit to ensure no issues
		## Break stored snapshots into chunks and store a list of indexes instead
		$storedSnapshotParts = [];
		while(strlen($storedSnapshots) > 12000000) {
			$storedSnapshotParts[] = substr($storedSnapshots, 0, 12000000);
			$storedSnapshots = substr($storedSnapshots, 12000000);
		}
		
		$storedSnapshotParts[] = $storedSnapshots;
		$storedSnapshotIndexes = [];
		
		foreach($storedSnapshotParts as $storedIndex => $thisPart) {
			echo "Saving ".$snapshotSetting."_".$storedIndex." with length: ".strlen($thisPart);
			$this->setSystemSetting($snapshotSetting."_".$storedIndex, $thisPart);
			$storedSnapshotIndexes[] = $snapshotSetting."_".$storedIndex;
		}
		
		$storedSnapshotIndexes = json_encode($storedSnapshotIndexes);
		$this->setSystemSetting($snapshotSetting, $storedSnapshotIndexes);
	}
	
	function decodeSnapshotsFromStorage($snapshotSetting) {
		$storedSnapshotIndexes = $this->getSystemSetting($snapshotSetting);
		$storedSnapshotIndexes = json_decode($storedSnapshotIndexes, true);
		
		$storedSnapshots = "";
		foreach($storedSnapshotIndexes as $thisIndex) {
			$storedSnapshots .= $this->getSystemSetting($thisIndex);
		}
		
		return json_decode($storedSnapshots);
	}

	## RDR Cron method to pull data in
	public function rdr_pull($debugApi = false,$singleRecord = false) {
//		$this->log("Ran pull cron");
		
		if(is_array($debugApi)) {
			## When run from the cron, an array is passed in here
			$debugApi = false;
		}

		$cronBeginTime = microtime(true);
		$projectList = $this->framework->getProjectsWithModuleEnabled();

		## Start by looping through all projects with this module enabled to update cache
		foreach($projectList as $projectId) {
			## If a null or empty project ID gets passed in, skip it
			if(!$projectId) {
				continue;
			}
			
			$allUrlsCached = true;
			
			## Cache the cron results for this project,
			## stop if over 90 seconds for single pull or 240 for whole cron
			$rdrUrls = $this->getProjectSetting("rdr-urls", $projectId);
			$dataConnectionTypes = $this->getProjectSetting("rdr-connection-type",$projectId);
			foreach($rdrUrls as $urlKey => $thisUrl) {
				## Only processing pull connections here, also skip empty URLs
				if($dataConnectionTypes[$urlKey] != "pull" || empty($thisUrl)) {
					continue;
				}
				
				$startTime = microtime(true);
				
				$cacheDone = $this->resume_cache_or_restart($thisUrl);
				if(!$cacheDone) {
					$allUrlsCached = false;
				}
				
				$endTime = microtime(true);
				if(($endTime - $startTime) > 90 || ($endTime - $cronBeginTime) > 240) {
					continue;
				}
			}
			
			if($allUrlsCached) {
				## Pull event ID and Arm ID from the \Project object for this project
				$proj = new \Project($projectId);
				$proj->loadEvents();
				$eventId = $proj->firstEventId;
				$armId = $proj->firstArmId;
	
				## Pull the project metadata
				$metadata = $this->getMetadata($projectId);
	
				## Pull the module settings needed for import from this project
				$dataMappingJson = $this->getProjectSetting("rdr-data-mapping-json",$projectId);
				$dataMappingFields = $this->getProjectSetting("rdr-redcap-field-name",$projectId);
				$dataMappingApiFields = $this->getProjectSetting("rdr-redcap-field-name",$projectId);
				$apiRecordFields = $this->getProjectSetting("rdr-endpoint-record",$projectId);
	//			$redcapRecordFields = $this->getProjectSetting("rdr-record-field",$projectId);
				$dataFormats = $this->getProjectSetting("rdr-data-format",$projectId);
				$testingOnly = $this->getProjectSetting("rdr-test-only",$projectId);
	
				## Loop through each of the URLs this project is pointed to
				foreach($rdrUrls as $urlKey => $thisUrl) {
					## Only processing pull connections here, also skip empty URLs
					if($dataConnectionTypes[$urlKey] != "pull" || empty($thisUrl)) {
						continue;
					}
	
					## TODO This might be outdated now
					## Check for a JSON version of the data mapping and pull directly from the other settings
					## If it doesn't exist
					if(empty($dataMappingJson[$urlKey])) {
						$dataMapping = [];
	
						foreach($dataMappingFields[$urlKey] as $mappingKey => $fieldName) {
							$dataMapping[$fieldName] = $dataMappingApiFields[$urlKey][$mappingKey];
						}
					}
					else {
						$dataMapping = json_decode($dataMappingJson[$urlKey],true);
					}
	
					## This RDR doesn't have its data mapping set up yet (or it's set up improperly)
					if(count($dataMapping) == 0) {
						continue;
					}
	
					## Pull the form name from the first field in the mapping along with the list of existing records
					$fieldName = reset(array_keys($dataMapping));
					$formName = $metadata[$fieldName]["form_name"];
					$recordList = \REDCap::getData(["project_id" => $projectId,"fields" => $fieldName]);
	
					$recordIds = array_keys($recordList);
					
					## Get an error running max on an empty array
					$maxRecordId = count($recordIds) > 0 ? max($recordIds) : 0;
					
					$decodedResults = $this->getCachedDataByURL($thisUrl);
					
					## Start looping through the data returned from the API (this is the "record" level)
					foreach($decodedResults as $dataKey => $dataDetails) {
						## This could be because an error message was received or the API data isn't formatted properly
						## Or if not yet at $maxRecordId
						if(!is_array($dataDetails) || $dataKey < $maxRecordId) {
							continue;
						}
	
						## "flat" means that the top level array keys don't contain the record IDs, so need to look it up from the data
						$recordId = $dataKey;
						if($dataFormats[$urlKey] == "flat") {
							$recordId = $dataDetails[$apiRecordFields[$urlKey]];
						}
	
						## Don't try to import if the record already exists
						## TODO See if we can find a way to update records without making it
						## TODO run so slowly that it can never finish in App Engine (60 second timeout)
						if(array_key_exists($recordId,$recordList)) {
							continue;
						}
						
						## Start with an empty data set for the record and start trying to pull data from the API array
						$rowData = [];
						foreach($dataMapping as $redcapField => $apiField) {
	
							$checkboxMatches = [];
	
							## Check REDCap metadata so that bool and raw data can be mapped properly
							## "___[raw_value]" is used to map checkboxes one value at a time
							if(preg_match("/\\_\\_\\_([0-9a-zA-Z]+$)/",$redcapField,$checkboxMatches)) {
								$checkboxValue = $checkboxMatches[1];
								$checkboxFieldName = substr($redcapField,0,strlen($redcapField) - strlen($checkboxMatches[0]));
	
								if(!array_key_exists($checkboxFieldName,$rowData)) {
									$rowData[$checkboxFieldName] = [];
								}
	
								$rowData[$checkboxFieldName][$checkboxValue] = ($this->getApiValue($dataDetails,$apiField) ? "1" : "0");
							}
							else {
								$rowData[$redcapField] = $this->getApiValue($dataDetails,$apiField,$metadata[$redcapField]);
							}
						}
	
						if($testingOnly[$urlKey] == "1") {
							if(!$debugApi) {
								echo "<pre>".htmlspecialchars($recordId." => ".var_export($rowData,true))."</pre>";echo "<br />";
							}
						}
						else {
							self::checkShutdown();
							
							## Attempt to save the data
							$results = $this->saveData($projectId,$recordId,$eventId,$rowData);
	
							if(count($results["errors"]) > 0) {
								error_log("PMI RDR: Couldn't import data: ".var_export($results["errors"],true));
							}
	
							try {
								## Trigger alerts and notifications
								$eta = new \Alerts();
	
								$eta->saveRecordAction($projectId,$recordId,$formName,$eventId);
							}
							## Catch issues with sending alerts
							catch(\Exception $e) {
								error_log("RDRError sending notification email- Project: $projectId - Record: $recordId: ".var_export($e->getMessage(),true));
							}
	
							## Add to records cache
							\Records::addRecordToRecordListCache($projectId,$recordId,$armId);
	
							## Define a constant so that this module's own save hook isn't called
							define(self::RECORD_CREATED_BY_MODULE.$recordId,1);
	
							## Set the $_GET parameter as auto record generation hook seems to call errors on this (when called by the cron)
							$_GET['pid'] = $projectId;
							$_GET['id'] = $recordId;
	
							## Prevent module errors from crashing the whole import process
							try {
								ExternalModules::callHook("redcap_save_record",[$projectId,$recordId,$formName,$eventId,NULL,NULL,NULL]);
							}
							catch(\Exception $e) {
								$test = \REDCap::email("kyle.mcguffin@vumc.org","","PMI - Error on PMI RDR Module","External Module Error - Project: $projectId - Record: $recordId: ".$e->getMessage());
								error_log("External Module Error - Project: $projectId - Record: $recordId: ".$e->getMessage());
							}
						}
					}
				}
			}
		}
	}

	public function getApiValue($apiData,$apiField,$fieldMetadata = false) {
		if($apiField == "@NOW") {
			return date("Y-m-d H:i:s");
		}

		$apiFieldList = explode("/",$apiField);
		$importFrom = &$apiData;

		## This section allows nested API data to be accessed directly by using "/" delimiter on the module config
		foreach($apiFieldList as $thisField) {
			if(array_key_exists($thisField,$importFrom)) {
				$importFrom = &$importFrom[$thisField];
			}
			else if(strpos($thisField,"[") !== false) {
				## Special piping characters present, so need to apply the special rules
				if($thisField == "[owner]") {
					$ownerId = false;
					foreach($apiData["workspaceUsers"] as $thisUser) {
						if($thisUser["role"] == "OWNER") {
							$ownerId = $thisUser["userId"];
							break;
						}
					}

					if($ownerId) {
						foreach($importFrom as $researcherKey => $thisResearcher) {
							if($thisResearcher["userId"] == $ownerId) {
								$importFrom = &$importFrom[$researcherKey];
								continue 2;
							}
						}
					}

					## This line should only be reached if $ownerId not found or if $ownerId doesn't exist in the current array
					return false;
				}
			}
			else {
				return false;
			}
		}

		## For array values in RDR, it will return a 1 element array with "UNSET"
		## instead of NULL or an empty array
		if(is_array($importFrom)) {
			foreach($importFrom as $thisValue) {
				if($thisValue == "UNSET") {
					$importFrom = false;
					break;
				}
			}
		}

		if($fieldMetadata === false) {
			return ($importFrom ? 1 : 0);
		}

		if($fieldMetadata["field_type"] == "checkbox") {
			$value = [];
			foreach($importFrom as $checkboxRaw) {
				$value[htmlspecialchars($checkboxRaw)] = 1;
			}
			return $value;
		}

		if($fieldMetadata["field_type"] == "yesno") {
			return ($importFrom ? "1" : "0");
		}

		return htmlspecialchars($importFrom);
	}

	public function getGoogleClient() {
		if(!$this->client) {
			if(empty($this->credentials)) {
				if($_GET['debug']) {
					echo "<pre>Creating client without any credentials\n";echo "</pre>";echo "<br />";
				}
				$this->client = new \Google_Client([]);
				$this->client->useApplicationDefaultCredentials();
			}
			else {
				$this->client = new \Google_Client([]);
				$this->client->setAuthConfig($this->credentials);
			}

			$this->client->addScope("profile");
			$this->client->addScope("email");

			$authUserEmail = $this->getSystemSetting("auth-user-email");

			if($authUserEmail) {
				$this->client->setSubject($authUserEmail);
				if($_GET['debug']) {
					echo "Setting Auth User to " . $authUserEmail . "<Br />";
				}
			}
		}

		return $this->client;
	}

	public function redcap_module_save_configuration( $project_id ) {
		$oldJson = $this->getProjectSetting('existing-json');
		$newJson = $this->getProjectSetting('rdr-data-mapping-json');
		$newFields = $this->getProjectSetting('rdr-redcap-field-name');
		$newMatchingFields = $this->getProjectSetting('rdr-endpoint-field-name');
		$newMappingSubSettings = $this->getProjectSetting("rdr-data-mapping");

		$dataChanged = false;
		if(!is_array($oldJson)) {
			$oldJson = [];
		}

		foreach($newJson as $apiKey => $jsonDetails) {
			$combinedJson = $this->refactorDropdownsToJson($newFields[$apiKey],$newMatchingFields[$apiKey]);
			if(array_key_exists($apiKey,$oldJson)) {
				$thisOldJson = $oldJson[$apiKey];
			}
			else {
				$thisOldJson = "";
			}

			## Use dropdowns to check for updates
			if($jsonDetails == $thisOldJson) {
				## This means something changed on the dropdowns
				if($combinedJson != $thisOldJson) {
					$oldJson[$apiKey] = $combinedJson;
					$newJson[$apiKey] = $combinedJson;
					$dataChanged = true;
				}
			}
			## This means the json was updated, so need to update the dropdowns too
			else {
				$dataChanged = true;
				$decodedJson = json_decode($jsonDetails,true);
				$thisNewFields = [];
				$thisNewMatchingFields = [];

				foreach($decodedJson as $redcapField => $endpointField) {
					$thisNewFields[] = $redcapField;
					$thisNewMatchingFields[] = $endpointField;
				}

				$thisNewJson = $this->refactorDropdownsToJson($thisNewFields,$thisNewMatchingFields);

				$newFields[$apiKey] = $thisNewFields;
				$newMatchingFields[$apiKey] = $thisNewMatchingFields;

				$newMappingSubSettings[$apiKey] = array_fill(0,count($thisNewFields),"true");
				$oldJson[$apiKey] = $thisNewJson;
				$newJson[$apiKey] = $thisNewJson;
			}
		}

		if($dataChanged) {
			$this->setProjectSetting("rdr-redcap-field-name",$newFields);
			$this->setProjectSetting("rdr-endpoint-field-name",$newMatchingFields);
			$this->setProjectSetting("existing-json",$oldJson);
			$this->setProjectSetting("rdr-data-mapping-json",$newJson);
			$this->setProjectSetting("rdr-data-mapping",$newMappingSubSettings);
		}
	}

	public function refactorDropdownsToJson($newFields, $endpointFields) {
		if(count($newFields) != count($endpointFields)) {
			return false;
		}

		$newJson = [];
		foreach($newFields as $fieldKey => $fieldName) {
			$endpoint = $endpointFields[$fieldKey];

			$newJson[$fieldName] = $endpoint;
		}

		return json_encode($newJson);
	}

	public function redcap_module_link_check_display($project_id, $link) {
		if($link["name"] == "Test Page") {
			if(constant("SUPER_USER") == 1) {
				return parent::redcap_module_link_check_display($project_id, $link);
			}
			else {
				return false;
			}
		}
		else {
			return parent::redcap_module_link_check_display($project_id, $link);
		}
	}
	
	public static function checkShutdown() {
		$connectionStatus = connection_status();
		if($connectionStatus == 2 || $connectionStatus == 3) {
			error_log("App Engine Error: Timeout");
			echo "Process timed out<br />";
			die();
		}
	}
}