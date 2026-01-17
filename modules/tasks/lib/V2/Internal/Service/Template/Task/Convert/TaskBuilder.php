<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert;

use Bitrix\Tasks\V2\Internal\Entity;

class TaskBuilder
{
	private array $changes = [];

	public function __construct(
		private readonly Entity\Task $initialTask
	)
	{

	}

	public function set(string $field, mixed $value): self
	{
		$this->changes[$field] = $value;

		return $this;
	}

	public function build(): Entity\Task
	{
		if (empty($this->changes))
		{
			return $this->initialTask;
		}

		$fields = array_merge($this->initialTask->toArray(), $this->changes);

		return Entity\Task::mapFromArray($fields);
	}
}
