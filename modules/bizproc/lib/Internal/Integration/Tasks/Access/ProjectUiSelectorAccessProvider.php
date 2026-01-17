<?php

namespace Bitrix\Bizproc\Internal\Integration\Tasks\Access;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\ProjectProvider;
use Bitrix\UI\EntitySelector\Item;

/**
 * Checks access to projects available to select in UI selector of projects
 */
class ProjectUiSelectorAccessProvider
{
	/**
	 * @param int $userId
	 * @param list<int> $projectIds
	 *
	 * @return Result
	 */
	public function isUserHasAccess(int $userId, array $projectIds): Result
	{
		if (empty($projectIds))
		{
			return Result::createOk();
		}

		if (!Loader::includeModule('ui'))
		{
			return Result::createFromErrorCode(Error::MODULE_NOT_INSTALLED, ['moduleName' => 'ui']);
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return Result::createFromErrorCode(Error::MODULE_NOT_INSTALLED, ['moduleName' => 'socialnetwork']);
		}

		$provider = new ProjectProvider(['currentUserId' => $userId]);
		$items = $provider->getItems($projectIds);
		$allowedToSelectIds = array_map(fn(Item $item) => $item->getId(), $items);
		$deniedIds = array_diff($projectIds, $allowedToSelectIds);
		if (count($deniedIds) > 0)
		{
			$message = Loc::getMessage('BIZPROC_INTERNAL_INTEGRATION_TASKS_ACCESS_UI_PROVIDER_ERROR');

			return Result::createError(new Error($message));
		}

		return Result::createOk();
	}
}