<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

error_reporting(E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE);

define("START_EXEC_TIME", microtime(true));

if (!defined('B_PROLOG_INCLUDED'))
{
	define("B_PROLOG_INCLUDED", true);
}

require_once __DIR__ . '/bx_root.php';
require_once __DIR__ . '/lib/loader.php';
require_once __DIR__ . '/include/autoload.php';
require_once __DIR__ . '/classes/general/version.php';

// global functions
require_once __DIR__ . '/tools.php';

/**
 * @todo Get rid of
 */
FormDecode();

$application = \Bitrix\Main\HttpApplication::getInstance();

// Defined in dbconn.php
global $DBType, $DBDebug, $DBDebugToFile, $DBHost, $DBName, $DBLogin, $DBPassword;

// read various parameters
require_once $_SERVER["DOCUMENT_ROOT"] . getLocalPath('php_interface/dbconn.php', BX_PERSONAL_ROOT);

// not used anymore
$DBType = 'mysql';
$DBHost = '';
$DBLogin = '';
$DBPassword = '';
$DBName = '';

// need to be after dbconn.php
require_once __DIR__ . '/include/constants.php';

// Database-dependent classes
CAllDatabase::registerAutoload();

// From here global variable $DB is available (CDatabase class)
$GLOBALS['DB'] = new CDatabase();

$GLOBALS['DB']->debug = $DBDebug;
if ($DBDebugToFile)
{
	$GLOBALS['DB']->DebugToFile = true;
	$application->getConnection()->startTracker()->startFileLog($_SERVER['DOCUMENT_ROOT'] . '/mysql_debug.sql');
}

//magic parameters: show sql queries statistics
$show_sql_stat = '';
if (isset($_GET['show_sql_stat']))
{
	$show_sql_stat = ($_GET['show_sql_stat'] == 'Y' ? 'Y' : '');
	setcookie('show_sql_stat', $show_sql_stat, false, '/');
}
elseif (isset($_COOKIE['show_sql_stat']) && $_COOKIE['show_sql_stat'] == 'Y')
{
	$show_sql_stat = 'Y';
}
if ($show_sql_stat == 'Y')
{
	$GLOBALS['DB']->ShowSqlStat = true;
	$application->getConnection()->startTracker();
}

/**
 * License key.
 * @deprecated Use $application->getLicense()->getKey().
 */
define('LICENSE_KEY', $application->getLicense()->getKey());

/** @todo Change globals to getInstance() */
$GLOBALS['CACHE_STAT_BYTES'] = 0;
$GLOBALS['CACHE_MANAGER'] = new CCacheManager;
$GLOBALS['stackCacheManager'] = new CStackCacheManager();

if (file_exists(($fname = __DIR__ . '/classes/general/update_db_updater.php')))
{
	$US_HOST_PROCESS_MAIN = true;
	include $fname;
}
