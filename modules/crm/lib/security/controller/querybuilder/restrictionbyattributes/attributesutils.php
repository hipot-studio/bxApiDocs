<?php

namespace Bitrix\Crm\Security\Controller\QueryBuilder\RestrictionByAttributes;

use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\Main\Loader;

final class AttributesUtils
{
	protected static string $userRegex = '/^U(\d+)$/i';

	protected static string $intranetDepartmentRegex = '/^D(\d+)$/i';

	public static function tryParseUser($attribute, &$value): bool
	{
		return self::tryParseAttributeValue($attribute, self::$userRegex, $value);
	}

	public static function tryParseIntranetDepartment($attribute, &$value): bool
	{
		return self::tryParseAttributeValue($attribute, self::$intranetDepartmentRegex, $value);
	}

	public static function tryParseHrDepartment($attribute, &$value): bool
	{
		Loader::requireModule('humanresources');
		$preparedHrDepartment = preg_quote(AccessCodeType::HrDepartmentType->value, '/');
		$hrDepartmentRegex = '/^'.$preparedHrDepartment.'(\d+)$/i'; // /^SND(\d+)$/i

		return self::tryParseAttributeValue($attribute, $hrDepartmentRegex, $value);
	}

	public static function tryParseHrTeam($attribute, &$value): bool
	{
		Loader::requireModule('humanresources');
		$preparedHrTeam = preg_quote(AccessCodeType::HrTeamType->value, '/');
		$hrTeamRegex = '/^'.$preparedHrTeam.'(\d+)$/i'; // /^SNT(\d+)$/i

		return self::tryParseAttributeValue($attribute, $hrTeamRegex, $value);
	}

	private static function tryParseAttributeValue($attribute, $regex, &$value): bool
	{
		if (preg_match($regex, $attribute, $m) !== 1)
		{
			return false;
		}

		$value = $m[1] ?? '';

		return true;
	}
}
