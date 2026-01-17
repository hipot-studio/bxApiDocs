<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Document;

use Bitrix\Main\Type\Contract\Arrayable;

final class DocumentComplexType implements Arrayable
{
	public function __construct(
		protected readonly string $moduleId,
		protected readonly string $entity,
		protected readonly string $type,
	)
	{}

	public function toArray(): array
	{
		return [
			$this->moduleId,
			$this->entity,
			$this->type,
		];
	}
}
