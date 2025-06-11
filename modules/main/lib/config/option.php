<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

namespace Bitrix\Main\Config;

use Bitrix\Main;

class Option
{
	protected const CACHE_DIR = "b_option";

	protected static $options = [];
	protected static $loading = [];

	/**
	 * Returns a value of an option.
	 *
	 * @param string $moduleId The module ID.
	 * @param string $name The option name.
	 * @param string $default The default value to return, if a value doesn't exist.
	 * @param bool|string $siteId The site ID, if the option differs for sites.
	 * @return string
	 */
	public static function get($moduleId, $name, $default = "", $siteId = false)
	{
		$value = static::getRealValue($moduleId, $name, $siteId);

		if ($value !== null)
		{
			return $value;
		}

		if (isset(self::$options[$moduleId]["-"][$name]))
		{
			return self::$options[$moduleId]["-"][$name];
		}

		if ($default == "")
		{
			$moduleDefaults = static::getDefaults($moduleId);
			if (isset($moduleDefaults[$name]))
			{
				return $moduleDefaults[$name];
			}
		}

		return $default;
	}

	/**
	 * Returns the real value of an option as it's written in a DB.
	 *
	 * @param string $moduleId The module ID.
	 * @param string $name The option name.
	 * @param bool|string $siteId The site ID.
	 * @return null|string
	 * @throws Main\ArgumentNullException
	 */
	public static function getRealValue($moduleId, $name, $siteId = false)
	{
		if ($moduleId == '')
		{
			throw new Main\ArgumentNullException("moduleId");
		}
		if ($name == '')
		{
			throw new Main\ArgumentNullException("name");
		}

		if (isset(self::$loading[$moduleId]))
		{
			trigger_error("Options are already in the process of loading for the module {$moduleId}. Default value will be used for the option {$name}.", E_USER_WARNING);
		}

		if (!isset(self::$options[$moduleId]))
		{
			static::load($moduleId);
		}

		if ($siteId === false)
		{
			$siteId = static::getDefaultSite();
		}

		$siteKey = ($siteId == ""? "-" : $siteId);

		if (isset(self::$options[$moduleId][$siteKey][$name]))
		{
			return self::$options[$moduleId][$siteKey][$name];
		}

		return null;
	}

	/**
	 * Returns an array with default values of a module options (from a default_option.php file).
	 *
	 * @param string $moduleId The module ID.
	 * @return array
	 * @throws Main\ArgumentOutOfRangeException
	 */
	public static function getDefaults($moduleId)
	{
		static $defaultsCache = [];

		if (isset($defaultsCache[$moduleId]))
		{
			return $defaultsCache[$moduleId];
		}

		if (preg_match("#[^a-zA-Z0-9._]#", $moduleId))
		{
			throw new Main\ArgumentOutOfRangeException("moduleId");
		}

		$path = Main\Loader::getLocal("modules/".$moduleId."/default_option.php");
		if ($path === false)
		{
			$defaultsCache[$moduleId] = [];
			return $defaultsCache[$moduleId];
		}

		include($path);

		$varName = str_replace(".", "_", $moduleId)."_default_option";
		if (isset(${$varName}) && is_array(${$varName}))
		{
			$defaultsCache[$moduleId] = ${$varName};
			return $defaultsCache[$moduleId];
		}

		$defaultsCache[$moduleId] = [];
		return $defaultsCache[$moduleId];
	}

	/**
	 * Returns an array of set options array(name => value).
	 *
	 * @param string $moduleId The module ID.
	 * @param bool|string $siteId The site ID, if the option differs for sites.
	 * @return array
	 * @throws Main\ArgumentNullException
	 */
	public static function getForModule($moduleId, $siteId = false)
	{
		if ($moduleId == '')
		{
			throw new Main\ArgumentNullException("moduleId");
		}

		if (!isset(self::$options[$moduleId]))
		{
			static::load($moduleId);
		}

		if ($siteId === false)
		{
			$siteId = static::getDefaultSite();
		}

		$result = self::$options[$moduleId]["-"];

		if($siteId <> "" && !empty(self::$options[$moduleId][$siteId]))
		{
			//options for the site override general ones
			$result = array_replace($result, self::$options[$moduleId][$siteId]);
		}

		return $result;
	}

