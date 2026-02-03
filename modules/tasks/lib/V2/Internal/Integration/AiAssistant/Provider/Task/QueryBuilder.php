<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Task;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Provider\Query\TaskQuery;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\SearchTasksDto;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TaskStatusMapper;

class QueryBuilder
{
	private const TASKS_LIMIT = 15;

	private TaskQuery $query;

	public function __construct(
		private readonly TaskStatusMapper $statusMapper,
	)
	{
	}

	public function build(SearchTasksDto $dto, int $userId): TaskQuery
	{
		$this->query = new TaskQuery($userId);

		$this
			->buildSelect()
			->buildFilter($dto, $userId)
			->buildOrder()
			->buildLimit()
		;

		return $this->query;
	}

	private function buildSelect(): self
	{
		$this->query->setSelect([
			'ID',
			'TITLE',
			'DESCRIPTION',
			'DEADLINE',
			'PRIORITY',
			'STATUS',
			'PARENT_ID',
			'CREATED_BY',
			'CREATED_BY_NAME',
			'CREATED_BY_LAST_NAME',
			'RESPONSIBLE_ID',
			'RESPONSIBLE_NAME',
			'RESPONSIBLE_LAST_NAME',
			'GROUP_ID',
			'GROUP_NAME',
			'GROUP_TYPE',
		]);

		return $this;
	}

	private function buildFilter(SearchTasksDto $dto, int $userId): self
	{
		return
			$this
				->filterByTitle($dto)
				->filterByDescription($dto)
				->filterByDeadlineRange($dto)
				->filterByRelatedEntities($dto)
				->filterByParticipants($dto, $userId)
				->filterByStatus($dto)
		;
	}

	private function buildOrder(): self
	{
		$this->query->setOrder(['DEADLINE' => 'ASC']);

		return $this;
	}

	private function buildLimit(): self
	{
		$this->query->setLimit(static::TASKS_LIMIT);

		return $this;
	}

	private function filterByTitle(SearchTasksDto $dto): self
	{
		$title = trim($dto->title ?? '');

		if ($title !== '')
		{
			$this->query->addWhere('%TITLE', $title);
		}

		return $this;
	}

	private function filterByDescription(SearchTasksDto $dto): self
	{
		$description = trim($dto->description ?? '');

		if ($description !== '')
		{
			$this->query->addWhere('%DESCRIPTION', $description);
		}

		return $this;
	}

	private function filterByDeadlineRange(SearchTasksDto $dto): self
	{
		if ($dto->deadlineFrom !== null)
		{
			$this->query->addWhere('>=DEADLINE', $dto->deadlineFrom);
		}

		if ($dto->deadlineTo !== null)
		{
			$this->query->addWhere('<=DEADLINE', $dto->deadlineTo);
		}

		return $this;
	}

	private function filterByRelatedEntities(SearchTasksDto $dto): self
	{
		if ($dto->groupId !== null)
		{
			$this->query->addWhere('GROUP_ID', $dto->groupId);
		}

		$tag = trim($dto->tag ?? '');

		if ($tag !== '')
		{
			$this->query->addWhere('TAG', $tag);
		}

		return $this;
	}

	private function filterByParticipants(SearchTasksDto $dto, int $userId): self
	{
		if (!$dto->hasParticipantFilters())
		{
			$this->query->addWhere('MEMBER', $userId);

			return $this;
		}

		$participantFilters = [
			'MEMBER' => $dto->memberId,
			'CREATED_BY' => $dto->creatorId,
			'RESPONSIBLE_ID' => $dto->responsibleId,
			'ACCOMPLICE' => $dto->accompliceId,
			'AUDITOR' => $dto->auditorId,
		];

		foreach ($participantFilters as $field => $value)
		{
			if ($value !== null)
			{
				$this->query->addWhere($field, $value);
			}
		}

		return $this;
	}

	private function filterByStatus(SearchTasksDto $dto): self
	{
		if ($dto->status === null)
		{
			$this->query->addWhere('@REAL_STATUS', Status::getInWorkStatuses());
		}
		else
		{
			$this->query->addWhere('REAL_STATUS', $this->statusMapper->mapFromEnum($dto->status));
		}

		return $this;
	}
}
