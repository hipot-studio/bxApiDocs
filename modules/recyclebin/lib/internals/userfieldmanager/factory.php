<?php

namespace Bitrix\Recyclebin\Internals\UserFieldManager;

class Factory
{
	public static function getManager(array $userField): BaseField
	{
		return match ($userField['USER_TYPE_ID'])
		{
			\Bitrix\Main\UserField\Types\FileType::USER_TYPE_ID => new FileField($userField),
			default => new BaseField($userField),
		};
	}
}
