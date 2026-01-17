<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Flow\FlowCollection;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLog;
use Bitrix\Tasks\V2\Internal\Entity\HistoryGridLogCollection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\CheckListService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\CrmService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\FlowService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\GroupService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\RelatedTaskService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\TaskReportService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\TaskStatusService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\TaskMarkService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\TaskPriorityService;
use Bitrix\Tasks\V2\Internal\Service\Grid\History\Service\UserService;

class TaskHistoryGridLogEnrichService
{
	private array $flowEntitiesIds = [];
	private FlowCollection $flowEntities;

	private array $groupEntitiesIds = [];
	private GroupCollection $groupEntities;

	private array $userEntitiesIds = [];
	private UserCollection $userEntities;

	private array $relatedTaskEntitiesIds = [];
	private array $relatedTaskEntities = [];

	public function __construct(
		private readonly FlowService $flowService,
		private readonly GroupService $groupService,
		private readonly UserService $userService,
		private readonly CrmService $crmService,
		private readonly CheckListService $checkListService,
		private readonly RelatedTaskService $relatedTaskService,
		private readonly TaskStatusService $taskStatusService,
		private readonly TaskMarkService $taskMarkService,
		private readonly TaskPriorityService $taskPriorityService,
		private readonly TaskReportService $taskReportService,
	)
	{

	}

	public function fill(HistoryGridLogCollection $historyGridLogCollection, int $userId): HistoryGridLogCollection
	{
		$this->storeRelatedEntitiesIds($historyGridLogCollection);
		$this->getRelatedEntities($userId);

		return $this->enrichHistoryGridLogs($historyGridLogCollection, $userId);
	}

	private function storeRelatedEntitiesIds(HistoryGridLogCollection $historyGridLogCollection): void
	{
		foreach ($historyGridLogCollection as $historyGridLog)
		{
			switch ($historyGridLog->field)
			{
				case 'FLOW_ID':
					$fromId = (int)$historyGridLog->fromValue;
					$toId = (int)$historyGridLog->toValue;

					if ($fromId > 0)
					{
						$this->flowEntitiesIds[] = $fromId;
					}

					if ($toId > 0)
					{
						$this->flowEntitiesIds[] = $toId;
					}

					break;
				case 'GROUP_ID':
					$fromId = (int)$historyGridLog->fromValue;
					$toId = (int)$historyGridLog->toValue;

					if ($fromId > 0)
					{
						$this->groupEntitiesIds[] = $fromId;
					}

					if ($toId > 0)
					{
						$this->groupEntitiesIds[] = $toId;
					}

					break;
				case 'PARENT_ID':
				case 'DEPENDS_ON':
					$this->relatedTaskEntitiesIds = array_merge(
						$this->relatedTaskEntitiesIds,
						explode(',', (string)$historyGridLog->fromValue),
						explode(',', (string)$historyGridLog->toValue),
					);

					break;
				case 'AUDITORS':
				case 'ACCOMPLICES':
				case 'RESPONSIBLE_ID':
				case 'CREATED_BY':
					$this->userEntitiesIds = array_merge(
						$this->userEntitiesIds,
						explode(',', (string)$historyGridLog->fromValue),
						explode(',', (string)$historyGridLog->toValue),
					);

					break;
				default:
					break;
			}
		}
	}

	private function getRelatedEntities(int $userId): void
	{
		$this->prepareRelatedEntitiesIds();

		$this->flowEntities = $this->flowService->getFlows($this->flowEntitiesIds, $userId);
		$this->groupEntities = $this->groupService->getGroups($this->groupEntitiesIds);
		$this->userEntities = $this->userService->getUsers($this->userEntitiesIds);
		$this->relatedTaskEntities = $this->relatedTaskService->getRelatedTasks($this->relatedTaskEntitiesIds, $userId);
		$this->relatedTaskEntities = array_column($this->relatedTaskEntities, null, 'ID');
	}

	private function prepareRelatedEntitiesIds(): void
	{
		Collection::normalizeArrayValuesByInt($this->flowEntitiesIds);
		Collection::normalizeArrayValuesByInt($this->groupEntitiesIds);
		Collection::normalizeArrayValuesByInt($this->userEntitiesIds);
		Collection::normalizeArrayValuesByInt($this->relatedTaskEntitiesIds);
	}