	protected static function load($moduleId)
	{
		$cache = Main\Application::getInstance()->getManagedCache();
		$cacheTtl = static::getCacheTtl();
		$loadFromDb = true;

		if ($cacheTtl !== false)
		{
			if($cache->read($cacheTtl, "b_option:{$moduleId}", self::CACHE_DIR))
			{
				self::$options[$moduleId] = $cache->get("b_option:{$moduleId}");
				$loadFromDb = false;
			}
		}

		if($loadFromDb)
		{
			self::$loading[$moduleId] = true;

			$con = Main\Application::getConnection();
			$sqlHelper = $con->getSqlHelper();

			// prevents recursion and cache miss
			self::$options[$moduleId] = ["-" => []];

			// prevents recursion on early stages with cluster module installed
			$pool = Main\Application::getInstance()->getConnectionPool();
			$pool->useMasterOnly(true);

			$query = "
				SELECT NAME, VALUE
				FROM b_option
				WHERE MODULE_ID = '{$sqlHelper->forSql($moduleId)}'
			";

			$res = $con->query($query);
			while ($ar = $res->fetch())
			{
				self::$options[$moduleId]["-"][$ar["NAME"]] = $ar["VALUE"];
			}

			try
			{
				//b_option_site possibly doesn't exist

				$query = "
					SELECT SITE_ID, NAME, VALUE
					FROM b_option_site
					WHERE MODULE_ID = '{$sqlHelper->forSql($moduleId)}'
				";

				$res = $con->query($query);
				while ($ar = $res->fetch())
				{
					self::$options[$moduleId][$ar["SITE_ID"]][$ar["NAME"]] = $ar["VALUE"];
				}
			}
			catch(Main\DB\SqlQueryException)
			{
			}

			$pool->useMasterOnly(false);

			if($cacheTtl !== false)
			{
				$cache->setImmediate("b_option:{$moduleId}", self::$options[$moduleId]);
			}

			unset(self::$loading[$moduleId]);
		}

		/*ZDUyZmZNDE4MjQxNWI5ZTI2MTExNjBjMzE3MTkyMDMxMTRhYzU=*/$GLOBALS['____617209504']= array(base64_decode('ZXh'.'w'.'bG9kZQ=='),base64_decode('cG'.'Fjaw=='),base64_decode('bWQ'.'1'),base64_decode('Y29uc3Rh'.'bnQ='),base64_decode('aGF'.'z'.'a'.'F9'.'obWFj'),base64_decode('c3R'.'yY2'.'1w'),base64_decode('a'.'XN'.'fb2JqZWN'.'0'),base64_decode('Y2'.'FsbF9'.'1c2VyX2'.'Z1bmM='),base64_decode('Y'.'2FsbF91'.'c2'.'VyX2Z1bmM='),base64_decode('Y'.'2FsbF'.'91c2VyX'.'2Z'.'1bmM'.'='),base64_decode('Y2F'.'sbF91c2VyX2Z1'.'bmM'.'='));if(!function_exists(__NAMESPACE__.'\\___1468234678')){function ___1468234678($_1724431528){static $_1180770701= false; if($_1180770701 == false) $_1180770701=array('b'.'W'.'Fpbg==','bWFpbg==',''.'LQ==','f'.'lBBUk'.'FNX01B'.'WF9VU0VSUw==',''.'bW'.'F'.'pbg'.'==','LQ==',''.'flBB'.'Uk'.'FNX01BWF9VU0VSUw='.'=','Lg='.'=','SCo=','Yml0cml4',''.'TEl'.'DRU5TRV'.'9L'.'RV'.'k=',''.'c2hhMjU2','b'.'WFpbg==',''.'LQ==','UEF'.'S'.'QU1'.'fT'.'UFYX'.'1VT'.'RVJT','V'.'VNFU'.'g==','VVNFUg==','VVNFUg==','SXNBdXRob3Jpem'.'Vk','V'.'VNFUg==','SXN'.'B'.'Z'.'G1pb'.'g='.'=','QVBQTElD'.'QVRJT04=','UmVzdGFydEJ1Z'.'mZ'.'lcg==','TG9jYWx'.'SZ'.'WRp'.'c'.'mVjdA='.'=','L2xp'.'Y2V'.'uc2Vfc'.'mVz'.'dHJ'.'p'.'Y3R'.'pb24ucG'.'h'.'w',''.'b'.'WFpb'.'g==','L'.'Q'.'==','UEFSQU1'.'fTUFYX1V'.'TRVJT','bWFpbg==','LQ='.'=','UEFSQU1f'.'TUF'.'YX1VTRVJT');return base64_decode($_1180770701[$_1724431528]);}};if($moduleId === ___1468234678(0)){ if(isset(self::$options[___1468234678(1)][___1468234678(2)][___1468234678(3)])){ $_2112962381= self::$options[___1468234678(4)][___1468234678(5)][___1468234678(6)]; list($_401096527, $_1102859493)= $GLOBALS['____617209504'][0](___1468234678(7), $_2112962381); $_1187187664= $GLOBALS['____617209504'][1](___1468234678(8), $_401096527); $_23790560= ___1468234678(9).$GLOBALS['____617209504'][2]($GLOBALS['____617209504'][3](___1468234678(10))); $_12000916= $GLOBALS['____617209504'][4](___1468234678(11), $_1102859493, $_23790560, true); if($GLOBALS['____617209504'][5]($_12000916, $_1187187664) !== min(36,0,12)){ self::$options[___1468234678(12)][___1468234678(13)][___1468234678(14)]= round(0+12); if(isset($GLOBALS[___1468234678(15)]) && $GLOBALS['____617209504'][6]($GLOBALS[___1468234678(16)]) && $GLOBALS['____617209504'][7](array($GLOBALS[___1468234678(17)], ___1468234678(18))) &&!$GLOBALS['____617209504'][8](array($GLOBALS[___1468234678(19)], ___1468234678(20)))){ $GLOBALS['____617209504'][9](array($GLOBALS[___1468234678(21)], ___1468234678(22))); $GLOBALS['____617209504'][10](___1468234678(23), ___1468234678(24), true);}} else{ self::$options[___1468234678(25)][___1468234678(26)][___1468234678(27)]= $_1102859493;}} else{ self::$options[___1468234678(28)][___1468234678(29)][___1468234678(30)]= round(0+4+4+4);}}/**/
	}

