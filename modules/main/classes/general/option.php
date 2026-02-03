<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\ArgumentNullException;

/**
 * @deprecated Use Bitrix\Main\Config\Option
 */
class COption
{
	public static function GetOptionString($module_id, $name, $def = "", $site = false, $bExactSite = false)
	{
		$v = null;

		try
		{
			if ($bExactSite)
			{
				$v = Option::getRealValue($module_id, $name, $site);
				return $v === null ? false : $v;
			}

			$v = Option::get($module_id, $name, $def, $site);
		}
		catch (ArgumentNullException)
		{
		}

		return $v;
	}

	public static function SetOptionString($module_id, $name, $value = "", $desc = false, $site = "")
	{
		Option::set($module_id, $name, $value, $site);
		return true;
	}

	public static function RemoveOption($module_id, $name = "", $site = false)
	{
		$filter = [];
		if ($name <> '')
		{
			$filter["name"] = $name;
		}
		if ($site <> '')
		{
			$filter["site_id"] = $site;
		}
		Option::delete($module_id, $filter);
	}

	public static function GetOptionInt($module_id, $name, $def = "", $site = false)
	{
		return intval(self::GetOptionString($module_id, $name, $def, $site));
	}

	public static function SetOptionInt($module_id, $name, $value = "", $desc = "", $site = "")
	{
		return self::SetOptionString($module_id, $name, intval($value), $desc, $site);
	}
}
