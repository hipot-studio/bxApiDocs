<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

use Bitrix\Main\Authentication\Policy;

class CCheckListTools
{
	public static function __scandir($pwd, &$arFiles, $arExcept = [])
	{
		if (file_exists($pwd))
		{
			$dir = scandir($pwd);
			foreach ($dir as $file)
			{
				if ($file == ".." || $file == ".")
				{
					continue;
				}
				if (is_dir($pwd . $file))
				{
					if (isset($arExcept["FOLDERS"]))
					{
						if (in_array($pwd . $file, $arExcept["FOLDERS"]) || in_array($file, $arExcept["FOLDERS"]))
						{
							continue;
						}
					}
					$curDir = basename($pwd);
					if (isset($arExcept["SUBDIR"][$curDir]) && !in_array($file, $arExcept["SUBDIR"][$curDir]))
					{
						continue;
					}
					static::__scandir($pwd . $file . "/", $arFiles, $arExcept);
				}
				else
				{
					if (isset($arExcept["EXT"]) && !in_array(substr(strrchr($file, '.'), 1), $arExcept["EXT"]))
					{
						continue;
					}
					if (isset($arExcept["FILES"]))
					{
						if (in_array($pwd . $file, $arExcept["FILES"]) || in_array($file, $arExcept["FILES"]))
						{
							continue;
						}
					}
					$arFiles[] = $pwd . $file;
				}
			}
		}
	}

	/**
	 * @return string 'low', 'middle', 'high'
	 */
	public static function AdminPolicyLevel()
	{
		$policy = CUser::getPolicy(1);

		$preset = Policy\RulesCollection::createByPreset(Policy\RulesCollection::PRESET_MIDDLE);
		if ($policy->compare($preset))
		{
			// middle preset is stronger than the current
			return 'low';
		}

		$preset = Policy\RulesCollection::createByPreset(Policy\RulesCollection::PRESET_HIGH);
		if ($policy->compare($preset))
		{
			// high preset is stronger than the current
			return 'middle';
		}

		return 'high';
	}
}
