<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;

class AbsenceType implements EntityInterface
{
	public function __construct(
		public readonly int $id,
		public readonly string $xmlId,
		public readonly string $name,
		public readonly bool $isActive,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}
}