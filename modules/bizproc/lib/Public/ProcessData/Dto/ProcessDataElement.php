<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Dto;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\ProcessData\Enum\DataElementPurpose;

final class ProcessDataElement
{
	public function __construct(
		public readonly string $key,
		public readonly string $sourceType,
		public readonly DataElementPurpose $purpose,
		public readonly array $property,
		public readonly bool $acceptsStartValue = false,
		public readonly ?array $inputProperty = null,
		public readonly ?string $visibility = null,
	)
	{
	}

	/**
	 * @param array $visibilityNames Visibility code to name map of the template document type
	 *                               ({@see \Bitrix\Bizproc\Workflow\Type\GlobalsManager::getVisibilityShortNames}).
	 */
	public function toArray(array $visibilityNames = []): array
	{
		return [
			'key' => $this->key,
			'sourceType' => $this->sourceType,
			'property' => FieldType::normalizeProperty($this->property),
			'purpose' => $this->purpose->value,
			'acceptsStartValue' => $this->acceptsStartValue,
			'inputProperty' => $this->inputProperty === null
				? null
				: FieldType::normalizeProperty($this->inputProperty)
			,
			'visibility' => $this->visibility,
			'visibilityName' => $this->visibility === null ? null : ($visibilityNames[$this->visibility] ?? null),
			'createdBy' => $this->getUserId('CreatedBy'),
			'modifiedBy' => $this->getUserId('ModifiedBy'),
		];
	}

	private function getUserId(string $propertyKey): ?int
	{
		$userId = $this->property[$propertyKey] ?? null;

		return $userId === null ? null : (int)$userId;
	}
}
