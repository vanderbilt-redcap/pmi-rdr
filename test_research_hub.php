<?php


/** @var \Vanderbilt\GSuiteIntegration\GSuiteIntegration $module */
$client = $module->getGoogleClient();

/** @var GuzzleHttp\ClientInterface $httpClient */
$httpClient = $client->authorize();


$results = $httpClient->request(
	'GET',
	"https://all-of-us-rdr-prod.appspot.com/rdr/v1/researchHub/projectDirectory",
	['query' => [
		'status' => 'ACTIVE',
		'page' => 1,
		'pageSize' => 1500
	]]
);


$cachedDirectoryData = (string) $results->getBody();
$cachedDirectoryData = json_decode($cachedDirectoryData,true);

$csvHeaders = [
  "workspaceId",
  "snapshotId",
  "name",
  "creationTime",
  "modifiedTime",
  "status",
  "workspaceUsers",
  "workspaceOwner",
  "hasVerifiedInstitution",
  "excludeFromPublicDirectory",
  "ethicalLegalSocialImplications",
  "reviewRequested",
  "diseaseFocusedResearch",
  "diseaseFocusedResearchName",
  "otherPurposeDetails",
  "methodsDevelopment",
  "controlSet",
  "ancestry",
  "accessTier",
  "socialBehavioral",
  "populationHealth",
  "drugDevelopment",
  "commercialPurpose",
  "educational",
  "otherPurpose",
  "scientificApproaches",
  "intendToStudy",
  "findingsFromStudy",
  "focusOnUnderrepresentedPopulations",
  "workspaceDemographic",
  "cdrVersion"
];
header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=file.csv");

$f = fopen("php://output","w");

fputcsv($f,$csvHeaders);

foreach($cachedDirectoryData["data"] as $thisWorkspace) {
	$output = [];
	foreach($csvHeaders as $thisHeader) {
		if(is_array($thisWorkspace[$thisHeader])) {
			$output[] = json_encode($thisWorkspace[$thisHeader]);
		}
		else {
			$output[] = $thisWorkspace[$thisHeader];
		}
	}
	fputcsv($f,$output);
}
