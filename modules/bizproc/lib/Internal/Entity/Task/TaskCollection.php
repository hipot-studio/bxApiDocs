<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method Task|null getFirstCollectionItem()
 * @method \ArrayIterator<Task> getIterator()
 */
class TaskCollection extends BaseEntityCollection
{
	public function __construct(Task ...$tasks)
	{
		foreach ($tasks as $task)
		{
			$this->collectionItems[] = $task;
		}
	}

	/**
	 * @return array<string, TaskCollection>
	 */
	public function groupByWorkflowId(): array
	{
		$grouped = [];
		foreach ($this->collectionItems as $task)
		{
			$grouped[$task->getWorkflowId()][] = $task;
		}

		$groupedCollections = [];
		foreach ($grouped as $workflowId => $tasks)
		{
			$groupedCollections[$workflowId] = new self(...$tasks);
		}

		return $groupedCollections;
	}

	public function take(int $count): self
	{
		$collection = array_slice($this->collectionItems, 0, $count);

		return new self(...$collection);
	}

	public function skip(int $count): self
	{
		$collection = array_slice($this->collectionItems, $count);

		return new self(...$collection);
	}
}
