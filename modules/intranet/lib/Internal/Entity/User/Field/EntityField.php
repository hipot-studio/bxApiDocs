<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

abstract class EntityField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly mixed $value,
	)
	{
	}
	public function isValid(mixed $value): bool
	{
		return $value instanceof ($this->getEntityType());
	}

	protected abstract function getEntityType(): string;
}
