<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\SocialNetwork\Url;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url\WorkgroupViewUrlProvider;

final class WorkgroupUriProvider
{
	public function __construct(
		private readonly string $pathToGroup,
		private readonly string $pathToGroupTasks,
	)
	{
	}

	/**
	 * @throws LoaderException
	 */
	public function getUri(int $groupId, ?string $groupType = null): string
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return '';
		}

		return (new WorkgroupViewUrlProvider(
			mode: WorkgroupList::MODE_TASKS_PROJECT,
			pathToGroup: $this->pathToGroup,
			pathToGroupTasks: $this->pathToGroupTasks,
		))->getUrl($groupId, $groupType);
	}
}
