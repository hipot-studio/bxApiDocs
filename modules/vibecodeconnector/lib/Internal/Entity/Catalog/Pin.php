<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class Pin implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $userId,
		private int $catalogItemId,
		private DateTime $pinnedAt,
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

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getCatalogItemId(): int
	{
		return $this->catalogItemId;
	}

	public function getPinnedAt(): DateTime
	{
		return $this->pinnedAt;
	}
}
