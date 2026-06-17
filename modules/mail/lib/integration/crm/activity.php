<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class Activity
{
	public static function getShareableActivityUrl(int $activityId, int $chatId): ?string
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		return Container::getInstance()
			->getRouter()
			->getActivityDetailsShareableUrl($activityId, $chatId)
			?->getUri()
		;
	}
}
