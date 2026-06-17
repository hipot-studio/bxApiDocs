<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Service;

use Bitrix\Intranet\Internal\Integration\Calendar\EventProvider;
use Bitrix\Intranet\Internal\Integration\Socialnetwork;

class CalendarParentChatResolver
{
	public function __construct(
		private readonly EventProvider $eventProvider,
		private readonly Socialnetwork\ProjectChatProvider $projectChatProvider,
		private readonly Socialnetwork\FeatureProvider $featureProvider,
	)
	{
	}

	public function resolve(array $event): int
	{
		if (!$this->featureProvider->isNewProjectsAvailable())
		{
			return 0;
		}

		$groupId = $this->resolveGroupId($event);
		if ($groupId <= 0)
		{
			return 0;
		}

		return $this->resolveProjectChatId($groupId);
	}

	private function resolveGroupId(array $event): int
	{
		return $this->eventProvider->resolveGroupId($event);
	}

	private function resolveProjectChatId(int $projectId): int
	{
		if ($projectId <= 0)
		{
			return 0;
		}

		return $this->projectChatProvider->getChatId($projectId);
	}
}
