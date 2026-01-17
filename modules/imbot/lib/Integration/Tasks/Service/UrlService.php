<?php

declare(strict_types=1);

namespace Bitrix\Imbot\Integration\Tasks\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Public\Service\MartaService;
use CTaskCountersNotifier;

class UrlService
{
	public function getTasksListLink(int $userId): string
	{
		if (!Loader::includeModule('tasks'))
		{
			return '';
		}

		if (class_exists(MartaService::class))
		{
			return (new MartaService())->getTasksListLink($userId);
		}

		return CTaskCountersNotifier::getTasksListLink($userId);
	}
}
