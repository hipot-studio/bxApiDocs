<?php

namespace Bitrix\Market\Application;

use Bitrix\Market\Internal\Integration\Rest;

class Installed
{
	public static function getByCode(string $appCode): array
	{
		return (new Rest\ApplicationProvider())->findByCode($appCode);
	}
}
