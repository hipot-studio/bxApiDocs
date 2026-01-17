<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Document;

use Bitrix\Main\Type\Contract\Arrayable;

final class DocumentComplexId implements Arrayable
{
	public function __construct(
		public readonly string $moduleId,
		public readonly string $entity,
		public readonly string|int $id,
	)
	{}

	public function toArray(): array
	{
		return [
			$this->moduleId,
			$this->entity,
			$this->id,
		];
	}
}
