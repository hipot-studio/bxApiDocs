<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Socialnetwork\V2\Internal\Access\LegacyGroup;
use Bitrix\Socialnetwork\V2\Internal\Access\Scrum;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime\TaskRealtimePageContext;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime\TaskRealtimeGridProvider;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime\TaskRealtimePageContextSigner;
use Bitrix\Socialnetwork\V2\Public\Dto;
use CSocNetUser;

class ProjectListGridRealtime extends BaseController
{
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Dto\LegacyGroup\LegacyGroupCollection::class,
				'legacyGroups',
				fn(string $className, array $ids = []): ?Dto\LegacyGroup\LegacyGroupCollection
					=> $this->getWithAccess(
						$this,
						'legacyGroups',
						Dto\LegacyGroup\LegacyGroupCollection::mapFromIds($ids),
					),
			),
			new ExactParameter(
				Dto\Scrum\ScrumCollection::class,
				'scrums',
				fn(string $className, array $ids = []): ?Dto\Scrum\ScrumCollection
					=> $this->getWithAccess(
						$this,
						'scrums',
						Dto\Scrum\ScrumCollection::mapFromIds($ids),
					),
			),
		];
	}

	/**
	 * Collection-read realtime endpoint for the project task grid.
	 * Collection access is normalized at the controller boundary.
	 *
	 * @ajaxAction socialnetwork.V2.ProjectListGridRealtime.getProjectTaskCounters
	 */
	public function getProjectTaskCountersAction(
		#[LegacyGroup\Permission\ReadCollection]
		Dto\LegacyGroup\LegacyGroupCollection $legacyGroups,
		TaskRealtimeGridProvider $taskRealtimeGridProvider,
		string $signedPageContext = '',
	): ?array
	{
		if ($this->restoreTaskRealtimePageContext(
			signedPageContext: $signedPageContext,
			expectedMode: TaskRealtimePageContext::MODE_PROJECT,
		) === null)
		{
			return null;
		}

		return $taskRealtimeGridProvider->getLegacyGroupTaskCounters(
			readableIds: $legacyGroups->getIds(),
		);
	}

	/**
	 * Collection-read realtime endpoint for the project task grid.
	 * Collection access is normalized at the controller boundary. Raw requested ids are
	 * still forwarded so unreadable rows can resolve to `false` during reconcile/remove.
	 *
	 * @ajaxAction socialnetwork.V2.ProjectListGridRealtime.getProjectTaskGridRows
	 */
	public function getProjectTaskGridRowsAction(
		TaskRealtimeGridProvider $taskRealtimeGridProvider,
		#[LegacyGroup\Permission\ReadCollection]
		Dto\LegacyGroup\LegacyGroupCollection $legacyGroups,
		array $ids = [],
		int $page = 1,
		string $signedPageContext = '',
	): ?array
	{
		$pageContext = $this->restoreTaskRealtimePageContext(
			signedPageContext: $signedPageContext,
			expectedMode: TaskRealtimePageContext::MODE_PROJECT,
		);
		if ($pageContext === null)
		{
			return null;
		}

		return $taskRealtimeGridProvider->getLegacyGroupTaskGridRows(
			ids: $ids,
			readableIds: $legacyGroups->getIds(),
			pageContext: $pageContext,
			page: $page,
			currentUserId: $this->userId,
			isAdmin: CSocNetUser::isCurrentUserModuleAdmin(),
		);
	}

	/**
	 * Collection-read realtime endpoint for the scrum task grid.
	 * Collection access is normalized at the controller boundary.
	 *
	 * @ajaxAction socialnetwork.V2.ProjectListGridRealtime.getScrumTaskCounters
	 */
	public function getScrumTaskCountersAction(
		#[Scrum\Permission\ReadCollection]
		Dto\Scrum\ScrumCollection $scrums,
		TaskRealtimeGridProvider $taskRealtimeGridProvider,
		string $signedPageContext = '',
	): ?array
	{
		if ($this->restoreTaskRealtimePageContext(
			signedPageContext: $signedPageContext,
			expectedMode: TaskRealtimePageContext::MODE_SCRUM,
		) === null)
		{
			return null;
		}

		return $taskRealtimeGridProvider->getScrumTaskCounters(
			readableIds: $scrums->getIds(),
		);
	}

	/**
	 * Collection-read realtime endpoint for the scrum task grid.
	 * Collection access is normalized at the controller boundary. Raw requested ids are
	 * still forwarded so unreadable rows can resolve to `false` during reconcile/remove.
	 *
	 * @ajaxAction socialnetwork.V2.ProjectListGridRealtime.getScrumTaskGridRows
	 */
	public function getScrumTaskGridRowsAction(
		TaskRealtimeGridProvider $taskRealtimeGridProvider,
		#[Scrum\Permission\ReadCollection]
		Dto\Scrum\ScrumCollection $scrums,
		array $ids = [],
		int $page = 1,
		string $signedPageContext = '',
	): ?array
	{
		$pageContext = $this->restoreTaskRealtimePageContext(
			signedPageContext: $signedPageContext,
			expectedMode: TaskRealtimePageContext::MODE_SCRUM,
		);
		if ($pageContext === null)
		{
			return null;
		}

		return $taskRealtimeGridProvider->getScrumTaskGridRows(
			ids: $ids,
			readableIds: $scrums->getIds(),
			pageContext: $pageContext,
			page: $page,
			currentUserId: $this->userId,
			isAdmin: CSocNetUser::isCurrentUserModuleAdmin(),
		);
	}

	private function restoreTaskRealtimePageContext(string $signedPageContext, string $expectedMode): ?TaskRealtimePageContext
	{
		$pageContext = TaskRealtimePageContextSigner::unsign($signedPageContext);
		if ($pageContext?->allowsSelfNarrowFor($this->userId, $expectedMode))
		{
			return $pageContext;
		}

		return $this->buildForbiddenResponse(
			$this->buildForbiddenError('invalid_task_realtime_page_context'),
		);
	}
}
