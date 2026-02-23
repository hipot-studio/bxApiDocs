<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskArchive;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class TaskArchiveTasks implements EntityInterface
{
	private ?int $id = null;
	private ?int $archiveId = null;
	private ?int $taskId = null;
	private ?int $completedAt = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getArchiveId(): ?int
	{
		return $this->archiveId;
	}

	public function setArchiveId(?int $archiveId): self
	{
		$this->archiveId = $archiveId;

		return $this;
	}

	public function getTaskId(): ?int
	{
		return $this->taskId;
	}

	public function setTaskId(?int $taskId): self
	{
		$this->taskId = $taskId;

		return $this;
	}

	public function getCompletedAt(): ?int
	{
		return $this->completedAt;
	}

	public function setCompletedAt(?int $completedAt): self
	{
		$this->completedAt = $completedAt;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$entity = new self();
		if (isset($props['id']))
		{
			$entity->setId((int)$props['id']);
		}
		if (isset($props['archiveId']))
		{
			$entity->setArchiveId((int)$props['archiveId']);
		}
		if (isset($props['taskId']))
		{
			$entity->setTaskId((int)$props['taskId']);
		}
		if (isset($props['completedAt']))
		{
			$entity->setCompletedAt((int)$props['completedAt']);
		}

		return $entity;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'archiveId' => $this->getArchiveId(),
			'taskId' => $this->getTaskId(),
			'completedAt' => $this->getCompletedAt(),
		];
	}
}
