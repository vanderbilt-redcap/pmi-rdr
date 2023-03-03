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
	else if($_GET["pull_record"]) {
		$module->rdr_pull($_GET['debug'] == 1,$_GET["pull_record"]);
	}
	else {
		ini_set("default_socket_timeout", 240);
		$module->rdr_pull($_GET['debug'] == 1);
	}
}