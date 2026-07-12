<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class ViewedAbsence extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $viewedBy = null,
		public readonly ?int $userId = null,
		public readonly ?int $absenceId = null,
		public readonly ?DateTime $absenceEnd = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			viewedBy: static::mapInteger($props, 'viewedBy'),
			userId: static::mapInteger($props, 'userId'),
			absenceId: static::mapInteger($props, 'absenceId'),
			absenceEnd: static::mapDateTime($props, 'absenceEnd'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'viewedBy' => $this->viewedBy,
			'userId' => $this->userId,
			'absenceId' => $this->absenceId,
			'absenceEnd' => $this->absenceEnd,
		];
	}
}
