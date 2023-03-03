<?php
/**
 * Created by PhpStorm.
 * User: mcguffk
 * Date: 3/18/2020
 * Time: 10:51 AM
 */

/** @var $module \PmiModule\PmiRdrModule\PmiRdrModule */
if(defined("SUPER_USER") && SUPER_USER == 1) {
	if($_GET["phpinfo"] == 1) {
		phpinfo();
	}
	/** @var $module \PmiModule\PmiRdrModule\PmiRdrModule */
	echo "<form url='".$_SERVER['REQUEST_URI']."' method='GET'>
	<input type='hidden' value='".htmlspecialchars($_GET['prefix'])."' name='prefix' />
	<input type='hidden' value='".htmlspecialchars($_GET['page'])."' name='page' />
	<input type='hidden' value='".htmlspecialchars($_GET['pid'])."' name='pid' />
	<table>
		<tr><td>Workspace to pull:</td><td><input type='text' data-lpignore='true' value='".htmlspecialchars($_GET['pull_record'])."' name='pull_record' /></td></tr>
		<tr><td>Check to run the cron:</td><td><input type='checkbox' data-lpignore='true' value='1' ".(empty($_GET['run_cron']) ? "" : "checked")." name='run_cron' /></td></tr>
		<tr><td><input type='submit' value='Submit' /></td></tr>
	</table></form>";
	
	if($_GET['pull_record']) {
		$module->rdr_pull($_GET['debug'] == 1,$_GET["pull_record"]);
	}
	
	if($_GET['run_cron']) {
		$module->rdr_pull($_GET['debug'] == 1);
	}
}