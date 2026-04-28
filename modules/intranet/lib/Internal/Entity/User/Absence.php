<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class Absence implements EntityInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly DateTime $dateFrom,
		private readonly DateTime $dateTo,
		private readonly string $description,
		private readonly string $typeXmlId,
		private readonly string $name,
		private ?int $id = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'dateFrom' => $this->getDateFrom()->toString(),
			'dateTo' => $this->getDateTo()->toString(),
			'description' => $this->getDescription(),
			'typeXmlId' => $this->getTypeXmlId(),
			'name' => $this->getName(),
		];
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getDateFrom(): DateTime
	{
		return $this->dateFrom;
	}

	public function getDateTo(): DateTime
	{
		return $this->dateTo;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getTypeXmlId(): string
	{
		return $this->typeXmlId;
	}

	public function getName(): string
	{
		return $this->name;
	}
}
