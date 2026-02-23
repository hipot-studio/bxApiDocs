<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskUser;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;

class TaskUser implements EntityInterface
{
	private ?int $id = null;
	private ?int $userId = null;
	private ?int $taskId = null;
	private ?int $status = null;
	private ?int $dateUpdate = null;
	private ?int $originalUserId = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

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

	public function getStatus(): ?int
	{
		return $this->status;
	}

	public function setStatus(?int $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function getDateUpdate(): ?int
	{
		return $this->dateUpdate;
	}

	public function setDateUpdate(?int $dateUpdate): self
	{
		$this->dateUpdate = $dateUpdate;

		return $this;
	}

	public function getOriginalUserId(): ?int
	{
		return $this->originalUserId;
	}

	public function setOriginalUserId(?int $originalUserId): self
	{
		$this->originalUserId = $originalUserId;

		return $this;
	}

	public static function mapFromArray(array $props): self
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}
		if (isset($props['userId']))
		{
			$result->setUserId((int)$props['userId']);
		}
		if (isset($props['taskId']))
		{
			$result->setTaskId((int)$props['taskId']);
		}
		if (isset($props['status']))
		{
			$result->setStatus((int)$props['status']);
		}
		if (isset($props['dateUpdate']))
		{
			$result->setDateUpdate((int)$props['dateUpdate']);
		}
		if (isset($props['originalUserId']))
		{
			$result->setOriginalUserId((int)$props['originalUserId']);
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'taskId' => $this->getTaskId(),
			'status' => $this->getStatus(),
			'dateUpdate' => $this->getDateUpdate(),
			'originalUserId' => $this->getOriginalUserId(),
		];
	}
}
