<?php

namespace Bitrix\Rest\V3\Dto;

class UserFieldTypeFactory
{
	protected static array $baseTypes = [
		'int' => 'int',
		'file' => 'string',
		'enum' => 'string',
		'double' => 'float',
		'datetime' => 'datetime',
		'string' => 'string',
	];

	private static array $userTypes = [];

	public static function getFromBitrixType(string $bitrixType): string
	{
		if (!isset(self::$userTypes[$bitrixType]))
		{
			global $USER_FIELD_MANAGER;
			$userFieldType = $USER_FIELD_MANAGER->GetUserType($bitrixType);
			if (!empty($userFieldType))
			{
				self::$userTypes[$bitrixType] = $userFieldType;
			}
		}

		$userType = self::$userTypes[$bitrixType];

		return self::$baseTypes[$userType['BASE_TYPE']] ?: 'string';

	}
}
