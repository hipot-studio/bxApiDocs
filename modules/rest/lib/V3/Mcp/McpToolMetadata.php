<?php

namespace Bitrix\Rest\V3\Mcp;

use Bitrix\Rest\V3\Attribute\McpTool;

final class McpToolMetadata
{
	public function __construct(
		public readonly bool $export,
		public readonly string $toolSetCode,
		public readonly ?string $nameOverride = null,
		public readonly ?string $descriptionOverride = null,
		public readonly array $descriptionAdditions = [],
		public readonly array $hiddenFields = [],
		public readonly ?array $fieldWhitelist = null,
	)
	{
	}

	public function hasFieldRestrictions(): bool
	{
		return $this->hiddenFields !== [] || $this->fieldWhitelist !== null;
	}

	public function isFieldAllowed(string $fieldName): bool
	{
		if (in_array($fieldName, $this->hiddenFields, true))
		{
			return false;
		}

		return $this->fieldWhitelist === null || in_array($fieldName, $this->fieldWhitelist, true);
	}

	public static function fromAttribute(McpTool $attribute): self
	{
		return new self(
			export: $attribute->export,
			toolSetCode: $attribute->toolSetCode,
			nameOverride: $attribute->nameOverride,
			descriptionOverride: $attribute->descriptionOverride,
			descriptionAdditions: $attribute->descriptionAdditions,
			hiddenFields: $attribute->hiddenFields,
			fieldWhitelist: $attribute->fieldWhitelist,
		);
	}

	public static function fromArray(?array $data): ?self
	{
		if ($data === null)
		{
			return null;
		}

		return new self(
			export: (bool)($data['export'] ?? false),
			toolSetCode: (string)($data['toolSetCode'] ?? ''),
			nameOverride: isset($data['nameOverride']) ? (string)$data['nameOverride'] : null,
			descriptionOverride: isset($data['descriptionOverride']) ? (string)$data['descriptionOverride'] : null,
			descriptionAdditions: isset($data['descriptionAdditions']) && is_array($data['descriptionAdditions'])
				? array_values($data['descriptionAdditions'])
				: [],
			hiddenFields: isset($data['hiddenFields']) && is_array($data['hiddenFields'])
				? array_values($data['hiddenFields'])
				: [],
			fieldWhitelist: isset($data['fieldWhitelist']) && is_array($data['fieldWhitelist'])
				? array_values($data['fieldWhitelist'])
				: null,
		);
	}

	public function toArray(): array
	{
		return [
			'export' => $this->export,
			'toolSetCode' => $this->toolSetCode,
			'nameOverride' => $this->nameOverride,
			'descriptionOverride' => $this->descriptionOverride,
			'descriptionAdditions' => $this->descriptionAdditions,
			'hiddenFields' => $this->hiddenFields,
			'fieldWhitelist' => $this->fieldWhitelist,
		];
	}
}
