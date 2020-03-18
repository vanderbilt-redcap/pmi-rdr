<?php
namespace PmiModule\PmiRdrModule;

use ExternalModules\ExternalModules;
use Google\Cloud\Datastore\DatastoreClient;

class PmiRdrModule extends \ExternalModules\AbstractExternalModule {
	public $client;
	public $credentials;

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
			if($_SERVER['APPLICATION_ID']) {
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
		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $module->getGoogleClient();

		/** @var GuzzleHttp\ClientInterface $httpClient */
		$httpClient = $client->authorize();

		$data = $this->getData($project_id,$record);


	}

	## RDR Cron method to pull data in
	public function rdr_pull() {
		/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
		$client = $this->getGoogleClient();

		/** @var GuzzleHttp\ClientInterface $httpClient */
		$httpClient = $client->authorize();

		$projectQuery = ExternalModules::getEnabledProjects($this->PREFIX);
		$projectList = [];

		while($row = $projectQuery->fetch_assoc()) {
			$projectList[] = $row['project_id'];
		}
		foreach($projectList as $projectId) {
			$rdrUrl = $this->getProjectSetting("rdr-pull-url",$projectId);
			foreach($rdrUrl as $urlKey => $thisUrl) {
				$results = $httpClient->get($thisUrl);

				$decodedResults = json_decode($results->getBody()->getContents());

				echo "<pre>";var_dump($decodedResults);echo "</pre>";echo "<br />";
			}
		}
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
}