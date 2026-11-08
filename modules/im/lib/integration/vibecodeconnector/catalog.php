<?php

declare(strict_types=1);

namespace Bitrix\Im\Integration\Vibecodeconnector;

use Bitrix\Im\Common;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

class Catalog
{
	public static function isAvailableForUser(?int $userId = null): bool
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return false;
		}

		if (!class_exists(AvailabilityService::class))
		{
			// TODO: remove this legacy fallback once vibecodeconnector ships the availability predicate everywhere.
			return Option::get('vibecodeconnector', 'is_ready', 'N') === 'Y';
		}

		$userId = Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		return (new AvailabilityService())->isAvailableForUser((int)$userId);
	}
}
