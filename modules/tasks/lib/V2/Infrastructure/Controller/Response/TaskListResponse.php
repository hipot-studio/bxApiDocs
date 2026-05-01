<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Response;

use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\Select;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList\SelectItem;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\Type;
use Closure;

class TaskListResponse
{
	protected array $selectedFields = [];

	public function __construct(
		public TaskCollection $items,
		public Select $select,
		public Closure|int $count,
	)
	{
		$this->selectedFields = array_flip(array_map(
			fn (SelectItem $item) => $item->field,
			$this->select->list,
		));
	}

	public function toArray(): array
	{
		$mappedTasks = [];
		foreach ($this->items as $task)
		{
			$mappedTasks[] = $this->mapFromEntity($task);
		}

		return (
			new Page(
				'tasks',
				array_values(array_filter($mappedTasks)),
				$this->count,
			)
		)->toArray();
	}

	protected function shouldBeReturned(string $fieldName): bool
	{
		return isset($this->selectedFields[$fieldName]);
	}
	
	protected function mapFromEntity(Task $task): array
	{
		$result = [];
		if ($this->shouldBeReturned('id'))
		{
			$result['id'] = $task->id;
		}

		if ($this->shouldBeReturned('title'))
		{
			$result['title'] = $task->title;
		}

		if ($this->shouldBeReturned('activityDate'))
		{
			$result['activityDate'] = $task->activityTs;
		}

		if ($this->shouldBeReturned('deadline'))
		{
			$result['deadline'] = $task->deadlineTs;
		}

		if ($this->shouldBeReturned('creator'))
		{
			$result['creator'] = $task->creator === null ? null : [
				'id' => $task->creator->id,
				'name' => $task->creator->name,
			];
		}

		if ($this->shouldBeReturned('responsible'))
		{
			$result['responsible'] = $task->responsible === null ? null : [
				'id' => $task->responsible->id,
				'name' => $task->responsible->name,
			];
		}

		if ($this->shouldBeReturned('group'))
		{
			$result['group'] = [
				'id' => $task->group?->id,
				'name' => $task->group?->name,
			];
		}

		if ($this->shouldBeReturned('flow'))
		{
			if ($task->flow === null)
			{
				$result['flow'] = null;
			}
			else
			{
				$result['flow'] = [
					'id' => $task->flow->id,
					'name' => $task->flow->name,
				];
			}
		}

		if ($this->shouldBeReturned('createdDate'))
		{
			$result['createdDate'] = $task->createdTs;
		}

		if ($this->shouldBeReturned('changedDate'))
		{
			$result['changedDate'] = $task->changedTs;
		}

		if ($this->shouldBeReturned('closedDate'))
		{
			$result['closedDate'] = $task->closedTs;
		}

		if ($this->shouldBeReturned('timeEstimate'))
		{
			if ($task->estimatedTime === null || $task->estimatedTime === 0)
			{
				$result['timeEstimate'] = null;
			}
			else
			{
				$result['timeEstimate'] = $task->estimatedTime;
			}
		}

		if ($this->shouldBeReturned('allowTimeTracking'))
		{
			$result['allowTimeTracking'] = $task->allowsTimeTracking;
		}

		if ($this->shouldBeReturned('mark'))
		{
			$result['mark'] = $task->mark?->value;
		}

		if ($this->shouldBeReturned('allowChangeDeadline'))
		{
			$result['allowChangeDeadline'] = $task->allowsChangeDeadline;
		}

		if ($this->shouldBeReturned('timeSpentInLogs'))
		{
			$result['timeSpentInLogs'] = $task->timeSpent;
		}

		if ($this->shouldBeReturned('tags'))
		{
			$result['tags'] = [];

			foreach ($task->tags ?? [] as $tag)
			{
				$result['tags'][] = [
					'id' => $tag->id,
					'name' => $tag->name,
				];
			}
		}

		if ($this->shouldBeReturned('startDatePlan'))
		{
			$result['startDatePlan'] = $task->startPlanTs;
		}

		if ($this->shouldBeReturned('endDatePlan'))
		{
			$result['endDatePlan'] = $task->endPlanTs;
		}

		if ($this->shouldBeReturned('ufCrmTaskLead'))
		{
			$result['ufCrmTaskLead'] = null;
			foreach ($task->crmItems ?? [] as $item)
			{
				if ($item->type === Type::Lead)
				{
					$result['ufCrmTaskLead'] = $item->toArray();
				}
			}
		}

		if ($this->shouldBeReturned('ufCrmTaskDeal'))
		{
			$result['ufCrmTaskDeal'] = null;
			foreach ($task->crmItems ?? [] as $item)
			{
				if ($item->type === Type::Deal)
				{
					$result['ufCrmTaskDeal'] = $item->toArray();
				}
			}
		}

		if ($this->shouldBeReturned('ufCrmTaskContact'))
		{
			$result['ufCrmTaskContact'] = null;
			foreach ($task->crmItems ?? [] as $item)
			{
				if ($item->type === Type::Contact)
				{
					$result['ufCrmTaskContact'] = $item->toArray();
				}
			}
		}

		if ($this->shouldBeReturned('ufCrmTaskCompany'))
		{
			$result['ufCrmTaskCompany'] = null;
			foreach ($task->crmItems ?? [] as $item)
			{
				if ($item->type === Type::Company)
				{
					$result['ufCrmTaskCompany'] = $item->toArray();
				}
			}
		}

		if ($this->shouldBeReturned('ufCrmTask'))
		{
			$result['ufCrmTask'] = $task->crmItems?->toArray();
		}

		if ($this->shouldBeReturned('status'))
		{
			$result['status'] = $task->status;
		}

		if ($this->shouldBeReturned('complete'))
		{
			$result['complete'] = $task->status === Task\Status::Completed;
		}

		if ($this->shouldBeReturned('links'))
		{
			$result['links'] = [];

			foreach ($task->links ?? [] as $link)
			{
				$result['links'][] = [
					'dependencyId' => $link->dependentId,
					'type' => $link->type->value,
				];
			}
		}

		return $result;
	}
}
