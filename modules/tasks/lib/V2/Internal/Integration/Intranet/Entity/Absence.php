<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Absence extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly int $typeEnumId,
		public readonly DateTime $dateTimeFrom,
		public readonly DateTime $dateTimeTo,
		public readonly string $name = '',
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			userId: static::mapInteger($props, 'userId'),
			typeEnumId: static::mapInteger($props, 'typeEnumId'),
			dateTimeFrom: static::mapDateTime($props, 'dateTimeFrom'),
			dateTimeTo: static::mapDateTime($props, 'dateTimeTo'),
			name: static::mapString($props, 'name') ?? '',
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'typeEnumId' => $this->typeEnumId,
			'dateTimeFrom' => $this->dateTimeFrom,
			'dateTimeTo' => $this->dateTimeTo,
			'name' => $this->name,
		];
	}
}