	private function enrichHistoryGridLogs(HistoryGridLogCollection $historyGridLogCollection, int $userId): HistoryGridLogCollection
	{
		foreach ($historyGridLogCollection as $historyGridLog)
		{
			switch ($historyGridLog->field)
			{
				case 'UF_CRM_TASK_ADDED':
				case 'UF_CRM_TASK_DELETED':
					$this->fillCrm($historyGridLog);

					break;
				case 'AUDITORS':
				case 'ACCOMPLICES':
				case 'RESPONSIBLE_ID':
				case 'CREATED_BY':
					$this->fillUser($historyGridLog);

					break;
				case 'FLOW_ID':
					$this->fillFlow($historyGridLog);

					break;
				case 'GROUP_ID':
					$this->fillGroup($historyGridLog, $userId);

					break;
				case 'CHECKLIST_ITEM_CHECK':
					$this->fillCheckListItemCheck($historyGridLog);

					break;
				case 'CHECKLIST_ITEM_UNCHECK':
					$this->fillCheckListItemUncheck($historyGridLog);

					break;
				case 'PARENT_ID':
				case 'DEPENDS_ON':
					$this->fillRelatedTasks($historyGridLog, $userId);

					break;
				case 'STATUS':
					$this->fillStatus($historyGridLog);

					break;
				case 'MARK':
					$this->fillMark($historyGridLog);

					break;
				case 'PRIORITY':
					$this->fillPriority($historyGridLog);

					break;
				case 'ADD_IN_REPORT':
					$this->fillReport($historyGridLog);

					break;
				default:
					break;
			}
		}

		return $historyGridLogCollection;
	}

	private function fillReport(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->taskReportService->fillReport((string)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->taskReportService->fillReport((string)$historyGridLog->toValue);
	}

	private function fillPriority(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->taskPriorityService->fillPriority((int)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->taskPriorityService->fillPriority((int)$historyGridLog->toValue);
	}

	private function fillMark(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->taskMarkService->fillMark((string)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->taskMarkService->fillMark((string)$historyGridLog->toValue);
	}

	private function fillStatus(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->taskStatusService->fillStatus((int)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->taskStatusService->fillStatus((int)$historyGridLog->toValue);
	}

	private function fillRelatedTasks(HistoryGridLog $historyGridLog, int $userId): void
	{
		$historyGridLog->fromValue = $this->relatedTaskService->fillRelatedTasks(
			tasks: $this->relatedTaskEntities,
			taskIds: explode(',', (string)$historyGridLog->fromValue),
			userId: $userId,
		);
		$historyGridLog->toValue = $this->relatedTaskService->fillRelatedTasks(
			tasks: $this->relatedTaskEntities,
			taskIds: explode(',', (string)$historyGridLog->toValue),
			userId: $userId,
		);
	}

	private function fillCheckListItemCheck(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->checkListService->fillCheckListItem((string)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->checkListService->fillCheckListItem(
			title: (string)$historyGridLog->toValue,
			isChecked: true,
		);
	}

	private function fillCheckListItemUncheck(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->checkListService->fillCheckListItem(
			title: (string)$historyGridLog->fromValue,
			isChecked: true,
		);
		$historyGridLog->toValue = $this->checkListService->fillCheckListItem((string)$historyGridLog->toValue);
	}

	private function fillCrm(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->crmService->fillCrm(explode(',', (string)$historyGridLog->fromValue));
		$historyGridLog->toValue = $this->crmService->fillCrm(explode(',', (string)$historyGridLog->toValue));
	}

	private function fillFlow(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->flowService->fillFlow($this->flowEntities, (int)$historyGridLog->fromValue);
		$historyGridLog->toValue = $this->flowService->fillFlow($this->flowEntities, (int)$historyGridLog->toValue);
	}

	private function fillGroup(HistoryGridLog $historyGridLog, int $userId): void
	{
		$historyGridLog->fromValue = $this->groupService->fillGroup(
			groupEntities: $this->groupEntities,
			groupId: (int)$historyGridLog->fromValue,
			userId: $userId
		);

		$historyGridLog->toValue = $this->groupService->fillGroup(
			groupEntities: $this->groupEntities,
			groupId: (int)$historyGridLog->toValue,
			userId: $userId
		);
	}

	private function fillUser(HistoryGridLog $historyGridLog): void
	{
		$historyGridLog->fromValue = $this->userService->fillUser(
			$this->userEntities,
			explode(',', (string)$historyGridLog->fromValue),
		);

		$historyGridLog->toValue = $this->userService->fillUser(
			$this->userEntities,
			explode(',', (string)$historyGridLog->toValue),
		);
	}
}
