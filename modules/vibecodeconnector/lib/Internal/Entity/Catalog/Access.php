<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

final class Access implements EntityInterface
{
	private ?int $id = null;

	public function __construct(
		private int $catalogItemId,
		private string $accessCode,
		private DateTime $grantedAt,
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

	public function getCatalogItemId(): int
	{
		return $this->catalogItemId;
	}

	public function getAccessCode(): string
	{
		return $this->accessCode;
	}

	public function getGrantedAt(): DateTime
	{
		return $this->grantedAt;
	}
}
