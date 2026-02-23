<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Bizproc\Internal\Entity\Task\TaskUser\TaskUserCollection;

class Task implements EntityInterface
{
	private ?int $id = null;
	private ?string $workflowId = null;
	private ?string $activity = null;
	private ?string $activityName = null;
	private ?int $modified = null;
	private ?int $overdueDate = null;
	private ?string $name = null;
	private ?string $description = null;
	private ?array $parameters = null;
	private ?int $status = null;
	private ?bool $isInline = null;
	private ?int $delegationType = null;
	private ?string $documentName = null;
	private ?int $createdDate = null;
	private ?TaskUserCollection $taskUsers = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
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

	public function getActivity(): ?string
	{
		return $this->activity;
	}

	public function setActivity(?string $activity): self
	{
		$this->activity = $activity;

		return $this;
	}

	public function getActivityName(): ?string
	{
		return $this->activityName;
	}

	public function setActivityName(?string $activityName): self
	{
		$this->activityName = $activityName;

		return $this;
	}

	public function getModified(): ?int
	{
		return $this->modified;
	}

	public function setModified(?int $modified): self
	{
		$this->modified = $modified;

		return $this;
	}

	public function getOverdueDate(): ?int
	{
		return $this->overdueDate;
	}

	public function setOverdueDate(?int $overdueDate): self
	{
		$this->overdueDate = $overdueDate;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getParameters(): ?array
	{
		return $this->parameters;
	}

	public function setParameters(?array $parameters): self
	{
		$this->parameters = $parameters;

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

	public function getIsInline(): ?bool
	{
		return $this->isInline;
	}

	public function setIsInline(?bool $isInline): self
	{
		$this->isInline = $isInline;

		return $this;
	}

	public function getDelegationType(): ?int
	{
		return $this->delegationType;
	}

	public function setDelegationType(?int $delegationType): self
	{
		$this->delegationType = $delegationType;

		return $this;
	}

	public function getDocumentName(): ?string
	{
		return $this->documentName;
	}

	public function setDocumentName(?string $documentName): self
	{
		$this->documentName = $documentName;

		return $this;
	}

	public function getCreatedDate(): ?int
	{
		return $this->createdDate;
	}

	public function setCreatedDate(?int $createdDate): self
	{
		$this->createdDate = $createdDate;

		return $this;
	}

	public function getTaskUsers(): ?TaskUserCollection
	{
		return $this->taskUsers;
	}

	public function setTaskUsers(?TaskUserCollection $taskUsers): self
	{
		$this->taskUsers = $taskUsers;

		return $this;
	}

	public static function mapFromArray(array $props): static
	{
		$result = new self();

		if (isset($props['id']))
		{
			$result->setId((int)$props['id']);
		}
		if (isset($props['workflowId']))
		{
			$result->setWorkflowId((string)$props['workflowId']);
		}
		if (isset($props['activity']))
		{
			$result->setActivity((string)$props['activity']);
		}
		if (isset($props['activityName']))
		{
			$result->setActivityName((string)$props['activityName']);
		}
		if (isset($props['modified']))
		{
			$result->setModified((int)$props['modified']);
		}
		if (isset($props['overdueDate']))
		{
			$result->setOverdueDate((int)$props['overdueDate']);
		}
		if (isset($props['name']))
		{
			$result->setName((string)$props['name']);
		}
		if (isset($props['description']))
		{
			$result->setDescription((string)$props['description']);
		}
		if (isset($props['parameters']))
		{
			$result->setParameters((array)$props['parameters']);
		}
		if (isset($props['status']))
		{
			$result->setStatus((int)$props['status']);
		}
		if (isset($props['isInline']))
		{
			$result->setIsInline((bool)$props['isInline']);
		}
		if (isset($props['delegationType']))
		{
			$result->setDelegationType((int)$props['delegationType']);
		}
		if (isset($props['documentName']))
		{
			$result->setDocumentName((string)$props['documentName']);
		}
		if (isset($props['createdDate']))
		{
			$result->setCreatedDate((int)$props['createdDate']);
		}
		if (isset($props['taskUsers']))
		{
			$result->setTaskUsers(TaskUserCollection::mapFromArray((array)$props['taskUsers']));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'workflowId' => $this->getWorkflowId(),
			'activity' => $this->getActivity(),
			'activityName' => $this->getActivityName(),
			'modified' => $this->getModified(),
			'overdueDate' => $this->getOverdueDate(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'parameters' => $this->getParameters(),
			'status' => $this->getStatus(),
			'isInline' => $this->getIsInline(),
			'delegationType' => $this->getDelegationType(),
			'documentName' => $this->getDocumentName(),
			'createdDate' => $this->getCreatedDate(),
			'taskUsers' => $this->getTaskUsers()?->toArray(),
		];
	}
}
