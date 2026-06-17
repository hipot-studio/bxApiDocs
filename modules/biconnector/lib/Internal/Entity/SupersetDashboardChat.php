<?php

namespace Bitrix\BIConnector\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardChat implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private readonly int $dashboardId,
		private readonly int $chatId,
		private readonly int $createdById,
		private readonly DateTime $dateCreate = new DateTime(),
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

	public function getChatId(): int
	{
		return $this->chatId;
	}

	public function getCreatedById(): int
	{
		return $this->createdById;
	}

	public function getDateCreate(): DateTime
	{
		return $this->dateCreate;
	}
}
