<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Url;

use Bitrix\Main\Web\Uri;
use Bitrix\Socialnetwork\Component\WorkgroupList;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Site\GroupUrl;
use Closure;

final class WorkgroupViewUrlProvider
{
	private const GROUP_ID_TOKENS = ['#id#', '#ID#', '#GROUP_ID#', '#group_id#'];

	private Closure $collabUrlResolver;

	public function __construct(
		private readonly string $mode,
		private readonly string $pathToGroup,
		private readonly string $pathToGroupTasks,
		?Closure $collabUrlResolver = null,
	)
	{
		$this->collabUrlResolver = $collabUrlResolver
			?? static fn(int $groupId): string => GroupUrl::get($groupId, Type::Collab->value);
	}

	public function getUrl(int $groupId, ?string $groupType = null): string
	{
		if ($groupId <= 0)
		{
			return '';
		}

		if ($groupType === Type::Collab->value)
		{
			$collabUrl = ($this->collabUrlResolver)($groupId);
			if ($collabUrl !== '')
			{
				return $collabUrl;
			}
		}

		$url = str_replace(
			search: self::GROUP_ID_TOKENS,
			replace: (string)$groupId,
			subject: $this->getPath($groupType),
		);
		if ($url === '' || $groupType !== Type::Scrum->value)
		{
			return $url;
		}

		return (new Uri($url))
			->addParams(['scrum' => 'Y'])
			->getUri();
	}

	private function getPath(?string $groupType = null): string
	{
		return (
			$groupType === Type::Scrum->value
			&& $this->isTasksMode()
		)
			? $this->pathToGroupTasks
			: $this->getGroupPath();
	}

	private function getGroupPath(): string
	{
		if ($this->pathToGroup !== '')
		{
			return $this->pathToGroup;
		}

		if ($this->pathToGroupTasks === '')
		{
			return '';
		}

		$groupPath = preg_replace(
			pattern: '~/tasks/?(?:\?.*)?$~',
			replacement: '/',
			subject: $this->pathToGroupTasks,
		);

		return (
			is_string($groupPath)
			&& $groupPath !== $this->pathToGroupTasks
		)
			? $groupPath
			: '';
	}

	private function isTasksMode(): bool
	{
		return in_array($this->mode, WorkgroupList::getTasksModeList(), true);
	}
}
