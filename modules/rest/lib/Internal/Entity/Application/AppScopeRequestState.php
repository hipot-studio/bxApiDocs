<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class AppScopeRequestState implements EntityInterface
{
	private ?int $id = null;
	private ?DateTime $dateCreate = null;

	public function __construct(
		private readonly int $requestId,
		private readonly AppScopeRequestStatus $status,
		private readonly string $comment = '',
	) {}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getRequestId(): int
	{
		return $this->requestId;
	}

	public function getStatus(): AppScopeRequestStatus
	{
		return $this->status;
	}

	public function getComment(): string
	{
		return $this->comment;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}
}
