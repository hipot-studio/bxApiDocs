<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardView implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private readonly int $dashboardId,
		private int $userId,
		private DateTime $viewedAt = new DateTime(),
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getDashboardId(): int
	{
		return $this->dashboardId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getViewedAt(): DateTime
	{
		return $this->viewedAt;
	}

	public function setViewedAt(DateTime $viewedAt): self
	{
		$this->viewedAt = $viewedAt;

		return $this;
	}
}
