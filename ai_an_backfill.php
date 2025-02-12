<?php

use PmiModule\PmiRdrModule\PmiRdrModule;
use Vanderbilt\GSuiteIntegration\GSuiteIntegration;

if (defined("SUPER_USER") && SUPER_USER == 1) {

	/** @var $module PmiRdrModule */
	echo "<form url='".$_SERVER['REQUEST_URI']."' method='POST'>
    <input type='hidden' value='".htmlspecialchars($_GET['prefix'])."' name='prefix' />
    <input type='hidden' value='".htmlspecialchars($_GET['page'])."' name='page' />
    <input type='hidden' value='".htmlspecialchars($_GET['pid'])."' name='pid' />
    <table>
        <tr>
        	<td>Snapshot IDs (line delimited)</td>
        	<td><textarea name='snapshot_ids' id='' cols='30' rows='10'></textarea></td>
        </tr>
        <tr><td><input type='submit' value='Submit' /></td></tr>
    </table></form>";
	if ($_POST['snapshot_ids']) {
		$rdrUrl = $module->getProjectSetting("rdr-urls")[0];
		$snapshotIds =  preg_split("/\r\n|\n|\r/", $_POST['snapshot_ids']);

		if (!empty($snapshotIds)) {
			/** @var GSuiteIntegration $module */
			$client = $module->getGoogleClient();
			/** @var GuzzleHttp\ClientInterface $httpClient */
			$httpClient = $client->authorize();
			$response = [];
			foreach ($snapshotIds as $snapshotId) {
				$returnArr = [
					'snapshot_id' => $snapshotId,
				];
				$url = trim($rdrUrl."?snapshot_id=".$snapshotId);
				$results = $httpClient->get($url);
				$decodedResults = json_decode($results->getBody()->getContents(), true);
				$snapshot = $decodedResults[0];
				$returnArr['aianResearchType'] = $snapshot['aianResearchType'];
				$returnArr['aianResearchDetails'] = $snapshot['aianResearchDetails'];
				$response[] = $returnArr;
			}
			if (count($response) != 0) {
				echo "<table>
						<tr>
							<th>Snapshot ID</th>
							<th>aianResearchType</th>
							<th>aianResearchDetails</th>
						</tr>
						";
				foreach ($response as $snapshot) {
					echo "<tr>";
					echo "<td>".$snapshot['snapshot_id']."</td>";
					echo "<td>".$snapshot['aianResearchType']."</td>";
					echo "<td>".$snapshot['aianResearchDetails']."</td>";
					echo "</tr>";
				}
				echo "</table>";
			}
		}
	}

} else {
	echo '<h1>You do not have permission to access this page</h1>';
}
