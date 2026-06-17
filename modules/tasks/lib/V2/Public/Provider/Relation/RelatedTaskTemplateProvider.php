<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Relation;

use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\RelationTaskMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;
use Bitrix\Tasks\V2\Internal\Repository\SubTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskParameterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskParams;

class RelatedTaskTemplateProvider extends AbstractRelationTaskProvider
{
	public function __construct(
		protected readonly TemplateRightService $templateRightService,
		protected readonly TaskRightService $taskRightService,
		protected readonly TaskList $taskList,
		protected readonly UserRepositoryInterface $userRepository,
		protected readonly RelationTaskMapper $relationTaskMapper,
		protected readonly GroupRepositoryInterface $groupRepository,
		protected readonly SubTaskRepositoryInterface $subTaskRepository,
		protected readonly TaskParameterRepositoryInterface $taskParameterRepository,
		protected readonly TaskStatusMapper $taskStatusMapper,
		protected readonly DeadlineChangeLogRepositoryInterface $deadlineChangeLogRepository,
	)
	{

	}

	protected function getFilter(RelationTaskParams $relationTaskParams): array
	{
		return ['=DEPENDS_ON_TEMPLATE' => $relationTaskParams->templateId];
	}

	protected function getFilterForIds(RelationTaskParams $relationTaskParams): array
	{
		return $this->getFilter($relationTaskParams);
	}

	protected function getRelationRights(array $taskIds, int $rootId, int $userId): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		$params['detachRelated'] = ['relatedId' => $rootId];

		return $this->taskRightService->getTaskRightsBatch(
			userId: $userId,
			taskIds: $taskIds,
			rules: ActionDictionary::RELATED_TASK_TEMPLATE_ACTIONS,
			params: $params,
		);
	}

	protected function checkRootAccess(RelationTaskParams $relationTaskParams): bool
	{
		if (!$relationTaskParams->checkRootAccess)
		{
			return true;
		}

		return $this->templateRightService->canView($relationTaskParams->userId, $relationTaskParams->templateId);
	}

	protected function checkRoot(RelationTaskParams $relationTaskParams): bool
	{
		return $relationTaskParams->templateId > 0;
	}
}
