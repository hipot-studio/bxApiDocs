<?php

namespace Bitrix\Tasks\Flow\Internal\Event\Task;

use Bitrix\Tasks\Flow\Internal\FlowTaskTable;
use Exception;

class FlowTaskEventHandler
{
	private int $flowId = 0;
	private int $taskId = 0;
	private array $fields = [];
	private array $previousFields = [];

	public function withFlowId(int $flowId): static
	{
		$this->flowId = $flowId;
		return $this;
	}

	public function withTaskId(int $taskId): static
	{
		$this->taskId = $taskId;
		return $this;
	}

	public function withFields(array $fields): static
	{
		$this->fields = $fields;
		return $this;
	}

	public function withPreviousFields(array $previousFields): static
	{
		$this->previousFields = $previousFields;
		return $this;
	}

	/**
	 * @throws Exception
	 */
	public function onTaskAdd(): void
	{
		FlowTaskTable::add([
			'FLOW_ID' => $this->flowId,
			'TASK_ID' => $this->taskId,
		]);
	}

	public function onTaskUpdate(): void
	{
		$groupId = (isset($this->fields['GROUP_ID']) ? (int)$this->fields['GROUP_ID'] : null);
		$previousGroupId = (isset($this->previousFields['GROUP_ID']) ? (int)$this->previousFields['GROUP_ID'] : null);

		$isGroupChanged = (($groupId && $groupId !== $previousGroupId)
			|| ($groupId === 0 && $previousGroupId > 0));

		if ($isGroupChanged)
		{
			FlowTaskTable::deleteRelation($this->taskId);
		}
	}

	public function onTaskDelete(): void
	{
		FlowTaskTable::deleteRelation($this->taskId);
	}
}