<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\FieldAttributeType;

final readonly class StorageKey
{
	public function __construct(
		private int $entityTypeId,
		private string $entityScope,
		private string $fieldName,
		private bool $customField,
	)
	{
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function getEntityScope(): string
	{
		return $this->entityScope;
	}

	public function getTypeId(): int
	{
		return FieldAttributeType::REQUIRED;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function isCustomField(): bool
	{
		return $this->customField;
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'entityScope' => $this->entityScope,
			'typeId' => $this->getTypeId(),
			'fieldName' => $this->fieldName,
			'isCustomField' => $this->customField,
		];
	}
}
