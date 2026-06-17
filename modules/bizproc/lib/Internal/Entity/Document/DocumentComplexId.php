<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Document;

use Bitrix\Main\Type\Contract\Arrayable;

final readonly class DocumentComplexId implements Arrayable
{
	public function __construct(
		public string $moduleId,
		public string $entity,
		public string|int $id,
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
