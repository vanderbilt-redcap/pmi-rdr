<?php
namespace PmiModule\PmiRdrModule;

use ExternalModules\ExternalModules;
use Google\Cloud\Datastore\DatastoreClient;

class PmiRdrModule extends \ExternalModules\AbstractExternalModule {
	public $client;
	public $credentials;
	
	private static $loggingEnabled;
	private static $cachedMetadata;

	const RECORD_CREATED_BY_MODULE = "rdr_module_created_this_";

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
	
	public function getLoggingEnabled() {
		if(self::$loggingEnabled === NULL) {
			self::$loggingEnabled = $this->getSystemSetting("logging-enabled");
		}
		
		return self::$loggingEnabled;
	}
	
	public function getMetadata($projectId, $forms = NULL) {
		if(!array_key_exists($projectId,self::$cachedMetadata)) {
			self::$cachedMetadata[$projectId] = parent::getMetadata($projectId,$forms);
		}
		
		return self::$cachedMetadata[$projectId];
	}
	
	public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1 ) {
		## Prevent hook from being called by the RDR cron
		if(constant(self::RECORD_CREATED_BY_MODULE.$project_id."~".$record) == 1) {
			return;
		}

		define(self::RECORD_CREATED_BY_MODULE.$project_id."~".$record,1);

		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $this->getGoogleClient();

		/** @var \GuzzleHttp\Client $httpClient */
		$httpClient = $client->authorize();

		$data = json_decode($this->getData($project_id,$record,"","json"),true);
		$thisData = false;
		$nonInstancedData = false;
		$this->log("debug",["data" => json_encode($data)]);

		
		foreach($data as $instanceDetails) {
			if(!array_key_exists("event_id",$instanceDetails) && !array_key_exists("redcap_repeat_instance",$instanceDetails)) {
				$nonInstancedData = $instanceDetails;
				$thisData = $instanceDetails;
			}
			
			if((!array_key_exists("event_id", $instanceDetails) || $event_id == $instanceDetails["event_id"]) && 
					$instanceDetails["redcap_repeat_instance"] == "") {
				$nonInstancedData = $instanceDetails;
			}
			
			if($instanceDetails["redcap_repeat_instrument"] == $instrument &&
					(($repeat_instance == 1 && (
						$instanceDetails["redcap_repeat_instance"] == 1 ||
						$instanceDetails["redcap_repeat_instance"] == ""
					)) || $repeat_instance == $instanceDetails["redcap_repeat_instance"])) {
				$thisData = $instanceDetails;
			}
		}
		$this->log("debug",["nonInstanceData" => json_encode($nonInstancedData),"instanceData" => json_encode($thisData)]);

		$rdrUrl = $this->getProjectSetting("rdr-urls",$project_id);

		$dataMappingJson = $this->getProjectSetting("rdr-data-mapping-json",$project_id);
		$dataMappingFields = $this->getProjectSetting("rdr-redcap-field-name",$project_id);
		$dataMappingApiFields = $this->getProjectSetting("rdr-redcap-field-name",$project_id);
		$apiRecordFields = $this->getProjectSetting("rdr-endpoint-record",$project_id);
		$apiInstanceFields = $this->getProjectSetting("rdr-endpoint-instance",$project_id);
		$redcapRecordFields = $this->getProjectSetting("rdr-record-field",$project_id);
		$redcapInstanceFields = $this->getProjectSetting("rdr-instance-field",$project_id);
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
						if($this->getMetadata($project_id)[$redcapField]["field_type"] == "checkbox") {
							$value = [];
							foreach($data[$redcapField] as $checkboxRaw => $checkboxChecked) {
								if($checkboxChecked == 1) {
									$value[] = $checkboxRaw;
								}
							}
						}
						else if($this->getMetadata($project_id)[$redcapField]["field_type"] == "yesno") {
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
								$rowData[$redcapField] = $this->getApiValue($dataDetails,$apiField,$this->getMetadata($project_id)[$redcapField]);
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

	## RDR Cron method to pull data in
	public function rdr_pull($debugApi = false,$singleRecord = false) {
		error_log("RDR: Ran pull cron");
		
		if(is_array($debugApi)) {
			## When run from the cron, an array is passed in here
			$debugApi = false;
		}

		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $this->getGoogleClient();

		/** @var GuzzleHttp\ClientInterface $httpClient */
		$httpClient = $client->authorize();

		$projectList = $this->framework->getProjectsWithModuleEnabled();

		## Start by looping through all projects with this module enabled
		foreach($projectList as $projectId) {
			## If a null or empty project ID gets passed in, skip it
			if(!$projectId) {
				continue;
			}

			## Pull the module settings needed for import from this project
			$rdrUrl = $this->getProjectSetting("rdr-urls",$projectId);
			$dataMappingJson = $this->getProjectSetting("rdr-data-mapping-json",$projectId);
			$dataMappingFields = $this->getProjectSetting("rdr-redcap-field-name",$projectId);
			$dataMappingApiFields = $this->getProjectSetting("rdr-redcap-field-name",$projectId);
			$apiRecordFields = $this->getProjectSetting("rdr-endpoint-record",$projectId);
			$apiInstanceFields = $this->getProjectSetting("rdr-endpoint-instance",$projectId);
			$redcapRecordFields = $this->getProjectSetting("rdr-record-field",$projectId);
			$redcapInstanceFields = $this->getProjectSetting("rdr-instance-field",$projectId);
			$dataFormats = $this->getProjectSetting("rdr-data-format",$projectId);
			$testingOnly = $this->getProjectSetting("rdr-test-only",$projectId);
			$dataConnectionTypes = $this->getProjectSetting("rdr-connection-type",$projectId);

			## Loop through each of the URLs this project is pointed to
			foreach($rdrUrl as $urlKey => $thisUrl) {
				## Only processing pull connections here, also skip empty URLs
				if($dataConnectionTypes[$urlKey] != "pull" || empty($thisUrl)) {
					continue;
				}

                $dataMapping = json_decode($dataMappingJson[$urlKey],true);

				## This RDR doesn't have its data mapping set up yet (or it's set up improperly)
				if(count($dataMapping) == 0) {
					continue;
				}
				$firstField = $this->getRecordIdField($projectId);
                $recordField = $redcapRecordFields[$urlKey];
                $instanceField = $redcapInstanceFields[$urlKey];

				## Pull the form name from the first field in the mapping along with the list of existing records
				$fieldNames = array_keys($dataMapping);
				$fieldNames[] = $recordField;
				$fieldNames[] = $firstField;
				$recordList = \REDCap::getData(["project_id" => $projectId,"fields" => $fieldNames, "return_format" => "json"]);

				$recordList = json_decode($recordList,true);

				$maxSnapshot = 0;
				
				$recordIds = [];
				foreach($recordList as $recordRow) {
					if($instanceField) {
						$maxSnapshot = max($maxSnapshot,$recordRow[$instanceField]);
						if($recordRow["redcap_repeat_instance"] === "") {
                            $recordIds[$recordRow[$firstField]]["record"] = $recordRow[$recordField];
                            $recordIds[$recordRow[$firstField]]["record_id"] = $recordRow[$firstField];
                        }
						else {
						    $recordIds[$recordRow[$firstField]]["instance"] = $recordRow[$instanceField];
                            $recordIds[$recordRow[$firstField]]["instance_id"] = $recordRow["redcap_repeat_instance"];
                        }
					}
					else if(!$instanceField) {
						$maxSnapshot = max($maxSnapshot,$recordRow[$recordField]);
						$recordIds[$recordRow[$firstField]] = [
							"record" => $recordRow[$recordField],
							"record_id" => $recordRow[$firstField]
						];
					}
				}

				## Pull the data from the API and then decode it (assuming its JSON for now)
				if($singleRecord) {
					$results = $httpClient->get($thisUrl."?snapshot_id=".$singleRecord);
				}
				else if(count($recordIds) > 0) {
					$results = $httpClient->get($thisUrl."?last_snapshot_id=".$maxSnapshot);
				}
				else {
					$results = $httpClient->get($thisUrl);
				}

				$decodedResults = json_decode($results->getBody()->getContents(),true);

				## Export full API results if trying to debug
				if($debugApi) {
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
					$recordId = $dataKey;
					if($dataFormats[$urlKey] == "flat") {
						$recordId = $dataDetails[$apiRecordFields[$urlKey]];
					}
					
					if($this->getLoggingEnabled()) {
						$this->log("rdr_api_log",[
							"record" => $recordId,
							"project_id" => $projectId,
							"data" => json_encode($dataDetails)
						]);
					}

					## Don't try to import if the record already exists
					## TODO See if we can find a way to update records without making it
					## TODO run so slowly that it can never finish in App Engine (60 second timeout)
					if(array_key_exists($recordId,$recordList)) {
						continue;
					}

					## Start with an empty data set for the record and start trying to pull data from the API array
					$rowData = [$recordField => $recordId,
                        $this->getRecordIdField($projectId) => $recordId];
					foreach($dataMapping as $redcapField => $apiField) {
						$checkboxMatches = [];

						## Check REDCap metadata so that bool and raw data can be mapped properly
						## "___[raw_value]" is used to map checkboxes one value at a time
						if(preg_match("/\\_\\_\\_([0-9a-zA-Z]+$)/",$redcapField,$checkboxMatches)) {
							$rowData[$redcapField] = ($this->getApiValue($dataDetails,$apiField) ? "1" : "0");
						}
						else {
							$rowData[$redcapField] = $this->getApiValue($dataDetails,$apiField,$this->getMetadata($projectId)[$redcapField]);
						}
					}
					
					if($this->getLoggingEnabled()) {
						$this->log("rdr_import_log",[
							"record" => $recordId,
							"project_id" => $projectId,
							"data" => json_encode($rowData)
						]);
					}
					
					if($testingOnly[$urlKey] == "1") {
						if(!$debugApi) {
							echo "<pre>".htmlspecialchars($recordId." => ".var_export($rowData,true))."</pre>";echo "<br />";
						}
					}
					else {
                        $this->saveSnapshotToRecord($projectId, $recordId, $rowData, $instanceField);
					}
				}
			}
		}
	}
	
	public function saveSnapshotToRecord($projectId, $recordId, $rowData, $instanceField) {
		self::checkShutdown();

		$repeatingForms = $this->getRepeatingForms(NULL, $projectId);
		$repeatingData = [];
		$nonRepeatingData = [];
		$nonRepeatingForm = "";

		foreach($rowData as $fieldName => $fieldValue) {
            $checkboxMatches = [];
            $formField = $fieldName;

            ## Check REDCap metadata so that bool and raw data can be mapped properly
            ## "___[raw_value]" is used to map checkboxes one value at a time
            if(preg_match("/\\_\\_\\_([0-9a-zA-Z]+$)/",$fieldName,$checkboxMatches)) {
                $formField = substr($fieldName,0,strlen($checkboxMatches[1]) - strlen($checkboxMatches[0]));
            }

            $formName = $this->getMetadata($projectId)[$formField]["form_name"];
            if(in_array($formName,$repeatingForms)) {
                $repeatingData[$formName][$fieldName] = $fieldValue;
            }
            else {
                $nonRepeatingForm = $formName;
                $nonRepeatingData[$fieldName] = $fieldValue;
            }
        }

		
		## Pull event ID and Arm ID from the \Project object for this project
		$proj = new \Project($projectId);
		$proj->loadEvents();
		$eventId = $proj->firstEventId;
		$armId = $proj->firstArmId;
		
		## Attempt to save the non-repeating data
		$results = $this->saveData($projectId,$recordId,$eventId,$nonRepeatingData);

		if(count($results["errors"]) > 0) {
			error_log("PMI RDR: Couldn't import non-repeating data: ".var_export($results["errors"],true));
		}

		if(count($repeatingData) > 0) {
		    foreach($repeatingData as $formName => $repeatingRow) {
		        $repeatingRow[$this->getRecordIdField($projectId)] = $recordId;

                $results = $this->addOrUpdateInstances([$repeatingRow],$instanceField);

                if(count($results["errors"]) > 0) {
                    error_log("PMI RDR: Couldn't import repeating data: ".var_export($results["errors"],true));
                }
            }
        }

		try {
			## Trigger alerts and notifications
			$eta = new \Alerts();

			$eta->saveRecordAction($projectId,$recordId,$nonRepeatingForm,$eventId);
			foreach($repeatingData as $formName => $repeatingRow) {
                $eta->saveRecordAction($projectId,$recordId,$formName,$eventId);
            }
		}
		## Catch issues with sending alerts
		catch(\Exception $e) {
			error_log("RDRError sending notification email- Project: $projectId - Record: $recordId: ".var_export($e->getMessage(),true));
		}

		## Add to records cache
		\Records::addRecordToRecordListCache($projectId,$recordId,$armId);

		## Define a constant so that this module's own save hook isn't called
		define(self::RECORD_CREATED_BY_MODULE.$projectId."~".$recordId,1);

		## Set the $_GET parameter as auto record generation hook seems to call errors on this (when called by the cron)
		$_GET['pid'] = $projectId;
		$_GET['id'] = $recordId;

		## Prevent module errors from crashing the whole import process
		try {
			ExternalModules::callHook("redcap_save_record",[$projectId,$recordId,$nonRepeatingForm,$eventId,NULL,NULL,NULL]);

            foreach($repeatingData as $formName => $repeatingRow) {
                ExternalModules::callHook("redcap_save_record",[$projectId,$recordId,$formName,$eventId,NULL,NULL,NULL]);
            }
		}
		catch(\Exception $e) {
            $test = \REDCap::email("kyle.mcguffin@vumc.org","","PMI - Error on PMI RDR Module","External Module Error - Project: $projectId - Record: $recordId: ".$e->getMessage());
            error_log("External Module Error - Project: $projectId - Record: $recordId: ".$e->getMessage());
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