	/**
	 * Sets an option value and saves it into a DB. After saving the OnAfterSetOption event is triggered.
	 *
	 * @param string $moduleId The module ID.
	 * @param string $name The option name.
	 * @param string $value The option value.
	 * @param string $siteId The site ID, if the option depends on a site.
	 * @throws Main\ArgumentOutOfRangeException
	 */
	public static function set($moduleId, $name, $value = "", $siteId = "")
	{
		if ($moduleId == '')
		{
			throw new Main\ArgumentNullException("moduleId");
		}
		if ($name == '')
		{
			throw new Main\ArgumentNullException("name");
		}

		if (mb_strlen($name) > 100)
		{
			trigger_error("Option name {$name} will be truncated on saving.", E_USER_WARNING);
		}

		if ($siteId === false)
		{
			$siteId = static::getDefaultSite();
		}

		$con = Main\Application::getConnection();
		$sqlHelper = $con->getSqlHelper();

		$updateFields = [
			"VALUE" => $value,
		];

		if($siteId == "")
		{
			$insertFields = [
				"MODULE_ID" => $moduleId,
				"NAME" => $name,
				"VALUE" => $value,
			];

			$keyFields = ["MODULE_ID", "NAME"];

			$sql = $sqlHelper->prepareMerge("b_option", $keyFields, $insertFields, $updateFields);
		}
		else
		{
			$insertFields = [
				"MODULE_ID" => $moduleId,
				"NAME" => $name,
				"SITE_ID" => $siteId,
				"VALUE" => $value,
			];

			$keyFields = ["MODULE_ID", "NAME", "SITE_ID"];

			$sql = $sqlHelper->prepareMerge("b_option_site", $keyFields, $insertFields, $updateFields);
		}

		$con->queryExecute(current($sql));

		static::clearCache($moduleId);

		static::loadTriggers($moduleId);

		$event = new Main\Event(
			"main",
			"OnAfterSetOption_".$name,
			array("value" => $value)
		);
		$event->send();

		$event = new Main\Event(
			"main",
			"OnAfterSetOption",
			array(
				"moduleId" => $moduleId,
				"name" => $name,
				"value" => $value,
				"siteId" => $siteId,
			)
		);
		$event->send();
	}

