<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service;

use Bitrix\Tasks\V2\Internal\DI\Container;
use COption;

class MartaService
{
	public function getTasksListLink(int $userId): string
	{
		$portalUrl = Container::getInstance()->getUrlService()->getHostUrl();

		$tasksUrl = str_replace(
			['#user_id#', '#USER_ID#'],
			[$userId, $userId],
			COption::GetOptionString(
				'tasks',
				'paths_task_user',
				'/company/personal/user/#user_id#/tasks/',
				SITE_ID
			)
		);

		return $portalUrl . $tasksUrl;
	}
}
