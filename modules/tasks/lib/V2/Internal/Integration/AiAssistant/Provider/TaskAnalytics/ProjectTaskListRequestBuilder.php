<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskAnalytics;

use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Helper\TaskStatusesNotSuitableForAnalysisHelper;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetProjectTasksForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Repository\Task\Filter;
use Bitrix\Tasks\V2\Internal\Repository\Task\ListSelect;
use Bitrix\Tasks\V2\Internal\Repository\Task\Order;
use Bitrix\Tasks\V2\Internal\Repository\Pagination;

class ProjectTaskListRequestBuilder
{
	private const SELECTED_FIELDS = [
		'id',
		'title',
		'status',
		'deadline',
		'priority',
		'createdDate',
		'taskControl',
		'statusChangedDate',
		'chatId',
		'stageId',
		'groupId',
	];

	private const DEADLINE_FIELD = 'deadline';
	private const CHANGED_DATE_FIELD = 'changedDate';
	private const GROUP_ID_FIELD = 'groupId';
	private const RESPONSIBLE_ID_FIELD = 'responsibleId';
	private const CREATED_BY_FIELD = 'createdBy';
	private const STATUS_FIELD = 'realStatus';

	public function buildRecentlyChanged(int $userId, GetProjectTasksForAnalysisDto $dto): ProjectTaskListRequestDto
	{
		return new ProjectTaskListRequestDto(
			$this->buildPagination($dto->limit),
			$this->buildFilter($userId, $dto),
			$this->buildChangedDateOrderDesc(),
			$this->buildListSelect(),
		);
	}

	public function buildDeadlineFilled(int $userId, GetProjectTasksForAnalysisDto $dto): ProjectTaskListRequestDto
	{
		return new ProjectTaskListRequestDto(
			$this->buildPagination($dto->limit),
			$this->buildFilterWithDeadline($userId, $dto),
			$this->buildDeadlineOrder(),
			$this->buildListSelect(),
		);
	}

	public function buildDeadlineEmpty(int $userId, GetProjectTasksForAnalysisDto $dto, int $limit): ProjectTaskListRequestDto
	{
		return new ProjectTaskListRequestDto(
			$this->buildPagination($limit),
			$this->buildFilterWithoutDeadline($userId, $dto),
			$this->buildChangedDateOrderAsc(),
			$this->buildListSelect(),
		);
	}

	private function buildPagination(int $limit): Pagination
	{
		return new Pagination($limit);
	}

	private function buildFilter(int $userId, GetProjectTasksForAnalysisDto $dto): Filter
	{
		return new Filter($this->buildBaseFilter($dto), $userId);
	}

	private function buildFilterWithDeadline(int $userId, GetProjectTasksForAnalysisDto $dto): Filter
	{
		$filter = $this->buildBaseFilter($dto);

		$filter->where(new Condition(self::DEADLINE_FIELD, '!', null));

		return new Filter($filter, $userId);
	}

	private function buildFilterWithoutDeadline(int $userId, GetProjectTasksForAnalysisDto $dto): Filter
	{
		$filter = $this->buildBaseFilter($dto);

		$filter->where(new Condition(self::DEADLINE_FIELD, '=', null));

		return new Filter($filter, $userId);
	}

	private function buildBaseFilter(GetProjectTasksForAnalysisDto $dto): ConditionTree
	{
		$filter = new ConditionTree();

		$filter->where(new Condition(self::GROUP_ID_FIELD, '=', $dto->projectId));

		$filter->where(new Condition(self::STATUS_FIELD, '!', TaskStatusesNotSuitableForAnalysisHelper::getRawList()));

		if ($dto->responsibleId !== null)
		{
			$filter->where(new Condition(self::RESPONSIBLE_ID_FIELD, '=', $dto->responsibleId));
		}

		if ($dto->creatorId !== null)
		{
			$filter->where(new Condition(self::CREATED_BY_FIELD, '=', $dto->creatorId));
		}

		return $filter;
	}

	private function buildChangedDateOrderAsc(): Order
	{
		$sort = [self::CHANGED_DATE_FIELD => 'asc'];

		return new Order($sort);
	}

	private function buildChangedDateOrderDesc(): Order
	{
		$sort = [self::CHANGED_DATE_FIELD => 'desc'];

		return new Order($sort);
	}

	private function buildDeadlineOrder(): Order
	{
		$sort = [self::DEADLINE_FIELD => 'asc'];

		return new Order($sort);
	}

	private function buildListSelect(): ListSelect
	{
		return new ListSelect(self::SELECTED_FIELDS);
	}
}
