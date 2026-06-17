<?php

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Main\Loader;

class Exclusion
{
	public static function addEmail(string $email): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		\Bitrix\Crm\Exclusion\Store::add(\Bitrix\Crm\Communication\Type::EMAIL, $email);
	}
}
