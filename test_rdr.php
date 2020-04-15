<?php
/**
 * Created by PhpStorm.
 * User: mcguffk
 * Date: 3/18/2020
 * Time: 10:51 AM
 */

/** @var $module \PmiModule\PmiRdrModule\PmiRdrModule */
if(defined("SUPER_USER") && SUPER_USER == 1) {
	$data = $module->rdr_pull($_GET['debug'] == 1);
}