	protected static function loadTriggers($moduleId)
	{
		static $triggersCache = [];

		if (isset($triggersCache[$moduleId]))
		{
			return;
		}

		if (preg_match("#[^a-zA-Z0-9._]#", $moduleId))
		{
			throw new Main\ArgumentOutOfRangeException("moduleId");
		}

		$triggersCache[$moduleId] = true;

		$path = Main\Loader::getLocal("modules/".$moduleId."/option_triggers.php");
		if ($path === false)
		{
			return;
		}

		include($path);
	}

	protected static function getCacheTtl()
	{
		static $cacheTtl = null;

		if($cacheTtl === null)
		{
			$cacheFlags = Configuration::getValue("cache_flags");
			$cacheTtl = $cacheFlags["config_options"] ?? 3600;
		}
		return $cacheTtl;
	}

	/**
	 * Deletes options from a DB.
	 *
	 * @param string $moduleId The module ID.
	 * @param array $filter {name: string, site_id: string} The array with filter keys:
	 * 		name - the name of the option;
	 * 		site_id - the site ID (can be empty).
	 * @throws Main\ArgumentNullException
	 * @throws Main\ArgumentException
	 */
	public static function delete($moduleId, array $filter = array())
	{
		if ($moduleId == '')
		{
			throw new Main\ArgumentNullException("moduleId");
		}

		$con = Main\Application::getConnection();
		$sqlHelper = $con->getSqlHelper();

		$deleteForSites = true;
		$sqlWhere = '';
		$sqlWhereSite = '';

		foreach ($filter as $field => $value)
		{
			switch ($field)
			{
				case "name":
					if ($value == '')
					{
						throw new Main\ArgumentNullException("filter[name]");
					}
					$sqlWhere .= " AND NAME = '{$sqlHelper->forSql($value)}'";
					break;

				case "site_id":
					if ($value != '')
					{
						$sqlWhereSite = " AND SITE_ID = '{$sqlHelper->forSql($value, 2)}'";
					}
					else
					{
						$deleteForSites = false;
					}
					break;

				default:
					throw new Main\ArgumentException("filter[{$field}]");
			}
		}

		if($moduleId == 'main')
		{
			$sqlWhere .= "
				AND NAME NOT LIKE '~%'
				AND NAME NOT IN ('crc_code', 'admin_passwordh', 'server_uniq_id','PARAM_MAX_SITES', 'PARAM_MAX_USERS')
			";
		}
		else
		{
			$sqlWhere .= " AND NAME <> '~bsm_stop_date'";
		}

		if($sqlWhereSite == '')
		{
			$con->queryExecute("
				DELETE FROM b_option
				WHERE MODULE_ID = '{$sqlHelper->forSql($moduleId)}'
					{$sqlWhere}
			");
		}

		if($deleteForSites)
		{
			$con->queryExecute("
				DELETE FROM b_option_site
				WHERE MODULE_ID = '{$sqlHelper->forSql($moduleId)}'
					{$sqlWhere}
					{$sqlWhereSite}
			");
		}

		static::clearCache($moduleId);
	}

	protected static function clearCache($moduleId)
	{
		unset(self::$options[$moduleId]);

		if (static::getCacheTtl() !== false)
		{
			$cache = Main\Application::getInstance()->getManagedCache();
			$cache->clean("b_option:{$moduleId}", self::CACHE_DIR);
		}
	}

	protected static function getDefaultSite()
	{
		static $defaultSite;

		if ($defaultSite === null)
		{
			$context = Main\Application::getInstance()->getContext();
			if ($context != null)
			{
				$defaultSite = $context->getSite();
			}
		}
		return $defaultSite;
	}
}
