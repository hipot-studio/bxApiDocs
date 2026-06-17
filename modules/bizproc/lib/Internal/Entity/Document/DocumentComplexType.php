<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Document;

use Bitrix\Main\Type\Contract\Arrayable;

final readonly class DocumentComplexType implements Arrayable
{
	public function __construct(
		public string $moduleId,
		public string $entity,
		public string $type,
	)
	{}

	public function equals(self $complexDocumentType): bool
	{
		return $this->toArray() === $complexDocumentType->toArray();
	}

	public function getKey(): string
	{
		return implode('@', $this->toArray());
	}

	public function toArray(): array
	{
		return [
			$this->moduleId,
			$this->entity,
			$this->type,
		];
	}
}
