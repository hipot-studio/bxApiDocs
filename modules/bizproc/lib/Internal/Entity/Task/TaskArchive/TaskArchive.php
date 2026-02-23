<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskArchive;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class TaskArchive implements EntityInterface
{
	private ?int $id = null;
	private ?string $workflowId = null;
	private ?array $tasksData = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getWorkflowId(): ?string
	{
		return $this->workflowId;
	}

	public function setWorkflowId(?string $workflowId): self
	{
		$this->workflowId = $workflowId;

		return $this;
	}

	public function getTasksData(): ?array
	{
		return $this->tasksData;
	}

	public function setTasksData(?array $tasksData): self
	{
		$this->tasksData = $tasksData;

		return $this;
	}

	public function getTasksCount(): int
	{
		return count($this->tasksData);
	}

	public static function mapFromArray(array $props): static
	{
		$entity = new self();

		if (isset($props['id']))
		{
			$entity->setId((int)$props['id']);
		}
		if (isset($props['workflowId']))
		{
			$entity->setWorkflowId((string)$props['workflowId']);
		}
		if (isset($props['tasksData']))
		{
			$entity->setTasksData((array)$props['tasksData']);
		}

		return $entity;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'workflowId' => $this->getWorkflowId(),
			'tasksData' => $this->getTasksData(),
		];
	}
}
